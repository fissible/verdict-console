<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

/** @param list<ObservedConfiguration> $observations */
final readonly class ConfigurationDriftResult
{
    /** @param list<ObservedConfiguration> $observations */
    public function __construct(
        public EvidenceRecordingState $recording,
        public array $observations,
        public ?string $recordedBy = null,
    ) {}
}
