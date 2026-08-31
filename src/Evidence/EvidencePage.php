<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

/** @param list<EvidenceRecord> $records */
final readonly class EvidencePage
{
    /** @param list<EvidenceRecord> $records */
    public function __construct(
        public EvidenceRecordingState $recording,
        public array $records,
        public int $total,
        public int $page,
        public int $perPage,
        /** The configured writer when evidence is retained somewhere this table adapter cannot read. */
        public ?string $recordedBy = null,
        /** Null means no conversation filter was requested. */
        public ?ConversationCorrelation $conversation = null,
    ) {}
}
