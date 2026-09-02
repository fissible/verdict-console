<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApprovalDecisionKind;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\LaravelAi\VerdictProvenanceMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Doctor\Doctor;
use Fissible\VerdictConsole\Doctor\FindingCode;
use Fissible\VerdictConsole\Doctor\Severity;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsole\Evidence\SinkPosture;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;

final class DoctorSubjectTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'A tool.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'ok';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

final class DoctorApprovalAuthorizer implements ApprovalDecisionAuthorizer
{
    public function authorize(ApprovalReceipt $receipt, ApprovalDecisionKind $kind, string $decidedBy): bool
    {
        return true;
    }
}

/**
 * Wired correctly: conversational, remembers, declares both middlewares, binds a tool.
 *
 * Both middlewares, because they guard different things. `VerdictApprovalMiddleware` is what lets an
 * approved receipt execute; `VerdictProvenanceMiddleware` is what stamps `invocation_id` on the
 * decision evidence the VC-14 correlation joins against. The approval round trip works without the
 * second one, which is exactly why a healthy install must be shown declaring it.
 */
final class HealthyAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'healthy';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [doctorBoundTool()];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            new VerdictApprovalMiddleware(new ApprovalExecutionContext),
            new VerdictProvenanceMiddleware(app(ProvenanceLedger::class), Trust::Untrusted, DataClass::Internal),
        ];
    }
}

/**
 * The approval loop works, the evidence join is empty. Everything `HealthyAgent` has except the
 * provenance middleware — so the only thing the doctor can say about it is the correlation gap.
 */
final class UncorrelatedAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'uncorrelated';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [doctorBoundTool()];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new VerdictApprovalMiddleware(new ApprovalExecutionContext)];
    }
}

/** Not `Conversational`: Laravel AI throws rather than pausing. */
final class NotConversationalDoctorAgent implements Agent, HasMiddleware, HasTools
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'not conversational';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [doctorBoundTool()];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new VerdictApprovalMiddleware(new ApprovalExecutionContext)];
    }
}

/** Implements the contract but omits the trait: the silent half of the pair. */
final class TraitlessAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;

    private ?string $conversationId = null;

    public function instructions(): Stringable|string
    {
        return 'no trait';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [doctorBoundTool()];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new VerdictApprovalMiddleware(new ApprovalExecutionContext)];
    }

    public function forParticipant(object $participant): static
    {
        return $this;
    }

    public function forUser($user): static
    {
        return $this;
    }

    public function continue(string $conversationId, ?object $as = null): static
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    public function continueLastConversation(object $as): static
    {
        return $this;
    }

    public function messages(): iterable
    {
        return [];
    }

    public function currentConversation(): ?string
    {
        return $this->conversationId;
    }

    public function hasConversationParticipant(): bool
    {
        return false;
    }

    public function conversationParticipant(): ?object
    {
        return null;
    }
}

/** Declares no middleware: an approved receipt fails as `invalid_state`. */
final class MiddlewarelessAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'no middleware';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [doctorBoundTool()];
    }
}

/** Registered as resumable but binds nothing through Verdict. */
final class ToollessAgent implements Agent, HasMiddleware, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'no bound tool';
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new VerdictApprovalMiddleware(new ApprovalExecutionContext)];
    }
}

function doctorBoundTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('doctor.healthy')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'doctor.healthy',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): object => new stdClass,
            )
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'doctor-target',
                    identityUsing: fn (ActionEnvelope $e, object $t): array => ['id' => 1],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, object $t): array => ['id' => 1])
                ->executeUsing(fn (AuthorizedAction $a): string => 'done'),
        );
    }

    return $verdict->bound(new DoctorSubjectTool, 'doctor.healthy', new ActionContext('actor'));
}

/** @param  array<string, Agent>  $agents */
function doctorFor(array $agents = []): Doctor
{
    $registry = new AgentResolverRegistry;

    foreach ($agents as $key => $agent) {
        $registry->register($key, fn (): Agent => $agent, fn (Agent $candidate): bool => $candidate === $agent);
    }

    app()->instance(ResumableAgents::class, $registry);

    return app(Doctor::class);
}

