<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Chat\ChatService;
use Fissible\VerdictConsole\Chat\ChatStream;
use Fissible\VerdictConsole\Chat\ChatTurn;
use Fissible\VerdictConsole\Contracts\ChatEntry;
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Exceptions\UnresolvableAgentKey;
use Fissible\VerdictConsole\Tests\EndToEndTestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Promptable;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolApprovalRequest;
use Laravel\Ai\Tools\Request;

/*
 * #97: token streaming through the core, so an adapter never has to duplicate the authorization
 * seams ChatService owns. `ChatService::stream()` mirrors start/continue — the blank-prompt guard,
 * ownership, and the ChatEntry participant/key seam — and returns a ChatStream: an iterator
 * relaying Laravel AI's own stream events chunk-by-chunk, with the finished turn afterwards
 * surfaced exactly the way ChatTurn surfaces it today.
 *
 * The seam's shape follows the rule-5 measurement over the faked SSE gateway, not the non-streamed
 * path. The measured boundaries: a Verdict-gated pause arrives mid-stream as a ToolApprovalRequest
 * event carrying the pending approvals, with the Verdict receipt already issued and the gate
 * holding the tool unexecuted — but the console's ingestion row and the stored conversation land
 * only when the iterator is EXHAUSTED (Laravel AI finalizes in the stream's completion callback,
 * after StreamEnd is yielded). An adapter that stops consuming on the pause event never completes
 * ingestion. That is why the turn is a method that refuses until iteration has fully completed,
 * and why these tests pin both sides of that boundary.
 */

const STREAM_CHAT_KEY = 'stream-chat@v1';

final class StreamChatLedger
{
    public int $executions = 0;
}

final class StreamChatCancelTool implements Tool
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
        return ['order_id' => $schema->integer()->required()];
    }
}

function streamChatTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('stream.orders.cancel')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'stream.orders.cancel',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): object => new stdClass,
            )
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'stream-chat-target',
                    identityUsing: fn (ActionEnvelope $e, object $t): array => ['id' => 1],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, object $t): array => ['id' => 1])
                ->executeUsing(function (AuthorizedAction $a): string {
                    app(StreamChatLedger::class)->executions++;

                    return 'done';
                }),
        );
    }

    return $verdict->bound(new StreamChatCancelTool, 'stream.orders.cancel', new ActionContext('customer'));
}

class StreamChatAgent implements Agent, HasTools, RemembersConversationsContract
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
        return [streamChatTool()];
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

/** A second entry agent, distinguishable by the `agent` column Laravel AI records per message. */
final class StreamChatAgentV2 extends StreamChatAgent {}

/**
 * Deliberately not the identity mapping: user N is participant N + 35, so an implementation that
 * hands Laravel AI the authenticated user instead of the entry's participant cannot pass.
 */
final class StreamChatEntry implements ChatEntry
{
    public function participantFor(Authenticatable $user): object
    {
        return new GenericUser(['id' => (int) $user->getAuthIdentifier() + 35]);
    }

    public function entryKeyFor(object $participant): string
    {
        return STREAM_CHAT_KEY;
    }
}

final class StreamChatParticipants implements ConversationParticipants
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

function streamChatUser(int $id = 7): GenericUser
{
    return new GenericUser(['id' => $id]);
}

/** The same key under another participant type: ownership must compare type AND key. */
final class StreamChatOtherType
{
    public function __construct(public int $id) {}
}

/** A chat-completions SSE body: data: lines ending in [DONE], as the gateway's parser reads them. */
function chatSseBody(array $chunks): string
{
    $lines = array_map(fn (array $chunk): string => 'data: '.json_encode($chunk), $chunks);
    $lines[] = 'data: [DONE]';

    return implode("\n\n", $lines)."\n\n";
}

function chatSseText(string ...$deltas): string
{
    $chunks = [];
    foreach ($deltas as $delta) {
        $chunks[] = ['model' => EndToEndTestCase::MODEL, 'choices' => [['delta' => ['content' => $delta], 'finish_reason' => null]]];
    }
    $chunks[] = ['choices' => [['delta' => [], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]];

    return chatSseBody($chunks);
}

function chatSseToolCall(string $toolCallId, array $arguments): string
{
    return chatSseBody([
        ['model' => EndToEndTestCase::MODEL, 'choices' => [['delta' => ['tool_calls' => [[
            'index' => 0, 'id' => $toolCallId, 'function' => ['name' => 'StreamChatCancelTool', 'arguments' => ''],
        ]]], 'finish_reason' => null]]],
        ['choices' => [['delta' => ['tool_calls' => [[
            'index' => 0, 'function' => ['arguments' => json_encode($arguments)],
        ]]], 'finish_reason' => null]]],
        ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]],
    ]);
}

