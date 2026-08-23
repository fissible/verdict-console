<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Contracts;

/**
 * Host-owned durable identity for Laravel AI conversation participants.
 *
 * Laravel AI hands the pause listener a live object. It is neither durable nor safe for this package
 * to reduce to a class name and an id, so a host whose runs are participant-bound supplies an opaque
 * reference and its inverse. Both methods are non-null: a participant-bound pause needs the exact
 * participant back at resume, not merely some object.
 *
 * **The bar is Laravel AI's, and it is exact.** `DatabaseConversationStore::storeApprovalResults()`
 * re-finds the paused assistant turn by `participant_type` **and** `participant_id` alongside the
 * conversation id — and when the resuming agent carries no participant it requires *both columns to
 * be null*, rather than skipping the filter. So a participant-bound turn resumed without its
 * participant is not merely unmatched, it is excluded, and the resume raises
 * `ApprovalMismatchException` after the receipt has already been approved.
 *
 * An implementation therefore satisfies this contract only when, for every participant it is given:
 *
 * - `Laravel\Ai\Models\Conversation::participantType(resolve(referenceFor($p)))` equals
 *   `Conversation::participantType($p)` — the morph class for an Eloquent model, `::class` otherwise;
 * - `Conversation::participantKey(resolve(referenceFor($p)))` equals `Conversation::participantKey($p)`
 *   — the model key, or the object's `id` property.
 *
 * The bridge round-trips both at ingestion and compares them **strictly**, so a key rebuilt as `'7'`
 * where the original was `7` is recorded as `participant_unresolvable`. That is deliberately stricter
 * than the database comparison a resume would actually perform: a false `unresumable` is a row an
 * operator can act on, while a false `drivable` strands an approved receipt. Reconstruct the key in
 * its original type.
 */
interface ConversationParticipants
{
    /** Return an opaque durable reference for this participant. */
    public function referenceFor(object $participant): string;

    /** Rebuild the exact participant an opaque reference names — same Laravel AI type and key. */
    public function resolve(string $reference): object;
}
