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
     * The host-supplied opaque token declaring what resolve() would currently rebuild for this key.
     *
     * The host must keep the token consistent with that reconstruction: it changes when, and only
     * when, the host considers the reconstruction changed. The package never derives, parses, or
     * interprets it. Any non-empty string is valid verbatim, including "0", padding, and whitespace;
     * only the empty string is refused. Null declares no version.
     *
     * Compare declarations only for the same key, using exact string equality. Identical non-null
     * tokens declare the same build; different tokens declare a change, with no ordering or
     * compatibility meaning. Equality is agreement of declarations, not independently verified
     * reconstruction equivalence. Null on either side is incomparable: sameness and difference
     * are both unknown.
     *
     * This method is required even when a host declines versioning by returning null. An optional
     * companion interface was rejected because it trades a one-method implementation update for
     * permanent instanceof capability detection at every consumer. Adding this required method
     * is breaking for custom implementations; in the 0.x line, the minor is the breaking boundary.
     *
     * @return non-empty-string|null
     *
     * @throws UnresolvableAgentKey when the key is unknown
     */
    public function versionFor(string $key): ?string;

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