function sseHeaders(): array
{
    return ['Content-Type' => 'text/event-stream'];
}

beforeEach(function (): void {
    $this->migrateRoundTripTables();

    $console = dirname(__DIR__, 2).'/database/migrations';
    (require $console.'/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_notifications_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_reconciliations_table.php.stub')->up();

    $this->app->instance(StreamChatLedger::class, new StreamChatLedger);
    $this->app->instance(ChatEntry::class, new StreamChatEntry);
    $this->app->instance(ConversationParticipants::class, new StreamChatParticipants);
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('stream-chat');
        }
    });
    config()->set('verdict.approvals.authorizer', AllowAllApprovalAuthorizer::class);

    app(ResumableAgents::class)->register(
        STREAM_CHAT_KEY,
        fn (): StreamChatAgent => new StreamChatAgent,
        fn (Agent $agent): bool => $agent::class === StreamChatAgent::class,
    );
});

it('refuses a blank prompt before anything streams, in both modes', function (?string $conversationId): void {
    Http::fake();

    expect(fn () => app(ChatService::class)->stream(streamChatUser(), $conversationId, "  \n "))
        ->toThrow(InvalidArgumentException::class, 'A chat prompt must not be blank.');

    Http::assertNothingSent();
})->with([
    'start mode' => [null],
    'continue mode' => ['conv-never-checked'],
]);

it('refuses foreign, nonexistent, and type-mismatched conversations with one exact message', function (callable $attempt): void {
    Http::fake(['*/chat/completions' => Http::response(chatSseText('Hello.'), 200, sseHeaders())]);
    $turn = iterateFully(app(ChatService::class)->stream(streamChatUser(7), null, 'Hello'))->turn();
    $messagesBefore = DB::table('agent_conversation_messages')->count();

    try {
        $attempt($this->app, $turn->conversationId);
        $this->fail('The stream must refuse.');
    } catch (AuthorizationException $e) {
        // toBe, not substring: a refusal that volunteers anything more (existence, ownership,
        // whose it is) is a disclosure channel between participants.
        expect($e->getMessage())->toBe('This participant may not use this conversation.');
    }

    Http::assertSentCount(1, 'Only the owner\'s turn may have reached the transport.');
    expect(DB::table('agent_conversation_messages')->count())->toBe($messagesBefore, 'A refused attempt stores nothing.');
})->with([
    'another participant\'s conversation' => [fn ($app, string $conversationId) => $app->make(ChatService::class)->stream(streamChatUser(8), $conversationId, 'Mine now')],
    'a conversation that does not exist' => [fn ($app, string $conversationId) => $app->make(ChatService::class)->stream(streamChatUser(8), 'conv-never-existed', 'Hello?')],
    'the right key under a different participant type' => [function ($app, string $conversationId) {
        $app->instance(ChatEntry::class, new class implements ChatEntry
        {
            public function participantFor(Authenticatable $user): object
            {
                return new StreamChatOtherType(42);
            }

            public function entryKeyFor(object $participant): string
            {
                return STREAM_CHAT_KEY;
            }
        });

        return $app->make(ChatService::class)->stream(streamChatUser(7), $conversationId, 'Same key, other type');
    }],
]);

/** Iterate a stream to completion, returning it for turn() reads. */
function iterateFully(ChatStream $stream): ChatStream
{
    foreach ($stream as $event) {
    }

    return $stream;
}

