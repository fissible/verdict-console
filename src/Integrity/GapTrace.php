<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Integrity;

use DateTimeImmutable;

final readonly class GapTrace
{
    public function __construct(
        public int $persistedMarks,
        public ?DateTimeImmutable $latestMarkAt,
    ) {}
}
