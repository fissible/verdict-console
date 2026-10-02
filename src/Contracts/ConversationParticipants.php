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
 * **The bar is this console's, and it is exact (#129).** laravel/ai 1.0 resumes a paused turn
 * without checking the participant, so a wrong or missing rebuild no longer strands anything — it
 * completes silently, with the live response and events carrying an identity the pause never
 * recorded. The console refuses to set that up: a participant-bound pause is drivable only when
 * the host can reproduce, at ingestion, exactly the identity Laravel AI captured.
 *
 * An implementation therefore satisfies this contract only when, for every participant it is given:
 *
 * - `Laravel\Ai\Models\Conversation::participantType(resolve(referenceFor($p)))` equals
 *   `Conversation::participantType($p)` — the morph class for an Eloquent model, `::class` otherwise;
 * - `Conversation::participantKey(resolve(referenceFor($p)))` equals `Conversation::participantKey($p)`
 *   — the model key, or the object's `id` property.
 *
 * The bridge round-trips both at ingestion and compares them **strictly**, so a key rebuilt as `'7'`
 * where the original was `7` is recorded as `participant_unresolvable`. Deliberate (#129): a
 * type-lossy reference is evidence the host's derivation does not reproduce the identity it was
 * given, and loosening the comparison would paper over exactly the ambiguity ('7', '07', 7) this
 * policy exists to refuse. A false `unresumable` is a row an operator can act on; a lossy rebuild
 * resumed anyway is an identity substitution nothing would surface. Reconstruct the key in its
 * original type.
 */
interface ConversationParticipants
{
    /** Return an opaque durable reference for this participant. */
    public function referenceFor(object $participant): string;

    /** Rebuild the exact participant an opaque reference names — same Laravel AI type and key. */
    public function resolve(string $reference): object;
}
