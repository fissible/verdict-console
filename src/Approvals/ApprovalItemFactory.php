<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/** Assembles an item at render time from the console index and the supported live Verdict read. */
final readonly class ApprovalItemFactory
{
    public function __construct(
        private ApprovalChallengeReader $challenges,
        private ApprovalVerbs $verbs,
    ) {}

    public function make(PendingApproval $approval): ApprovalItem
    {
        $challenge = $this->challenges->challengeForToolCall($approval->tool_call_id);

        return ApprovalItem::from($approval, $challenge, $this->verbs->resolve($approval, $challenge));
    }
}
