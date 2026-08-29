<?php

declare(strict_types=1);

use DateTimeImmutable as ClaimTime;
use Fissible\Verdict\Contracts\Clock;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimManager;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimOutcome;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimResolution;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimTransition;
use Fissible\VerdictConsole\Contracts\ExecutionClaimAuthority;
use Fissible\VerdictConsole\Exceptions\ExecutionClaimNotFound;
use Fissible\VerdictConsole\Exceptions\ExecutionClaimResolutionFailed;
use Fissible\VerdictConsole\Exceptions\ExecutionClaimStillActive;
use Fissible\VerdictConsole\ExecutionClaims\ExecutionClaimItem;
use Fissible\VerdictConsole\ExecutionClaims\ExecutionClaimService;
use Fissible\VerdictConsole\ExecutionClaims\GateExecutionClaimAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Claims are created through Verdict's own store, exactly as an executor would leave them, so the
 * rows under test are the rows an operator would meet. The console adds no claim state of its own.
 */
beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/create_verdict_execution_claims_table.php.stub')->up();
});

function claimRow(string $id, string $capability = 'orders.refund', ExecutionClaimStatus $status = ExecutionClaimStatus::Claimed): ExecutionClaim
{
    $now = new ClaimTime('2026-08-29 12:00:00', new DateTimeZone('UTC'));

    return new ExecutionClaim(
        id: $id,
        capability: $capability,
        policy: 'refund-once',
        bindingFingerprint: hash('sha256', $id.'-binding'),
        status: $status,
        attemptCount: 1,
        claimedAt: $now,
        completedAt: null,
        indeterminateAt: null,
        releasedAt: null,
        resolvedBy: null,
        resolutionReason: null,
        createdAt: $now,
        updatedAt: $now,
    );
}

/** An admitted claim whose executor then threw: the one state that genuinely needs a person. */
function indeterminateClaim(string $id, string $capability = 'orders.refund'): ExecutionClaim
{
    $store = app(ExecutionClaimStore::class);
    $store->claim(claimRow($id, $capability));
    $transition = $store->markIndeterminate($id, new ClaimTime('2026-08-29 12:01:00', new DateTimeZone('UTC')));

    expect($transition->outcome)->toBe(ExecutionClaimOutcome::Indeterminate, 'Fixture: Verdict must have marked the claim indeterminate.');

    return $transition->claim;
}

/** An admitted claim still marked active — possibly executing right now. */
function activeClaim(string $id, string $capability = 'orders.refund'): ExecutionClaim
{
    $transition = app(ExecutionClaimStore::class)->claim(claimRow($id, $capability));

    expect($transition->outcome)->toBe(ExecutionClaimOutcome::Claimed, 'Fixture: Verdict must have admitted the claim.');

    return $transition->claim;
}

/** A claim the executor finished: the transition Verdict itself takes, not a terminal-shaped row. */
function completedClaim(string $id): void
{
    activeClaim($id);
    $transition = app(ExecutionClaimStore::class)->complete($id, new ClaimTime('2026-08-29 12:02:00', new DateTimeZone('UTC')));

    expect($transition->outcome)->toBe(ExecutionClaimOutcome::Completed, 'Fixture: Verdict must have completed the claim.');
}

/** A claim an operator already released for retry: resolved, so no longer anyone's to reconcile. */
function releasedClaim(string $id): void
{
    indeterminateClaim($id);
    $transition = app(ExecutionClaimStore::class)->resolve($id, ExecutionClaimResolution::Retryable, 'earlier-operator', 'Released earlier.', new ClaimTime('2026-08-29 12:03:00', new DateTimeZone('UTC')));

    expect($transition->outcome)->toBe(ExecutionClaimOutcome::Released, 'Fixture: Verdict must have released the claim.');
}

/**
 * Wraps the real store and records every resolve() it is asked for, so a test can prove the service
 * reached Verdict's transition rather than updating the table itself and describing the result.
 */
final class RecordingExecutionClaimStore implements ExecutionClaimStore
{
    /** @var list<array{claimId: string, resolution: ExecutionClaimResolution, resolvedBy: string, reason: string, at: ClaimTime}> */
    public array $resolutions = [];

    public ?ExecutionClaimTransition $lastTransition = null;

    public function __construct(private readonly ExecutionClaimStore $inner) {}

    public function claim(ExecutionClaim $claim): ExecutionClaimTransition
    {
        return $this->inner->claim($claim);
    }

    public function complete(string $claimId, ClaimTime $at): ExecutionClaimTransition
    {
        return $this->inner->complete($claimId, $at);
    }

    public function markIndeterminate(string $claimId, ClaimTime $at): ExecutionClaimTransition
    {
        return $this->inner->markIndeterminate($claimId, $at);
    }

