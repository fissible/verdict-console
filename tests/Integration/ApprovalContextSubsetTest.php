<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalReceipt;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\Verdict\Contracts\ApprovalReceiptStore;
use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\VerdictConsole\Approvals\ApprovalContextScope;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Illuminate\Support\Facades\Schema;

/**
 * VC-69's stated point, measured against the real reader: with the console scoped by
 * `ApprovalContextScope` on the same identifiers, the pending rows the console shows are exactly
 * the receipts Verdict's `pendingWithin()` would enumerate for that scope. Lifecycle stays the
 * state machinery's job — the console deliberately still shows a context-matching row whose
 * receipt was already decided — so the equality is asserted on the pending slice, which is the
 * slice where a person can act.
 */
beforeEach(function (): void {
    $verdict = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';
    (require $verdict.'/create_verdict_approval_receipts_table.php.stub')->up();
    (require $verdict.'/add_proposal_provenance_to_verdict_approval_receipts_table.php.stub')->up();
    (require $verdict.'/add_approval_context_to_verdict_approval_receipts_table.php.stub')->up();

    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_pending_approvals');
    Schema::dropIfExists('verdict_approval_receipts');
});

/** @param array<string, string|int>|null $context */
function subsetReceipt(string $toolCallId, ?array $context): ApprovalReceipt
{
    $now = new DateTimeImmutable('2026-08-30T12:00:00+00:00');

    $receipt = new ApprovalReceipt(
        id: 'receipt-'.$toolCallId,
        toolCallId: $toolCallId,
        capability: 'orders.cancel',
        bindingFingerprint: hash('sha256', $toolCallId.'-binding'),
        provenance: null,
        approvalContext: $context,
        status: ApprovalReceiptStatus::Pending,
        reason: null,
        expiresAt: $now->modify('+15 minutes'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: $now,
        updatedAt: $now,
    );

    app(ApprovalReceiptStore::class)->issue($receipt);

    return $receipt;
}

it('shows exactly the pending rows Verdict would enumerate for the same scope', function (): void {
    $store = new PendingApprovalStore;
    $pairs = [
        'call_acme' => ['tenant' => 'acme'],
        'call_acme_ws' => ['tenant' => 'acme', 'workspace' => 7],
        'call_beta' => ['tenant' => 'beta'],
        'call_uncaptured' => null,
        'call_decided' => ['tenant' => 'acme'],
    ];

    foreach ($pairs as $toolCallId => $context) {
        subsetReceipt($toolCallId, $context);
        $store->ingest(
            toolCallId: $toolCallId,
            conversationId: 'conv-'.$toolCallId,
            receiptId: 'receipt-'.$toolCallId,
            approvalContext: $context,
        );
    }

    // Decided outside the console after capture: still context-visible, no longer pending.
    app(ApprovalReceiptStore::class)->reject('receipt-call_decided', 'call_decided', 'someone-else', new DateTimeImmutable('2026-08-30T12:01:00+00:00'));

    app()->instance(ApprovalScope::class, new ApprovalContextScope(['tenant' => 'acme']));

    $visible = app(PendingApprovalStore::class)->visible();
    $visibleToolCalls = array_map(static fn (PendingApproval $row): string => $row->tool_call_id, $visible);

    // The scope dimension: every visible row carries the scope's identifiers; beta and the
    // uncaptured row are outside it. The decided row remains visible — the inbox renders it as
    // already decided — because lifecycle is not this boundary's job.
    expect($visibleToolCalls)->toEqualCanonicalizing(['call_acme', 'call_acme_ws', 'call_decided']);

    $enumerated = array_map(
        static fn (ApprovalStatusView $view): string => $view->toolCallId,
        app(ApprovalStatusReader::class)->pendingWithin(['tenant' => 'acme']),
    );
    $reader = app(ApprovalStatusReader::class);
    $visiblePending = array_values(array_filter(
        $visibleToolCalls,
        static fn (string $toolCallId): bool => $reader->statusForToolCall($toolCallId)?->status === ApprovalReceiptStatus::Pending,
    ));

    // The subset guarantee, both directions on the actionable slice: what the console shows a
    // person and still lets them decide is exactly what Verdict would enumerate for the scope.
    expect($visiblePending)->toEqualCanonicalizing($enumerated)
        ->and($enumerated)->toEqualCanonicalizing(['call_acme', 'call_acme_ws']);
});
