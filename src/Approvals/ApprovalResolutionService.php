<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\Verdict\Approvals\ApprovalDecisionKind;
use Fissible\Verdict\Approvals\ApprovalManager;
use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\Verdict\Approvals\ApprovalTransition;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\VerdictConsole\Contracts\ApproverAuthority;
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Events\ApprovalDecisionRefused;
use Fissible\VerdictConsole\Exceptions\ApprovalNotDrivable;
use Fissible\VerdictConsole\Exceptions\ApprovalResumeFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Throwable;

/** Turns one authorized human decision into one exact Laravel AI continuation. */
final readonly class ApprovalResolutionService
{
    private const string AUTHORIZATION_REFUSAL_MESSAGE = 'This approver may not resolve this approval.';

    public function __construct(
        private ApprovalManager $approvals,
        private ApprovalStatusReader $statuses,
        private ApproverAuthority $authority,
        private ResumableAgents $agents,
        private ConversationParticipants $participants,
        private PendingApprovalStore $pendingApprovals,
        private ApprovalReconciliationStore $reconciliations,
        private ApprovalNotificationDispatcher $notifications,
        private Dispatcher $events,
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

    /**
     * End a lapsed Laravel AI turn without inventing a second Verdict decision.
     *
     * The status read distinguishes a still-pending decision from a lapsed or already-decided
     * receipt. Sending Laravel AI a keyed rejection for the latter safely covers both: an expired
     * turn records its refusal, while an already-resolved turn is rejected by Laravel AI before any
     * tool can execute.
     */
    public function close(PendingApproval $approval, ?Authenticatable $approver): CloseOutcome
    {
        $this->assertVisible($approval);

        if (! $this->authority->allows($approval, $approver)) {
            throw new AuthorizationException(self::AUTHORIZATION_REFUSAL_MESSAGE);
        }

        if ($approval->resumability !== Resumability::Drivable) {
            throw ApprovalNotDrivable::forApproval($approval);
        }

        if ($approval->resolver_key === null || $approval->conversation_id === null) {
            throw ApprovalNotDrivable::forMissingResumeContext($approval);
        }

        // A pending, unexpired status still accepts a real Verdict decision, so close must not
        // preempt it. A missing or mismatched view cannot authorize one.
        $view = $this->statusFor($approval);

        if ($view?->status === ApprovalReceiptStatus::Pending && $view->expiresAt > now()) {
            return CloseOutcome::DecisionStillAvailable;
        }

        $this->pendingApprovals->beginResumeAttempt($approval);

        try {
            $participant = $approval->participant_reference === null
                ? null
                : $this->participants->resolve($approval->participant_reference);

            $agent = $this->agents->resolve($approval->resolver_key)
                ->continue($approval->conversation_id, $participant);
        } catch (Throwable $e) {
            // No prompt started, so a close cannot have reached the tool it is refusing.
            $this->reconciliations->detect($approval, ResumeFailurePhase::DefinitelyPreExecution);

            throw ApprovalResumeFailed::forApproval($approval, $e);
        }

        try {
            $agent->prompt(Decisions::from([
                $approval->tool_call_id => Decision::reject(),
            ]));
        } catch (ApprovalMismatchException $e) {
            // ApprovalMismatchException also reports a participant-scoped conversation miss, which
            // leaves the paused turn untouched. Only this measured Laravel AI message proves the
            // exact tool call was already resolved before execution; every other mismatch remains
            // indeterminate rather than falsely telling an operator their close succeeded.
            if ($e->getMessage() === 'Approval decisions include already-resolved tool call ids.') {
                return CloseOutcome::AlreadyResolved;
            }

            $this->reconciliations->detect($approval, ResumeFailurePhase::Indeterminate);

            throw ApprovalResumeFailed::forApproval($approval, $e);
        } catch (Throwable $e) {
            // prompt() owns tool execution, so any other throw remains indeterminate like VC-10.
            $this->reconciliations->detect($approval, ResumeFailurePhase::Indeterminate);

            throw ApprovalResumeFailed::forApproval($approval, $e);
        }

        return CloseOutcome::Closed;
    }

    private function resolve(PendingApproval $approval, ?Authenticatable $approver, bool $approve): ?ApprovalTransition
    {
        $this->assertVisible($approval);

        if (! $this->authority->allows($approval, $approver)) {
            throw new AuthorizationException(self::AUTHORIZATION_REFUSAL_MESSAGE);
        }

        if ($approval->resumability !== Resumability::Drivable) {
            throw ApprovalNotDrivable::forApproval($approval);
        }

        // A drivable row was checked at ingestion, but a corrupt or manually edited row must not
        // spend a receipt merely because its enum says drivable.
        if ($approval->resolver_key === null || $approval->conversation_id === null) {
            throw ApprovalNotDrivable::forMissingResumeContext($approval);
        }

        // Eloquent attributes are mutable. Preserve the context already admitted above before
        // notifying host code, so that code cannot make this continuation use a different row value.
        $resolverKey = $approval->resolver_key;
        $conversationId = $approval->conversation_id;

        $view = $this->statusFor($approval);

        if ($view === null || $view->status !== ApprovalReceiptStatus::Pending || $view->expiresAt <= now()) {
            return null;
        }

        $actorKey = $this->authority->actorKeyFor($approver);
        $transition = $approve
            ? $this->approvals->approve($view->receiptId, $view->toolCallId, $actorKey)
            : $this->approvals->reject($view->receiptId, $view->toolCallId, $actorKey);

        $expected = $approve ? ApprovalOutcome::Approved : ApprovalOutcome::Rejected;

        if ($transition->outcome === ApprovalOutcome::Unauthorized) {
            $kind = $approve ? ApprovalDecisionKind::Approve : ApprovalDecisionKind::Reject;

            $this->events->dispatch(new ApprovalDecisionRefused($approval, $kind));

            throw new AuthorizationException(self::AUTHORIZATION_REFUSAL_MESSAGE);
        }

        if ($transition->outcome !== $expected) {
            return $transition;
        }

        // This is the returned Verdict transition itself, the only decision observation the console
        // owns here. Notify before attempting Laravel AI's continuation, whose outcome cannot turn
        // this receipt transition into evidence that an action finished.
        $approve ? $this->notifications->approved($approval) : $this->notifications->rejected($approval);

        // This is console-owned operational state, deliberately outside Verdict's security-state
        // transaction. It says a continuation was attempted, never that a receipt remains valid.
        $this->pendingApprovals->beginResumeAttempt($approval);

        try {
            $participant = $approval->participant_reference === null
                ? null
                : $this->participants->resolve($approval->participant_reference);

            $agent = $this->agents->resolve($resolverKey)
                ->continue($conversationId, $participant);
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

    /**
     * Refuse a row the host's current scope no longer exposes before consulting Verdict.
     *
     * A model can outlive a tenant switch or be supplied directly to this headless service. Treating
     * either as merely "not found" would still allow a later manager call to spend its receipt, so
     * the same non-disclosing refusal used for an unauthorized approver stops it at the boundary.
     */
    private function assertVisible(PendingApproval $approval): void
    {
        if (! $this->pendingApprovals->isVisible($approval)) {
            throw new AuthorizationException(self::AUTHORIZATION_REFUSAL_MESSAGE);
        }
    }

    /**
     * Read the row's receipt by its stable id when available, rejecting a foreign tool-call view.
     *
     * The status read is observational and poll-consistent. The transition manager remains the
     * authority that re-validates the receipt under its write transaction.
     */
    private function statusFor(PendingApproval $approval): ?ApprovalStatusView
    {
        $view = $approval->receipt_id === null
            ? $this->statuses->statusForToolCall($approval->tool_call_id)
            : $this->statuses->statusFor($approval->receipt_id);

        return $view?->toolCallId === $approval->tool_call_id ? $view : null;
    }
}
