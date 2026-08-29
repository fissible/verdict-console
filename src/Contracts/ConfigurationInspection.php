<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\VerdictConsole\Configuration\ApprovalRules;
use Fissible\VerdictConsole\Configuration\CapabilityInspection;
use Fissible\VerdictConsole\Configuration\RateLimitInspection;

/**
 * Console-owned, host-replaceable read boundary for Verdict's declared configuration.
 *
 * Hosts that assemble capabilities or approval policy differently can bind this contract to their
 * own read model without making an operator surface depend on Verdict's registry implementation.
 */
interface ConfigurationInspection
{
    /** @return list<CapabilityInspection> */
    public function capabilities(): array;

    /** @return list<RateLimitInspection> */
    public function rateLimits(): array;

    public function approvalRules(): ApprovalRules;
}
