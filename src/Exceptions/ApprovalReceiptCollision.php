<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use RuntimeException;
use Throwable;

/** Two distinct pause rows tried to claim one Verdict receipt. */
final class ApprovalReceiptCollision extends RuntimeException
{
    public static function forToolCall(string $toolCallId, Throwable $previous): self
    {
        return new self('A Verdict receipt is already indexed by another paused approval for tool call ['.$toolCallId.'].', previous: $previous);
    }
}
