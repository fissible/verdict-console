<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\ConversationCorrelation;
use Fissible\VerdictConsole\Evidence\ConversationInvocationStore;
use Fissible\VerdictConsole\Evidence\DatabaseEvidenceQuery;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidencePage;
use Fissible\VerdictConsole\Evidence\EvidenceQueryResult;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verdict's published evidence-table migrations, in the order a host runs them.
 *
 * Listed explicitly because order matters and the adapter reads the *resulting* schema; a test
 * below holds this list equal to what the installed Verdict actually publishes, so a new stub in
 * a Verdict release fails here instead of leaving the fixture quietly behind the real table.
 */
const VERDICT_EVIDENCE_STUBS = [
    'create_verdict_evidence_table.php.stub',
    'add_provenance_to_verdict_evidence_table.php.stub',
    'add_invocation_id_to_verdict_evidence_table.php.stub',
    'add_tool_kind_to_verdict_evidence_table.php.stub',
    'add_configuration_fingerprint_to_verdict_evidence_table.php.stub',
    'add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub',
    'add_target_source_to_verdict_evidence_table.php.stub',
    'add_tool_description_fingerprints_to_verdict_evidence_table.php.stub',
    'add_record_identity_to_verdict_evidence_table.php.stub',
    'add_review_outcome_to_verdict_evidence_table.php.stub',
    'add_intent_id_to_verdict_evidence_table.php.stub',
];

function verdictMigrationsPath(): string
{
    return dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';
}

beforeEach(function (): void {
    config()->set('verdict.evidence.table', 'console_test_evidence');
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    foreach (VERDICT_EVIDENCE_STUBS as $migration) {
        (require verdictMigrationsPath().'/'.$migration)->up();
    }

    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_conversation_invocations_table.php.stub')->up();
});

afterEach(function (): void {
    Schema::dropIfExists('console_test_evidence');
    Schema::dropIfExists('verdict_console_conversation_invocations');
});

it('builds the evidence fixture from every evidence-table stub the installed Verdict publishes', function (): void {
    $published = array_map(basename(...), glob(verdictMigrationsPath().'/*verdict_evidence_table.php.stub') ?: []);
    $listed = VERDICT_EVIDENCE_STUBS;
    sort($published);
    sort($listed);

    // Verdict adds columns to this table by additive stub; the adapter is only tested against the
    // real schema if the fixture applies all of them. A mismatch means a Verdict upgrade changed
    // the table and this file did not follow.
    expect($listed)->toBe($published);
});

it('exposes recording being off separately from an empty enabled evidence table', function (): void {
    $off = app(EvidenceQuery::class)->search(new EvidenceFilter);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $empty = app(EvidenceQuery::class)->search(new EvidenceFilter);

    expect($off->recording)->toBe(EvidenceRecordingState::Off)
        ->and($off->records)->toBe([])
        ->and($off->conversation)->toBeNull('No conversation was asked about, so none is reported on.')
        ->and($empty->recording)->toBe(EvidenceRecordingState::On)
        ->and($empty->records)->toBe([])
        ->and($empty->conversation)->toBeNull();
});

it('uses Verdicts narrow writer before the legacy recorder and distinguishes an unreadable writer', function (): void {
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    $tableWriter = app(EvidenceQuery::class)->search(new EvidenceFilter);

    config()->set('verdict.evidence.writer', 'App\\Evidence\\ExternalWriter');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $elsewhere = app(EvidenceQuery::class)->search(new EvidenceFilter);

    config()->set('verdict.evidence.writer', null);
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);

    $attestWriter = app(EvidenceQuery::class)->search(new EvidenceFilter);

    expect($tableWriter->recording)->toBe(EvidenceRecordingState::On)
        ->and($tableWriter->recordedBy)->toBeNull()
        ->and($elsewhere->recording)->toBe(EvidenceRecordingState::Elsewhere)
        ->and($elsewhere->recordedBy)->toBe('App\\Evidence\\ExternalWriter')
        ->and($elsewhere->records)->toBe([])
        ->and($attestWriter->recording)->toBe(EvidenceRecordingState::Chained, 'The attest recorder appends to a chain; calling it a readable table is the #104 defect.')
        ->and($attestWriter->recordedBy)->toBeNull();
});

/**
 * #104: the attest recorder appends decisions to the attest chain; the SQL table receives only
 * chain_gap markers. "On" over that table reads as "nothing happened" — the exact lie the Off and
 * Elsewhere states exist to prevent. The chained state claims configuration only: a chained sink
 * is selected — never that any append succeeded, that the chain verifies, or that no gap exists.
 */
