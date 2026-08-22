<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\GateApproverAuthority;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Contracts\ApproverAuthority;
use Fissible\VerdictConsole\Exceptions\ApproverNotIdentified;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate as Gateway;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

function approvalRow(): PendingApproval
{
    return new PendingApproval([
        'id' => 'row-1',
        'ingest_key' => str_repeat('a', 64),
        'receipt_id' => 'receipt-1',
        'tool_call_id' => 'call-1',
        'conversation_id' => 'conv-1',
        'resumability' => Resumability::Drivable->value,
    ]);
}

function approver(int|string $id = 7): Authenticatable
{
    return new GenericUser(['id' => $id]);
}

/**
 * The default that matters most: nothing is approvable until a host says who may approve.
 *
 * Laravel's Gate returns false for an ability nobody defined, so this is the shipped behaviour
 * rather than a check this package performs — but it is asserted here because it is the single
 * property that must never regress. An approval console whose out-of-the-box answer is "yes" is
 * worse than no console.
 */
it('denies every approver until the host defines the ability', function (): void {
    expect(app(ApproverAuthority::class)->allows(approvalRow(), approver()))->toBeFalse();
});

it('permits an approver the host gate allows', function (): void {
    Gate::define('approve-verdict-action', fn (Authenticatable $user): bool => true);

    expect(app(ApproverAuthority::class)->allows(approvalRow(), approver()))->toBeTrue();
});

it('refuses an approver the host gate denies', function (): void {
    Gate::define('approve-verdict-action', fn (Authenticatable $user): bool => false);

    expect(app(ApproverAuthority::class)->allows(approvalRow(), approver()))->toBeFalse();
});

/** The row is passed to the ability, so a host can decide per approval rather than per user. */
it('passes the approval to the gate so authority can be per-row', function (): void {
    Gate::define(
        'approve-verdict-action',
        fn (Authenticatable $user, PendingApproval $approval): bool => $approval->receipt_id === 'receipt-1',
    );

    $other = approvalRow();
    $other->receipt_id = 'receipt-2';

    expect(app(ApproverAuthority::class)->allows(approvalRow(), approver()))->toBeTrue()
        ->and(app(ApproverAuthority::class)->allows($other, approver()))->toBeFalse();
});

/**
 * An anonymous approval is the absence of the control, not a permissive case.
 *
 * **Tested against a permissive Gate double on purpose.** Laravel's real Gate already denies a null
 * user — verified — so asserting this through the framework would pass whether or not this package
 * guarded anything, which is a test that cannot fail for the reason it claims. Injecting a Gate that
 * says yes to everything makes the guard the only thing that can produce a denial, so this now
 * measures the package rather than the framework.
 *
 * The guard is kept rather than leaning on Laravel's behaviour because "nobody may approve
 * anonymously" is a property this package owes its users, not one it should inherit from an
 * undocumented framework detail that a future release could reasonably change.
 */
it('refuses an unauthenticated approver even when the gate would permit everything', function (): void {
    $permissive = Mockery::mock(Gateway::class);
    $permissive->shouldReceive('forUser')->andReturnSelf();
    $permissive->shouldReceive('allows')->andReturnTrue();

    $authority = new GateApproverAuthority($permissive, app('config'));

    expect($authority->allows(approvalRow(), approver()))->toBeTrue('The double must permit an identified approver.')
        ->and($authority->allows(approvalRow(), null))->toBeFalse('Only the null guard can deny here.');
});

/**
 * The actor key is the audit label Verdict records on the receipt. It must name somebody.
 *
 * Falling back to 'unknown' or '' would produce an evidence entry asserting that a consequential
 * action was approved while naming nobody — worse than a failed approval, because it looks complete.
 */
it('refuses to name an approver that does not exist', function (): void {
    expect(fn () => app(ApproverAuthority::class)->actorKeyFor(null))
        ->toThrow(ApproverNotIdentified::class);
});

it('refuses to name an approver whose identifier is empty', function (): void {
    expect(fn () => app(ApproverAuthority::class)->actorKeyFor(new GenericUser(['id' => ''])))
        ->toThrow(ApproverNotIdentified::class);
});

/** The bare identifier, not a ClassName:id convention this package would be inventing. */
it('records the authenticated identifier as the actor key', function (): void {
    expect(app(ApproverAuthority::class)->actorKeyFor(approver(7)))->toBe('7')
        ->and(app(ApproverAuthority::class)->actorKeyFor(approver('operator-a')))->toBe('operator-a');
});

it('reads the ability name from configuration', function (): void {
    config()->set('verdict-console.approvals.gate', 'approve-something-else');

    Gate::define('approve-something-else', fn (Authenticatable $user): bool => true);
    Gate::define('approve-verdict-action', fn (Authenticatable $user): bool => false);

    expect(app(ApproverAuthority::class)->allows(approvalRow(), approver()))->toBeTrue();
});

it('falls back to the documented ability when configuration is blank', function (): void {
    config()->set('verdict-console.approvals.gate', '');

    Gate::define('approve-verdict-action', fn (Authenticatable $user): bool => true);

    expect(app(ApproverAuthority::class)->allows(approvalRow(), approver()))->toBeTrue();
});

it('is resolvable from the container and replaceable by a host', function (): void {
    expect(app(ApproverAuthority::class))->toBeInstanceOf(GateApproverAuthority::class);

    app()->instance(ApproverAuthority::class, new class implements ApproverAuthority
    {
        public function allows(PendingApproval $approval, ?Authenticatable $approver): bool
        {
            return true;
        }

        public function actorKeyFor(?Authenticatable $approver): string
        {
            return 'host-defined';
        }
    });

    // No Gate is defined, so the shipped authority would deny — proof the host's replaced it.
    expect(app(ApproverAuthority::class)->allows(approvalRow(), null))->toBeTrue()
        ->and(app(ApproverAuthority::class)->actorKeyFor(null))->toBe('host-defined');
});
