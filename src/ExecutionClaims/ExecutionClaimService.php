<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\ExecutionClaims;

use Fissible\Verdict\ExecutionClaims\ExecutionClaimManager;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimOutcome;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimResolution;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimTransition;
use Fissible\VerdictConsole\Contracts\ExecutionClaimAuthority;
use Fissible\VerdictConsole\Exceptions\ExecutionClaimNotFound;
use Fissible\VerdictConsole\Exceptions\ExecutionClaimResolutionFailed;
use Fissible\VerdictConsole\Exceptions\ExecutionClaimStillActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Lists and reconciles Verdict execution claims without creating Console claim state.
 *
 * An indeterminate claim is the one queue where a human is genuinely required (design §8). Verdict
 * retains the resolution's actor and reason; this service records nothing of its own, and returns
 * Verdict's outcome rather than treating the caller's intended resolution as what happened.
 */
final readonly class ExecutionClaimService
{
    private const string AUTHORIZATION_REFUSAL_MESSAGE = 'This operator may not resolve this execution claim.';

    public function __construct(
        private ExecutionClaimManager $claims,
        private ExecutionClaimAuthority $authority,
    ) {}

    /** @return list<ExecutionClaimItem> */
    public function unresolved(): array
    {
        return array_map(ExecutionClaimItem::fromClaim(...), $this->claims->unresolved());
    }

    public function resolve(
        string $claimId,
        ExecutionClaimResolution $resolution,
        ?Authenticatable $operator,
        string $reason,
        bool $force = false,
    ): ExecutionClaimTransition {
        // This boundary precedes lookup so an anonymous caller cannot use the service as an id
        // oracle, even if a host's Gate deliberately has guest-permissive callbacks.
        if ($operator === null) {
            throw new AuthorizationException(self::AUTHORIZATION_REFUSAL_MESSAGE);
        }

        $claim = $this->claims->find($claimId);

        if ($claim === null) {
            throw ExecutionClaimNotFound::forClaim($claimId);
        }

        $item = ExecutionClaimItem::fromClaim($claim);

        if (! $this->authority->allows($item, $operator)) {
            throw new AuthorizationException(self::AUTHORIZATION_REFUSAL_MESSAGE);
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A reconciliation reason is required.');
        }

        if ($claim->status === ExecutionClaimStatus::Claimed && ! $force) {
            throw ExecutionClaimStillActive::forClaim($claimId);
        }

        $transition = $this->claims->resolve(
            $claimId,
            $resolution,
            $this->authority->actorKeyFor($operator),
            $reason,
        );

        if (! in_array($transition->outcome, [ExecutionClaimOutcome::Completed, ExecutionClaimOutcome::Released], true)) {
            throw ExecutionClaimResolutionFailed::forClaim($claimId, $transition->outcome);
        }

        return $transition;
    }
}
