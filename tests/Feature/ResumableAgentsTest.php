<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Exceptions\ResumableAgentFailure;
use Fissible\VerdictConsole\Exceptions\UnkeyableAgent;
use Fissible\VerdictConsole\Exceptions\UnresolvableAgentKey;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;

/** An agent with runtime constructor input — the case class-plus-participant cannot rebuild. */
final class TenantedOrdersAgent implements Agent, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function __construct(public readonly string $tenant, public readonly string $model) {}

    public function instructions(): Stringable|string
    {
        return 'Handle orders for tenant '.$this->tenant.'.';
    }
}

/** Conversational, so it could pause — but nothing registers a key for it. */
final class UnregisteredAgent implements Agent, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'nobody registered me';
    }
}

/** Not conversational: a resolver returning this has produced something unusable. */
final class NotConversationalAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'no conversation';
    }
}

function ordersRegistry(string $tenant = 'acme'): AgentResolverRegistry
{
    return (new AgentResolverRegistry)->register(
        'orders@v1',
        fn (): TenantedOrdersAgent => new TenantedOrdersAgent($tenant, 'model-v1'),
        fn (Agent $agent): bool => $agent instanceof TenantedOrdersAgent,
    );
}

it('round-trips an agent through its key', function (): void {
    $registry = ordersRegistry();
    $paused = new TenantedOrdersAgent('acme', 'model-v1');

    $rebuilt = $registry->resolve($registry->keyFor($paused));

    // NOTE: this is a fixture-level proxy, not proof of equivalence. Two agent instances have no
    // defined equality, and comparing a few properties would pass for an agent whose constructor
    // took something the key dropped. Equivalence is host-defined by contract; what this asserts is
    // that the shipped registry returns the thing it was told to, on a fixture simple enough for
    // that to be checkable.
    expect($rebuilt)->toBeInstanceOf(TenantedOrdersAgent::class)
        ->and($rebuilt->tenant)->toBe('acme')
        ->and($rebuilt->model)->toBe('model-v1')
        ->and($rebuilt)->toBeInstanceOf(RemembersConversationsContract::class);
});

it('returns the agent bare, with no conversation attached', function (): void {
    $registry = ordersRegistry();

    // Attaching is resumption's business: the caller attaches the conversation captured at pause
    // time. A registry that pre-attached would have to guess which conversation, and guessing is
    // exactly the defect fissible/verdict#265 records.
    expect($registry->resolve('orders@v1')->currentConversation())->toBeNull();
});

/**
 * The property that makes a key durable rather than merely unique: it survives the process.
 *
 * A key derived from `spl_object_id()` or a random seed passes every same-process assertion and
 * fails only for runs that outlive the process that paused them — which is every run this package
 * exists to resume.
 */
it('derives the same key from a rebuilt registry in a fresh container', function (): void {
    $keyAtPause = ordersRegistry()->keyFor(new TenantedOrdersAgent('acme', 'model-v1'));

    // Stand in for a later request: nothing survives but the string.
    $carried = json_decode(json_encode(['key' => $keyAtPause], JSON_THROW_ON_ERROR), true)['key'];

    $this->refreshApplication();

    expect(ordersRegistry()->keyFor(new TenantedOrdersAgent('acme', 'model-v1')))->toBe($carried)
        ->and(ordersRegistry()->resolve($carried))->toBeInstanceOf(TenantedOrdersAgent::class);
});

it('refuses to key an agent nothing is registered for', function (): void {
    expect(fn () => ordersRegistry()->keyFor(new UnregisteredAgent))
        ->toThrow(UnkeyableAgent::class);

    // Rather than returning a placeholder that stores cleanly and fails only at resume.
    expect(fn () => ordersRegistry()->keyFor(new UnregisteredAgent))
        ->toThrow(ResumableAgentFailure::class, 'No resumable-agent key is registered');
});

/** Registration order is not a property a host can reason about months later. */
it('refuses to key an agent that two keys both claim', function (): void {
    $registry = ordersRegistry()->register(
        'orders@shadow',
        fn (): TenantedOrdersAgent => new TenantedOrdersAgent('acme', 'model-v1'),
        fn (Agent $agent): bool => $agent instanceof TenantedOrdersAgent,
    );

    expect(fn () => $registry->keyFor(new TenantedOrdersAgent('acme', 'model-v1')))
        ->toThrow(UnkeyableAgent::class, 'matches more than one');
});

