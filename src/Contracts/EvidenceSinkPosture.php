<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\VerdictConsole\Evidence\SinkPosture;

/**
 * Console-owned, host-replaceable read boundary for the configured evidence sink.
 *
 * Configuration proves selection only and never implies verified or complete recording.
 */
interface EvidenceSinkPosture
{
    public function read(): SinkPosture;
}
