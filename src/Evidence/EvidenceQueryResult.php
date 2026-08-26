<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

/** @param list<EvidenceRecord> $records */
final readonly class EvidenceQueryResult
{
    /** @param list<EvidenceRecord> $records */
    public function __construct(
        public EvidenceRecordingState $recording,
        public array $records,
        /** The configured writer when evidence is retained somewhere this table adapter cannot read. */
        public ?string $recordedBy = null,
    ) {}
}
