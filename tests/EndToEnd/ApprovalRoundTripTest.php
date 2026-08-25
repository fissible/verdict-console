<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Exceptions\UnsafeOuterTransaction;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Approvals\ApprovalReconciliation;
use Fissible\VerdictConsole\Approvals\ApprovalResolutionService;
use Fissible\VerdictConsole\Approvals\PendingApproval as StoredPendingApproval;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Approvals\ResumeFailurePhase;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Fissible\VerdictConsole\Contracts\ApprovalPresenter;
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Events\ApprovalIngestionIncident;
use Fissible\VerdictConsole\Exceptions\ApprovalNotDrivable;
use Fissible\VerdictConsole\Exceptions\ApprovalResumeFailed;
use Fissible\VerdictConsole\Participants\UnconfiguredConversationParticipants;
use Fissible\VerdictConsole\Presentation\ApprovalPresentation;
use Fissible\VerdictConsole\Tests\EndToEndTestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\Decision as AiDecision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval as LaravelPendingApproval;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Tools\Request;

const TOOL_CALL_ID = 'call_round_trip';
const ORDER_ID = 1001;

/** Counts executions across a whole test. The one number the round trip is really about. */
final class RoundTripLedger
{
    public int $executions = 0;
}

final readonly class RoundTripOrder
{
    public function __construct(public int $id) {}
}

final readonly class RoundTripCustomer
{
    public function __construct(public int $id) {}
}

/** Faithfully rebuilds the simple participant fixture by its host-owned opaque reference. */
final class RoundTripParticipants implements ConversationParticipants
{
    public function referenceFor(object $participant): string
    {
        if (! $participant instanceof RoundTripCustomer) {
            throw new LogicException('Unexpected round-trip participant.');
        }

        return 'customer:'.$participant->id;
    }

    public function resolve(string $reference): object
    {
        if (! preg_match('/^customer:(\\d+)$/', $reference, $matches)) {
            throw new LogicException('Unknown round-trip participant reference.');
        }

        return new RoundTripCustomer((int) $matches[1]);
    }
}

/** Keeps the real receipt lifecycle, changing only the transition under this control. */
final readonly class ForcedApprovalOutcomeStore implements ApprovalReceiptStore
{
    public function __construct(
        private ApprovalReceiptStore $delegate,
        private ApprovalOutcome $outcome,
    ) {}

    public function issue(ApprovalReceipt $receipt): ApprovalTransition
    {
        return $this->delegate->issue($receipt);
    }

    public function findForToolCall(string $toolCallId): ?ApprovalReceipt
    {
        return $this->delegate->findForToolCall($toolCallId);
    }

    public function approve(string $receiptId, string $toolCallId, string $approvedBy, DateTimeImmutable $at): ApprovalTransition
    {
        return ApprovalTransition::to($this->outcome);
    }

    public function reject(string $receiptId, string $toolCallId, string $rejectedBy, DateTimeImmutable $at): ApprovalTransition
    {
        return $this->delegate->reject($receiptId, $toolCallId, $rejectedBy, $at);
    }

    public function validate(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        return $this->delegate->validate($toolCallId, $bindingFingerprint, $at);
    }

    public function consume(string $toolCallId, string $bindingFingerprint, DateTimeImmutable $at): ApprovalTransition
    {
        return $this->delegate->consume($toolCallId, $bindingFingerprint, $at);
    }
}

final class CancelOrderTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Cancel an order by id.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'The Verdict-bound tool handles this.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

/**
 * Builds the bound tool, registering the capability once per container.
 *
 * The capability declares an execution-target policy deliberately: `requiresConfirmation()` without
 * one makes `VerdictManager::requestConfirmation()` return null, so the run never pauses, no
 * approval is ever requested, and this whole test would pass vacuously (design §12, verdict#230).
 */
function roundTripTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('orders.cancel')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.cancel',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): RoundTripOrder => new RoundTripOrder(
                    (int) $e->proposal->arguments['order_id'],
                ),
            )
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'round-trip-target',
                    // Identity is the order id, not spl_object_id: the proposal and the execution
                    // resolve two different PHP objects for the same order.
                    identityUsing: fn (ActionEnvelope $e, RoundTripOrder $t): array => ['id' => $t->id],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, RoundTripOrder $t): array => ['order_id' => $t->id])
                ->executeUsing(function (AuthorizedAction $a): string {
                    app(RoundTripLedger::class)->executions++;

                    return 'Order cancelled.';
                }),
        );
    }

    return $verdict->bound(new CancelOrderTool, 'orders.cancel', new ActionContext('customer-7'));
}

/**
 * The agent under test.
 *
 * Three declarations here are load-bearing, and each fails differently when omitted (design §3, §12):
 *
 * - `RemembersConversationsContract` extends `Conversational`, which is what
 *   `throwIfNotResumable()` checks. Without it the run raises `ApprovalNotResumableException` the
 *   moment it would pause — a **loud** failure.
 * - The `RemembersConversations` *trait* plus a conversation store is what makes the paused turn
 *   durable. Without it the resume silently records nothing — the **quiet** failure, and the one a
 *   cross-process console would actually hit.
 * - `VerdictApprovalMiddleware` is **not** auto-registered. Without it
 *   `ApprovalExecutionContext::allows()` is false for every call and an approved receipt fails
 *   proposal-validation with `invalid_state`.
 */
/**
 * A resumable agent that records what VC-6 sends it, and refuses the one call it must never receive.
 *
 * This is a **service-boundary** control, not an execution one: substituting it means nothing
 * actually resumes. That is the point — it measures the shape of the continuation the resolution
 * service constructs, which the real round-trip test cannot isolate because a successful resume
 * looks the same whether the decision map was exact or a wildcard.
 */
