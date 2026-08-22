<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Exceptions\ApproverNotIdentified;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may decide an approval, and what name that decision is recorded under.
 *
 * Both answers belong to the host. This package never hard-codes authority — an approval console
 * that lets the wrong people approve is worse than no console — and it never invents an identity
 * for the audit trail either.
 *
 * Tenancy and scoping are deliberately absent here; they are VC-12.
 */
interface ApproverAuthority
{
    /**
     * Whether this approver may decide this approval.
     *
     * `$approver` is null when nobody is authenticated, which must never be permitted: an anonymous
     * approval is the absence of the control, not a permissive case.
     */
    public function allows(PendingApproval $approval, ?Authenticatable $approver): bool;

    /**
     * The actor key recorded against the receipt as `approvedBy` / `rejectedBy`.
     *
     * This is an **audit label**, not an identity to reconstruct from. It answers "who decided
     * this?" in the host's own records, months later, and nothing in this package parses it.
     *
     * @throws ApproverNotIdentified when there is no approver to name
     */
    public function actorKeyFor(?Authenticatable $approver): string;
}
