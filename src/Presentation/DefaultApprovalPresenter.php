<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Presentation;

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\VerdictConsole\Contracts\ApprovalPresenter;
use Laravel\Ai\Approvals\PendingApproval as LaravelPendingApproval;

/**
 * The conservative presentation installed with the package.
 *
 * It displays only the boundary's vocabulary: Laravel AI's tool and human reason, Verdict's
 * capability and reason when a receipt-backed challenge exists, plus a one-way fingerprint of the
 * arguments. Ability, target policy, target identity, and claim type are intentionally absent: the
 * public pause/challenge APIs do not expose them, and inventing values would mislead an approver.
 *
 * Two fields the challenge *does* expose are omitted on purpose, because both look like helpful
 * additions and neither is:
 *
 * - **`expiresAt`.** Receipt TTL is Verdict's, read live through `ApprovalStatusReader`. VC-4
 *   deliberately gave the row no expiry column for the same reason, and copying the deadline into
 *   a durable presentation would reintroduce exactly that divergence: an inbox rendering an
 *   approval as still actionable from a snapshot taken minutes ago.
 * - **`provenance`.** A presentation must never persist provenance: it is released application data,
 *   not console-owned workflow state. A host surface may render provenance live from the challenge;
 *   `ApprovalManager::issue()` already applied (or refused) that release while the invocation frame
 *   existed, so rendering does not initiate a second context release. [ADR 0026](https://github.com/fissible/verdict/blob/main/docs/adr/0026-what-an-approver-is-shown.md)
 *   makes the durable receipt payload the answer to a later request having no invocation frame.
 */
final class DefaultApprovalPresenter implements ApprovalPresenter
{
    public function present(LaravelPendingApproval $approval, ?ApprovalChallenge $challenge = null): ApprovalPresentation
    {
        return new ApprovalPresentation(
            tool: $approval->tool,
            argumentsFingerprint: ArgumentFingerprint::make($approval->arguments),
            reason: $challenge !== null && $challenge->reason !== null ? $challenge->reason : $approval->reason,
            capability: $challenge?->capability,
        );
    }
}
