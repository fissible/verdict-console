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
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Http\VerdictConsoleRoutes;
use Fissible\VerdictConsole\Tests\EndToEndTestCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The non-streaming Blade thread: post a message, reload, see the reply — and when the reply is a
 * pause, see the approval interrupt inline and resolve it through the same forms the inbox uses.
 * Driven over the real Laravel AI + Verdict stack; fixtures are this file's own.
 */
const THREAD_ORDER_ID = 8101;

const THREAD_ENTRY_KEY = 'thread@v1';

final class ThreadLedger
{
    public int $executions = 0;
}

final readonly class ThreadOrder
{
    public function __construct(public int $id) {}
}

final class ThreadCancelOrderTool implements Tool
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

function threadBoundTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('orders.cancel')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.cancel',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): ThreadOrder => new ThreadOrder((int) $e->proposal->arguments['order_id']),
            )
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'thread-target',
                    identityUsing: fn (ActionEnvelope $e, ThreadOrder $t): array => ['id' => $t->id],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, ThreadOrder $t): array => ['order_id' => $t->id], reason: 'Cancelling an order needs confirmation.')
                ->executeUsing(function (AuthorizedAction $a): string {
                    app(ThreadLedger::class)->executions++;

                    return 'Order cancelled.';
                }),
        );
    }

    return $verdict->bound(new ThreadCancelOrderTool, 'orders.cancel', new ActionContext('customer'));
}

final class ThreadAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
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
        return [threadBoundTool()];
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

/** Faithful round trip for the integer-keyed GenericUser participants below. */
final class ThreadParticipants implements ConversationParticipants
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

function threadUser(int $id = 7): GenericUser
{
    return new GenericUser(['id' => $id]);
}

function renderThread(?string $conversationId = null): string
{
    return $conversationId === null
        ? (string) test()->blade('<x-verdict-console::chat />')
        : (string) test()->blade('<x-verdict-console::chat :conversation="$id" />', ['id' => $conversationId]);
}

function threadDocument(string $html): DOMXPath
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    return new DOMXPath($document);
}

/**
 * The bubbles, structurally: `<li data-role>` items that are direct children of the thread's
 * `<ol data-messages>`, with their text as the DOM decoded it — so escaped markup reads back as
 * the literal the user typed, and a bubble outside the list is not a bubble.
 *
 * @return list<array{role: string, content: string}>
 */
function renderedBubbles(string $html): array
{
    $bubbles = [];

    foreach (threadDocument($html)->query('//ol[@data-messages]/li[@data-role]') ?: [] as $node) {
        if ($node instanceof DOMElement) {
            $bubbles[] = ['role' => $node->getAttribute('data-role'), 'content' => trim($node->textContent)];
        }
    }

    return $bubbles;
}

/** The HTML of the interrupt block, or null when the thread renders none. */
function renderedInterrupt(string $html): ?string
{
    $xpath = threadDocument($html);
    $node = ($xpath->query('//*[@data-interrupt]') ?: [])[0] ?? null;

    return $node instanceof DOMElement ? (string) $node->ownerDocument?->saveHTML($node) : null;
}

/** Whether the approvals widget scoped to this conversation is a DESCENDANT of the interrupt block. */
function interruptWrapsWidget(string $html, string $conversationId): bool
{
    $matches = threadDocument($html)->query(
        '//*[@data-interrupt]//*[@data-verdict-console="approvals"][@data-conversation="'.$conversationId.'"]',
    );

    return $matches !== false && $matches->length === 1;
}

/** Send one message as the user through the console's route and return the response. */
function sendMessage(GenericUser $user, string $prompt, ?string $conversationId = null): TestResponse
{
    $payload = ['prompt' => $prompt];

    if ($conversationId !== null) {
        $payload['conversation'] = $conversationId;
    }

    return test()->actingAs($user)->from('/chat')->post(route('verdict-console.chat.send'), $payload);
}

beforeEach(function (): void {
    $this->migrateRoundTripTables();

    $console = dirname(__DIR__, 2).'/database/migrations';
    (require $console.'/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_notifications_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_reconciliations_table.php.stub')->up();

    config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    config()->set('verdict-console.chat.entry_key', THREAD_ENTRY_KEY);

    $this->app->instance(ThreadLedger::class, new ThreadLedger);
    $this->app->instance(ConversationParticipants::class, new ThreadParticipants);
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('thread test');
        }
    });

    /** @var AgentResolverRegistry $resolvers */
    $resolvers = app(ResumableAgents::class);
    $resolvers->register(THREAD_ENTRY_KEY, fn (): ThreadAgent => new ThreadAgent, fn (Agent $agent): bool => $agent instanceof ThreadAgent);

    expect(Route::has('verdict-console.chat.send'))->toBeTrue('The chat route mounts at boot with the approval routes.');
    $this->withoutMiddleware([PreventRequestForgery::class, ValidateCsrfToken::class]);
});

