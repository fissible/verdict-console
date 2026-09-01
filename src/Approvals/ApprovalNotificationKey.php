<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/**
 * Names observations the console can notify without pretending to observe an action lifecycle.
 *
 * Hosts receive this value to choose different audiences for a new pause, a recorded decision, and
 * Laravel AI's continuation event. Verdict #299 now makes receipt consumption observable, so its
 * transition has a key too. Keeping the set closed still prevents a surface from routing a
 * fabricated action-completed observation that the console cannot know.
 */
enum ApprovalNotificationKey: string
{
    case Pending = 'approval-pending';
    case Approved = 'approval-approved';
    case Rejected = 'approval-rejected';
    case Consumed = 'approval-consumed';
    case ResumeOutcome = 'approval-resume-outcome';
}
