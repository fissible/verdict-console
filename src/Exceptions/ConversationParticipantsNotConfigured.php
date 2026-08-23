<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use LogicException;

/**
 * The host never registered a {@see ConversationParticipants}.
 *
 * Named rather than a bare `LogicException` so a host can tell *this* apart from a bug inside its own
 * implementation of the contract. Both end the same way at ingestion — the row is written and marked
 * `participant_unresolvable` — but only one of them is fixed by a service-provider binding.
 */
final class ConversationParticipantsNotConfigured extends LogicException
{
    public static function make(): self
    {
        return new self(
            'This application pauses agent runs bound to a conversation participant, but has not bound a '
            .'[Fissible\VerdictConsole\Contracts\ConversationParticipants] implementation. Until it does, those '
            .'pauses are recorded as unresumable: Laravel AI re-finds a paused turn by participant type and key, '
            .'so resuming without rebuilding the exact participant raises an ApprovalMismatchException.'
        );
    }
}
