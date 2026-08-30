<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use Fissible\VerdictConsole\Approvals\PendingApproval;
use LogicException;

/** A retry was requested for a pause that has not recorded a failed continuation. */
final class NoContinuationToRetry extends LogicException
{
    public static function forApproval(PendingApproval $approval): self
    {
        return new self('Approval ['.$approval->tool_call_id.'] has no recorded failed continuation to retry.');
    }
}
