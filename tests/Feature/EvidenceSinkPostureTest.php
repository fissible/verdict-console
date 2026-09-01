<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Fissible\VerdictConsole\Evidence\ConfigurationSinkPosture;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsole\Evidence\SinkPosture;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #105: one console-owned, host-replaceable answer to "what is the evidence sink and can this
 * console read it" — the posture every sink-answering surface consumes instead of re-deriving
 * config on its own. Configuration proves selection only: nothing here claims a write succeeded,
 * a chain verifies, or a table is complete.
 *
 * The shipped reader works by configuration inspection alone. These class names are deliberately
 * strings: the reader must never import or resolve Verdict recorder types.
 */
const POSTURE_NULL_RECORDER = 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder';
const POSTURE_DATABASE_RECORDER = 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder';
const POSTURE_ATTEST_RECORDER = 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder';

function posture(): SinkPosture
{
    return app(EvidenceSinkPosture::class)->read();
}

/** An instantiable host writer for the parity check: narrow contract, records nothing. */
final class PostureParityWriter implements EvidenceWriter
{
    public function record(DecisionEvidence $evidence): void {}

    public function recordRelease(ContextReleaseEvidence $evidence): void {}

    public function recordProvenance(ProvenanceEntry $entry): void {}

    public function recordDerivation(ProvenanceDerivation $derivation): void {}
}

it('binds the shipped configuration reader to a contract a host may replace', function (): void {
    expect(app(EvidenceSinkPosture::class))->toBeInstanceOf(ConfigurationSinkPosture::class);

    $replacement = new class implements EvidenceSinkPosture
    {
        public function read(): SinkPosture
        {
            return new SinkPosture(
                state: EvidenceRecordingState::Elsewhere,
                effectiveWriter: null,
                recordedBy: 'Host\\Replacement\\Answered',
                table: null,
                connection: null,
                chainConfigured: false,
            );
        }
    };

    app()->instance(EvidenceSinkPosture::class, $replacement);

    expect(posture()->recordedBy)->toBe('Host\\Replacement\\Answered');
});

/** The honesty states through the one boundary, matching the answers the surfaces already give. */
it('reports off, on, elsewhere, and chained from configuration alone', function (): void {
    config()->set('verdict.evidence.recorder', POSTURE_NULL_RECORDER);

    $off = posture();

    config()->set('verdict.evidence.recorder', POSTURE_DATABASE_RECORDER);

    $on = posture();

    config()->set('verdict.evidence.writer', 'App\\Evidence\\ExternalWriter');

    $elsewhere = posture();

    config()->set('verdict.evidence.writer', null);
    config()->set('verdict.evidence.recorder', POSTURE_ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'main-ledger');

    $chained = posture();

    expect($off->state)->toBe(EvidenceRecordingState::Off)
        ->and($off->effectiveWriter)->toBe(POSTURE_NULL_RECORDER)
        ->and($off->recordedBy)->toBeNull()
        ->and($on->state)->toBe(EvidenceRecordingState::On)
        ->and($on->effectiveWriter)->toBe(POSTURE_DATABASE_RECORDER)
        ->and($on->recordedBy)->toBeNull()
        ->and($elsewhere->state)->toBe(EvidenceRecordingState::Elsewhere)
        ->and($elsewhere->effectiveWriter)->toBe('App\\Evidence\\ExternalWriter', 'The narrow writer key takes precedence over the legacy recorder — Verdict resolves EvidenceWriter from it first.')
        ->and($elsewhere->recordedBy)->toBe('App\\Evidence\\ExternalWriter')
        ->and($chained->state)->toBe(EvidenceRecordingState::Chained)
        ->and($chained->effectiveWriter)->toBe(POSTURE_ATTEST_RECORDER)
        ->and($chained->recordedBy)->toBe('main-ledger');
});

it('treats an absent recorder key as recording off, exactly as the read surfaces do', function (): void {
    $evidence = config('verdict.evidence');
    unset($evidence['recorder']);
    config()->set('verdict.evidence', $evidence);

    expect(posture()->state)->toBe(EvidenceRecordingState::Off)
        ->and(posture()->effectiveWriter)->toBe(POSTURE_NULL_RECORDER);
});

