<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Approvals\PendingApproval as StoredPendingApproval;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Fissible\VerdictConsole\Contracts\ApprovalPresenter;
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Events\ApprovalIngestionIncident;
use Fissible\VerdictConsole\Presentation\ApprovalPresentation;
use Fissible\VerdictConsole\Tests\EndToEndTestCase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Promptable;
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

    $this->app->instance(RoundTripLedger::class, new RoundTripLedger);

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

it('captures a host-supplied opaque participant reference at the pause boundary', function (): void {
    app()->instance(ConversationParticipants::class, new class implements ConversationParticipants
    {
        public function referenceFor(object $participant): ?string
        {
            return $participant instanceof RoundTripCustomer ? 'customer:'.$participant->id : null;
        }

        public function resolve(string $reference): ?object
        {
            return null;
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