final class RecordingResumableAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    /** @var array<int, array{conversationId: string, participant: ?object}> */
    public array $continuations = [];

    public ?Decisions $decisions = null;

    #[Override]
    public function continue(string $conversationId, ?object $as = null): static
    {
        $this->continuations[] = ['conversationId' => $conversationId, 'participant' => $as];

        return $this;
    }

    /**
     * The prohibition, made executable.
     *
     * `continueLastConversation()` resolves to the participant's *most recent* conversation, which is
     * the wrong one whenever a participant has more than one in flight (fissible/verdict#265). A
     * source read cannot prove no path calls it; this can.
     */
    #[Override]
    public function continueLastConversation(object $as): static
    {
        throw new RuntimeException('VC-6 must resume an exact conversation id, never the participant\'s latest.');
    }

    #[Override]
    public function prompt(
        Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null): AgentResponse
    {
        if ($prompt instanceof Decisions) {
            $this->decisions = $prompt;
        }

        return new AgentResponse('recording-invocation', '', new Usage, new Meta);
    }

    public function instructions(): Stringable|string
    {
        return 'Records what it is asked to resume.';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [roundTripTool()];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [app(VerdictApprovalMiddleware::class)];
    }

    public function provider(): string
    {
        return EndToEndTestCase::PROVIDER;
    }
}

final class RoundTripAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'Cancel orders when asked.';
    }

    /**
     * Built here rather than injected: the bound tool closes over VerdictManager, and an agent
     * holding one as a property cannot be serialized onto a queue later.
     *
     * @return array<int, Tool>
     */
    public function tools(): array
    {
        return [roundTripTool()];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [app(VerdictApprovalMiddleware::class)];
    }

    public function provider(): string
    {
        return EndToEndTestCase::PROVIDER;
    }

    public function maxSteps(): int
    {
        return 3;
    }
}

beforeEach(function (): void {
    $this->migrateRoundTripTables();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_approval_reconciliations_table.php.stub')->up();

    $this->app->instance(RoundTripLedger::class, new RoundTripLedger);
    $this->app->instance(ConversationParticipants::class, new RoundTripParticipants);

    // A stub authorizer keeps this test about the approval round trip rather than about policy
    // resolution. Every permit below is the authorizer's, never a Verdict default.
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('test');
        }
    });

    /** @var AgentResolverRegistry $resolvers */
    $resolvers = app(ResumableAgents::class);
    $resolvers->register(
        'round-trip@v1',
        fn (): RoundTripAgent => new RoundTripAgent,
        fn (Agent $agent): bool => $agent instanceof RoundTripAgent,
    );
});

/** Pause the run and return the tool call id Verdict issued a receipt for. */
function pauseForApproval(RoundTripAgent $agent): string
{
    $paused = $agent->prompt('Please cancel order '.ORDER_ID.'.');

    expect($paused->hasPendingApprovals())->toBeTrue('A confirmation-gated capability must pause the run.')
        ->and(app(RoundTripLedger::class)->executions)->toBe(0, 'The executor must not run before a human decides.');

    return $paused->pendingApprovals->first()->id;
}

it('projects a Verdict-backed pause into one drivable console row', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    $agent = (new RoundTripAgent)->forParticipant(new RoundTripCustomer(7));
    $toolCallId = pauseForApproval($agent);

    $row = StoredPendingApproval::query()->sole();

    expect($row->tool_call_id)->toBe($toolCallId)
        ->and($row->receipt_id)->not->toBeNull()
        ->and($row->conversation_id)->not->toBeNull()
        ->and($row->invocation_id)->not->toBeNull()
        ->and($row->resolver_key)->toBe('round-trip@v1')
        ->and($row->resumability)->toBe(Resumability::Drivable)
        ->and($row->unresumable_reason)->toBeNull()
        ->and($row->getRawOriginal('presentation'))->not->toContain((string) ORDER_ID);
});

it('records a participant-bound pause as unresumable without a durable participant round trip', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);
    app()->instance(ConversationParticipants::class, new UnconfiguredConversationParticipants);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    $row = StoredPendingApproval::query()->sole();

    expect($row->resumability)->toBe(Resumability::Unresumable)
        ->and($row->participant_reference)->toBeNull()
        ->and($row->unresumable_reason)->toBe(UnresumableReason::ParticipantUnresolvable);
    Event::assertDispatched(ApprovalIngestionIncident::class, fn (ApprovalIngestionIncident $incident): bool => $incident->reason === UnresumableReason::ParticipantUnresolvable);
});

it('rejects a participant reference that rebuilds to a different Laravel AI identity', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);
    app()->instance(ConversationParticipants::class, new class implements ConversationParticipants
    {
        public function referenceFor(object $participant): string
        {
            return 'customer:7';
        }

        public function resolve(string $reference): object
        {
            return new RoundTripCustomer(8);
        }
    });
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    expect(StoredPendingApproval::query()->sole()->unresumable_reason)
        ->toBe(UnresumableReason::ParticipantUnresolvable);
});

it('allows a participant-less pause without a participant reference', function (): void {
    app()->instance(ConversationParticipants::class, new UnconfiguredConversationParticipants);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    pauseForApproval(new RoundTripAgent);

    $row = StoredPendingApproval::query()->sole();

    expect($row->resumability)->toBe(Resumability::Drivable)
        ->and($row->participant_reference)->toBeNull()
        ->and($row->unresumable_reason)->toBeNull();
});

