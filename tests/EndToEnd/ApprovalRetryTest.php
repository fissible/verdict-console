<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationKey;
use Fissible\VerdictConsole\Approvals\ApprovalReconciliation;
use Fissible\VerdictConsole\Approvals\ApprovalReconciliationStore;
use Fissible\VerdictConsole\Approvals\ApprovalResolutionService;
use Fissible\VerdictConsole\Approvals\CloseOutcome;
use Fissible\VerdictConsole\Approvals\PendingApproval as StoredPendingApproval;
use Fissible\VerdictConsole\Approvals\ResumeFailurePhase;
use Fissible\VerdictConsole\Approvals\RetryOutcome;
use Fissible\VerdictConsole\Contracts\ApprovalNotificationRecipients;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Exceptions\ApprovalNotDrivable;
use Fissible\VerdictConsole\Exceptions\ApprovalResumeFailed;
use Fissible\VerdictConsole\Exceptions\NoContinuationToRetry;
use Fissible\VerdictConsole\Notifications\ApprovalResumeOutcomeNotification;
use Fissible\VerdictConsole\Tests\EndToEndTestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Tools\Request;

/**
 * VC-86: the durable-retry path for a decision Verdict accepted but Laravel AI could not resume.
 *
 * The decision is re-read live through the status read at retry time — never persisted in console
 * state — and re-sent as the same tool-call-id-keyed continuation the original resolution would
 * have driven. Fixtures are this file's own.
 */
const RETRY_TOOL_CALL_ID = 'call_retry';
const RETRY_ORDER_ID = 8601;

/** Counts executions across a whole test. At-most-once is the number this suite is really about. */
final class RetryLedger
{
    public int $executions = 0;
}

final readonly class RetryOrder
{
    public function __construct(public int $id) {}
}

final readonly class RetryCustomer
{
    public function __construct(public int $id) {}
}

final class RetryNotificationRecipient
{
    use Notifiable;

    public function __construct(private readonly string $key) {}

    public function getKey(): string
    {
        return $this->key;
    }
}

/** Faithfully rebuilds the simple participant fixture by its host-owned opaque reference. */
final class RetryParticipants implements ConversationParticipants
{
    public function referenceFor(object $participant): string
    {
        if (! $participant instanceof RetryCustomer) {
            throw new LogicException('Unexpected retry participant.');
        }

        return 'customer:'.$participant->id;
    }

    public function resolve(string $reference): object
    {
        if (! preg_match('/^customer:(\d+)$/', $reference, $matches)) {
            throw new LogicException('Unknown retry participant reference.');
        }

        return new RetryCustomer((int) $matches[1]);
    }
}

/** Forces status views per read path, so the retry's routing and its derived decision are observable. */
final class RetryForcedStatuses implements ApprovalStatusReader
{
    /** @var list<string> every read made through this reader, in order, as "method:key" */
    public array $reads = [];

    public function __construct(
        private readonly ?ApprovalStatusView $byReceiptId = null,
        private readonly ?ApprovalStatusView $byToolCall = null,
    ) {}

    public function statusFor(string $receiptId): ?ApprovalStatusView
    {
        $this->reads[] = 'statusFor:'.$receiptId;

        return $this->byReceiptId;
    }

    public function statusForToolCall(string $toolCallId): ?ApprovalStatusView
    {
        $this->reads[] = 'statusForToolCall:'.$toolCallId;

        return $this->byToolCall;
    }

    public function pendingWithin(array $scope): array
    {
        return [];
    }
}

/** @param array<string, string|int>|null $context */
function retryForcedView(
    ApprovalReceiptStatus $status,
    string $receiptId,
    string $toolCallId = RETRY_TOOL_CALL_ID,
): ApprovalStatusView {
    return new ApprovalStatusView(
        receiptId: $receiptId,
        toolCallId: $toolCallId,
        capability: 'retry.orders.cancel',
        status: $status,
        reason: null,
        expiresAt: new DateTimeImmutable('2030-01-02T03:04:05+00:00'),
        approvedBy: $status === ApprovalReceiptStatus::Approved ? 'operator-1' : null,
        approvedAt: null,
        rejectedBy: $status === ApprovalReceiptStatus::Rejected ? 'operator-1' : null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: new DateTimeImmutable('2026-08-30T09:00:00+00:00'),
        approvalContext: null,
    );
}

