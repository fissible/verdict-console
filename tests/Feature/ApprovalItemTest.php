<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\Verdict\Approvals\ApproverSummaryRelease;
use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Approvals\UpstreamSource;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\Verdict\Support\ApproverSummary;
use Fissible\VerdictConsole\Approvals\ApprovalChallengeReader;
use Fissible\VerdictConsole\Approvals\ApprovalItem;
use Fissible\VerdictConsole\Approvals\ApprovalItemFactory;
use Fissible\VerdictConsole\Approvals\ApprovalVerbs;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Illuminate\Support\Facades\Schema;

/**
 * VC-45: the item's status fields come from verdict#298's `ApprovalStatusView` — the read that
 * un-collapses the old null into "already decided" versus "lapsed, undecided". The live challenge
 * survives for exactly one datum the view does not carry: provenance.
 */
final class ItemStatuses implements ApprovalStatusReader
{
    /** @var list<array{0: string, 1: string}> every read the factory made, as [method, key] */
    public array $reads = [];

    public function __construct(private ?ApprovalStatusView $view = null) {}

    public function statusFor(string $receiptId): ?ApprovalStatusView
    {
        $this->reads[] = ['statusFor', $receiptId];

        return $this->view;
    }

    public function statusForToolCall(string $toolCallId): ?ApprovalStatusView
    {
        $this->reads[] = ['statusForToolCall', $toolCallId];

        return $this->view;
    }

    public function pendingWithin(array $scope): array
    {
        return [];
    }
}

final class ItemChallenges implements ApprovalChallengeReader
{
    /** @var list<string> */
    public array $reads = [];

    public function __construct(private ?ApprovalChallenge $challenge = null) {}

    public function challengeForToolCall(string $toolCallId): ?ApprovalChallenge
    {
        $this->reads[] = $toolCallId;

        return $this->challenge;
    }
}

function itemStatusView(
    ApprovalReceiptStatus $status = ApprovalReceiptStatus::Pending,
    string $expiresAt = '2030-01-02T03:04:05+00:00',
    string $toolCallId = 'call_1',
    string $receiptId = 'persisted-receipt-id',
): ApprovalStatusView {
    return new ApprovalStatusView(
        receiptId: $receiptId,
        toolCallId: $toolCallId,
        capability: 'orders.cancel',
        status: $status,
        reason: 'Cancelling an order needs confirmation.',
        expiresAt: new DateTimeImmutable($expiresAt),
        approvedBy: in_array($status, [ApprovalReceiptStatus::Approved, ApprovalReceiptStatus::Consumed], true) ? 'other-operator' : null,
        approvedAt: null,
        rejectedBy: $status === ApprovalReceiptStatus::Rejected ? 'other-operator' : null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: new DateTimeImmutable('2026-08-30T09:00:00+00:00'),
        approvalContext: null,
    );
}

function approvalItem(
    PendingApprovalStore $store,
    PendingApproval $approval,
    ?ApprovalStatusView $view,
    ?ApprovalChallenge $challenge = null,
): ApprovalItem {
    return (new ApprovalItemFactory(
        new ItemStatuses($view),
        new ItemChallenges($challenge),
        new ApprovalVerbs($store),
    ))->make($approval);
}

/** The live challenge deliberately disagrees with the view everywhere they overlap, so any field it leaks into fails. */
function challenge(
    ?ProposalProvenance $provenance = null,
    ?ApproverSummary $summary = null,
    ?ApproverSummaryRelease $release = null,
): ApprovalChallenge {
    return new ApprovalChallenge(
        receiptId: 'live-receipt-id',
        toolCallId: 'call_1',
        capability: 'orders.cancel',
        reason: 'A different reason the challenge must not contribute.',
        expiresAt: new DateTimeImmutable('2031-05-06T07:08:09+00:00'),
        provenance: $provenance,
        // Deliberately not the view's createdAt: waiting_since is the view's field, and a
        // challenge instant leaking into it fails here.
        issuedAt: new DateTimeImmutable('2029-09-09T09:09:09+00:00'),
        approverSummary: $summary,
        approverSummaryRelease: $release,
    );
}

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();

    $this->store = new PendingApprovalStore;
    $this->approval = $this->store->ingest(
        toolCallId: 'call_1',
        conversationId: 'conversation_1',
        receiptId: 'persisted-receipt-id',
        presentation: ['tool' => 'orders.cancel', 'arguments_fingerprint' => 'sha256:stored'],
        resumability: Resumability::Drivable,
    );
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_pending_approvals');
});

