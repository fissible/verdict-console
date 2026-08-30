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
use Fissible\VerdictConsole\Approvals\PendingApproval as StoredPendingApproval;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Tests\EndToEndTestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;

/**
 * The widget's form-posts, end to end: a real pause, a real receipt, a browser-shaped POST, and the
 * VC-6 outcome measured where it matters — whether the executor ran. Fixtures are this file's own.
 */
const INBOX_ORDER_ID = 7001;

final class InboxLedger
{
    public int $executions = 0;
}

/** Forces one status view over whatever the store holds, so routing through the reader is falsifiable. */
final class InboxForcedStatuses implements ApprovalStatusReader
{
    /** @var list<string> every read made through this reader, in order, as "method:key" */
    public array $reads = [];

    public function __construct(private readonly ApprovalStatusView $view) {}

    public function statusFor(string $receiptId): ?ApprovalStatusView
    {
        $this->reads[] = 'statusFor:'.$receiptId;

        return $this->view->receiptId === $receiptId ? $this->view : null;
    }

    public function statusForToolCall(string $toolCallId): ?ApprovalStatusView
    {
        $this->reads[] = 'statusForToolCall:'.$toolCallId;

        return $this->view->toolCallId === $toolCallId ? $this->view : null;
    }

    public function pendingWithin(array $scope): array
    {
        return [];
    }
}

final readonly class InboxOrder
{
    public function __construct(public int $id) {}
}

final class InboxCancelOrderTool implements Tool
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

function inboxBoundTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('orders.cancel')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.cancel',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): InboxOrder => new InboxOrder((int) $e->proposal->arguments['order_id']),
            )
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'inbox-target',
                    identityUsing: fn (ActionEnvelope $e, InboxOrder $t): array => ['id' => $t->id],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, InboxOrder $t): array => ['order_id' => $t->id], reason: 'Cancelling an order needs confirmation.')
                ->executeUsing(function (AuthorizedAction $a): string {
                    app(InboxLedger::class)->executions++;

                    return 'Order cancelled.';
                }),
        );
    }

    return $verdict->bound(new InboxCancelOrderTool, 'orders.cancel', new ActionContext('customer'));
}

final class InboxAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
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
        return [inboxBoundTool()];
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

/** Pause a participant-less run and return the stored console row. */
function pausedInboxRow(): StoredPendingApproval
{
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(test()->toolCallResponse('call_inbox', 'InboxCancelOrderTool', ['order_id' => INBOX_ORDER_ID]))
            ->push(test()->textResponse('Done.')),
    ]);

    $paused = (new InboxAgent)->prompt('Please cancel order '.INBOX_ORDER_ID.'.');

    expect($paused->hasPendingApprovals())->toBeTrue('Fixture: the run must pause.');

    return StoredPendingApproval::query()->sole();
}

function inboxApprover(): GenericUser
{
    return new GenericUser(['id' => 501]);
}

beforeEach(function (): void {
    $this->migrateRoundTripTables();

    $console = dirname(__DIR__, 2).'/database/migrations';
    (require $console.'/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_notifications_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_reconciliations_table.php.stub')->up();

    $this->app->instance(InboxLedger::class, new InboxLedger);
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('inbox test');
        }
    });

    /** @var AgentResolverRegistry $resolvers */
    $resolvers = app(ResumableAgents::class);
    $resolvers->register('inbox@v1', fn (): InboxAgent => new InboxAgent, fn (Agent $agent): bool => $agent instanceof InboxAgent);

    // The `web` group encrypts cookies and flashes to the session, both of which need an app key;
    // a fixed test-only key keeps the suite hermetic and deterministic.
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

    // The routes mounted at boot. The browser-shaped requests below carry no CSRF token; both
    // framework names are bypassed so the suite holds across the Laravel versions in the matrix.
    expect(Route::has('verdict-console.approvals.approve'))->toBeTrue('The routes mount at boot by default.');
    $this->withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
});

/** Hides every row: the endpoint must answer as if the row did not exist, before any Gate. */
final class HideEverythingScope implements ApprovalScope
{
    public function apply(Builder $query): Builder
    {
        return $query->whereRaw('1 = 0');
    }
}

it('approves through the form-post and the paused run executes exactly once', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();

    $response = $this->actingAs(inboxApprover())->from('/inbox')->post(route('verdict-console.approvals.approve', $row->id));

    $response->assertRedirect('/inbox')->assertSessionHas('verdict-console.status', 'approved');

    expect(app(InboxLedger::class)->executions)->toBe(1, 'An approved, keyed resume executes exactly once.')
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->first())
        ->toMatchArray(['status' => 'consumed', 'approved_by' => '501']);

    Http::assertSentCount(2);
});