beforeEach(function (): void {
    // #107's evidence-recording finding fires whenever the sink posture is Off and no explicit
    // decision is recorded. This harness's default recorder IS the null one, so the baseline run
    // records the decision the way a host would — the finding's own suppression semantics.
    config()->set('verdict-console.evidence.accepted_off', true);

    // The conversation tables are a real precondition; migrate them so the default run is clean.
    (require dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php')->up();

    // So is the correlation projection's table: without it every completed turn logs a failed write.
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_conversation_invocations_table.php.stub')->up();

    // Verdict leaves the authorizer to the application, so nothing binds one by default and
    // VerdictManager cannot build without it. The doctor never asks it to decide anything; this is
    // here so the capabilities under inspection can be registered at all.
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('doctor test');
        }
    });

    config()->set('verdict.approvals.authorizer', DoctorApprovalAuthorizer::class);

});

it('reports nothing when every precondition is satisfied', function (): void {
    config()->set('verdict.approvals.authorizer', DoctorApprovalAuthorizer::class);

    expect(doctorFor(['healthy' => new HealthyAgent])->run())->toBe([]);
});

it('reports a missing approval decision authorizer before a person clicks approve', function (): void {
    config()->set('verdict.approvals.authorizer', null);

    $findings = doctorFor()->run();
    $finding = current(array_filter($findings, fn ($f) => $f->code === FindingCode::ApprovalAuthorizerMissing));

    expect($finding)->not->toBeFalse()
        ->and($finding->severity)->toBe(Severity::Error)
        ->and($finding->summary)->toContain('fail-closed')
        ->and($finding->fix)->toContain('verdict:make-approval-flow');
});

it('does not report an approval decision authorizer the host configures', function (): void {
    config()->set('verdict.approvals.authorizer', DoctorApprovalAuthorizer::class);

    expect(array_map(fn ($finding) => $finding->code, doctorFor()->run()))
        ->not->toContain(FindingCode::ApprovalAuthorizerMissing);
});

it('reports an approval decision authorizer class that cannot resolve', function (): void {
    config()->set('verdict.approvals.authorizer', 'App\\Support\\MissingApprovalAuthorizer');

    $finding = current(array_filter(
        doctorFor()->run(),
        fn ($finding) => $finding->code === FindingCode::ApprovalAuthorizerInvalid,
    ));

    expect($finding)->not->toBeFalse()
        ->and($finding->severity)->toBe(Severity::Error)
        ->and($finding->summary)->toBe(
            'The configured approval decision authorizer [App\\Support\\MissingApprovalAuthorizer] does not exist.',
        );
});

it('reports an approval decision authorizer that implements the wrong contract', function (): void {
    config()->set('verdict.approvals.authorizer', DoctorSubjectTool::class);

    $finding = current(array_filter(
        doctorFor()->run(),
        fn ($finding) => $finding->code === FindingCode::ApprovalAuthorizerInvalid,
    ));

    expect($finding)->not->toBeFalse()
        ->and($finding->severity)->toBe(Severity::Error)
        ->and($finding->summary)->toContain(ApprovalDecisionAuthorizer::class);
});

it('warns when production configures Verdict\'s test-only allow-all authorizer', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config()->set('verdict.approvals.authorizer', AllowAllApprovalAuthorizer::class);

    $finding = current(array_filter(
        doctorFor()->run(),
        fn ($finding) => $finding->code === FindingCode::ApprovalAuthorizerAllowsAll,
    ));

    expect($finding)->not->toBeFalse()
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->summary)->toContain('authorizes every decision');
});

it('permits Verdict\'s test-only allow-all authorizer in the testing environment', function (): void {
    config()->set('verdict.approvals.authorizer', AllowAllApprovalAuthorizer::class);

    expect(array_map(fn ($finding) => $finding->code, doctorFor()->run()))
        ->not->toContain(FindingCode::ApprovalAuthorizerAllowsAll);
});

it('reports a resolver key that does not rebuild an agent', function (): void {
    $registry = (new AgentResolverRegistry)->register(
        'broken',
        fn (): object => throw new RuntimeException('the tenant connection is gone'),
        fn (Agent $agent): bool => false,
    );

    app()->instance(ResumableAgents::class, $registry);

    $findings = app(Doctor::class)->run();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe(FindingCode::ResolverKeyUnresolvable)
        ->and($findings[0]->severity)->toBe(Severity::Error)
        ->and($findings[0]->subject)->toBe('broken')
        // The cause is what makes the finding actionable; "the resolver threw" alone is not.
        ->and($findings[0]->summary)->toContain('the tenant connection is gone');
});