it('renders every provenance disclosure distinctly from the live challenge', function (?ProposalProvenance $provenance, array $expected): void {
    $item = approvalItem($this->store, $this->approval, itemStatusView(), challenge(provenance: $provenance));

    expect($item->toArray())->toMatchArray([
        'receipt_id' => 'persisted-receipt-id',
        'capability' => 'orders.cancel',
        'reason' => 'Cancelling an order needs confirmation.',
        'reason_label' => 'Why this capability is gated',
        'expires_at' => '2030-01-02T03:04:05+00:00',
        'waiting_since' => '2026-08-30T09:00:00+00:00',
        'state' => 'pending',
        'receipt_status' => 'pending',
        'verbs' => ['approve', 'reject'],
        'provenance' => $expected,
    ]);
})->with([
    'declared sources' => [ProposalProvenance::declared([
        new UpstreamSource(Source::user('customer'), Trust::Trusted, DataClass::PII, ContextChannel::UserInput),
    ]), [
        'state' => 'declared',
        'sources' => [[
            'kind' => 'user', 'name' => 'customer', 'trust' => 'trusted', 'data_class' => 'pii',
            'channel' => 'user_input', 'warning' => false,
        ]],
        'undescribed_source_count' => 0,
        'withheld_source_count' => 0,
    ]],
    'declared partial release' => [ProposalProvenance::declared([
        new UpstreamSource(Source::external('search'), Trust::Untrusted, DataClass::Public, ContextChannel::RetrievedDocument),
    ], undescribedSourceCount: 2, withheldSourceCount: 1), [
        'state' => 'declared',
        'sources' => [[
            'kind' => 'external', 'name' => 'search', 'trust' => 'untrusted', 'data_class' => 'public',
            'channel' => 'retrieved_document', 'warning' => true,
        ]],
        'undescribed_source_count' => 2,
        'withheld_source_count' => 1,
    ]],
    'unknown' => [ProposalProvenance::unknown(), ['state' => 'unknown', 'message' => 'provenance unknown — no derivation was declared']],
    'unreleased' => [ProposalProvenance::unreleased(), ['state' => 'unreleased', 'message' => 'the application has not configured provenance release to approvers']],
    'before capture' => [null, ['state' => 'issued_before_provenance_capture', 'message' => 'issued before provenance capture']],
]);

it('takes status fields from the status view, never the presentation or the challenge', function (): void {
    $this->approval->forceFill(['presentation' => ['expires_at' => '1999-01-01T00:00:00+00:00']]);

    $rendered = approvalItem($this->store, $this->approval, itemStatusView(), challenge())->toArray();

    expect($rendered['expires_at'])->toBe('2030-01-02T03:04:05+00:00')
        ->and($rendered['reason'])->toBe('Cancelling an order needs confirmation.')
        ->and($rendered['receipt_id'])->toBe('persisted-receipt-id');
});

/**
 * ADR 0031 §5's split, rendered: a decided status (approved/rejected/consumed) is "already
 * decided"; Pending with the deadline in the past is "lapsed, undecided" — the console compares
 * clocks, because expiry has no transition moment and no Expired status exists. Every non-pending
 * state offers only close, and reports the persisted status it read.
 */
