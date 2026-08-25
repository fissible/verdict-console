<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Notifications;

use Fissible\VerdictConsole\Approvals\ApprovalNotificationKey;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Contracts\ApprovalNotificationRecipients;

/**
 * Ships silent until the host declares recipients.
 *
 * Fabricating a recipient would disclose approval state across an identity boundary the package does
 * not own. Returning none lets an installation adopt notifications deliberately without turning a
 * missing binding into a delivery decision.
 */
final class UnconfiguredApprovalNotificationRecipients implements ApprovalNotificationRecipients
{
    public function forApproval(PendingApproval $approval, ApprovalNotificationKey $key): iterable
    {
        return [];
    }
}
