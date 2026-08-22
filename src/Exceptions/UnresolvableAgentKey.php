<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use Throwable;

/**
 * A key that does not rebuild an agent — either unknown, or known and broken.
 *
 * The two are deliberately one type with different messages. To the ingestion path they mean the
 * same thing (this row is not drivable); to an operator they read differently, and the message says
 * which. A failing factory keeps its original throwable as `previous`, because "the resolver threw"
 * without the cause is the least actionable possible incident.
 */
final class UnresolvableAgentKey extends ResumableAgentFailure
{
    public static function unknown(string $key): self
    {
        return new self(sprintf(
            'No resumable-agent resolver is registered for key [%s]. If rows still reference it, the '
            .'key was retired too early; if none do, the row predates a key migration.',
            $key,
        ));
    }

    public static function failed(string $key, Throwable $cause): self
    {
        return new self(
            sprintf('The resumable-agent resolver for key [%s] failed: %s', $key, $cause->getMessage()),
            previous: $cause,
        );
    }

    public static function notConversational(string $key, object $produced): self
    {
        return new self(sprintf(
            'The resolver for key [%s] produced [%s], which is not a conversational agent. A resume '
            .'needs continue(), and Laravel AI will not pause an agent that is not Conversational at '
            .'all — so an agent reaching here without it could never have produced this pause.',
            $key,
            $produced::class,
        ));
    }
}