/** The table facts belong to the posture only while the sink IS a readable table. */
it('names the table and connection only for a tabular sink', function (): void {
    config()->set('verdict.evidence.recorder', POSTURE_DATABASE_RECORDER);
    config()->set('verdict.evidence.table', 'host_evidence');
    config()->set('verdict.evidence.connection', 'audit');

    $tabular = posture();

    config()->set('verdict.evidence.table', null);
    config()->set('verdict.evidence.connection', null);

    $defaults = posture();

    config()->set('verdict.evidence.recorder', POSTURE_ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'main-ledger');

    $chained = posture();

    config()->set('verdict.evidence.recorder', POSTURE_NULL_RECORDER);

    $off = posture();

    // An external writer's posture must not leak the tabular config beside it: the table facts
    // describe the sink selected, not whatever keys happen to be set.
    config()->set('verdict.evidence.writer', 'App\\Evidence\\ExternalWriter');
    config()->set('verdict.evidence.table', 'host_evidence');
    config()->set('verdict.evidence.connection', 'audit');

    $elsewhere = posture();

    expect($tabular->table)->toBe('host_evidence')
        ->and($tabular->connection)->toBe('audit')
        ->and($defaults->table)->toBe('verdict_evidence', "Verdict's published default table name.")
        ->and($defaults->connection)->toBeNull()
        ->and($chained->table)->toBeNull('A chained sink is not a readable table; naming one would invite reading it.')
        ->and($chained->connection)->toBeNull()
        ->and($off->table)->toBeNull()
        ->and($off->connection)->toBeNull()
        ->and($elsewhere->state)->toBe(EvidenceRecordingState::Elsewhere)
        ->and($elsewhere->table)->toBeNull('An unreadable sink has no table to name, whatever config sets.')
        ->and($elsewhere->connection)->toBeNull();
});

/**
 * The chain identity follows #104's rules exactly: the fixed chain id, else the resolver class,
 * and no identity at all for a topology Verdict itself rejects — while chainConfigured answers the
 * raw configuration fact #108's ADR needs, independent of which sink is selected.
 */
