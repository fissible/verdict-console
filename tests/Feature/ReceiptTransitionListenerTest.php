<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\Events\ApprovalReceiptTransitioned;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\VerdictConsole\Approvals\ApprovalNotification;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationDispatcher;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationKey;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Contracts\ApprovalNotificationRecipients;
use Fissible\VerdictConsole\Notifications\ApprovedApprovalNotification;
use Fissible\VerdictConsole\Notifications\ConsumedApprovalNotification;
use Fissible\VerdictConsole\Notifications\RejectedApprovalNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * VC-46: verdict#299's receipt-transition event, adopted. One store-dispatched event —
 * ApprovalReceiptTransitioned, carrying identity, resulting status, and time under ADR 0008 —
 * lets the console observe decisions made through some other client and receipts the model
 * consumed, which were invisible until the next live read. The observation lands through the
 * existing claim-idempotent notification dispatcher; ingestion stays on Laravel AI's
 * ToolApprovalRequested, and no listener invents the expiry transition Verdict does not have.
 */
final class TransitionRecipient
{
    use Notifiable;

    public function __construct(private readonly string $key) {}

    public function getKey(): string
    {
        return $this->key;
    }
}

function transitionRow(string $toolCallId, string $receiptId): PendingApproval
{
    $approval = app(PendingApprovalStore::class)->ingest(
        $toolCallId,
        conversationId: 'conversation-1',
        receiptId: $receiptId,
    );

    DB::table('verdict_approval_receipts')->insert([
        'id' => $receiptId,
        'tool_call_id' => $toolCallId,
        'capability' => 'orders.cancel',
        'binding_fingerprint' => str_repeat('a', 64),
        'status' => 'pending',
        'reason' => 'Cancelling an order needs confirmation.',
        'expires_at' => now()->addHour(),
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    return $approval;
}

function transitioned(string $receiptId, string $toolCallId, ApprovalReceiptStatus $status): ApprovalReceiptTransitioned
{
    return new ApprovalReceiptTransitioned(
        receiptId: $receiptId,
        toolCallId: $toolCallId,
        capability: 'orders.cancel',
        status: $status,
        occurredAt: new DateTimeImmutable('now'),
    );
}

beforeEach(function (): void {
    config()->set('verdict.approvals.authorizer', AllowAllApprovalAuthorizer::class);

    $console = dirname(__DIR__, 2).'/database/migrations';
    (require $console.'/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_notifications_table.php.stub')->up();

    $verdict = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';

    foreach ([
        'create_verdict_approval_receipts_table.php.stub',
        'add_proposal_provenance_to_verdict_approval_receipts_table.php.stub',
        'add_approval_context_to_verdict_approval_receipts_table.php.stub',
        'add_approver_summary_to_verdict_approval_receipts_table.php.stub',
        'add_pending_enumeration_index_to_verdict_approval_receipts_table.php.stub',
    ] as $stub) {
        (require $verdict.'/'.$stub)->up();
    }

    $this->recipient = new TransitionRecipient('observer');
    $recipient = $this->recipient;
    app()->instance(ApprovalNotificationRecipients::class, new class($recipient) implements ApprovalNotificationRecipients
    {
        /** @var list<ApprovalNotificationKey> */
        public array $requested = [];

        /** @var list<PendingApproval> */
        public array $approvals = [];

        public function __construct(private readonly TransitionRecipient $recipient) {}

        public function forApproval(PendingApproval $approval, ApprovalNotificationKey $key): iterable
        {
            $this->requested[] = $key;
            $this->approvals[] = $approval;

            return [$this->recipient];
        }
    });
    Notification::fake();
});

it('notifies an approval decided through another client, exactly once under redelivery', function (): void {
    $row = transitionRow('call_elsewhere', 'receipt-elsewhere');

    // The console's resolution service never runs; the committed transition's event is the only
    // thing the console hears — delivered twice, as a queue retry or second worker would.
    // (The Integration tier proves the same event arriving from Verdict's real manager and store;
    // this tier deliberately boots no Verdict provider.)
    event(transitioned('receipt-elsewhere', 'call_elsewhere', ApprovalReceiptStatus::Approved));
    event(transitioned('receipt-elsewhere', 'call_elsewhere', ApprovalReceiptStatus::Approved));

    Notification::assertSentToTimes($this->recipient, ApprovedApprovalNotification::class, 1);

    // The observation must carry the indexed row itself — a listener fabricating a sufficient
    // model from the event payload would notify while orphaning the claim from the queue's row.
    $given = app(ApprovalNotificationRecipients::class)->approvals;

    expect($given)->toHaveCount(1)
        ->and($given[0]->getKey())->toBe($row->getKey())
        ->and($given[0]->tool_call_id)->toBe('call_elsewhere')
        ->and($given[0]->receipt_id)->toBe('receipt-elsewhere');
});

it('treats a terminal event whose binding does not match the indexed row as a quiet no-op', function (): void {
    $row = transitionRow('call_bound', 'receipt-bound');

    // A known tool call under a different receipt, and a known receipt under a different tool
    // call: neither names the indexed pause, so neither may notify, claim, or touch the row.
    event(transitioned('receipt-other', 'call_bound', ApprovalReceiptStatus::Approved));
    event(transitioned('receipt-bound', 'call_other', ApprovalReceiptStatus::Approved));

    Notification::assertNothingSent();

    $kept = $row->fresh();

    expect(ApprovalNotification::query()->count())->toBe(0)
        ->and(PendingApproval::query()->count())->toBe(1)
        ->and($kept->getKey())->toBe($row->getKey())
        ->and($kept->tool_call_id)->toBe('call_bound')
        ->and($kept->receipt_id)->toBe('receipt-bound');
});

it('notifies a rejection observed from the transition event', function (): void {
    transitionRow('call_rejected_elsewhere', 'receipt-rejected-elsewhere');

    event(transitioned('receipt-rejected-elsewhere', 'call_rejected_elsewhere', ApprovalReceiptStatus::Rejected));

    Notification::assertSentToTimes($this->recipient, RejectedApprovalNotification::class, 1);
});

it('ships the consumed notice VC-11 could not build, reporting the observation within its ceiling', function (): void {
    $row = transitionRow('call_consumed', 'receipt-consumed');

    event(transitioned('receipt-consumed', 'call_consumed', ApprovalReceiptStatus::Consumed));
    event(transitioned('receipt-consumed', 'call_consumed', ApprovalReceiptStatus::Consumed));

    Notification::assertSentToTimes($this->recipient, ConsumedApprovalNotification::class, 1);

    // ADR 0028 ceiling: report Verdict's observation — the receipt was consumed — never the
    // unobservable consequence a reader would infer from "completed" or "finished".
    $message = (new ConsumedApprovalNotification($row))->toArray($this->recipient)['message'];

    expect($message)->toContain('consumed')
        ->not->toContain('completed')
        ->not->toContain('finished');

    $keys = app(ApprovalNotificationRecipients::class)->requested;

    expect($keys)->toContain(ApprovalNotificationKey::Consumed);
});

it('notifies once when the console decided and the transition event arrives for its own decision', function (): void {
    $row = transitionRow('call_own_decision', 'receipt-own-decision');

    // The resolution service notifies at decision time; the store's event then reports the same
    // committed transition back. One observation, one claim, one notification.
    app(ApprovalNotificationDispatcher::class)->approved($row);
    event(transitioned('receipt-own-decision', 'call_own_decision', ApprovalReceiptStatus::Approved));

    Notification::assertSentToTimes($this->recipient, ApprovedApprovalNotification::class, 1);
});

it('ingests nothing from an issuance transition: the pause pipeline stays on ToolApprovalRequested', function (): void {
    event(transitioned('receipt-issued', 'call_never_ingested', ApprovalReceiptStatus::Pending));

    // The issuance event carries no conversation or participant correlation; treating it as a
    // second ingestion path would mint rows the resume pipeline cannot drive.
    expect(PendingApproval::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('treats a transition for a pause this console never indexed as a quiet no-op', function (): void {
    event(transitioned('receipt-foreign', 'call_foreign', ApprovalReceiptStatus::Approved));

    Notification::assertNothingSent();

    expect(PendingApproval::query()->count())->toBe(0);
});

/**
 * ADR 0001 §3: expiry has no transition moment — a TTL passes silently, observed only at
 * validate/consume time — so no listener may exist for an expiry-shaped event. Verdict's status
 * vocabulary is where one would have to appear first; this pins the console to deciding what an
 * expired transition means BEFORE handling one, instead of silently routing it.
 */
it('confirms verdict ships no expiry transition for the console to listen for', function (): void {
    expect(array_map(fn (ApprovalReceiptStatus $status) => $status->value, ApprovalReceiptStatus::cases()))
        ->toEqualCanonicalizing(['pending', 'approved', 'rejected', 'consumed']);
});
