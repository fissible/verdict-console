<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Writes and reads the console's index of paused approvals.
 *
 * The only interesting thing here is `ingest()`, and the only interesting thing about `ingest()` is
 * that it does not read before it writes.
 */
final class PendingApprovalStore
{
    private ApprovalScope $scope;

    /**
     * Null until the first insert checks whether the host has run VC-68's published migration.
     *
     * Composer can update package code before the host runs its migrations. During that interval
     * this console must still index a pause rather than fail every ingestion; after migration, a
     * worker restart gives the process the new schema as usual.
     */
    private ?bool $hasApprovalContextColumn = null;

    public function __construct(?ApprovalScope $scope = null)
    {
        // Direct construction remains neutral for package consumers that only write or test rows;
        // container resolution receives the host binding, which is the runtime boundary for reads.
        $this->scope = $scope ?? new UnscopedApprovalScope;
    }

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
     * @param  array<string, string|int>|null  $approvalContext  Host/application-owned binding
     *                                                           identifiers, captured verbatim. Like every other ingest field, it is first-write-wins.
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
        ?array $approvalContext = null,
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
            approvalContext: $approvalContext,
        )->pendingApproval;
    }

    /**
     * Record a pause and say whether this call created its row.
     *
     * The listener needs this narrower outcome to make its incident dispatch idempotent under a
     * concurrent redelivery. Callers that only need the durable row use {@see ingest()}.
     *
     * @param  array<string, mixed>|null  $presentation
     * @param  array<string, string|int>|null  $approvalContext  Host/application-owned binding
     *                                                           identifiers, captured verbatim. Like every other ingest field, it is first-write-wins.
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
        ?array $approvalContext = null,
    ): PendingApprovalIngestion {
        $ingestKey = PendingApproval::ingestKey($toolCallId, $conversationId);
        $now = now();

        try {
            $attributes = [
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
            ];

            if ($this->hasApprovalContextColumn()) {
                $attributes['approval_context'] = $approvalContext === null ? null : json_encode($approvalContext, JSON_THROW_ON_ERROR);
            }

            PendingApproval::query()->insert($attributes);
        } catch (UniqueConstraintViolationException $e) {
            // Read back by the natural key rather than trusting the id generated above: whoever won
            // the race wrote a different one, and theirs is the row that matters.
            $existing = $this->correlationQuery()->where('ingest_key', $ingestKey)->first();

            if ($existing === null) {
                throw $e;
            }

            return new PendingApprovalIngestion($existing, false);
        }

        return new PendingApprovalIngestion(
            $this->correlationQuery()->where('ingest_key', $ingestKey)->sole(),
            true,
        );
    }

    /**
     * Whether the current host schema includes VC-68's published column.
     *
     * The result is deliberately memoized. A long-running worker started before the migration must
     * be restarted after it, just as it must be for any schema change.
     */
    private function hasApprovalContextColumn(): bool
    {
        $connection = PendingApproval::query()->getConnection();

        if (! $connection instanceof Connection) {
            throw new LogicException('The pending approval connection does not support schema inspection.');
        }

        return $this->hasApprovalContextColumn ??= $connection
            ->getSchemaBuilder()
            ->hasColumn((new PendingApproval)->getTable(), 'approval_context');
    }

    /**
     * The row recording a given pause, if this console has seen it.
     */
    public function findByToolCall(string $toolCallId, ?string $conversationId = null): ?PendingApproval
    {
        return $this->correlationQuery()
            ->where('ingest_key', PendingApproval::ingestKey($toolCallId, $conversationId))
            ->first();
    }

    /**
     * Find an approval only when the host's operator scope currently exposes it.
     *
     * Unlike the correlation reads, this read is scoped because it serves an operator action.
     */
    public function findVisible(string $id): ?PendingApproval
    {
        return $this->scope->apply(PendingApproval::query())->find($id);
    }

    /**
     * List the approvals the host currently exposes to an operator, newest first.
     *
     * Unlike correlation reads, this list is an operator surface and therefore always scoped.
     *
     * @return list<PendingApproval>
     */
    public function visible(): array
    {
        return array_values($this->scope->apply(PendingApproval::query())
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get()
            ->all());
    }

    /**
     * List one conversation's approvals inside the host's visibility boundary, newest first.
     *
     * The conversation predicate belongs in the query rather than a post-read filter: a thread
     * must neither render nor ask Verdict to resolve rows belonging to another conversation.
     *
     * @return list<PendingApproval>
     */
    public function visibleForConversation(string $conversationId): array
    {
        return array_values($this->scope->apply(PendingApproval::query())
            ->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->get()
            ->all());
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
            $locked = $this->correlationQuery()
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
        return $this->correlationQuery()->where('receipt_id', $receiptId)->first();
    }

    /**
     * Find the indexed pause named by one observed Verdict receipt transition.
     *
     * Both identities are required. A tool-call id can be reused only by a malformed or stale
     * delivery, and a receipt id alone must never attach an observation to a different pause.
     */
    public function findByTransition(string $toolCallId, string $receiptId): ?PendingApproval
    {
        return $this->correlationQuery()
            ->where('tool_call_id', $toolCallId)
            ->where('receipt_id', $receiptId)
            ->first();
    }

    /**
     * Whether this approval remains inside the host's current query boundary.
     *
     * Actions accept a row because a surface just rendered it, but that row can outlive a tenant
     * switch. Re-reading only its key through the scope prevents a stale or injected model from
     * becoming an authorization bypass.
     */
    public function isVisible(PendingApproval $approval): bool
    {
        return $this->scope->apply(PendingApproval::query())->whereKey($approval->getKey())->exists();
    }

    /** @return Builder<PendingApproval> */
    private function correlationQuery(): Builder
    {
        // Queue listeners must be able to correlate Laravel AI events even when they have no
        // operator tenant context. Scope decides what a human may see or act on, not whether a
        // console-owned pause can be written, locked, or joined to its framework event.
        return PendingApproval::query();
    }
}