/**
 * A non-conversational agent never reaches the doctor's per-agent checks: `ResumableAgents::resolve()`
 * returns `Agent&RemembersConversations`, so VC-2 refuses it first. The doctor's job here is to
 * surface that refusal as something actionable rather than to re-check a shape it cannot receive.
 */
it('surfaces a non-conversational agent through the resolver rather than a per-agent check', function (): void {
    $findings = doctorFor(['a' => new NotConversationalDoctorAgent])->run();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe(FindingCode::ResolverKeyUnresolvable)
        ->and($findings[0]->summary)->toContain('not a conversational agent')
        // The message must name why the shape matters, not just that it is wrong.
        ->and($findings[0]->summary)->toContain('continue()');
});

/**
 * The two conversation preconditions are separate findings on purpose. They fail in opposite ways —
 * one throws loudly at pause, the other silently skips durable recording — so an agent that has the
 * contract but not the trait must be told exactly that, not "your conversation setup is wrong".
 */
it('separates the loud conversation precondition from the silent one', function (): void {
    $findings = doctorFor(['a' => new TraitlessAgent])->run();
    $codes = array_map(fn ($f) => $f->code, $findings);

    // This agent satisfies the contract by hand, so VC-2's resolve() accepts it — which is exactly
    // why the trait check has to exist separately. The contract is the loud gate; the trait is the
    // silent one, and only the silent one can get this far.
    expect($codes)->toContain(FindingCode::AgentDoesNotRememberConversations)
        ->and($codes)->not->toContain(FindingCode::ResolverKeyUnresolvable);

    $silent = current(array_filter($findings, fn ($f) => $f->code === FindingCode::AgentDoesNotRememberConversations));

    expect($silent->summary)->toContain('Nothing raises');
});

it('reports a missing approval middleware', function (): void {
    $findings = doctorFor(['a' => new MiddlewarelessAgent])->run();

    expect(array_map(fn ($f) => $f->code, $findings))->toContain(FindingCode::ApprovalMiddlewareMissing)
        ->and(current(array_filter($findings, fn ($f) => $f->code === FindingCode::ApprovalMiddlewareMissing))->summary)
        ->toContain('invalid_state');
});

it('warns rather than errors for a resumable agent that binds nothing', function (): void {
    $findings = doctorFor(['pointless' => new ToollessAgent])->run();
    $finding = current(array_filter($findings, fn ($f) => $f->code === FindingCode::AgentHasNoBoundTool));

    expect($finding->severity)->toBe(Severity::Warning, 'Nothing is broken; the registration just does nothing.')
        // The fix names the key to unregister, so it is actionable without a lookup.
        ->and($finding->fix)->toContain('pointless');
});

/**
 * Checked as tables rather than a container binding. `ConversationStore` is bound by Laravel AI's
 * provider, which this package hard-depends on, so a binding check could never fail — what actually
 * breaks a host is publishing and never migrating.
 */
it('reports missing conversation tables once, not per agent', function (): void {
    Schema::drop('agent_conversations');

    $findings = doctorFor(['a' => new HealthyAgent, 'b' => new HealthyAgent])->run();
    $tables = array_filter($findings, fn ($f) => $f->code === FindingCode::ConversationTablesMissing);

    expect($tables)->toHaveCount(1)
        ->and(current($tables)->severity)->toBe(Severity::Error);
});

/**
 * The half a one-table check would miss, and the more dangerous half.
 *
 * Laravel AI's single migration creates both tables, so they are usually present together — but a
 * host that migrated partially, renamed one, or restored a partial dump gets a doctor that says
 * "clean" and a run that pauses into nothing. The messages table is where the paused assistant turn
 * lives: `DatabaseConversationStore::storeAssistantMessage()` writes its `tool_calls` and
 * `approval_state` there, and `getLatestConversationMessages()` reads them back to reconstruct the
 * pending call on resume. Without it the conversation row exists and the pause is lost.
 */