final class RetryCancelOrderTool implements Tool
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

/** Builds the bound tool, registering the confirmation-gated capability once per container. */
function retryBoundTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('retry.orders.cancel')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'retry.orders.cancel',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): RetryOrder => new RetryOrder((int) $e->proposal->arguments['order_id']),
            )
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'retry-target',
                    identityUsing: fn (ActionEnvelope $e, RetryOrder $t): array => ['id' => $t->id],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, RetryOrder $t): array => ['order_id' => $t->id])
                ->executeUsing(function (AuthorizedAction $a): string {
                    app(RetryLedger::class)->executions++;

                    return 'Order cancelled.';
                }),
        );
    }

    return $verdict->bound(new RetryCancelOrderTool, 'retry.orders.cancel', new ActionContext('retry-customer'));
}

final class RetryAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'Cancel orders when asked.';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [retryBoundTool()];
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

/** Records the continuation the retry constructs instead of executing it (service-boundary control). */
final class RetryRecordingAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
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

    #[Override]
    public function continueLastConversation(object $as): static
    {
        throw new RuntimeException('A retry must resume an exact conversation id, never the participant\'s latest.');
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

        return new AgentResponse('retry-recording-invocation', '', new Usage, new Meta);
    }

    public function instructions(): Stringable|string
    {
        return 'Records what it is asked to resume.';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [retryBoundTool()];
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

/** Swap in a resolver whose factory fails, so a continuation dies definitely before any prompt. */
function retryResolverBroken(): void
{
    $broken = new AgentResolverRegistry;
    $broken->register(
        'retry@v1',
        fn (): object => throw new LogicException('host resolver offline'),
        fn (Agent $agent): bool => $agent instanceof RetryAgent,
    );
    app()->instance(ResumableAgents::class, $broken);
}

/** Restore the working resolver the retry needs. */
function retryResolverRestored(): void
{
    $working = new AgentResolverRegistry;
    $working->register(
        'retry@v1',
        fn (): RetryAgent => new RetryAgent,
        fn (Agent $agent): bool => $agent instanceof RetryAgent,
    );
    app()->instance(ResumableAgents::class, $working);
}

beforeEach(function (): void {
    $this->migrateRoundTripTables();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_approval_notifications_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_approval_reconciliations_table.php.stub')->up();

    $this->app->instance(RetryLedger::class, new RetryLedger);
    $this->app->instance(ConversationParticipants::class, new RetryParticipants);

    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('retry test');
        }
    });

    retryResolverRestored();
});

/** Pause the run and return the tool call id Verdict issued a receipt for. */
function retryPause(RetryAgent $agent): string
{
    $paused = $agent->prompt('Please cancel order '.RETRY_ORDER_ID.'.');

    expect($paused->hasPendingApprovals())->toBeTrue('Fixture: the confirmation-gated run must pause.')
        ->and(app(RetryLedger::class)->executions)->toBe(0);

    return $paused->pendingApprovals->first()->id;
}

/**
 * A real pause whose decision Verdict recorded but whose continuation failed before any prompt:
 * receipt approved (or rejected), nothing executed, one pre-execution reconciliation, one attempt.
 */
function decidedButUnresumed(bool $approve = true): StoredPendingApproval
{
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(test()->toolCallResponse(RETRY_TOOL_CALL_ID, 'RetryCancelOrderTool', ['order_id' => RETRY_ORDER_ID]))
            ->push(test()->textResponse('Order cancelled.')),
    ]);

    retryPause((new RetryAgent)->forParticipant(new RetryCustomer(7)));
    retryResolverBroken();
    $row = StoredPendingApproval::query()->sole();
    $service = app(ApprovalResolutionService::class);

    try {
        $approve
            ? $service->approve($row, new GenericUser(['id' => 'operator-1']))
            : $service->reject($row, new GenericUser(['id' => 'operator-1']));

        throw new LogicException('Fixture: the continuation must fail.');
    } catch (ApprovalResumeFailed) {
    }

    retryResolverRestored();

    return $row->refresh();
}

