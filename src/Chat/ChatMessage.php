<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Chat;

/** One stored Laravel AI message projected for a console chat thread. */
final readonly class ChatMessage
{
    public function __construct(public string $role, public ?string $content) {}
}
