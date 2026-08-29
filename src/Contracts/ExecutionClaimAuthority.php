<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\VerdictConsole\Exceptions\ApproverNotIdentified;
use Fissible\VerdictConsole\ExecutionClaims\ExecutionClaimItem;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may reconcile an execution claim, and what name that reconciliation is recorded under.
 *
 * Both answers belong to the host. The Gate receives the item so a host policy can scope by
 * capability or tenant, while the package never invents an identity convention for Verdict's
 * reconciliation audit trail.
 */
interface ExecutionClaimAuthority
{
    /** Whether this operator may reconcile this claim. */
    public function allows(ExecutionClaimItem $claim, ?Authenticatable $operator): bool;

    /**
     * The audit label Verdict records as the person who resolved this claim.
     *
     * @throws ApproverNotIdentified when there is no operator to name
     */
    public function actorKeyFor(?Authenticatable $operator): string;
}
