<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\ConversationInvocationStore;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidenceQueryResult;
use Fissible\VerdictConsole\Evidence\EvidenceRecord;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * The audit page is a pure function of the VC-13 read boundary: recording state, the records, and
 * the VC-14 conversation correlation. Everything here renders from real rows in Verdict's published
 * schema under a real recorder configuration; nothing is mocked.
 */
const AUDIT_EVIDENCE_STUBS = [
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

beforeEach(function (): void {
    config()->set('verdict.evidence.table', 'console_audit_evidence');
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    $migrations = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';

    foreach (AUDIT_EVIDENCE_STUBS as $stub) {
        (require $migrations.'/'.$stub)->up();
    }

    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_conversation_invocations_table.php.stub')->up();
});

afterEach(function (): void {
    Schema::dropIfExists('console_audit_evidence');
    Schema::dropIfExists('verdict_console_conversation_invocations');
});

/** Same guard the evidence-query suite carries: a new Verdict stub must not leave this fixture behind. */
it('builds its fixture from every evidence-table stub the installed Verdict publishes', function (): void {
    $published = array_map(basename(...), glob(dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/*verdict_evidence_table.php.stub') ?: []);

    expect(AUDIT_EVIDENCE_STUBS)->toEqualCanonicalizing($published);
});

/** @param array<string, mixed> $attributes */
function insertAuditEvidence(array $attributes): void
{
    DB::table('console_audit_evidence')->insert([
        'id' => $attributes['id'],
        'record_type' => $attributes['record_type'] ?? 'decision',
        'capability' => $attributes['capability'] ?? 'orders.cancel',
        'stage' => $attributes['stage'] ?? 'proposal',
        'disposition' => $attributes['disposition'] ?? 'permit',
        'reason' => $attributes['reason'] ?? null,
        'claim_type' => $attributes['claim_type'] ?? null,
        'record_digest' => $attributes['record_digest'] ?? null,
        'invocation_id' => $attributes['invocation_id'] ?? null,
        'recorded_at' => $attributes['recorded_at'],
    ]);
}

/** Ten decisions, a minute apart, newest last in the table so "newest first" is a real ordering claim. */
function seedAuditEvidence(int $count = 10): void
{
    for ($i = 1; $i <= $count; $i++) {
        insertAuditEvidence([
            'id' => sprintf('decision-%02d', $i),
            'disposition' => $i % 3 === 0 ? 'deny' : 'permit',
            'claim_type' => $i % 3 === 0 ? 'policy.denied' : null,
            'record_digest' => 'canonicaljson-sha256:'.str_repeat((string) ($i % 10), 8),
            'invocation_id' => 'invocation-'.(int) ceil($i / 5),
            'recorded_at' => sprintf('2026-08-30 10:%02d:00', $i),
        ]);
    }
}

function renderEvidence(string $attributes = '', array $data = []): string
{
    return (string) test()->blade('<x-verdict-console::evidence '.$attributes.' />', $data);
}

/** The recorded rows in rendered order: [id, disposition] pairs read from the table's row attributes. */
function renderedRecords(string $html): array
{
    return array_map(fn (array $row): array => [$row['id'], $row['disposition']], renderedRecordFields($html));
}

/**
 * Every rendered row with its cells, associated: each row's id and disposition from the `<tr>` and
 * a field => text map from its own `<td data-field>` children — so a value can be tied to the row
 * it belongs to, and a field rendered outside the table counts for nothing.
 *
 * @return list<array{id: string, disposition: string, fields: array<string, string>}>
 */
function renderedRecordFields(string $html): array
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();
    $xpath = new DOMXPath($document);

    $rows = [];

    foreach ($xpath->query('//table[@data-evidence]//tr[@data-record]') ?: [] as $node) {
        if (! $node instanceof DOMElement) {
            continue;
        }

        $fields = [];

        foreach ($xpath->query('.//td[@data-field]', $node) ?: [] as $cell) {
            if ($cell instanceof DOMElement) {
                $fields[$cell->getAttribute('data-field')] = trim($cell->textContent);
            }
        }

        $rows[] = ['id' => $node->getAttribute('data-record'), 'disposition' => $node->getAttribute('data-disposition'), 'fields' => $fields];
    }

    return $rows;
}

function auditBoundaryRecord(string $id): EvidenceRecord
{
    return new EvidenceRecord(
        id: $id,
        capability: 'orders.cancel',
        stage: 'proposal',
        disposition: 'permit',
        claimType: null,
        recordDigest: null,
        argumentFingerprint: null,
        idempotencyKeyFingerprint: null,
        approvalReceiptFingerprint: null,
        configurationFingerprint: null,
        actorFingerprint: null,
        subjectFingerprint: null,
        proposalTargetIdentityFingerprint: null,
        executionTargetIdentityFingerprint: null,
        rateLimitKeyFingerprint: null,
        executionClaimFingerprint: null,
        executionClaimBindingFingerprint: null,
        invocationId: null,
        rateLimitResetAt: null,
        recordedAt: new DateTimeImmutable('2026-08-30 12:00:00+00:00'),
    );
}

/** A canned read-boundary answer, recording what the component asked for. */
final class RecordingEvidenceQuery implements EvidenceQuery
{
    public ?EvidenceFilter $filter = null;

    public function __construct(private readonly EvidenceQueryResult $result) {}

    public function search(EvidenceFilter $filter): EvidenceQueryResult
    {
        $this->filter = $filter;

        return $this->result;
    }
}

function rootAttribute(string $html, string $attribute): ?string
{
    return preg_match('/<section\b[^>]*\bdata-verdict-console="evidence"[^>]*\b'.preg_quote($attribute, '/').'="([^"]*)"/', $html, $m) === 1 ? $m[1] : null;
}

/**
 * The issue's second acceptance criterion, and the design's hard constraint (§6.6): the default
 * recorder is the null one, so a fresh install's audit page is blank BY CONFIG. It must say that,
 * in the design's own words, and must not draw a table that reads as "nothing happened".
 */
it('says recording is off — blank by config — instead of rendering an empty table', function (): void {
    $html = renderEvidence();

    expect(rootAttribute($html, 'data-recording'))->toBe('off')
        ->and($html)->toContain('recording is off — blank by config.')
        ->and($html)->not->toContain('<table')
        ->and($html)->not->toContain('data-empty')
        ->and($html)->not->toContain('No decisions')
        ->and($html)->not->toContain('<form', 'The audit page is read-only: nothing to submit.');
});

/**
 * The page is a consumer of the VC-13 boundary, not a second implementation of it: what it says
 * about recording is what the boundary answered — even when config says otherwise — and the props
 * are forwarded as the boundary's own filter.
 */
it('renders the read boundarys answer and forwards its props as the filter', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    $boundary = new RecordingEvidenceQuery(new EvidenceQueryResult(EvidenceRecordingState::Off, []));
    app()->instance(EvidenceQuery::class, $boundary);

    $html = renderEvidence('disposition="deny" capability="orders.cancel" conversation="conversation-a"');

    expect(rootAttribute($html, 'data-recording'))->toBe('off', 'Config says on; the boundary said off; the boundary wins.')
        ->and($html)->toContain('recording is off — blank by config.')
        ->and($boundary->filter?->disposition)->toBe('deny')
        ->and($boundary->filter?->capability)->toBe('orders.cancel')
        ->and($boundary->filter?->conversationId)->toBe('conversation-a');
});

/** And the rows are the boundary's rows: what is in the database is not what this page renders. */
it('renders the records the boundary returned, not the tables contents', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertAuditEvidence(['id' => 'in-the-database', 'recorded_at' => '2026-08-30 10:00:00']);
    app()->instance(EvidenceQuery::class, new RecordingEvidenceQuery(new EvidenceQueryResult(EvidenceRecordingState::On, [
        auditBoundaryRecord('from-the-boundary'),
    ])));

    $html = renderEvidence();

    expect(array_column(renderedRecords($html), 0))->toBe(['from-the-boundary'])
        ->and($html)->not->toContain('in-the-database');
});

it('names the writer when evidence is retained somewhere this page cannot read', function (): void {
    config()->set('verdict.evidence.writer', 'App\\Evidence\\ExternalWriter');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $html = renderEvidence();

    expect(rootAttribute($html, 'data-recording'))->toBe('elsewhere')
        ->and($html)->toContain('App\\Evidence\\ExternalWriter')
        ->and($html)->not->toContain('<table')
        ->and($html)->not->toContain('recording is off');
});

/** Recording on and nothing recorded is a different fact from recording off, and reads differently. */
it('says nothing has been recorded when recording is on and the table is empty', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $html = renderEvidence();

    expect(rootAttribute($html, 'data-recording'))->toBe('on')
        ->and($html)->toContain('data-empty')
        ->and($html)->toContain('No decisions have been recorded.')
        ->and($html)->not->toContain('<table')
        ->and($html)->not->toContain('recording is off');
});

/** The row surfaces what the issue names — claimType and recordDigest — beside the decision vocabulary. */
it('renders decision records newest first with claim type and record digest', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertAuditEvidence(['id' => 'older', 'disposition' => 'permit', 'recorded_at' => '2026-08-30 10:00:00', 'record_digest' => 'canonicaljson-sha256:older', 'invocation_id' => 'invocation-1']);
    insertAuditEvidence(['id' => 'newer', 'disposition' => 'deny', 'claim_type' => 'policy.denied', 'record_digest' => 'canonicaljson-sha256:newer', 'recorded_at' => '2026-08-30 10:05:00', 'stage' => 'execution']);
    insertAuditEvidence(['id' => 'provenance-row', 'record_type' => 'provenance', 'stage' => 'input', 'disposition' => 'recorded', 'recorded_at' => '2026-08-30 10:06:00']);

    $html = renderEvidence();
    $rows = renderedRecordFields($html);

    expect($html)->not->toContain('<form', 'The audit page stays read-only when populated too.')
        ->and(renderedRecords($html))->toBe([['newer', 'deny'], ['older', 'permit']])
        ->and($rows[0]['fields'])->toMatchArray([
            'capability' => 'orders.cancel',
            'stage' => 'execution',
            'disposition' => 'deny',
            'claim_type' => 'policy.denied',
            'record_digest' => 'canonicaljson-sha256:newer',
        ])
        ->and(array_keys($rows[0]['fields']))->toContain('recorded_at', 'invocation_id')
        ->and($rows[1]['fields'])->toMatchArray([
            'stage' => 'proposal',
            'disposition' => 'permit',
            'claim_type' => '',
            'record_digest' => 'canonicaljson-sha256:older',
            'invocation_id' => 'invocation-1',
        ])
        ->and($html)->toContain('datetime="2026-08-30T10:05:00+00:00"')
        ->and($html)->not->toContain('provenance-row', 'Only decision records are audit rows; provenance is not.');
});

