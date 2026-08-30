<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\VerdictConsole\Approvals\ApprovalVerbs;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Illuminate\Database\Eloquent\Builder;

final readonly class ConversationApprovalScope implements ApprovalScope
{
    public function __construct(private string $conversationId) {}

    public function apply(Builder $query): Builder
    {
        return $query->where('conversation_id', $this->conversationId);
    }
}

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
});

it('keeps correlation reads available while hiding another tenant approval', function (): void {
    $writer = new PendingApprovalStore;
    $visible = $writer->ingest('call_visible', conversationId: 'tenant-a');
    $writer->ingest('call_hidden', conversationId: 'tenant-b');

    app()->instance(ApprovalScope::class, new ConversationApprovalScope('tenant-a'));
    $approvals = app(PendingApprovalStore::class);
    $hidden = $approvals->findByToolCall('call_hidden', 'tenant-b');

    expect($approvals->findByToolCall('call_visible', 'tenant-a')?->id)->toBe($visible->id)
        ->and($hidden)->not->toBeNull();
    expect($hidden)->not->toBeNull()
        ->and($approvals->isVisible($hidden))->toBeFalse();
});

it('records a pause even when the worker has no matching host scope', function (): void {
    app()->instance(ApprovalScope::class, new ConversationApprovalScope('tenant-a'));

    $approval = app(PendingApprovalStore::class)->ingest('call_worker', conversationId: 'tenant-b');

    expect($approval->tool_call_id)->toBe('call_worker')
        ->and($approval->conversation_id)->toBe('tenant-b');
});

it('does not render an action for a row outside the host scope', function (): void {
    $approval = (new PendingApprovalStore)->ingest(
        toolCallId: 'call_hidden_verb',
        conversationId: 'tenant-b',
        resumability: Resumability::Drivable,
    );
    app()->instance(ApprovalScope::class, new ConversationApprovalScope('tenant-a'));
    $view = new ApprovalStatusView(
        receiptId: 'receipt_hidden_verb',
        toolCallId: 'call_hidden_verb',
        capability: 'orders.cancel',
        status: ApprovalReceiptStatus::Pending,
        reason: null,
        expiresAt: new DateTimeImmutable('+10 minutes'),
        approvedBy: null,
        approvedAt: null,
        rejectedBy: null,
        rejectedAt: null,
        consumedAt: null,
        createdAt: new DateTimeImmutable('2026-08-30T09:00:00+00:00'),
        approvalContext: null,
    );

    expect(app(ApprovalVerbs::class)->resolve($approval, $view))->toBe([]);
});
