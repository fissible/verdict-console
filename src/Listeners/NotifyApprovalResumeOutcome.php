<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Listeners;

use Fissible\VerdictConsole\Approvals\ApprovalNotificationDispatcher;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Laravel\Ai\Events\ToolApprovalResolved;

/** Notifies only from Laravel AI's explicit continuation event, never from an ambiguous null challenge. */
final readonly class NotifyApprovalResumeOutcome
{
    public function __construct(
        private PendingApprovalStore $pendingApprovals,
        private ApprovalNotificationDispatcher $notifications,
    ) {}

    public function handle(ToolApprovalResolved $event): void
    {
        foreach ($event->toolResults as $result) {
            $approval = $this->pendingApprovals->findByToolCall($result->id, $event->conversationId);

            if ($approval !== null) {
                $this->notifications->resumeOutcome($approval);
            }
        }
    }
}
