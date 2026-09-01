<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

use DateTimeImmutable;

final readonly class ObservedConfiguration
{
    public function __construct(
        public string $capability,
        public string $configurationFingerprint,
        public DateTimeImmutable $firstObservedAt,
        public DateTimeImmutable $lastObservedAt,
        public int $decisionCount,
    ) {}
}
