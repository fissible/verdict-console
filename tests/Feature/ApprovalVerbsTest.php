<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\VerdictConsole\Approvals\ApprovalSurfaceContract;
use Fissible\VerdictConsole\Approvals\ApprovalVerb;
use Fissible\VerdictConsole\Approvals\ApprovalVerbs;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Fissible\VerdictConsole\Exceptions\ApprovalSurfaceContractViolation;
use Illuminate\Support\Facades\Schema;

/**
 * VC-45: the resolver reads Verdict state as an `ApprovalStatusView` (verdict#298, ADR 0031),
 * never as a live challenge. Freshness is poll-consistency — a Pending view may be one poll
 * stale, and that is safe because approve()/reject() re-validate inside their locked
 * transaction; a stale read can only render a verb one interval longer than it was live.
 */
function verbsStatusView(
    ApprovalReceiptStatus $status = ApprovalReceiptStatus::Pending,
    string $expiresAt = '2030-01-02T03:04:05+00:00',
    string $toolCallId = 'call_1',
    string $receiptId = 'receipt_1',
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

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();

    $this->approvals = new PendingApprovalStore;
    $this->verbs = new ApprovalVerbs($this->approvals);
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_pending_approvals');
});

it('offers approve and reject only for a drivable item whose receipt is pending and unlapsed', function (): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Drivable,
    );

    expect($this->verbs->resolve($approval, verbsStatusView()))
        ->toBe([ApprovalVerb::Approve, ApprovalVerb::Reject]);
});

it('offers close when the status read finds no receipt', function (): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Drivable,
    );

    expect($this->verbs->resolve($approval, null))->toBe([ApprovalVerb::Close]);
});

/**
 * ADR 0031 §5: there is no Expired status and no expiry transition moment. The view reports
 * Pending plus the deadline, and the consumer compares clocks — a passed deadline withdraws the
 * decision verbs and leaves only the non-authorizing close.
 */
it('offers close for a pending receipt whose deadline has passed', function (): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Drivable,
    );

    expect($this->verbs->resolve($approval, verbsStatusView(expiresAt: '2000-01-02T03:04:05+00:00')))
        ->toBe([ApprovalVerb::Close]);
});

/** A decision that already happened is not offered again; the run may still need its close. */
it('offers close for a receipt another actor already decided', function (ApprovalReceiptStatus $status): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Drivable,
    );

    expect($this->verbs->resolve($approval, verbsStatusView(status: $status)))->toBe([ApprovalVerb::Close]);
})->with([
    'approved' => [ApprovalReceiptStatus::Approved],
    'rejected' => [ApprovalReceiptStatus::Rejected],
    'consumed' => [ApprovalReceiptStatus::Consumed],
]);

it('offers no verb when the status view belongs to another tool call', function (): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Drivable,
    );

    expect($this->verbs->resolve($approval, verbsStatusView(toolCallId: 'call_2', receiptId: 'receipt_2')))
        ->toBe([]);
});

it('offers no verb for every unresumable reason even when the receipt is pending', function (UnresumableReason $reason): void {
    $approval = $this->approvals->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Unresumable,
        unresumableReason: $reason,
    );

    expect($this->verbs->resolve($approval, verbsStatusView()))->toBe([]);
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
    $view = verbsStatusView();

    expect(fn () => $contract->assertRendered(
        [ApprovalVerb::Reject, ApprovalVerb::Approve],
        $approval,
        $view,
    ))->not->toThrow(ApprovalSurfaceContractViolation::class)
        ->and(fn () => $contract->assertRendered(
            [ApprovalVerb::Approve],
            $approval,
            $view,
        ))->toThrow(ApprovalSurfaceContractViolation::class, 'expected [approve, reject], rendered [approve]')
        ->and(fn () => $contract->assertRendered(
            [ApprovalVerb::Approve, ApprovalVerb::Reject, ApprovalVerb::Approve],
            $approval,
            $view,
        ))->toThrow(ApprovalSurfaceContractViolation::class, 'expected [approve, reject], rendered [approve, reject, approve]');
});
