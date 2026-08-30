<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * How a host attaches a console chat to a participant and names its entry agent.
 *
 * The entry is a resumable-agent **key**, never an agent instance: it is the same key VC-2 rebuilds
 * after a pause, so a chat the console starts is resumable by construction. An instance would let a
 * host start a chat nobody can resume once that request has ended.
 */
interface ChatEntry
{
    /** Return the participant a new or continued console conversation belongs to. */
    public function participantFor(Authenticatable $user): object;

    /** Return a {@see ResumableAgents} key for this participant, not a live agent instance. */
    public function entryKeyFor(object $participant): string;
}