/** The first acceptance criterion: it paginates, from the query string, newest first, with links. */
it('paginates newest first from the page query parameter', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    seedAuditEvidence(10);

    $first = renderEvidence(':per-page="4"');

    expect(rootAttribute($first, 'data-page'))->toBe('1')
        ->and(rootAttribute($first, 'data-pages'))->toBe('3')
        ->and(array_column(renderedRecords($first), 0))->toBe(['decision-10', 'decision-09', 'decision-08', 'decision-07'])
        ->and($first)->toContain('data-pagination')
        ->and($first)->toContain('data-page-link="2"')
        ->and($first)->toContain('page=2');

    request()->merge(['page' => 2]);

    $second = renderEvidence(':per-page="4"');

    expect(rootAttribute($second, 'data-page'))->toBe('2')
        ->and(array_column(renderedRecords($second), 0))->toBe(['decision-06', 'decision-05', 'decision-04', 'decision-03'], 'Page boundaries hold, not just the first page.');

    request()->merge(['page' => 3]);

    $last = renderEvidence(':per-page="4"');

    expect(rootAttribute($last, 'data-page'))->toBe('3')
        ->and(array_column(renderedRecords($last), 0))->toBe(['decision-02', 'decision-01'])
        ->and($last)->toContain('data-page-link="2"')
        ->and($last)->not->toContain('data-page-link="4"');

    request()->merge(['page' => 99]);

    $beyond = renderEvidence(':per-page="4"');

    expect(renderedRecords($beyond))->toBe([], 'An out-of-range page renders no rows and does not crash.')
        ->and(rootAttribute($beyond, 'data-pages'))->toBe('3')
        ->and($beyond)->toContain('data-page-link="3"');
});

