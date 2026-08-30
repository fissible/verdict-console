<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\ApprovalNotification;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationStore;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_approval_notifications_table.php.stub')->up();

    $this->approvals = new PendingApprovalStore;
    $this->notifications = new ApprovalNotificationStore;
    $this->row = $this->approvals->ingest(toolCallId: 'call_1', conversationId: 'conv_1');
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_approval_notifications');
    Schema::dropIfExists('verdict_console_pending_approvals');
});

it('claims a notification once and refuses the second claim', function (): void {
    $first = $this->notifications->claim($this->row, 'assigned');
    $second = $this->notifications->claim($this->row, 'assigned');

    expect($first)->toBeInstanceOf(ApprovalNotification::class)
        ->and($second)->toBeNull('A second claim must not authorize a second send.')
        ->and(ApprovalNotification::query()->count())->toBe(1);
});

/** Different notifications about the same approval are independent; only a repeat is refused. */
it('claims distinct notifications for the same approval', function (): void {
    expect($this->notifications->claim($this->row, 'assigned'))->not->toBeNull()
        ->and($this->notifications->claim($this->row, 'reminder-1'))->not->toBeNull()
        ->and($this->notifications->claim($this->row, 'escalation'))->not->toBeNull()
        ->and(ApprovalNotification::query()->count())->toBe(3);
});

/**
 * The same notification key for two different approvals is two different notifications.
 *
 * Uniqueness is on the pair. Keying on the notification alone would mean the first approval ever
 * assigned silenced every later one.
 */
it('scopes idempotency to the approval, not the notification key alone', function (): void {
    $other = $this->approvals->ingest(toolCallId: 'call_2', conversationId: 'conv_2');

    expect($this->notifications->claim($this->row, 'assigned'))->not->toBeNull()
        ->and($this->notifications->claim($other, 'assigned'))->not->toBeNull()
        ->and(ApprovalNotification::query()->count())->toBe(2);
});

/**
 * A claim is written before the send is attempted, so a crash mid-send leaves evidence.
 *
 * Recording only on success would make "died while sending" indistinguishable from "never started",
 * and the retry would send a second time — the duplicate this table exists to prevent.
 */
it('records a claim as in flight until its outcome is known', function (): void {
    $claim = $this->notifications->claim($this->row, 'assigned');

    expect($claim->isInFlight())->toBeTrue()
        ->and($claim->delivered_at)->toBeNull()
        ->and($claim->failed_at)->toBeNull();
});

it('records delivery', function (): void {
    $claim = $this->notifications->claim($this->row, 'assigned');
    $this->notifications->markDelivered($claim);

    $stored = ApprovalNotification::query()->sole();

    expect($stored->delivered_at)->not->toBeNull()
        ->and($stored->failed_at)->toBeNull()
        ->and($stored->isInFlight())->toBeFalse();
});

it('records failure with its reason', function (): void {
    $claim = $this->notifications->claim($this->row, 'assigned');
    $this->notifications->markFailed($claim, 'SMTP 550 mailbox unavailable');

    $stored = ApprovalNotification::query()->sole();

    expect($stored->failed_at)->not->toBeNull()
        ->and($stored->delivered_at)->toBeNull()
        ->and($stored->failure_reason)->toBe('SMTP 550 mailbox unavailable');
});

/** A long exception message must cost the tail of a diagnostic, never the whole record. */
it('truncates an overlong failure reason rather than losing the record', function (): void {
    $claim = $this->notifications->claim($this->row, 'assigned');
    $this->notifications->markFailed($claim, str_repeat('x', 5000));

    $stored = ApprovalNotification::query()->sole();

    expect($stored->failed_at)->not->toBeNull()
        ->and(strlen((string) $stored->failure_reason))->toBeLessThanOrEqual(255);
});

/** A retry that succeeds must not leave the row claiming both outcomes. */
it('replaces a failure with a delivery when a retry succeeds', function (): void {
    $claim = $this->notifications->claim($this->row, 'assigned');
    $this->notifications->markFailed($claim, 'timeout');
    $this->notifications->markDelivered($claim);

    $stored = ApprovalNotification::query()->sole();

    expect($stored->delivered_at)->not->toBeNull()
        ->and($stored->failed_at)->toBeNull()
        ->and($stored->failure_reason)->toBeNull();
});

it('finds a claim and lists every notification for an approval', function (): void {
    $this->notifications->claim($this->row, 'assigned');
    $this->notifications->claim($this->row, 'reminder-1');

    expect($this->notifications->find($this->row, 'assigned'))->not->toBeNull()
        ->and($this->notifications->find($this->row, 'never-sent'))->toBeNull()
        ->and($this->notifications->forApproval($this->row))->toHaveCount(2);
});

/**
 * This table records console work, never Verdict's decision or the recipient's details.
 *
 * A `recipient` or `body` column would turn an audit of what the console did into a copy of the
 * message itself, which is the host's to keep and ADR 0008's fingerprint rule to answer for.
 */
it('mirrors no Verdict state and stores no message content', function (): void {
    $columns = Schema::getColumnListing('verdict_console_approval_notifications');

    expect($columns)->not->toContain('receipt_id')
        ->and($columns)->not->toContain('status')
        ->and($columns)->not->toContain('expires_at')
        ->and($columns)->not->toContain('recipient')
        ->and($columns)->not->toContain('body');
});
