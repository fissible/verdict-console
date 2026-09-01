<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Reviews;

use DateTimeImmutable;

/** Display-safe projection of one Verdict review-status view. */
final readonly class ReviewItem
{
    public function __construct(
        public string $requestId,
        public string $capability,
        public ReviewItemState $state,
        public ?string $reason,
        public ?string $summaryFingerprint,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public ?string $resolvedBy,
        public ?DateTimeImmutable $resolvedAt,
    ) {}
}
