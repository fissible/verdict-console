<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/**
 * Which drivability check failed — an observation, never an inference.
 *
 * The distinction matters, because this package has already been wrong about it once. It cannot say
 * *why* a challenge is unavailable: `ApprovalManager::challengeForToolCall()` returns
 * `?ApprovalChallenge`, so absent, ambiguous, non-pending and expired collapse into one null with no
 * public datum separating them. What it can say is **which check it ran and which one came back
 * empty**, because it ran them. That is what this enum records.
 *
 * The three cases are the three conditions drivability requires (design §6.3). A row can fail more
 * than one; the column names the **first** failure in the order below, and the ingestion incident
 * carries the same value, so the two never disagree.
 */
enum UnresumableReason: string
{
    /**
     * `challengeForToolCall()` returned null.
     *
     * Deliberately not narrowed further: the receipt may be absent, ambiguous, non-pending or
     * expired, and no public API distinguishes them. An operator reading this should look at the
     * receipt; a future Verdict status-read contract (`MILESTONES.md`) is what would let this say
     * more.
     */
    case ChallengeUnavailable = 'challenge_unavailable';

    /** The host's resolver key did not rebuild an agent, so no resume can be driven for this row. */
    case AgentUnresolvable = 'agent_unresolvable';

    /**
     * The pause carried no conversation id.
     *
     * `continue()` requires a string, so there is nothing to continue into — unresumable however
     * good the receipt and the resolver key are.
     */
    case ConversationAbsent = 'conversation_absent';
}
