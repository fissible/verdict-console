<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Participants;

use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Exceptions\ConversationParticipantsNotConfigured;

/**
 * The shipped default: no participant persistence is configured, and it says so.
 *
 * It refuses rather than returning null, because null would be a *claim* — that this pause needs no
 * participant — and this class cannot know that. Refusing is the honest answer, and the bridge turns
 * it into a `participant_unresolvable` row rather than a false `drivable` one. A genuinely
 * participant-less pause never reaches here at all: the bridge checks for a participant first.
 *
 * The package deliberately ships no working default. Reducing a participant to a class name and an id
 * would invent an identity model on the host's behalf, and Laravel AI's live participant object is not
 * durable — so the only correct implementation is one the host writes.
 */
final class UnconfiguredConversationParticipants implements ConversationParticipants
{
    public function referenceFor(object $participant): string
    {
        throw ConversationParticipantsNotConfigured::make();
    }

    public function resolve(string $reference): object
    {
        throw ConversationParticipantsNotConfigured::make();
    }
}