it('rejects through the form-post and the run resumes to a refusal without executing', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();

    $this->actingAs(inboxApprover())->from('/inbox')->post(route('verdict-console.approvals.reject', $row->id))
        ->assertRedirect('/inbox')
        ->assertSessionHas('verdict-console.status', 'rejected');

    expect(app(InboxLedger::class)->executions)->toBe(0)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->first())
        ->toMatchArray(['status' => 'rejected', 'rejected_by' => '501']);

    // Measured, not assumed: a bare rejection ends the turn — Laravel AI records the denied tool
    // result and returns without another model call (TextGenerationLoop::resumeFromApproval).
    Http::assertSentCount(1);
});

/** "Back" with nowhere to go back to falls back to the application root, never to an error. */
it('redirects to the application root when the post carries no referer', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();

    $this->actingAs(inboxApprover())->post(route('verdict-console.approvals.reject', $row->id))
        ->assertRedirect(url('/'));
});

/**
 * The widget carries no authority of its own: the same Gate that governs the service governs the
 * endpoint, and a refusal is a 403 with nothing spent — no receipt transition, no resume.
 */
it('refuses an approver the host Gate denies, spending nothing', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => false);
    $row = pausedInboxRow();

    $this->actingAs(inboxApprover())->post(route('verdict-console.approvals.approve', $row->id))->assertForbidden();

    expect(app(InboxLedger::class)->executions)->toBe(0)
        ->and(app(VerdictManager::class)->approvals()->challengeForToolCall('call_inbox'))->not->toBeNull('The receipt is still pending.');

    Http::assertSentCount(1);
});

it('refuses an unauthenticated post', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();

    $this->post(route('verdict-console.approvals.approve', $row->id))->assertForbidden();

    expect(app(InboxLedger::class)->executions)->toBe(0);
});

it('answers not-found for a row that does not exist, before any authority check', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => throw new LogicException('The Gate must not be consulted for a row that is not there.'));

    $this->actingAs(inboxApprover())->post(route('verdict-console.approvals.approve', 'not-a-row'))->assertNotFound();
});

/** VC-12: a row outside the host scope is indistinguishable from a row that does not exist. */
it('answers not-found for an existing row outside the host scope, before any authority check', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => throw new LogicException('The Gate must not be consulted for a hidden row.'));
    $row = pausedInboxRow();
    app()->instance(ApprovalScope::class, new HideEverythingScope);

    $this->actingAs(inboxApprover())->post(route('verdict-console.approvals.approve', $row->id))->assertNotFound();

    expect(app(InboxLedger::class)->executions)->toBe(0);
});

it('refuses a close the host Gate denies, spending nothing', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => false);
    $row = pausedInboxRow();

    $this->actingAs(inboxApprover())->post(route('verdict-console.approvals.close', $row->id))->assertForbidden();

    expect(app(InboxLedger::class)->executions)->toBe(0)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->value('status'))->toBe('pending');

    Http::assertSentCount(1);
});

/**
 * The state the close form exists for: a receipt that lapsed. The status read reports Pending
 * with a passed deadline (ADR 0031 §5), the widget renders the row as lapsed-undecided with
 * close as its only control, and posting it resumes the exact conversation with a rejection —
 * the receipt is never decided on the human's behalf.
 */
it('renders a lapsed receipt with only a close form, and close resumes without deciding the receipt', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();
    DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->update(['expires_at' => now()->subMinute()]);

    $html = (string) $this->blade('<x-verdict-console::approvals />');

    expect($html)->toContain('data-approval="'.$row->id.'"')
        ->and($html)->toContain('data-state="lapsed_undecided"')
        ->and($html)->toContain('lapsed, undecided')
        ->and($html)->toContain('action="'.route('verdict-console.approvals.close', $row->id).'"')
        ->and($html)->not->toContain('data-verb="approve"')
        ->and($html)->not->toContain('data-verb="reject"');

    $this->actingAs(inboxApprover())->from('/inbox')->post(route('verdict-console.approvals.close', $row->id))
        ->assertRedirect('/inbox')
        ->assertSessionHas('verdict-console.status', 'closed');

    expect(app(InboxLedger::class)->executions)->toBe(0, 'Close only sends Laravel AI a rejection.')
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->value('status'))->toBe('pending', 'Close never mutates the receipt.');

    // Same measured fact as reject: the bare rejection close sends ends the turn with no model call.
    Http::assertSentCount(1);
});

/**
 * The other half of the old collapse: a receipt another actor decided outside this console. The
 * status read says which half (ADR 0031 §5): the row renders as already decided — carrying the
 * persisted status — offers only the non-authorizing close, and a forged approve decides nothing.
 */
