<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\VerdictConsole\Contracts\ConfigurationDriftQuery;
use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Fissible\VerdictConsole\Evidence\ConfigurationDriftResult;
use Fissible\VerdictConsole\Evidence\DatabaseConfigurationDriftQuery;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsole\Evidence\ObservedConfiguration;
use Fissible\VerdictConsole\Evidence\SinkPosture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #106: the observed-configuration aggregate — per (capability, configuration_fingerprint): first
 * and last observation and the decision count, from the decision trail alone. This is an
 * observation of the trail, never a write log: an unexercised configuration change leaves no row.
 * Fixtures are this file's own.
 */
const DRIFT_EVIDENCE_STUBS = [
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

/** @param array<string, mixed> $attributes */
function insertDriftRow(array $attributes): void
{
    DB::table('console_drift_evidence')->insert([
        'id' => $attributes['id'],
        'record_type' => $attributes['record_type'] ?? 'decision',
        'capability' => array_key_exists('capability', $attributes) ? $attributes['capability'] : 'orders.cancel',
        'stage' => $attributes['stage'] ?? 'proposal',
        'disposition' => $attributes['disposition'] ?? 'permit',
        'configuration_fingerprint' => $attributes['configuration_fingerprint'] ?? null,
        'recorded_at' => $attributes['recorded_at'],
    ]);
}

/** @return list<array{0: string, 1: string, 2: string, 3: string, 4: int}> capability, fingerprint, first, last, count */
function driftTuples(ConfigurationDriftResult $result): array
{
    return array_map(fn (ObservedConfiguration $observed): array => [
        $observed->capability,
        $observed->configurationFingerprint,
        $observed->firstObservedAt->format(DATE_ATOM),
        $observed->lastObservedAt->format(DATE_ATOM),
        $observed->decisionCount,
    ], $result->observations);
}

beforeEach(function (): void {
    config()->set('verdict.evidence.table', 'console_drift_evidence');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $migrations = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';

    foreach (DRIFT_EVIDENCE_STUBS as $stub) {
        (require $migrations.'/'.$stub)->up();
    }
});

afterEach(function (): void {
    Schema::dropIfExists('console_drift_evidence');
});

/** Same guard every evidence fixture carries: a new Verdict stub must not leave this file behind. */
it('builds its fixture from every evidence-table stub the installed Verdict publishes', function (): void {
    $published = array_map(basename(...), glob(dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/*verdict_evidence_table.php.stub') ?: []);

    expect(DRIFT_EVIDENCE_STUBS)->toEqualCanonicalizing($published);
});

/**
 * The aggregate is exact: counts and both timestamp bounds per (capability, fingerprint), ordered
 * capability-ascending then newest-last-observation first — and two capabilities sharing a
 * fingerprint NEVER merge.
 */
it('aggregates observed configurations per capability with exact counts and bounds', function (): void {
    // A non-UTC host timezone must not shift the bounds: the schema is timezone-naive and the
    // write side mints UTC, so hydration reads UTC whatever the application is set to.
    config()->set('app.timezone', 'America/Chicago');
    date_default_timezone_set('America/Chicago');

    insertDriftRow(['id' => 'a-old-1', 'capability' => 'billing.refund', 'configuration_fingerprint' => 'sha256:fp-one', 'recorded_at' => '2026-09-01 10:00:00']);
    insertDriftRow(['id' => 'a-old-2', 'capability' => 'billing.refund', 'configuration_fingerprint' => 'sha256:fp-one', 'recorded_at' => '2026-09-01 10:05:00']);
    insertDriftRow(['id' => 'a-old-3', 'capability' => 'billing.refund', 'configuration_fingerprint' => 'sha256:fp-one', 'recorded_at' => '2026-09-01 10:10:00']);
    insertDriftRow(['id' => 'a-new-1', 'capability' => 'billing.refund', 'configuration_fingerprint' => 'sha256:fp-two', 'recorded_at' => '2026-09-01 10:20:00']);
    // The same fingerprint under another capability: a distinct observation with its own bounds.
    insertDriftRow(['id' => 'b-1', 'capability' => 'orders.cancel', 'configuration_fingerprint' => 'sha256:fp-one', 'recorded_at' => '2026-09-01 10:01:00']);
    insertDriftRow(['id' => 'b-2', 'capability' => 'orders.cancel', 'configuration_fingerprint' => 'sha256:fp-one', 'recorded_at' => '2026-09-01 10:02:00']);
    // Two fingerprints last observed at the same instant: the order is still deterministic —
    // fingerprint ascending breaks the tie, on every engine.
    insertDriftRow(['id' => 'z-b', 'capability' => 'zulu.charge', 'configuration_fingerprint' => 'sha256:fp-b', 'recorded_at' => '2026-09-01 10:30:00']);
    insertDriftRow(['id' => 'z-a', 'capability' => 'zulu.charge', 'configuration_fingerprint' => 'sha256:fp-a', 'recorded_at' => '2026-09-01 10:30:00']);

    $result = app(ConfigurationDriftQuery::class)->observed();

    expect($result->recording)->toBe(EvidenceRecordingState::On)
        ->and(driftTuples($result))->toBe([
            ['billing.refund', 'sha256:fp-two', '2026-09-01T10:20:00+00:00', '2026-09-01T10:20:00+00:00', 1],
            ['billing.refund', 'sha256:fp-one', '2026-09-01T10:00:00+00:00', '2026-09-01T10:10:00+00:00', 3],
            ['orders.cancel', 'sha256:fp-one', '2026-09-01T10:01:00+00:00', '2026-09-01T10:02:00+00:00', 2],
            ['zulu.charge', 'sha256:fp-a', '2026-09-01T10:30:00+00:00', '2026-09-01T10:30:00+00:00', 1],
            ['zulu.charge', 'sha256:fp-b', '2026-09-01T10:30:00+00:00', '2026-09-01T10:30:00+00:00', 1],
        ]);

    date_default_timezone_set('UTC');
});

/** The provider ships the database aggregate on the contract, not merely something bindable. */
it('binds the shipped database drift query to the contract', function (): void {
    expect(app(ConfigurationDriftQuery::class))->toBeInstanceOf(DatabaseConfigurationDriftQuery::class);
});

/**
 * Only capability-resolved decisions observe a configuration: rows with a null fingerprint, rows
 * with no capability, and non-decision record types are excluded explicitly — never counted, never
 * bound-shifting.
 */
it('excludes null fingerprints, null capabilities, and non-decision rows explicitly', function (): void {
    insertDriftRow(['id' => 'counted', 'capability' => 'orders.cancel', 'configuration_fingerprint' => 'sha256:fp-one', 'recorded_at' => '2026-09-01 10:05:00']);
    insertDriftRow(['id' => 'null-fingerprint', 'capability' => 'orders.cancel', 'recorded_at' => '2026-09-01 09:00:00']);
    insertDriftRow(['id' => 'null-capability', 'capability' => null, 'configuration_fingerprint' => 'sha256:fp-one', 'recorded_at' => '2026-09-01 09:01:00']);
    insertDriftRow(['id' => 'provenance-row', 'record_type' => 'provenance', 'capability' => 'orders.cancel', 'stage' => 'input', 'disposition' => 'recorded', 'configuration_fingerprint' => 'sha256:fp-one', 'recorded_at' => '2026-09-01 09:02:00']);
    insertDriftRow(['id' => 'gap-row', 'record_type' => 'chain_gap', 'capability' => 'orders.cancel', 'stage' => 'decision', 'disposition' => 'gap', 'configuration_fingerprint' => 'sha256:fp-one', 'recorded_at' => '2026-09-01 09:03:00']);

    $result = app(ConfigurationDriftQuery::class)->observed();

    // One observation, and its bounds prove the excluded rows shifted nothing: first equals last
    // equals the single counted decision.
    expect(driftTuples($result))->toBe([
        ['orders.cancel', 'sha256:fp-one', '2026-09-01T10:05:00+00:00', '2026-09-01T10:05:00+00:00', 1],
    ]);
});

/** An empty trail is an answer, not an error. */
it('answers an empty trail with recording on and no observations', function (): void {
    $result = app(ConfigurationDriftQuery::class)->observed();

    expect($result->recording)->toBe(EvidenceRecordingState::On)
        ->and($result->observations)->toBe([])
        ->and($result->recordedBy)->toBeNull();
});

/**
 * The drift read consumes the #105 posture boundary: a non-readable posture is repeated verbatim
 * with no observations — and nothing is queried at all, whatever tables exist.
 */
it('repeats a non-readable posture verbatim without touching any table', function (): void {
    insertDriftRow(['id' => 'unreadable', 'capability' => 'orders.cancel', 'configuration_fingerprint' => 'sha256:fp-one', 'recorded_at' => '2026-09-01 10:00:00']);

    foreach ([
        [EvidenceRecordingState::Off, null],
        [EvidenceRecordingState::Elsewhere, 'App\\Evidence\\ExternalWriter'],
        [EvidenceRecordingState::Chained, 'drift-fake-chain'],
    ] as [$state, $recordedBy]) {
        app()->instance(EvidenceSinkPosture::class, new class($state, $recordedBy) implements EvidenceSinkPosture
        {
            public function __construct(private readonly EvidenceRecordingState $state, private readonly ?string $recordedBy) {}

            public function read(): SinkPosture
            {
                return new SinkPosture(
                    state: $this->state,
                    effectiveWriter: null,
                    recordedBy: $this->recordedBy,
                    table: null,
                    connection: null,
                    chainConfigured: false,
                );
            }
        });

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $result = app(ConfigurationDriftQuery::class)->observed();

        expect($result->recording)->toBe($state)
            ->and($result->recordedBy)->toBe($recordedBy)
            ->and($result->observations)->toBe([])
            ->and($statements)->toBe([]);
    }
});

/** And the readable path reads what the POSTURE names — the table config names does not exist. */
it('reads the table and connection the posture names, never the ones config names', function (): void {
    config()->set('database.connections.drift_audit', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('verdict.evidence.table', 'config_names_a_missing_table');

    Schema::connection('drift_audit')->create('drift_posture_table', function ($table): void {
        $table->string('id')->primary();
        $table->string('record_type');
        $table->string('capability')->nullable();
        $table->string('stage');
        $table->string('disposition');
        $table->string('configuration_fingerprint')->nullable();
        $table->timestamp('recorded_at');
    });

    DB::connection('drift_audit')->table('drift_posture_table')->insert([
        'id' => 'observed-there',
        'record_type' => 'decision',
        'capability' => 'orders.cancel',
        'stage' => 'proposal',
        'disposition' => 'permit',
        'configuration_fingerprint' => 'sha256:fp-remote',
        'recorded_at' => '2026-09-01 10:00:00',
    ]);

    app()->instance(EvidenceSinkPosture::class, new class implements EvidenceSinkPosture
    {
        public function read(): SinkPosture
        {
            return new SinkPosture(
                state: EvidenceRecordingState::On,
                effectiveWriter: 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder',
                recordedBy: null,
                table: 'drift_posture_table',
                connection: 'drift_audit',
                chainConfigured: false,
            );
        }
    });

    $result = app(ConfigurationDriftQuery::class)->observed();

    expect(driftTuples($result))->toBe([
        ['orders.cancel', 'sha256:fp-remote', '2026-09-01T10:00:00+00:00', '2026-09-01T10:00:00+00:00', 1],
    ]);

    Schema::connection('drift_audit')->dropIfExists('drift_posture_table');
});

/** The contract is host-replaceable: a bound replacement answers, whatever the table holds. */
it('binds the shipped query to a contract a host may replace', function (): void {
    insertDriftRow(['id' => 'in-the-table', 'capability' => 'orders.cancel', 'configuration_fingerprint' => 'sha256:fp-table', 'recorded_at' => '2026-09-01 10:00:00']);

    app()->instance(ConfigurationDriftQuery::class, new class implements ConfigurationDriftQuery
    {
        public function observed(): ConfigurationDriftResult
        {
            return new ConfigurationDriftResult(
                recording: EvidenceRecordingState::On,
                observations: [new ObservedConfiguration(
                    capability: 'host.replaced',
                    configurationFingerprint: 'sha256:from-the-replacement',
                    firstObservedAt: new DateTimeImmutable('2026-09-01 09:00:00+00:00'),
                    lastObservedAt: new DateTimeImmutable('2026-09-01 09:30:00+00:00'),
                    decisionCount: 7,
                )],
                recordedBy: null,
            );
        }
    });

    $result = app(ConfigurationDriftQuery::class)->observed();

    expect(driftTuples($result))->toBe([
        ['host.replaced', 'sha256:from-the-replacement', '2026-09-01T09:00:00+00:00', '2026-09-01T09:30:00+00:00', 7],
    ]);
});
