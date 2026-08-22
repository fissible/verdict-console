<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use Laravel\Ai\Contracts\Agent;

/**
 * The host cannot name how to rebuild this agent.
 *
 * Thrown rather than returning `''` or the class name: a placeholder key would be stored, look
 * resolvable, and fail only at resume — long after the information needed to diagnose it is gone.
 */
final class UnkeyableAgent extends ResumableAgentFailure
{
    public static function for(Agent $agent): self
    {
        return new self(sprintf(
            'No resumable-agent key is registered for [%s]. Register one, or this agent\'s paused '
            .'approvals cannot be resumed by the console.',
            $agent::class,
        ));
    }

    /** @param  list<non-empty-string>  $keys */
    public static function ambiguous(Agent $agent, array $keys): self
    {
        return new self(sprintf(
            'Agent [%s] matches more than one resumable-agent key [%s]. Ambiguity is refused rather '
            .'than resolved by registration order, because the key chosen here is the one a resume '
            .'depends on months later.',
            $agent::class,
            implode(', ', $keys),
        ));
    }
}
