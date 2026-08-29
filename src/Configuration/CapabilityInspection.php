<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Configuration;

/**
 * One capability as declared by Verdict.
 * Display-safe declared configuration: names, limits, and postures, never closures or resolved targets.
 */
final readonly class CapabilityInspection
{
    public function __construct(
        public string $name,
        public string $ability,
        public string $configurationFingerprint,
        public ?string $configurationVersion,
        public bool $confirmationRequired,
        public ?string $confirmationReason,
        public ?int $confirmationTtlSeconds,
        public ?string $executionTargetPolicy,
        public ?string $executionTargetStrategy,
        public ?RateLimitInspection $rateLimit,
        public ?string $executionClaimPolicy,
        public ?bool $requiresIntentRecord,
        public bool $consequential,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'ability' => $this->ability,
            'configuration_fingerprint' => $this->configurationFingerprint,
            'configuration_version' => $this->configurationVersion,
            'confirmation_required' => $this->confirmationRequired,
            'confirmation_reason' => $this->confirmationReason,
            'confirmation_ttl_seconds' => $this->confirmationTtlSeconds,
            'execution_target_policy' => $this->executionTargetPolicy,
            'execution_target_strategy' => $this->executionTargetStrategy,
            'rate_limit' => $this->rateLimit?->toArray(),
            'execution_claim_policy' => $this->executionClaimPolicy,
            'requires_intent_record' => $this->requiresIntentRecord,
            'consequential' => $this->consequential,
        ];
    }
}
