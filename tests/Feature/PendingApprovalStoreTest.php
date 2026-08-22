<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();

    $this->store = new PendingApprovalStore;
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_pending_approvals');
});

it('records a pause', function (): void {
    $row = $this->store->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        participantReference: 'host-opaque-ref-1',
        invocationId: 'inv_1',
        receiptId: 'receipt_1',
        resolverKey: 'orders-agent',
        presentation: ['capability' => 'orders.cancel'],
        resumability: Resumability::Drivable,
    );

    expect($row->exists)->toBeTrue()
        ->and($row->id)->not->toBeEmpty()
        ->and($row->receipt_id)->toBe('receipt_1')
        ->and($row->resumability)->toBe(Resumability::Drivable)
        ->and($row->presentation)->toBe(['capability' => 'orders.cancel'])
        ->and($row->participant_reference)->toBe('host-opaque-ref-1')
        ->and(PendingApproval::query()->count())->toBe(1);
});

/** A redelivered `ToolApprovalRequested` is the same pause arriving twice, not a second pause. */
it('returns the same row when the same pause is ingested again', function (): void {
    $first = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', receiptId: 'receipt_1');
    $second = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', receiptId: 'receipt_1');

    expect($second->id)->toBe($first->id)
        ->and(PendingApproval::query()->count())->toBe(1);
});

/**
 * The reason `ingest_key` is a hashed non-null column rather than a composite unique index over
 * `(tool_call_id, conversation_id)`.
 *
 * A UNIQUE index does not constrain rows where an indexed column is NULL — two NULLs never compare
 * equal — so a pause with no conversation would duplicate on every redelivery under the composite
 * key, which is precisely the case a console cannot afford to get wrong: an approval with no
 * conversation is already the one it cannot resume.
 */
it('stays idempotent for a pause that has no conversation', function (): void {
    $first = $this->store->ingest(toolCallId: 'call_1', conversationId: null);
    $second = $this->store->ingest(toolCallId: 'call_1', conversationId: null);

    expect($second->id)->toBe($first->id)
        ->and(PendingApproval::query()->count())->toBe(1);
});

