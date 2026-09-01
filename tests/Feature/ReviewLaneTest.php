<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ReviewStatusReader;
use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Reviews\ReviewStatus;
use Fissible\Verdict\Reviews\ReviewStatusView;
use Fissible\Verdict\Testing\AllowAllReviewAuthorizer;
use Fissible\VerdictConsole\Exceptions\ReviewSurfaceContractViolation;
use Fissible\VerdictConsole\Reviews\ReviewItem;
use Fissible\VerdictConsole\Reviews\ReviewItemState;
use Fissible\VerdictConsole\Reviews\ReviewQueue;
use Fissible\VerdictConsole\Reviews\ReviewQueueState;
use Fissible\VerdictConsole\Reviews\ReviewSurfaceContract;
use Fissible\VerdictConsole\Reviews\ReviewVerb;
use Fissible\VerdictConsole\Reviews\ReviewVerbs;
use Illuminate\Support\Facades\DB;

/**
 * VC-48, the read half: the asynchronous review lane rendered from verdict#297's shipped substrate
 * through the #298 reader. Nothing pauses and nothing resumes — the queue is a scoped read of
 * durable review requests, honest about the three ways it can have nothing to show: the lane is
 * not configured, the console has no review scope, or the scope simply holds no pending request.
 *
 * Recorded decline: ADR 0026's four-state provenance rendering cannot be built on this lane yet —
 * the shipped ReviewStatusView exposes reason and summary fingerprint but no provenance at all.
 * That is an upstream read-contract question, not something to query around the reader for.
 */
