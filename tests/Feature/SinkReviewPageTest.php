<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsole\Evidence\SinkPosture;

/**
 * #107: the evidence-sink review — a rendering of the #105 posture that makes the recording
 * decision reviewable. Off is two different facts: nobody decided, or the host explicitly recorded
 * the decision to run without evidence (verdict-console.evidence.accepted_off). The page is a pure
 * function of the posture contract and that config key; no database exists in this file.
 */
const SINK_REVIEW_TRADEOFF = 'configuring the shipped attest recorder chains records written by later record() calls; it neither backfills nor makes pre-existing rows verifiable through the chain';

function bindSinkPosture(
    EvidenceRecordingState $state,
    ?string $effectiveWriter = null,
    ?string $recordedBy = null,
    ?string $table = null,
    ?string $connection = null,
    bool $chainConfigured = false,
): void {
    $posture = new SinkPosture(
        state: $state,
        effectiveWriter: $effectiveWriter,
        recordedBy: $recordedBy,
        table: $table,
        connection: $connection,
        chainConfigured: $chainConfigured,
    );

    app()->instance(EvidenceSinkPosture::class, new class($posture) implements EvidenceSinkPosture
    {
        public function __construct(private readonly SinkPosture $posture) {}

        public function read(): SinkPosture
        {
            return $this->posture;
        }
    });
}

function renderSinkReview(): string
{
    return (string) test()->blade('<x-verdict-console::sink-review />');
}

function sinkReviewState(string $html): ?string
{
    return preg_match('/<section\b[^>]*\bdata-verdict-console="sink-review"[^>]*\bdata-posture="([^"]*)"/', $html, $m) === 1 ? $m[1] : null;
}

/**
 * The five review states render distinctly — and the two Off variants are different facts: an
 * undecided Off names the missing decision; an acknowledged Off names the decision that was made.
 */
