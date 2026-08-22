<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Tests\EndToEndTestCase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decision as AiDecision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
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
});

/** Pause the run and return the tool call id Verdict issued a receipt for. */
function pauseForApproval(RoundTripAgent $agent): string
{
    $paused = $agent->prompt('Please cancel order '.ORDER_ID.'.');

    expect($paused->hasPendingApprovals())->toBeTrue('A confirmation-gated capability must pause the run.')
        ->and(app(RoundTripLedger::class)->executions)->toBe(0, 'The executor must not run before a human decides.');

    return $paused->pendingApprovals->first()->id;
}

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
