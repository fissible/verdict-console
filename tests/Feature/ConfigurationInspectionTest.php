<?php

declare(strict_types=1);

use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimPolicy;
use Fissible\Verdict\RateLimits\RateLimitPolicy;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\VerdictConsole\Configuration\ApprovalRules;
use Fissible\VerdictConsole\Configuration\CapabilityInspection;
use Fissible\VerdictConsole\Configuration\RateLimitInspection;
use Fissible\VerdictConsole\Configuration\VerdictConfigurationInspection;
use Fissible\VerdictConsole\Contracts\ConfigurationInspection;

/**
 * Every closure a capability can hold throws. Inspection is a read of what was *declared*; if any
 * test here trips one of these, the read-model has started evaluating policy, which is the one thing
 * an inspect-only surface must never do.
 */
function inspectionNeverCalled(): Closure
{
    return fn (): never => throw new LogicException('Configuration inspection must not invoke capability code.');
}

function inspectionFullCapability(): Capability
{
    return Capability::usingPolicy('orders.refund', 'update', inspectionNeverCalled())
        ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(name: 'refund-target', identityUsing: inspectionNeverCalled()))
        ->requiresConfirmation(inspectionNeverCalled(), reason: 'Refunds move money.', ttlSeconds: 600)
        ->rateLimit(RateLimitPolicy::fixedWindow('refund-rate', 5, 3600, inspectionNeverCalled(), 'Five refunds an hour.'))
        ->atMostOnce(ExecutionClaimPolicy::named('refund-once', inspectionNeverCalled()))
        ->configurationVersion('2026-08')
        ->requiresIntentRecord(true)
        ->executeUsing(inspectionNeverCalled());
}

function inspectionBareCapability(): Capability
{
    return Capability::usingPolicy('billing.read', 'view', inspectionNeverCalled());
}

/**
 * The discriminating third shape: confirmation with no reason and no TTL, a refresh-strategy target,
 * an explicitly *declined* intent posture, and a second rate limit — so a projection that derived
 * fields from the wrong source, or reported only the first policy it met, cannot pass.
 */
function inspectionSparseCapability(): Capability
{
    return Capability::usingPolicy('accounts.close', 'delete', inspectionNeverCalled())
        ->executionTarget(ExecutionTargetPolicy::refresh(name: 'close-target', identityUsing: inspectionNeverCalled(), refreshUsing: inspectionNeverCalled()))
        ->requiresConfirmation(inspectionNeverCalled())
        ->rateLimit(RateLimitPolicy::fixedWindow('close-rate', 1, 86400, inspectionNeverCalled()))
        ->requiresIntentRecord(false);
}

function inspectionOver(Capability ...$capabilities): ConfigurationInspection
{
    $registry = new CapabilityRegistry;

    foreach ($capabilities as $capability) {
        $registry->register($capability);
    }

    return new VerdictConfigurationInspection($registry, app('config'));
}

/**
 * Properties are compared through get_object_vars(): Pest's toMatchArray() prefers an object's
 * toArray(), whose keys are the snake_case rendering, and these assertions name the declared
 * properties.
 *
 * The fingerprint is the point of the surface: every decision record carries the
 * `configuration_fingerprint` of the capability as it stood when the decision was made, so an
 * operator reading evidence can tell whether a row was decided under the configuration they are
 * looking at now. It must be Verdict's own value, never one this package recomputes.
 */
