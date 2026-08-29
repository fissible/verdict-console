<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionContext;
use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Actions\AuthorizedAction;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Context\DataClass;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\ProvenanceLedger;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\LaravelAi\VerdictProvenanceMiddleware;
use Fissible\Verdict\Targets\ExecutionTargetPolicy;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\ConversationCorrelation;
use Fissible\VerdictConsole\Evidence\ConversationInvocationStore;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidenceRecord;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsole\Tests\EndToEndTestCase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decision as AiDecision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Tools\Request;

/**
 * The positive control for VC-14.
 *
 * The Feature suite proves the listener handles events it is handed. This file proves the events
 * Laravel AI actually dispatches carry both ids at the moment they fire, and that the invocation id
 * Verdict stamps on decision evidence is the same one those events publish — the two facts the
 * whole projection rests on, and neither is provable from a constructed event.
 *
 * Fixtures are deliberately separate from the approval round trip's: a test file must not depend
 * on classes another test file happens to have declared first.
 */
const CORRELATION_ORDER_ID = 4242;

final class CorrelationLedger
{
    public int $executions = 0;
}

final readonly class CorrelationOrder
{
    public function __construct(public int $id) {}
}

final readonly class CorrelationCustomer
{
    public function __construct(public int $id) {}
}

final class CorrelationCancelOrderTool implements Tool
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

/** The execution-target policy is what makes `requiresConfirmation()` pause rather than return null. */
function correlationBoundTool(): Tool
{
    $verdict = app(VerdictManager::class);

    if (! app(CapabilityRegistry::class)->has('orders.cancel')) {
        $verdict->capability(
            Capability::usingPolicy(
                name: 'orders.cancel',
                ability: 'update',
                resolveTarget: fn (ActionEnvelope $e): CorrelationOrder => new CorrelationOrder(
                    (int) $e->proposal->arguments['order_id'],
                ),
            )
                ->executionTarget(ExecutionTargetPolicy::acceptStaleSnapshot(
                    name: 'correlation-target',
                    identityUsing: fn (ActionEnvelope $e, CorrelationOrder $t): array => ['id' => $t->id],
                ))
                ->requiresConfirmation(fn (ActionEnvelope $e, CorrelationOrder $t): array => ['order_id' => $t->id])
                ->executeUsing(function (AuthorizedAction $a): string {
                    app(CorrelationLedger::class)->executions++;

                    return 'Order cancelled.';
                }),
        );
    }

    return $verdict->bound(new CorrelationCancelOrderTool, 'orders.cancel', new ActionContext('customer-7'));
}

/**
 * `VerdictProvenanceMiddleware` is the declaration this projection depends on and the approval round
 * trip does not need: it is what pushes the invocation frame `VerdictManager` reads when it stamps
 * `invocation_id` on decision evidence. Without it every decision row carries null there, and a
 * correlated conversation has nothing to join.
 */
final class CorrelationAgent implements Agent, HasMiddleware, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'Cancel orders when asked.';
    }

    /** @return array<int, Tool> */
    public function tools(): array
    {
        return [correlationBoundTool()];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            app(VerdictApprovalMiddleware::class),
            new VerdictProvenanceMiddleware(app(ProvenanceLedger::class), Trust::Untrusted, DataClass::Internal),
        ];
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

beforeEach(function (): void {
    $this->migrateRoundTripTables();

    $console = dirname(__DIR__, 2).'/database/migrations';
    (require $console.'/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_notifications_table.php.stub')->up();
    (require $console.'/create_verdict_console_approval_reconciliations_table.php.stub')->up();
    (require $console.'/create_verdict_console_conversation_invocations_table.php.stub')->up();

    $verdict = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';
    foreach ([
        'create_verdict_evidence_table.php.stub',
        'add_provenance_to_verdict_evidence_table.php.stub',
        'add_invocation_id_to_verdict_evidence_table.php.stub',
        'add_tool_kind_to_verdict_evidence_table.php.stub',
        'add_configuration_fingerprint_to_verdict_evidence_table.php.stub',
        'add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub',
        'add_target_source_to_verdict_evidence_table.php.stub',
        'add_tool_description_fingerprints_to_verdict_evidence_table.php.stub',
        'add_record_identity_to_verdict_evidence_table.php.stub',
        'create_verdict_provenance_derivations_table.php.stub',
    ] as $migration) {
        (require $verdict.'/'.$migration)->up();
    }

    // A real durable recorder, so the join has a right-hand side. Verdict's default records nothing.
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $this->app->instance(CorrelationLedger::class, new CorrelationLedger);
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('test');
        }
    });
});

