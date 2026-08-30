<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use LogicException;

/** The host has not named the resumable agent that may receive console chats. */
final class ChatEntryNotConfigured extends LogicException
{
    public static function make(): self
    {
        return new self(
            'No chat entry is configured. Set [verdict-console.chat.entry_key] to a key registered with '
            .'[Fissible\VerdictConsole\Contracts\ResumableAgents].'
        );
    }
}