it('relays Laravel AI\'s own stream events chunk-by-chunk, in order, and lazily', function (): void {
    Http::fake(['*/chat/completions' => Http::response(chatSseText('Hel', 'lo.'), 200, sseHeaders())]);

    $stream = app(ChatService::class)->stream(streamChatUser(), null, 'Say hello');

    // The producer checkpoint: building the stream must not consume it. An implementation that
    // buffers the whole run before yielding has already hit the transport here.
    Http::assertNothingSent();

    $events = [];
    $deltas = [];
    foreach ($stream as $event) {
        expect($event)->toBeInstanceOf(StreamEvent::class);
        $events[] = $event::class;
        if ($event instanceof TextDelta) {
            $deltas[] = $event->delta;
        }
        // The turn does not exist at ANY point during iteration — Laravel AI finalizes the
        // conversation only in the completion callback, after StreamEnd itself is yielded.
        expect(fn () => $stream->turn())->toThrow(LogicException::class);
    }

    expect($events[0])->toBe(StreamStart::class)
        ->and(end($events))->toBe(StreamEnd::class)
        ->and($deltas)->toBe(['Hel', 'lo.'], 'Chunks arrive as the transport produced them, not reassembled.');
});

it('surfaces the finished turn the way ChatTurn surfaces it today', function (): void {
    Http::fake(['*/chat/completions' => Http::response(chatSseText('Hello there.'), 200, sseHeaders())]);

    $stream = app(ChatService::class)->stream(streamChatUser(), null, 'Say hello');
    $eventInvocations = [];
    foreach ($stream as $event) {
        $eventInvocations[] = $event->invocationId;
    }
    $turn = $stream->turn();

    expect($turn)->toBeInstanceOf(ChatTurn::class)
        ->and($turn->conversationId)->not->toBe('')
        ->and($turn->text)->toBe('Hello there.')
        ->and($turn->paused)->toBeFalse()
        ->and($turn->pendingToolCallIds)->toBe([])
        // Correlation, not mere non-emptiness: the turn names the same invocation its events carried.
        ->and(array_unique($eventInvocations))->toBe([$turn->invocationId]);
});

it('refuses to answer turn() before and during consumption, including after a deliberate break', function (): void {
    Http::fake(['*/chat/completions' => Http::response(chatSseText('Hel', 'lo.'), 200, sseHeaders())]);

    $stream = app(ChatService::class)->stream(streamChatUser(), null, 'Say hello');

    // The measurement behind this: conversation persistence happens in the completion callback,
    // only when the iterator is exhausted; an abandoned stream has no finished turn to report.
    expect(fn () => $stream->turn())->toThrow(LogicException::class);

    foreach ($stream as $event) {
        if ($event instanceof TextDelta) {
            break;
        }
    }

    expect(fn () => $stream->turn())->toThrow(LogicException::class);

    // An abandoned stream is as spent as an exhausted one: re-iterating may neither resume the
    // broken generator nor quietly issue a fresh model request.
    expect(function () use ($stream): void {
        foreach ($stream as $event) {
        }
    })->toThrow(LogicException::class);

    Http::assertSentCount(1);
});

it('refuses a second iteration rather than replaying or re-prompting', function (): void {
    Http::fake(['*/chat/completions' => Http::response(chatSseText('Hello.'), 200, sseHeaders())]);

    $stream = iterateFully(app(ChatService::class)->stream(streamChatUser(), null, 'Say hello'));

    expect(function () use ($stream): void {
        foreach ($stream as $event) {
        }
    })->toThrow(LogicException::class);

    Http::assertSentCount(1, 'A consumed stream must never issue another model request.');
});

it('propagates a transport failure after a delivered delta, and turn() does not manufacture a result', function (): void {
    Http::fake(['*/chat/completions' => Http::response(chatSseBody([
        ['model' => EndToEndTestCase::MODEL, 'choices' => [['delta' => ['content' => 'Partial'], 'finish_reason' => null]]],
        ['error' => ['code' => 'upstream_reset', 'message' => 'connection reset']],
    ]), 200, sseHeaders())]);

    $stream = app(ChatService::class)->stream(streamChatUser(), null, 'Say hello');

    // Laravel AI yields its Error event, then throws its terminal StreamErrorException. The seam
    // relays transparently: the delivered chunks, the event, AND the throw all reach the adapter —
    // swallowing the exception to finish the loop quietly is the lie this test refuses.
    $sawDelta = false;
    $sawError = false;
    $terminal = null;
    try {
        foreach ($stream as $event) {
            $sawDelta = $sawDelta || $event instanceof TextDelta;
            $sawError = $sawError || $event instanceof Error;
        }
    } catch (Throwable $terminal) {
    }

    expect($sawDelta)->toBeTrue('The delta delivered before the failure reached the adapter.')
        ->and($sawError)->toBeTrue('The failure reaches the adapter as Laravel AI\'s own Error event.')
        ->and($terminal)->toBeInstanceOf(StreamErrorException::class)
        ->and(fn () => $stream->turn())->toThrow(LogicException::class);
});

