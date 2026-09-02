<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Contracts\EvidenceIntegrity;
use Fissible\VerdictConsole\Integrity\ChainIntegrityState;
use Fissible\VerdictConsole\Integrity\ChainIntegrityView;
use Fissible\VerdictConsole\Integrity\GapTrace;
use Fissible\VerdictConsole\Integrity\RecordedVerification;
use Fissible\VerdictConsole\Integrity\UnnameableReason;

/**
 * ADR 0002 §2/§6, rendered: every state's copy verbatim, the recorded-source attribution variant,
 * the errored attempt beside — never instead of — the standing claim, gap marks in their own
 * words, and nothing at all for a sink that is not chained.
 */
function integrityRun(
    string $outcome = 'verified',
    string $source = 'automated',
    string $ranAt = '2026-09-01T12:00:00+00:00',
    ?int $verifiedThrough = 120,
    ?int $brokenAt = null,
    ?string $attestOutcome = 'ok',
    ?string $outputDigest = null,
    string $ranBy = 'scheduler-1',
): RecordedVerification {
    return new RecordedVerification(
        outcome: $outcome,
        ranAt: new DateTimeImmutable($ranAt),
        ranBy: $ranBy,
        fromSeq: 1,
        toSeqRequested: null,
        verifiedThroughSeq: $verifiedThrough,
        brokenAtSeq: $brokenAt,
        attestOutcome: $attestOutcome,
        policyFingerprint: hash('sha256', 'policy'),
        source: $source,
        outputDigest: $outputDigest,
        errorClass: null,
        verifierVersions: ['attest-laravel' => '1.1.0'],
    );
}

function integrityView(
    ChainIntegrityState $state,
    ?RecordedVerification $completed = null,
    ?RecordedVerification $attempt = null,
    ?GapTrace $gaps = null,
    string $chainId = 'orders-chain',
    ?UnnameableReason $unnameableReason = null,
): ChainIntegrityView {
    return new ChainIntegrityView(
        chainId: $chainId,
        state: $state,
        unnameableReason: $unnameableReason,
        lastCompleted: $completed,
        lastAttempt: $attempt ?? $completed,
        gaps: $gaps,
    );
}

/** @param list<ChainIntegrityView> $views */
function bindIntegrity(array $views): void
{
    app()->instance(EvidenceIntegrity::class, new class($views) implements EvidenceIntegrity
    {
        /** @param list<ChainIntegrityView> $views */
        public function __construct(private readonly array $views) {}

        public function chains(): array
        {
            return $this->views;
        }
    });
}

function renderIntegrity(): string
{
    return (string) test()->blade('<x-verdict-console::integrity />');
}

it('renders nothing at all when there is no chain to report on', function (): void {
    bindIntegrity([]);

    // NotApplicable renders nothing: the evidence surfaces' states already speak (ADR 0002 §2).
    expect(renderIntegrity())->not->toContain('chain')
        ->not->toContain('Verified')
        ->not->toContain('verification');
});

it('renders each states copy verbatim, from the record rather than from the state', function (): void {
    // Every value below is deliberately distinct so a component rendering state-shaped constants
    // — instead of the record's own sequence, instant, and actor — fails on the exact string.
    bindIntegrity([
        integrityView(ChainIntegrityState::Unnameable, chainId: 'resolver', unnameableReason: UnnameableReason::NoNamedChains),
        integrityView(ChainIntegrityState::Unnameable, chainId: 'broken-config', unnameableReason: UnnameableReason::InvalidTopology),
        integrityView(ChainIntegrityState::Unverified, chainId: 'fresh-chain'),
        integrityView(ChainIntegrityState::Verified, completed: integrityRun(verifiedThrough: 205, ranAt: '2026-09-03T05:00:00+00:00', ranBy: 'auditor-2'), chainId: 'good-chain'),
        integrityView(ChainIntegrityState::Failed, completed: integrityRun(outcome: 'failed', verifiedThrough: null, brokenAt: 87, attestOutcome: 'broken_link', ranAt: '2026-09-03T06:30:00+00:00'), chainId: 'bad-chain'),
    ]);

    $html = renderIntegrity();

    expect($html)->toContain('Chained through a resolver; no chains are named for integrity reporting.')
        ->toContain('Chain configuration is invalid; integrity cannot be reported.')
        ->toContain('Not yet verified.')
        ->toContain('Verified through sequence 205 at 2026-09-03T05:00:00+00:00 by auditor-2.')
        ->toContain('Verification failed at sequence 87 (broken_link) at 2026-09-03T06:30:00+00:00.')
        ->not->toContain('the chain is verified')
        ->not->toContain('tampered');
});

it('attributes a recorded-source claim as recorded, never as independently verified', function (): void {
    bindIntegrity([
        integrityView(ChainIntegrityState::Verified, completed: integrityRun(source: 'recorded')),
    ]);

    $html = renderIntegrity();

    expect($html)->toContain('Verified through sequence 120 at 2026-09-01T12:00:00+00:00, as recorded by scheduler-1.')
        ->not->toContain('independently');
});

it('renders byte-identical output for a recorded claim with and without an output digest', function (): void {
    // Same chain id, same record, separately bound and rendered: the only difference is the
    // digest, and ADR 0002 §4 says it changes nothing rendered — not a word, not a marker.
    bindIntegrity([integrityView(ChainIntegrityState::Verified, completed: integrityRun(source: 'recorded'))]);
    $plain = renderIntegrity();

    bindIntegrity([integrityView(ChainIntegrityState::Verified, completed: integrityRun(source: 'recorded', outputDigest: hash('sha256', 'artifact')))]);
    $digested = renderIntegrity();

    expect($digested)->toBe($plain)
        ->and($digested)->not->toContain(hash('sha256', 'artifact'));
});

it('renders a newer errored attempt beside the standing claim, not instead of it', function (): void {
    bindIntegrity([
        integrityView(
            ChainIntegrityState::Verified,
            completed: integrityRun(),
            attempt: integrityRun(outcome: 'errored', ranAt: '2026-09-02T08:00:00+00:00', verifiedThrough: null, attestOutcome: null),
        ),
    ]);

    $html = renderIntegrity();

    expect($html)->toContain('Verified through sequence 120 at 2026-09-01T12:00:00+00:00 by scheduler-1.')
        ->toContain('Last verification attempt errored at 2026-09-02T08:00:00+00:00.');
});

it('renders gap marks in their own words, and their absence as no information', function (): void {
    bindIntegrity([
        integrityView(ChainIntegrityState::Verified, completed: integrityRun(), gaps: new GapTrace(3, new DateTimeImmutable('2026-09-01T07:00:00+00:00')), chainId: 'gappy'),
        integrityView(ChainIntegrityState::Verified, completed: integrityRun(), chainId: 'unknown-gaps'),
    ]);

    $html = renderIntegrity();

    // §6's copy — never merged into a verification outcome; and gaps: null renders as no gap
    // information, never as zero gaps.
    expect($html)->toContain('3 chain-write gap marks (latest 2026-09-01T07:00:00+00:00)')
        ->not->toContain('0 chain-write gap marks');
});