it('captures a host-supplied opaque participant reference at the pause boundary', function (): void {
    app()->instance(ConversationParticipants::class, new class implements ConversationParticipants
    {
        public function referenceFor(object $participant): string
        {
            return 'customer:'.($participant instanceof RoundTripCustomer ? $participant->id : throw new LogicException('Unexpected participant.'));
        }

        public function resolve(string $reference): object
        {
            return new RoundTripCustomer((int) substr($reference, strlen('customer:')));
        }
    });
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    expect(StoredPendingApproval::query()->sole()->participant_reference)->toBe('customer:7');
});

it('retains a drivable row when the host presenter fails', function (): void {
    Log::spy();
    app()->instance(ApprovalPresenter::class, new class implements ApprovalPresenter
    {
        public function present(LaravelPendingApproval $approval, ?ApprovalChallenge $challenge = null): ApprovalPresentation
        {
            throw new RuntimeException('host presenter blew up');
        }
    });
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    $row = StoredPendingApproval::query()->sole();

    expect($row->resumability)->toBe(Resumability::Drivable)
        ->and($row->presentation)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Verdict Console could not create an approval presentation.'
            && $context['tool_call_id'] === TOOL_CALL_ID
            && $context['exception'] === RuntimeException::class,
        );
});

/**
 * The presenter guard has to cover the *encode*, not just the call.
 *
 * `ApprovalPresentation::details` is host-owned `array<string, mixed>`, so a presenter can return
 * successfully and still hand back something JSON cannot represent — invalid UTF-8, a resource, an
 * INF. `toArray()` does not care; the store's `json_encode(..., JSON_THROW_ON_ERROR)` does, and it
 * runs *after* the presenter guard has already been passed. Left uncovered, that `JsonException`
 * reaches the per-item catch-all, gets filed as a malformed sibling, and **no row is written** — the
 * one outcome §6.3 says a presenter failure must never produce, arrived at through the back door.
 *
 * So the encode is validated where the guard is, and an unencodable presentation degrades exactly
 * like a throwing presenter: the row survives, drivability is untouched, the presentation is null.
 */
it('retains a drivable row when the host presentation cannot be encoded', function (): void {
    Log::spy();
    app()->instance(ApprovalPresenter::class, new class implements ApprovalPresenter
    {
        public function present(LaravelPendingApproval $approval, ?ApprovalChallenge $challenge = null): ApprovalPresentation
        {
            // Returns cleanly. Only the encode discovers the problem.
            return new ApprovalPresentation(
                tool: $approval->tool,
                argumentsFingerprint: hash('sha256', 'arguments'),
                details: ['host_value' => "\xB1\x31"],
            );
        }
    });
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    $row = StoredPendingApproval::query()->sole();

    expect($row->resumability)->toBe(Resumability::Drivable)
        ->and($row->unresumable_reason)->toBeNull()
        ->and($row->presentation)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Verdict Console could not create an approval presentation.'
            && $context['tool_call_id'] === TOOL_CALL_ID
            && $context['exception'] === JsonException::class,
        );
    Log::shouldNotHaveReceived('error');
});

/**
 * Validating the projection is not enough; it has to be *normalized*.
 *
 * `ApprovalPresentation::details` is `array<string, mixed>`, and `mixed` includes `JsonSerializable`
 * — host code that runs *during* encoding. A guard that encodes once to prove the value is fine, then
 * hands the original array on, lets the store encode it a **second** time and call that host code
 * again. A stateful implementation can pass the first call and fail the second, and the row is lost
 * exactly as before: the two encodes are not the same operation.
 *
 * So the boundary converts rather than checks. The array the store receives is decoded back from the
 * bytes that were proven to encode, which contains only JSON-native values — no objects, no host code
 * left to run. The store's encode cannot invoke anything, so it cannot fail for this class of input.
 *
 * The expected outcome is therefore stronger than the invalid-UTF-8 case: the presentation is not
 * merely absent-and-safe, it **survives**, because the first and only serialization succeeded.
 */
it('normalizes a presentation so a stateful JsonSerializable cannot be re-invoked by the store', function (): void {
    Log::spy();
    app()->instance(ApprovalPresenter::class, new class implements ApprovalPresenter
    {
        public function present(LaravelPendingApproval $approval, ?ApprovalChallenge $challenge = null): ApprovalPresentation
        {
            return new ApprovalPresentation(
                tool: $approval->tool,
                argumentsFingerprint: hash('sha256', 'arguments'),
                details: ['host_value' => new class implements JsonSerializable
                {
                    private int $calls = 0;

                    public function jsonSerialize(): mixed
                    {
                        // Fine once, unencodable thereafter. Only a second serialization sees it.
                        return ++$this->calls === 1 ? 'first-call-value' : "\xB1\x31";
                    }
                }],
            );
        }
    });
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    $row = StoredPendingApproval::query()->sole();

    expect($row->resumability)->toBe(Resumability::Drivable)
        ->and($row->unresumable_reason)->toBeNull()
        ->and($row->presentation['details']['host_value'])
        ->toBe('first-call-value', 'The one serialization that ran is what is stored.');
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
});

