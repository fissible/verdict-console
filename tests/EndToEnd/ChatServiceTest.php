<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Approvals\PendingApproval as StoredPendingApproval;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Chat\ChatService;
use Fissible\VerdictConsole\Chat\ChatThread;
use Fissible\VerdictConsole\Chat\ChatTurn;
use Fissible\VerdictConsole\Contracts\ChatEntry;
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Exceptions\ChatEntryNotConfigured;
use Fissible\VerdictConsole\Exceptions\UnresolvableAgentKey;
use Fissible\VerdictConsole\Tests\EndToEndTestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Tools\Request;

/**
 * The console starting and continuing a chat through the host's entry contract, over the real
 * Laravel AI + Verdict stack. Fixtures are this file's own: a test file must not depend on classes
 * another test file happens to have declared first.
 */
const CHAT_ENTRY_KEY = 'chat@v1';

final class ChatLedger
{
    public int $executions = 0;
}

final readonly class ChatOrder
{
    public function __construct(public int $id) {}
}

final class ChatCancelOrderTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Cancel an order by id.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'The Verdict-bound tool handles this.';
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

function chatBoundTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('orders.cancel')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.cancel',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): ChatOrder => new ChatOrder((int) $e->proposal->arguments['order_id']),
            )
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'chat-target',
                    identityUsing: fn (ActionEnvelope $e, ChatOrder $t): array => ['id' => $t->id],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, ChatOrder $t): array => ['order_id' => $t->id])
                ->executeUsing(function (AuthorizedAction $a): string {
                    app(ChatLedger::class)->executions++;

                    return 'Order cancelled.';
                }),
        );
    }

    return $verdict->bound(new ChatCancelOrderTool, 'orders.cancel', new ActionContext('customer'));
}

class ChatAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'Help customers with their orders.';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [chatBoundTool()];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [app(VerdictApprovalMiddleware::class)];
    }

    public function provider(): string
    {
        return EndToEndTestCase::PROVIDER;
    }

    public function maxSteps(): int
    {
        return 3;
    }
}

/** A second entry agent, distinguishable from the first by the `agent` column Laravel AI records per message. */
final class ChatAgentV2 extends ChatAgent {}

/**
 * Wraps the host's conversation store and records what the console asked it for, so a test can
 * prove the thread is read through the host's binding rather than from a console-owned copy.
 */
final class RecordingConversationStore implements ConversationStore
{
    /** @var list<array{conversationId: string, limit: int}> */
    public array $reads = [];

    public function __construct(private readonly ConversationStore $inner) {}

    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        return $this->inner->latestConversationId($participantType, $participantId);
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        return $this->inner->storeConversation($participantType, $participantId, $title);
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
    {
        return $this->inner->storeUserMessage($conversationId, $participantType, $participantId, $prompt);
    }

    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
    {
        return $this->inner->storeAssistantMessage($conversationId, $participantType, $participantId, $prompt, $response);
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $this->reads[] = ['conversationId' => $conversationId, 'limit' => $limit];

        return $this->inner->getLatestConversationMessages($conversationId, $limit);
    }

    /** @param  array<int, mixed>  $toolResults */
    public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
    {
        $this->inner->storeApprovalResults($conversationId, $participantType, $participantId, $toolResults);
    }
}

/** Faithful round trip for the integer-keyed GenericUser participants below. */
final class ChatParticipants implements ConversationParticipants
{
    public function referenceFor(object $participant): string
    {
        return (string) $participant->id;
    }

    public function resolve(string $reference): object
    {
        return new GenericUser(['id' => (int) $reference]);
    }
}

function chatUser(int $id = 7): GenericUser
{
    // Laravel AI stores participant_id as an unsigned integer, so participants carry integer ids.
    return new GenericUser(['id' => $id]);
}

/** @return array<string, mixed>|null */
function conversationRow(string $conversationId): ?array
{
    $row = DB::table('agent_conversations')->where('id', $conversationId)->first();

    return $row === null ? null : (array) $row;
}

