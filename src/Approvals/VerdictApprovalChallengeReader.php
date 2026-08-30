<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ApprovalManager;

/**
 * Provides Verdict's supported live-challenge lookup for provenance disclosure and ingestion-time observation.
 *
 * The console must not query a Verdict table: a null challenge deliberately collapses absent,
 * expired, and already-decided receipts. This reader is not the status read; status is read through
 * Verdict's published status-read contract.
 */
final readonly class VerdictApprovalChallengeReader implements ApprovalChallengeReader
{
    public function __construct(private ApprovalManager $approvals) {}

    public function challengeForToolCall(string $toolCallId): ?ApprovalChallenge
    {
        return $this->approvals->challengeForToolCall($toolCallId);
    }
}
