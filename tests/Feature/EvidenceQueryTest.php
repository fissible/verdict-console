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
        ->and($attestWriter->recording)->toBe(EvidenceRecordingState::On);
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
