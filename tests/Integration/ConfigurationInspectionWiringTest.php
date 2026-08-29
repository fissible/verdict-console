<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Configuration\CapabilityInspection;
use Fissible\VerdictConsole\Contracts\ConfigurationInspection;

/**
 * The Feature suite proves the projection over a hand-built registry. This proves the container
 * hands the shipped inspection Verdict's *real* registry — the one `VerdictManager::capability()`
 * writes to — rather than a fresh, empty one that would make every host's console show nothing.
 */
it('inspects the capabilities the application registered through Verdict', function (): void {
    app()->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('wiring test');
        }
    });

    $capability = Capability::usingPolicy('wiring.probe', 'update', fn (ActionEnvelope $e): object => new stdClass);
    app(VerdictManager::class)->capability($capability);

    $inspected = array_values(array_filter(
        app(ConfigurationInspection::class)->capabilities(),
        fn (CapabilityInspection $c): bool => $c->name === 'wiring.probe',
    ));

    expect($inspected)->toHaveCount(1)
        ->and($inspected[0]->configurationFingerprint)->toBe($capability->configurationFingerprint())
        ->and(app(ConfigurationInspection::class)->approvalRules()->ttlSeconds)->toBe(config('verdict.approvals.ttl_seconds'), 'Verdict\'s merged config is what a real host reads.');
});
