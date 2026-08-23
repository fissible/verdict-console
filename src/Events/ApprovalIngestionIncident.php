<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Events;

use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\UnresumableReason;

/**
 * A pause the console recorded but cannot drive.
 *
 * This is intentionally ephemeral. VC-15 will project it into the incident ledger; until then
 * the row's `unresumable_reason` is the only durable record of the observation.
 */
final readonly class ApprovalIngestionIncident
{
    public function __construct(
        public PendingApproval $pendingApproval,
        public UnresumableReason $reason,
    ) {}
}
