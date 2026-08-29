<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Configuration;

/**
 * Approval rules read together from Verdict's configuration and the console's gate.
 * Display-safe declared configuration: names, limits, and postures, never closures or resolved targets.
 */
final readonly class ApprovalRules
{
    public function __construct(
        public ?int $ttlSeconds,
        public ?string $authorizer,
        public bool $strictProvenance,
        public string $gateAbility,
    ) {}

    /** @return array{ttl_seconds: ?int, authorizer: ?string, strict_provenance: bool, gate_ability: string} */
    public function toArray(): array
    {
        return [
            'ttl_seconds' => $this->ttlSeconds,
            'authorizer' => $this->authorizer,
            'strict_provenance' => $this->strictProvenance,
            'gate_ability' => $this->gateAbility,
        ];
    }
}
