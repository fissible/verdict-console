<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes and reads the console's index of paused approvals.
 *
 * The only interesting thing here is `ingest()`, and the only interesting thing about `ingest()` is
 * that it does not read before it writes.
 */
final class PendingApprovalStore
{
    /**
     * Record a pause, or return the row that already records it.
     *
     * **First-write-wins, and atomic.** A redelivered `ToolApprovalRequested` — the same pause
     * arriving twice — must not produce a second row, and the obvious implementation
     * (`if (! exists) { insert }`) cannot guarantee that: two workers can both pass the existence
     * check before either writes. Delegating the decision to the unique index closes that window
     * entirely, because there is no window. The database picks the winner.
     *
     * First-write-wins rather than last-write-wins is deliberate: by the time a redelivery arrives
     * the original row may have been annotated — marked unresumable, given a resolver key — and an
     * upsert would silently discard that. A duplicate event carries no newer truth than the row it
     * duplicates.
     *
     * **It catches rather than ignores, and that distinction is load-bearing.** An earlier version
     * used `insertOrIgnore`, which discards *every* conflict — so a second pause claiming a receipt
     * another row already holds was silently dropped, and the read-back then failed with a
     * bewildering "no rows" error. But two pauses on one receipt is not a redelivery; it is an
     * anomaly worth raising, because it would mean two humans could drive the same action. Catching
     * the violation and re-reading lets the two cases be told apart: a row under this ingest key
     * means redelivery, no row means the conflict was somebody else's constraint, and that is
     * rethrown.
     *
     * `$unresumableReason` names which drivability check failed, when one did — an observation the
     * bridge made, never an inference. It is stored on the row because until VC-15's ledger exists
     * the matching ingestion incident is ephemeral, so the row is the only place the reason survives.
     *
     * **A drivable row never carries one, and that is enforced here rather than trusted.** A row
     * saying both "this console can drive the run" and "the resolver key did not rebuild an agent"
     * is self-contradictory, and an operator reading the reason column has no way to know which half
     * to believe. `resumability` is the authority; a reason supplied alongside `Drivable` is
     * discarded, so the row and the ingestion incident cannot disagree about a row that has nothing
     * to explain.
     *
     * `$participantReference` is opaque and host-supplied. This package never derives it from
     * Laravel AI's participant object and never interprets it — see the migration for why.
     *
     * @param  array<string, mixed>|null  $presentation
     */
    public function ingest(
        string $toolCallId,
        ?string $conversationId = null,
        ?string $participantReference = null,
        ?string $invocationId = null,
        ?string $receiptId = null,
        ?string $resolverKey = null,
        ?array $presentation = null,
        Resumability $resumability = Resumability::Unresumable,
        ?UnresumableReason $unresumableReason = null,
    ): PendingApproval {
        return $this->ingestWithOutcome(
            toolCallId: $toolCallId,
            conversationId: $conversationId,
            participantReference: $participantReference,
            invocationId: $invocationId,
            receiptId: $receiptId,
            resolverKey: $resolverKey,
            presentation: $presentation,
            resumability: $resumability,
            unresumableReason: $unresumableReason,
        )->pendingApproval;
    }

    /**
     * Record a pause and say whether this call created its row.
     *
     * The listener needs this narrower outcome to make its incident dispatch idempotent under a
     * concurrent redelivery. Callers that only need the durable row use {@see ingest()}.
     *
     * @param  array<string, mixed>|null  $presentation
     */
    public function ingestWithOutcome(
        string $toolCallId,
        ?string $conversationId = null,
        ?string $participantReference = null,
        ?string $invocationId = null,
        ?string $receiptId = null,
        ?string $resolverKey = null,
        ?array $presentation = null,
        Resumability $resumability = Resumability::Unresumable,
        ?UnresumableReason $unresumableReason = null,
    ): PendingApprovalIngestion {
        $ingestKey = PendingApproval::ingestKey($toolCallId, $conversationId);
        $now = now();

        try {
            PendingApproval::query()->insert([
                'id' => Str::uuid()->toString(),
                'ingest_key' => $ingestKey,
                'receipt_id' => $receiptId,
                'tool_call_id' => $toolCallId,
                'conversation_id' => $conversationId,
                'participant_reference' => $participantReference,
                'invocation_id' => $invocationId,
                'resolver_key' => $resolverKey,
                'presentation' => $presentation === null ? null : json_encode($presentation, JSON_THROW_ON_ERROR),
                'resumability' => $resumability->value,
                'unresumable_reason' => $resumability === Resumability::Drivable ? null : $unresumableReason?->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Read back by the natural key rather than trusting the id generated above: whoever won
            // the race wrote a different one, and theirs is the row that matters.
            $existing = PendingApproval::query()->where('ingest_key', $ingestKey)->first();

            if ($existing === null) {
                throw $e;
            }

            return new PendingApprovalIngestion($existing, false);
        }

        return new PendingApprovalIngestion(
            PendingApproval::query()->where('ingest_key', $ingestKey)->sole(),
            true,
        );
    }

    /**
     * The row recording a given pause, if this console has seen it.
     */
    public function findByToolCall(string $toolCallId, ?string $conversationId = null): ?PendingApproval
    {
        return PendingApproval::query()
            ->where('ingest_key', PendingApproval::ingestKey($toolCallId, $conversationId))
            ->first();
    }

    /**
     * Record that a resume attempt is beginning, and say which attempt it is.
     *
     * **Locked rather than incremented-then-read.** `increment()` followed by a separate `value()`
     * is two statements: a concurrent attempt can land between them, and the caller is handed a
     * number that belongs to somebody else. That is fine for a metric and wrong for this, because
     * VC-10 reconciliation decides what to do from *which* attempt this is — a first attempt and a
     * fourth call for different action.
     *
     * The transaction is the console's own table only. It must not wrap a Verdict mutation:
     * `SecurityStateTransaction` refuses an outer transaction, by design (design §12).
     *
     * @return int this caller's attempt number, counting from 1
     */
    public function beginResumeAttempt(PendingApproval $approval): int
    {
        return DB::transaction(function () use ($approval): int {
            $locked = PendingApproval::query()
                ->whereKey($approval->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $attempt = $locked->resume_attempts + 1;

            $locked->forceFill([
                'resume_attempts' => $attempt,
                'last_resume_attempt_at' => now(),
            ])->save();

            return $attempt;
        });
    }

    /**
     * The row holding a given Verdict receipt, if any.
     *
     * Returns a row, never a receipt *status* — that is read live from Verdict, and this table holds
     * no copy of it. (Design §5.)
     */
    public function findByReceipt(string $receiptId): ?PendingApproval
    {
        return PendingApproval::query()->where('receipt_id', $receiptId)->first();
    }
}
