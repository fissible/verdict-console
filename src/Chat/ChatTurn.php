<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Chat;

/** The display-safe result of one console prompt. */
final readonly class ChatTurn
{
    /** @param list<string> $pendingToolCallIds */
    public function __construct(
        public string $conversationId,
        public string $invocationId,
        public string $text,
        public bool $paused,
        public array $pendingToolCallIds,
    ) {}
}
