<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\VerdictConsole\Presentation\ApprovalPresentation;
use Laravel\Ai\Approvals\PendingApproval as LaravelPendingApproval;

/**
 * Projects a Laravel AI approval into the only data the console may persist for display.
 *
 * The parameter is Laravel AI's `PendingApproval` — the event payload — deliberately aliased, because
 * this package has a class of the same name for its own durable row. Unaliased `PendingApproval`
 * always means the console's row; the event payload is always `LaravelPendingApproval`. The bridge
 * imports both, so the storage boundary has to be legible at a glance.
 *
 * The host owns any application-specific disclosure. Implementations receive the original arguments
 * because an opt-in presenter may disclose a capability-specific subset, but the package's default
 * presenter never copies them. `$challenge` is null for a receiptless approval.
 */
interface ApprovalPresenter
{
    public function present(LaravelPendingApproval $approval, ?ApprovalChallenge $challenge = null): ApprovalPresentation;
}