it('reports the chain identity by #104s rules and the chain-configured fact independently', function (): void {
    config()->set('verdict.evidence.recorder', POSTURE_ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', null);
    config()->set('verdict.evidence.attest.chain_resolver', 'App\\Support\\UnresolvableTenantChainResolver');

    $resolver = posture();

    config()->set('verdict.evidence.attest.chain', 'main-ledger');

    $both = posture();

    config()->set('verdict.evidence.attest.chain_resolver', null);
    config()->set('verdict.evidence.attest.chain', null);

    $neither = posture();

    // A database recorder beside leftover attest config: the selected sink is the table, but the
    // chain-configured fact still answers truthfully.
    config()->set('verdict.evidence.recorder', POSTURE_DATABASE_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'main-ledger');

    $tabularWithChain = posture();

    expect($resolver->state)->toBe(EvidenceRecordingState::Chained)
        ->and($resolver->recordedBy)->toBe('App\\Support\\UnresolvableTenantChainResolver')
        ->and($resolver->chainConfigured)->toBeTrue()
        ->and($both->recordedBy)->toBeNull('Both keys set is a topology Verdict rejects; the posture names no identity.')
        ->and($both->chainConfigured)->toBeTrue()
        ->and($neither->state)->toBe(EvidenceRecordingState::Chained)
        ->and($neither->recordedBy)->toBeNull()
        ->and($neither->chainConfigured)->toBeFalse()
        ->and($tabularWithChain->state)->toBe(EvidenceRecordingState::On)
        ->and($tabularWithChain->chainConfigured)->toBeTrue();

    // Configured means a non-empty value, exactly as the identity rules read them: empty strings
    // are unset, and an empty fixed id beside a valid resolver leaves the resolver in charge.
    config()->set('verdict.evidence.recorder', POSTURE_ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', '');
    config()->set('verdict.evidence.attest.chain_resolver', null);

    $emptyChain = posture();

    config()->set('verdict.evidence.attest.chain_resolver', '');

    $bothEmpty = posture();

    config()->set('verdict.evidence.attest.chain_resolver', 'App\\Support\\UnresolvableTenantChainResolver');

    $emptyChainRealResolver = posture();

    expect($emptyChain->chainConfigured)->toBeFalse()
        ->and($emptyChain->recordedBy)->toBeNull()
        ->and($bothEmpty->chainConfigured)->toBeFalse()
        ->and($emptyChainRealResolver->chainConfigured)->toBeTrue()
        ->and($emptyChainRealResolver->recordedBy)->toBe('App\\Support\\UnresolvableTenantChainResolver');
});

/**
 * The named divergence this issue decides. Measured against Verdict v0.14 (VerdictServiceProvider,
 * EvidenceWriter binding): only `null` falls back to the legacy recorder; an empty string passes
 * the null check and dies inside `$app->make('')` the first time the writer resolves. No honesty
 * state can say "resolution will throw", and the console's old reading — "recorded elsewhere by
 * ''" — named a writer that does not exist. Decision: for the posture read, empty means unset
 * (the recorder key answers), because an empty env var is the dominant real cause and every other
 * mapping states a falsehood. The raw divergence from Verdict's throw is a doctor finding to file
 * with the sink-review work, not a state.
 */
it('reads an empty writer as unset rather than an unnameable elsewhere', function (): void {
    config()->set('verdict.evidence.writer', '');
    config()->set('verdict.evidence.recorder', POSTURE_DATABASE_RECORDER);

    $emptyOverDatabase = posture();

    config()->set('verdict.evidence.recorder', POSTURE_NULL_RECORDER);

    $emptyOverNull = posture();

    config()->set('verdict.evidence.recorder', POSTURE_ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'main-ledger');

    $emptyOverChained = posture();

    // And the same hygiene for the recorder key itself: '' is unset, so the shipped default — the
    // null recorder — answers.
    config()->set('verdict.evidence.writer', null);
    config()->set('verdict.evidence.recorder', '');

    $emptyRecorder = posture();

    expect($emptyOverDatabase->state)->toBe(EvidenceRecordingState::On)
        ->and($emptyOverDatabase->effectiveWriter)->toBe(POSTURE_DATABASE_RECORDER)
        ->and($emptyOverDatabase->recordedBy)->toBeNull()
        ->and($emptyOverNull->state)->toBe(EvidenceRecordingState::Off)
        ->and($emptyOverChained->state)->toBe(EvidenceRecordingState::Chained)
        ->and($emptyOverChained->recordedBy)->toBe('main-ledger')
        ->and($emptyRecorder->state)->toBe(EvidenceRecordingState::Off)
        ->and($emptyRecorder->effectiveWriter)->toBe(POSTURE_NULL_RECORDER);
});

/** A non-string writer or recorder value can select nothing nameable: elsewhere, unnamed. */
it('reports a non-string sink selection as an unnameable elsewhere', function (): void {
    config()->set('verdict.evidence.recorder', ['not', 'a', 'class']);

    expect(posture()->state)->toBe(EvidenceRecordingState::Elsewhere)
        ->and(posture()->effectiveWriter)->toBeNull()
        ->and(posture()->recordedBy)->toBeNull();
});

/**
 * The evidence read surface consumes THIS boundary rather than keeping a second derivation: bound
 * to an answer configuration cannot produce, the query must repeat the contract's answer. No
 * evidence table exists in this test — a query that still read one would fail loudly here.
 */
it('drives the evidence read surfaces recording answer through the posture boundary', function (): void {
    config()->set('verdict.evidence.recorder', POSTURE_DATABASE_RECORDER);

    app()->instance(EvidenceSinkPosture::class, new class implements EvidenceSinkPosture
    {
        public function read(): SinkPosture
        {
            return new SinkPosture(
                state: EvidenceRecordingState::Chained,
                effectiveWriter: null,
                recordedBy: 'fake-chain-not-derivable-from-config',
                table: null,
                connection: null,
                chainConfigured: true,
            );
        }
    });

    $complete = app(EvidenceQuery::class)->search(new EvidenceFilter);
    $page = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 1, perPage: 10);

    expect($complete->recording)->toBe(EvidenceRecordingState::Chained)
        ->and($complete->recordedBy)->toBe('fake-chain-not-derivable-from-config')
        ->and($complete->records)->toBe([])
        ->and($page->recording)->toBe(EvidenceRecordingState::Chained)
        ->and($page->recordedBy)->toBe('fake-chain-not-derivable-from-config')
        ->and($page->total)->toBe(0);

    // And the same for a replacement declaring the sink unreadable outright: an implementation
    // that special-cased chained but re-derived elsewhere from config would read the
    // config-derived table — which does not exist — and die here.
    app()->instance(EvidenceSinkPosture::class, new class implements EvidenceSinkPosture
    {
        public function read(): SinkPosture
        {
            return new SinkPosture(
                state: EvidenceRecordingState::Elsewhere,
                effectiveWriter: 'Host\\Fake\\Writer',
                recordedBy: 'Host\\Fake\\Writer',
                table: null,
                connection: null,
                chainConfigured: false,
            );
        }
    });

    $elsewhereComplete = app(EvidenceQuery::class)->search(new EvidenceFilter);
    $elsewherePage = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 1, perPage: 10);

    expect($elsewhereComplete->recording)->toBe(EvidenceRecordingState::Elsewhere)
        ->and($elsewhereComplete->recordedBy)->toBe('Host\\Fake\\Writer')
        ->and($elsewhereComplete->records)->toBe([])
        ->and($elsewherePage->recording)->toBe(EvidenceRecordingState::Elsewhere)
        ->and($elsewherePage->recordedBy)->toBe('Host\\Fake\\Writer')
        ->and($elsewherePage->total)->toBe(0);
});

