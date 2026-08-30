<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use LogicException;

/** A claimed row may still be executing and therefore needs an explicit override. */
final class ExecutionClaimStillActive extends LogicException
{
    public static function forClaim(string $claimId): self
    {
        return new self('Execution claim ['.$claimId.'] is still active; investigate, then repeat with force.');
    }
}
