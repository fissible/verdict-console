<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

/**
 * Optional host-owned persistence for Laravel AI conversation participants.
 *
 * Laravel AI gives the pause listener a live object. It is neither durable nor safe for this package
 * to reduce to a class name and id, so a host that needs to reattach a participant supplies an
 * opaque reference and its inverse. `null` means the host intentionally resumes without one.
 */
interface ConversationParticipants
{
    /** Return an opaque durable reference, or null when no participant attachment is needed. */
    public function referenceFor(object $participant): ?string;

    /** Rebuild an opaque reference, or null when this host resumes without a participant. */
    public function resolve(string $reference): ?object;
}