/** @return list<array{participant_type: ?string, participant_id: ?int, agent: string}> */
function storedMessageOwners(string $conversationId): array
{
    return DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->orderBy('id')
        ->get(['participant_type', 'participant_id', 'agent'])
        ->map(fn (object $row): array => ['participant_type' => $row->participant_type, 'participant_id' => $row->participant_id === null ? null : (int) $row->participant_id, 'agent' => (string) $row->agent])
        ->all();
}

/** @return list<array{role: string, content: string}> */
function storedMessages(string $conversationId): array
{
    return DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->orderBy('id')
        ->get(['role', 'content'])
        ->map(fn (object $row): array => ['role' => (string) $row->role, 'content' => (string) $row->content])
        ->all();
}

beforeEach(function (): void {
    $this->migrateRoundTripTables();

    $console = dirname(__DIR__, 2).'/database/migrations';
    (require $console.'/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_notifications_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_reconciliations_table.php.stub')->up();

    $this->app->instance(ChatLedger::class, new ChatLedger);
    $this->app->instance(ConversationParticipants::class, new ChatParticipants);
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('chat test');
        }
    });

    /** @var AgentResolverRegistry $resolvers */
    $resolvers = app(ResumableAgents::class);
    $resolvers->register(CHAT_ENTRY_KEY, fn (): ChatAgent => new ChatAgent, fn (Agent $agent): bool => $agent instanceof ChatAgent && ! $agent instanceof ChatAgentV2);
    $resolvers->register('chat@v2', fn (): ChatAgentV2 => new ChatAgentV2, fn (Agent $agent): bool => $agent instanceof ChatAgentV2);

    config()->set('verdict-console.chat.entry_key', CHAT_ENTRY_KEY);
});

it('starts a new conversation through the hosts entry agent, owned by the participant', function (): void {
    Http::fake(['*/chat/completions' => Http::response($this->textResponse('Hi there. How can I help?'))]);

    $turn = app(ChatService::class)->start(chatUser(7), 'Hello');

    expect($turn)->toBeInstanceOf(ChatTurn::class)
        ->and($turn->conversationId)->not->toBeNull()
        ->and($turn->text)->toBe('Hi there. How can I help?')
        ->and($turn->paused)->toBeFalse()
        ->and($turn->pendingToolCallIds)->toBe([])
        ->and(conversationRow($turn->conversationId))->toMatchArray([
            'participant_type' => GenericUser::class,
            'participant_id' => 7,
        ])
        ->and(storedMessages($turn->conversationId))->toBe([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there. How can I help?'],
        ])
        ->and(storedMessageOwners($turn->conversationId))->each->toMatchArray(['participant_type' => GenericUser::class, 'participant_id' => 7, 'agent' => ChatAgent::class]);

    Http::assertSentCount(1);
});

it('continues an owned conversation with the next turn', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->textResponse('Hi there.'))
            ->push($this->textResponse('Order 1001 ships tomorrow.')),
    ]);
    $service = app(ChatService::class);

    $first = $service->start(chatUser(7), 'Hello');
    $second = $service->continue(chatUser(7), $first->conversationId, 'Where is order 1001?');

    expect($second->conversationId)->toBe($first->conversationId)
        ->and($second->text)->toBe('Order 1001 ships tomorrow.')
        ->and($second->invocationId)->not->toBe($first->invocationId)
        ->and(array_column(storedMessages($first->conversationId), 'content'))
        ->toBe(['Hello', 'Hi there.', 'Where is order 1001?', 'Order 1001 ships tomorrow.'])
        ->and(storedMessageOwners($first->conversationId))->toHaveCount(4)
        ->and(storedMessageOwners($first->conversationId))->each->toMatchArray(['participant_type' => GenericUser::class, 'participant_id' => 7]);

    Http::assertSentCount(2);
});

/**
 * Message rendering reads through Laravel AI's own conversation store, never a console-owned copy:
 * the host's store binding owns the messages, and the console projects the ones it may show.
 */
