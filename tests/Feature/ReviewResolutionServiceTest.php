<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ReviewDecisionAuthorizer;
use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewDecisionKind;
use Fissible\Verdict\Reviews\ReviewOutcome;
use Fissible\Verdict\Reviews\ReviewRequest;
use Fissible\Verdict\Testing\AllowAllReviewAuthorizer;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Reviews\ReviewResolutionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * VC-48, the decision half: approve and reject drive verdict#297's reviewed transition with the
 * actor key — and nothing else. No receipt is minted, no agent is resumed, no execution happens;
 * what the host does with a recorded review is application policy (ADR 0001 §3). Authority is the
 * lane's own Gate ability, fail-closed, and never the confirmation lane's.
 */
function resolutionRow(
    string $requestId,
    string $status = 'pending',
    string $expiresAt = '+1 hour',
    ?array $context = ['team' => 'payments'],
): string {
    $id = hash('sha256', $requestId);

    DB::table('verdict_review_requests')->insert([
        'id' => $id,
        'capability' => 'orders.refund',
        'binding_fingerprint' => hash('sha256', 'binding-'.$requestId),
        'status' => $status,
        'reason' => 'Refunds over the limit need review.',
        'expires_at' => now()->modify($expiresAt),
        // Issuance-faithful: a real transition always records who and when.
        'resolved_by' => $status === 'pending' ? null : 'reviewer-2',
        'resolved_at' => $status === 'pending' ? null : now()->subMinutes(2),
        'approval_context' => $context === null ? null : json_encode($context, JSON_THROW_ON_ERROR),
        'approver_summary' => json_encode([
            'content' => 'Refund #881 for customer X.',
            'fingerprint' => hash('sha256', 'Refund #881 for customer X.'),
        ], JSON_THROW_ON_ERROR),
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    return $id;
}

function reviewer(): GenericUser
{
    return new GenericUser(['id' => 'reviewer-1']);
}

beforeEach(function (): void {
    config()->set('verdict.reviews.store', DatabaseReviewRequestStore::class);
    config()->set('verdict.reviews.authorizer', AllowAllReviewAuthorizer::class);
    Gate::define('review-verdict-action', fn (): bool => true);
    config()->set('verdict-console.reviews.scope', ['team' => 'payments']);

    (require dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/create_verdict_review_requests_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/create_verdict_approval_receipts_table.php.stub')->up();
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_approval_receipts');
});

it('approves a pending review: the reviewed transition, the actor key, and nothing else', function (): void {
    $id = resolutionRow('resolve-approve');

    $outcome = app(ReviewResolutionService::class)->approve($id, reviewer());

    $row = DB::table('verdict_review_requests')->where('id', $id)->first();

    expect($outcome->succeeded())->toBeTrue()
        ->and($row->status)->toBe('approved')
        ->and($row->resolved_by)->toBe('reviewer-1')
        ->and($row->resolved_at)->not->toBeNull()
        // Record-only, asserted: approving a review must not mint authority for any execution.
        ->and(DB::table('verdict_approval_receipts')->count())->toBe(0);
});

it('rejects a pending review with the same shape and the opposite outcome', function (): void {
    $id = resolutionRow('resolve-reject');

    $outcome = app(ReviewResolutionService::class)->reject($id, reviewer());

    expect($outcome->succeeded())->toBeTrue()
        ->and(DB::table('verdict_review_requests')->where('id', $id)->value('status'))->toBe('rejected');
});

it('resumes no agent and touches no conversation, ever', function (): void {
    $recorded = new class
    {
        public array $calls = [];
    };
    app(ResumableAgents::class)->register(
        'review@v1',
        function () use ($recorded): never {
            $recorded->calls[] = 'resolved';
            throw new RuntimeException('The review lane must never reach the agent registry.');
        },
        fn (object $agent): bool => false,
    );

    $id = resolutionRow('resolve-no-resume');
    app(ReviewResolutionService::class)->approve($id, reviewer());

    expect($recorded->calls)->toBe([]);
});

it('refuses an anonymous reviewer before anything is looked up or transitioned', function (): void {
    $id = resolutionRow('resolve-anonymous');

    expect(fn () => app(ReviewResolutionService::class)->approve($id, null))
        ->toThrow(AuthorizationException::class, 'This reviewer may not resolve this review.');

    expect(DB::table('verdict_review_requests')->where('id', $id)->value('status'))->toBe('pending');
});

it('fails closed when the configured ability is genuinely undefined, and refuses a denied reviewer', function (): void {
    $undefined = resolutionRow('resolve-undefined-gate');

    // Genuinely absent: the configured ability is one nothing ever registered. Laravel Gates deny
    // unknown abilities, and the lane inherits that — never defaulting open because a host forgot
    // to wire authority.
    config()->set('verdict-console.reviews.gate', 'never-defined-review-ability');

    expect(fn () => app(ReviewResolutionService::class)->approve($undefined, reviewer()))
        ->toThrow(AuthorizationException::class, 'This reviewer may not resolve this review.');

    expect(DB::table('verdict_review_requests')->where('id', $undefined)->value('status'))->toBe('pending');

    // And a defined-but-denying ability refuses identically, leaving the row identically alone.
    config()->set('verdict-console.reviews.gate', 'review-verdict-action');
    Gate::define('review-verdict-action', fn (): bool => false);

    $denied = resolutionRow('resolve-denied');

    expect(fn () => app(ReviewResolutionService::class)->reject($denied, reviewer()))
        ->toThrow(AuthorizationException::class, 'This reviewer may not resolve this review.');

    expect(DB::table('verdict_review_requests')->where('id', $denied)->value('status'))->toBe('pending');
});

it('never accepts the confirmation lanes authority: an approver is not a reviewer', function (): void {
    $id = resolutionRow('resolve-wrong-lane');

    Gate::define('approve-verdict-action', fn (): bool => true);
    Gate::define('review-verdict-action', fn (): bool => false);

    expect(fn () => app(ReviewResolutionService::class)->approve($id, reviewer()))
        ->toThrow(AuthorizationException::class, 'This reviewer may not resolve this review.');
});

it('honours a configurable review ability name', function (): void {
    config()->set('verdict-console.reviews.gate', 'tenant-review-ability');
    Gate::define('tenant-review-ability', fn (): bool => true);

    $id = resolutionRow('resolve-configured-gate');

    expect(app(ReviewResolutionService::class)->approve($id, reviewer())->succeeded())->toBeTrue();
});

it('reports a lapsed or already-decided request as verdicts refusal outcome, transitioning nothing', function (): void {
    $lapsed = resolutionRow('resolve-lapsed', expiresAt: '-1 minute');
    $decided = resolutionRow('resolve-decided', status: 'approved');

    $lapsedOutcome = app(ReviewResolutionService::class)->approve($lapsed, reviewer());
    $decidedOutcome = app(ReviewResolutionService::class)->reject($decided, reviewer());

    // The manager's own vocabulary surfaces unaltered: an operator reads expired or
    // invalid_state, never a success and never a masked authorization failure.
    expect($lapsedOutcome->succeeded())->toBeFalse()
        ->and($lapsedOutcome->outcome)->toBe(ReviewOutcome::Expired)
        ->and($decidedOutcome->succeeded())->toBeFalse()
        ->and($decidedOutcome->outcome)->toBe(ReviewOutcome::InvalidState)
        ->and(DB::table('verdict_review_requests')->where('id', $lapsed)->value('status'))->toBe('pending')
        // The refused reject must leave the original decision untouched — including who made it.
        ->and(DB::table('verdict_review_requests')->where('id', $decided)->value('resolved_by'))->toBe('reviewer-2');
});

it('surfaces verdicts own authorizer denial: the gate is not the only refusal in the chain', function (): void {
    config()->set('verdict.reviews.authorizer', DenyAllReviewDecisionAuthorizer::class);

    $id = resolutionRow('resolve-upstream-denied');

    // A service driving the store's transition directly would approve here: only the manager
    // consults the review authorizer, and it refuses before the store is ever reached.
    $outcome = app(ReviewResolutionService::class)->approve($id, reviewer());

    expect($outcome->succeeded())->toBeFalse()
        ->and($outcome->outcome)->toBe(ReviewOutcome::Unauthorized)
        ->and(DB::table('verdict_review_requests')->where('id', $id)->value('status'))->toBe('pending')
        ->and(DB::table('verdict_review_requests')->where('id', $id)->value('resolved_by'))->toBeNull();
});

it('refuses to resolve a request outside the consoles review scope, without disclosing it exists', function (): void {
    $foreign = resolutionRow('resolve-foreign-scope', context: ['team' => 'fulfilment']);
    $contextless = resolutionRow('resolve-no-context', context: null);

    foreach ([$foreign, $contextless] as $id) {
        expect(fn () => app(ReviewResolutionService::class)->approve($id, reviewer()))
            ->toThrow(AuthorizationException::class, 'This reviewer may not resolve this review.');

        expect(DB::table('verdict_review_requests')->where('id', $id)->value('status'))->toBe('pending');
    }
});

final class DenyAllReviewDecisionAuthorizer implements ReviewDecisionAuthorizer
{
    public function authorize(ReviewRequest $request, ReviewDecisionKind $kind, string $decidedBy): bool
    {
        return false;
    }
}