it('calls a chained sink chained and keeps the unreadable tables rows out of the answer', function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', 'main-ledger');

    // What an attest-only host's table really holds: a gap marker. The decision row is seeded
    // anyway — an implementation that returns it is reading a table it just disclaimed.
    insertEvidence(['id' => 'gap-1', 'record_type' => 'chain_gap', 'stage' => 'decision', 'disposition' => 'gap', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'stray-decision', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:01:00']);

    // The disclaimed table is not merely filtered out — nothing is queried at all: on an
    // attest-only host verdict.evidence.connection may point somewhere this console cannot read,
    // so a query is itself a side effect. Every statement counts, not just ones naming the
    // fixture's table — a regression reading the fallback table by another name must fail too.
    $evidenceQueries = [];
    DB::listen(function ($query) use (&$evidenceQueries): void {
        $evidenceQueries[] = $query->sql;
    });

    $result = app(EvidenceQuery::class)->search(new EvidenceFilter);

    expect($result->recording)->toBe(EvidenceRecordingState::Chained)
        ->and($result->recording->value)->toBe('chained', 'Adapter surfaces key on the string value.')
        ->and($result->records)->toBe([])
        ->and($result->recordedBy)->toBe('main-ledger')
        ->and($evidenceQueries)->toBe([]);
});

/**
 * The identity is what configuration proves: the fixed chain id, or the class chosen to mint one —
 * never a resolved value. The resolver class deliberately does not exist: an implementation that
 * instantiates it to learn the chain id fails loudly here, exactly as the adapter's
 * configuration-inspection-only rule requires.
 */
it('names the configured chain identity without resolving anything', function (): void {
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', 'App\\Support\\UnresolvableTenantChainResolver');

    $resolver = app(EvidenceQuery::class)->search(new EvidenceFilter);

    config()->set('verdict.evidence.attest.chain_resolver', null);

    $unnamed = app(EvidenceQuery::class)->search(new EvidenceFilter);

    // The narrow writer reaches the same state the legacy recorder key does.
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.writer', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', 'tenant-42-ledger');

    $narrowWriter = app(EvidenceQuery::class)->search(new EvidenceFilter);

    // Verdict's recorder construction rejects both keys set together as mutually exclusive chain
    // topologies — but only when the recorder is resolved, which a console read never does. The
    // console reports the state honestly and picks no side of a topology Verdict itself rejects.
    config()->set('verdict.evidence.attest.chain_resolver', 'App\\Support\\UnresolvableTenantChainResolver');

    $both = app(EvidenceQuery::class)->search(new EvidenceFilter);

    expect($resolver->recording)->toBe(EvidenceRecordingState::Chained)
        ->and($resolver->recordedBy)->toBe('App\\Support\\UnresolvableTenantChainResolver')
        ->and($unnamed->recording)->toBe(EvidenceRecordingState::Chained)
        ->and($unnamed->recordedBy)->toBeNull('A misconfigured chained sink still is one; there is just no identity to name.')
        ->and($narrowWriter->recording)->toBe(EvidenceRecordingState::Chained)
        ->and($narrowWriter->recordedBy)->toBe('tenant-42-ledger')
        ->and($both->recording)->toBe(EvidenceRecordingState::Chained)
        ->and($both->recordedBy)->toBeNull('Both keys set is a topology Verdict rejects; the console names no identity rather than inventing a winner.');
});

/** The paged shape answers chained exactly as search() does — and no query touches the table. */
it('answers the chained state in the paged shape without touching the table', function (): void {
    (new ConversationInvocationStore)->record('invocation-1', 'conversation-a');
    insertEvidence(['id' => 'gap-1', 'record_type' => 'chain_gap', 'stage' => 'decision', 'disposition' => 'gap', 'recorded_at' => '2026-08-25 10:00:00']);

    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);
    config()->set('verdict.evidence.attest.chain', 'main-ledger');

    // Only the console-owned conversation-mapping reads are legitimate here. A statement is
    // unexpected unless it is a pure mapping read: a single SELECT (no subqueries), naming the
    // mapping table, no join, and no table whose name contains "evidence" — reaching the
    // disclaimed sink through a join, an EXISTS, or a differently named fallback table is still
    // a read of a sink this adapter just disclaimed.
    $evidenceQueries = [];
    DB::listen(function ($query) use (&$evidenceQueries): void {
        $sql = strtolower((string) $query->sql);

        if (! str_starts_with(ltrim($sql), 'select')
            || substr_count($sql, 'select') !== 1
            || ! str_contains($sql, 'verdict_console_conversation_invocations')
            || str_contains($sql, 'evidence')
            || str_contains($sql, 'join')) {
            $evidenceQueries[] = $sql;
        }
    });

    $page = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 1, perPage: 10);
    $known = app(EvidenceQuery::class)->searchPage(new EvidenceFilter(conversationId: 'conversation-a'), page: 1, perPage: 10);
    $unknown = app(EvidenceQuery::class)->searchPage(new EvidenceFilter(conversationId: 'conversation-never-seen'), page: 1, perPage: 10);

    // The console-owned conversation mapping still answers: whether Verdict retained anything this
    // adapter can read is a separate fact, exactly as the off and elsewhere states already pin.
    expect($page->recording)->toBe(EvidenceRecordingState::Chained)
        ->and($page->records)->toBe([])
        ->and($page->total)->toBe(0)
        ->and($page->recordedBy)->toBe('main-ledger')
        ->and($known->recording)->toBe(EvidenceRecordingState::Chained)
        ->and($known->conversation)->toBe(ConversationCorrelation::Known)
        ->and($known->records)->toBe([])
        ->and($known->total)->toBe(0)
        ->and($known->recordedBy)->toBe('main-ledger')
        ->and($unknown->recording)->toBe(EvidenceRecordingState::Chained)
        ->and($unknown->conversation)->toBe(ConversationCorrelation::Unknown)
        ->and($unknown->records)->toBe([])
        ->and($unknown->total)->toBe(0)
        ->and($unknown->recordedBy)->toBe('main-ledger')
        ->and($evidenceQueries)->toBe([]);
});