it('starts the conversation as the ENTRY\'s participant, never the authenticated user', function (): void {
    Http::fake(['*/chat/completions' => Http::response(chatSseText('Hello.'), 200, sseHeaders())]);

    $turn = iterateFully(app(ChatService::class)->stream(streamChatUser(7), null, 'Hello'))->turn();

    $conversation = DB::table('agent_conversations')->where('id', $turn->conversationId)->sole();

    // User 7 maps to participant 42: storing 7 means the entry seam was bypassed.
    expect((int) $conversation->participant_id)->toBe(42)
        ->and($conversation->participant_type)->toBe(GenericUser::class);
});

it('resolves the entry key per turn: a continuation streams through the agent the entry names NOW', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(chatSseText('First.'), 200, sseHeaders())
            ->push(chatSseText('Second.'), 200, sseHeaders()),
    ]);
    // One mutable entry, bound before the service resolves, flipped between turns: a service that
    // resolved its agent once at construction (or cached the key) streams both turns through v1.
    $entry = new class implements ChatEntry
    {
        public string $key = STREAM_CHAT_KEY;

        public function participantFor(Authenticatable $user): object
        {
            return new GenericUser(['id' => (int) $user->getAuthIdentifier() + 35]);
        }

        public function entryKeyFor(object $participant): string
        {
            return $this->key;
        }
    };
    $this->app->instance(ChatEntry::class, $entry);
    app(ResumableAgents::class)->register(
        'stream-chat@v2',
        fn (): StreamChatAgentV2 => new StreamChatAgentV2,
        fn (Agent $agent): bool => $agent::class === StreamChatAgentV2::class,
    );
    $service = app(ChatService::class);

    $first = iterateFully($service->stream(streamChatUser(), null, 'One'))->turn();
    $entry->key = 'stream-chat@v2';
    iterateFully($service->stream(streamChatUser(), $first->conversationId, 'Two'));

    $agents = DB::table('agent_conversation_messages')
        ->where('conversation_id', $first->conversationId)
        ->where('role', 'assistant')
        ->orderBy('id')
        ->pluck('agent')
        ->all();

    expect($agents)->toBe([StreamChatAgent::class, StreamChatAgentV2::class], 'Each turn streams through the agent the entry resolves at that moment.');
});

it('continues an owned conversation in place: same id, the new turn appended', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(chatSseText('First.'), 200, sseHeaders())
            ->push(chatSseText('Second.'), 200, sseHeaders()),
    ]);
    $service = app(ChatService::class);

    $first = iterateFully($service->stream(streamChatUser(), null, 'One'))->turn();
    $second = iterateFully($service->stream(streamChatUser(), $first->conversationId, 'Two'))->turn();

    expect($second->conversationId)->toBe($first->conversationId)
        ->and($second->text)->toBe('Second.')
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $first->conversationId)->where('role', 'assistant')->count())->toBe(2);

    // The continuation genuinely carried the conversation: its request holds the prior exchange
    // and the new prompt, not just a fresh prompt under a reused id.
    $requests = Http::recorded();
    $continuation = json_decode((string) $requests[1][0]->body(), true);
    $contents = array_map(fn (array $m): string => is_string($m['content'] ?? null) ? $m['content'] : '', $continuation['messages'] ?? []);

    expect(implode(' ', $contents))->toContain('One')
        ->and(implode(' ', $contents))->toContain('First.')
        ->and(implode(' ', $contents))->toContain('Two');
});

it('relays a Verdict-gated pause mid-stream and surfaces it on the turn, tool unexecuted', function (): void {
    Http::fake(['*/chat/completions' => Http::response(chatSseToolCall('call_stream_chat', ['order_id' => 7]), 200, sseHeaders())]);

    $stream = app(ChatService::class)->stream(streamChatUser(), null, 'Cancel order 7');

    $approvalEvents = [];
    $rowsAtPause = null;
    $receiptsAtPause = null;
    foreach ($stream as $event) {
        if ($event instanceof ToolApprovalRequest) {
            $approvalEvents[] = $event;
            // The measured boundary: at the pause event the Verdict receipt already exists, while
            // the console's ingestion row does not yet — it lands at exhaustion. An adapter that
            // stops consuming here strands the pause outside the console's index.
            $receiptsAtPause = DB::table($this->approvalReceiptTable())->count();
            $rowsAtPause = DB::table('verdict_console_pending_approvals')->count();
        }
    }
    $turn = $stream->turn();

    expect($approvalEvents)->toHaveCount(1, 'The pause reaches the adapter as Laravel AI\'s own stream event.')
        ->and($approvalEvents[0]->pendingApprovals->first()->id)->toBe('call_stream_chat')
        ->and($receiptsAtPause)->toBe(1)
        ->and($rowsAtPause)->toBe(0)
        ->and($turn->paused)->toBeTrue()
        ->and($turn->pendingToolCallIds)->toBe(['call_stream_chat'])
        ->and(app(StreamChatLedger::class)->executions)->toBe(0, 'The gate held the tool unexecuted mid-stream.');
});

