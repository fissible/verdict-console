<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

/** Whether Verdict was configured to retain evidence when this read was made. */
enum EvidenceRecordingState: string
{
    case Off = 'off';
    case On = 'on';
}
