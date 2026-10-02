<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Exceptions\UnresolvableAgentKey;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;

/*
 * #51: the agent-reconstruction version design §6.2 promises, with the decisions recorded on the
 * contract rather than guessed by a consumer:
 *
 * - WHOSE VALUE: host-supplied and opaque, like resolver keys and participant references — this
 *   package never derives, parses, or interprets one.
 * - WHERE IT HANGS: `ResumableAgents::versionFor(string $key): ?string`, beside `resolve()` and
 *   keyed the same way. The host must keep the reported token consistent with what `resolve()`
 *   would currently rebuild for that key: the token is a declaration about the factory, so it
 *   changes when (and only when) the host considers the reconstruction changed.
 * - OPTIONALITY: the method is required on the contract — every host acknowledges versioning even
 *   if only to decline it — and the VALUE is optional: null declares no version, and every pre-#51
 *   registration behaves exactly as before. Custom ResumableAgents implementations must add the
 *   method; in this 0.x line the minor is the breaking boundary (what the issue calls a "major
 *   bump"), recorded in the changelog. An optional companion interface was considered and
 *   rejected: it trades a one-method update for permanent instanceof capability-detection at
 *   every consumer.
 * - WHAT "CHANGED" MEANS: opaque string equality, per key, between host declarations — nothing
 *   more. Two non-null tokens for the SAME key are the same declared build when identical and a
 *   changed declaration when not; equality asserts agreement of declarations, never independently
 *   verified equivalence; inequality carries no ordering and no compatibility judgment. A null on
 *   either side means unknown: an unversioned rebuild can never be shown to be the same build,
 *   only never shown to be a different one.
 * - TOKEN SHAPE: any non-empty string, stored and returned verbatim — '0', padding, and
 *   whitespace-only tokens are all valid opaque values. Only the empty string is refused, as a
 *   null the caller did not mean.
 */

/** A real conversational agent shape, so versioned registrations exercise the full VC-2 surface. */
final class VersionedFixtureAgent implements Agent, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'versioned fixture';
    }
}

function fixtureFactory(): Closure
{
    return fn (): VersionedFixtureAgent => new VersionedFixtureAgent;
}

function neverMatches(): Closure
{
    return fn (Agent $agent): bool => false;
}

it('reports each key\'s own version: two versioned keys and an unversioned one stay distinct', function (): void {
    $registry = new AgentResolverRegistry;
    $registry->register('support@v7', fixtureFactory(), neverMatches(), version: 'deploy-2026-10-02');
    $registry->register('billing@v3', fixtureFactory(), neverMatches(), version: 'deploy-2026-09-14');
    $registry->register('legacy@v1', fixtureFactory(), neverMatches());

    expect($registry->versionFor('support@v7'))->toBe('deploy-2026-10-02')
        ->and($registry->versionFor('billing@v3'))->toBe('deploy-2026-09-14')
        ->and($registry->versionFor('legacy@v1'))->toBeNull();
});

it('refuses an unknown key even in a populated registry, the same way resolve() does', function (): void {
    $registry = new AgentResolverRegistry;
    $registry->register('support@v7', fixtureFactory(), neverMatches(), version: 'deploy-2026-10-02');

    expect(fn () => $registry->versionFor('never-registered'))
        ->toThrow(UnresolvableAgentKey::class);
});

it('reports null for a registration that declared no version, and for an explicit null', function (): void {
    $registry = new AgentResolverRegistry;
    $registry->register('omitted@v1', fixtureFactory(), neverMatches());
    $registry->register('explicit@v1', fixtureFactory(), neverMatches(), version: null);

    expect($registry->versionFor('omitted@v1'))->toBeNull()
        ->and($registry->versionFor('explicit@v1'))->toBeNull();
});

it('answers from the registration alone: reading a version runs no host factory or matcher', function (): void {
    $registry = new AgentResolverRegistry;
    $registry->register(
        'support@v7',
        fn (): object => throw new LogicException('versionFor must not rebuild the agent.'),
        fn (Agent $agent): bool => throw new LogicException('versionFor must not run matchers.'),
        version: 'deploy-2026-10-02',
    );

    expect($registry->versionFor('support@v7'))->toBe('deploy-2026-10-02');
});

it('treats the token as opaque: verbatim storage, and "0" and whitespace-only are valid tokens', function (string $token): void {
    $registry = new AgentResolverRegistry;
    $registry->register('support@v7', fixtureFactory(), neverMatches(), version: $token);

    expect($registry->versionFor('support@v7'))->toBe($token);
})->with([
    'padded' => ['  v2.0-RC1 '],
    'falsy string zero' => ['0'],
    'whitespace-only' => ['   '],
]);

it('refuses only the empty string, leaving the previous registration wholly untouched', function (): void {
    $registry = new AgentResolverRegistry;
    $original = new VersionedFixtureAgent;
    $registry->register('support@v7', fn (): VersionedFixtureAgent => $original, fn (Agent $a): bool => $a === $original, version: 'build-1');

    expect(fn () => $registry->register(
        'support@v7',
        fn (): object => throw new LogicException('The refused registration\'s factory must never be kept.'),
        fn (Agent $a): bool => throw new LogicException('The refused registration\'s matcher must never be kept.'),
        version: '',
    ))->toThrow(InvalidArgumentException::class);

    // Not just the token: the factory and matcher of the refused registration must not half-apply.
    expect($registry->versionFor('support@v7'))->toBe('build-1')
        ->and($registry->resolve('support@v7'))->toBe($original)
        ->and($registry->keyFor($original))->toBe('support@v7');
});

it('re-registration replaces the version each time, including back to null, without touching other keys', function (): void {
    $registry = new AgentResolverRegistry;
    $registry->register('support@v7', fixtureFactory(), neverMatches(), version: 'build-1');
    $registry->register('billing@v3', fixtureFactory(), neverMatches(), version: 'other-build');

    $registry->register('support@v7', fixtureFactory(), neverMatches(), version: 'build-2');
    expect($registry->versionFor('support@v7'))->toBe('build-2');

    $registry->register('support@v7', fixtureFactory(), neverMatches());
    expect($registry->versionFor('support@v7'))->toBeNull('The newest declaration wins, even when it declines to version.')
        ->and($registry->versionFor('billing@v3'))->toBe('other-build');
});

it('keeps the full VC-2 surface working for a versioned registration', function (): void {
    $registry = new AgentResolverRegistry;
    $agent = new VersionedFixtureAgent;
    $registry->register('fixture@v1', fn (): VersionedFixtureAgent => new VersionedFixtureAgent, fn (Agent $a): bool => $a instanceof VersionedFixtureAgent, version: 'build-9');

    expect($registry->keyFor($agent))->toBe('fixture@v1')
        ->and($registry->resolve('fixture@v1'))->toBeInstanceOf(VersionedFixtureAgent::class)
        ->and(iterator_to_array($registry->keys()))->toContain('fixture@v1')
        ->and($registry->versionFor('fixture@v1'))->toBe('build-9');
});

it('is part of the ResumableAgents contract, keyed like resolve()', function (): void {
    $method = new ReflectionMethod(ResumableAgents::class, 'versionFor');
    $parameter = $method->getParameters()[0];

    expect((string) $method->getReturnType())->toBe('?string')
        ->and($method->getNumberOfParameters())->toBe(1)
        ->and((string) $parameter->getType())->toBe('string')
        ->and($parameter->isOptional())->toBeFalse();
});
