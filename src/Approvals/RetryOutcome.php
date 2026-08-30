<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/**
 * The observable result of re-driving a continuation whose decision Verdict already holds.
 *
 * The decision re-sent is re-read live at retry time and never persisted; a consumed receipt
 * refuses the retry because the tool already ran; a pending receipt has no decision to re-send and
 * inventing one would be ADR 0029's forbidden auto-decision.
 */
enum RetryOutcome: string
{
    case ResumedApproval = 'resumed_approval';
    case ResumedRejection = 'resumed_rejection';
    case AlreadyResumed = 'already_resumed';
    case DecisionStillPending = 'decision_still_pending';
    case ReceiptConsumed = 'receipt_consumed';
    case ReceiptUnavailable = 'receipt_unavailable';
}
