<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Integrity;

enum ChainIntegrityState: string
{
    case NotApplicable = 'not_applicable';
    case Unnameable = 'unnameable';
    case Unverified = 'unverified';
    case Verified = 'verified';
    case Failed = 'failed';
}