it('treats Verdicts absent recorder configuration as recording off', function (): void {
    $evidence = config('verdict.evidence');
    unset($evidence['recorder']);
    config()->set('verdict.evidence', $evidence);

    $result = app(EvidenceQuery::class)->search(new EvidenceFilter);

    expect($result->recording)->toBe(EvidenceRecordingState::Off)
        ->and($result->records)->toBe([]);
});

it('reads only decision evidence from Verdicts shipped schema without exposing raw values', function (): void {
    insertEvidence([
        'id' => 'decision-1',
        'record_type' => 'decision',
        'capability' => 'orders.refund',
        'stage' => 'proposal',
        'disposition' => 'throttle',
        'claim_type' => 'rate_limit.refused',
        'record_digest' => 'canonicaljson-sha256:decision-1',
        'reason' => 'The customer email and refund amount must not reach this surface.',
        'argument_fingerprint' => str_repeat('a', 64),
        'idempotency_key_fingerprint' => str_repeat('b', 64),
        'approval_receipt_fingerprint' => str_repeat('c', 64),
        'configuration_fingerprint' => str_repeat('d', 64),
        'actor_fingerprint' => str_repeat('e', 64),
        'subject_fingerprint' => str_repeat('f', 64),
        'proposal_target_identity_fingerprint' => str_repeat('1', 64),
        'execution_target_identity_fingerprint' => str_repeat('2', 64),
        'rate_limit_key_fingerprint' => str_repeat('3', 64),
        'execution_claim_fingerprint' => str_repeat('4', 64),
        'execution_claim_binding_fingerprint' => str_repeat('5', 64),
        'invocation_id' => 'invocation-1',
        'rate_limit_reset_at' => '2026-08-25 12:30:00',
        'recorded_at' => '2026-08-25 12:00:00',
    ]);
    insertEvidence([
        'id' => 'provenance-1',
        'record_type' => 'provenance',
        'stage' => 'input',
        'disposition' => 'recorded',
        'recorded_at' => '2026-08-25 12:01:00',
    ]);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $result = app(EvidenceQuery::class)->search(new EvidenceFilter);

    expect($result->recording)->toBe(EvidenceRecordingState::On)
        ->and($result->records)->toHaveCount(1)
        ->and($result->records[0])->toMatchArray([
            'id' => 'decision-1',
            'capability' => 'orders.refund',
            'stage' => 'proposal',
            'disposition' => 'throttle',
            'claimType' => 'rate_limit.refused',
            'recordDigest' => 'canonicaljson-sha256:decision-1',
            'argumentFingerprint' => str_repeat('a', 64),
            'idempotencyKeyFingerprint' => str_repeat('b', 64),
            'approvalReceiptFingerprint' => str_repeat('c', 64),
            'configurationFingerprint' => str_repeat('d', 64),
            'actorFingerprint' => str_repeat('e', 64),
            'subjectFingerprint' => str_repeat('f', 64),
            'proposalTargetIdentityFingerprint' => str_repeat('1', 64),
            'executionTargetIdentityFingerprint' => str_repeat('2', 64),
            'rateLimitKeyFingerprint' => str_repeat('3', 64),
            'executionClaimFingerprint' => str_repeat('4', 64),
            'executionClaimBindingFingerprint' => str_repeat('5', 64),
            'invocationId' => 'invocation-1',
            'rateLimitResetAt' => new DateTimeImmutable('2026-08-25 12:30:00 UTC'),
            'recordedAt' => new DateTimeImmutable('2026-08-25 12:00:00 UTC'),
        ])
        ->and(property_exists($result->records[0], 'reason'))->toBeFalse();
});

