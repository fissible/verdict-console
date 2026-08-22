<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Agents;

use Closure;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Exceptions\UnkeyableAgent;
use Fissible\VerdictConsole\Exceptions\UnresolvableAgentKey;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations;
use Throwable;

/**
 * The shipped {@see ResumableAgents} implementation: a registry of key → factory, plus a predicate
 * per key saying which agents that key names.
 *
 * A host may bind its own implementation instead; this one exists so the common case is a few lines
 * in a service provider rather than a class. It deliberately holds no state about *pauses* — it maps
 * agents to keys and keys to agents, nothing more, and has no dependency on the console's tables.
 *
 * **Keys are opaque strings and versioning is a host convention.** Registering `orders@v1` and
 * `orders@v2` side by side is how a deploy migrates agents without stranding live rows: new pauses
 * key to v2, rows already holding v1 still resolve. Nothing here parses the `@v2`; the registry
 * simply holds both. When v1 may be retired is an operational question about which rows still
 * reference it, and deliberately not this class's business.
 */
final class AgentResolverRegistry implements ResumableAgents
{
    /** @var array<non-empty-string, Closure(): object> */
    private array $factories = [];

    /** @var array<non-empty-string, Closure(Agent): bool> */
    private array $matchers = [];

    /**
     * Register how to rebuild one kind of agent, and how to recognise it.
     *
     * @param  non-empty-string  $key
     * @param  Closure(): object  $factory  rebuilds the agent from nothing but this key
     * @param  Closure(Agent): bool  $matches  whether a paused agent should be keyed to this key
     */
    public function register(string $key, Closure $factory, Closure $matches): self
    {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('A resumable-agent key must not be empty or whitespace.');
        }

        $this->factories[$key] = $factory;
        $this->matchers[$key] = $matches;

        return $this;
    }

    public function keyFor(Agent $agent): string
    {
        $matched = [];

        foreach ($this->matchers as $key => $matches) {
            if ($matches($agent)) {
                $matched[] = $key;
            }
        }

        // Ambiguity is refused rather than settled by registration order. The key chosen at pause
        // time is what a resume depends on long afterwards, and "whichever was registered first"
        // is not a property a host can reason about.
        return match (count($matched)) {
            1 => $matched[0],
            0 => throw UnkeyableAgent::for($agent),
            default => throw UnkeyableAgent::ambiguous($agent, $matched),
        };
    }

    public function resolve(string $key): Agent&RemembersConversations
    {
        $factory = $this->factories[$key] ?? throw UnresolvableAgentKey::unknown($key);

        try {
            $agent = $factory();
        } catch (Throwable $e) {
            throw UnresolvableAgentKey::failed($key, $e);
        }

        // Checked rather than trusted to the type hint: the factory is host code, and a resolver
        // returning the wrong shape should name its key rather than surface as a TypeError from
        // inside this method.
        if (! $agent instanceof Agent || ! $agent instanceof RemembersConversations) {
            throw UnresolvableAgentKey::notConversational($key, $agent);
        }

        return $agent;
    }

    /** @return iterable<non-empty-string> */
    public function keys(): iterable
    {
        return array_keys($this->factories);
    }
}