function reviewRow(
    string $requestId,
    string $capability = 'orders.refund',
    string $status = 'pending',
    string $expiresAt = '+1 hour',
    ?array $context = ['team' => 'payments'],
    ?string $reason = 'Refunds over the limit need review.',
    ?string $resolvedBy = null,
): string {
    $id = hash('sha256', $requestId);

    DB::table('verdict_review_requests')->insert([
        'id' => $id,
        'capability' => $capability,
        'binding_fingerprint' => hash('sha256', 'binding-'.$requestId),
        'status' => $status,
        'reason' => $reason,
        'expires_at' => now()->modify($expiresAt),
        'resolved_by' => $resolvedBy,
        'resolved_at' => $resolvedBy === null ? null : now()->subMinutes(2),
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

beforeEach(function (): void {
    config()->set('verdict.reviews.store', DatabaseReviewRequestStore::class);
    config()->set('verdict.reviews.authorizer', AllowAllReviewAuthorizer::class);
    config()->set('verdict-console.reviews.scope', ['team' => 'payments']);

    (require dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/create_verdict_review_requests_table.php.stub')->up();
});

// --- queue states ---------------------------------------------------------------------------------

it('reports the lane unconfigured when verdict has no review store, without touching any table', function (): void {
    config()->set('verdict.reviews.store', null);

    $result = app(ReviewQueue::class)->items();

    expect($result->state)->toBe(ReviewQueueState::Unconfigured)
        ->and($result->items)->toBe([]);
});

it('reports the queue unscoped when the console has no review scope, refusing to enumerate', function (): void {
    reviewRow('request-visible');
    config()->set('verdict-console.reviews.scope', []);

    // The reader refuses unscoped enumeration by design (ADR 0035 §4); the console must surface
    // that refusal as its own honest state, never widen it into enumerate-everything.
    $result = app(ReviewQueue::class)->items();

    expect($result->state)->toBe(ReviewQueueState::Unscoped)
        ->and($result->items)->toBe([]);
});

it('lists pending requests inside the scope, oldest first, and nothing outside it', function (): void {
    $inside = reviewRow('request-inside');
    $insideNewer = reviewRow('request-inside-newer');
    DB::table('verdict_review_requests')->where('id', $insideNewer)->update(['created_at' => now()->subMinutes(5)]);
    $richerContext = reviewRow('request-richer-context', context: ['team' => 'payments', 'region' => 'eu']);
    DB::table('verdict_review_requests')->where('id', $richerContext)->update(['created_at' => now()->subMinutes(2)]);
    reviewRow('request-other-team', context: ['team' => 'fulfilment']);
    reviewRow('request-no-context', context: null);
    reviewRow('request-decided', status: 'approved', resolvedBy: 'reviewer-2');

    // Containment is scope-subset-of-context: a request carrying more context than the scope asks
    // about still belongs to it (ADR 0035 §4's typed containment, proven through the console).
    $result = app(ReviewQueue::class)->items();

    expect($result->state)->toBe(ReviewQueueState::Ready)
        ->and(array_map(fn (ReviewItem $item): string => $item->requestId, $result->items))->toBe([$inside, $insideNewer, $richerContext]);
});

it('keeps the scope typed end to end: an integer never matches its string twin', function (): void {
    config()->set('verdict-console.reviews.scope', ['tenant' => 1]);
    $typed = reviewRow('request-typed-int', context: ['tenant' => 1]);
    reviewRow('request-string-twin', context: ['tenant' => '1']);

    $result = app(ReviewQueue::class)->items();

    expect(array_map(fn (ReviewItem $item): string => $item->requestId, $result->items))->toBe([$typed]);
});

it('breaks a created-at tie by request id, so two same-second requests cannot swap between polls', function (): void {
    $tieA = reviewRow('request-tie-a');
    $tieB = reviewRow('request-tie-b');
    $tied = now()->subMinutes(3)->startOfSecond();
    DB::table('verdict_review_requests')->whereIn('id', [$tieA, $tieB])->update(['created_at' => $tied]);

    [$first, $second] = strcmp($tieA, $tieB) < 0 ? [$tieA, $tieB] : [$tieB, $tieA];

    expect(array_map(fn (ReviewItem $item): string => $item->requestId, app(ReviewQueue::class)->items()->items))
        ->toBe([$first, $second]);
});

/**
 * The escape a real deterministic store cannot expose: a queue could read the table itself and,
 * against this database, agree with the reader on every test above. Here the bound reader answers
 * with an order and a member the table contradicts — what renders is what the reader said, asked
 * with exactly the configured scope, or this test fails.
 */
it('renders the readers answer in the readers order, asked with the configured scope', function (): void {
    reviewRow('request-in-table-only');

    $viewFor = function (string $requestId, string $createdAt, ?array $context = ['team' => 'payments']): ReviewStatusView {
        return new ReviewStatusView(
            requestId: hash('sha256', $requestId),
            capability: 'orders.refund',
            status: ReviewStatus::Pending,
            reason: 'Scripted.',
            summaryFingerprint: null,
            createdAt: new DateTimeImmutable($createdAt),
            expiresAt: new DateTimeImmutable('+1 hour'),
            resolvedBy: null,
            resolvedAt: null,
            approvalContext: $context,
        );
    };

    // The contextless view could never come from the real reader — so a queue that re-filters
    // the answer by context locally drops it and fails. pendingWithin() owns filtering.
    $reader = new class($viewFor('scripted-newer', '-1 minute'), $viewFor('scripted-older', '-10 minutes'), $viewFor('scripted-contextless', '-20 minutes', null)) implements ReviewStatusReader
    {
        /** @var list<array<string, string|int>> */
        public array $asked = [];

        /** @var list<ReviewStatusView> */
        private array $views;

        public function __construct(ReviewStatusView ...$views)
        {
            $this->views = array_values($views);
        }

        public function statusFor(string $requestId): ?ReviewStatusView
        {
            return null;
        }

        public function pendingWithin(array $scope): array
        {
            $this->asked[] = $scope;

            return $this->views;
        }
    };
    app()->instance(ReviewStatusReader::class, $reader);

    $result = app(ReviewQueue::class)->items();

    expect(array_map(fn (ReviewItem $item): string => $item->requestId, $result->items))
        ->toBe([hash('sha256', 'scripted-newer'), hash('sha256', 'scripted-older'), hash('sha256', 'scripted-contextless')])
        ->and(array_map(fn (ReviewItem $item): string => $item->requestId, $result->items))->not->toContain(hash('sha256', 'request-in-table-only'))
        ->and($reader->asked)->toBe([['team' => 'payments']]);
});

// --- item read-model ------------------------------------------------------------------------------

it('builds display-safe items carrying the request vocabulary and computed lapse', function (): void {
    $pending = reviewRow('request-pending');
    $lapsed = reviewRow('request-lapsed', expiresAt: '-1 minute');

    $items = app(ReviewQueue::class)->items()->items;
    $byId = array_combine(array_map(fn (ReviewItem $item): string => $item->requestId, $items), $items);

    // Expiry is the console's clock comparison over the reader's expiresAt — never a stored
    // status (ADR 0031 §5 / 0035 §4): the lapsed request still enumerates, as its own state.
    expect($byId[$pending]->state)->toBe(ReviewItemState::Pending)
        ->and($byId[$pending]->capability)->toBe('orders.refund')
        ->and($byId[$pending]->reason)->toBe('Refunds over the limit need review.')
        ->and($byId[$pending]->summaryFingerprint)->toBe(hash('sha256', 'Refund #881 for customer X.'))
        ->and($byId[$lapsed]->state)->toBe(ReviewItemState::LapsedUndecided);
});

// --- verbs ----------------------------------------------------------------------------------------

it('offers approve and reject for a live pending request and nothing for any other state', function (): void {
    $verbs = app(ReviewVerbs::class);

    $pendingId = reviewRow('verbs-pending');
    $lapsedId = reviewRow('verbs-lapsed', expiresAt: '-1 minute');
    $decidedId = reviewRow('verbs-decided', status: 'approved', resolvedBy: 'reviewer-2');

    $items = [];

    foreach (app(ReviewQueue::class)->items()->items as $item) {
        $items[$item->requestId] = $item;
    }

    // Only the live pending request is in the queue; lapsed enumerates too, decided does not.
    expect($verbs->resolve($items[$pendingId]))->toBe([ReviewVerb::Approve, ReviewVerb::Reject])
        ->and($verbs->resolve($items[$lapsedId]))->toBe([]);

    // There is no close verb anywhere on this lane: nothing is waiting, so there is nothing to
    // resume or dismiss — a workflow-exit control here would imply a run that does not exist.
    expect(ReviewVerb::cases())->toHaveCount(2);
});

// --- surface contract -----------------------------------------------------------------------------

it('judges every rendered verb set through one surface contract', function (): void {
    $pendingId = reviewRow('contract-pending');

    $items = app(ReviewQueue::class)->items()->items;
    $item = $items[array_key_first($items)];

    $contract = app(ReviewSurfaceContract::class);

    $contract->assertRendered([ReviewVerb::Approve, ReviewVerb::Reject], $item);

    // A stale approve control on a lapsed item is exactly what the contract exists to refuse.
    $lapsed = new ReviewItem(
        requestId: $item->requestId,
        capability: $item->capability,
        state: ReviewItemState::LapsedUndecided,
        reason: $item->reason,
        summaryFingerprint: $item->summaryFingerprint,
        createdAt: $item->createdAt,
        expiresAt: $item->expiresAt,
        resolvedBy: null,
        resolvedAt: null,
    );

    expect(fn () => $contract->assertRendered([ReviewVerb::Approve], $lapsed))
        ->toThrow(ReviewSurfaceContractViolation::class);
});