it('lists every registered capability with its declared configuration and Verdicts fingerprint', function (): void {
    $full = inspectionFullCapability();
    $bare = inspectionBareCapability();
    $sparse = inspectionSparseCapability();

    $capabilities = inspectionOver($full, $bare, $sparse)->capabilities();

    expect($capabilities)->toHaveCount(3)
        ->and(array_map(fn (CapabilityInspection $c): string => $c->name, $capabilities))->toBe(['accounts.close', 'billing.read', 'orders.refund'], 'Sorted by name, not registration order.');

    [$close, $read, $refund] = $capabilities;

    expect(get_object_vars($refund))->toMatchArray([
        'name' => 'orders.refund',
        'ability' => 'update',
        'configurationFingerprint' => $full->configurationFingerprint(),
        'configurationVersion' => '2026-08',
        'confirmationRequired' => true,
        'confirmationReason' => 'Refunds move money.',
        'confirmationTtlSeconds' => 600,
        'executionTargetPolicy' => 'refund-target',
        'executionTargetStrategy' => 'accept_stale_snapshot',
        'executionClaimPolicy' => 'refund-once',
        'requiresIntentRecord' => true,
        'consequential' => true,
    ])
        ->and($refund->rateLimit)->toBeInstanceOf(RateLimitInspection::class)
        ->and(get_object_vars($refund->rateLimit))->toMatchArray(['capability' => 'orders.refund', 'name' => 'refund-rate', 'limit' => 5, 'windowSeconds' => 3600, 'reason' => 'Five refunds an hour.'])
        ->and(get_object_vars($read))->toMatchArray([
            'name' => 'billing.read',
            'ability' => 'view',
            'configurationFingerprint' => $bare->configurationFingerprint(),
            'configurationVersion' => null,
            'confirmationRequired' => false,
            'confirmationReason' => null,
            'confirmationTtlSeconds' => null,
            'executionTargetPolicy' => null,
            'executionTargetStrategy' => null,
            'rateLimit' => null,
            'executionClaimPolicy' => null,
            'requiresIntentRecord' => null,
            'consequential' => false,
        ])
        ->and(get_object_vars($close))->toMatchArray([
            'name' => 'accounts.close',
            'ability' => 'delete',
            'configurationFingerprint' => $sparse->configurationFingerprint(),
            'configurationVersion' => null,
            'confirmationRequired' => true,
            'confirmationReason' => null,
            'confirmationTtlSeconds' => null,
            'executionTargetPolicy' => 'close-target',
            'executionTargetStrategy' => 'refresh',
            'executionClaimPolicy' => null,
            'requiresIntentRecord' => false,
            'consequential' => true,
        ])
        ->and(get_object_vars($close->rateLimit))->toMatchArray(['capability' => 'accounts.close', 'name' => 'close-rate', 'limit' => 1, 'windowSeconds' => 86400, 'reason' => null]);
});

it('lists rate limits only for capabilities that declare one, by capability name', function (): void {
    $limits = inspectionOver(inspectionFullCapability(), inspectionBareCapability(), inspectionSparseCapability())->rateLimits();

    expect($limits)->toHaveCount(2)
        ->and($limits[0])->toBeInstanceOf(RateLimitInspection::class)
        ->and(array_map(fn (RateLimitInspection $l): array => [$l->capability, $l->name, $l->limit, $l->windowSeconds], $limits))
        ->toBe([['accounts.close', 'close-rate', 1, 86400], ['orders.refund', 'refund-rate', 5, 3600]]);
});

/**
 * Approval rules are two packages' configuration read together: Verdict's receipt TTL, authorizer,
 * and strict-provenance posture, and this console's own Gate ability — the rule for *who may
 * approve*, which is the host's decision delegated through config (design §7).
 */
it('reads the approval rules from Verdicts configuration and the consoles gate', function (): void {
    config()->set('verdict.approvals.ttl_seconds', 1200);
    config()->set('verdict.approvals.authorizer', 'App\\Approvals\\TenantAuthorizer');
    config()->set('verdict.approvals.strict_provenance', true);
    config()->set('verdict-console.approvals.gate', 'approve-refunds');

    $rules = inspectionOver()->approvalRules();

    expect($rules)->toBeInstanceOf(ApprovalRules::class)
        ->and(get_object_vars($rules))->toMatchArray([
            'ttlSeconds' => 1200,
            'authorizer' => 'App\\Approvals\\TenantAuthorizer',
            'strictProvenance' => true,
            'gateAbility' => 'approve-refunds',
        ]);

    // The two postures are independent settings; an authorizer being configured says nothing about
    // strict provenance, and a projection that inferred one from the other would be wrong here.
    config()->set('verdict.approvals.strict_provenance', false);

    expect(get_object_vars(inspectionOver()->approvalRules()))->toMatchArray([
        'authorizer' => 'App\\Approvals\\TenantAuthorizer',
        'strictProvenance' => false,
    ]);
});