/** A receiptless Laravel AI approval is recorded, not silently lost or made console-actionable. */
it('records a receiptless pause as challenge unavailable and emits one incident', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);

    $approval = new LaravelPendingApproval(
        id: 'receiptless-call',
        tool: 'host_only_tool',
        arguments: ['secret' => 'must-not-persist'],
        reason: 'The host requested review.',
    );

    $event = new ToolApprovalRequested(
        invocationId: 'invocation-receiptless',
        agent: new RoundTripAgent,
        pendingApprovals: collect([$approval]),
        conversationId: 'conversation-receiptless',
    );

    event($event);
    event($event); // A redelivery must not produce a second incident for the same stored pause.

    $row = StoredPendingApproval::query()->sole();

    expect($row->receipt_id)->toBeNull()
        ->and($row->resolver_key)->toBeNull()
        ->and($row->resumability)->toBe(Resumability::Unresumable)
        ->and($row->unresumable_reason)->toBe(UnresumableReason::ChallengeUnavailable)
        ->and($row->getRawOriginal('presentation'))->not->toContain('must-not-persist');

    Event::assertDispatched(ApprovalIngestionIncident::class, fn (ApprovalIngestionIncident $incident): bool => $incident->pendingApproval->is($row)
            && $incident->reason === UnresumableReason::ChallengeUnavailable,
    );
    Event::assertDispatchedTimes(ApprovalIngestionIncident::class, 1);
});

it('isolates a malformed pending item so its sibling still ingests', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);
    Log::spy();

    event(new ToolApprovalRequested(
        invocationId: 'one-bad-sibling',
        agent: new RoundTripAgent,
        pendingApprovals: collect([new stdClass, new LaravelPendingApproval('healthy-sibling', 'host_only_tool', [])]),
        conversationId: 'sibling-conversation',
    ));

    expect(StoredPendingApproval::query()->sole()->tool_call_id)->toBe('healthy-sibling');
    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Verdict Console could not ingest a paused approval.'
            && $context['tool_call_id'] === null
            && $context['exception'] === TypeError::class,
        );
});

it('logs a critical lost-pause failure when the console table is unavailable', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);
    Log::spy();
    Schema::drop('verdict_console_pending_approvals');

    event(new ToolApprovalRequested(
        invocationId: 'unwritable-index',
        agent: new RoundTripAgent,
        pendingApprovals: collect([new LaravelPendingApproval('unwritable-call', 'host_only_tool', [])]),
        conversationId: 'unwritable-conversation',
    ));

    Event::assertNotDispatched(ApprovalIngestionIncident::class);
    Log::shouldHaveReceived('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Verdict Console could not durably record a paused approval.'
            && $context['tool_call_id'] === 'unwritable-call'
            && $context['exception'] === QueryException::class,
        );
});

it('logs a receipt collision as a critical anomaly rather than malformed input', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);
    $agent = (new RoundTripAgent)->forParticipant(new RoundTripCustomer(7));
    $paused = $agent->prompt('Please cancel order '.ORDER_ID.'.');
    Log::spy();

    event(new ToolApprovalRequested(
        invocationId: 'duplicate-receipt',
        agent: $agent,
        pendingApprovals: $paused->pendingApprovals,
        conversationId: 'different-conversation',
    ));

    expect(StoredPendingApproval::query()->count())->toBe(1);
    Log::shouldHaveReceived('critical')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Verdict Console detected one receipt indexed by multiple pauses.'
            && $context['tool_call_id'] === TOOL_CALL_ID
            && $context['exception'] === UniqueConstraintViolationException::class,
        );
});

it('logs the default warning for an ingestion incident until the ledger exists', function (): void {
    Log::spy();

    event(new ToolApprovalRequested(
        invocationId: 'warning-log-invocation',
        agent: new RoundTripAgent,
        pendingApprovals: collect([new LaravelPendingApproval('warning-log-call', 'host_only_tool', [])]),
        conversationId: 'warning-log-conversation',
    ));

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Verdict Console recorded a paused approval it cannot resume.'
            && $context['tool_call_id'] === 'warning-log-call'
            && $context['unresumable_reason'] === UnresumableReason::ChallengeUnavailable->value,
        );
});

/** The manager intentionally collapses an expired receipt and an absent one into the same null. */
it('records an expired receipt exactly like a receiptless pause', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    $agent = (new RoundTripAgent)->forParticipant(new RoundTripCustomer(7));
    $paused = $agent->prompt('Please cancel order '.ORDER_ID.'.');
    $approval = $paused->pendingApprovals->sole();

    // Set up the state Verdict must collapse. The bridge itself only observes the public manager's
    // null and never reads this table or infers that expiry was the reason.
    StoredPendingApproval::query()->delete();
    DB::table('verdict_approval_receipts')->where('tool_call_id', $approval->id)->update([
        'expires_at' => now()->subMinute(),
    ]);

    event(new ToolApprovalRequested(
        invocationId: 'delayed-delivery',
        agent: $agent,
        pendingApprovals: collect([$approval]),
        conversationId: $paused->conversationId,
        conversationUser: $paused->conversationUser,
    ));

    $row = StoredPendingApproval::query()->sole();

    expect($row->receipt_id)->toBeNull()
        ->and($row->resumability)->toBe(Resumability::Unresumable)
        ->and($row->unresumable_reason)->toBe(UnresumableReason::ChallengeUnavailable);

    Event::assertDispatched(ApprovalIngestionIncident::class, fn (ApprovalIngestionIncident $incident): bool => $incident->reason === UnresumableReason::ChallengeUnavailable);
});

it('records a receipt-backed pause whose agent has no resolver instead of refusing it', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);
    app()->instance(ResumableAgents::class, new AgentResolverRegistry);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    $row = StoredPendingApproval::query()->sole();

    expect($row->receipt_id)->not->toBeNull()
        ->and($row->resolver_key)->toBeNull()
        ->and($row->resumability)->toBe(Resumability::Unresumable)
        ->and($row->unresumable_reason)->toBe(UnresumableReason::AgentUnresolvable);

    Event::assertDispatched(ApprovalIngestionIncident::class, fn (ApprovalIngestionIncident $incident): bool => $incident->reason === UnresumableReason::AgentUnresolvable);
});

