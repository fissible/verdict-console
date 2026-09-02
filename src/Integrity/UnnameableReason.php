<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Integrity;

enum UnnameableReason: string
{
    case NoNamedChains = 'no_named_chains';
    case InvalidTopology = 'invalid_topology';
}
