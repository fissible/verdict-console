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
        if ($approval->resumability !== Resumability::Drivable) {
            return [];
        }

        // A null live challenge is deliberately not called "expired": Verdict also returns null for
        // a receipt that another actor already decided. Both states need a non-authorizing reject
        // continuation; verdict#298 lets VC-45 narrow this defence when status becomes readable.
        if ($challenge === null) {
            return [ApprovalVerb::Close];
        }

        if ($challenge->toolCallId !== $approval->tool_call_id) {
            return [];
        }

        return [ApprovalVerb::Approve, ApprovalVerb::Reject];
    }
}
