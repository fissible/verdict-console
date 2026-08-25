<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/**
 * Names observations the console can notify without pretending to observe a receipt lifecycle.
 *
 * Hosts receive this value to choose different audiences for a new pause, a recorded decision, and
 * Laravel AI's continuation event. Keeping the set closed prevents a surface from routing a
 * fabricated "consumed" or "action completed" observation that the console cannot know.
 */
enum ApprovalNotificationKey: string
{
    case Pending = 'approval-pending';
    case Approved = 'approval-approved';
    case Rejected = 'approval-rejected';
    case ResumeOutcome = 'approval-resume-outcome';
}