it('records a receipt-backed pause when a host matcher throws', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);
    $exploding = (new AgentResolverRegistry)->register(
        'round-trip@exploding',
        fn (): RoundTripAgent => new RoundTripAgent,
        fn (Agent $agent): bool => throw new RuntimeException('host matcher blew up'),
    );
    app()->instance(ResumableAgents::class, $exploding);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    $row = StoredPendingApproval::query()->sole();

    expect($row->receipt_id)->not->toBeNull()
        ->and($row->resumability)->toBe(Resumability::Unresumable)
        ->and($row->unresumable_reason)->toBe(UnresumableReason::AgentUnresolvable);
});

it('keeps a known resolver key when its factory stops rebuilding the agent', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);
    $broken = (new AgentResolverRegistry)->register(
        'round-trip@retired',
        fn (): object => throw new LogicException('the tenant is gone'),
        fn (Agent $agent): bool => $agent instanceof RoundTripAgent,
    );
    app()->instance(ResumableAgents::class, $broken);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    $row = StoredPendingApproval::query()->sole();

    expect($row->receipt_id)->not->toBeNull()
        ->and($row->resolver_key)->toBe('round-trip@retired')
        ->and($row->resumability)->toBe(Resumability::Unresumable)
        ->and($row->unresumable_reason)->toBe(UnresumableReason::AgentUnresolvable);
});

it('records a receipt-backed pause without a conversation as unresumable', function (): void {
    Event::fake([ApprovalIngestionIncident::class]);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID])),
    ]);

    $agent = (new RoundTripAgent)->forParticipant(new RoundTripCustomer(7));
    $paused = $agent->prompt('Please cancel order '.ORDER_ID.'.');

    // Laravel AI's real pause has a conversation. Re-deliver the same pending call without one to
    // prove the bridge does not call `continue()` later with an invented identifier.
    StoredPendingApproval::query()->delete();
    event(new ToolApprovalRequested(
        invocationId: 'conversationless-delivery',
        agent: $agent,
        pendingApprovals: $paused->pendingApprovals,
        conversationId: null,
    ));

    $row = StoredPendingApproval::query()->sole();

    expect($row->receipt_id)->not->toBeNull()
        ->and($row->resolver_key)->toBe('round-trip@v1')
        ->and($row->conversation_id)->toBeNull()
        ->and($row->resumability)->toBe(Resumability::Unresumable)
        ->and($row->unresumable_reason)->toBe(UnresumableReason::ConversationAbsent);

    Event::assertDispatched(ApprovalIngestionIncident::class, fn (ApprovalIngestionIncident $incident): bool => $incident->reason === UnresumableReason::ConversationAbsent);
});

/**
 * The round trip this package exists to automate, driven end to end with no network.
 *
 * VC-1's job is to force every design §12 hazard into the open before any of the runtime is built,
 * because this is the path where the design can be *wrong* rather than merely late.
 */
it('executes a confirmation-gated capability exactly once across a pause, an approval, and a resume', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))
            ->push($this->textResponse('Order cancelled.')),
    ]);

    $agent = (new RoundTripAgent)->forParticipant(new RoundTripCustomer(7));

    $toolCallId = pauseForApproval($agent);

    // The human decision happens in Verdict, through its own authenticated flow. The agent
    // framework's decision below is not a substitute for it — approving only there leaves the
    // receipt unapproved and the resume denies at execution.
    $approvals = app(VerdictManager::class)->approvals();
    $challenge = $approvals->challengeForToolCall($toolCallId);

    expect($challenge)->not->toBeNull('A pending receipt must yield a challenge for the approver to read.');

    $approvals->approve($challenge->receiptId, $challenge->toolCallId, 'operator-1');

    // A decision keyed by this exact tool call. `Decision::approveAll()` yields a wildcard that
    // `ApprovalExecutionContext::push()` deliberately skips, so a blanket approval from the agent
    // loop cannot authorize a specific consequential action.
    $agent->prompt(Decisions::from([$toolCallId => AiDecision::approve()]));

    expect(app(RoundTripLedger::class)->executions)->toBe(1, 'An approved, specifically-decided resume must execute exactly once.');

    Http::assertSentCount(2);
});

/**
 * The measurement behind the fourth drivability condition — the reason a participant-bound pause is
 * not `drivable` without a working {@see ConversationParticipants}.
 *
 * This is a **negative control over Laravel AI, not over this package.** Everything else about the
 * participant condition asserts how the bridge *reacts* to the upstream rule; those tests would all
 * still pass if the rule did not exist. This one exercises the rule itself, so it fails loudly if a
 * future laravel/ai relaxes it and the fourth condition stops being necessary.
 *
 * `DatabaseConversationStore::storeApprovalResults()` re-finds the paused assistant turn by
 * `participant_type`/`participant_id` as well as conversation id, and a null-participant resume
 * requires *both columns to be null* rather than skipping the filter — so the participant-bound turn
 * is excluded, not merely unmatched.
 *
 * **It fails after the action has already run, which is the part that matters.**
 * `TextGenerationLoop` calls `resumeFromApproval()` — executing the approved tools — and only then
 * hands the results to the recorder that throws (`TextGenerationLoop.php:88-94`). And the throw is
 * inside `storeApprovalResults()`'s own transaction, so the turn's update rolls back. The measured
 * end state is all three at once: the consequential action **executed**, the Verdict receipt is
 * **spent**, and the conversation still believes it is **waiting for a human**. This is not a resume
 * that harmlessly declines; it is a divergence between what happened and what is recorded, and it is
 * why ingestion refuses to call such a row drivable rather than discovering this after approval.
 */
