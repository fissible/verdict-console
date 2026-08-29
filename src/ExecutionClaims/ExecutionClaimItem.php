<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\ExecutionClaims;

use DateTimeImmutable;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;

/**
 * The safe claim projection an operator surface may render and authorize.
 *
 * Verdict records `hash('sha256', $claim->id)` as `execution_claim_fingerprint` on decision
 * evidence. Keeping that value here makes the evidence correlation described in design §6.2 a
 * direct join rather than a Console-owned claim record.
 */
final readonly class ExecutionClaimItem
{
    public function __construct(
        public string $id,
        public string $fingerprint,
        public string $capability,
        public string $policy,
        public string $bindingFingerprint,
        public string $status,
        public int $attemptCount,
        public DateTimeImmutable $claimedAt,
        public ?DateTimeImmutable $indeterminateAt,
        public DateTimeImmutable $updatedAt,
    ) {}

    public static function fromClaim(ExecutionClaim $claim): self
    {
        return new self(
            id: $claim->id,
            fingerprint: hash('sha256', $claim->id),
            capability: $claim->capability,
            policy: $claim->policy,
            bindingFingerprint: $claim->bindingFingerprint,
            status: $claim->status->value,
            attemptCount: $claim->attemptCount,
            claimedAt: $claim->claimedAt,
            indeterminateAt: $claim->indeterminateAt,
            updatedAt: $claim->updatedAt,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'capability' => $this->capability,
            'policy' => $this->policy,
            'binding_fingerprint' => $this->bindingFingerprint,
            'status' => $this->status,
            'attempt_count' => $this->attemptCount,
            'claimed_at' => $this->claimedAt->format(DATE_ATOM),
            'indeterminate_at' => $this->indeterminateAt?->format(DATE_ATOM),
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}
