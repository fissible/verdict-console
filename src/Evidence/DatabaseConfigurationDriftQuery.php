<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\VerdictConsole\Contracts\ConfigurationDriftQuery;
use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;

/**
 * Aggregates observed configuration fingerprints from Verdict's published decision evidence.
 *
 * This is observed history, not a write log: configurations that never decided anything have no
 * row to aggregate.
 */
final readonly class DatabaseConfigurationDriftQuery implements ConfigurationDriftQuery
{
    public function __construct(
        private DatabaseManager $database,
        private Container $app,
    ) {}

    public function observed(): ConfigurationDriftResult
    {
        $posture = $this->app->make(EvidenceSinkPosture::class)->read();

        if ($posture->state !== EvidenceRecordingState::On) {
            return new ConfigurationDriftResult($posture->state, [], $posture->recordedBy);
        }

        if ($posture->table === null) {
            throw new \LogicException('A readable evidence posture must name a table.');
        }

        $rows = $this->database
            ->connection($posture->connection)
            ->table($posture->table)
            ->selectRaw('capability, configuration_fingerprint, MIN(recorded_at) as first_observed_at, MAX(recorded_at) as last_observed_at, COUNT(*) as decision_count')
            ->where('record_type', 'decision')
            ->whereNotNull('capability')
            ->whereNotNull('configuration_fingerprint')
            ->groupBy('capability', 'configuration_fingerprint')
            ->orderBy('capability')
            ->orderByDesc('last_observed_at')
            ->orderBy('configuration_fingerprint')
            ->get();

        $observations = [];

        foreach ($rows as $row) {
            $observations[] = new ObservedConfiguration(
                capability: (string) $row->capability,
                configurationFingerprint: (string) $row->configuration_fingerprint,
                firstObservedAt: $this->date((string) $row->first_observed_at),
                lastObservedAt: $this->date((string) $row->last_observed_at),
                decisionCount: (int) $row->decision_count,
            );
        }

        return new ConfigurationDriftResult(EvidenceRecordingState::On, $observations);
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
