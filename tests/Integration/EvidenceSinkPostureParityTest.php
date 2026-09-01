<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\EvidenceRecorder;
use Fissible\Verdict\Contracts\EvidenceWriter;
use Fissible\Verdict\Evidence\ContextReleaseEvidence;
use Fissible\Verdict\Evidence\DecisionEvidence;
use Fissible\Verdict\Evidence\ProvenanceDerivation;
use Fissible\Verdict\Evidence\ProvenanceEntry;
use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;

const PARITY_NULL_RECORDER = 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder';
const PARITY_DATABASE_RECORDER = 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder';

/** An instantiable host writer for the parity check: narrow contract, records nothing. */
final class PostureParityWriter implements EvidenceWriter
{
    public function record(DecisionEvidence $evidence): void {}

    public function recordRelease(ContextReleaseEvidence $evidence): void {}

    public function recordProvenance(ProvenanceEntry $entry): void {}

    public function recordDerivation(ProvenanceDerivation $derivation): void {}
}

/**
 * #105: the precedence is measured, not hand-written lore — for every supported non-empty
 * selection the posture's effectiveWriter must equal the class Verdict's own EvidenceWriter
 * binding resolves. This lives in the Integration suite because it needs Verdict's real container
 * bindings; the shipped reader itself never touches them. The empty-writer divergence stays
 * deliberately outside this parity — Verdict throws there, and its alignment is decided in the
 * Feature posture suite.
 */
it('matches Verdicts own writer resolution for every supported non-empty selection', function (): void {
    $verdictResolves = function (): string {
        // The EvidenceWriter binding is scoped and the legacy EvidenceRecorder it falls back to is
        // a singleton; both hold what they resolved, so both are forgotten to re-read config fresh.
        app()->forgetInstance(EvidenceRecorder::class);
        app()->forgetScopedInstances();

        return app(EvidenceWriter::class)::class;
    };

    $posture = fn (): ?string => app(EvidenceSinkPosture::class)->read()->effectiveWriter;

    config()->set('verdict.evidence.writer', null);
    config()->set('verdict.evidence.recorder', PARITY_NULL_RECORDER);

    expect($posture())->toBe($verdictResolves())->toBe(PARITY_NULL_RECORDER);

    config()->set('verdict.evidence.recorder', PARITY_DATABASE_RECORDER);

    expect($posture())->toBe($verdictResolves())->toBe(PARITY_DATABASE_RECORDER);

    config()->set('verdict.evidence.writer', PostureParityWriter::class);

    expect($posture())->toBe($verdictResolves())->toBe(PostureParityWriter::class, 'The narrow writer key must win over the legacy recorder, exactly as Verdict resolves it.');
});