it('retries an approved-but-unresumed continuation and the tool executes exactly once', function (): void {
    $row = decidedButUnresumed();

    // The stranded state the retry exists for: decision durable, nothing executed, failure durable.
    expect(DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->value('status'))->toBe('approved')
        ->and(app(RetryLedger::class)->executions)->toBe(0)
        ->and(ApprovalReconciliation::query()->sole()->phase)->toBe(ResumeFailurePhase::DefinitelyPreExecution)
        ->and($row->resume_attempts)->toBe(1);

    $outcome = app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-2']));

    expect($outcome)->toBe(RetryOutcome::ResumedApproval)
        ->and(app(RetryLedger::class)->executions)->toBe(1, 'The approved tool executes exactly once.')
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->first())
        // The receipt shows the original decision untouched: a retry re-sends a continuation and
        // performs no second Verdict transition — not even under a different operator.
        ->toMatchArray(['status' => 'consumed', 'approved_by' => 'operator-1', 'rejected_by' => null])
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(2, 'Retry attempts ride the existing counter.');

    Http::assertSentCount(2);
});

it('refuses a second retry of a consumed receipt, keeping execution at most once', function (): void {
    $row = decidedButUnresumed();
    $service = app(ApprovalResolutionService::class);

    expect($service->retry($row, new GenericUser(['id' => 'operator-1'])))->toBe(RetryOutcome::ResumedApproval);

    Notification::fake();

    expect($service->retry($row->refresh(), new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::ReceiptConsumed)
        ->and(app(RetryLedger::class)->executions)->toBe(1)
        // A refused retry attempts no resume, so it spends no attempt.
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(2);

    // A refusal is not an observation of anything new; it announces nothing.
    Notification::assertNothingSent();
});

it('retries a rejected-but-unresumed continuation to a clean refusal without executing', function (): void {
    $row = decidedButUnresumed(approve: false);

    expect(DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->value('status'))->toBe('rejected');

    $outcome = app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-2']));

    expect($outcome)->toBe(RetryOutcome::ResumedRejection)
        ->and(app(RetryLedger::class)->executions)->toBe(0)
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(2, 'A resumed rejection spends a real attempt.')
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->first())
        ->toMatchArray(['status' => 'rejected', 'rejected_by' => 'operator-1']);

    // The refusal genuinely reached Laravel AI: the turn is no longer resumable, which a close
    // relays as the measured already-resolved answer rather than closing anything.
    expect(app(ApprovalResolutionService::class)->close($row->refresh(), new GenericUser(['id' => 'operator-1'])))
        ->toBe(CloseOutcome::AlreadyResolved);

    // Measured on the reject round trip: a bare rejection ends the turn without another model call.
    Http::assertSentCount(1);
});

/**
 * The dangerous half of at-most-once: an indeterminate failure where the tool DID run before the
 * continuation died. The receipt is consumed, and the retry must read that and refuse — never
 * re-drive an execution because a failure report looked retryable.
 */
it('never re-executes after an indeterminate failure that already ran the tool', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse(RETRY_TOOL_CALL_ID, 'RetryCancelOrderTool', ['order_id' => RETRY_ORDER_ID]))
            ->push($this->textResponse('Order cancelled.')),
    ]);

    retryPause((new RetryAgent)->forParticipant(new RetryCustomer(7)));
    app()->instance(ConversationParticipants::class, new class implements ConversationParticipants
    {
        public function referenceFor(object $participant): string
        {
            return 'customer:7';
        }

        public function resolve(string $reference): object
        {
            return new RetryCustomer(8);
        }
    });
    $row = StoredPendingApproval::query()->sole();

    expect(fn () => app(ApprovalResolutionService::class)->approve($row, new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalResumeFailed::class);
    expect(app(RetryLedger::class)->executions)->toBe(1)
        ->and(ApprovalReconciliation::query()->sole()->phase)->toBe(ResumeFailurePhase::Indeterminate)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->value('status'))->toBe('consumed');

    // The participant round-trips again; only the receipt's consumed status may stop this retry.
    $this->app->instance(ConversationParticipants::class, new RetryParticipants);

    expect(app(ApprovalResolutionService::class)->retry($row->refresh(), new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::ReceiptConsumed)
        ->and(app(RetryLedger::class)->executions)->toBe(1, 'Retry after an executed indeterminate failure must not run the tool again.');
});

