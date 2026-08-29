<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Listeners;

use Fissible\VerdictConsole\Evidence\ConversationInvocationStore;
use Laravel\Ai\Events\AgentPrompted;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Projects remembered completion invocations for evidence correlation.
 *
 * `AgentPrompted` and `AgentStreamed` are the boundaries: approval events fire later in the same
 * gateway call with the same response, so observing them would only repeat a completion already
 * recorded here. Completion also covers invocations that never pause and therefore never emit one.
 */
final readonly class RecordConversationInvocation
{
    public function __construct(
        private ConversationInvocationStore $invocations,
        private LoggerInterface $logger,
    ) {}

    public function handle(AgentPrompted $event): void
    {
        $conversationId = $event->response->conversationId;

        if ($conversationId === null) {
            return;
        }

        try {
            $row = $this->invocations->record($event->invocationId, $conversationId);
        } catch (Throwable $e) {
            // This is a read-model write: losing it must never change the agent's response.
            $this->logger->error('Verdict Console could not record an invocation conversation correlation.', [
                'invocation_id' => $event->invocationId,
                'exception' => $e::class,
            ]);

            return;
        }

        if ($row->conversation_id !== $conversationId) {
            $this->logger->warning('Verdict Console observed conflicting conversations for an invocation.', [
                'invocation_id' => $event->invocationId,
                'recorded_conversation_id' => $row->conversation_id,
                'observed_conversation_id' => $conversationId,
            ]);
        }
    }
}