it('filters decision evidence by disposition, capability, and inclusive recorded-at bounds', function (): void {
    insertEvidence(['id' => 'permit', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'early-deny', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'first-deny', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'recorded_at' => '2026-08-25 11:00:00']);
    insertEvidence(['id' => 'second-deny', 'record_type' => 'decision', 'capability' => 'billing.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'recorded_at' => '2026-08-25 12:00:00']);
    insertEvidence(['id' => 'late-deny', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'recorded_at' => '2026-08-25 13:00:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $records = app(EvidenceQuery::class)->search(new EvidenceFilter(
        disposition: 'deny',
        capability: 'orders.refund',
        recordedFrom: new DateTimeImmutable('2026-08-25 06:00:00-05:00'),
        recordedUntil: new DateTimeImmutable('2026-08-25 07:00:00-05:00'),
    ))->records;

    expect(array_map(fn ($record) => $record->id, $records))->toBe(['first-deny']);
});

/**
 * #102: the six opaque fingerprint columns become pivot filters — "everything sharing this value"
 * — honored identically by both reads. Every other pivot's rows leave each column null, so the
 * exact answers also prove a null fingerprint never matches any set pivot.
 */
it('pivots both reads on each fingerprint field, and null never matches', function (): void {
    $pivots = [
        'actor_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(actorFingerprint: $value),
        'subject_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(subjectFingerprint: $value),
        'argument_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(argumentFingerprint: $value),
        'approval_receipt_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(approvalReceiptFingerprint: $value),
        'configuration_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(configurationFingerprint: $value),
        'execution_claim_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(executionClaimFingerprint: $value),
    ];

    foreach (array_keys($pivots) as $column) {
        insertEvidence(['id' => "old-{$column}", 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', $column => "sha256:{$column}-shared", 'recorded_at' => '2026-08-25 10:00:00']);
        insertEvidence(['id' => "new-{$column}", 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', $column => "sha256:{$column}-shared", 'recorded_at' => '2026-08-25 11:00:00']);
        insertEvidence(['id' => "other-{$column}", 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', $column => "sha256:{$column}-different", 'recorded_at' => '2026-08-25 12:00:00']);
        insertEvidence(['id' => "null-{$column}", 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 13:00:00']);
    }

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $ids = fn (array $records): array => array_map(fn ($record) => $record->id, $records);

    foreach ($pivots as $column => $filter) {
        $complete = app(EvidenceQuery::class)->search($filter("sha256:{$column}-shared"));
        $page = app(EvidenceQuery::class)->searchPage($filter("sha256:{$column}-shared"), page: 1, perPage: 1);

        expect($ids($complete->records))->toBe(["old-{$column}", "new-{$column}"], "The {$column} pivot must return exactly its sharing rows.")
            ->and($ids($page->records))->toBe(["new-{$column}"], "The paged {$column} pivot must cut the slice.")
            ->and($page->total)->toBe(2, "The paged {$column} pivot must cut the total with the slice.");
    }
});

/**
 * ADR 0008: fingerprints are opaque, so equality is the only honest question a pivot may ask — on
 * every field, and never a pattern: % and _ in a value are data, not wildcards.
 */
it('matches every fingerprint pivot exactly, never by prefix or pattern', function (): void {
    $pivots = [
        'actor_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(actorFingerprint: $value),
        'subject_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(subjectFingerprint: $value),
        'argument_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(argumentFingerprint: $value),
        'approval_receipt_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(approvalReceiptFingerprint: $value),
        'configuration_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(configurationFingerprint: $value),
        'execution_claim_fingerprint' => fn (string $value): EvidenceFilter => new EvidenceFilter(executionClaimFingerprint: $value),
    ];

    foreach (array_keys($pivots) as $column) {
        insertEvidence(['id' => "exact-{$column}", 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', $column => "sha256:{$column}-aaaa", 'recorded_at' => '2026-08-25 10:00:00']);
        insertEvidence(['id' => "longer-{$column}", 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', $column => "sha256:{$column}-aaaa00", 'recorded_at' => '2026-08-25 10:01:00']);
        insertEvidence(['id' => "percent-{$column}", 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', $column => "sha256:{$column}-aa%", 'recorded_at' => '2026-08-25 10:02:00']);
        insertEvidence(['id' => "underscore-{$column}", 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', $column => "sha256:{$column}-a_a", 'recorded_at' => '2026-08-25 10:03:00']);
        insertEvidence(['id' => "wildcard-bait-{$column}", 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', $column => "sha256:{$column}-aXa", 'recorded_at' => '2026-08-25 10:04:00']);
    }

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $ids = fn (array $records): array => array_map(fn ($record) => $record->id, $records);

    foreach ($pivots as $column => $filter) {
        $exact = app(EvidenceQuery::class)->search($filter("sha256:{$column}-aaaa"));
        $percent = app(EvidenceQuery::class)->search($filter("sha256:{$column}-aa%"));
        $underscore = app(EvidenceQuery::class)->search($filter("sha256:{$column}-a_a"));
        $prefix = app(EvidenceQuery::class)->search($filter("sha256:{$column}-aa"));

        expect($ids($exact->records))->toBe(["exact-{$column}"], "The {$column} pivot must match its exact value only.")
            ->and($ids($percent->records))->toBe(["percent-{$column}"], "A % in the {$column} value is data, never a wildcard.")
            ->and($ids($underscore->records))->toBe(["underscore-{$column}"], "A _ in the {$column} value is data, never a single-character wildcard.")
            ->and($prefix->records)->toBe([], "A prefix of a {$column} fingerprint identifies nothing.");
    }
});

/** Every pivot composes with the others and the existing filters as AND, on both reads. */
it('composes every fingerprint pivot and the existing filters as AND', function (): void {
    $shared = [
        'actor_fingerprint' => 'sha256:actor-a',
        'subject_fingerprint' => 'sha256:subject-s',
        'argument_fingerprint' => 'sha256:argument-g',
        'approval_receipt_fingerprint' => 'sha256:receipt-r',
        'configuration_fingerprint' => 'sha256:configuration-c',
        'execution_claim_fingerprint' => 'sha256:claim-x',
    ];

    // Every row shares the same capability and invocation and sits inside the filtered window, so
    // each decoy below is otherwise identical to the match on every non-pivot constraint too.
    insertEvidence(['id' => 'all-match', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 10:00:00', ...$shared]);

    // Six decoys, each agreeing on everything except exactly one pivot: any pivot an
    // implementation ORs, ignores, or overwrites — on the plain path or the invocation-constrained
    // one — lets its decoy through.
    $minute = 1;
    foreach (array_keys($shared) as $column) {
        insertEvidence(['id' => "differs-{$column}", 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'invocation_id' => 'invocation-1', 'recorded_at' => sprintf('2026-08-25 10:%02d:00', $minute++), ...array_merge($shared, [$column => 'sha256:decoy'])]);
    }

    insertEvidence(['id' => 'same-prints-wrong-disposition', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'permit', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 10:07:00', ...$shared]);
    // A second full match sitting exactly on recordedUntil, with all-match exactly on recordedFrom:
    // the window stays inclusive at both edges on the pivot path too.
    insertEvidence(['id' => 'at-until', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 10:08:00', ...$shared]);
    insertEvidence(['id' => 'past-until', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 10:09:00', ...$shared]);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $filter = new EvidenceFilter(
        disposition: 'deny',
        capability: 'orders.refund',
        recordedFrom: new DateTimeImmutable('2026-08-25 10:00:00 UTC'),
        recordedUntil: new DateTimeImmutable('2026-08-25 10:08:00 UTC'),
        invocationId: 'invocation-1',
        actorFingerprint: 'sha256:actor-a',
        subjectFingerprint: 'sha256:subject-s',
        argumentFingerprint: 'sha256:argument-g',
        approvalReceiptFingerprint: 'sha256:receipt-r',
        configurationFingerprint: 'sha256:configuration-c',
        executionClaimFingerprint: 'sha256:claim-x',
    );

    $complete = app(EvidenceQuery::class)->search($filter);
    $page = app(EvidenceQuery::class)->searchPage($filter, page: 1, perPage: 10);

    expect(array_map(fn ($record) => $record->id, $complete->records))->toBe(['all-match', 'at-until'])
        ->and(array_map(fn ($record) => $record->id, $page->records))->toBe(['at-until', 'all-match'])
        ->and($page->total)->toBe(2);
});

/**
 * A pivot must not cost any existing constraint: capability, the recorded window, and the
 * conversation correlation all keep cutting beside it, on both reads — an implementation that
 * rebuilds the query for a fingerprint and drops an earlier clause fails here.
 */
it('keeps every existing constraint cutting beside a fingerprint pivot', function (): void {
    $correlations = new ConversationInvocationStore;
    $correlations->record('invocation-1', 'conversation-a');
    $correlations->record('invocation-2', 'conversation-a');

    insertEvidence(['id' => 'kept', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'permit', 'actor_fingerprint' => 'sha256:actor-a', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 11:00:00']);
    insertEvidence(['id' => 'other-capability', 'record_type' => 'decision', 'capability' => 'billing.refund', 'stage' => 'proposal', 'disposition' => 'permit', 'actor_fingerprint' => 'sha256:actor-a', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 11:01:00']);
    insertEvidence(['id' => 'outside-window', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'permit', 'actor_fingerprint' => 'sha256:actor-a', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 14:00:00']);
    insertEvidence(['id' => 'outside-conversation', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'permit', 'actor_fingerprint' => 'sha256:actor-a', 'invocation_id' => 'invocation-unmapped', 'recorded_at' => '2026-08-25 11:02:00']);
    insertEvidence(['id' => 'other-actor', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'permit', 'actor_fingerprint' => 'sha256:actor-y', 'invocation_id' => 'invocation-2', 'recorded_at' => '2026-08-25 11:03:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $filter = new EvidenceFilter(
        capability: 'orders.refund',
        recordedFrom: new DateTimeImmutable('2026-08-25 10:30:00 UTC'),
        recordedUntil: new DateTimeImmutable('2026-08-25 12:00:00 UTC'),
        conversationId: 'conversation-a',
        actorFingerprint: 'sha256:actor-a',
    );

    $complete = app(EvidenceQuery::class)->search($filter);
    $page = app(EvidenceQuery::class)->searchPage($filter, page: 1, perPage: 10);

    // And the direct invocation constraint: same actor, same capability, inside the window, but on
    // another invocation — only the invocation_id clause excludes it.
    insertEvidence(['id' => 'same-actor-other-invocation', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'permit', 'actor_fingerprint' => 'sha256:actor-a', 'invocation_id' => 'invocation-2', 'recorded_at' => '2026-08-25 11:04:00']);

    $invocationFilter = new EvidenceFilter(
        capability: 'orders.refund',
        recordedFrom: new DateTimeImmutable('2026-08-25 10:30:00 UTC'),
        recordedUntil: new DateTimeImmutable('2026-08-25 12:00:00 UTC'),
        invocationId: 'invocation-1',
        actorFingerprint: 'sha256:actor-a',
    );

    $invocationComplete = app(EvidenceQuery::class)->search($invocationFilter);
    $invocationPage = app(EvidenceQuery::class)->searchPage($invocationFilter, page: 1, perPage: 10);

    expect(array_map(fn ($record) => $record->id, $complete->records))->toBe(['kept'])
        ->and($complete->conversation)->toBe(ConversationCorrelation::Known)
        ->and(array_map(fn ($record) => $record->id, $page->records))->toBe(['kept'])
        ->and($page->total)->toBe(1)
        ->and(array_map(fn ($record) => $record->id, $invocationComplete->records))->toBe(['kept'])
        ->and(array_map(fn ($record) => $record->id, $invocationPage->records))->toBe(['kept'])
        ->and($invocationPage->total)->toBe(1);
});

/**
 * The pivots are additive: every existing field keeps its position ahead of them, so positional
 * construction and replacement boundary implementations compile unchanged.
 */
it('keeps every existing filter field in its position ahead of the pivots', function (): void {
    $from = new DateTimeImmutable('2026-08-25 10:00:00 UTC');
    $until = new DateTimeImmutable('2026-08-25 11:00:00 UTC');

    $filter = new EvidenceFilter('deny', 'orders.refund', $from, $until, 'conversation-a', 'invocation-1');

    expect($filter->disposition)->toBe('deny')
        ->and($filter->capability)->toBe('orders.refund')
        ->and($filter->recordedFrom)->toBe($from)
        ->and($filter->recordedUntil)->toBe($until)
        ->and($filter->conversationId)->toBe('conversation-a')
        ->and($filter->invocationId)->toBe('invocation-1')
        ->and($filter->actorFingerprint)->toBeNull()
        ->and($filter->subjectFingerprint)->toBeNull()
        ->and($filter->argumentFingerprint)->toBeNull()
        ->and($filter->approvalReceiptFingerprint)->toBeNull()
        ->and($filter->configurationFingerprint)->toBeNull()
        ->and($filter->executionClaimFingerprint)->toBeNull();
});

it('filters decision evidence by invocation without needing a conversation mapping', function (): void {
    insertEvidence(['id' => 'in-scope', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'other-invocation', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'invocation_id' => 'invocation-2', 'recorded_at' => '2026-08-25 10:01:00']);
    insertEvidence(['id' => 'outside-any-invocation', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:02:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $result = app(EvidenceQuery::class)->search(new EvidenceFilter(invocationId: 'invocation-1'));

    expect(array_map(fn ($record) => $record->id, $result->records))->toBe(['in-scope'])
        ->and($result->conversation)->toBeNull();
});

/**
 * "Evidence for a conversation" is not native: `DecisionEvidence` carries an invocation id and no
 * conversation id (design §6.6). The console's own projection supplies the join, and it must span
 * every invocation the conversation had — a pause and its resume are two — while a decision made
 * outside any Laravel AI invocation can never belong to one.
 */
it('returns evidence across every invocation the console observed for a conversation', function (): void {
    $correlations = new ConversationInvocationStore;
    $correlations->record('invocation-pause', 'conversation-a');
    $correlations->record('invocation-resume', 'conversation-a');
    $correlations->record('invocation-other', 'conversation-b');

    insertEvidence(['id' => 'pause', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'require_confirmation', 'invocation_id' => 'invocation-pause', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'resume', 'record_type' => 'decision', 'stage' => 'execution', 'disposition' => 'permit', 'invocation_id' => 'invocation-resume', 'recorded_at' => '2026-08-25 10:05:00']);
    insertEvidence(['id' => 'other-conversation', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'invocation_id' => 'invocation-other', 'recorded_at' => '2026-08-25 10:06:00']);
    insertEvidence(['id' => 'outside-any-invocation', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:07:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $result = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: 'conversation-a'));

    expect($result->recording)->toBe(EvidenceRecordingState::On)
        ->and($result->conversation)->toBe(ConversationCorrelation::Known)
        ->and(array_map(fn ($record) => $record->id, $result->records))->toBe(['pause', 'resume']);
});

/**
 * The acceptance criterion's second half: a missing mapping degrades explicitly. An empty result
 * for a conversation the console never saw would read as "nothing was decided," when the honest
 * statement is "this console cannot say" — the conversation may predate the projection, or have
 * run without the boundaries that feed it.
 */
it('reports a conversation the console never observed as unknown rather than as empty evidence', function (): void {
    (new ConversationInvocationStore)->record('invocation-1', 'conversation-a');
    insertEvidence(['id' => 'decided', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 10:00:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $unknown = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: 'conversation-never-seen'));
    $known = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: 'conversation-a'));

    expect($unknown->recording)->toBe(EvidenceRecordingState::On)
        ->and($unknown->conversation)->toBe(ConversationCorrelation::Unknown)
        ->and($unknown->records)->toBe([])
        ->and($known->conversation)->toBe(ConversationCorrelation::Known)
        ->and($known->records)->toHaveCount(1);
});

/**
 * The mapping is console-owned; whether Verdict retained anything to join it to is a separate fact,
 * and it is answered the same way whether recording is off or happening somewhere this adapter
 * cannot read.
 */
it('reports the conversation mapping independently of whether Verdict is recording', function (): void {
    (new ConversationInvocationStore)->record('invocation-1', 'conversation-a');

    $off = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: 'conversation-a'));

    config()->set('verdict.evidence.writer', 'App\\Evidence\\ExternalWriter');

    $elsewhere = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: 'conversation-a'));
    $elsewhereUnknown = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: 'conversation-never-seen'));

    expect($off->recording)->toBe(EvidenceRecordingState::Off)
        ->and($off->conversation)->toBe(ConversationCorrelation::Known)
        ->and($off->records)->toBe([])
        ->and($elsewhere->recording)->toBe(EvidenceRecordingState::Elsewhere)
        ->and($elsewhere->conversation)->toBe(ConversationCorrelation::Known)
        ->and($elsewhere->records)->toBe([])
        ->and($elsewhereUnknown->recording)->toBe(EvidenceRecordingState::Elsewhere)
        ->and($elsewhereUnknown->conversation)->toBe(ConversationCorrelation::Unknown);
});

it('narrows a conversation filter by invocation rather than widening it', function (): void {
    $correlations = new ConversationInvocationStore;
    $correlations->record('invocation-1', 'conversation-a');
    $correlations->record('invocation-2', 'conversation-b');

    insertEvidence(['id' => 'row-1', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'row-2', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'invocation_id' => 'invocation-2', 'recorded_at' => '2026-08-25 10:01:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $inside = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: 'conversation-a', invocationId: 'invocation-1'));
    $outside = app(EvidenceQuery::class)->search(new EvidenceFilter(conversationId: 'conversation-a', invocationId: 'invocation-2'));

    expect(array_map(fn ($record) => $record->id, $inside->records))->toBe(['row-1'])
        ->and($outside->records)->toBe([])
        ->and($outside->conversation)->toBe(ConversationCorrelation::Known);
});

it('keeps the read boundary independent of Verdicts writer and recorder implementations', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/src/Evidence/DatabaseEvidenceQuery.php');

    expect($source)->not->toContain('Fissible\\Verdict\\Contracts\\EvidenceWriter')
        ->and($source)->not->toContain('Fissible\\Verdict\\Contracts\\EvidenceRecorder')
        ->and($source)->not->toContain('Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder')
        ->and($source)->not->toContain('Fissible\\Verdict\\Evidence\\DecisionEvidence');
});

it('binds the shipped table adapter to a contract a host may replace', function (): void {
    expect(app(EvidenceQuery::class))->toBeInstanceOf(DatabaseEvidenceQuery::class);

    $replacement = new class implements EvidenceQuery
    {
        public function search(EvidenceFilter $filter): EvidenceQueryResult
        {
            return new EvidenceQueryResult(EvidenceRecordingState::On, []);
        }

        public function searchPage(EvidenceFilter $filter, int $page, int $perPage): EvidencePage
        {
            return new EvidencePage(EvidenceRecordingState::On, [], total: 0, page: $page, perPage: $perPage);
        }
    };

    app()->instance(EvidenceQuery::class, $replacement);

    expect(app(EvidenceQuery::class))->toBe($replacement);
});

// --- paged read -----------------------------------------------------------------------------------

/**
 * The paged read shape (#99): the same boundary, answering one page newest-first with the filtered
 * total, so a surface whose evidence volume outgrows the complete projection can stop
 * materializing it. search() and its complete-projection contract are unchanged.
 */
it('answers one page of decision evidence newest first with the filtered total', function (): void {
    foreach (range(1, 5) as $i) {
        insertEvidence(['id' => 'row-'.$i, 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:0'.$i.':00']);
    }
    insertEvidence(['id' => 'provenance-1', 'record_type' => 'provenance', 'stage' => 'input', 'disposition' => 'recorded', 'recorded_at' => '2026-08-25 11:00:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $page = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 2, perPage: 2);

    expect($page->recording)->toBe(EvidenceRecordingState::On)
        ->and(array_map(fn ($record) => $record->id, $page->records))->toBe(['row-3', 'row-2'])
        ->and($page->total)->toBe(5)
        ->and($page->page)->toBe(2)
        ->and($page->perPage)->toBe(2);
});

it('orders same-instant evidence by id descending so pages cannot overlap between requests', function (): void {
    insertEvidence(['id' => 'tie-a', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'tie-b', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'tie-c', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:00:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $first = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 1, perPage: 2);
    $second = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 2, perPage: 2);

    expect(array_map(fn ($record) => $record->id, $first->records))->toBe(['tie-c', 'tie-b'])
        ->and(array_map(fn ($record) => $record->id, $second->records))->toBe(['tie-a']);
});

it('applies every filter to the page and its total together', function (): void {
    insertEvidence(['id' => 'permit', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'deny-early', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 09:00:00']);
    insertEvidence(['id' => 'deny-1', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 11:00:00']);
    insertEvidence(['id' => 'deny-2', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'invocation_id' => 'invocation-2', 'recorded_at' => '2026-08-25 12:00:00']);
    insertEvidence(['id' => 'deny-late', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'invocation_id' => 'invocation-1', 'recorded_at' => '2026-08-25 14:00:00']);
    insertEvidence(['id' => 'deny-other', 'record_type' => 'decision', 'capability' => 'billing.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'recorded_at' => '2026-08-25 13:00:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    // The slice honors every filter and the total counts the whole filtered set, not the table —
    // the recorded-at bounds and the invocation filter must cut the count exactly as they cut the page.
    $bounded = app(EvidenceQuery::class)->searchPage(
        new EvidenceFilter(
            disposition: 'deny',
            capability: 'orders.refund',
            recordedFrom: new DateTimeImmutable('2026-08-25 10:00:00 UTC'),
            recordedUntil: new DateTimeImmutable('2026-08-25 13:00:00 UTC'),
        ),
        page: 1,
        perPage: 1,
    );

    expect(array_map(fn ($record) => $record->id, $bounded->records))->toBe(['deny-2'])
        ->and($bounded->total)->toBe(2);

    $invocation = app(EvidenceQuery::class)->searchPage(new EvidenceFilter(invocationId: 'invocation-1'), page: 1, perPage: 2);

    expect(array_map(fn ($record) => $record->id, $invocation->records))->toBe(['deny-late', 'deny-1'])
        ->and($invocation->total)->toBe(3);
});

it('spans a conversations invocations in the paged read and degrades unknown ones identically', function (): void {
    $correlations = new ConversationInvocationStore;
    $correlations->record('invocation-pause', 'conversation-a');
    $correlations->record('invocation-resume', 'conversation-a');

    insertEvidence(['id' => 'pause', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'require_confirmation', 'invocation_id' => 'invocation-pause', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'resume', 'record_type' => 'decision', 'stage' => 'execution', 'disposition' => 'permit', 'invocation_id' => 'invocation-resume', 'recorded_at' => '2026-08-25 10:05:00']);
    insertEvidence(['id' => 'unrelated', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:06:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $known = app(EvidenceQuery::class)->searchPage(new EvidenceFilter(conversationId: 'conversation-a'), page: 1, perPage: 10);
    $unknown = app(EvidenceQuery::class)->searchPage(new EvidenceFilter(conversationId: 'conversation-never-seen'), page: 1, perPage: 10);

    expect($known->conversation)->toBe(ConversationCorrelation::Known)
        ->and(array_map(fn ($record) => $record->id, $known->records))->toBe(['resume', 'pause'])
        ->and($known->total)->toBe(2)
        ->and($unknown->conversation)->toBe(ConversationCorrelation::Unknown)
        ->and($unknown->records)->toBe([])
        ->and($unknown->total)->toBe(0);
});

it('answers off and elsewhere recording states in the paged shape without touching the table', function (): void {
    (new ConversationInvocationStore)->record('invocation-1', 'conversation-a');
    insertEvidence(['id' => 'retained', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:00:00']);

    $evidenceQueries = [];
    DB::listen(function ($query) use (&$evidenceQueries): void {
        if (str_contains($query->sql, 'console_test_evidence')) {
            $evidenceQueries[] = $query->sql;
        }
    });

    $off = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 1, perPage: 10);
    $offKnown = app(EvidenceQuery::class)->searchPage(new EvidenceFilter(conversationId: 'conversation-a'), page: 1, perPage: 10);

    config()->set('verdict.evidence.writer', 'App\\Evidence\\ExternalWriter');

    $elsewhere = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 1, perPage: 10);
    $elsewhereUnknown = app(EvidenceQuery::class)->searchPage(new EvidenceFilter(conversationId: 'conversation-never-seen'), page: 1, perPage: 10);

    // Rows the configuration says this adapter may not vouch for stay out of the page AND the
    // total — a nonzero total over an unreadable page would invent evidence — while the
    // console-owned conversation mapping still answers, exactly as search() does. And the claim in
    // this test's name is measured: no query touches the evidence table in any of these calls.
    expect($off->recording)->toBe(EvidenceRecordingState::Off)
        ->and($off->records)->toBe([])
        ->and($off->total)->toBe(0)
        ->and($offKnown->conversation)->toBe(ConversationCorrelation::Known)
        ->and($offKnown->records)->toBe([])
        ->and($offKnown->total)->toBe(0)
        ->and($elsewhere->recording)->toBe(EvidenceRecordingState::Elsewhere)
        ->and($elsewhere->recordedBy)->toBe('App\\Evidence\\ExternalWriter')
        ->and($elsewhere->records)->toBe([])
        ->and($elsewhere->total)->toBe(0)
        ->and($elsewhereUnknown->conversation)->toBe(ConversationCorrelation::Unknown)
        ->and($elsewhereUnknown->total)->toBe(0)
        ->and($evidenceQueries)->toBe([]);
});

it('returns an empty page with the real total beyond the last page and clamps degenerate inputs', function (): void {
    insertEvidence(['id' => 'only', 'record_type' => 'decision', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:00:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $beyond = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 9, perPage: 10);
    $clamped = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 0, perPage: 0);

    // An audit page fed page=0 by a UI must not throw or lie; it answers the first page, one row.
    expect($beyond->records)->toBe([])
        ->and($beyond->total)->toBe(1)
        ->and($beyond->page)->toBe(9)
        ->and($clamped->page)->toBe(1)
        ->and($clamped->perPage)->toBe(1)
        ->and(array_map(fn ($record) => $record->id, $clamped->records))->toBe(['only']);
});

/** @param array<string, mixed> $attributes */
function insertEvidence(array $attributes): void
{
    DB::table('console_test_evidence')->insert([
        'id' => $attributes['id'],
        'record_type' => $attributes['record_type'],
        'capability' => $attributes['capability'] ?? null,
        'stage' => $attributes['stage'],
        'disposition' => $attributes['disposition'],
        'reason' => $attributes['reason'] ?? null,
        'argument_fingerprint' => $attributes['argument_fingerprint'] ?? null,
        'idempotency_key_fingerprint' => $attributes['idempotency_key_fingerprint'] ?? null,
        'approval_receipt_fingerprint' => $attributes['approval_receipt_fingerprint'] ?? null,
        'proposal_target_identity_fingerprint' => $attributes['proposal_target_identity_fingerprint'] ?? null,
        'execution_target_identity_fingerprint' => $attributes['execution_target_identity_fingerprint'] ?? null,
        'rate_limit_key_fingerprint' => $attributes['rate_limit_key_fingerprint'] ?? null,
        'execution_claim_fingerprint' => $attributes['execution_claim_fingerprint'] ?? null,
        'execution_claim_binding_fingerprint' => $attributes['execution_claim_binding_fingerprint'] ?? null,
        'configuration_fingerprint' => $attributes['configuration_fingerprint'] ?? null,
        'actor_fingerprint' => $attributes['actor_fingerprint'] ?? null,
        'subject_fingerprint' => $attributes['subject_fingerprint'] ?? null,
        'claim_type' => $attributes['claim_type'] ?? null,
        'record_digest' => $attributes['record_digest'] ?? null,
        'invocation_id' => $attributes['invocation_id'] ?? null,
        'rate_limit_reset_at' => $attributes['rate_limit_reset_at'] ?? null,
        'recorded_at' => $attributes['recorded_at'],
    ]);
}
