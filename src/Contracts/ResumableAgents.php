<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\VerdictConsole\Exceptions\UnkeyableAgent;
use Fissible\VerdictConsole\Exceptions\UnresolvableAgentKey;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations;

/**
 * How the host rebuilds an agent this console paused.
 *
 * `ToolApprovalRequested` hands over an `Agent` **instance**. Resuming happens later — a different
 * request, often a different process — so the instance is gone and nothing in the event is durable
 * enough to rebuild it from. Agent class plus participant is not enough for any agent that takes a
 * provider/model choice, tenant context, or constructor input, which is most real ones.
 *
 * So the host owns reconstruction, and this contract is the seam. The package stores the key and
 * hands it back; it never parses one.
 */
interface ResumableAgents
{
    /**
     * The durable key naming how to rebuild this agent.
     *
     * Must be **pure and stable**: the same agent yields the same key across processes and deploys.
     * A key derived from `spl_object_id()`, a timestamp, or a random seed breaks resumption only for
     * runs that outlive the process that paused them — which is the only kind that needs resuming.
     *
     * @throws UnkeyableAgent when this agent is not one the host can rebuild
     */
    public function keyFor(Agent $agent): string;

    /**
     * Rebuild the agent a key names.
     *
     * Returns a **conversation-capable** agent, because a resume is meaningless without one: only
     * `RemembersConversations` carries `continue()`, and `Conversational` is what Laravel AI checks
     * before it will pause at all.
     *
     * Returns it **bare** — no conversation attached. Attaching is resumption's business, not
     * reconstruction's, and the caller attaches by the conversation id captured at pause time.
     *
     * Must not require the pause to still exist. If rebuilding needs to read the console's own
     * tables, the key is not carrying enough.
     *
     * @throws UnresolvableAgentKey when the key is unknown, or known and its factory fails
     */
    public function resolve(string $key): Agent&RemembersConversations;

    /**
     * Every key this host can currently resolve.
     *
     * Exists so startup preflight is expressible *through this interface* rather than by reaching
     * into whatever registry a host happens to use. Without it, "every registered key resolves" is
     * not a testable claim.
     *
     * @return iterable<non-empty-string>
     */
    public function keys(): iterable;
}
