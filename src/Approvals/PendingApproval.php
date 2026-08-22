<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use Illuminate\Database\Eloquent\Model;

/**
 * The console's queryable index of paused approvals.
 *
 * Verdict's `ApprovalReceiptStore` exposes `findForToolCall()` and the transition methods, but no
 * `find(receiptId)` and no list or query API — so an inbox cannot be built by enumerating receipts.
 * This table is that index. It is not a second authorization authority and holds no copy of the
 * receipt's status: authoritative state is read live via `ApprovalManager::challengeForToolCall()`.
 * (Design §5, §6.1.)
 *
 * @property string $id
 * @property string $ingest_key
 * @property string|null $receipt_id
 * @property string $tool_call_id
 * @property string|null $conversation_id
 * @property string|null $participant_reference
 * @property string|null $invocation_id
 * @property string|null $resolver_key
 * @property array<string, mixed>|null $presentation
 * @property Resumability $resumability
 * @property UnresumableReason|null $unresumable_reason
 */
final class PendingApproval extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'verdict_console_pending_approvals';

    protected $guarded = [];

    /**
     * The deterministic ingest key for a pause.
     *
     * The natural key is the tool call plus its conversation, but `conversation_id` is nullable and
     * a UNIQUE index does not constrain rows where a column is NULL — two NULLs never compare
     * equal, so a redelivered event for a conversationless pause would insert a second row. Hashing
     * both into one non-null column is what makes redelivery collide. The sentinel is explicit so a
     * literal conversation id of `"-"` cannot impersonate absence.
     */
    public static function ingestKey(string $toolCallId, ?string $conversationId): string
    {
        return hash('sha256', json_encode([
            'tool_call_id' => $toolCallId,
            'conversation_id' => $conversationId,
            'conversation_present' => $conversationId !== null,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'presentation' => 'array',
            'resumability' => Resumability::class,
            'unresumable_reason' => UnresumableReason::class,
        ];
    }
}
