<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Leaves the package neutral until a host supplies its own tenancy boundary.
 *
 * A fabricated tenant default would be an authority decision made without the host's data model.
 * Hosts that have a boundary replace this binding, and every console read then carries that scope.
 */
final class UnscopedApprovalScope implements ApprovalScope
{
    public function apply(Builder $query): Builder
    {
        return $query;
    }
}
