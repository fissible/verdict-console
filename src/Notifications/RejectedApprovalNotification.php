<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Notifications;

use Fissible\VerdictConsole\Approvals\PendingApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Announces Verdict's recorded rejection, not whether a later action ran. */
final class RejectedApprovalNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly PendingApproval $approval) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array{message: string, approval_id: string, tool_call_id: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => 'A tool approval was rejected.',
            'approval_id' => $this->approval->getKey(),
            'tool_call_id' => $this->approval->tool_call_id,
        ];
    }
}