    public function resolve(string $claimId, ExecutionClaimResolution $resolution, string $resolvedBy, string $reason, ClaimTime $at): ExecutionClaimTransition
    {
        $this->resolutions[] = ['claimId' => $claimId, 'resolution' => $resolution, 'resolvedBy' => $resolvedBy, 'reason' => $reason, 'at' => $at];

        return $this->lastTransition = $this->inner->resolve($claimId, $resolution, $resolvedBy, $reason, $at);
    }

    public function find(string $claimId): ?ExecutionClaim
    {
        return $this->inner->find($claimId);
    }

    /** @return list<ExecutionClaim> */
    public function unresolved(): array
    {
        return $this->inner->unresolved();
    }
}

/**
 * The instant a private clock hands to one manager instance. That clock is never bound in the
 * container, so nothing but a call through *this* `ExecutionClaimManager::resolve()` can reach the
 * store carrying it: a service that resolved Verdict's Clock itself and called the store directly
 * would stamp the system time instead. The store spy records what arrived.
 */
const RESOLVED_AT = '2026-08-29 15:30:00';

function recordingStore(): RecordingExecutionClaimStore
{
    $store = new RecordingExecutionClaimStore(app(ExecutionClaimStore::class));
    $privateClock = new class implements Clock
    {
        public function now(): ClaimTime
        {
            return new ClaimTime(RESOLVED_AT, new DateTimeZone('UTC'));
        }
    };

    app()->instance(ExecutionClaimStore::class, $store);
    app()->instance(ExecutionClaimManager::class, new ExecutionClaimManager($store, $privateClock));

    return $store;
}

function operator(string $id = 'operator-1'): Authenticatable
{
    return new GenericUser(['id' => $id]);
}

function allowEveryone(): void
{
    Gate::define('resolve-verdict-execution-claim', fn (Authenticatable $user, ExecutionClaimItem $claim): bool => true);
}

/** @return array<string, mixed>|null */
function storedClaim(string $id): ?array
{
    $row = DB::table('verdict_execution_claims')->where('id', $id)->first();

    return $row === null ? null : (array) $row;
}

it('lists unresolved claims with the fingerprint evidence records them under', function (): void {
    indeterminateClaim('claim-indeterminate');
    activeClaim('claim-active', 'billing.charge');
    completedClaim('claim-completed');
    releasedClaim('claim-released');

    $items = app(ExecutionClaimService::class)->unresolved();

    expect($items)->toHaveCount(2)
        ->and($items)->each->toBeInstanceOf(ExecutionClaimItem::class)
        ->and(array_map(fn (ExecutionClaimItem $i): string => $i->id, $items))->toEqualCanonicalizing(['claim-indeterminate', 'claim-active']);

    $indeterminate = current(array_filter($items, fn (ExecutionClaimItem $i): bool => $i->id === 'claim-indeterminate'));

    // Evidence keeps only hash('sha256', id) as execution_claim_fingerprint (design §6.2); the item
    // carries that value so an operator can join a claim to the decision rows it produced.
    expect($indeterminate)->toMatchArray([
        'id' => 'claim-indeterminate',
        'fingerprint' => hash('sha256', 'claim-indeterminate'),
        'capability' => 'orders.refund',
        'policy' => 'refund-once',
        'bindingFingerprint' => hash('sha256', 'claim-indeterminate-binding'),
        'status' => 'indeterminate',
        'attemptCount' => 1,
    ])
        ->and($indeterminate->indeterminateAt)->toBeInstanceOf(ClaimTime::class)
        ->and($indeterminate->claimedAt)->toBeInstanceOf(ClaimTime::class);
});

it('projects to arrays a UI can render', function (): void {
    indeterminateClaim('claim-1');

    $array = app(ExecutionClaimService::class)->unresolved()[0]->toArray();

    expect($array)->toHaveKeys(['id', 'fingerprint', 'capability', 'policy', 'binding_fingerprint', 'status', 'attempt_count', 'claimed_at', 'indeterminate_at', 'updated_at'])
        ->and(json_encode($array, JSON_THROW_ON_ERROR))->toBeString();
});

/**
 * The success path asserts Verdict's *returned* transition, never the caller's intent: the outcome
 * decides what happened, and the claim row is what the audit reads later.
 */
