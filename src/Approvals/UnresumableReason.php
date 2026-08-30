<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/**
 * Which drivability check failed — an observation, never an inference.
 *
 * The distinction matters, because this package has already been wrong about it once. It cannot say
 * *why* a challenge is unavailable at ingestion: `ApprovalManager::challengeForToolCall()` returns
 * `?ApprovalChallenge`, so its first drivability observation collapses absent, ambiguous,
 * non-pending and expired into one null. `ApprovalStatusReader` now lets surfaces render
 * already-decided, lapsed, or unavailable from the later status read; this enum still records
 * **which ingestion check ran and which one came back empty**, because it ran it.
 *
 * The four cases are the four conditions drivability requires (design §6.3). A row can fail more
 * than one; the column names the **first** failure in the order below, and the ingestion incident
 * carries the same value, so the two never disagree.
 */
enum UnresumableReason: string
{
    /**
     * `challengeForToolCall()` returned null.
     *
     * This remains the ingestion-time observation. `ApprovalStatusReader` lets later surfaces
     * render already-decided versus lapsed versus unavailable from the status read, without
     * rewriting what the first challenge availability check observed.
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

    /** A participant-bound pause could not round-trip to the same Laravel AI type/key. */
    case ParticipantUnresolvable = 'participant_unresolvable';
}
