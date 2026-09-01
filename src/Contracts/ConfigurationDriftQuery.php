<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\VerdictConsole\Evidence\ConfigurationDriftResult;

/**
 * Console-owned, host-replaceable read boundary for observed configuration history.
 */
interface ConfigurationDriftQuery
{
    public function observed(): ConfigurationDriftResult;
}