it('cannot resume a participant-bound pause without rebuilding its participant', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))
            ->push($this->textResponse('Order cancelled.')),
    ]);

    $agent = (new RoundTripAgent)->forParticipant(new RoundTripCustomer(7));
    $toolCallId = pauseForApproval($agent);
    $conversationId = $agent->currentConversation();

    $approvals = app(VerdictManager::class)->approvals();
    $challenge = $approvals->challengeForToolCall($toolCallId);
    $approvals->approve($challenge->receiptId, $challenge->toolCallId, 'operator-1');

    // Exactly what VC-6 would do with a row whose participant reference could not be rebuilt: the
    // right conversation id, the right tool call, the right decision — and no participant.
    $rebuilt = app(ResumableAgents::class)->resolve('round-trip@v1')->continue($conversationId, null);

    expect(fn () => $rebuilt->prompt(Decisions::from([$toolCallId => AiDecision::approve()])))
        ->toThrow(ApprovalMismatchException::class);

    // The three halves of the divergence, asserted rather than described. If a future laravel/ai
    // records before it executes, or drops the participant filter, one of these changes and this
    // test says so.
    expect(app(RoundTripLedger::class)->executions)
        ->toBe(1, 'The approved action runs before the recorder that rejects it.')
        ->and($approvals->challengeForToolCall($toolCallId))
        ->toBeNull('The Verdict receipt is spent by the resume that then fails.')
        ->and(DB::table(config('ai.conversations.tables.messages'))->whereNotNull('approval_state')->count())
        ->toBe(1, 'The turn still awaits a human: the recorder threw inside its own transaction.');
});

/**
 * The other half of "never hangs": a denial has to end the run cleanly rather than leave the agent
 * waiting on a decision that already happened.
 */
it('returns a clean refusal without executing when the human denies', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))
            ->push($this->textResponse('I did not cancel the order.')),
    ]);

    $agent = (new RoundTripAgent)->forParticipant(new RoundTripCustomer(7));

    $toolCallId = pauseForApproval($agent);

    $approvals = app(VerdictManager::class)->approvals();
    $challenge = $approvals->challengeForToolCall($toolCallId);
    $approvals->reject($challenge->receiptId, $challenge->toolCallId, 'operator-1');

    $resumed = $agent->prompt(Decisions::from([$toolCallId => AiDecision::reject()]));

    expect($resumed)->not->toBeNull('A denied resume must return, not hang.')
        ->and($resumed->hasPendingApprovals())->toBeFalse('A decided call must not still be pending.')
        ->and(app(RoundTripLedger::class)->executions)->toBe(0, 'A denial must not execute the capability.');
});

it('approves through the console and resumes the captured participant-bound conversation exactly once', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))
            ->push($this->textResponse('Order cancelled.')),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));
    $transition = app(ApprovalResolutionService::class)->approve(StoredPendingApproval::query()->sole(), new GenericUser(['id' => 'operator-1']));

    expect($transition?->outcome->value)->toBe('approved')
        ->and(app(RoundTripLedger::class)->executions)->toBe(1)
        // A resume attempt is a console *action*, not a failure count: it is recorded for the
        // successful path too, so "attempts, no reconciliation record" reads as a clean resume rather
        // than as silence. VC-10 asks which attempt this is; it never asks how many went wrong.
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1, 'A successful resume is still an attempt.')
        ->and(StoredPendingApproval::query()->sole()->last_resume_attempt_at)->not->toBeNull()
        ->and(ApprovalReconciliation::query()->count())->toBe(0, 'Nothing diverged, so there is nothing to reconcile.');
});

/**
 * The reject path, driven through the console rather than through Laravel AI directly.
 *
 * Every other console control drives `approve()`, so this is the one that would catch a `reject()`
 * that silently took the approve branch — the outcome gate expects `Rejected` here, and a
 * copy-paste that left `ApprovalOutcome::Approved` in place would return the transition without
 * resuming, leaving the run paused forever with a spent receipt.
 *
 * Three facts, because a denial that merely fails to execute is not the same as a denial that
 * *resolves*: Verdict recorded `Rejected`, the run came back with nothing still pending, and the
 * capability never ran.
 */
it('rejects through the console and resumes to a clean refusal without executing', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))
            ->push($this->textResponse('I did not cancel the order.')),
    ]);

    $toolCallId = pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));
    $transition = app(ApprovalResolutionService::class)->reject(StoredPendingApproval::query()->sole(), new GenericUser(['id' => 'operator-1']));

    expect($transition?->outcome)->toBe(ApprovalOutcome::Rejected)
        ->and(app(RoundTripLedger::class)->executions)->toBe(0, 'A denial must not execute the capability.')
        ->and(app(VerdictManager::class)->approvals()->challengeForToolCall($toolCallId))
        ->toBeNull('A rejected receipt is resolved, so it offers no further challenge.');
});

/**
 * The reject path sends its own decision, not an approval.
 *
 * Asserted through the recording fixture because the difference is invisible downstream: a resume
 * carrying `Decision::approve()` under a rejected receipt fails at Verdict's execution gate, so the
 * capability still does not run and a test watching only the ledger would pass either way.
 */
it('rejects with a decision map containing only this tool call, marked rejected', function (): void {
    [$recorder, , $toolCallId] = pauseThenRecordResume(
        $this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]),
        approve: false,
    );

    expect($recorder->decisions)->not->toBeNull('The service must resume through the resolved agent.')
        ->and(array_keys($recorder->decisions->all()))->toBe([$toolCallId])
        ->and($recorder->decisions->get($toolCallId)?->isRejected())->toBeTrue();
});

