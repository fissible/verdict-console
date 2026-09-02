<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Contracts\EvidenceIntegrity;
use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsole\Evidence\SinkPosture;
use Fissible\VerdictConsole\Integrity\ChainIntegrityState;
use Fissible\VerdictConsole\Integrity\ChainVerificationStore;
use Fissible\VerdictConsole\Integrity\NullEvidenceIntegrity;
use Fissible\VerdictConsole\Integrity\RecordedVerification;
use Fissible\VerdictConsole\Integrity\UnnameableReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR 0002 §§1–4: the integrity boundary beside the evidence state boundary. States derive from
 * the effective sink through the posture contract and from the console's own two-group
 * verification record; nothing here reads an evidence table, and an errored attempt never
 * disturbs a standing claim.
 */
const ATTEST_RECORDER = 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder';

function recordedRun(
    string $outcome = 'verified',
    string $source = 'automated',
    ?int $verifiedThrough = 120,
    ?int $brokenAt = null,
): RecordedVerification {
    return new RecordedVerification(
        outcome: $outcome,
        ranAt: new DateTimeImmutable('2026-09-01T12:00:00+00:00'),
        ranBy: 'scheduler-1',
        fromSeq: 1,
        toSeqRequested: null,
        verifiedThroughSeq: $verifiedThrough,
        brokenAtSeq: $brokenAt,
        attestOutcome: $outcome === 'errored' ? null : 'ok',
        policyFingerprint: hash('sha256', 'policy'),
        source: $source,
        outputDigest: null,
        errorClass: null,
        verifierVersions: ['attest-laravel' => '1.1.0', 'attest' => '1.3.0', 'verdict' => '0.15.0'],
    );
}

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_chain_verifications_table.php.stub')->up();
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_chain_verifications');
});

it('binds a null default a host may replace', function (): void {
    expect(app(EvidenceIntegrity::class))->toBeInstanceOf(NullEvidenceIntegrity::class);

    $replacement = new class implements EvidenceIntegrity
    {
        public function chains(): array
        {
            return [];
        }
    };
    app()->instance(EvidenceIntegrity::class, $replacement);

    expect(app(EvidenceIntegrity::class))->toBe($replacement);
});

it('reports nothing when the effective sink is not chained, whatever chain keys say', function (): void {
    // Chain settings beside a non-attest effective writer are inert (ADR 0002 §2).
    config()->set('verdict.evidence.recorder', 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder');
    config()->set('verdict.evidence.attest.chain', 'orders-chain');

    expect(app(EvidenceIntegrity::class)->chains())->toBe([]);
});

it('reports a fixed chain with no recorded verification as unverified', function (): void {
    config()->set('verdict.evidence.recorder', ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'orders-chain');

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->chainId)->toBe('orders-chain')
        ->and($views[0]->state)->toBe(ChainIntegrityState::Unverified)
        ->and($views[0]->lastCompleted)->toBeNull()
        ->and($views[0]->lastAttempt)->toBeNull()
        // The null default cannot read gap marks: no information, never zero (ADR 0002 §6).
        ->and($views[0]->gaps)->toBeNull();
});

it('reports a resolver topology with no named chains as unnameable', function (): void {
    config()->set('verdict.evidence.recorder', ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain_resolver', 'App\\Attest\\TenantChains');

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->state)->toBe(ChainIntegrityState::Unnameable)
        ->and($views[0]->unnameableReason)->toBe(UnnameableReason::NoNamedChains);
});

it('reports host-named chains in the named order under a resolver topology', function (): void {
    config()->set('verdict.evidence.recorder', ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain_resolver', 'App\\Attest\\TenantChains');
    config()->set('verdict-console.integrity.chains', ['tenant-b', 'tenant-a']);

    $views = app(EvidenceIntegrity::class)->chains();

    expect(array_map(fn ($view) => $view->chainId, $views))->toBe(['tenant-b', 'tenant-a'])
        ->and($views[0]->state)->toBe(ChainIntegrityState::Unverified);
});

it('fails closed to unnameable when verdict itself rejects the topology', function (): void {
    // Exactly one of chain / chain_resolver may be set upstream; both set is not a topology to
    // report on (ADR 0002 §3).
    config()->set('verdict.evidence.recorder', ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'orders-chain');
    config()->set('verdict.evidence.attest.chain_resolver', 'App\\Attest\\TenantChains');

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->state)->toBe(ChainIntegrityState::Unnameable)
        ->and($views[0]->unnameableReason)->toBe(UnnameableReason::InvalidTopology);
});

it('treats an attest sink with neither chain nor resolver as the same invalid topology', function (): void {
    config()->set('verdict.evidence.recorder', ATTEST_RECORDER);

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->state)->toBe(ChainIntegrityState::Unnameable)
        ->and($views[0]->unnameableReason)->toBe(UnnameableReason::InvalidTopology);
});

it('ignores the host chain list beside a fixed chain: the fixed chain alone reports', function (): void {
    config()->set('verdict.evidence.recorder', ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'orders-chain');
    config()->set('verdict-console.integrity.chains', ['other-a', 'other-b']);

    expect(array_map(fn ($view) => $view->chainId, app(EvidenceIntegrity::class)->chains()))->toBe(['orders-chain']);
});