it('resolves an indeterminate claim as completed through Verdicts own transition, under the authoritys actor key', function (): void {
    allowEveryone();
    indeterminateClaim('claim-1');
    $store = recordingStore();

    $transition = app(ExecutionClaimService::class)->resolve('claim-1', ExecutionClaimResolution::Completed, operator('operator-7'), 'Confirmed in the payment gateway: refund 8f2a settled.');

    // The resolution reached Verdict's store with the console's arguments and the manager's clock
    // instant — never a description the console composed after touching the table itself.
    expect($store->resolutions)->toHaveCount(1)
        ->and($store->resolutions[0])->toMatchArray([
            'claimId' => 'claim-1',
            'resolution' => ExecutionClaimResolution::Completed,
            'resolvedBy' => 'operator-7',
            'reason' => 'Confirmed in the payment gateway: refund 8f2a settled.',
        ])
        ->and($store->resolutions[0]['at']->format('Y-m-d H:i:s'))->toBe(RESOLVED_AT, 'Only ExecutionClaimManager::resolve() stamps Verdict\'s clock; the store was not called directly.')
        ->and($transition->outcome)->toBe(ExecutionClaimOutcome::Completed)
        ->and(storedClaim('claim-1'))->toMatchArray([
            'status' => 'completed',
            'resolved_by' => 'operator-7',
            'resolution_reason' => 'Confirmed in the payment gateway: refund 8f2a settled.',
        ])
        ->and(app(ExecutionClaimService::class)->unresolved())->toBe([]);
});

/**
 * The host's authority is the one consulted — for both answers it gives. A tenant-scoped authority
 * that denies is obeyed even while a permissive Gate would have allowed, and the actor recorded on
 * the row is the authority's label, never the authenticated id taken directly.
 */
it('obeys a replaced authority for both its denial and its actor key', function (): void {
    allowEveryone();
    app()->instance(ExecutionClaimAuthority::class, new class implements ExecutionClaimAuthority
    {
        public function allows(ExecutionClaimItem $claim, ?Authenticatable $operator): bool
        {
            // A tenant boundary the Gate knows nothing about.
            return $operator !== null && str_starts_with($claim->capability, 'orders.');
        }

        public function actorKeyFor(?Authenticatable $operator): string
        {
            return 'ops-team:'.$operator?->getAuthIdentifier();
        }
    });
    indeterminateClaim('claim-orders', 'orders.refund');
    indeterminateClaim('claim-billing', 'billing.charge');
    $service = app(ExecutionClaimService::class);

    expect(fn (): mixed => $service->resolve('claim-billing', ExecutionClaimResolution::Completed, operator('operator-7'), 'Checked.'))
        ->toThrow(AuthorizationException::class)
        ->and(storedClaim('claim-billing'))->toMatchArray(['status' => 'indeterminate', 'resolved_by' => null]);

    $service->resolve('claim-orders', ExecutionClaimResolution::Completed, operator('operator-7'), 'Checked.');

    expect(storedClaim('claim-orders')['resolved_by'])->toBe('ops-team:operator-7');
});

it('releases an indeterminate claim for one explicit retry', function (): void {
    allowEveryone();
    indeterminateClaim('claim-1');

    $transition = app(ExecutionClaimService::class)->resolve('claim-1', ExecutionClaimResolution::Retryable, operator(), 'Gateway shows no charge; safe to retry.');

    expect($transition->outcome)->toBe(ExecutionClaimOutcome::Released)
        ->and(storedClaim('claim-1')['status'])->toBe('released');
});

/**
 * The shipped authority denies until the host defines the ability, and an anonymous operator is
 * refused before any Gate is consulted — the same fail-closed shape as approvals. Neither attempt
 * may reach Verdict: the row stays exactly as the executor left it.
 */
it('refuses to resolve while no Gate ability is defined', function (): void {
    indeterminateClaim('claim-1');

    expect(fn (): mixed => app(ExecutionClaimService::class)->resolve('claim-1', ExecutionClaimResolution::Completed, operator(), 'Checked.'))
        ->toThrow(AuthorizationException::class)
        ->and(storedClaim('claim-1'))->toMatchArray(['status' => 'indeterminate', 'resolved_by' => null, 'resolution_reason' => null]);
});

/**
 * An anonymous operator is refused before the Gate is consulted. The Gate here admits guests — a
 * nullable user parameter is how Laravel lets it — so only a guard in front of it can produce this
 * refusal, and it holds even when the claim does not exist: nobody unauthenticated learns whether
 * an id is real.
 */
it('refuses an anonymous operator before the Gate, even for an unknown claim', function (): void {
    Gate::define('resolve-verdict-execution-claim', fn (?Authenticatable $user, ExecutionClaimItem $claim): bool => true);
    indeterminateClaim('claim-1');
    $service = app(ExecutionClaimService::class);

    expect(fn (): mixed => $service->resolve('claim-1', ExecutionClaimResolution::Completed, null, 'Checked.'))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): mixed => $service->resolve('no-such-claim', ExecutionClaimResolution::Completed, null, 'Checked.'))
        ->toThrow(AuthorizationException::class)
        ->and(storedClaim('claim-1'))->toMatchArray(['status' => 'indeterminate', 'resolved_by' => null]);
});

