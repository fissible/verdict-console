<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\VerdictConsole\Contracts\ApproverAuthority;
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Exceptions\ApprovalNotDrivable;
use Fissible\VerdictConsole\Exceptions\ApprovalResumeFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Throwable;

/** Turns one authorized human decision into one exact Laravel AI continuation. */
final readonly class ApprovalResolutionService
{
    public function __construct(
        private ApprovalManager $approvals,
        private ApproverAuthority $authority,
        private ResumableAgents $agents,
        private ConversationParticipants $participants,
        private PendingApprovalStore $pendingApprovals,
        private ApprovalReconciliationStore $reconciliations,
    ) {}

    /**
     * Approve and resume this exact pause, or return null when it is no longer actionable.
     */
    public function approve(PendingApproval $approval, ?Authenticatable $approver): ?ApprovalTransition
    {
        return $this->resolve($approval, $approver, true);
    }

    /**
     * Reject and resume this exact pause, or return null when it is no longer actionable.
     */
    public function reject(PendingApproval $approval, ?Authenticatable $approver): ?ApprovalTransition
    {
        return $this->resolve($approval, $approver, false);
    }

    private function resolve(PendingApproval $approval, ?Authenticatable $approver, bool $approve): ?ApprovalTransition
    {
        if (! $this->authority->allows($approval, $approver)) {
            throw new AuthorizationException('This approver may not resolve this approval.');
        }

        if ($approval->resumability !== Resumability::Drivable) {
            throw ApprovalNotDrivable::forApproval($approval);
        }

        // A drivable row was checked at ingestion, but a corrupt or manually edited row must not
        // spend a receipt merely because its enum says drivable.
        if ($approval->resolver_key === null || $approval->conversation_id === null) {
            throw ApprovalNotDrivable::forMissingResumeContext($approval);
        }

        // The live manager is the only supported status read. Its null is intentionally not
        // classified: absent, ambiguous, expired, and non-pending collapse at this boundary.
        $challenge = $this->approvals->challengeForToolCall($approval->tool_call_id);

        if ($challenge === null) {
            return null;
        }

        $actorKey = $this->authority->actorKeyFor($approver);
        $transition = $approve
            ? $this->approvals->approve($challenge->receiptId, $challenge->toolCallId, $actorKey)
            : $this->approvals->reject($challenge->receiptId, $challenge->toolCallId, $actorKey);

        $expected = $approve ? ApprovalOutcome::Approved : ApprovalOutcome::Rejected;

        if ($transition->outcome !== $expected) {
            return $transition;
        }

        // This is console-owned operational state, deliberately outside Verdict's security-state
        // transaction. It says a continuation was attempted, never that a receipt remains valid.
        $this->pendingApprovals->beginResumeAttempt($approval);

        try {
            $participant = $approval->participant_reference === null
                ? null
                : $this->participants->resolve($approval->participant_reference);

            $agent = $this->agents->resolve($approval->resolver_key)
                ->continue($approval->conversation_id, $participant);
        } catch (Throwable $e) {
            // No prompt started, so Laravel AI could not have reached the approved tool.
            $this->reconciliations->detect($approval, ResumeFailurePhase::DefinitelyPreExecution);

            throw ApprovalResumeFailed::forApproval($approval, $e);
        }

        try {
            $agent->prompt(Decisions::from([
                $approval->tool_call_id => $approve ? Decision::approve() : Decision::reject(),
            ]));
        } catch (Throwable $e) {
            // prompt() runs the tool and writes its result internally; a throw gives no public
            // observation of which side of that boundary it fell on, so it is indeterminate.
            $this->reconciliations->detect($approval, ResumeFailurePhase::Indeterminate);

            throw ApprovalResumeFailed::forApproval($approval, $e);
        }

        return $transition;
    }
}
