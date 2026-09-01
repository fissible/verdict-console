<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Reviews;

/** The honest availability state of the separate, asynchronous review lane. */
enum ReviewQueueState: string
{
    case Unconfigured = 'unconfigured';
    case Unscoped = 'unscoped';
    case Ready = 'ready';
}
