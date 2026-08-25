<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\VerdictConsole\Approvals\ApprovalSurfaceContract;
use Fissible\VerdictConsole\Approvals\ApprovalVerb;
use Fissible\VerdictConsole\Approvals\ApprovalVerbs;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Fissible\VerdictConsole\Exceptions\ApprovalSurfaceContractViolation;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();

    $this->approvals = new PendingApprovalStore;
    $this->verbs = new ApprovalVerbs;
    $this->challenge = new ApprovalChallenge(
        receiptId: 'receipt_1',
        toolCallId: 'call_1',
        capability: 'orders.cancel',
        reason: 'Cancelling an order needs confirmation.',
        expiresAt: new DateTimeImmutable('+10 minutes'),
    );
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_pending_approvals');
});

it('offers approve and reject only for a drivable item with a live challenge', function (): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Drivable,
    );

    expect($this->verbs->resolve($approval, $this->challenge))
        ->toBe([ApprovalVerb::Approve, ApprovalVerb::Reject]);
});

it('offers close when a drivable confirmation row no longer has a live challenge', function (): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Drivable,
    );

    expect($this->verbs->resolve($approval, null))->toBe([ApprovalVerb::Close]);
});

it('offers no verb when a live challenge belongs to another tool call', function (): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Drivable,
    );
    $otherChallenge = new ApprovalChallenge(
        receiptId: 'receipt_2',
        toolCallId: 'call_2',
        capability: 'orders.cancel',
        reason: 'Cancelling an order needs confirmation.',
        expiresAt: new DateTimeImmutable('+10 minutes'),
    );

    expect($this->verbs->resolve($approval, $otherChallenge))->toBe([]);
});

it('offers no verb for every unresumable reason even when a live challenge exists', function (UnresumableReason $reason): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Unresumable,
        unresumableReason: $reason,
    );

    expect($this->verbs->resolve($approval, $this->challenge))->toBe([]);
})->with(UnresumableReason::cases());

it('offers no widened decision shape alongside close', function (): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Drivable,
    );

    $verbs = $this->verbs->resolve($approval, null);

    expect($verbs)->toBe([ApprovalVerb::Close])
        ->and(ApprovalVerb::cases())->toBe([ApprovalVerb::Approve, ApprovalVerb::Reject, ApprovalVerb::Close]);
});

it('gives rendering surfaces one assertion against the shared verb rule', function (): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Drivable,
    );
    $contract = new ApprovalSurfaceContract($this->verbs);

    expect(fn () => $contract->assertRendered(
        [ApprovalVerb::Reject, ApprovalVerb::Approve],
        $approval,
        $this->challenge,
    ))->not->toThrow(ApprovalSurfaceContractViolation::class)
        ->and(fn () => $contract->assertRendered(
            [ApprovalVerb::Approve],
            $approval,
            $this->challenge,
        ))->toThrow(ApprovalSurfaceContractViolation::class, 'expected [approve, reject], rendered [approve]')
        ->and(fn () => $contract->assertRendered(
            [ApprovalVerb::Approve, ApprovalVerb::Reject, ApprovalVerb::Approve],
            $approval,
            $this->challenge,
        ))->toThrow(ApprovalSurfaceContractViolation::class, 'expected [approve, reject], rendered [approve, reject, approve]');
});
