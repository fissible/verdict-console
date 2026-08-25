<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\ApprovalNotification;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationDispatcher;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationKey;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Contracts\ApprovalNotificationRecipients;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_approval_notifications_table.php.stub')->up();
});

it('ships with no approval-notification recipients until the host opts in', function (): void {
    $approval = app(PendingApprovalStore::class)->ingest('call_default_recipient');

    expect(app(ApprovalNotificationRecipients::class)->forApproval($approval, ApprovalNotificationKey::Pending))->toBe([]);
});

it('does not claim or report delivery when the shipped recipient default has nobody to notify', function (): void {
    $approval = app(PendingApprovalStore::class)->ingest('call_default_delivery');

    app(ApprovalNotificationDispatcher::class)->pending($approval);

    expect(ApprovalNotification::query()->count())->toBe(0);
});
