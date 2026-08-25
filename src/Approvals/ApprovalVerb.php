<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/** The complete, deliberately small vocabulary a surface may offer for one approval item. */
enum ApprovalVerb: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Close = 'close';
}
