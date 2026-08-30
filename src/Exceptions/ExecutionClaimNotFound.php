<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use RuntimeException;

/** The authenticated operator named a claim Verdict no longer has. */
final class ExecutionClaimNotFound extends RuntimeException
{
    public static function forClaim(string $claimId): self
    {
        return new self('Execution claim ['.$claimId.'] was not found.');
    }
}
