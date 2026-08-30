<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\ExecutionClaims;

use Fissible\VerdictConsole\Contracts\ExecutionClaimAuthority;
use Fissible\VerdictConsole\Exceptions\ApproverNotIdentified;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The shipped fail-closed authority: a Laravel Gate ability and the operator's own identifier.
 *
 * The ability comes from `verdict-console.execution_claims.gate`; a host with another authority
 * model replaces this binding instead of making reconciliation policy a package decision.
 */
final readonly class GateExecutionClaimAuthority implements ExecutionClaimAuthority
{
    public function __construct(
        private Gate $gate,
        private Config $config,
    ) {}

    public function allows(ExecutionClaimItem $claim, ?Authenticatable $operator): bool
    {
        // A host may intentionally grant a Gate ability to guests. Reconciliation must not expose
        // that escape hatch: an unauthenticated call cannot be an attributable human resolution.
        if ($operator === null) {
            return false;
        }

        return $this->gate->forUser($operator)->allows($this->ability(), $claim);
    }

    public function actorKeyFor(?Authenticatable $operator): string
    {
        if ($operator === null) {
            throw ApproverNotIdentified::make();
        }

        $key = $operator->getAuthIdentifier();

        if ($key === null || (is_string($key) && trim($key) === '')) {
            throw ApproverNotIdentified::make();
        }

        return (string) $key;
    }

    private function ability(): string
    {
        $ability = $this->config->get('verdict-console.execution_claims.gate');

        return is_string($ability) && $ability !== '' ? $ability : 'resolve-verdict-execution-claim';
    }
}