it('ingests the streamed pause into the console index once the stream is exhausted, drivable and correlated', function (): void {
    Http::fake(['*/chat/completions' => Http::response(chatSseToolCall('call_stream_chat', ['order_id' => 7]), 200, sseHeaders())]);

    $turn = iterateFully(app(ChatService::class)->stream(streamChatUser(), null, 'Cancel order 7'))->turn();

    $row = DB::table('verdict_console_pending_approvals')->where('tool_call_id', 'call_stream_chat')->sole();

    expect($row->resumability)->toBe('drivable')
        ->and($row->resolver_key)->toBe(STREAM_CHAT_KEY)
        ->and($row->conversation_id)->toBe($turn->conversationId)
        ->and($row->invocation_id)->toBe($turn->invocationId, 'The row, the events, and the turn name one invocation.')
        ->and(DB::table($this->approvalReceiptTable())->where('tool_call_id', 'call_stream_chat')->value('status'))->toBe('pending');
});

it('carries every pending approval of one paused step, with fragmented tool arguments reassembled', function (): void {
    Http::fake(['*/chat/completions' => Http::response(chatSseBody([
        ['model' => EndToEndTestCase::MODEL, 'choices' => [['delta' => ['tool_calls' => [
            ['index' => 0, 'id' => 'call_a', 'function' => ['name' => 'StreamChatCancelTool', 'arguments' => '']],
            ['index' => 1, 'id' => 'call_b', 'function' => ['name' => 'StreamChatCancelTool', 'arguments' => '']],
        ]], 'finish_reason' => null]]],
        ['choices' => [['delta' => ['tool_calls' => [
            ['index' => 0, 'function' => ['arguments' => '{"order']],
            ['index' => 1, 'function' => ['arguments' => '{"order_id": 2']],
        ]], 'finish_reason' => null]]],
        ['choices' => [['delta' => ['tool_calls' => [
            ['index' => 0, 'function' => ['arguments' => '_id": 1}']],
            ['index' => 1, 'function' => ['arguments' => '}']],
        ]], 'finish_reason' => null]]],
        ['choices' => [['delta' => [], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]],
    ]), 200, sseHeaders())]);

    $stream = app(ChatService::class)->stream(streamChatUser(), null, 'Cancel orders 1 and 2');
    $relayed = [];
    foreach ($stream as $event) {
        if ($event instanceof ToolApprovalRequest) {
            foreach ($event->pendingApprovals as $approval) {
                $relayed[$approval->id] = $approval->arguments;
            }
        }
    }
    $turn = $stream->turn();

    expect($relayed)->toBe([
        'call_a' => ['order_id' => 1],
        'call_b' => ['order_id' => 2],
    ], 'The relayed event carries every approval with its reassembled arguments, verbatim.')
        ->and($turn->paused)->toBeTrue()
        ->and($turn->pendingToolCallIds)->toBe(['call_a', 'call_b'])
        ->and(app(StreamChatLedger::class)->executions)->toBe(0);
});

it('streams through the entry seam: an unresolvable entry key refuses before anything streams', function (): void {
    Http::fake();
    $this->app->instance(ChatEntry::class, new class implements ChatEntry
    {
        public function participantFor(Authenticatable $user): object
        {
            return new GenericUser(['id' => 1]);
        }

        public function entryKeyFor(object $participant): string
        {
            return 'never-registered@v0';
        }
    });

    expect(fn () => app(ChatService::class)->stream(streamChatUser(), null, 'Hello'))
        ->toThrow(UnresolvableAgentKey::class);

    Http::assertNothingSent();
});