it('returns the live-challenge null path on a second approve without resuming again', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))
            ->push($this->textResponse('Order cancelled.')),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));
    $service = app(ApprovalResolutionService::class);
    $row = StoredPendingApproval::query()->sole();
    $service->approve($row, new GenericUser(['id' => 'operator-1']));

    // A spent receipt has no live challenge. This deliberately asserts the return surface as well
    // as execution count, so an earlier row-state short circuit cannot satisfy the test.
    expect($service->approve($row, new GenericUser(['id' => 'operator-1'])))
        ->toBeNull()
        ->and(app(RoundTripLedger::class)->executions)->toBe(1);
});

it('does not resume when Verdict returns a non-approval transition', function (ApprovalOutcome $outcome): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    app()->instance(ApprovalReceiptStore::class, new ForcedApprovalOutcomeStore(app(ApprovalReceiptStore::class), $outcome));
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));
    $transition = null;

    // A non-approval transition must return normally. If this throws, Verdict's downstream
    // proposal validation—not this service's outcome gate—would be protecting the test.
    expect(function () use (&$transition): void {
        $transition = app(ApprovalResolutionService::class)->approve(StoredPendingApproval::query()->sole(), new GenericUser(['id' => 'operator-1']));
    })
        ->not->toThrow(ApprovalResumeFailed::class);

    expect($transition?->outcome)->toBe($outcome)
        ->and(app(RoundTripLedger::class)->executions)->toBe(0, 'Only an Approved transition may resume this tool call.');
})->with([
    'consumed' => [ApprovalOutcome::Consumed],
    'mismatch' => [ApprovalOutcome::Mismatch],
    'expired' => [ApprovalOutcome::Expired],
    'not_found' => [ApprovalOutcome::NotFound],
    'invalid_state' => [ApprovalOutcome::InvalidState],
]);

it('refuses an unauthorized approver before it can record or resume a decision', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => false);
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    expect(fn () => app(ApprovalResolutionService::class)->approve(StoredPendingApproval::query()->sole(), new GenericUser(['id' => 'operator-1'])))
        ->toThrow(AuthorizationException::class);
    expect(app(RoundTripLedger::class)->executions)->toBe(0)
        ->and(app(VerdictManager::class)->approvals()->challengeForToolCall(TOOL_CALL_ID))->not->toBeNull();
});

it('refuses a known unresumable row before it can spend its live receipt', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))]);

    $toolCallId = pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));
    $row = StoredPendingApproval::query()->sole();
    $row->resumability = Resumability::Unresumable;
    $row->unresumable_reason = UnresumableReason::AgentUnresolvable;

    expect(fn () => app(ApprovalResolutionService::class)->approve($row, new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalNotDrivable::class, 'agent_unresolvable');
    expect(app(VerdictManager::class)->approvals()->challengeForToolCall($toolCallId))->not->toBeNull();
});

it('reports an unresumable legacy row with no stored reason as unknown before it can spend its receipt', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))]);

    $toolCallId = pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));
    $row = StoredPendingApproval::query()->sole();
    $row->resumability = Resumability::Unresumable;
    $row->unresumable_reason = null;

    expect(fn () => app(ApprovalResolutionService::class)->approve($row, new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalNotDrivable::class, 'unknown');
    expect(app(VerdictManager::class)->approvals()->challengeForToolCall($toolCallId))->not->toBeNull();
});

it('refuses a corrupt drivable row without resume context before it can spend its receipt', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))]);

    $toolCallId = pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));
    $row = StoredPendingApproval::query()->sole();
    $row->resolver_key = null;

    expect(fn () => app(ApprovalResolutionService::class)->approve($row, new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalNotDrivable::class, 'marked drivable but lacks its captured resume context');
    expect(app(VerdictManager::class)->approvals()->challengeForToolCall($toolCallId))->not->toBeNull();
});

/**
 * Pause for real, then make the stored resolver key rebuild a recorder instead of the live agent.
 *
 * @param  array<string, mixed>  $toolCallResponse  the caller's, because it is a TestCase method
 * @param  ?RoundTripCustomer  $participant  null pauses a genuinely participant-less run
 * @return array{0: RecordingResumableAgent, 1: StoredPendingApproval, 2: string}
 */
function pauseThenRecordResume(array $toolCallResponse, bool $approve = true, ?RoundTripCustomer $participant = new RoundTripCustomer(7)): array
{
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake(['*/chat/completions' => Http::sequence()->push($toolCallResponse)]);

    $agent = new RoundTripAgent;
    $toolCallId = pauseForApproval($participant === null ? $agent : $agent->forParticipant($participant));
    $recorder = new RecordingResumableAgent;

    /** @var AgentResolverRegistry $resolvers */
    $resolvers = app(ResumableAgents::class);
    $resolvers->register('round-trip@v1', fn (): RecordingResumableAgent => $recorder, fn (Agent $agent): bool => $agent instanceof RoundTripAgent);

    $row = StoredPendingApproval::query()->sole();
    $service = app(ApprovalResolutionService::class);
    $approve
        ? $service->approve($row, new GenericUser(['id' => 'operator-1']))
        : $service->reject($row, new GenericUser(['id' => 'operator-1']));

    return [$recorder, $row, $toolCallId];
}

/**
 * The wildcard guard, asserted as an exact key set rather than the absence of `'*'`.
 *
 * `Decision::approveAll()` is not the only route to a wildcard: `Decisions::approveRemaining()` and
 * `rejectRemaining()` both splat `'*'` into the map, so a test that greps for one method name would
 * miss the other two. An exact key set catches all three — and also catches a future refactor that
 * batches sibling tool calls into a single resume, which would authorize calls no human decided on.
 */