it('renders a receipt decided by another actor as already decided, offering only close', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();
    DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')
        ->update(['status' => 'rejected', 'rejected_by' => 'someone-else', 'rejected_at' => now()]);

    $html = (string) $this->blade('<x-verdict-console::approvals />');

    expect($html)->toContain('data-approval="'.$row->id.'"')
        ->and($html)->toContain('data-state="already_decided"')
        ->and($html)->toContain('data-receipt-status="rejected"')
        ->and($html)->toContain('already decided')
        ->and($html)->toContain('action="'.route('verdict-console.approvals.close', $row->id).'"')
        ->and($html)->not->toContain('data-verb="approve"')
        ->and($html)->not->toContain('data-verb="reject"');

    foreach (['approve', 'reject'] as $verb) {
        $this->actingAs(inboxApprover())->from('/inbox')->post(route('verdict-console.approvals.'.$verb, $row->id))
            ->assertRedirect('/inbox')
            ->assertSessionHas('verdict-console.status', 'not_actionable');
    }

    expect(app(InboxLedger::class)->executions)->toBe(0)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->first())
        ->toMatchArray(['status' => 'rejected', 'rejected_by' => 'someone-else']);

    Http::assertSentCount(1);
});

/**
 * A receipt the store no longer holds is not "decided" and not "lapsed" — the reader answers
 * null, and the row must say only that, still offering the run its non-authorizing way out.
 */
it('renders a row whose receipt vanished as unavailable, spending nothing on a forged approve', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();
    DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->delete();

    $html = (string) $this->blade('<x-verdict-console::approvals />');

    expect($html)->toContain('data-state="receipt_unavailable"')
        ->and($html)->toContain('receipt unavailable')
        ->and($html)->toContain('action="'.route('verdict-console.approvals.close', $row->id).'"')
        ->and($html)->not->toContain('data-verb="approve"');

    $this->actingAs(inboxApprover())->from('/inbox')->post(route('verdict-console.approvals.approve', $row->id))
        ->assertRedirect('/inbox')
        ->assertSessionHas('verdict-console.status', 'not_actionable');

    expect(app(InboxLedger::class)->executions)->toBe(0);

    Http::assertSentCount(1);
});

/** Builds the forced view for the one real receipt of this file's paused runs. */
function inboxForcedView(string $receiptId, ApprovalReceiptStatus $status, string $expiresAt = '2030-01-02T03:04:05+00:00'): ApprovalStatusView
{
    return new ApprovalStatusView(
        receiptId: $receiptId,
        toolCallId: 'call_inbox',
        capability: 'orders.cancel',
        status: $status,
        reason: 'Cancelling an order needs confirmation.',
        expiresAt: new DateTimeImmutable($expiresAt),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: $status === ApprovalReceiptStatus::Rejected ? 'someone-else' : null,
        rejectedAt: $status === ApprovalReceiptStatus::Rejected ? new DateTimeImmutable('2026-08-30T09:00:00+00:00') : null,
        consumedAt: null,
        createdAt: new DateTimeImmutable('2026-08-30T08:59:00+00:00'),
        approvalContext: null,
    );
}

/**
 * VC-45's routing rule made falsifiable: the widget and the resolution service trust verdict#298's
 * reader, not the live challenge. A reader that reports this receipt as already rejected must
 * withdraw the decision even though the store still holds a live pending receipt — an
 * implementation that kept reading challengeForToolCall() would render pending, approve, and
 * execute here.
 */
it('trusts the status read over the live challenge when deciding actionability', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();
    $receiptId = (string) DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->value('id');
    app()->instance(ApprovalStatusReader::class, new InboxForcedStatuses(
        inboxForcedView($receiptId, ApprovalReceiptStatus::Rejected),
    ));

    expect((string) $this->blade('<x-verdict-console::approvals />'))->toContain('data-state="already_decided"');

    $this->actingAs(inboxApprover())->from('/inbox')->post(route('verdict-console.approvals.approve', $row->id))
        ->assertRedirect('/inbox')
        ->assertSessionHas('verdict-console.status', 'not_actionable');

    expect(app(InboxLedger::class)->executions)->toBe(0)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->value('status'))->toBe('pending');

    Http::assertSentCount(1);
});

/**
 * close's "decision still available" pre-check reads status, not the challenge: a reader that
 * still reports Pending with a future deadline must hold close back even though the receipt in
 * the store has lapsed and no live challenge exists any more.
 */
