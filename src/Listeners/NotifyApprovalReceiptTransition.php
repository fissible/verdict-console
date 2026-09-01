<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Listeners;

use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\Events\ApprovalReceiptTransitioned;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationDispatcher;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationKey;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationStore;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Throwable;

/** Notifies only from Verdict's observed receipt transitions; pause ingestion remains Laravel AI-owned. */
final readonly class NotifyApprovalReceiptTransition
{
    public function __construct(
        private PendingApprovalStore $pendingApprovals,
        private ApprovalNotificationStore $notificationStore,
        private ApprovalNotificationDispatcher $notifications,
    ) {}

    public function handle(ApprovalReceiptTransitioned $event): void
    {
        if ($event->status === ApprovalReceiptStatus::Pending) {
            return;
        }

        $approval = $this->pendingApprovals->findByTransition($event->toolCallId, $event->receiptId);

        if ($approval === null) {
            return;
        }

        if ($event->status === ApprovalReceiptStatus::Approved) {
            if ($this->wasClaimed($approval, ApprovalNotificationKey::Approved)) {
                return;
            }

            $this->notifications->approved($approval);

            return;
        }

        if ($event->status === ApprovalReceiptStatus::Rejected) {
            if ($this->wasClaimed($approval, ApprovalNotificationKey::Rejected)) {
                return;
            }

            $this->notifications->rejected($approval);

            return;
        }

        if ($this->wasClaimed($approval, ApprovalNotificationKey::Consumed)) {
            return;
        }

        $this->notifications->consumed($approval);
    }

    /**
     * Avoid re-entering host recipient policy after the durable dispatcher claim has won.
     *
     * This is only a redelivery shortcut, never a prerequisite: hosts can receive the package code
     * before they migrate its notification table, and the dispatcher already degrades that state
     * without interrupting Verdict's committed transition.
     */
    private function wasClaimed(PendingApproval $approval, ApprovalNotificationKey $key): bool
    {
        try {
            return $this->notificationStore->find($approval, $key->value) !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