/**
 * A rejected receipt whose turn was already closed: the receipt still reads Rejected, so the retry
 * attempts the resume — and must relay Laravel AI's measured already-resolved answer rather than
 * reporting a successful retry it did not perform.
 */
it('reports a turn Laravel AI already resolved instead of calling the retry successful', function (): void {
    $row = decidedButUnresumed(approve: false);
    $service = app(ApprovalResolutionService::class);

    expect($service->close($row, new GenericUser(['id' => 'operator-1'])))->toBe(CloseOutcome::Closed);

    expect($service->retry($row->refresh(), new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::AlreadyResumed)
        ->and(app(RetryLedger::class)->executions)->toBe(0);
});

/**
 * A close-path reconciliation leaves the receipt undecided. There is no decision to re-send, and
 * inventing one would be the auto-reject ADR 0029 forbids: the retry refuses and the decide/close
 * paths keep owning the row.
 */
it('does not retry a decision nobody made', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(RETRY_TOOL_CALL_ID, 'RetryCancelOrderTool', ['order_id' => RETRY_ORDER_ID]))]);

    retryPause((new RetryAgent)->forParticipant(new RetryCustomer(7)));
    DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->update(['expires_at' => now()->subMinute()]);
    retryResolverBroken();
    $row = StoredPendingApproval::query()->sole();

    expect(fn () => app(ApprovalResolutionService::class)->close($row, new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalResumeFailed::class);

    retryResolverRestored();
    Notification::fake();

    expect(app(ApprovalResolutionService::class)->retry($row->refresh(), new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::DecisionStillPending)
        ->and(app(RetryLedger::class)->executions)->toBe(0)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->value('status'))->toBe('pending');

    Notification::assertNothingSent();
});

it('says when the receipt vanished rather than guessing a decision', function (): void {
    $row = decidedButUnresumed();
    DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->delete();

    expect(app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::ReceiptUnavailable)
        ->and(app(RetryLedger::class)->executions)->toBe(0)
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1);
});

/** Retry is the reconciliation path: without a recorded failed continuation there is nothing to retry. */
it('throws when no failed continuation exists to retry', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake(['*/chat/completions' => Http::sequence()->push($this->toolCallResponse(RETRY_TOOL_CALL_ID, 'RetryCancelOrderTool', ['order_id' => RETRY_ORDER_ID]))]);

    retryPause((new RetryAgent)->forParticipant(new RetryCustomer(7)));
    $row = StoredPendingApproval::query()->sole();

    expect(fn () => app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-1'])))
        ->toThrow(NoContinuationToRetry::class);
    expect(app(RetryLedger::class)->executions)->toBe(0)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->value('status'))->toBe('pending');
});

it('refuses a retry the host Gate denies, resuming nothing', function (): void {
    $row = decidedButUnresumed();
    Gate::define('approve-verdict-action', fn (): bool => false);

    expect(fn () => app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-2'])))
        ->toThrow(AuthorizationException::class);
    expect(app(RetryLedger::class)->executions)->toBe(0)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->value('status'))->toBe('approved')
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1);
});

/**
 * Abandonment is an operator's closure note, not a lock: an explicit later retry supersedes it and
 * leaves the note untouched, so the record still says when the strand was first written off.
 */
it('retries an abandoned reconciliation and leaves its abandonment record untouched', function (): void {
    $row = decidedButUnresumed();
    $store = app(ApprovalReconciliationStore::class);
    $abandoned = $store->markAbandoned($store->find($row) ?? throw new LogicException('Fixture: reconciliation must exist.'));

    expect(app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::ResumedApproval)
        ->and(app(RetryLedger::class)->executions)->toBe(1)
        ->and(ApprovalReconciliation::query()->sole()->abandoned_at?->toIso8601String())
        ->toBe($abandoned->abandoned_at?->toIso8601String());
});