/**
 * And the tabular half of the same delegation: the table AND connection the query reads are the
 * ones the POSTURE names. The real table lives only on a second connection; config names a
 * missing table on the default connection — an implementation keeping its own table or connection
 * derivation dies loudly on either.
 */
it('reads the table and connection the posture boundary names, never the ones config names', function (): void {
    config()->set('database.connections.posture_audit', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('verdict.evidence.recorder', POSTURE_DATABASE_RECORDER);
    config()->set('verdict.evidence.table', 'config_names_a_missing_table');
    config()->set('verdict.evidence.connection', null);

    Schema::connection('posture_audit')->create('posture_named_table', function ($table): void {
        $table->string('id')->primary();
        $table->string('record_type');
        $table->string('capability')->nullable();
        $table->string('stage');
        $table->string('disposition');
        $table->text('reason')->nullable();
        $table->string('claim_type')->nullable();
        $table->string('record_digest')->nullable();
        $table->string('argument_fingerprint')->nullable();
        $table->string('idempotency_key_fingerprint')->nullable();
        $table->string('approval_receipt_fingerprint')->nullable();
        $table->string('configuration_fingerprint')->nullable();
        $table->string('actor_fingerprint')->nullable();
        $table->string('subject_fingerprint')->nullable();
        $table->string('proposal_target_identity_fingerprint')->nullable();
        $table->string('execution_target_identity_fingerprint')->nullable();
        $table->string('rate_limit_key_fingerprint')->nullable();
        $table->string('execution_claim_fingerprint')->nullable();
        $table->string('execution_claim_binding_fingerprint')->nullable();
        $table->string('invocation_id')->nullable();
        $table->timestamp('rate_limit_reset_at')->nullable();
        $table->timestamp('recorded_at');
    });

    DB::connection('posture_audit')->table('posture_named_table')->insert([
        'id' => 'in-the-postures-table',
        'record_type' => 'decision',
        'stage' => 'proposal',
        'disposition' => 'permit',
        'recorded_at' => '2026-09-01 10:00:00',
    ]);

    app()->instance(EvidenceSinkPosture::class, new class implements EvidenceSinkPosture
    {
        public function read(): SinkPosture
        {
            return new SinkPosture(
                state: EvidenceRecordingState::On,
                effectiveWriter: POSTURE_DATABASE_RECORDER,
                recordedBy: null,
                table: 'posture_named_table',
                connection: 'posture_audit',
                chainConfigured: false,
            );
        }
    });

    $complete = app(EvidenceQuery::class)->search(new EvidenceFilter);
    $page = app(EvidenceQuery::class)->searchPage(new EvidenceFilter, page: 1, perPage: 10);

    expect(array_map(fn ($record) => $record->id, $complete->records))->toBe(['in-the-postures-table'])
        ->and(array_map(fn ($record) => $record->id, $page->records))->toBe(['in-the-postures-table'])
        ->and($page->total)->toBe(1);

    Schema::connection('posture_audit')->dropIfExists('posture_named_table');
});

/**
 * The reader answers by configuration inspection only: no Verdict recorder type is imported by the
 * posture files, and the contract states the ceiling — configuration proves selection, never that
 * recording is verified or complete.
 */
it('imports no Verdict types and states the selection-only ceiling', function (): void {
    $sources = [
        'src/Contracts/EvidenceSinkPosture.php',
        'src/Evidence/SinkPosture.php',
        'src/Evidence/ConfigurationSinkPosture.php',
    ];

    foreach ($sources as $source) {
        $code = (string) file_get_contents(dirname(__DIR__, 2).'/'.$source);

        // The only permitted spelling of a Verdict name is an escaped string constant
        // ('Fissible\\Verdict\\...'): strip that form, and any remaining single-backslash
        // occurrence — a use statement, an FQCN reference, ::class, an unescaped string — fails.
        // (toContain takes no message parameter; the path in the failure comes from per-file
        // assertion.)
        $withoutEscapedStrings = str_replace('Fissible\\\\Verdict\\\\', '', $code);

        expect($withoutEscapedStrings)->not->toContain('Fissible\\Verdict\\');
    }

    expect((string) file_get_contents(dirname(__DIR__, 2).'/src/Contracts/EvidenceSinkPosture.php'))
        ->toContain('never implies verified or complete');
});

/**
 * The precedence is measured, not hand-written lore: for every supported non-empty selection the
 * posture's effectiveWriter must equal the class Verdict's own EvidenceWriter binding resolves.
 * (Tests may import Verdict types; only the shipped reader may not.) The empty-writer divergence
 * stays deliberately outside this parity — Verdict throws there, and its alignment is decided in
 * the empty-writer test above.
 */
it('matches Verdicts own writer resolution for every supported non-empty selection', function (): void {
    $verdictResolves = function (): string {
        // The EvidenceWriter binding is scoped and holds what it resolved; re-read config fresh.
        app()->forgetScopedInstances();

        return app(EvidenceWriter::class)::class;
    };

    config()->set('verdict.evidence.recorder', POSTURE_NULL_RECORDER);

    expect(posture()->effectiveWriter)->toBe($verdictResolves())->toBe(POSTURE_NULL_RECORDER);

    config()->set('verdict.evidence.recorder', POSTURE_DATABASE_RECORDER);

    expect(posture()->effectiveWriter)->toBe($verdictResolves())->toBe(POSTURE_DATABASE_RECORDER);

    config()->set('verdict.evidence.writer', PostureParityWriter::class);

    expect(posture()->effectiveWriter)->toBe($verdictResolves())->toBe(PostureParityWriter::class, 'The narrow writer key must win over the legacy recorder, exactly as Verdict resolves it.');
});

/** The contract is one read; the value is immutable. A wider API or a mutable answer fails here. */
it('keeps the contract to a single read of an immutable value', function (): void {
    $contract = new ReflectionClass(EvidenceSinkPosture::class);
    $methods = array_map(fn (ReflectionMethod $method): string => $method->getName(), $contract->getMethods());
    sort($methods);

    expect($contract->isInterface())->toBeTrue()
        ->and($methods)->toBe(['read'])
        ->and((new ReflectionClass(SinkPosture::class))->isReadOnly())->toBeTrue();
});