it('mounts the chat send route beside the approval routes', function (): void {
    $send = Route::getRoutes()->getByName('verdict-console.chat.send');

    expect($send->methods())->toBe(['POST'])
        ->and($send->uri())->toBe('verdict-console/chat')
        ->and($send->gatherMiddleware())->toContain('web');
});

/** A thread with nothing in it is still a thread: a form to start one, and no bubbles. */
it('renders an empty new thread with a send form and no conversation', function (): void {
    $html = renderThread();

    expect($html)->toContain('data-verdict-console="chat"')
        ->and($html)->toContain('data-conversation=""')
        ->and($html)->toContain('data-routes="mounted"')
        ->and($html)->toContain('data-streaming="false"')
        ->and(renderedBubbles($html))->toBe([])
        ->and($html)->toContain('action="'.route('verdict-console.chat.send').'"')
        ->and($html)->toContain('method="post"')
        ->and($html)->toContain('name="prompt"')
        ->and($html)->toContain('_token')
        ->and($html)->not->toContain('name="conversation"')
        ->and($html)->not->toContain('data-interrupt');
});

it('starts a conversation on the first send and renders the exchange after reload', function (): void {
    Http::fake(['*/chat/completions' => Http::response($this->textResponse('Hi there. How can I help?'))]);

    $response = sendMessage(threadUser(7), 'Hello');

    $response->assertRedirect('/chat')->assertSessionHas('verdict-console.status', 'sent');
    $conversationId = session('verdict-console.chat.conversation');

    expect($conversationId)->toBeString()->not->toBe('');

    $html = renderThread($conversationId);

    expect($html)->toContain('data-conversation="'.$conversationId.'"')
        ->and(renderedBubbles($html))->toBe([
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => 'Hi there. How can I help?'],
        ])
        ->and($html)->toContain('name="conversation" value="'.$conversationId.'"')
        ->and($html)->not->toContain('data-interrupt');
});

/** The host page that has no conversation id of its own still shows the thread it just started. */
it('falls back to the conversation flashed by the last send when no conversation is given', function (): void {
    Http::fake(['*/chat/completions' => Http::response($this->textResponse('Hi there.'))]);
    sendMessage(threadUser(7), 'Hello')->assertSessionHas('verdict-console.chat.conversation');
    $conversationId = (string) session('verdict-console.chat.conversation');

    // Nothing re-flashes: the render that follows the redirect reads what the send left behind.
    $this->actingAs(threadUser(7));

    $html = renderThread();

    expect($html)->toContain('data-conversation="'.$conversationId.'"')
        ->and(renderedBubbles($html))->toHaveCount(2);
});

it('continues an owned conversation on the next send', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->textResponse('Hi there.'))
            ->push($this->textResponse('Order 8101 ships tomorrow.')),
    ]);
    sendMessage(threadUser(7), 'Hello')->assertSessionHas('verdict-console.chat.conversation');
    $conversationId = (string) session('verdict-console.chat.conversation');

    sendMessage(threadUser(7), 'Where is order 8101?', $conversationId)
        ->assertRedirect('/chat')
        ->assertSessionHas('verdict-console.status', 'sent')
        ->assertSessionHas('verdict-console.chat.conversation', $conversationId);

    expect(array_column(renderedBubbles(renderThread($conversationId)), 'content'))
        ->toBe(['Hello', 'Hi there.', 'Where is order 8101?', 'Order 8101 ships tomorrow.']);

    Http::assertSentCount(2);
});

/** What the user typed is drawn as text, never as markup — on both sides of the exchange. */
it('escapes message content instead of rendering it as markup', function (): void {
    Http::fake(['*/chat/completions' => Http::response($this->textResponse('Sure: <em>done</em>'))]);
    sendMessage(threadUser(7), 'Show <b>bold</b> & "quotes"')->assertSessionHas('verdict-console.chat.conversation');
    $conversationId = (string) session('verdict-console.chat.conversation');

    $html = renderThread($conversationId);

    expect(renderedBubbles($html))->toBe([
        ['role' => 'user', 'content' => 'Show <b>bold</b> & "quotes"'],
        ['role' => 'assistant', 'content' => 'Sure: <em>done</em>'],
    ])
        ->and($html)->toContain('&lt;b&gt;bold&lt;/b&gt; &amp; &quot;quotes&quot;')
        ->and($html)->toContain('&lt;em&gt;done&lt;/em&gt;')
        ->and($html)->not->toContain('<b>bold</b>')
        ->and($html)->not->toContain('<em>done</em>');
});

