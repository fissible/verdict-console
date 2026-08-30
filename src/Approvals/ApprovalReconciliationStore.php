<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\VerdictConsole\Exceptions\ReconciliationRecordUnreadable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/** Durable detection, retry eligibility, and closure of continuations that failed after Verdict accepted a decision. */
final class ApprovalReconciliationStore
{
    /**
     * Persist a failed continuation once, or return the record that already captured it.
     *
     * The unique index is the idempotency decision: another worker can observe the same exception
     * before this one returns, so an existence check would leave a duplicate-record race.
     *
     * **First detection wins, phase included.** A later call carrying a different phase returns the
     * original record unchanged and its own phase is discarded. That is deliberate: the stored phase
     * is an observation of what was seen at the moment continuation failed, and overwriting it would
     * replace a fact with a later guess — the operator would be shown the most recent report rather
     * than the first, with no way to tell which. If a row ever needs a second observation, it needs a
     * second record and a schema that admits many, not a mutable field on one.
     */
    public function detect(PendingApproval $approval, ResumeFailurePhase $phase): ApprovalReconciliation
    {
        $now = now();

        try {
            ApprovalReconciliation::query()->insert([
                'id' => Str::uuid()->toString(),
                'pending_approval_id' => $approval->getKey(),
                'phase' => $phase->value,
                'detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->find($approval);

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        return $this->find($approval) ?? throw ReconciliationRecordUnreadable::forApproval((string) $approval->getKey());
    }

    /** The durable reconciliation record for one paused approval, if continuation failed. */
    public function find(PendingApproval $approval): ?ApprovalReconciliation
    {
        return ApprovalReconciliation::query()
            ->where('pending_approval_id', $approval->getKey())
            ->first();
    }

    /**
     * Record abandonment once and preserve the original operator-visible timestamp on repeats.
     *
     * The `whereNull` guard makes repeats no-ops at the database boundary, rather than trusting a
     * stale in-memory model that two operators could both hold.
     */
    public function markAbandoned(ApprovalReconciliation $reconciliation): ApprovalReconciliation
    {
        $now = now();

        ApprovalReconciliation::query()
            ->whereKey($reconciliation->getKey())
            ->whereNull('abandoned_at')
            ->update([
                'abandoned_at' => $now,
                'updated_at' => $now,
            ]);

        return ApprovalReconciliation::query()
            ->whereKey($reconciliation->getKey())
            ->firstOrFail();
    }
}
