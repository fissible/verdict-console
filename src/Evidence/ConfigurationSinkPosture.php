<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Configuration-only sink selection reader. It never resolves or instantiates a recorder.
 */
final readonly class ConfigurationSinkPosture implements EvidenceSinkPosture
{
    private const string NULL_RECORDER = 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder';

    private const string DATABASE_RECORDER = 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder';

    private const string ATTEST_RECORDER = 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder';

    public function __construct(private Config $config) {}

    public function read(): SinkPosture
    {
        $effectiveWriter = $this->effectiveWriter();
        $chain = $this->config->get('verdict.evidence.attest.chain');
        $resolver = $this->config->get('verdict.evidence.attest.chain_resolver');

        if (! is_string($effectiveWriter)) {
            return new SinkPosture(
                state: EvidenceRecordingState::Elsewhere,
                effectiveWriter: null,
                recordedBy: null,
                table: null,
                connection: null,
                chainConfigured: $this->chainConfigured($chain, $resolver),
                configuredChain: $this->normalized($chain),
                chainResolver: $this->normalized($resolver),
            );
        }

        if ($effectiveWriter === self::NULL_RECORDER) {
            return $this->posture(EvidenceRecordingState::Off, $effectiveWriter, null, $chain, $resolver);
        }

        if ($effectiveWriter === self::DATABASE_RECORDER) {
            return $this->posture(EvidenceRecordingState::On, $effectiveWriter, null, $chain, $resolver);
        }

        if ($effectiveWriter === self::ATTEST_RECORDER) {
            return $this->posture(EvidenceRecordingState::Chained, $effectiveWriter, $this->chainIdentity($chain, $resolver), $chain, $resolver);
        }

        return $this->posture(EvidenceRecordingState::Elsewhere, $effectiveWriter, $effectiveWriter, $chain, $resolver);
    }

    private function effectiveWriter(): mixed
    {
        $writer = $this->config->get('verdict.evidence.writer');

        if (is_string($writer) && $writer !== '') {
            return $writer;
        }

        if ($writer !== null && ! is_string($writer)) {
            return $writer;
        }

        $recorder = $this->config->get('verdict.evidence.recorder');

        if (is_string($recorder)) {
            return $recorder === '' ? self::NULL_RECORDER : $recorder;
        }

        return $recorder ?? self::NULL_RECORDER;
    }

    private function posture(EvidenceRecordingState $state, string $effectiveWriter, ?string $recordedBy, mixed $chain, mixed $resolver): SinkPosture
    {
        $table = $state === EvidenceRecordingState::On ? $this->config->get('verdict.evidence.table', 'verdict_evidence') : null;
        $connection = $state === EvidenceRecordingState::On ? $this->config->get('verdict.evidence.connection') : null;

        return new SinkPosture(
            state: $state,
            effectiveWriter: $effectiveWriter,
            recordedBy: $recordedBy,
            table: $state === EvidenceRecordingState::On ? (is_string($table) ? $table : 'verdict_evidence') : null,
            connection: is_string($connection) ? $connection : null,
            chainConfigured: $this->chainConfigured($chain, $resolver),
            configuredChain: $this->normalized($chain),
            chainResolver: $this->normalized($resolver),
        );
    }

    private function chainIdentity(mixed $chain, mixed $resolver): ?string
    {
        $chain = is_string($chain) && $chain !== '' ? $chain : null;
        $resolver = is_string($resolver) && $resolver !== '' ? $resolver : null;

        return $chain !== null && $resolver === null ? $chain : ($chain === null ? $resolver : null);
    }

    private function chainConfigured(mixed $chain, mixed $resolver): bool
    {
        return (is_string($chain) && $chain !== '') || (is_string($resolver) && $resolver !== '');
    }

    private function normalized(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
