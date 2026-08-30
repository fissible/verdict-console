<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Chat;

use Fissible\VerdictConsole\Contracts\ChatEntry;
use Fissible\VerdictConsole\Exceptions\ChatEntryNotConfigured;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The shipped chat entry: use the authenticated user and require the host to nominate its agent.
 *
 * A package cannot select a host's resumable agent safely, so an absent setting refuses before a
 * prompt can create a conversation that no operator can continue after a pause.
 */
final readonly class ConfiguredChatEntry implements ChatEntry
{
    public function __construct(private Config $config) {}

    public function participantFor(Authenticatable $user): object
    {
        return $user;
    }

    public function entryKeyFor(object $participant): string
    {
        $key = $this->config->get('verdict-console.chat.entry_key');

        if (! is_string($key) || trim($key) === '') {
            throw ChatEntryNotConfigured::make();
        }

        return $key;
    }
}