it('holds close back on the status read alone, even when the live challenge is gone', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();
    $receiptId = (string) DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->value('id');
    DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->update(['expires_at' => now()->subMinute()]);
    app()->instance(ApprovalStatusReader::class, new InboxForcedStatuses(
        inboxForcedView($receiptId, ApprovalReceiptStatus::Pending),
    ));

    $this->actingAs(inboxApprover())->from('/inbox')->post(route('verdict-console.approvals.close', $row->id))
        ->assertRedirect('/inbox')
        ->assertSessionHas('verdict-console.status', 'decision_still_available');

    expect(app(InboxLedger::class)->executions)->toBe(0)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->value('status'))->toBe('pending');

    Http::assertSentCount(1);
});

/**
 * The store's public API permits a drivable row that carries no receipt id (a host-constructed
 * pause). For that row the status read falls back to statusForToolCall(): a reader still
 * reporting Pending for the tool call must hold close back even though the stored receipt has
 * lapsed and the row offers no receipt id to read by — an implementation that auto-collapsed
 * receiptless rows, or that still read the challenge, would close here.
 */
it('reads status by tool call for a receiptless drivable row before closing', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();
    DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->update(['expires_at' => now()->subMinute()]);
    $row->forceFill(['receipt_id' => null])->save();
    $statuses = new InboxForcedStatuses(inboxForcedView('receipt-forced', ApprovalReceiptStatus::Pending));
    app()->instance(ApprovalStatusReader::class, $statuses);

    $this->actingAs(inboxApprover())->from('/inbox')->post(route('verdict-console.approvals.close', $row->id))
        ->assertRedirect('/inbox')
        ->assertSessionHas('verdict-console.status', 'decision_still_available');

    // The outcome came from the tool-call read, not from a blanket receiptless rule and not from
    // a receipt-id lookup the row cannot support.
    expect($statuses->reads)->toBe(['statusForToolCall:call_inbox'])
        ->and(app(InboxLedger::class)->executions)->toBe(0);

    Http::assertSentCount(1);
});

/**
 * The absence of approve and reject controls on a lapsed row is not what protects it. A forged
 * POST to either still-routable endpoint must decide nothing, execute nothing, and resume nothing:
 * VC-6 answers null for a receipt Verdict no longer accepts a decision on, and the widget relays it.
 */
it('refuses forged approve and reject posts against a lapsed receipt, spending nothing', function (string $verb): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();
    DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->update(['expires_at' => now()->subMinute()]);

    $this->actingAs(inboxApprover())->from('/inbox')->post(route('verdict-console.approvals.'.$verb, $row->id))
        ->assertRedirect('/inbox')
        ->assertSessionHas('verdict-console.status', 'not_actionable');

    expect(app(InboxLedger::class)->executions)->toBe(0)
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_inbox')->first())
        ->toMatchArray(['status' => 'pending', 'approved_by' => null, 'rejected_by' => null]);

    Http::assertSentCount(1, 'Nothing resumed: only the original pause reached the model.');
})->with(['approve', 'reject']);

/** Close on a row whose decision is still available does not decide: the service reports it, the widget relays it. */
it('relays a close that found a live decision still available, deciding nothing', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    $row = pausedInboxRow();

    $this->actingAs(inboxApprover())->from('/inbox')->post(route('verdict-console.approvals.close', $row->id))
        ->assertRedirect('/inbox')
        ->assertSessionHas('verdict-console.status', 'decision_still_available');

    expect(app(InboxLedger::class)->executions)->toBe(0)
        ->and(app(VerdictManager::class)->approvals()->challengeForToolCall('call_inbox'))->not->toBeNull();

    Http::assertSentCount(1);
});

/**
 * VC-68's null half against a real receipt: this file's fixture binds a plain ActionContext, so
 * Verdict stores the receipt with no `approval_context` — the pre-adoption storage era. The row
 * records null, a storage era rather than a disclosure state, and invents nothing.
 */
it('records a null approval context for a receipt issued without one', function (): void {
    $row = pausedInboxRow();

    expect($row->receipt_id)->not->toBeNull()
        ->and($row->approval_context)->toBeNull();
});

/** The widget over a real pause: the row is drivable and offers exactly approve and reject. */
it('renders the real paused row as drivable with approve and reject forms', function (): void {
    $row = pausedInboxRow();

    $html = (string) $this->blade('<x-verdict-console::approvals />');

    expect($html)->toContain('data-approval="'.$row->id.'"')
        ->and($html)->toContain('data-state="pending"')
        ->and($html)->toContain('action="'.route('verdict-console.approvals.approve', $row->id).'"')
        ->and($html)->toContain('action="'.route('verdict-console.approvals.reject', $row->id).'"')
        ->and($html)->not->toContain('data-verb="close"')
        ->and($html)->toContain('Cancelling an order needs confirmation.');
});
