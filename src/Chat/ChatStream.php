<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Chat;

use Generator;
use IteratorAggregate;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use LogicException;
use Traversable;

/**
 * A single consumption of Laravel AI's events, followed by its completed chat turn.
 *
 * @implements IteratorAggregate<int, StreamEvent>
 */
final class ChatStream implements IteratorAggregate
{
    private bool $started = false;

    private ?ChatTurn $turn = null;

    public function __construct(private readonly StreamableAgentResponse $response) {}

    /** @return Traversable<int, StreamEvent> */
    public function getIterator(): Traversable
    {
        if ($this->started) {
            throw new LogicException('A chat stream may only be consumed once.');
        }

        $this->started = true;

        return $this->consume();
    }

    public function turn(): ChatTurn
    {
        if ($this->turn === null) {
            throw new LogicException('A chat turn is available only after successful stream exhaustion.');
        }

        return $this->turn;
    }

    /** @return Generator<int, StreamEvent, mixed, void> */
    private function consume(): Generator
    {
        $paused = false;
        $pendingToolCallIds = [];

        foreach ($this->response as $event) {
            if ($event instanceof ToolApprovalRequest) {
                $paused = true;

                foreach ($event->pendingApprovals as $approval) {
                    $pendingToolCallIds[] = $approval->id;
                }
            }

            yield $event;
        }

        // Exhaustion, including the vendor's completion callbacks, persists the conversation
        // and ingests approvals. Yielding StreamEnd alone does not cross that boundary.
        if ($this->response->conversationId === null || $this->response->text === null) {
            throw new LogicException('A completed chat stream must have a conversation id and text.');
        }

        $this->turn = new ChatTurn(
            $this->response->conversationId,
            $this->response->invocationId,
            $this->response->text,
            $paused,
            $pendingToolCallIds,
        );
    }
}
