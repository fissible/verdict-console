<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\ConversationCorrelation;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Promptable;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;

const CORRELATION_TABLE = 'verdict_console_conversation_invocations';

/**
 * Every event here is dispatched for real, through the application's dispatcher. Nothing else in
 * this package listens to `AgentPrompted` or `AgentStreamed`, so a recorded row proves both that the
 * listener is registered for that event and that it handled it; the approval events are not
 * registered at all, because both fire after the completion event in the same gateway call with
 * the same response, and would only observe what was already recorded.
 */
beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_conversation_invocations_table.php.stub')->up();
});

afterEach(function (): void {
    Schema::dropIfExists(CORRELATION_TABLE);
});

/** Never prompted: the events below are constructed, not produced, so the agent is only an identity. */
function correlationFixtureAgent(): Agent
{
    return new class implements Agent
    {
        use Promptable;

        public function instructions(): string
        {
            return 'A correlation fixture.';
        }
    };
}

function correlationFixturePrompt(Agent $agent): AgentPrompt
{
    return new AgentPrompt($agent, 'Cancel the order.', [], Mockery::mock(TextProvider::class), 'fixture-model');
}

function correlationFixtureResponse(string $invocationId, ?string $conversationId): AgentResponse
{
    $response = new AgentResponse($invocationId, 'Done.', new Usage, new Meta);

    return $conversationId === null ? $response : $response->withinConversation($conversationId);
}

/** @return array<string, string> invocation id => conversation id, in invocation order */
function recordedCorrelations(): array
{
    return DB::table(CORRELATION_TABLE)->orderBy('invocation_id')->pluck('conversation_id', 'invocation_id')->all();
}

/**
 * The ordinary completion is the boundary that makes the projection complete: a denied or permitted
 * action never pauses, so an approval-only capture would leave every Deny in a conversation
 * uncorrelated. A redelivery of the same completion is the same fact.
 */
it('records the conversation an ordinary prompt completed in, once per invocation', function (): void {
    Log::spy();
    $agent = correlationFixtureAgent();

    event(new AgentPrompted('invocation-1', correlationFixturePrompt($agent), correlationFixtureResponse('invocation-1', 'conversation-a')));
    event(new AgentPrompted('invocation-1', correlationFixturePrompt($agent), correlationFixtureResponse('invocation-1', 'conversation-a')));

    expect(recordedCorrelations())->toBe(['invocation-1' => 'conversation-a']);
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

/**
 * `AgentStreamed extends AgentPrompted`, and Laravel's dispatcher resolves listeners by the event's
 * own class and its interfaces — never its parents. A listener registered for `AgentPrompted` alone
 * would leave every streamed conversation uncorrelated while every test that constructs the parent
 * event stayed green. This is the test that notices.
 */
it('records a streamed completion, which Laravel AI reports through a subclass', function (): void {
    $agent = correlationFixtureAgent();
    $response = (new StreamedAgentResponse('invocation-2', collect(), new Meta))->withinConversation('conversation-a');

    event(new AgentStreamed('invocation-2', correlationFixturePrompt($agent), $response));

    expect(recordedCorrelations())->toBe(['invocation-2' => 'conversation-a']);
});

/** An agent that does not remember conversations has nothing to correlate, and that is routine. */
it('records nothing and reports nothing when an invocation has no conversation', function (): void {
    Log::spy();
    $agent = correlationFixtureAgent();

    event(new AgentPrompted('invocation-3', correlationFixturePrompt($agent), correlationFixtureResponse('invocation-3', null)));
    event(new AgentStreamed('invocation-4', correlationFixturePrompt($agent), new StreamedAgentResponse('invocation-4', collect(), new Meta)));

    expect(recordedCorrelations())->toBe([]);
    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('keeps the first conversation and warns when a later event disagrees about an invocation', function (): void {
    Log::spy();
    $agent = correlationFixtureAgent();

    event(new AgentPrompted('invocation-5', correlationFixturePrompt($agent), correlationFixtureResponse('invocation-5', 'conversation-a')));
    event(new AgentPrompted('invocation-5', correlationFixturePrompt($agent), correlationFixtureResponse('invocation-5', 'conversation-b')));

    expect(recordedCorrelations())->toBe(['invocation-5' => 'conversation-a']);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => in_array('invocation-5', $context, true)
            && in_array('conversation-a', $context, true)
            && in_array('conversation-b', $context, true));
});

/**
 * The projection is a read-model, not authority. A host that has not published this migration, or
 * whose console database is unreachable, must still get its agent's answer. What it gets later is
 * the explicit degradation: the conversation is reported as unknown, never as empty.
 */
it('logs an error, lets the run continue, and later reports the conversation as unknown when the projection cannot be written', function (): void {
    Schema::drop(CORRELATION_TABLE);
    Log::spy();
    $agent = correlationFixtureAgent();

    event(new AgentPrompted('invocation-6', correlationFixturePrompt($agent), correlationFixtureResponse('invocation-6', 'conversation-a')));

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => in_array('invocation-6', $context, true));

    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_conversation_invocations_table.php.stub')->up();

    expect(app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: 'conversation-a'))->conversation)
        ->toBe(ConversationCorrelation::Unknown);
});
