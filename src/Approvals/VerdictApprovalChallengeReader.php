<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ApprovalManager;

/**
 * Keeps Verdict receipt storage behind its supported live-challenge API.
 *
 * The console must not query a Verdict table: a null challenge deliberately collapses absent,
 * expired, and already-decided receipts until Verdict publishes its status read contract.
 */
final readonly class VerdictApprovalChallengeReader implements ApprovalChallengeReader
{
    public function __construct(private ApprovalManager $approvals) {}

    public function challengeForToolCall(string $toolCallId): ?ApprovalChallenge
    {
        return $this->approvals->challengeForToolCall($toolCallId);
    }
}
