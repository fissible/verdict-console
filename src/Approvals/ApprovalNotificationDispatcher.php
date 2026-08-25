<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\VerdictConsole\Contracts\ApprovalNotificationRecipients;
use Fissible\VerdictConsole\Notifications\ApprovalResumeOutcomeNotification;
use Fissible\VerdictConsole\Notifications\ApprovedApprovalNotification;
use Fissible\VerdictConsole\Notifications\PendingApprovalNotification;
use Fissible\VerdictConsole\Notifications\RejectedApprovalNotification;
use Illuminate\Contracts\Notifications\Dispatcher;
use Throwable;

/**
 * Delivers observations only after their row-level claim has made redelivery safe.
 *
 * One claim covers every recipient because the observation belongs to the approval, not to an
 * individual delivery target. Recipient-specific claims would make changing a host's recipient
 * list silently re-notify people who already received the same observation. Delivery is never
 * allowed to interrupt the continuation whose observation it reports: Verdict's transition is
 * already durable by then, while a channel outage is console operational state.
 */
final readonly class ApprovalNotificationDispatcher
{
    public function __construct(
        private ApprovalNotificationStore $notifications,
        private ApprovalNotificationRecipients $recipients,
        private Dispatcher $dispatcher,
    ) {}

    /** Announce a newly indexed pending approval once. */
    public function pending(PendingApproval $approval): void
    {
        $this->dispatch($approval, ApprovalNotificationKey::Pending, new PendingApprovalNotification($approval));
    }

    /** Announce Verdict's observed approval without inferring what the resumed tool did. */
    public function approved(PendingApproval $approval): void
    {
        $this->dispatch($approval, ApprovalNotificationKey::Approved, new ApprovedApprovalNotification($approval));
    }

    /** Announce Verdict's observed rejection without claiming a consumed receipt. */
    public function rejected(PendingApproval $approval): void
    {
        $this->dispatch($approval, ApprovalNotificationKey::Rejected, new RejectedApprovalNotification($approval));
    }

    /** Announce Laravel AI's reported continuation result without inferring a completed action. */
    public function resumeOutcome(PendingApproval $approval): void
    {
        $this->dispatch($approval, ApprovalNotificationKey::ResumeOutcome, new ApprovalResumeOutcomeNotification($approval));
    }

    private function dispatch(PendingApproval $approval, ApprovalNotificationKey $key, object $notification): void
    {
        try {
            // Materialised as a plain list rather than through collect(). The contract returns a
            // bare `iterable`, whose key type collect() cannot resolve on every supported Laravel
            // version -- it analysed clean here and failed all 24 CI cells. A host may also hand
            // back a Generator, which must be walked once and kept, not counted and re-walked.
            $supplied = $this->recipients->forApproval($approval, $key);
            $recipients = is_array($supplied) ? array_values($supplied) : iterator_to_array($supplied, false);
        } catch (Throwable $e) {
            $this->markFailed($approval, $key, null, $e);

            return;
        }

        if ($recipients === []) {
            return;
        }

        $claim = null;

        try {
            $claim = $this->notifications->claim($approval, $key->value);

            if ($claim === null) {
                return;
            }

            $this->dispatcher->send($recipients, $notification);
            $this->notifications->markDelivered($claim);
        } catch (Throwable $e) {
            $this->markFailed($approval, $key, $claim, $e);
        }
    }

    /**
     * Preserve a delivery failure when possible without turning a failed audit write into a failed run.
     *
     * The stored exception class identifies the operational seam without retaining an exception
     * message, which commonly includes recipient addresses or rendered copy this audit table must not
     * become a second log for.
     */
    private function markFailed(
        PendingApproval $approval,
        ApprovalNotificationKey $key,
        ?ApprovalNotification $claim,
        Throwable $failure,
    ): void {
        try {
            $claim ??= $this->notifications->claim($approval, $key->value);

            if ($claim !== null) {
                $this->notifications->markFailed($claim, $failure::class);
            }
        } catch (Throwable) {
            // The observed decision and its continuation remain more important than an unavailable
            // console audit table; a retry cannot make a notification channel authoritative.
        }
    }
}