it('defaults to twenty-five records per page', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    seedAuditEvidence(26);

    $html = renderEvidence();

    expect(renderedRecords($html))->toHaveCount(25)
        ->and(rootAttribute($html, 'data-pages'))->toBe('2');
});

it('preserves other query parameters in its page links', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    seedAuditEvidence(6);
    request()->merge(['team' => 'ops']);

    $html = renderEvidence(':per-page="4"');

    preg_match('/<a\b[^>]*data-page-link="2"[^>]*href="([^"]+)"/', $html, $m);

    expect($m[1] ?? null)->not->toBeNull()
        ->and($m[1])->toContain('page=2')
        ->and($m[1])->toContain('team=ops');
});

/** Filters narrow the whole result BEFORE slicing: the page count belongs to the filtered set. */
it('paginates the filtered result, not a filtered page', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    seedAuditEvidence(10);

    $html = renderEvidence('disposition="deny" :per-page="2"');

    expect(array_column(renderedRecords($html), 0))->toBe(['decision-09', 'decision-06'])
        ->and(rootAttribute($html, 'data-pages'))->toBe('2', 'Three denies at two per page is two pages.');
});

it('shows a single page with no pagination controls when everything fits', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    seedAuditEvidence(3);

    $html = renderEvidence(':per-page="25"');

    expect(rootAttribute($html, 'data-pages'))->toBe('1')
        ->and(renderedRecords($html))->toHaveCount(3)
        ->and($html)->not->toContain('data-page-link');
});

