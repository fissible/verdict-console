<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\VerdictConsole\Contracts\ApproverAuthority;
use Fissible\VerdictConsole\Exceptions\ApproverNotIdentified;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The shipped authority: a Laravel Gate ability, and the authenticated user's own identifier.
 *
 * **It denies until the host says otherwise, and that is the feature.** Laravel's Gate returns false
 * for an ability nobody defined, so a fresh install approves nothing. An approval console whose
 * default is "anyone may approve" would be worse than having no console at all, so the default here
 * is the one that fails closed: define the ability, or nothing is approvable.
 *
 * The ability name comes from `verdict-console.approvals.gate`, and the whole class is replaceable
 * through the container for hosts whose authority model is not a Gate.
 */
final readonly class GateApproverAuthority implements ApproverAuthority
{
    public function __construct(
        private Gate $gate,
        private Config $config,
    ) {}

    public function allows(PendingApproval $approval, ?Authenticatable $approver): bool
    {
        // An unauthenticated approver is refused before the Gate is consulted. Passing null through
        // would let a host's `before()` callback or a permissive ability grant an *anonymous*
        // approval, and no ability definition should be able to reach that outcome by accident.
        if ($approver === null) {
            return false;
        }

        return $this->gate->forUser($approver)->allows($this->ability(), $approval);
    }

    public function actorKeyFor(?Authenticatable $approver): string
    {
        if ($approver === null) {
            throw ApproverNotIdentified::make();
        }

        $key = $approver->getAuthIdentifier();

        // Deliberately the bare identifier, not `ClassName:7`. This package does not invent identity
        // conventions on a host's behalf — the same rule that keeps it from reconstructing a
        // participant from a class name and an id. A host whose identifiers are not unique across
        // guards, or which wants a richer label in its audit trail, binds its own authority.
        if ($key === null || (is_string($key) && trim($key) === '')) {
            throw ApproverNotIdentified::make();
        }

        return (string) $key;
    }

    private function ability(): string
    {
        $ability = $this->config->get('verdict-console.approvals.gate');

        return is_string($ability) && $ability !== '' ? $ability : 'approve-verdict-action';
    }
}
