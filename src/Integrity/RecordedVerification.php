<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Integrity;

use DateTimeImmutable;

final readonly class RecordedVerification
{
    /** @param array<string, string> $verifierVersions */
    public function __construct(
        public string $outcome,
        public DateTimeImmutable $ranAt,
        public string $ranBy,
        public int $fromSeq,
        public ?int $toSeqRequested,
        public ?int $verifiedThroughSeq,
        public ?int $brokenAtSeq,
        public ?string $attestOutcome,
        public string $policyFingerprint,
        public string $source,
        public ?string $outputDigest,
        public ?string $errorClass,
        public array $verifierVersions,
    ) {}
}
