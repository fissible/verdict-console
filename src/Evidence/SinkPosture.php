<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

final readonly class SinkPosture
{
    public function __construct(
        public EvidenceRecordingState $state,
        public ?string $effectiveWriter,
        public ?string $recordedBy,
        public ?string $table,
        public ?string $connection,
        public bool $chainConfigured,
    ) {}
}
