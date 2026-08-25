<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

use Fissible\VerdictConsole\Approvals\PendingApproval;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lets the host constrain approval queries with its own tenancy or ownership model.
 *
 * The console has neither a tenant identifier nor authority to derive one from a participant or
 * conversation. Receiving the query keeps that decision in host code, where a scope may use the
 * host's tenant context, joins, or existing ownership columns without this package storing a second
 * tenancy model.
 */
interface ApprovalScope
{
    /**
     * @param  Builder<PendingApproval>  $query
     * @return Builder<PendingApproval>
     */
    public function apply(Builder $query): Builder;
}
