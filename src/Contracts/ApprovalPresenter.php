<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\VerdictConsole\Presentation\ApprovalPresentation;
use Laravel\Ai\Approvals\PendingApproval;

/**
 * Projects a Laravel AI approval into the only data the console may persist for display.
 *
 * The host owns any application-specific disclosure. Implementations receive the original arguments
 * because an opt-in presenter may disclose a capability-specific subset, but the package's default
 * presenter never copies them. `$challenge` is null for a receiptless approval.
 */
interface ApprovalPresenter
{
    public function present(PendingApproval $approval, ?ApprovalChallenge $challenge = null): ApprovalPresentation;
}