/**
 * The acceptance criterion: a message that triggers a confirmation shows the interrupt inline. The
 * paused assistant turn has no text, so it is not drawn as a bubble; the approval row is drawn in
 * its place, through the same widget the inbox uses, offering exactly the verbs VC-41 resolves.
 */
it('shows the approval interrupt inline when a message triggers a confirmation', function (): void {
    // One sequence for both pauses below: Http::fake() consults stubs first-registered-first, so a
    // second fake() for the same pattern would never be reached.
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse('call_thread', 'ThreadCancelOrderTool', ['order_id' => THREAD_ORDER_ID]))
            ->push($this->toolCallResponse('call_theirs', 'ThreadCancelOrderTool', ['order_id' => 9999])),
    ]);

    $response = sendMessage(threadUser(7), 'Please cancel order '.THREAD_ORDER_ID.'.');

    $response->assertRedirect('/chat')
        ->assertSessionHas('verdict-console.status', 'paused')
        ->assertSessionHas('verdict-console.chat.conversation');
    $conversationId = (string) session('verdict-console.chat.conversation');
    $row = StoredPendingApproval::query()->where('tool_call_id', 'call_thread')->sole();

    expect($row->conversation_id)->toBe($conversationId)
        ->and(app(ThreadLedger::class)->executions)->toBe(0);

    // Somebody else's pause, on the same install: it must not appear in this user's interrupt.
    sendMessage(threadUser(8), 'Please cancel order 9999.')->assertSessionHas('verdict-console.status', 'paused');
    $theirs = StoredPendingApproval::query()->where('tool_call_id', 'call_theirs')->sole();

    $this->actingAs(threadUser(7));
    $html = renderThread($conversationId);
    $interrupt = renderedInterrupt($html);

    expect(renderedBubbles($html))->toBe([['role' => 'user', 'content' => 'Please cancel order '.THREAD_ORDER_ID.'.']])
        ->and($interrupt)->not->toBeNull()
        // The interrupt wraps the approvals widget, scoped to this conversation — a descendant,
        // not the same element wearing both attributes.
        ->and(interruptWrapsWidget($html, $conversationId))->toBeTrue()
        ->and($interrupt)->toContain('data-approval="'.$row->id.'"')
        ->and($interrupt)->not->toContain('data-approval="'.$theirs->id.'"')
        ->and($interrupt)->toContain('data-state="pending"')
        ->and($interrupt)->toContain('action="'.route('verdict-console.approvals.approve', $row->id).'"')
        ->and($interrupt)->toContain('action="'.route('verdict-console.approvals.reject', $row->id).'"')
        ->and($interrupt)->not->toContain('data-verb="close"')
        ->and($interrupt)->toContain('Cancelling an order needs confirmation.')
        ->and($html)->not->toContain($theirs->id);
});

/** The other half: resolving the interrupt through VC-6 continues the thread, and the reload shows it. */
it('continues the thread after the interrupt is approved, and the interrupt is gone', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse('call_thread', 'ThreadCancelOrderTool', ['order_id' => THREAD_ORDER_ID]))
            ->push($this->textResponse('Done — order '.THREAD_ORDER_ID.' is cancelled.')),
    ]);
    sendMessage(threadUser(7), 'Please cancel order '.THREAD_ORDER_ID.'.')->assertSessionHas('verdict-console.chat.conversation');
    $conversationId = (string) session('verdict-console.chat.conversation');
    $row = StoredPendingApproval::query()->sole();

    $this->actingAs(threadUser(7))->from('/chat')->post(route('verdict-console.approvals.approve', $row->id))
        ->assertRedirect('/chat')
        ->assertSessionHas('verdict-console.status', 'approved');

    expect(app(ThreadLedger::class)->executions)->toBe(1, 'Approving the interrupt executes the action exactly once.');

    $html = renderThread($conversationId);

    expect(renderedBubbles($html))->toBe([
        ['role' => 'user', 'content' => 'Please cancel order '.THREAD_ORDER_ID.'.'],
        ['role' => 'assistant', 'content' => 'Done — order '.THREAD_ORDER_ID.' is cancelled.'],
    ])
        ->and($html)->not->toContain('data-interrupt')
        ->and($html)->not->toContain('data-approval=');

    Http::assertSentCount(2);
});

