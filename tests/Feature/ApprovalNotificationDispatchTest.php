<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\ApprovalNotification;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationDispatcher;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationKey;
use Fissible\VerdictConsole\Approvals\ApprovalNotificationStore;
use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Contracts\ApprovalNotificationRecipients;
use Fissible\VerdictConsole\Notifications\ApprovalResumeOutcomeNotification;
use Fissible\VerdictConsole\Notifications\ApprovedApprovalNotification;
use Fissible\VerdictConsole\Notifications\PendingApprovalNotification;
use Fissible\VerdictConsole\Notifications\RejectedApprovalNotification;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Notification;

final class ApprovalNotificationRecipient
{
    use Notifiable;

    public function __construct(private readonly string $key) {}

    public function getKey(): string
    {
        return $this->key;
    }
}

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_approval_notifications_table.php.stub')->up();
});

it('claims one pending observation before notifying every host recipient', function (): void {
    $approval = app(PendingApprovalStore::class)->ingest('call_pending_notification');
    $first = new ApprovalNotificationRecipient('first');
    $second = new ApprovalNotificationRecipient('second');

    app()->instance(ApprovalNotificationRecipients::class, new class($first, $second) implements ApprovalNotificationRecipients
    {
        public function __construct(private object $first, private object $second) {}

        public function forApproval(PendingApproval $approval, ApprovalNotificationKey $key): iterable
        {
            return [$this->first, $this->second];
        }
    });
    Notification::fake();

    app(ApprovalNotificationDispatcher::class)->pending($approval);
    app(ApprovalNotificationDispatcher::class)->pending($approval);

    Notification::assertSentToTimes($first, PendingApprovalNotification::class, 1);
    Notification::assertSentToTimes($second, PendingApprovalNotification::class, 1);
    expect(ApprovalNotification::query()->count())->toBe(1, 'A claim belongs to the approval observation, not to each recipient.');
});

it('never presents a notification as a consumed receipt or a finished action', function (): void {
    $approval = app(PendingApprovalStore::class)->ingest('call_notification_copy');
    $recipient = new ApprovalNotificationRecipient('copy');

    $messages = [
        (new PendingApprovalNotification($approval))->toArray($recipient)['message'],
        (new ApprovedApprovalNotification($approval))->toArray($recipient)['message'],
        (new RejectedApprovalNotification($approval))->toArray($recipient)['message'],
        (new ApprovalResumeOutcomeNotification($approval))->toArray($recipient)['message'],
    ];

    foreach ($messages as $message) {
        expect($message)->not->toContain('consumed')
            ->not->toContain('completed')
            ->not->toContain('finished');
    }
});

it('gives the host the observation key needed to route different notification audiences', function (): void {
    $approval = app(PendingApprovalStore::class)->ingest('call_keyed_recipients');
    $recipient = new ApprovalNotificationRecipient('routing');
    $recipients = new class($recipient) implements ApprovalNotificationRecipients
    {
        /** @var array<int, ApprovalNotificationKey> */
        public array $requested = [];

        public function __construct(private object $recipient) {}

        public function forApproval(PendingApproval $approval, ApprovalNotificationKey $key): iterable
        {
            $this->requested[] = $key;

            return [$this->recipient];
        }
    };
    app()->instance(ApprovalNotificationRecipients::class, $recipients);
    app()->instance(ApprovalNotificationStore::class, new ApprovalNotificationStore);
    Notification::fake();

    app(ApprovalNotificationDispatcher::class)->pending($approval);

    expect($recipients->requested)->toBe([ApprovalNotificationKey::Pending]);
});

it('routes every observation only to the recipient selected for its key', function (): void {
    $approval = app(PendingApprovalStore::class)->ingest('call_keyed_audiences');
    $pending = new ApprovalNotificationRecipient('pending');
    $approved = new ApprovalNotificationRecipient('approved');
    $rejected = new ApprovalNotificationRecipient('rejected');
    $resumed = new ApprovalNotificationRecipient('resumed');
    app()->instance(ApprovalNotificationRecipients::class, new class($pending, $approved, $rejected, $resumed) implements ApprovalNotificationRecipients
    {
        public function __construct(
            private object $pending,
            private object $approved,
            private object $rejected,
            private object $resumed,
        ) {}

        public function forApproval(PendingApproval $approval, ApprovalNotificationKey $key): iterable
        {
            return match ($key) {
                ApprovalNotificationKey::Pending => [$this->pending],
                ApprovalNotificationKey::Approved => [$this->approved],
                ApprovalNotificationKey::Rejected => [$this->rejected],
                ApprovalNotificationKey::ResumeOutcome => [$this->resumed],
            };
        }
    });
    Notification::fake();

    $dispatcher = app(ApprovalNotificationDispatcher::class);
    $dispatcher->pending($approval);
    $dispatcher->approved($approval);
    $dispatcher->rejected($approval);
    $dispatcher->resumeOutcome($approval);

    Notification::assertSentToTimes($pending, PendingApprovalNotification::class, 1);
    Notification::assertSentToTimes($approved, ApprovedApprovalNotification::class, 1);
    Notification::assertSentToTimes($rejected, RejectedApprovalNotification::class, 1);
    Notification::assertSentToTimes($resumed, ApprovalResumeOutcomeNotification::class, 1);
});