it('derives the effective sink through the posture boundary, not by re-reading config', function (): void {
    // Config says nothing is chained; the bound posture says chained. The boundary must follow
    // the posture contract — the one source every sink-answering surface shares (#105).
    // Every config signal disagrees with the double — writer precedence included — so identity
    // or applicability re-read from config, rather than the posture's recordedBy, fails here.
    config()->set('verdict.evidence.recorder', 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder');
    config()->set('verdict.evidence.writer', 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder');
    config()->set('verdict.evidence.attest.chain', 'config-chain');
    app()->instance(EvidenceSinkPosture::class, new class implements EvidenceSinkPosture
    {
        public function read(): SinkPosture
        {
            return new SinkPosture(
                state: EvidenceRecordingState::Chained,
                effectiveWriter: ATTEST_RECORDER,
                recordedBy: 'posture-chain',
                table: null,
                connection: null,
                chainConfigured: true,
            );
        }
    });

    $views = app(EvidenceIntegrity::class)->chains();

    expect($views)->toHaveCount(1)
        ->and($views[0]->chainId)->toBe('posture-chain');
});

// --- the two-group record ------------------------------------------------------------------------

it('derives the standing state from the last completed verification', function (): void {
    config()->set('verdict.evidence.recorder', ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'orders-chain');

    app(ChainVerificationStore::class)->record('orders-chain', recordedRun());

    $view = app(EvidenceIntegrity::class)->chains()[0];

    expect($view->state)->toBe(ChainIntegrityState::Verified)
        ->and($view->lastCompleted?->verifiedThroughSeq)->toBe(120)
        ->and($view->lastCompleted?->verifierVersions)->toBe(['attest-laravel' => '1.1.0', 'attest' => '1.3.0', 'verdict' => '0.15.0']);
});

it('keeps the standing claim when a newer attempt errors', function (): void {
    config()->set('verdict.evidence.recorder', ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'orders-chain');

    $store = app(ChainVerificationStore::class);
    $store->record('orders-chain', recordedRun());
    $store->record('orders-chain', new RecordedVerification(
        outcome: 'errored',
        ranAt: new DateTimeImmutable('2026-09-02T08:00:00+00:00'),
        ranBy: 'scheduler-1',
        fromSeq: 1,
        toSeqRequested: null,
        verifiedThroughSeq: null,
        brokenAtSeq: null,
        attestOutcome: null,
        policyFingerprint: hash('sha256', 'policy'),
        source: 'automated',
        outputDigest: null,
        errorClass: 'RuntimeException',
        verifierVersions: ['attest-laravel' => '1.1.0'],
    ));

    $view = app(EvidenceIntegrity::class)->chains()[0];

    // ADR 0002 §4: an errored attempt never erases the standing claim.
    expect($view->state)->toBe(ChainIntegrityState::Verified)
        ->and($view->lastCompleted?->outcome)->toBe('verified')
        ->and($view->lastAttempt?->outcome)->toBe('errored')
        ->and($view->lastAttempt?->ranAt->format(DATE_ATOM))->toBe('2026-09-02T08:00:00+00:00');
});

it('preserves a failed standing claim through an errored attempt, and lets a completed run replace both groups', function (): void {
    config()->set('verdict.evidence.recorder', ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'orders-chain');

    $store = app(ChainVerificationStore::class);
    $store->record('orders-chain', recordedRun(outcome: 'failed', verifiedThrough: null, brokenAt: 87));
    $store->record('orders-chain', new RecordedVerification(
        outcome: 'errored',
        ranAt: new DateTimeImmutable('2026-09-02T08:00:00+00:00'),
        ranBy: 'scheduler-1',
        fromSeq: 1,
        toSeqRequested: null,
        verifiedThroughSeq: null,
        brokenAtSeq: null,
        attestOutcome: null,
        policyFingerprint: hash('sha256', 'policy'),
        source: 'automated',
        outputDigest: null,
        errorClass: 'RuntimeException',
        verifierVersions: [],
    ));

    $view = app(EvidenceIntegrity::class)->chains()[0];

    expect($view->state)->toBe(ChainIntegrityState::Failed)
        ->and($view->lastAttempt?->errorClass)->toBe('RuntimeException');

    // A later completed run replaces both groups.
    $store->record('orders-chain', recordedRun());

    $view = app(EvidenceIntegrity::class)->chains()[0];

    expect($view->state)->toBe(ChainIntegrityState::Verified)
        ->and($view->lastAttempt?->outcome)->toBe('verified');
});

it('reports a failed completed verification as failed, one row per chain', function (): void {
    config()->set('verdict.evidence.recorder', ATTEST_RECORDER);
    config()->set('verdict.evidence.attest.chain', 'orders-chain');

    $store = app(ChainVerificationStore::class);
    $store->record('orders-chain', recordedRun());
    $store->record('orders-chain', recordedRun(outcome: 'failed', verifiedThrough: null, brokenAt: 87));

    $view = app(EvidenceIntegrity::class)->chains()[0];

    expect($view->state)->toBe(ChainIntegrityState::Failed)
        ->and($view->lastCompleted?->brokenAtSeq)->toBe(87)
        ->and(DB::table('verdict_console_chain_verifications')->count())->toBe(1);
});
