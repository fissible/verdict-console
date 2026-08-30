<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;

/** Resolves the single verb set every approval surface must render from live Verdict state. */
final class ApprovalVerbs
{
    /**
     * The store is required rather than fabricated here so a rendering surface cannot silently drop
     * the host scope by constructing its own neutral store around a stale row.
     */
    public function __construct(private PendingApprovalStore $pendingApprovals) {}

    /**
     * Return the verbs a surface may offer for this item.
     *
     * A persisted row proves only that Laravel AI once paused; the status view proves its current
     * receipt state. Requiring both keeps a lapsed, consumed, or otherwise non-pending receipt
     * from acquiring an approve button through stale console state.
     *
     * @return list<ApprovalVerb>
     */
    public function resolve(PendingApproval $approval, ?ApprovalStatusView $view): array
    {
        if (! $this->pendingApprovals->isVisible($approval)) {
            return [];
        }

        if ($approval->resumability !== Resumability::Drivable) {
            return [];
        }

        if ($view === null) {
            return [ApprovalVerb::Close];
        }

        if ($view->toolCallId !== $approval->tool_call_id) {
            return [];
        }

        return $view->status === ApprovalReceiptStatus::Pending && $view->expiresAt > now()
            ? [ApprovalVerb::Approve, ApprovalVerb::Reject]
            : [ApprovalVerb::Close];
    }
}
