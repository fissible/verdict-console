<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/**
 * Whether this console can drive a paused run to a resume.
 *
 * Never a statement about the Verdict receipt's validity. Receipt state and TTL are Verdict's, read
 * live through `ApprovalManager`, and are deliberately not mirrored here — a second copy is the
 * divergence the design exists to avoid. This enum answers a different question: given what was
 * captured at pause time, can *this package* rebuild the agent and resume it at all?
 */
enum Resumability: string
{
    /** A receipt, agent, conversation, and (when present) participant all round-trip faithfully. */
    case Drivable = 'drivable';

    /**
     * The row is recorded but this console cannot drive it — no Verdict receipt behind the approval
     * (a non-`BoundTool` approval, or an ambiguous tool-call id), or a resolver key that no longer
     * resolves. Such a run is already paused and waiting; refusing to record it would hide it
     * rather than prevent it, so it is stored, surfaced as not-console-actionable, and left to the
     * host's recovery protocol.
     */
    case Unresumable = 'unresumable';
}
