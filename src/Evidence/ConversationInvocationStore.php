<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

use Illuminate\Database\UniqueConstraintViolationException;

/** Writes the console's immutable invocation-to-conversation projection without operator scoping. */
final class ConversationInvocationStore
{
    /**
     * Record an observation, or return the first observation already recorded for the invocation.
     *
     * The primary key, rather than a check-then-insert, arbitrates concurrent redelivery. A later
     * conflicting conversation is evidence of an upstream inconsistency, not permission to rewrite
     * the historical observation, so the first row always stands.
     */
    public function record(string $invocationId, string $conversationId): ConversationInvocation
    {
        $now = now();

        try {
            ConversationInvocation::query()->insert([
                'invocation_id' => $invocationId,
                'conversation_id' => $conversationId,
                'observed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $existing = ConversationInvocation::query()->whereKey($invocationId)->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        return ConversationInvocation::query()->whereKey($invocationId)->sole();
    }

    /** @return list<string> */
    public function invocationIdsFor(string $conversationId): array
    {
        return array_values(ConversationInvocation::query()
            ->where('conversation_id', $conversationId)
            ->pluck('invocation_id')
            ->map(static fn (mixed $invocationId): string => (string) $invocationId)
            ->all());
    }
}