it('passes disposition and capability filters through to the read boundary', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    seedAuditEvidence(6);
    insertAuditEvidence(['id' => 'other-capability', 'capability' => 'billing.refund', 'disposition' => 'deny', 'recorded_at' => '2026-08-30 11:00:00']);

    $denies = renderEvidence('disposition="deny"');
    $billing = renderEvidence('capability="billing.refund"');

    expect(array_column(renderedRecords($denies), 0))->toBe(['other-capability', 'decision-06', 'decision-03'])
        ->and(array_column(renderedRecords($billing), 0))->toBe(['other-capability']);
});

/**
 * Conversation scope rides on VC-14: a conversation the console never observed is reported as
 * unknown — a statement about the console's projection, not about the evidence — never as empty.
 */
it('scopes to a conversation and states when the console never observed it', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    seedAuditEvidence(10);
    (new ConversationInvocationStore)->record('invocation-1', 'conversation-a');

    $known = renderEvidence('conversation="conversation-a"');
    $unknown = renderEvidence('conversation="conversation-never-seen"');

    expect(rootAttribute($known, 'data-conversation'))->toBe('known')
        ->and(array_column(renderedRecords($known), 0))->toBe(['decision-05', 'decision-04', 'decision-03', 'decision-02', 'decision-01'])
        ->and(rootAttribute($unknown, 'data-conversation'))->toBe('unknown')
        ->and($unknown)->toContain('never observed')
        ->and($unknown)->not->toContain('<table')
        ->and($unknown)->not->toContain('No decisions have been recorded.');
});

/** Fingerprints and digests are strings from a table a host controls; every one is drawn as text. */
it('escapes every value it renders', function (): void {
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);
    insertAuditEvidence([
        'id' => 'escaped<u>id</u>',
        'capability' => 'orders.<b>cancel</b>',
        'stage' => 'pro<script>posal',
        'disposition' => 'per"mit',
        'claim_type' => 'policy.<i>denied</i> & "x"',
        'record_digest' => 'digest<hr>value',
        'invocation_id' => 'inv<img>ocation',
        'recorded_at' => '2026-08-30 10:00:00',
    ]);

    $html = renderEvidence();

    expect($html)->toContain('orders.&lt;b&gt;cancel&lt;/b&gt;')
        ->and($html)->toContain('policy.&lt;i&gt;denied&lt;/i&gt; &amp; &quot;x&quot;')
        ->and($html)->toContain('escaped&lt;u&gt;id&lt;/u&gt;')
        ->and($html)->toContain('pro&lt;script&gt;posal')
        ->and($html)->toContain('digest&lt;hr&gt;value')
        ->and($html)->toContain('inv&lt;img&gt;ocation')
        ->and($html)->not->toContain('<b>cancel</b>')
        ->and($html)->not->toContain('<script>')
        ->and($html)->not->toContain('<hr>')
        ->and($html)->not->toContain('<img>')
        ->and($html)->not->toContain('<u>id</u>')
        ->and($html)->toContain('per&quot;mit')
        ->and($html)->not->toContain('per"mit');
});

/**
 * The recording-state statements outrank the correlation statement: a conversation filter under
 * recording off or elsewhere still reports the recording fact — that is why the page is blank —
 * while the correlation attribute stays visible for the UI that asked.
 */
it('reports the recording state first when a conversation filter meets recording off or elsewhere', function (): void {
    (new ConversationInvocationStore)->record('invocation-1', 'conversation-a');

    $off = renderEvidence('conversation="conversation-never-seen"');

    expect(rootAttribute($off, 'data-recording'))->toBe('off')
        ->and(rootAttribute($off, 'data-conversation'))->toBe('unknown')
        ->and($off)->toContain('recording is off — blank by config.')
        ->and($off)->not->toContain('never observed');

    config()->set('verdict.evidence.writer', 'App\Evidence\ExternalWriter');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $elsewhere = renderEvidence('conversation="conversation-a"');

    expect(rootAttribute($elsewhere, 'data-recording'))->toBe('elsewhere')
        ->and(rootAttribute($elsewhere, 'data-conversation'))->toBe('known')
        ->and($elsewhere)->toContain('App\Evidence\ExternalWriter')
        ->and($elsewhere)->not->toContain('<table');
});

it('publishes with the other views', function (): void {
    $target = resource_path('views/vendor/verdict-console');

    File::exists($target) && File::deleteDirectory($target);

    $this->artisan('vendor:publish', ['--tag' => 'verdict-console-views', '--force' => true])->assertSuccessful();

    expect(File::exists($target.'/components/evidence.blade.php'))->toBeTrue();

    File::deleteDirectory($target);
});
