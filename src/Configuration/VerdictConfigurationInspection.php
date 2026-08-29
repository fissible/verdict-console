<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Configuration;

use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\VerdictConsole\Contracts\ConfigurationInspection;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * An inspect-only projection of the configuration Verdict has already been given, with no write
 * path.
 *
 * Verdict policy is application code, and each capability's
 * configuration fingerprint is recorded in every capability-resolved decision record, so
 * a configuration write changes what already-recorded evidence means: a row would no longer
 * describe the capability that produced it. The console therefore shows declared state only —
 * names, limits, postures, and the fingerprint itself, which an operator can match against evidence
 * rows' `configuration_fingerprint`. Closures are never invoked: evaluating a resolver would turn
 * inspection into an action rather than a display-safe read.
 */
final readonly class VerdictConfigurationInspection implements ConfigurationInspection
{
    public function __construct(
        private CapabilityRegistry $capabilities,
        private Config $config,
    ) {}

    /** @return list<CapabilityInspection> */
    public function capabilities(): array
    {
        $inspections = array_map(
            fn (Capability $capability): CapabilityInspection => $this->inspect($capability),
            array_values($this->capabilities->all()),
        );

        usort($inspections, fn (CapabilityInspection $left, CapabilityInspection $right): int => $left->name <=> $right->name);

        return $inspections;
    }

    /** @return list<RateLimitInspection> */
    public function rateLimits(): array
    {
        $limits = array_values(array_filter(
            array_map(fn (CapabilityInspection $capability): ?RateLimitInspection => $capability->rateLimit, $this->capabilities()),
        ));

        // This order is inherited from the name-sorted capability inspections.

        return $limits;
    }

    public function approvalRules(): ApprovalRules
    {
        $ttl = $this->config->get('verdict.approvals.ttl_seconds');
        $authorizer = $this->config->get('verdict.approvals.authorizer');
        $strictProvenance = $this->config->get('verdict.approvals.strict_provenance', false);
        $gate = $this->config->get('verdict-console.approvals.gate', 'approve-verdict-action');

        return new ApprovalRules(
            ttlSeconds: is_int($ttl) ? $ttl : null,
            authorizer: is_string($authorizer) ? $authorizer : null,
            strictProvenance: is_bool($strictProvenance) ? $strictProvenance : false,
            gateAbility: is_string($gate) ? $gate : 'approve-verdict-action',
        );
    }

    private function inspect(Capability $capability): CapabilityInspection
    {
        $rateLimit = $capability->rateLimitPolicy();
        $executionTarget = $capability->executionTargetPolicy();
        $executionClaim = $capability->executionClaimPolicy();
        $declared = $capability->declaredConfiguration();

        return new CapabilityInspection(
            name: $capability->name,
            ability: $capability->ability,
            configurationFingerprint: $capability->configurationFingerprint(),
            configurationVersion: is_string($declared['configuration_version'] ?? null) ? $declared['configuration_version'] : null,
            confirmationRequired: $capability->confirmationRequired(),
            confirmationReason: $capability->confirmationReason(),
            confirmationTtlSeconds: $capability->confirmationTtlSeconds(),
            executionTargetPolicy: $executionTarget?->name,
            executionTargetStrategy: $executionTarget?->strategy->value,
            rateLimit: $rateLimit === null ? null : new RateLimitInspection(
                capability: $capability->name,
                name: $rateLimit->name,
                limit: $rateLimit->limit,
                windowSeconds: $rateLimit->windowSeconds,
                reason: $rateLimit->reason,
            ),
            executionClaimPolicy: $executionClaim?->name,
            requiresIntentRecord: $capability->intentRecordRequirement(),
            consequential: $capability->isConsequential(),
        );
    }
}
