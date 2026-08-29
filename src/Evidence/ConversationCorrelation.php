<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

/** Whether the console remembers any invocation belonging to the requested conversation. */
enum ConversationCorrelation: string
{
    case Known = 'known';
    case Unknown = 'unknown';
}
