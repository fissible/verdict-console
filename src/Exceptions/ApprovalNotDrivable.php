<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use Fissible\VerdictConsole\Approvals\PendingApproval;
use LogicException;

/** The console knows this stored pause cannot be resumed. */
final class ApprovalNotDrivable extends LogicException
{
    public static function forApproval(PendingApproval $approval): self
    {
        // The current bridge always records a reason, but older rows and direct store callers may
        // legitimately be unresumable without one. This diagnostic reports that observation; it
        // must not fail while trying to explain the row's already-known non-drivability.
        $storedReason = $approval->unresumable_reason;
        $reason = $storedReason === null ? 'unknown' : $storedReason->value;

        return new self('Approval ['.$approval->tool_call_id.'] is not drivable: '.$reason.'.');
    }

    public static function forMissingResumeContext(PendingApproval $approval): self
    {
        return new self('Approval ['.$approval->tool_call_id.'] is marked drivable but lacks its captured resume context.');
    }
}
