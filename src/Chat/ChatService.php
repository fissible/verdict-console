<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Chat;

use Fissible\VerdictConsole\Contracts\ChatEntry;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\AgentResponse;
use LogicException;

/**
 * Starts, continues, and reads participant-owned chats through host-owned Laravel AI seams.
 *
 * Ownership means the Laravel AI conversation belongs to the current participant. A refusal is a
 * single message so that a participant cannot learn whether a foreign conversation exists. The
 * console owns no conversation or message table: the host-bound Laravel AI store is the source for
 * both ownership and rendering. Continuation deliberately uses the participant's current entry
 * key. In v1 the console stores no per-conversation agent key, so a host re-pointing its entry also
 * re-points existing threads.
 */
final readonly class ChatService
{
    private const string AUTHORIZATION_REFUSAL_MESSAGE = 'This participant may not use this conversation.';

    public function __construct(
        private ChatEntry $entry,
        private ResumableAgents $agents,
        private ConversationStore $conversations,
    ) {}

    public function start(Authenticatable $user, string $prompt): ChatTurn
    {
        $this->assertPrompt($prompt);

        $participant = $this->entry->participantFor($user);
        $agent = $this->agents->resolve($this->entry->entryKeyFor($participant));

        return $this->turnFrom($agent->forParticipant($participant)->prompt($prompt));
    }

    public function continue(Authenticatable $user, string $conversationId, string $prompt): ChatTurn
    {
        $this->assertPrompt($prompt);

        $participant = $this->entry->participantFor($user);
        $this->assertOwns($conversationId, $participant);

        $agent = $this->agents->resolve($this->entry->entryKeyFor($participant));

        return $this->turnFrom($agent->continue($conversationId, $participant)->prompt($prompt));
    }

    public function thread(Authenticatable $user, string $conversationId, int $limit = 100): ChatThread
    {
        $participant = $this->entry->participantFor($user);
        $this->assertOwns($conversationId, $participant);

        $messages = array_values($this->conversations->getLatestConversationMessages($conversationId, $limit)
            ->map(fn (object $message): ChatMessage => new ChatMessage($message->role->value, $message->content))
            ->all());

        return new ChatThread($conversationId, $messages);
    }

    private function assertPrompt(string $prompt): void
    {
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('A chat prompt must not be blank.');
        }
    }

    private function assertOwns(string $conversationId, object $participant): void
    {
        $owns = Conversation::query()
            ->whereKey($conversationId)
            ->where('participant_type', Conversation::participantType($participant))
            ->where('participant_id', Conversation::participantKey($participant))
            ->exists();

        if (! $owns) {
            throw new AuthorizationException(self::AUTHORIZATION_REFUSAL_MESSAGE);
        }
    }

    private function turnFrom(AgentResponse $response): ChatTurn
    {
        if ($response->conversationId === null) {
            throw new LogicException('A remembered conversation response must have a conversation id.');
        }

        return new ChatTurn(
            $response->conversationId,
            $response->invocationId,
            $response->text,
            $response->hasPendingApprovals(),
            array_values($response->pendingApprovals->map(fn (object $approval): string => $approval->id)->all()),
        );
    }
}