it('resumes with a decision map containing only this tool call', function (): void {
    [$recorder, , $toolCallId] = pauseThenRecordResume($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]));

    expect($recorder->decisions)->not->toBeNull('The service must resume through the resolved agent.')
        ->and(array_keys($recorder->decisions->all()))->toBe([$toolCallId])
        ->and($recorder->decisions->get($toolCallId)?->isApproved())->toBeTrue();
});

/**
 * The continuation-method guard. Both arguments are asserted, not just the method: VC-5's negative
 * control showed that resuming a participant-bound pause with the wrong participant strands an
 * approved receipt, so "it called continue()" is only half the requirement.
 */
/**
 * The mirror of the participant-bound rule, and the reason it cannot be assumed from that one.
 *
 * Laravel AI's `storeApprovalResults()` does not skip the participant filter when the resuming agent
 * carries none — it requires `participant_type` **and** `participant_id` to be *null*. So attaching
 * something to a genuinely participant-less turn excludes it for the exact mirror-image reason that
 * attaching nothing excludes a participant-bound one, and strands the run the same way.
 *
 * VC-5 proves such a pause is recorded `drivable` with no reference. This proves VC-6 then resumes it
 * with no attachment, which is the half that would otherwise be inferred from a passing round trip
 * rather than observed.
 */
it('resumes a participant-less row with no attachment at all', function (): void {
    [$recorder, $row] = pauseThenRecordResume(
        $this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]),
        participant: null,
    );

    expect($row->participant_reference)->toBeNull('VC-5 records a participant-less pause without a reference.')
        ->and($recorder->continuations)->toHaveCount(1)
        ->and($recorder->continuations[0]['conversationId'])->toBe($row->conversation_id)
        ->and($recorder->continuations[0]['participant'])->toBeNull('Attaching one would exclude the paused turn.');
});

it('resumes the exact captured conversation and participant, never the latest one', function (): void {
    [$recorder, $row] = pauseThenRecordResume($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]));

    expect($recorder->continuations)->toHaveCount(1);

    $continuation = $recorder->continuations[0];

    expect($continuation['conversationId'])->toBe($row->conversation_id)
        ->and($continuation['participant'])->toBeInstanceOf(RoundTripCustomer::class)
        ->and($continuation['participant']->id)->toBe(7);
});

it('wraps a participant mismatch raised by continuation after Verdict records approval', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))
            ->push($this->textResponse('Order cancelled.')),
    ]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));
    app()->instance(ConversationParticipants::class, new class implements ConversationParticipants
    {
        public function referenceFor(object $participant): string
        {
            return 'customer:7';
        }

        public function resolve(string $reference): object
        {
            return new RoundTripCustomer(8);
        }
    });

    expect(fn () => app(ApprovalResolutionService::class)->approve(StoredPendingApproval::query()->sole(), new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalResumeFailed::class);
    expect(app(RoundTripLedger::class)->executions)->toBe(1)
        ->and(ApprovalReconciliation::query()->sole()->phase)->toBe(ResumeFailurePhase::Indeterminate)
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1);
});

it('records a resolver failure before prompt as definitely pre-execution', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    $broken = (new AgentResolverRegistry)->register(
        'round-trip@v1',
        fn (): object => throw new LogicException('host resolver failed before prompt'),
        fn (Agent $agent): bool => $agent instanceof RoundTripAgent,
    );
    app()->instance(ResumableAgents::class, $broken);

    expect(fn () => app(ApprovalResolutionService::class)->approve(StoredPendingApproval::query()->sole(), new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalResumeFailed::class);

    expect(app(RoundTripLedger::class)->executions)->toBe(0)
        ->and(ApprovalReconciliation::query()->sole()->phase)->toBe(ResumeFailurePhase::DefinitelyPreExecution)
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1)
        ->and(StoredPendingApproval::query()->sole()->last_resume_attempt_at)->not->toBeNull();
});

it('propagates Verdicts outer transaction guard rather than replacing it', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(TOOL_CALL_ID, 'CancelOrderTool', ['order_id' => ORDER_ID]))]);

    pauseForApproval((new RoundTripAgent)->forParticipant(new RoundTripCustomer(7)));

    expect(fn () => DB::transaction(fn () => app(ApprovalResolutionService::class)->approve(StoredPendingApproval::query()->sole(), new GenericUser(['id' => 'operator-1']))))
        ->toThrow(UnsafeOuterTransaction::class);
});

/**
 * The trap that makes every other assertion here vacuous, asserted directly so it cannot rot: a
 * capability that asks for confirmation without an execution-target policy never pauses at all.
 */
it('never pauses when a confirmation-gated capability has no execution-target policy', function (): void {
    app(VerdictManager::class)->capability(
        Capability::usingPolicy(
            name: 'orders.cancel-no-target',
            ability: 'update',
            resolveTarget: fn (ActionEnvelope $e): RoundTripOrder => new RoundTripOrder((int) $e->proposal->arguments['order_id']),
        )
            ->requiresConfirmation(fn (ActionEnvelope $e, RoundTripOrder $t): array => ['order_id' => $t->id])
            ->executeUsing(fn (AuthorizedAction $a): string => 'Order cancelled.'),
    );

    $tool = app(VerdictManager::class)->bound(new CancelOrderTool, 'orders.cancel-no-target', new ActionContext('customer-7'));

    expect($tool->shouldRequestApproval(new Request(['order_id' => ORDER_ID], 'no-target-call')))->toBeNull(
        'A confirmation gate with no execution target never asks Laravel AI to pause, so it never reaches this package. '
        .'The preflight doctor (VC-3) exists to catch this before it reaches a deployment.',
    );
});