it('reports the messages table missing even when the conversations table exists', function (): void {
    Schema::drop('agent_conversation_messages');

    $findings = doctorFor(['a' => new HealthyAgent])->run();
    $finding = current(array_filter($findings, fn ($f) => $f->code === FindingCode::ConversationTablesMissing));

    expect($finding)->not->toBeFalse('A half-migrated host must not pass preflight.')
        ->and($finding->severity)->toBe(Severity::Error)
        ->and($finding->subject)->toBe('agent_conversation_messages')
        // The subject names only what is actually missing, so the fix is not a guess.
        ->and($finding->subject)->not->toContain('agent_conversations,');
});

/** Both missing names both, so an operator sees the whole gap in one finding. */
it('names both tables when neither is migrated', function (): void {
    Schema::drop('agent_conversation_messages');
    Schema::drop('agent_conversations');

    $findings = doctorFor(['a' => new HealthyAgent])->run();
    $finding = current(array_filter($findings, fn ($f) => $f->code === FindingCode::ConversationTablesMissing));

    expect($finding->subject)->toBe('agent_conversations, agent_conversation_messages');
});

/** The #230 dead gate, checked over the registry: it is broken whichever agent reaches it. */
it('reports a confirmation gate that can never pause', function (): void {
    app(VerdictManager::class)->capability(
        Capability::usingPolicy(
            name: 'doctor.dead-gate',
            ability: 'update',
            resolveTarget: fn (ActionEnvelope $e): object => new stdClass,
        )
            ->requiresConfirmation(fn (ActionEnvelope $e, object $t): array => ['id' => 1])
            ->executeUsing(fn (AuthorizedAction $a): string => 'done'),
    );

    $findings = doctorFor()->run();
    $finding = current(array_filter($findings, fn ($f) => $f->code === FindingCode::ConfirmationGateCannotPause));

    expect($finding->severity)->toBe(Severity::Error)
        ->and($finding->subject)->toBe('doctor.dead-gate')
        ->and($finding->summary)->toContain('never pauses');
});

/** A gate that declares a target is not reported — or the finding would be noise. */
it('does not report a confirmation gate that declares an execution target', function (): void {
    doctorBoundTool();

    $codes = array_map(fn ($f) => $f->code, doctorFor()->run());

    expect($codes)->not->toContain(FindingCode::ConfirmationGateCannotPause);
});

/**
 * The VC-14 join has a host-side precondition the approval loop does not: `VerdictProvenanceMiddleware`
 * pushes the invocation frame Verdict reads when it stamps `invocation_id` on decision evidence.
 * Without it every decision row carries null, the projection still records the conversation, and a
 * conversation-scoped evidence query answers **Known with zero records** — indistinguishable from
 * "this conversation decided nothing". A warning, not an error: the approval loop is unaffected.
 */
it('warns per agent when the provenance middleware that stamps evidence is not declared', function (): void {
    $findings = doctorFor(['a' => new UncorrelatedAgent, 'b' => new HealthyAgent, 'c' => new UncorrelatedAgent])->run();

    expect($findings)->toHaveCount(2, 'One finding per uncorrelated agent; the healthy agent is not reported.');

    foreach ($findings as $finding) {
        expect($finding->code)->toBe(FindingCode::EvidenceCorrelationMiddlewareMissing)
            ->and($finding->severity)->toBe(Severity::Warning)
            ->and($finding->subject)->toBe(UncorrelatedAgent::class)
            // The summary must say what the operator would otherwise see: a conversation that looks decided-nothing.
            ->and($finding->summary)->toContain('invocation_id')
            // The fix names the host action, not just the class.
            ->and($finding->fix)->toContain('VerdictProvenanceMiddleware')
            ->and($finding->fix)->toContain('middleware()');
    }
});

/**
 * The other precondition, checked as a table for the same reason the conversation tables are: the
 * listener is registered by this package's own provider and can never be "unbound", but a host that
 * published the package and never ran the new migration gets a logged error per completed turn and
 * every conversation reported Unknown — correct, and easy to miss in a log stream.
 */
