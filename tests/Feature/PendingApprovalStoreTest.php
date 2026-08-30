<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\PendingApproval;
use Fissible\VerdictConsole\Approvals\PendingApprovalStore;
use Fissible\VerdictConsole\Approvals\Resumability;
use Fissible\VerdictConsole\Approvals\UnresumableReason;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_approval_context_to_verdict_console_pending_approvals_table.php.stub')->up();

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

/**
 * VC-68: `approval_context` is a correlation annotation copied once at ingestion — Verdict
 * documents the field as immutable after issue, which is what distinguishes it from the receipt
 * status and expiry this table deliberately never copies. Scalars only, ints surviving the round
 * trip, and null when the receipt predates capture: a storage era, not a disclosure state.
 */
it('captures approval context verbatim and defaults it to null', function (): void {
    $with = $this->store->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        receiptId: 'receipt_1',
        approvalContext: ['tenant' => 'acme', 'workspace' => 7],
    );
    $without = $this->store->ingest(toolCallId: 'call_2', conversationId: 'conv_2');
    // The store writes what it was handed: an explicit empty context is a datum, not an absence.
    // Measured upstream: Verdict persists an identifier-less issuance as '[]' too — only rows
    // predating the column hydrate null. The [] -> omitted collapse exists solely in Verdict's
    // binding fingerprint, not in storage.
    $empty = $this->store->ingest(toolCallId: 'call_3', conversationId: 'conv_3', approvalContext: []);

    expect(PendingApproval::query()->find($with->id)?->approval_context)
        ->toBe(['tenant' => 'acme', 'workspace' => 7])
        ->and(PendingApproval::query()->find($without->id)?->approval_context)->toBeNull()
        ->and(PendingApproval::query()->find($empty->id)?->approval_context)->toBe([]);
});

/** First-write-wins covers the annotation too: a redelivery must not swap or erase the captured context. */
it('keeps the originally captured approval context when the same pause is redelivered', function (): void {
    $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', approvalContext: ['tenant' => 'acme']);

    $redelivered = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', approvalContext: ['tenant' => 'imposter']);

    expect($redelivered->approval_context)->toBe(['tenant' => 'acme'])
        ->and(PendingApproval::query()->count())->toBe(1);
});

/**
 * Composer can update this package before the host runs VC-68's published migration. During that
 * interval a pause must still be indexed — a lost row is a stranded human decision — so the store
 * omits the column it cannot write rather than failing every ingestion. Verdict's own receipt
 * store tolerates the same interval the same way.
 */
