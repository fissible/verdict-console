<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Notification idempotency and delivery state for paused approvals (design §6.2).
 *
 * The console owns this because nothing else can: Verdict knows a receipt is pending, not whether a
 * human was ever told about it. Without a durable record, a retried reconciliation pass re-notifies
 * everybody it already notified.
 */
final class ApprovalNotificationStore
{
    /**
     * Claim the right to send one notification, or find that somebody already has.
     *
     * **The unique index decides, not a read.** `claimed !== null` is the answer to "should I send
     * this", and it is produced by attempting the insert rather than by checking first: two workers
     * reconciling the same row concurrently both pass any `exists()` check before either writes.
     * Delegating to `(pending_approval_id, notification_key)` closes that window because there is no
     * window — the same reasoning `PendingApprovalStore::ingest()` uses for redelivery.
     *
     * A claim is deliberately not a delivery. The row is written *before* the send is attempted, so
     * a process that dies mid-send leaves evidence that it tried; recording only on success would
     * make a crash indistinguishable from never having started, and the retry would send twice.
     *
     * @return ApprovalNotification|null the claim, or null when this notification was already claimed
     */
    public function claim(PendingApproval $approval, string $notificationKey): ?ApprovalNotification
    {
        $now = now();

        try {
            ApprovalNotification::query()->insert([
                'id' => Str::uuid()->toString(),
                'pending_approval_id' => $approval->getKey(),
                'notification_key' => $notificationKey,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        return $this->find($approval, $notificationKey);
    }

    /** Record that the claimed notification reached its recipient. */
    public function markDelivered(ApprovalNotification $notification): void
    {
        $notification->forceFill(['delivered_at' => now(), 'failed_at' => null, 'failure_reason' => null])->save();
    }

    /**
     * Record that the claimed notification did not reach its recipient, and why.
     *
     * The reason is truncated rather than rejected: this is a diagnostic an operator reads while
     * deciding whether to retry, and losing the whole record to a long exception message would cost
     * more than losing the tail of one.
     */
    public function markFailed(ApprovalNotification $notification, string $reason): void
    {
        $notification->forceFill([
            'failed_at' => now(),
            'delivered_at' => null,
            'failure_reason' => Str::limit($reason, 250),
        ])->save();
    }

    /** The claim for one notification of one approval, if this console ever made it. */
    public function find(PendingApproval $approval, string $notificationKey): ?ApprovalNotification
    {
        return ApprovalNotification::query()
            ->where('pending_approval_id', $approval->getKey())
            ->where('notification_key', $notificationKey)
            ->first();
    }

    /**
     * Every notification claimed for one approval, oldest first.
     *
     * @return Collection<int, ApprovalNotification>
     */
    public function forApproval(PendingApproval $approval): Collection
    {
        return ApprovalNotification::query()
            ->where('pending_approval_id', $approval->getKey())
            ->orderBy('created_at')
            ->get();
    }
}
