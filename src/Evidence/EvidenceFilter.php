<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

use DateTimeImmutable;

/** Optional filters over the durable decision-evidence vocabulary. */
final readonly class EvidenceFilter
{
    public function __construct(
        public ?string $disposition = null,
        public ?string $capability = null,
        public ?DateTimeImmutable $recordedFrom = null,
        public ?DateTimeImmutable $recordedUntil = null,
        public ?string $conversationId = null,
        public ?string $invocationId = null,
        public ?string $actorFingerprint = null,
        public ?string $subjectFingerprint = null,
        public ?string $argumentFingerprint = null,
        public ?string $approvalReceiptFingerprint = null,
        public ?string $configurationFingerprint = null,
        public ?string $executionClaimFingerprint = null,
    ) {}
}