/** A retry that fails the same way keeps one record, the first phase, and the row's abandonability. */
it('re-detects nothing new when the retry fails the same way', function (): void {
    $row = decidedButUnresumed();
    retryResolverBroken();

    expect(fn () => app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalResumeFailed::class);
    expect(ApprovalReconciliation::query()->count())->toBe(1)
        ->and(ApprovalReconciliation::query()->sole()->phase)->toBe(ResumeFailurePhase::DefinitelyPreExecution)
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(2)
        ->and(app(RetryLedger::class)->executions)->toBe(0);

    $store = app(ApprovalReconciliationStore::class);

    expect($store->markAbandoned($store->find($row) ?? throw new LogicException('Fixture: reconciliation must exist.'))->abandoned_at)
        ->not->toBeNull('A row whose retry failed stays abandonable.');
});

/** VC-12's rule holds for retry: a foreign-scoped row answers like a row that does not exist, before any read. */
it('refuses a foreign-scoped row before reading or spending anything', function (): void {
    $row = decidedButUnresumed();
    $statuses = new RetryForcedStatuses;
    app()->instance(ApprovalStatusReader::class, $statuses);
    app()->instance(ApprovalScope::class, new class implements ApprovalScope
    {
        public function apply(Builder $query): Builder
        {
            return $query->whereRaw('1 = 0');
        }
    });

    expect(fn () => app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-1'])))
        ->toThrow(AuthorizationException::class);
    expect($statuses->reads)->toBe([], 'A hidden row is never read from Verdict.')
        ->and(app(RetryLedger::class)->executions)->toBe(0)
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1);
});

