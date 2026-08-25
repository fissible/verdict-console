<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\Verdict\Approvals\ApprovalChallenge;

/** The live, pending-only Verdict challenge lookup used while rendering an approval item. */
interface ApprovalChallengeReader
{
    public function challengeForToolCall(string $toolCallId): ?ApprovalChallenge;
}