it('renders each receipt status as its own state', function (ApprovalStatusView $view, array $expected): void {
    expect(approvalItem($this->store, $this->approval, $view)->toArray())->toMatchArray($expected);
})->with([
    'pending, deadline ahead' => [
        itemStatusView(),
        ['state' => 'pending', 'receipt_status' => 'pending', 'expires_at' => '2030-01-02T03:04:05+00:00', 'verbs' => ['approve', 'reject']],
    ],
    'pending, deadline passed' => [
        itemStatusView(expiresAt: '2000-01-02T03:04:05+00:00'),
        ['state' => 'lapsed_undecided', 'receipt_status' => 'pending', 'expires_at' => '2000-01-02T03:04:05+00:00', 'verbs' => ['close'], 'provenance' => null],
    ],
    'approved elsewhere' => [
        itemStatusView(status: ApprovalReceiptStatus::Approved),
        ['state' => 'already_decided', 'receipt_status' => 'approved', 'verbs' => ['close'], 'provenance' => null],
    ],
    'rejected elsewhere' => [
        itemStatusView(status: ApprovalReceiptStatus::Rejected),
        ['state' => 'already_decided', 'receipt_status' => 'rejected', 'verbs' => ['close'], 'provenance' => null],
    ],
    'consumed' => [
        itemStatusView(status: ApprovalReceiptStatus::Consumed),
        ['state' => 'already_decided', 'receipt_status' => 'consumed', 'verbs' => ['close'], 'provenance' => null],
    ],
]);

it('makes a missing receipt non-actionable without classifying it', function (): void {
    expect(approvalItem($this->store, $this->approval, null)->toArray())->toMatchArray([
        'state' => 'receipt_unavailable',
        'receipt_status' => null,
        'receipt_id' => 'persisted-receipt-id',
        'capability' => null,
        'reason' => null,
        'expires_at' => null,
        'verbs' => ['close'],
        'provenance' => null,
    ]);
});

/** A status view for some other tool call proves nothing about this row; it must not dress the row in its state. */
it('discards a status view that does not belong to this row', function (): void {
    $foreign = itemStatusView(toolCallId: 'call_other', receiptId: 'receipt_other');

    expect(approvalItem($this->store, $this->approval, $foreign)->toArray())->toMatchArray([
        'state' => 'receipt_unavailable',
        'receipt_status' => null,
        'verbs' => [],
    ]);
});

it('reads status by receipt id when the row holds one, by tool call only when it does not', function (): void {
    $statuses = new ItemStatuses(itemStatusView());
    (new ApprovalItemFactory($statuses, new ItemChallenges(challenge()), new ApprovalVerbs($this->store)))->make($this->approval);

    expect($statuses->reads)->toBe([['statusFor', 'persisted-receipt-id']]);

    $receiptless = $this->store->ingest(
        toolCallId: 'call_2',
        conversationId: 'conversation_2',
        resumability: Resumability::Drivable,
    );
    $byToolCall = new ItemStatuses(itemStatusView(toolCallId: 'call_2', receiptId: 'receipt_2'));
    $item = (new ApprovalItemFactory($byToolCall, new ItemChallenges, new ApprovalVerbs($this->store)))->make($receiptless);

    expect($byToolCall->reads)->toBe([['statusForToolCall', 'call_2']])
        ->and($item->toArray())->toMatchArray([
            'state' => 'pending',
            'receipt_id' => 'receipt_2',
            'verbs' => ['approve', 'reject'],
        ]);
});

it('consults the challenge only while the receipt is pending and unlapsed — provenance is the one datum the status read does not carry', function (): void {
    $pending = new ItemChallenges(challenge());
    (new ApprovalItemFactory(new ItemStatuses(itemStatusView()), $pending, new ApprovalVerbs($this->store)))->make($this->approval);

    expect($pending->reads)->toBe(['call_1']);

    foreach ([
        itemStatusView(status: ApprovalReceiptStatus::Rejected),
        itemStatusView(expiresAt: '2000-01-02T03:04:05+00:00'),
        null,
    ] as $view) {
        $unconsulted = new ItemChallenges(challenge());
        (new ApprovalItemFactory(new ItemStatuses($view), $unconsulted, new ApprovalVerbs($this->store)))->make($this->approval);

        expect($unconsulted->reads)->toBe([]);
    }
});

/**
 * The challenge read can answer null while the view still says Pending — a decision landing
 * between the two reads, or a pre-capture receipt. Neither may change state or verbs: the
 * challenge affects provenance only.
 */
it('keeps a pending item pending when the challenge read returns nothing', function (): void {
    expect(approvalItem($this->store, $this->approval, itemStatusView(), null)->toArray())->toMatchArray([
        'state' => 'pending',
        'receipt_status' => 'pending',
        'verbs' => ['approve', 'reject'],
        'provenance' => ['state' => 'issued_before_provenance_capture', 'message' => 'issued before provenance capture'],
    ]);
});

