<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\Verdict\Approvals\ApprovalChallenge;

/** Resolves the single verb set every approval surface must render from live Verdict state. */
final class ApprovalVerbs
{
    /**
     * Return the verbs a surface may offer for this item.
     *
     * A persisted row proves only that Laravel AI once paused; the live challenge proves Verdict
     * still accepts a human decision. Requiring both keeps a lapsed, consumed, or otherwise
     * non-pending receipt from acquiring an approve button through stale console state.
     *
     * @return list<ApprovalVerb>
     */
    public function resolve(PendingApproval $approval, ?ApprovalChallenge $challenge): array
    {
        if ($approval->resumability !== Resumability::Drivable
            || $challenge === null
            || $challenge->toolCallId !== $approval->tool_call_id) {
            return [];
        }

        // VC-43 has not measured close against a non-pending turn, so emitting it now would make a
        // surface promise an exit path the runtime has not proved it can carry out.
        return [ApprovalVerb::Approve, ApprovalVerb::Reject];
    }
}
