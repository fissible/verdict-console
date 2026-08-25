<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Approvals\UpstreamSource;
use Fissible\Verdict\Context\ContextChannel;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Source;
use Fissible\Verdict\Context\Trust;
use Fissible\VerdictConsole\Approvals\ApprovalChallengeReader;
use Fissible\VerdictConsole\Approvals\ApprovalItem;
use Fissible\VerdictConsole\Approvals\ApprovalItemFactory;
use Fissible\VerdictConsole\Approvals\ApprovalVerbs;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();

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
    $item = approvalItem($this->store, $this->approval, challenge(provenance: $provenance));

    expect($item->toArray())->toMatchArray([
        'receipt_id' => 'live-receipt-id',
        'capability' => 'orders.cancel',
        'reason' => 'Cancelling an order needs confirmation.',
        'reason_label' => 'Why this capability is gated',
        'expires_at' => '2030-01-02T03:04:05+00:00',
        'waiting_since' => null,
        'state' => 'pending',
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

it('takes expiry from the live challenge, never the persisted presentation', function (): void {
    $this->approval->forceFill(['presentation' => ['expires_at' => '1999-01-01T00:00:00+00:00']]);

    expect(approvalItem($this->store, $this->approval, challenge())->toArray()['expires_at'])
        ->toBe('2030-01-02T03:04:05+00:00');
});

it('does not warn for an untrusted user source', function (): void {
    $item = approvalItem($this->store, $this->approval, challenge(provenance: ProposalProvenance::declared([
        new UpstreamSource(Source::user('customer'), Trust::Untrusted, DataClass::PII, ContextChannel::UserInput),
    ])));

    expect($item->toArray()['provenance']['sources'][0]['warning'])->toBeFalse();
});

it('calls only the live challenge contract and makes a missing challenge non-actionable', function (): void {
    $reader = new class implements ApprovalChallengeReader
    {
        public array $toolCallIds = [];

        public function challengeForToolCall(string $toolCallId): ?ApprovalChallenge
        {
            $this->toolCallIds[] = $toolCallId;

            return null;
        }
    };
    $item = (new ApprovalItemFactory($reader, new ApprovalVerbs($this->store)))->make($this->approval);

    expect($reader->toolCallIds)->toBe(['call_1'])
        ->and($item->toArray())->toMatchArray([
            'state' => 'expired_or_already_decided', 'expires_at' => null, 'verbs' => ['close'],
        ]);
});

function approvalItem(PendingApprovalStore $store, PendingApproval $approval, ApprovalChallenge $challenge): ApprovalItem
{
    return (new ApprovalItemFactory(new class($challenge) implements ApprovalChallengeReader
    {
        public function __construct(private ApprovalChallenge $challenge) {}

        public function challengeForToolCall(string $toolCallId): ?ApprovalChallenge
        {
            return $this->challenge;
        }
    }, new ApprovalVerbs($store)))->make($approval);
}

function challenge(?ProposalProvenance $provenance = null): ApprovalChallenge
{
    return new ApprovalChallenge(
        receiptId: 'live-receipt-id',
        toolCallId: 'call_1',
        capability: 'orders.cancel',
        reason: 'Cancelling an order needs confirmation.',
        expiresAt: new DateTimeImmutable('2030-01-02T03:04:05+00:00'),
        provenance: $provenance,
    );
}