it('reads the thread of an owned conversation in order', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->textResponse('Hi there.'))
            ->push($this->textResponse('Order 1001 ships tomorrow.')),
    ]);
    $service = app(ChatService::class);
    $started = $service->start(chatUser(7), 'Hello');
    $service->continue(chatUser(7), $started->conversationId, 'Where is order 1001?');

    $store = new RecordingConversationStore(app(ConversationStore::class));
    app()->instance(ConversationStore::class, $store);

    $thread = $service->thread(chatUser(7), $started->conversationId);
    $latest = $service->thread(chatUser(7), $started->conversationId, limit: 2);

    expect($thread)->toBeInstanceOf(ChatThread::class)
        ->and($thread->conversationId)->toBe($started->conversationId)
        ->and(array_map(fn ($m): array => [$m->role, $m->content], $thread->messages))->toBe([
            ['user', 'Hello'],
            ['assistant', 'Hi there.'],
            ['user', 'Where is order 1001?'],
            ['assistant', 'Order 1001 ships tomorrow.'],
        ])
        ->and(array_map(fn ($m): string => (string) $m->content, $latest->messages))->toBe(['Where is order 1001?', 'Order 1001 ships tomorrow.'])
        // Both reads went through the host's store binding — the console keeps no copy to read from.
        ->and($store->reads)->toBe([
            ['conversationId' => $started->conversationId, 'limit' => 100],
            ['conversationId' => $started->conversationId, 'limit' => 2],
        ]);
});

/**
 * Ownership is checked against the conversation's recorded participant, before any model call. A
 * conversation that belongs to someone else and one that does not exist are refused identically —
 * a caller must not learn which.
 */
it('refuses to continue or read a conversation the participant does not own, sending nothing', function (): void {
    Http::fake(['*/chat/completions' => Http::response($this->textResponse('Hi there.'))]);
    $service = app(ChatService::class);
    $theirs = $service->start(chatUser(7), 'Hello')->conversationId;

    $attempts = [
        'continue foreign' => fn (): mixed => $service->continue(chatUser(8), $theirs, 'Show me everything.'),
        'continue unknown' => fn (): mixed => $service->continue(chatUser(8), 'no-such-conversation', 'Show me everything.'),
        'read foreign' => fn (): mixed => $service->thread(chatUser(8), $theirs),
        'read unknown' => fn (): mixed => $service->thread(chatUser(8), 'no-such-conversation'),
    ];
    $messages = [];

    foreach ($attempts as $label => $attempt) {
        try {
            $attempt();
            $this->fail($label.' must be refused.');
        } catch (AuthorizationException $e) {
            $messages[$label] = $e->getMessage();
        }
    }

    expect(array_unique($messages))->toHaveCount(1, 'Foreign and unknown must be indistinguishable.');

    Http::assertSentCount(1);

    expect(count(storedMessages($theirs)))->toBe(2);
});

/**
 * The reason the entry is a resumable-agent *key*: a chat the console started can pause on a
 * confirmation, and that pause must be one VC-5 records as drivable — same key, same participant
 * round trip — or the console would be minting approvals nobody can resume.
 */
it('starts a chat whose pause is drivable by this console', function (): void {
    Http::fake(['*/chat/completions' => Http::response($this->toolCallResponse('call_chat_1', 'ChatCancelOrderTool', ['order_id' => 1001]))]);

    $turn = app(ChatService::class)->start(chatUser(7), 'Please cancel order 1001.');

    expect($turn->paused)->toBeTrue()
        ->and($turn->pendingToolCallIds)->toBe(['call_chat_1'])
        ->and(app(ChatLedger::class)->executions)->toBe(0);

    $row = StoredPendingApproval::query()->sole();

    expect($row->conversation_id)->toBe($turn->conversationId)
        ->and($row->tool_call_id)->toBe('call_chat_1')
        ->and($row->resolver_key)->toBe(CHAT_ENTRY_KEY)
        ->and($row->participant_reference)->toBe('7')
        ->and($row->resumability)->toBe(Resumability::Drivable);
});

