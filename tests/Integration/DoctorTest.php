<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApprovalExecutionContext;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Doctor\Doctor;
use Fissible\VerdictConsole\Doctor\FindingCode;
use Fissible\VerdictConsole\Doctor\Severity;
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

/** Wired correctly: conversational, remembers, declares the middleware, binds a tool. */
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
    // The conversation tables are a real precondition; migrate them so the default run is clean.
    (require dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php')->up();

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

});

it('reports nothing when every precondition is satisfied', function (): void {
    expect(doctorFor(['healthy' => new HealthyAgent])->run())->toBe([]);
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
