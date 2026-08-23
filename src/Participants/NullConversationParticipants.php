<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Participants;

use Fissible\VerdictConsole\Contracts\ConversationParticipants;

/** The conservative default: never persist Laravel AI's live participant object. */
final class NullConversationParticipants implements ConversationParticipants
{
    public function referenceFor(object $participant): ?string
    {
        return null;
    }

    public function resolve(string $reference): ?object
    {
        return null;
    }
}
