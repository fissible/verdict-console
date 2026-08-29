<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use Fissible\Verdict\ExecutionClaims\ExecutionClaimOutcome;
use RuntimeException;

/** Verdict declined to make the requested terminal reconciliation transition. */
final class ExecutionClaimResolutionFailed extends RuntimeException
{
    public function __construct(
        public readonly ExecutionClaimOutcome $outcome,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forClaim(string $claimId, ExecutionClaimOutcome $outcome): self
    {
        return new self($outcome, 'Execution claim ['.$claimId.'] resolution failed with outcome ['.$outcome->value.'].');
    }
}