it('renders undecided off, acknowledged off, on, elsewhere, and chained distinctly', function (): void {
    bindSinkPosture(EvidenceRecordingState::Off, effectiveWriter: 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder');

    $off = renderSinkReview();

    config()->set('verdict-console.evidence.accepted_off', true);

    $acknowledged = renderSinkReview();

    config()->set('verdict-console.evidence.accepted_off', false);
    bindSinkPosture(EvidenceRecordingState::On, effectiveWriter: 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder', table: 'host_evidence', connection: 'audit');

    $on = renderSinkReview();

    bindSinkPosture(EvidenceRecordingState::Elsewhere, effectiveWriter: 'App\\Evidence\\ExternalWriter', recordedBy: 'App\\Evidence\\ExternalWriter');

    $elsewhere = renderSinkReview();

    bindSinkPosture(EvidenceRecordingState::Chained, effectiveWriter: 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder', recordedBy: 'main-ledger', chainConfigured: true);

    $chained = renderSinkReview();

    expect(sinkReviewState($off))->toBe('off')
        ->and($off)->toContain('No evidence is being recorded, and no one has decided that.')
        ->and(sinkReviewState($acknowledged))->toBe('off_acknowledged')
        ->and($acknowledged)->toContain('Recording is off by explicit decision (verdict-console.evidence.accepted_off).')
        ->and($acknowledged)->not->toContain('no one has decided')
        ->and(sinkReviewState($on))->toBe('on')
        ->and($on)->toContain('host_evidence')
        ->and($on)->toContain('audit')
        ->and($on)->toContain('Fissible\Verdict\Evidence\DatabaseEvidenceRecorder')
        ->and(sinkReviewState($elsewhere))->toBe('elsewhere')
        ->and($elsewhere)->toContain('Evidence is recorded elsewhere by App\Evidence\ExternalWriter.')
        ->and(sinkReviewState($chained))->toBe('chained')
        ->and($chained)->toContain('A chained sink (main-ledger) is configured; decisions are not readable from this table.');
});

/**
 * The one-way tradeoff is part of reviewing the decision, so both Off variants state it verbatim:
 * choosing the attest recorder later does not repair the gap being created now.
 */
it('states the non-retroactivity tradeoff on both off variants', function (): void {
    bindSinkPosture(EvidenceRecordingState::Off, effectiveWriter: 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder');

    expect(renderSinkReview())->toContain(SINK_REVIEW_TRADEOFF);

    config()->set('verdict-console.evidence.accepted_off', true);

    expect(renderSinkReview())->toContain(SINK_REVIEW_TRADEOFF);
});

/** Only the literal boolean true is a decision; a malformed acknowledgement is not one. */
it('does not read a malformed acknowledgement as a decision', function (?string $note, mixed $value): void {
    bindSinkPosture(EvidenceRecordingState::Off, effectiveWriter: 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder');
    config()->set('verdict-console.evidence.accepted_off', $value);

    expect(sinkReviewState(renderSinkReview()))->toBe('off');
})->with([
    'string true' => ['string', 'true'],
    'integer one' => ['int', 1],
    'yes' => ['yes', 'yes'],
    'null' => ['null', null],
]);

/** The acknowledgement qualifies OFF alone: a readable or chained sink ignores the key entirely. */
it('never renders an acknowledged variant for a non-off posture', function (): void {
    config()->set('verdict-console.evidence.accepted_off', true);
    bindSinkPosture(EvidenceRecordingState::On, effectiveWriter: 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder', table: 'verdict_evidence');

    expect(sinkReviewState(renderSinkReview()))->toBe('on');

    bindSinkPosture(EvidenceRecordingState::Chained, effectiveWriter: 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder', recordedBy: 'main-ledger', chainConfigured: true);

    expect(sinkReviewState(renderSinkReview()))->toBe('chained');

    bindSinkPosture(EvidenceRecordingState::Elsewhere, effectiveWriter: 'App\\Evidence\\ExternalWriter', recordedBy: 'App\\Evidence\\ExternalWriter');

    expect(sinkReviewState(renderSinkReview()))->toBe('elsewhere', 'An acknowledged key never converts an unreadable sink into an acknowledged off.');
});

/** An unnameable writer or identity stays unnamed: no dangling "by .", no invented identity. */
it('renders unnamed elsewhere and chained postures without inventing an identity', function (): void {
    bindSinkPosture(EvidenceRecordingState::Elsewhere, effectiveWriter: null, recordedBy: null);

    $elsewhere = renderSinkReview();

    bindSinkPosture(EvidenceRecordingState::Chained, effectiveWriter: 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder', recordedBy: null, chainConfigured: false);

    $chained = renderSinkReview();

    expect(sinkReviewState($elsewhere))->toBe('elsewhere')
        ->and($elsewhere)->toContain('Evidence is recorded elsewhere; the writer is not nameable.')
        ->and($elsewhere)->not->toContain('elsewhere by')
        ->and(sinkReviewState($chained))->toBe('chained')
        ->and($chained)->toContain('A chained sink is configured; decisions are not readable from this table.')
        ->and($chained)->not->toContain('A chained sink (');
});

/** Everything rendered is escaped on every state: writers, tables, connections, identities. */
it('escapes every value it renders', function (): void {
    bindSinkPosture(EvidenceRecordingState::Elsewhere, effectiveWriter: '<script>alert(1)</script>', recordedBy: '<script>alert(1)</script>');

    $elsewhere = renderSinkReview();

    bindSinkPosture(EvidenceRecordingState::On, effectiveWriter: '<b>writer</b>', table: '<i>table</i>', connection: '<u>conn</u>');

    $on = renderSinkReview();

    bindSinkPosture(EvidenceRecordingState::Chained, effectiveWriter: '<b>attest</b>', recordedBy: '<i>ledger</i>', chainConfigured: true);

    $chained = renderSinkReview();

    expect($elsewhere)->not->toContain('<script>alert(1)</script>')
        ->and($elsewhere)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($on)->not->toContain('<b>writer</b>')
        ->and($on)->toContain('&lt;b&gt;writer&lt;/b&gt;')
        ->and($on)->not->toContain('<i>table</i>')
        ->and($on)->toContain('&lt;i&gt;table&lt;/i&gt;')
        ->and($on)->not->toContain('<u>conn</u>')
        ->and($on)->toContain('&lt;u&gt;conn&lt;/u&gt;')
        ->and($chained)->not->toContain('<i>ledger</i>')
        ->and($chained)->toContain('&lt;i&gt;ledger&lt;/i&gt;');
});

/** A review mutates nothing: the decision lives at config-file level, deliberately. */
it('renders read-only markup with no form', function (): void {
    bindSinkPosture(EvidenceRecordingState::Off, effectiveWriter: 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder');

    expect(renderSinkReview())->not->toContain('<form');
});