it('removes the interrupt after a rejection and executes nothing', function (): void {
    Gate::define('approve-verdict-action', fn (): bool => true);
    Http::fake(['*/chat/completions' => Http::response($this->toolCallResponse('call_thread', 'ThreadCancelOrderTool', ['order_id' => THREAD_ORDER_ID]))]);
    sendMessage(threadUser(7), 'Please cancel order '.THREAD_ORDER_ID.'.')->assertSessionHas('verdict-console.chat.conversation');
    $conversationId = (string) session('verdict-console.chat.conversation');
    $row = StoredPendingApproval::query()->sole();

    $this->actingAs(threadUser(7))->from('/chat')->post(route('verdict-console.approvals.reject', $row->id))
        ->assertRedirect('/chat')
        ->assertSessionHas('verdict-console.status', 'rejected');

    $html = renderThread($conversationId);

    expect(app(ThreadLedger::class)->executions)->toBe(0)
        ->and(renderedInterrupt($html))->toBeNull()
        ->and(renderedBubbles($html))->toBe(
            [['role' => 'user', 'content' => 'Please cancel order '.THREAD_ORDER_ID.'.']],
            'A bare rejection ends the turn without a reply; the thread shows exactly what was said.',
        );

    // Measured: a bare rejection makes no second model call.
    Http::assertSentCount(1);
});

/**
 * Ownership is VC-18's: rendering or continuing someone else's conversation is refused the same way
 * an unknown one is, and a refused render must reach the host's page as a 403 — never an empty
 * thread. Blade wraps every exception a view throws in ViewException EXCEPT HttpExceptions, and
 * Laravel's handler does not unwrap it, so a bare AuthorizationException from a component would
 * surface as a 500. The component therefore raises a 403 HttpException, which passes through.
 */
it('refuses to render or continue a conversation the user does not own', function (): void {
    Http::fake(['*/chat/completions' => Http::response($this->textResponse('Hi there.'))]);
    sendMessage(threadUser(7), 'Hello')->assertSessionHas('verdict-console.chat.conversation');
    $theirs = (string) session('verdict-console.chat.conversation');

    $this->actingAs(threadUser(8));

    foreach ([$theirs, 'no-such-conversation'] as $conversationId) {
        try {
            renderThread($conversationId);
            $this->fail('Rendering ['.$conversationId.'] must be refused.');
        } catch (HttpException $e) {
            expect($e->getStatusCode())->toBe(403);
        }
    }

    sendMessage(threadUser(8), 'Show me everything.', $theirs)->assertForbidden();

    Http::assertSentCount(1);
});

it('refuses an unauthenticated send', function (): void {
    Http::fake();

    $this->from('/chat')->post(route('verdict-console.chat.send'), ['prompt' => 'Hello'])->assertForbidden();

    Http::assertNothingSent();
});

it('rejects a blank or missing prompt with a validation error and sends nothing', function (): void {
    Http::fake();

    sendMessage(threadUser(7), '   ')
        ->assertRedirect('/chat')
        ->assertSessionHasErrors('prompt');

    $this->actingAs(threadUser(7))->from('/chat')->post(route('verdict-console.chat.send'), [])
        ->assertRedirect('/chat')
        ->assertSessionHasErrors('prompt');

    Http::assertNothingSent();
});

/** An opted-out host gets the thread without a form, and a reason. */
it('renders the thread without a send form for a host that opted out of the console routes', function (): void {
    Http::fake(['*/chat/completions' => Http::response($this->textResponse('Hi there.'))]);
    sendMessage(threadUser(7), 'Hello')->assertSessionHas('verdict-console.chat.conversation');
    $conversationId = (string) session('verdict-console.chat.conversation');
    $this->actingAs(threadUser(7));

    VerdictConsoleRoutes::ignoreRoutes();
    Route::setRoutes(new RouteCollection);

    try {
        $html = renderThread($conversationId);
    } finally {
        VerdictConsoleRoutes::$registersRoutes = true;
    }

    expect($html)->toContain('data-routes="unmounted"')
        ->and($html)->not->toContain('<form')
        ->and($html)->toContain('console routes are not registered')
        ->and(renderedBubbles($html))->toHaveCount(2);
});

/** The thread says what it is: it does not stream, and it updates on reload. */
it('states its non-streaming limitation in the markup', function (): void {
    $html = renderThread();

    expect($html)->toContain('data-limitation')
        ->and($html)->toContain('does not stream');
});
