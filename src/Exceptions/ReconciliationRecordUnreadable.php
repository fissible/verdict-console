<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use RuntimeException;

/**
 * A reconciliation row was written and could not be read back.
 *
 * Named rather than inline because of *when* it fires: the console has already recorded that a human
 * decision was accepted and its continuation failed, so a caller catching this is holding the one
 * situation reconciliation exists to make visible, and now cannot see it either. That deserves a type
 * a host can match on, not a generic logic error indistinguishable from a programming mistake.
 *
 * Reaching it means the insert reported success and the row is absent — a storage-layer contradiction
 * rather than anything a caller did wrong.
 */
final class ReconciliationRecordUnreadable extends RuntimeException
{
    public static function forApproval(string $pendingApprovalId): self
    {
        return new self(
            'A reconciliation record was written for pending approval ['.$pendingApprovalId.'] and could not be read back.'
        );
    }
}
