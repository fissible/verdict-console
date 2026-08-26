<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidenceQueryResult;

/**
 * Console-owned read boundary for Verdict decision evidence.
 *
 * Verdict deliberately exposes evidence as a write contract. Hosts that do not use the shipped SQL
 * recorder can bind this contract to their own read model without making a surface depend on a
 * recorder implementation or Verdict's private storage choices.
 */
interface EvidenceQuery
{
    public function search(EvidenceFilter $filter): EvidenceQueryResult;
}