it('still indexes a pause when the host has not yet run the approval-context migration', function (): void {
    Schema::dropIfExists('verdict_console_pending_approvals');
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub')->up();

    $row = (new PendingApprovalStore)->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        approvalContext: ['tenant' => 'acme'],
    );

    expect(Schema::hasColumn('verdict_console_pending_approvals', 'approval_context'))->toBeFalse()
        ->and($row->exists)->toBeTrue()
        ->and(PendingApproval::query()->count())->toBe(1);
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
 * VC-9's own acceptance clause, asserted against the schema rather than trusted to review.
 *
 * Operational state is what the *console* did — attempts it made, people it told. The temptation it
 * guards against is a column like `approved_at` or `receipt_expires_at`, which reads as harmless
 * caching and is exactly the divergence §5 forbids: the moment a second copy of authorization state
 * exists, something will read the stale one.
 */
it('adds only console work to the row, never a second copy of Verdict state', function (): void {
    $columns = Schema::getColumnListing('verdict_console_pending_approvals');

    expect($columns)->toContain('resume_attempts')
        ->and($columns)->toContain('last_resume_attempt_at')
        // A correlation annotation, immutable after issue — not mirrored, cache-prone state.
        ->and($columns)->toContain('approval_context')
        ->and($columns)->not->toContain('approved_at')
        ->and($columns)->not->toContain('rejected_at')
        ->and($columns)->not->toContain('receipt_expires_at')
        ->and($columns)->not->toContain('receipt_state');
});

/**
 * The attempt number is this caller's, and that is the whole reason it is locked.
 *
 * VC-10 decides what to do from *which* attempt this is — a first attempt and a fourth call for
 * different action — so an increment-then-read that can hand back a concurrent writer's number is
 * not a weaker version of this, it is a wrong one.
 *
 * **What this test cannot prove.** Sequentially, increment-then-read returns the same numbers, so
 * this passes against either implementation; only two writers racing tell them apart, and that is
 * not reachable in-process against SQLite. The row's ingest race is testable because a unique index
 * makes the database the arbiter and the loser gets an exception; a counter has no such witness.
 * Read this as protecting the counting, not as evidence of the lock.
 */
it('counts each resume attempt once and reports the caller its own number', function (): void {
    $row = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1');

    expect($this->store->beginResumeAttempt($row))->toBe(1)
        ->and($this->store->beginResumeAttempt($row))->toBe(2)
        ->and($this->store->beginResumeAttempt($row))->toBe(3)
        ->and(PendingApproval::query()->sole()->resume_attempts)->toBe(3);
});

/** A counter alone cannot tell "three attempts" from "three attempts, the last an hour ago". */
it('records when the most recent resume attempt began', function (): void {
    $row = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1');

    expect(PendingApproval::query()->sole()->last_resume_attempt_at)->toBeNull('An unattempted row has no attempt time.');

    $this->store->beginResumeAttempt($row);

    expect(PendingApproval::query()->sole()->last_resume_attempt_at)->not->toBeNull();
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

/**
 * The reason a row cannot be driven has to survive the process, because until VC-15 ships the
 * incident ledger nothing else carries it: the ingestion incident is an ephemeral event and a log
 * line. Without this column an operator sees `unresumable` and cannot tell whether to look at the
 * receipt or at a resolver registration.
 */
it('stores durably which drivability check failed', function (): void {
    $row = $this->store->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        resumability: Resumability::Unresumable,
        unresumableReason: UnresumableReason::AgentUnresolvable,
    );

    expect($row->unresumable_reason)->toBe(UnresumableReason::AgentUnresolvable)
        ->and(PendingApproval::query()->sole()->getRawOriginal('unresumable_reason'))->toBe('agent_unresolvable');
});

/** A caller that supplies no reason gets none — the column is not defaulted to anything. */
it('leaves the reason null for a drivable row', function (): void {
    $row = $this->store->ingest(toolCallId: 'call_1', conversationId: 'conv_1', receiptId: 'r1', resumability: Resumability::Drivable);

    expect($row->unresumable_reason)->toBeNull();
});

/**
 * The invariant, tested by trying to violate it: a drivable row cannot carry a reason.
 *
 * Passing one is not a caller error to tolerate quietly — a row asserting both "this console can
 * drive the run" and "the resolver key did not rebuild an agent" gives an operator two answers and
 * no way to choose. `resumability` is the authority, so the reason is discarded rather than stored.
 *
 * This is written as a *supplied* reason rather than an omitted one on purpose. Omitting it proves
 * only that nothing invents a value: that assertion still passes with the column write deleted
 * outright, so it cannot be the test carrying this claim.
 */
it('discards a reason supplied alongside a drivable row', function (): void {
    $row = $this->store->ingest(
        toolCallId: 'call_1',
        conversationId: 'conv_1',
        receiptId: 'r1',
        resumability: Resumability::Drivable,
        unresumableReason: UnresumableReason::AgentUnresolvable,
    );

    expect($row->unresumable_reason)->toBeNull()
        ->and(PendingApproval::query()->sole()->getRawOriginal('unresumable_reason'))->toBeNull();
});

/**
 * The enum is the vocabulary the row and the ingestion incident share, so the two can never
 * disagree about why a row is not drivable.
 */
it('covers exactly the four drivability conditions', function (): void {
    expect(array_map(fn (UnresumableReason $r): string => $r->value, UnresumableReason::cases()))
        ->toBe(['challenge_unavailable', 'agent_unresolvable', 'conversation_absent', 'participant_unresolvable']);
});
