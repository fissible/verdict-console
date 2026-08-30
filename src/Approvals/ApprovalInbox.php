<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/** Reads the host-visible approval index into render-time approval items. */
final readonly class ApprovalInbox
{
    public function __construct(
        private PendingApprovalStore $pendingApprovals,
        private ApprovalItemFactory $items,
    ) {}

    /** @return list<ApprovalItem> */
    public function items(): array
    {
        return array_map($this->items->make(...), $this->pendingApprovals->visible());
    }
}
