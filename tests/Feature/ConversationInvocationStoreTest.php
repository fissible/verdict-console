<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Evidence\ConversationInvocation;
use Fissible\VerdictConsole\Evidence\ConversationInvocationStore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_conversation_invocations_table.php.stub')->up();

    $this->store = new ConversationInvocationStore;
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_conversation_invocations');
});

it('records which conversation an invocation belonged to', function (): void {
    $row = $this->store->record('invocation-1', 'conversation-a');

    expect($row)->toBeInstanceOf(ConversationInvocation::class)
        ->and($row->invocation_id)->toBe('invocation-1')
        ->and($row->conversation_id)->toBe('conversation-a')
        ->and(DB::table('verdict_console_conversation_invocations')->count())->toBe(1);
});

/**
 * The schema is the projection's whole invariant: one row per invocation, findable by conversation.
 * Publishing a migration proves nothing about what it creates, so the constraint is exercised with
 * a raw duplicate rather than trusted, and the lookup index is read back rather than assumed.
 */
it('constrains the table to one row per invocation and indexes the conversation lookup', function (): void {
    $this->store->record('invocation-1', 'conversation-a');

    /** @var array<string, mixed> $standing */
    $standing = (array) DB::table('verdict_console_conversation_invocations')->sole();

    expect(fn () => DB::table('verdict_console_conversation_invocations')->insert([...$standing, 'conversation_id' => 'conversation-b']))
        ->toThrow(UniqueConstraintViolationException::class);

    $indexes = collect(Schema::getIndexes('verdict_console_conversation_invocations'));

    expect($indexes->contains(fn (array $index): bool => $index['columns'] === ['invocation_id'] && ($index['primary'] || $index['unique'])))
        ->toBeTrue('invocation_id must be the primary or a unique key; it is what makes a second observation collide.')
        ->and($indexes->contains(fn (array $index): bool => ($index['columns'][0] ?? null) === 'conversation_id'))
        ->toBeTrue('conversation lookups must not scan the table.');
});

/**
 * Laravel AI publishes the same invocation more than once — a streamed and a non-streamed
 * completion each fire once, but a queue can redeliver — and every observation of one fact is one
 * row. This is sequential redelivery, not a race: the atomicity argument is the unique key the
 * previous test pins, and a genuine multi-process race is outside this suite.
 */
it('keeps one row when the same invocation is observed again under the same conversation', function (): void {
    $first = $this->store->record('invocation-1', 'conversation-a');
    $again = $this->store->record('invocation-1', 'conversation-a');

    expect($again->conversation_id)->toBe($first->conversation_id)
        ->and(DB::table('verdict_console_conversation_invocations')->count())->toBe(1);
});

/**
 * An invocation belongs to exactly one conversation. A later observation that disagrees is an
 * upstream inconsistency the store surfaces rather than resolves: it hands back the row that
 * already stands, so the caller can see the disagreement, and never overwrites the first
 * observation with the second.
 */
it('keeps the first conversation when a later observation names a different one', function (): void {
    $this->store->record('invocation-1', 'conversation-a');

    $row = $this->store->record('invocation-1', 'conversation-b');

    expect($row->conversation_id)->toBe('conversation-a')
        ->and(DB::table('verdict_console_conversation_invocations')->pluck('conversation_id')->all())->toBe(['conversation-a']);
});

it('lists every invocation observed for a conversation and nothing for an unknown one', function (): void {
    $this->store->record('invocation-1', 'conversation-a');
    $this->store->record('invocation-2', 'conversation-a');
    $this->store->record('invocation-3', 'conversation-b');

    expect($this->store->invocationIdsFor('conversation-a'))->toEqualCanonicalizing(['invocation-1', 'invocation-2'])
        ->and($this->store->invocationIdsFor('conversation-b'))->toBe(['invocation-3'])
        ->and($this->store->invocationIdsFor('conversation-never-seen'))->toBe([]);
});
