<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/**
 * The observable result of an operator's non-authorizing attempt to end a paused turn.
 *
 * A null challenge cannot say whether a receipt expired or another actor already resolved it, so
 * callers need a result that distinguishes a carried-out close from a live decision that won the
 * race. The already-resolved outcome is separate so a harmless Laravel AI mismatch is not shown as
 * a framework failure.
 */
enum CloseOutcome: string
{
    case Closed = 'closed';
    case AlreadyResolved = 'already_resolved';
    case DecisionStillAvailable = 'decision_still_available';
}
