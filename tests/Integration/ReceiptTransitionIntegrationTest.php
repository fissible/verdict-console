<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Contracts\ApprovalNotificationRecipients;
use Fissible\VerdictConsole\Notifications\ApprovedApprovalNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * VC-46's wiring, proven against the real substrate: Verdict's manager decides, its store commits
 * and dispatches ApprovalReceiptTransitioned, and the console's listener — registered by the
 * provider, not by this test — lands the observation. The Feature tier pins the listener's whole
 * behavior matrix over hand-built events; this proves those events are the ones Verdict sends.
 */
final class TransitionChainRecipient
{
    use Notifiable;

    public function __construct(private readonly string $key) {}

    public function getKey(): string
    {
        return $this->key;
    }
}

it('lands the approved notice when verdicts own manager decides, with no console decision path involved', function (): void {
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

    app(PendingApprovalStore::class)->ingest(
        'call_real_chain',
        conversationId: 'conversation-1',
        receiptId: 'receipt-real-chain',
    );
    DB::table('verdict_approval_receipts')->insert([
        'id' => 'receipt-real-chain',
        'tool_call_id' => 'call_real_chain',
        'capability' => 'orders.cancel',
        'binding_fingerprint' => str_repeat('a', 64),
        'status' => 'pending',
        'reason' => 'Cancelling an order needs confirmation.',
        'expires_at' => now()->addHour(),
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ]);

    $recipient = new TransitionChainRecipient('integration-observer');
    app()->instance(ApprovalNotificationRecipients::class, new class($recipient) implements ApprovalNotificationRecipients
    {
        public function __construct(private readonly TransitionChainRecipient $recipient) {}

        public function forApproval($approval, $key): iterable
        {
            return [$this->recipient];
        }
    });
    Notification::fake();

    $transition = app(ApprovalManager::class)->approve('receipt-real-chain', 'call_real_chain', 'other-operator');

    expect($transition->succeeded())->toBeTrue()
        ->and(DB::table('verdict_approval_receipts')->where('id', 'receipt-real-chain')->value('status'))->toBe('approved');

    Notification::assertSentToTimes($recipient, ApprovedApprovalNotification::class, 1);
});
