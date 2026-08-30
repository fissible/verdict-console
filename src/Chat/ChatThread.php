<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Chat;

/** @param list<ChatMessage> $messages */
final readonly class ChatThread
{
    /** @param list<ChatMessage> $messages */
    public function __construct(public string $conversationId, public array $messages) {}
}