it('warns once, not per agent, when the correlation table is not migrated', function (): void {
    Schema::drop('verdict_console_conversation_invocations');

    $findings = doctorFor(['a' => new HealthyAgent, 'b' => new HealthyAgent])->run();
    $tables = array_values(array_filter($findings, fn ($f) => $f->code === FindingCode::EvidenceCorrelationTableMissing));

    expect($tables)->toHaveCount(1)
        ->and($tables[0]->severity)->toBe(Severity::Warning)
        ->and($tables[0]->subject)->toBe('verdict_console_conversation_invocations')
        ->and($tables[0]->summary)->toContain('Unknown')
        ->and($tables[0]->fix)->toContain('vendor:publish')
        ->and($tables[0]->fix)->toContain('verdict-console-migrations')
        ->and($tables[0]->fix)->toContain('migrate')
        ->and(array_map(fn ($f) => $f->code, $findings))->not->toContain(FindingCode::EvidenceCorrelationMiddlewareMissing);
});

/** The table is a package-global precondition: it is reported with no agents registered at all. */
it('reports the missing correlation table with no resumable agents registered', function (): void {
    Schema::drop('verdict_console_conversation_invocations');

    $findings = doctorFor()->run();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code)->toBe(FindingCode::EvidenceCorrelationTableMissing);
});

/** Both prerequisites absent is two findings with two remedies; neither may hide the other. */
it('reports both correlation prerequisites when both are absent', function (): void {
    Schema::drop('verdict_console_conversation_invocations');

    $findings = doctorFor(['a' => new UncorrelatedAgent])->run();
    $byCode = [];

    foreach ($findings as $finding) {
        $byCode[$finding->code->value] = $finding;
    }

    expect($findings)->toHaveCount(2)
        ->and($byCode)->toHaveCount(2)
        ->and($byCode)->toHaveKeys([FindingCode::EvidenceCorrelationMiddlewareMissing->value, FindingCode::EvidenceCorrelationTableMissing->value])
        ->and($byCode[FindingCode::EvidenceCorrelationMiddlewareMissing->value]->severity)->toBe(Severity::Warning)
        ->and($byCode[FindingCode::EvidenceCorrelationTableMissing->value]->severity)->toBe(Severity::Warning)
        ->and($byCode[FindingCode::EvidenceCorrelationMiddlewareMissing->value]->fix)
        ->not->toBe($byCode[FindingCode::EvidenceCorrelationTableMissing->value]->fix);
});

/**
 * The projection is the console's own table on the application's default connection — not on
 * `verdict.evidence.connection`, which is where Verdict's evidence lives and where the evidence
 * query adapter reads. A check that borrowed the evidence connection would pass on a host whose
 * evidence database happens to hold a same-named table and miss the one the listener writes to.
 */