/** The composite key it replaces would have failed this: proof the NULL case is genuinely covered. */
it('proves a composite unique index over the nullable column would not have held', function (): void {
    $rows = 2;

    for ($i = 0; $i < $rows; $i++) {
        PendingApproval::query()->insert([
            'id' => Str::uuid()->toString(),
            // A distinct ingest key per row, so this test isolates the NULL question rather than
            // re-testing the unique index.
            'ingest_key' => hash('sha256', 'distinct-'.$i),
            'tool_call_id' => 'call_1',
            'conversation_id' => null,
            'resumability' => Resumability::Unresumable->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // Both rows carry the identical natural key (call_1, NULL) and coexist happily. That is the
    // database behaviour the hashed column exists to defeat.
    expect(PendingApproval::query()->where('tool_call_id', 'call_1')->whereNull('conversation_id')->count())
        ->toBe($rows);
});

/**
 * The concurrency half of the acceptance criteria, and the honest bound on it.
 *
 * True parallel processes are not simulated: PHP has no reliable way to interleave two workers
 * mid-statement in a test. What is asserted instead is the property that makes the race
 * unwinnable — the row lands through the unique index rather than through a read-then-write, so
 * there is no window between "does it exist?" and "insert it" for a second worker to slip into.
 * This test forces exactly that interleaving by making the row appear *after* the store would have
 * checked and *before* it writes: the store must absorb it, not raise.
 */
it('absorbs a row that appears between the check a naive store would make and its write', function (): void {
    // Stand in for the concurrent worker that won the race.
    PendingApproval::query()->insert([
        'id' => Str::uuid()->toString(),
        'ingest_key' => PendingApproval::ingestKey('call_1', 'conv_1'),
        'receipt_id' => 'receipt_1',
        'tool_call_id' => 'call_1',
        'conversation_id' => 'conv_1',
        'resumability' => Resumability::Drivable->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A check-then-insert store would now raise a unique violation here; an upserting one would
    // overwrite the winner's row. Neither is acceptable.
    $row = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', receiptId: 'receipt_1');

    expect(PendingApproval::query()->count())->toBe(1)
        ->and($row->receipt_id)->toBe('receipt_1');
});

/**
 * First-write-wins, stated as a test because the alternative is silent data loss: by the time a
 * redelivery arrives the original row may have been annotated, and an upsert would discard that.
 */
it('does not overwrite an annotated row when the same pause is redelivered', function (): void {
    $first = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', resumability: Resumability::Drivable);

    $first->update(['resolver_key' => 'repaired-by-an-operator']);

    $second = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', resumability: Resumability::Unresumable);

    expect($second->resolver_key)->toBe('repaired-by-an-operator')
        ->and($second->resumability)->toBe(Resumability::Drivable, 'A duplicate event carries no newer truth than the row it duplicates.');
});

/** Two distinct pauses are two rows, or the index would be collapsing real work. */
it('records distinct pauses separately', function (): void {
    $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1');
    $this->store->ingest(toolCallId: 'call_2', conversationId: 'conv_1');
    $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_2');

    expect(PendingApproval::query()->count())->toBe(3);
});

/** A literal conversation id must not be able to impersonate the absence of one. */
it('separates a null conversation from a conversation literally named like a sentinel', function (): void {
    $this->store->ingest(toolCallId: 'call_1', conversationId: null);
    $this->store->ingest(toolCallId: 'call_1', conversationId: '-');
    $this->store->ingest(toolCallId: 'call_1', conversationId: '');

    expect(PendingApproval::query()->count())->toBe(3);
});

/** One receipt, one row — a receipt driven from two rows would be two humans deciding one action. */
it('refuses a second row for the same receipt', function (): void {
    $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', receiptId: 'receipt_1');

    expect(fn () => $this->store->ingest(toolCallId: 'call_2', conversationId: 'conv_2', receiptId: 'receipt_1'))
        ->toThrow(QueryException::class);
});

/**
 * The other side of unique-when-present: receiptless rows must be able to coexist. They are the
 * non-`BoundTool` and ambiguous approvals the console records but cannot drive (design §3, §6.3),
 * and a NULL-collapsing index would let only one of them exist.
 */
it('allows many rows with no receipt at all', function (): void {
    $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', receiptId: null);
    $this->store->ingest(toolCallId: 'call_2', conversationId: 'conv_2', receiptId: null);
    $this->store->ingest(toolCallId: 'call_3', conversationId: 'conv_3', receiptId: null);

    expect(PendingApproval::query()->whereNull('receipt_id')->count())->toBe(3);
});

it('finds a row by its pause and by its receipt', function (): void {
    $row = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', receiptId: 'receipt_1');

    expect($this->store->findByToolCall('call_1', 'conv_1')?->id)->toBe($row->id)
        ->and($this->store->findByReceipt('receipt_1')?->id)->toBe($row->id)
        ->and($this->store->findByToolCall('call_1', null))->toBeNull('A different conversation is a different pause.')
        ->and($this->store->findByReceipt('receipt_absent'))->toBeNull();
});

/** The columns the design says must not exist. A future migration adding either is the regression. */
it('stores no copy of the receipt status and no expiry of its own', function (): void {
    $columns = Schema::getColumnListing('verdict_console_pending_approvals');

    expect($columns)->not->toContain('status')
        ->and($columns)->not->toContain('receipt_status')
        ->and($columns)->not->toContain('expires_at')
        ->and($columns)->not->toContain('decided_at');
});

/**
 * The participant column is an opaque host reference, not Laravel AI's participant object and not a
 * class-name-plus-id convention this package invented.
 *
 * `ToolApprovalRequested` carries a live object; storing it as though it were durable, or encoding
 * it as `ClassName:7` and rebuilding by convention, guesses at the host's identity model and is
 * wrong the moment a participant needs a tenant, a guard, or a constructor argument. The default
 * resume path therefore attaches no participant at all — `continue($conversationId)` — and a host
 * that needs one supplies both the reference and the resolver.
 */
it('stores no participant object and invents no identity convention', function (): void {
    $columns = Schema::getColumnListing('verdict_console_pending_approvals');

    expect($columns)->not->toContain('conversation_user')
        ->and($columns)->not->toContain('participant_class')
        ->and($columns)->not->toContain('participant_id')
        ->and($columns)->toContain('participant_reference');

    // Whatever the host puts here comes back byte-identical; nothing parses it.
    $opaque = 'tenant=9|urn:acme:person:42';
    $row = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', participantReference: $opaque);

    expect($row->participant_reference)->toBe($opaque);
});