/** @return list<string> */
function correlationDispositions(EvidenceRecord ...$records): array
{
    return array_values(array_unique(array_map(fn (EvidenceRecord $record): string => $record->disposition, $records)));
}

/** @return list<EvidenceRecord> */
function correlationRecordsUnder(string $invocationId, EvidenceRecord ...$records): array
{
    return array_values(array_filter($records, fn (EvidenceRecord $record): bool => $record->invocationId === $invocationId));
}

/**
 * What Verdict itself wrote, read raw: the invocation ids on its decision rows. The projection is
 * only as good as this column, and a query that fabricated or over-supplied ids would still return
 * records — so the join's right-hand side is asserted directly, not inferred from a result.
 *
 * @return list<string|null>
 */
function rawDecisionInvocationIds(): array
{
    return DB::table('verdict_evidence')->where('record_type', 'decision')->distinct()->pluck('invocation_id')->all();
}

/**
 * A Chat Completions SSE body: one streamed tool call, or one streamed text reply.
 *
 * @param  array<string, mixed>|null  $toolCall  ['id' => ..., 'name' => ..., 'arguments' => [...]]
 */
function correlationStreamBody(?array $toolCall, ?string $text = null): string
{
    $delta = $toolCall === null
        ? ['content' => $text]
        : ['tool_calls' => [['index' => 0, 'id' => $toolCall['id'], 'type' => 'function', 'function' => ['name' => $toolCall['name'], 'arguments' => json_encode($toolCall['arguments'])]]]];
    $finish = $toolCall === null ? 'stop' : 'tool_calls';

    $chunks = [
        ['model' => EndToEndTestCase::MODEL, 'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => null]]],
        ['model' => EndToEndTestCase::MODEL, 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => $finish]]],
        ['model' => EndToEndTestCase::MODEL, 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2]],
    ];

    return implode('', array_map(fn (array $chunk): string => 'data: '.json_encode($chunk)."\n\n", $chunks))."data: [DONE]\n\n";
}

it('correlates the pause and the resume of an approved action with their conversation', function (): void {
    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse('call_correlated', 'CorrelationCancelOrderTool', ['order_id' => CORRELATION_ORDER_ID]))
            ->push($this->textResponse('Order cancelled.')),
    ]);

    $agent = new CorrelationAgent;

    $paused = $agent->prompt('Please cancel order '.CORRELATION_ORDER_ID.'.');

    expect($paused->hasPendingApprovals())->toBeTrue('A confirmation-gated capability must pause the run.')
        ->and($paused->conversationId)->not->toBeNull('A paused turn is always remembered, so the pause carries a conversation.');

    $approvals = app(VerdictManager::class)->approvals();
    $challenge = $approvals->challengeForToolCall($paused->pendingApprovals->first()->id);

    expect($challenge)->not->toBeNull();

    $approvals->approve($challenge->receiptId, $challenge->toolCallId, 'operator-1');

    $resumed = $agent->prompt(Decisions::from([$challenge->toolCallId => AiDecision::approve()]));

    expect(app(CorrelationLedger::class)->executions)->toBe(1)
        ->and($resumed->conversationId)->toBe($paused->conversationId)
        ->and($resumed->invocationId)->not->toBe($paused->invocationId, 'Laravel AI mints a fresh invocation id for the resume; the projection must hold both.');

    expect(app(ConversationInvocationStore::class)->invocationIdsFor($paused->conversationId))
        ->toEqualCanonicalizing([$paused->invocationId, $resumed->invocationId]);

    $result = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: $paused->conversationId));

    expect(rawDecisionInvocationIds())->toEqualCanonicalizing([$paused->invocationId, $resumed->invocationId]);

    $underResume = correlationRecordsUnder($resumed->invocationId, ...$result->records);

    expect($result->recording)->toBe(EvidenceRecordingState::On)
        ->and($result->conversation)->toBe(ConversationCorrelation::Known)
        ->and($result->records)->toHaveCount(count(DB::table('verdict_evidence')->where('record_type', 'decision')->get()))
        ->and(correlationDispositions(...correlationRecordsUnder($paused->invocationId, ...$result->records)))
        ->toContain('require_confirmation', 'The pause decision is recorded under the invocation that paused.')
        ->and($underResume)->not->toBeEmpty('The approved execution is decided under the resume\'s invocation id; a conversation view that omitted it would omit the decision the approval was about.')
        ->and(array_filter($underResume, fn (EvidenceRecord $record): bool => $record->stage === 'execution' && $record->disposition === 'permit'))
        ->not->toBeEmpty('The execution-stage permit is the decision the approval was for.');

    foreach ($result->records as $record) {
        expect($record->invocationId)->toBeIn([$paused->invocationId, $resumed->invocationId]);
    }

    $unseen = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: 'conversation-never-seen'));

    expect($unseen->recording)->toBe(EvidenceRecordingState::On)
        ->and($unseen->conversation)->toBe(ConversationCorrelation::Unknown)
        ->and($unseen->records)->toBe([]);
});

