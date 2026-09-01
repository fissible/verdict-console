<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Reviews;

/** @phpstan-type ReviewItems list<ReviewItem> */
final readonly class ReviewQueueResult
{
    /** @param list<ReviewItem> $items */
    public function __construct(
        public ReviewQueueState $state,
        public array $items,
    ) {}
}
