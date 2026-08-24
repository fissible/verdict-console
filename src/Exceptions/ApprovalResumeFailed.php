<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use Fissible\VerdictConsole\Approvals\PendingApproval;
use RuntimeException;
use Throwable;

/** A receipt transitioned, but the console could not complete its captured continuation. */
final class ApprovalResumeFailed extends RuntimeException
{
    public static function forApproval(PendingApproval $approval, Throwable $previous): self
    {
        return new self(
            'Verdict recorded a decision for tool call ['.$approval->tool_call_id.'], but the console could not complete its captured continuation.',
            previous: $previous,
        );
    }
}