/**
 * A Deny never pauses, so it never reaches the approval events. If the ordinary completion were not
 * a capture boundary, the one disposition an auditor most wants to see per conversation would be
 * the one a conversation view could never show.
 */
it('correlates a denied action that never paused with the conversation it was refused in', function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::deny('Not this customer\'s order.');
        }
    });

    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push($this->toolCallResponse('call_refused', 'CorrelationCancelOrderTool', ['order_id' => CORRELATION_ORDER_ID]))
            ->push($this->textResponse('I cannot cancel that order.')),
    ]);

    // A participant makes the turn remembered without a pause, which is what gives it a conversation.
    $response = (new CorrelationAgent)->forParticipant(new CorrelationCustomer(7))->prompt('Please cancel order '.CORRELATION_ORDER_ID.'.');

    expect($response->hasPendingApprovals())->toBeFalse()
        ->and(app(CorrelationLedger::class)->executions)->toBe(0)
        ->and($response->conversationId)->not->toBeNull();

    $result = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: $response->conversationId));

    expect(rawDecisionInvocationIds())->toBe([$response->invocationId])
        ->and($result->conversation)->toBe(ConversationCorrelation::Known)
        ->and(correlationDispositions(...$result->records))->toContain('deny');

    foreach ($result->records as $record) {
        expect($record->invocationId)->toBe($response->invocationId);
    }
});

/**
 * Streaming is the flagship surface's path (design §8), and it reports completion through
 * `AgentStreamed`, a subclass Laravel's dispatcher does not fold into `AgentPrompted`. The event
 * fires from a completion callback registered after the conversation middleware's own, on the same
 * response object — this proves that ordering against the real stream rather than from a reading
 * of it, and that Verdict's streamed invocation frame stamps the decision made during iteration.
 */
it('correlates a streamed run through its own completion event', function (): void {
    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::deny('Not this customer\'s order.');
        }
    });

    Http::fake([
        '*/chat/completions' => Http::sequence()
            ->push(correlationStreamBody(['id' => 'call_streamed', 'name' => 'CorrelationCancelOrderTool', 'arguments' => ['order_id' => CORRELATION_ORDER_ID]]))
            ->push(correlationStreamBody(null, 'I cannot cancel that order.')),
    ]);

    $stream = (new CorrelationAgent)->forParticipant(new CorrelationCustomer(7))->stream('Please cancel order '.CORRELATION_ORDER_ID.'.');

    foreach ($stream as $event) {
        // Consume; the completion callbacks fire only once the stream has been iterated.
    }

    expect($stream->conversationId)->not->toBeNull('A remembered streamed turn carries its conversation once complete.')
        ->and(app(CorrelationLedger::class)->executions)->toBe(0)
        ->and(rawDecisionInvocationIds())->toBe([$stream->invocationId]);

    $result = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: $stream->conversationId));

    expect($result->conversation)->toBe(ConversationCorrelation::Known)
        ->and(correlationDispositions(...$result->records))->toContain('deny');

    foreach ($result->records as $record) {
        expect($record->invocationId)->toBe($stream->invocationId);
    }
});