/**
 * Absent Verdict keys read as absent, never as a default this package invented on Verdict's behalf.
 * This tier boots only the console's provider, so Verdict's config is genuinely not merged here —
 * asserted first, so the test cannot pass by forcing the state it claims to observe.
 */
it('reports unset approval rules as unset rather than inventing Verdicts defaults', function (): void {
    expect(config('verdict.approvals'))->toBeNull('Verdict is not booted in the Feature tier; its config must be absent, not nulled by this test.');

    $rules = inspectionOver()->approvalRules();

    expect($rules->ttlSeconds)->toBeNull()
        ->and($rules->authorizer)->toBeNull()
        ->and($rules->strictProvenance)->toBeFalse()
        ->and($rules->gateAbility)->toBe('approve-verdict-action', 'The console ships this default itself.');
});

/**
 * The acceptance criterion's second half: no write path exists. Enforced at the surface a host
 * programs against — the contract exposes exactly the three reads; the shipped implementation adds
 * no public surface beyond it, holds no mutable state, and hands out neither the registry nor the
 * config repository; the projections hold no mutable state. Whether the implementation writes
 * *internally* is not something a test can prove; that stays a review guarantee, which is why the
 * class docblock has to state the reason writes are excluded.
 */
it('exposes no write path', function (): void {
    $publicMethods = fn (string $class): array => array_values(array_map(
        fn (ReflectionMethod $m): string => $m->getName(),
        array_filter((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC), fn (ReflectionMethod $m): bool => ! $m->isConstructor()),
    ));
    $sorted = function (array $names): array {
        sort($names);

        return $names;
    };

    expect($sorted($publicMethods(ConfigurationInspection::class)))->toBe(['approvalRules', 'capabilities', 'rateLimits'])
        ->and($sorted($publicMethods(VerdictConfigurationInspection::class)))->toBe($sorted($publicMethods(ConfigurationInspection::class)), 'The contract is the whole public surface.');

    foreach ([VerdictConfigurationInspection::class, CapabilityInspection::class, RateLimitInspection::class, ApprovalRules::class] as $class) {
        expect((new ReflectionClass($class))->isReadOnly())->toBeTrue($class.' must hold no mutable state.');
    }

    expect((new ReflectionClass(VerdictConfigurationInspection::class))->getProperties(ReflectionProperty::IS_PUBLIC))
        ->toBe([], 'The registry and config repository must not be reachable through the inspection.');
});

it('projects to arrays a UI can render and encode', function (): void {
    $inspection = inspectionOver(inspectionFullCapability());

    $capability = $inspection->capabilities()[0]->toArray();
    $rules = $inspection->approvalRules()->toArray();

    expect($capability)->toHaveKeys(['name', 'ability', 'configuration_fingerprint', 'confirmation_required', 'rate_limit', 'consequential'])
        ->and($capability['rate_limit'])->toBeArray()
        ->and($capability['rate_limit'])->toHaveKeys(['name', 'limit', 'window_seconds'])
        ->and($rules)->toHaveKeys(['ttl_seconds', 'authorizer', 'strict_provenance', 'gate_ability'])
        ->and(json_encode($capability, JSON_THROW_ON_ERROR))->toBeString();
});

it('binds the shipped inspection as a singleton, to a contract a host may replace', function (): void {
    expect(app(ConfigurationInspection::class))->toBeInstanceOf(VerdictConfigurationInspection::class)
        ->and(app(ConfigurationInspection::class))->toBe(app(ConfigurationInspection::class));

    $replacement = new class implements ConfigurationInspection
    {
        public function capabilities(): array
        {
            return [];
        }

        public function rateLimits(): array
        {
            return [];
        }

        public function approvalRules(): ApprovalRules
        {
            return new ApprovalRules(null, null, false, 'approve-verdict-action');
        }
    };

    app()->instance(ConfigurationInspection::class, $replacement);

    expect(app(ConfigurationInspection::class))->toBe($replacement);
});