it('refuses an unresumable or context-stripped row without attempting a continuation', function (): void {
    $row = decidedButUnresumed();
    $row->forceFill(['resumability' => 'unresumable'])->save();

    expect(fn () => app(ApprovalResolutionService::class)->retry($row->refresh(), new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalNotDrivable::class);

    $row->forceFill(['resumability' => 'drivable'])->save();
    $stripped = $row->refresh();
    $stripped->resolver_key = null;

    expect(fn () => app(ApprovalResolutionService::class)->retry($stripped, new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalNotDrivable::class, 'marked drivable but lacks its captured resume context');
    expect(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1)
        ->and(app(RetryLedger::class)->executions)->toBe(0);
});

/**
 * The routing rule made falsifiable, and with it the source of the re-sent decision: the retry
 * reads by the row's receipt id and re-sends what the READER reports — here a rejection, while the
 * store still holds the approved receipt. An implementation reading the store, the challenge, or
 * remembering the original verb would resume an approval.
 */
it('re-sends the decision the status read reports, read by the receipt id', function (): void {
    $row = decidedButUnresumed();
    $statuses = new RetryForcedStatuses(byReceiptId: retryForcedView(ApprovalReceiptStatus::Rejected, (string) $row->receipt_id));
    app()->instance(ApprovalStatusReader::class, $statuses);
    $recorder = new RetryRecordingAgent;

    /** @var AgentResolverRegistry $resolvers */
    $resolvers = app(ResumableAgents::class);
    $resolvers->register('retry@v1', fn (): RetryRecordingAgent => $recorder, fn (Agent $agent): bool => $agent instanceof RetryAgent);

    expect(app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::ResumedRejection)
        ->and($statuses->reads)->toBe(['statusFor:'.$row->receipt_id])
        ->and($recorder->decisions?->get($row->tool_call_id)?->isApproved())->toBeFalse();
});

it('discards a status view that belongs to another tool call', function (): void {
    $row = decidedButUnresumed();
    app()->instance(ApprovalStatusReader::class, new RetryForcedStatuses(
        byReceiptId: retryForcedView(ApprovalReceiptStatus::Approved, (string) $row->receipt_id, toolCallId: 'call_other'),
    ));

    expect(app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::ReceiptUnavailable)
        ->and(app(RetryLedger::class)->executions)->toBe(0)
        ->and(StoredPendingApproval::query()->sole()->resume_attempts)->toBe(1);
});

/** A receiptless drivable row is publicly constructible; its read falls back to the tool call. */
it('reads a receiptless row by its tool call before retrying', function (): void {
    $row = decidedButUnresumed();
    $row->forceFill(['receipt_id' => null])->save();
    $statuses = new RetryForcedStatuses(byToolCall: retryForcedView(ApprovalReceiptStatus::Approved, 'receipt-forced'));
    app()->instance(ApprovalStatusReader::class, $statuses);
    $recorder = new RetryRecordingAgent;

    /** @var AgentResolverRegistry $resolvers */
    $resolvers = app(ResumableAgents::class);
    $resolvers->register('retry@v1', fn (): RetryRecordingAgent => $recorder, fn (Agent $agent): bool => $agent instanceof RetryAgent);

    expect(app(ApprovalResolutionService::class)->retry($row->refresh(), new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::ResumedApproval)
        ->and($statuses->reads)->toBe(['statusForToolCall:'.RETRY_TOOL_CALL_ID]);
});

/**
 * Only Laravel AI's measured already-resolved message may become AlreadyResumed. Every other
 * mismatch — here a participant-scoped conversation miss — leaves the turn untouched and must
 * surface as a failed continuation, not a false success.
 */
it('does not report a participant mismatch as already resumed', function (): void {
    $row = decidedButUnresumed();
    app()->instance(ConversationParticipants::class, new class implements ConversationParticipants
    {
        public function referenceFor(object $participant): string
        {
            return 'customer:7';
        }

        public function resolve(string $reference): object
        {
            return new RetryCustomer(8);
        }
    });

    expect(fn () => app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-1'])))
        ->toThrow(ApprovalResumeFailed::class);
    expect(app(RetryLedger::class)->executions)->toBe(0)
        ->and(ApprovalReconciliation::query()->count())->toBe(1)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', RETRY_TOOL_CALL_ID)->value('status'))->toBe('approved');
});

/** The retry drives the same exact continuation the original resolution would have: nothing widens. */
it('resumes the retry with the exact captured decision map, conversation, and participant', function (): void {
    $row = decidedButUnresumed();
    $recorder = new RetryRecordingAgent;

    /** @var AgentResolverRegistry $resolvers */
    $resolvers = app(ResumableAgents::class);
    $resolvers->register('retry@v1', fn (): RetryRecordingAgent => $recorder, fn (Agent $agent): bool => $agent instanceof RetryAgent);

    expect(app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::ResumedApproval);

    expect($recorder->continuations)->toHaveCount(1)
        ->and($recorder->continuations[0]['conversationId'])->toBe($row->conversation_id)
        ->and($recorder->continuations[0]['participant'])->toBeInstanceOf(RetryCustomer::class)
        ->and($recorder->decisions)->not->toBeNull()
        ->and(array_keys($recorder->decisions->all()))->toBe([$row->tool_call_id])
        ->and($recorder->decisions->get($row->tool_call_id)?->isApproved())->toBeTrue();
});

/** The host hears the continuation outcome through the existing observation, exactly once. */
it('notifies the continuation outcome once when the retry succeeds', function (): void {
    $recipient = new RetryNotificationRecipient('retry-outcome');
    app()->instance(ApprovalNotificationRecipients::class, new class($recipient) implements ApprovalNotificationRecipients
    {
        public function __construct(private object $recipient) {}

        public function forApproval(StoredPendingApproval $approval, ApprovalNotificationKey $key): iterable
        {
            return [$this->recipient];
        }
    });
    Notification::fake();
    $row = decidedButUnresumed();

    expect(app(ApprovalResolutionService::class)->retry($row, new GenericUser(['id' => 'operator-1'])))
        ->toBe(RetryOutcome::ResumedApproval);

    Notification::assertSentToTimes($recipient, ApprovalResumeOutcomeNotification::class, 1);
});