it('checks the correlation table on the default connection, not the evidence connection', function (): void {
    config()->set('database.connections.evidence_elsewhere', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('verdict.evidence.connection', 'evidence_elsewhere');
    Schema::connection('evidence_elsewhere')->create('verdict_console_conversation_invocations', function ($table): void {
        $table->string('invocation_id')->primary();
        $table->string('conversation_id')->index();
    });
    Schema::drop('verdict_console_conversation_invocations');

    $codes = array_map(fn ($f) => $f->code, doctorFor(['a' => new HealthyAgent])->run());

    expect($codes)->toContain(FindingCode::EvidenceCorrelationTableMissing);
});

/** A missing approval middleware and a missing provenance middleware are two findings, two fixes. */
it('reports the provenance gap separately from a missing approval middleware', function (): void {
    $codes = array_map(fn ($f) => $f->code, doctorFor(['a' => new MiddlewarelessAgent])->run());

    expect($codes)->toContain(FindingCode::ApprovalMiddlewareMissing)
        ->and($codes)->toContain(FindingCode::EvidenceCorrelationMiddlewareMissing);
});

it('returns findings as data a UI can render', function (): void {
    $findings = doctorFor(['a' => new MiddlewarelessAgent])->run();

    expect($findings[0]->toArray())->toHaveKeys(['code', 'severity', 'subject', 'summary', 'fix'])
        ->and($findings[0]->toArray()['code'])->toBeString();
});

it('orders errors before warnings', function (): void {
    Schema::drop('agent_conversations');

    $severities = array_map(fn ($f) => $f->severity, doctorFor(['pointless' => new ToollessAgent])->run());

    expect($severities[0])->toBe(Severity::Error)
        ->and(end($severities))->toBe(Severity::Warning);
});

it('fails the command for an error and passes for a clean run', function (): void {
    doctorFor(['healthy' => new HealthyAgent]);
    $this->artisan('verdict-console:doctor')->assertSuccessful();

    doctorFor(['a' => new MiddlewarelessAgent]);
    $this->artisan('verdict-console:doctor')->assertFailed();
});

/** Warnings alone do not fail a build; --strict is how a host opts into that. */
it('fails a warning-only run only under --strict', function (): void {
    doctorFor(['pointless' => new ToollessAgent]);

    $this->artisan('verdict-console:doctor')->assertSuccessful();
    $this->artisan('verdict-console:doctor', ['--strict' => true])->assertFailed();
});

/** #107: bind a scripted posture so the finding is proven to consume the boundary, not config. */
function doctorPosture(EvidenceRecordingState $state, ?string $effectiveWriter = null): void
{
    $posture = new SinkPosture(
        state: $state,
        effectiveWriter: $effectiveWriter,
        recordedBy: null,
        table: null,
        connection: null,
        chainConfigured: false,
    );

    app()->instance(EvidenceSinkPosture::class, new class($posture) implements EvidenceSinkPosture
    {
        public function __construct(private readonly SinkPosture $posture) {}

        public function read(): SinkPosture
        {
            return $this->posture;
        }
    });
}

/**
 * #107: an undecided Off posture is an error until someone decides — and the complaint ends by
 * decision, not dismissal. The finding pins its exact code, severity, subject, the verbatim
 * one-way-tradeoff sentence, and the fix that names the decision key. The posture boundary is the
 * source: config here says a database recorder, and the finding still fires.
 */
it('reports an undecided off posture as an error with the recorded tradeoff', function (): void {
    config()->set('verdict-console.evidence.accepted_off', false);
    config()->set('verdict.evidence.recorder', 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder');
    doctorPosture(EvidenceRecordingState::Off, 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder');

    $findings = array_values(array_filter(doctorFor()->run(), fn ($finding) => $finding->code === FindingCode::EvidenceRecordingUnacknowledged));

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->code->value)->toBe('evidence_recording_unacknowledged')
        ->and($findings[0]->severity)->toBe(Severity::Error)
        ->and($findings[0]->subject)->toBe('verdict.evidence.recorder')
        ->and($findings[0]->summary)->toContain('configuring the shipped attest recorder chains records written by later record() calls; it neither backfills nor makes pre-existing rows verifiable through the chain')
        ->and($findings[0]->fix)->toContain('verdict-console.evidence.accepted_off')
        ->and($findings[0]->fix)->toContain('durable evidence recorder');
});

/** The explicit decision — and only the literal boolean — silences the finding. */
it('silences the finding only for the literal boolean acknowledgement', function (mixed $value, bool $suppressed): void {
    config()->set('verdict-console.evidence.accepted_off', $value);
    doctorPosture(EvidenceRecordingState::Off, 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder');

    $codes = array_map(fn ($finding) => $finding->code, doctorFor()->run());

    expect(in_array(FindingCode::EvidenceRecordingUnacknowledged, $codes, true))->toBe(! $suppressed);
})->with([
    'true suppresses' => [true, true],
    'false does not' => [false, false],
    'string true does not' => ['true', false],
    'integer one does not' => [1, false],
    'yes does not' => ['yes', false],
]);

/** No key at all is the core failure — an absent decision is not a made one. */
it('reports when no decision key exists at all', function (): void {
    config()->set('verdict-console.evidence', []);
    doctorPosture(EvidenceRecordingState::Off, 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder');

    $codes = array_map(fn ($finding) => $finding->code, doctorFor()->run());

    expect(in_array(FindingCode::EvidenceRecordingUnacknowledged, $codes, true))->toBeTrue();
});

/** A readable, chained, or elsewhere sink is a made decision: no finding, whatever the key says. */
it('reports nothing for non-off postures regardless of the acknowledgement key', function (): void {
    config()->set('verdict-console.evidence.accepted_off', false);

    foreach ([EvidenceRecordingState::On, EvidenceRecordingState::Elsewhere, EvidenceRecordingState::Chained] as $state) {
        doctorPosture($state, 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder');

        $codes = array_map(fn ($finding) => $finding->code, doctorFor()->run());

        expect(in_array(FindingCode::EvidenceRecordingUnacknowledged, $codes, true))->toBeFalse();
    }
});

/** The finding joins the run beside unrelated findings without displacing or duplicating any. */
it('adds exactly one finding to the acknowledged baseline, changing nothing else', function (): void {
    config()->set('verdict.approvals.authorizer', null);
    doctorPosture(EvidenceRecordingState::Off, 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder');

    config()->set('verdict-console.evidence.accepted_off', true);

    $baseline = array_map(fn ($finding) => $finding->code, doctorFor()->run());

    config()->set('verdict-console.evidence.accepted_off', false);

    $undecided = array_map(fn ($finding) => $finding->code, doctorFor()->run());

    $withoutNew = array_values(array_filter($undecided, fn ($code) => $code !== FindingCode::EvidenceRecordingUnacknowledged));

    // Exactly the baseline plus the one new finding: nothing dropped, nothing duplicated.
    expect(count($undecided))->toBe(count($baseline) + 1)
        ->and($withoutNew)->toBe($baseline)
        ->and(in_array(FindingCode::ApprovalAuthorizerMissing, $baseline, true))->toBeTrue();
});

/** The finding reaches the command surface too: an undecided Off names its code and fails the run. */
it('fails the doctor command for an undecided off posture, naming the finding', function (): void {
    doctorFor(['healthy' => new HealthyAgent]);
    config()->set('verdict-console.evidence.accepted_off', false);
    doctorPosture(EvidenceRecordingState::Off, 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder');

    $this->artisan('verdict-console:doctor')
        ->expectsOutputToContain('evidence_recording_unacknowledged')
        ->assertFailed();

    config()->set('verdict-console.evidence.accepted_off', true);

    $this->artisan('verdict-console:doctor')->assertSuccessful();
});

/**
 * A fresh install has NOT decided: the published default is false, and — through the REAL
 * registered posture reader over the shipped Null-recorder default, no fakes anywhere — the
 * finding fires. A missing posture binding or a wrong fresh-install posture fails here.
 */
it('ships the acknowledgement default as false so a fresh install is nagged', function (): void {
    $published = require dirname(__DIR__, 2).'/config/verdict-console.php';

    expect($published['evidence']['accepted_off'])->toBeFalse();

    config()->set('verdict-console.evidence.accepted_off', $published['evidence']['accepted_off']);

    $codes = array_map(fn ($finding) => $finding->code, doctorFor()->run());

    expect(in_array(FindingCode::EvidenceRecordingUnacknowledged, $codes, true))->toBeTrue();
});

/** ADR 0002 §3: a fixed chain and a resolver both set is a configuration Verdict rejects. */
it('flags an invalid chain topology as its own error finding', function (): void {
    config()->set('verdict.evidence.recorder', 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder');
    config()->set('verdict.evidence.attest.chain', 'orders-chain');
    config()->set('verdict.evidence.attest.chain_resolver', 'App\\Attest\\TenantChains');

    $findings = doctorFor()->run();
    $finding = current(array_filter($findings, fn ($f) => $f->code === FindingCode::ChainTopologyInvalid));

    expect($finding)->not->toBeFalse()
        ->and($finding->severity)->toBe(Severity::Error)
        ->and($finding->summary)->toContain('exactly one')
        ->and($finding->fix)->toContain('chain_resolver');
});

it('flags an attest sink with neither chain source set as the same invalid topology', function (): void {
    config()->set('verdict.evidence.recorder', 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder');

    expect(array_map(fn ($finding) => $finding->code, doctorFor()->run()))
        ->toContain(FindingCode::ChainTopologyInvalid);
});

it('raises no topology finding when exactly one chain source is set', function (): void {
    config()->set('verdict.evidence.recorder', 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder');
    config()->set('verdict.evidence.attest.chain', 'orders-chain');

    expect(array_map(fn ($finding) => $finding->code, doctorFor()->run()))
        ->not->toContain(FindingCode::ChainTopologyInvalid);
});