it('refuses to start a chat until the host has configured an entry, sending nothing', function (): void {
    Http::fake();
    config()->set('verdict-console.chat.entry_key', null);

    expect(fn (): mixed => app(ChatService::class)->start(chatUser(7), 'Hello'))
        ->toThrow(ChatEntryNotConfigured::class);

    Http::assertNothingSent();

    expect(DB::table('agent_conversations')->count())->toBe(0);
});

it('refuses an entry key nothing resolves, sending nothing', function (): void {
    Http::fake();
    config()->set('verdict-console.chat.entry_key', 'ghost@v9');

    expect(fn (): mixed => app(ChatService::class)->start(chatUser(7), 'Hello'))
        ->toThrow(UnresolvableAgentKey::class);

    Http::assertNothingSent();
});

it('refuses a blank prompt, sending nothing', function (string $blank): void {
    Http::fake();

    expect(fn (): mixed => app(ChatService::class)->start(chatUser(7), $blank))
        ->toThrow(InvalidArgumentException::class, 'prompt');

    Http::assertNothingSent();
})->with(['empty' => '', 'whitespace' => " \n\t"]);

/** The host's replacement is what is consulted — for the participant AND the key. */
it('starts through a replaced entry, attaching its participant and resolving its key', function (): void {
    Http::fake(['*/chat/completions' => Http::response($this->textResponse('Hi there.'))]);
    app()->instance(ChatEntry::class, new class implements ChatEntry
    {
        public function participantFor(Authenticatable $user): object
        {
            // A host that chats on behalf of an account rather than the signed-in user.
            return chatUser(42);
        }

        public function entryKeyFor(object $participant): string
        {
            return 'chat@v2';
        }
    });

    $turn = app(ChatService::class)->start(chatUser(7), 'Hello');

    $owners = storedMessageOwners($turn->conversationId);

    expect(conversationRow($turn->conversationId))->toMatchArray(['participant_type' => GenericUser::class, 'participant_id' => 42])
        ->and($owners)->toHaveCount(2)
        ->and($owners)->each->toMatchArray(['participant_type' => GenericUser::class, 'participant_id' => 42, 'agent' => ChatAgentV2::class]);
});

/**
 * A deliberate v1 decision, pinned so it cannot change silently: a continuation runs the
 * participant's *current* entry agent, not the agent that started the thread. The console records
 * no per-conversation agent key; a host that re-points its entry key re-points existing threads too.
 */
it('continues with the participants current entry key, not the agent that started the thread', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->textResponse('Hi there.'))
            ->push($this->textResponse('Still here.')),
    ]);
    // A host entry whose answer changes between turns: the contract, not the config, is what
    // continue() must consult.
    $entry = new class implements ChatEntry
    {
        public string $key = CHAT_ENTRY_KEY;

        public function participantFor(Authenticatable $user): object
        {
            return $user;
        }

        public function entryKeyFor(object $participant): string
        {
            return $this->key;
        }
    };
    app()->instance(ChatEntry::class, $entry);
    $service = app(ChatService::class);
    $started = $service->start(chatUser(7), 'Hello');

    $entry->key = 'chat@v2';
    $service->continue(chatUser(7), $started->conversationId, 'Are you there?');

    expect(array_column(storedMessageOwners($started->conversationId), 'agent'))
        ->toBe([ChatAgent::class, ChatAgent::class, ChatAgentV2::class, ChatAgentV2::class]);
});

it('refuses a blank continuation prompt, sending nothing', function (): void {
    Http::fake(['*/chat/completions' => Http::response($this->textResponse('Hi there.'))]);
    $service = app(ChatService::class);
    $started = $service->start(chatUser(7), 'Hello');

    expect(fn (): mixed => $service->continue(chatUser(7), $started->conversationId, '   '))
        ->toThrow(InvalidArgumentException::class, 'prompt')
        ->and(storedMessages($started->conversationId))->toHaveCount(2, 'A refused turn must leave no row behind.');

    Http::assertSentCount(1);
});