it('does not warn for an untrusted user source', function (): void {
    $item = approvalItem($this->store, $this->approval, itemStatusView(), challenge(provenance: ProposalProvenance::declared([
        new UpstreamSource(Source::user('customer'), Trust::Untrusted, DataClass::PII, ContextChannel::UserInput),
    ])));

    expect($item->toArray()['provenance']['sources'][0]['warning'])->toBeFalse();
});

/**
 * VC-47: verdict#300's issuance instant, adopted through the view. The status view's createdAt is
 * the receipt's issuance instant — the same value #300 threads onto the challenge — and the view
 * owns every live rendering field, so waiting_since is present whenever a receipt is readable and
 * null only when none is. The console row's created_at is ingestion time and never stands in; the
 * challenge's own issuedAt is staged to a different instant and must never leak.
 */
it('reports waiting_since as the views issuance instant for every readable receipt state', function (): void {
    $pending = approvalItem($this->store, $this->approval, itemStatusView(), challenge());
    $lapsed = approvalItem($this->store, $this->approval, itemStatusView(expiresAt: '2020-01-01T00:00:00+00:00'));
    $decided = approvalItem($this->store, $this->approval, itemStatusView(ApprovalReceiptStatus::Approved));
    $unavailable = approvalItem($this->store, $this->approval, null);

    foreach ([$pending, $lapsed, $decided] as $item) {
        expect($item->waitingSince?->format(DATE_ATOM))->toBe('2026-08-30T09:00:00+00:00');
    }

    expect($unavailable->waitingSince)->toBeNull()
        // Ingestion stamped the row moments ago; issuance was staged years apart. Equality here
        // would mean the row's created_at was relabelled as waiting time.
        ->and($this->approval->created_at->toIso8601String())->not->toBe('2026-08-30T09:00:00+00:00');
});

/**
 * VC-47's #306 companion: the approver summary rides the challenge like provenance does, under
 * ADR 0038's typed release states. Content appears only for a Released summary; a denied release
 * names its state and nothing else — the raw text of a withheld candidate is never retained, so
 * there is nothing this surface could show; a null release is the pre-feature storage era.
 */
it('renders the approver summary only as its typed release state admits', function (?ApproverSummary $summary, ?ApproverSummaryRelease $release, ?array $expected): void {
    $item = approvalItem($this->store, $this->approval, itemStatusView(), challenge(summary: $summary, release: $release));

    expect($item->toArray()['approver_summary'])->toBe($expected);
})->with([
    'released' => [
        new ApproverSummary('Cancel order #7 for customer X.', hash('sha256', 'Cancel order #7 for customer X.')),
        ApproverSummaryRelease::Released,
        ['state' => 'released', 'content' => 'Cancel order #7 for customer X.', 'fingerprint' => hash('sha256', 'Cancel order #7 for customer X.')],
    ],
    // A denied release carrying a (mis)persisted summary is the sharpest case: the withheld
    // content must not surface through any key, so the projection is state-only regardless.
    'release denied by policy' => [
        new ApproverSummary('WITHHELD: must never surface.', hash('sha256', 'WITHHELD: must never surface.')),
        ApproverSummaryRelease::ReleaseDenied,
        ['state' => 'release_denied'],
    ],
    'not released' => [null, ApproverSummaryRelease::NotReleased, ['state' => 'not_released']],
    // An inconsistent upstream pair — Released with nothing to release — projects nothing: a
    // state-only 'released' would promise content the renderer cannot have.
    'released without a summary' => [null, ApproverSummaryRelease::Released, null],
    'pre-feature era' => [null, null, null],
]);

it('carries no summary for a receipt state whose challenge is never read', function (): void {
    $poisoned = challenge(
        summary: new ApproverSummary('Must not surface.', hash('sha256', 'Must not surface.')),
        release: ApproverSummaryRelease::Released,
    );

    $decided = approvalItem($this->store, $this->approval, itemStatusView(ApprovalReceiptStatus::Approved), $poisoned);
    $lapsed = approvalItem($this->store, $this->approval, itemStatusView(expiresAt: '2020-01-01T00:00:00+00:00'), $poisoned);

    expect($decided->toArray()['approver_summary'])->toBeNull()
        ->and($lapsed->toArray()['approver_summary'])->toBeNull();
});
