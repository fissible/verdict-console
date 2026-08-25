<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\VerdictConsole\Approvals\ApprovalNotificationKey;
use Fissible\VerdictConsole\Approvals\PendingApproval;

/**
 * Lets the host choose who may see a console observation about one approval.
 *
 * Recipients belong to the host because the console has no safe default for an operator's identity
 * or delivery policy. An approval is supplied so a host can route by its own tenant, participant,
 * or operational ownership without the console inferring any of those relationships. The observation
 * key is supplied because the audience awaiting a decision is often not the one that needs to hear
 * its recorded outcome.
 */
interface ApprovalNotificationRecipients
{
    /** @return iterable<int, object> recipients accepted by Laravel's notification dispatcher */
    public function forApproval(PendingApproval $approval, ApprovalNotificationKey $key): iterable;
}