it('raises a catchable failure for an unknown key', function (): void {
    expect(fn () => ordersRegistry()->resolve('orders@absent'))
        ->toThrow(UnresolvableAgentKey::class)
        ->and(fn () => ordersRegistry()->resolve('orders@absent'))
        ->toThrow(ResumableAgentFailure::class);
});

/** "The resolver threw" without the cause is the least actionable possible incident. */
it('preserves the original throwable when a registered resolver fails', function (): void {
    $cause = new LogicException('the tenant connection is gone');

    $registry = (new AgentResolverRegistry)->register(
        'orders@broken',
        fn (): object => throw $cause,
        fn (Agent $agent): bool => true,
    );

    try {
        $registry->resolve('orders@broken');
        $this->fail('A failing resolver must not resolve.');
    } catch (UnresolvableAgentKey $e) {
        expect($e->getPrevious())->toBe($cause)
            ->and($e->getMessage())->toContain('the tenant connection is gone');
    }
});

it('rejects a resolver that produces a non-conversational agent', function (): void {
    $registry = (new AgentResolverRegistry)->register(
        'orders@wrong-shape',
        fn (): NotConversationalAgent => new NotConversationalAgent,
        fn (Agent $agent): bool => true,
    );

    expect(fn () => $registry->resolve('orders@wrong-shape'))
        ->toThrow(UnresolvableAgentKey::class, 'not a conversational agent');
});

it('rejects an empty key at registration rather than at resume', function (): void {
    expect(fn () => (new AgentResolverRegistry)->register('  ', fn (): object => new UnregisteredAgent, fn (): bool => true))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * Enumeration exists so startup preflight is expressible through the interface, rather than by
 * reaching into whatever registry a host happens to use.
 */
it('enumerates its keys so a preflight can resolve every one', function (): void {
    $registry = ordersRegistry()->register(
        'orders@v2',
        fn (): TenantedOrdersAgent => new TenantedOrdersAgent('acme', 'model-v2'),
        fn (Agent $agent): bool => false,
    );

    expect(iterator_to_array((function () use ($registry): Generator {
        yield from $registry->keys();
    })()))->toBe(['orders@v1', 'orders@v2']);

    // The preflight VC-3 will own, in the two lines it reduces to here.
    foreach ($registry->keys() as $key) {
        expect($registry->resolve($key))->toBeInstanceOf(Agent::class);
    }
});

it('lets a preflight over keys() catch a resolver that would fail at resume', function (): void {
    $registry = ordersRegistry()->register(
        'orders@broken',
        fn (): object => throw new RuntimeException('boom'),
        fn (Agent $agent): bool => false,
    );

    $broken = [];

    foreach ($registry->keys() as $key) {
        try {
            $registry->resolve($key);
        } catch (ResumableAgentFailure) {
            $broken[] = $key;
        }
    }

    expect($broken)->toBe(['orders@broken']);
});

/**
 * Versioned keys, which is how a deploy migrates agents without stranding rows that already hold the
 * old key. New pauses key to v2; rows still holding v1 resolve for as long as v1 stays registered.
 *
 * When v1 may be *retired* is a different question — it depends on whether any live row still
 * references it — and deliberately not this contract's business. It has no dependency on the
 * console's tables, so it cannot answer it and must not pretend to.
 */
it('resolves a retired-generation key while new pauses key to the current one', function (): void {
    $registry = (new AgentResolverRegistry)
        ->register(
            'orders@v1',
            fn (): TenantedOrdersAgent => new TenantedOrdersAgent('acme', 'model-v1'),
            // v1 no longer claims new agents: the deploy moved on.
            fn (Agent $agent): bool => false,
        )
        ->register(
            'orders@v2',
            fn (): TenantedOrdersAgent => new TenantedOrdersAgent('acme', 'model-v2'),
            fn (Agent $agent): bool => $agent instanceof TenantedOrdersAgent,
        );

    expect($registry->keyFor(new TenantedOrdersAgent('acme', 'model-v2')))->toBe('orders@v2')
        ->and($registry->resolve('orders@v1')->model)->toBe('model-v1', 'A row paused before the migration must still resume.')
        ->and($registry->resolve('orders@v2')->model)->toBe('model-v2');
});

it('is resolvable from the container as the contract', function (): void {
    expect(app(ResumableAgents::class))->toBeInstanceOf(AgentResolverRegistry::class)
        ->and(app(ResumableAgents::class))->toBe(app(ResumableAgents::class), 'Registrations must survive resolution.');
});
