<?php

declare(strict_types=1);

use Fissible\Verdict\Reviews\DatabaseReviewRequestStore;
use Fissible\Verdict\Testing\AllowAllReviewAuthorizer;
use Fissible\VerdictConsole\Reviews\ReviewQueue;
use Fissible\VerdictConsole\Reviews\ReviewSurfaceContract;
use Fissible\VerdictConsole\Reviews\ReviewVerb;
use Illuminate\Support\Facades\DB;

/**
 * VC-48, the rendered half: the review queue Blade component is a rendering of the lane's read
 * model and verbs — honest about every way it can be empty, offering approve and reject only
 * where the surface contract admits them, and never a control that implies a run is waiting.
 */
function pageReviewRow(
    string $requestId,
    string $status = 'pending',
    string $expiresAt = '+1 hour',
    ?string $resolvedBy = null,
): string {
    $id = hash('sha256', $requestId);
    // Issuance-faithful: a decided row always records who and when.
    $resolvedBy = $status === 'pending' ? $resolvedBy : ($resolvedBy ?? 'reviewer-2');

    DB::table('verdict_review_requests')->insert([
        'id' => $id,
        'capability' => 'orders.refund',
        'binding_fingerprint' => hash('sha256', 'binding-'.$requestId),
        'status' => $status,
        'reason' => 'Refunds over the limit need review.',
        'expires_at' => now()->modify($expiresAt),
        'resolved_by' => $resolvedBy,
        'resolved_at' => $resolvedBy === null ? null : now()->subMinutes(2),
        'approval_context' => json_encode(['team' => 'payments'], JSON_THROW_ON_ERROR),
        'approver_summary' => json_encode([
            'content' => 'Refund #881 for customer X.',
            'fingerprint' => hash('sha256', 'Refund #881 for customer X.'),
        ], JSON_THROW_ON_ERROR),
        'created_at' => now()->subMinutes(10),
        'updated_at' => now()->subMinutes(10),
    ]);

    return $id;
}

function renderReviews(): string
{
    return (string) test()->blade('<x-verdict-console::reviews />');
}

beforeEach(function (): void {
    config()->set('verdict.reviews.store', DatabaseReviewRequestStore::class);
    config()->set('verdict.reviews.authorizer', AllowAllReviewAuthorizer::class);
    config()->set('verdict-console.reviews.scope', ['team' => 'payments']);

    (require dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/create_verdict_review_requests_table.php.stub')->up();
});

it('says the review lane is not configured instead of rendering an empty queue', function (): void {
    config()->set('verdict.reviews.store', null);

    $html = renderReviews();

    expect($html)->toContain('The review lane is not configured.')
        ->not->toContain('No reviews are waiting.');
});

it('says the console has no review scope, and renders no request from outside one', function (): void {
    pageReviewRow('page-hidden');
    config()->set('verdict-console.reviews.scope', []);

    $html = renderReviews();

    expect($html)->toContain('No review scope is configured.')
        ->not->toContain(hash('sha256', 'page-hidden'));
});

it('says no reviews are waiting when the scope is simply empty', function (): void {
    expect(renderReviews())->toContain('No reviews are waiting.');
});

it('renders a pending request with its vocabulary and both lane verbs', function (): void {
    $id = pageReviewRow('page-pending');

    $html = renderReviews();

    expect($html)->toContain($id)
        ->toContain('orders.refund')
        ->toContain('Refunds over the limit need review.')
        ->toContain('data-review-verb="approve"')
        ->toContain('data-review-verb="reject"');
});

it('renders a lapsed request as lapsed, undecided — with no verb control at all', function (): void {
    $id = pageReviewRow('page-lapsed', expiresAt: '-1 minute');

    $html = renderReviews();

    // Lapse is computed from the reader's expiresAt, never written; the row stays visible as its
    // own honest state, and a stale approve control here is the surface contract's one refusal.
    expect($html)->toContain($id)
        ->toContain('lapsed, undecided')
        ->not->toContain('data-review-verb=');
});

/**
 * The queue idiom's judgment, per row: every rendered verb set — extracted from the markup, keyed
 * by data-review-request — must answer to the one surface contract, with a pending and a lapsed
 * row rendered together so a component that resolves verbs once for all rows fails.
 *
 * The extraction below is itself a pinned markup contract: each item renders as one flat row
 * element opening with data-review-request="<id>", and every verb control for that item appears
 * after its marker and before the next marker or the section's close. Nesting one row's controls
 * inside another's region would mis-associate them here — by design, not by accident.
 */
it('judges every rows rendered verb set through the surface contract', function (): void {
    pageReviewRow('page-contract-pending');
    pageReviewRow('page-contract-lapsed', expiresAt: '-1 minute');

    $html = renderReviews();

    $items = [];

    foreach (app(ReviewQueue::class)->items()->items as $item) {
        $items[$item->requestId] = $item;
    }

    preg_match_all('/data-review-request="([^"]+)"(.*?)(?=data-review-request="|<\/section>)/s', $html, $rows, PREG_SET_ORDER);

    expect($rows)->toHaveCount(2);

    $contract = app(ReviewSurfaceContract::class);

    foreach ($rows as $row) {
        preg_match_all('/data-review-verb="([^"]+)"/', $row[2], $verbs);
        $rendered = array_map(fn (string $verb): ReviewVerb => ReviewVerb::from($verb), $verbs[1]);

        $contract->assertRendered($rendered, $items[$row[1]]);
    }
});

it('renders the approver summary fingerprint, display-safe, on a pending row', function (): void {
    pageReviewRow('page-summary');

    expect(renderReviews())->toContain(hash('sha256', 'Refund #881 for customer X.'));
});

it('never renders the raw binding fingerprint anywhere', function (): void {
    pageReviewRow('page-safe');

    expect(renderReviews())->not->toContain(hash('sha256', 'binding-page-safe'));
});
