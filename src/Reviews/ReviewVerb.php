<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Reviews;

/** Review requests are record-only: there is deliberately no close or resume verb. */
enum ReviewVerb: string
{
    case Approve = 'approve';
    case Reject = 'reject';
}
