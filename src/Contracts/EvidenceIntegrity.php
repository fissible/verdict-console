<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\VerdictConsole\Integrity\ChainIntegrityView;

/** Console-owned, host-replaceable read boundary for dated chain-integrity claims. */
interface EvidenceIntegrity
{
    /** @return list<ChainIntegrityView> */
    public function chains(): array;
}
