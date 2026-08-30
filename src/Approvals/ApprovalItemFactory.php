<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\Verdict\Contracts\ApprovalStatusReader;

/** Assembles an item at render time from the console index and the supported live Verdict read. */
final readonly class ApprovalItemFactory
{
    public function __construct(
        private ApprovalStatusReader $statuses,
        private ApprovalChallengeReader $challenges,
        private ApprovalVerbs $verbs,
    ) {}

    public function make(PendingApproval $approval): ApprovalItem
    {
        $view = $approval->receipt_id === null
            ? $this->statuses->statusForToolCall($approval->tool_call_id)
            : $this->statuses->statusFor($approval->receipt_id);

        $verbs = $this->verbs->resolve($approval, $view);

        if ($view?->toolCallId !== $approval->tool_call_id) {
            $view = null;
        }

        // ApprovalStatusView owns every live rendering field except provenance. A challenge is
        // therefore read only for a still-pending, unexpired receipt, and cannot re-collapse a
        // status the inbox has already distinguished.
        $challenge = $view?->status === ApprovalReceiptStatus::Pending && $view->expiresAt > now()
            ? $this->challenges->challengeForToolCall($approval->tool_call_id)
            : null;

        return ApprovalItem::from($approval, $view, $challenge, $verbs);
    }
}
