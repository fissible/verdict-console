<?php

declare(strict_types=1);

use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Evidence\ArgumentFingerprint;
use Fissible\VerdictConsole\Approvals\PendingApproval as StoredPendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Contracts\ApprovalPresenter;
use Fissible\VerdictConsole\Presentation\ApprovalPresentation;
use Fissible\VerdictConsole\Presentation\DefaultApprovalPresenter;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Approvals\PendingApproval;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_pending_approvals');
});

function presentationApproval(array $arguments = ['secret' => 'do-not-store-me']): PendingApproval
{
    return new PendingApproval(
        id: 'tool-call-1',
        tool: 'cancel_order',
        arguments: $arguments,
        reason: 'Cancellation needs review.',
    );
}

it('persists only display-safe boundary vocabulary by default', function (): void {
    $arguments = [
        'order' => 'order_123',
        'api_key' => 'super-secret-api-key',
        'nested' => ['token' => 'another-secret'],
    ];

    $presentation = app(ApprovalPresenter::class)->present(
        presentationApproval($arguments),
        new ApprovalChallenge(
            receiptId: 'receipt-1',
            toolCallId: 'tool-call-1',
            capability: 'orders.cancel',
            reason: 'A human must confirm the cancellation.',
            expiresAt: new DateTimeImmutable('+10 minutes'),
        ),
    )->toArray();

    (new PendingApprovalStore)->ingest(
        toolCallId: 'tool-call-1',
        conversationId: 'conversation-1',
        presentation: $presentation,
    );

    $stored = StoredPendingApproval::query()->sole()->getRawOriginal('presentation');

    expect($presentation)->toBe([
        'tool' => 'cancel_order',
        'capability' => 'orders.cancel',
        'reason' => 'A human must confirm the cancellation.',
        'arguments_fingerprint' => ArgumentFingerprint::make($arguments),
        'details' => [],
    ])
        ->and($stored)->not->toContain('super-secret-api-key')
        ->and($stored)->not->toContain('another-secret')
        ->and($stored)->not->toContain('order_123');
});

it('uses the Laravel AI reason and no capability for receiptless approvals', function (): void {
    $presentation = (new DefaultApprovalPresenter)->present(presentationApproval())->toArray();

    expect($presentation['capability'])->toBeNull()
        ->and($presentation['reason'])->toBe('Cancellation needs review.')
        ->and($presentation['details'])->toBe([]);
});

it('allows a host to opt in to an application-specific presentation', function (): void {
    $hostPresenter = new class implements ApprovalPresenter
    {
        public function present(PendingApproval $approval, ?ApprovalChallenge $challenge = null): ApprovalPresentation
        {
            return new ApprovalPresentation(
                tool: $approval->tool,
                argumentsFingerprint: ArgumentFingerprint::make($approval->arguments),
                details: $challenge?->capability === 'orders.cancel' ? ['order' => $approval->arguments['order']] : [],
            );
        }
    };

    app()->instance(ApprovalPresenter::class, $hostPresenter);

    expect(app(ApprovalPresenter::class)->present(
        presentationApproval(['order' => 'order_123']),
        new ApprovalChallenge('receipt-1', 'tool-call-1', 'orders.cancel', null, new DateTimeImmutable('+10 minutes')),
    )->toArray()['details'])
        ->toBe(['order' => 'order_123']);
});