/** The Gate receives the item, so a host policy can scope by capability, tenant, or ownership. */
it('lets the host Gate scope which claims an operator may resolve', function (): void {
    Gate::define('resolve-verdict-execution-claim', fn (Authenticatable $user, ExecutionClaimItem $claim): bool => str_starts_with($claim->capability, 'orders.'));
    indeterminateClaim('claim-orders', 'orders.refund');
    indeterminateClaim('claim-billing', 'billing.charge');
    $service = app(ExecutionClaimService::class);

    expect(fn (): mixed => $service->resolve('claim-billing', ExecutionClaimResolution::Completed, operator(), 'Checked.'))
        ->toThrow(AuthorizationException::class)
        ->and($service->resolve('claim-orders', ExecutionClaimResolution::Completed, operator(), 'Checked.')->outcome)->toBe(ExecutionClaimOutcome::Completed)
        ->and(storedClaim('claim-billing')['status'])->toBe('indeterminate');
});

/** A reconciliation without a reason is not a reconciliation; refused here, before Verdict sees it. */
it('refuses a blank reconciliation reason', function (string $reason): void {
    allowEveryone();
    indeterminateClaim('claim-1');

    expect(fn (): mixed => app(ExecutionClaimService::class)->resolve('claim-1', ExecutionClaimResolution::Completed, operator(), $reason))
        ->toThrow(InvalidArgumentException::class, 'reason')
        ->and(storedClaim('claim-1')['status'])->toBe('indeterminate');
})->with(['empty' => '', 'whitespace' => "  \n\t"]);

/**
 * A claim still marked active may be executing at this moment. Resolving it is sometimes right — a
 * worker died without marking anything — but never by default, so the console mirrors Verdict's own
 * command: an explicit force, or a refusal that names the state.
 */
it('refuses a still-active claim unless the operator forces it', function (): void {
    allowEveryone();
    activeClaim('claim-1');
    $service = app(ExecutionClaimService::class);

    expect(fn (): mixed => $service->resolve('claim-1', ExecutionClaimResolution::Retryable, operator(), 'Worker died mid-flight.'))
        ->toThrow(ExecutionClaimStillActive::class)
        ->and(storedClaim('claim-1')['status'])->toBe('claimed')
        ->and($service->resolve('claim-1', ExecutionClaimResolution::Retryable, operator(), 'Worker died mid-flight.', force: true)->outcome)->toBe(ExecutionClaimOutcome::Released);
});

/** Claim ids are 64-character random values; naming an unknown one to an authenticated operator discloses nothing enumerable. */
it('names an unknown claim to an authenticated operator rather than resolving nothing', function (): void {
    allowEveryone();

    expect(fn (): mixed => app(ExecutionClaimService::class)->resolve('no-such-claim', ExecutionClaimResolution::Completed, operator(), 'Checked.'))
        ->toThrow(ExecutionClaimNotFound::class, 'no-such-claim');
});

/**
 * Verdict answers a resolve on an already-resolved claim with an outcome, not an exception. The
 * console must not report that as success: the transition's outcome is the fact, and a resolve that
 * did not complete or release is a failure carrying the outcome Verdict gave.
 */
it('reports a transition that neither completed nor released as a failure carrying Verdicts outcome', function (): void {
    allowEveryone();
    indeterminateClaim('claim-1');
    $service = app(ExecutionClaimService::class);
    $service->resolve('claim-1', ExecutionClaimResolution::Completed, operator(), 'First reconciliation.');

    try {
        $service->resolve('claim-1', ExecutionClaimResolution::Retryable, operator(), 'Second reconciliation.');
        $this->fail('A second resolution of a completed claim must not report success.');
    } catch (ExecutionClaimResolutionFailed $e) {
        expect($e->outcome)->toBe(ExecutionClaimOutcome::InvalidState)
            ->and($e->getMessage())->toContain('claim-1');
    }

    expect(storedClaim('claim-1'))->toMatchArray(['status' => 'completed', 'resolution_reason' => 'First reconciliation.']);
});

it('binds a fail-closed Gate authority the host may replace, on its own configured ability', function (): void {
    expect(app(ExecutionClaimAuthority::class))->toBeInstanceOf(GateExecutionClaimAuthority::class)
        ->and(config('verdict-console.execution_claims.gate'))->toBe('resolve-verdict-execution-claim');

    config()->set('verdict-console.execution_claims.gate', 'reconcile-claims');
    Gate::define('reconcile-claims', fn (Authenticatable $user, ExecutionClaimItem $claim): bool => true);
    indeterminateClaim('claim-1');

    expect(app(ExecutionClaimService::class)->resolve('claim-1', ExecutionClaimResolution::Completed, operator(), 'Checked.')->outcome)
        ->toBe(ExecutionClaimOutcome::Completed);
});
