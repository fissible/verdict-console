<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Events;

use Fissible\Verdict\Approvals\ApprovalDecisionKind;
use Fissible\VerdictConsole\Approvals\PendingApproval;

/**
 * Verdict refused a decision the console's own authority had already admitted.
 *
 * The anomaly listener projects this rather than the resolver writing inline, so this per-attempt
 * observation follows the same durable ledger path as every other console anomaly.
 */
final readonly class ApprovalDecisionRefused
{
    public function __construct(
        public PendingApproval $pendingApproval,
        public ApprovalDecisionKind $kind,
    ) {}
}
