<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use RuntimeException;
use Throwable;

/** The paused run could not be indexed durably, so the console cannot surface or resume it. */
final class ApprovalIngestionPersistenceFailed extends RuntimeException
{
    public static function forToolCall(string $toolCallId, Throwable $previous): self
    {
        return new self('Could not persist the paused approval for tool call ['.$toolCallId.'].', previous: $previous);
    }
}
