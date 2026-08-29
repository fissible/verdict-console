<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Configuration;

/**
 * One rate-limit policy with the capability it guards.
 * Display-safe declared configuration: names, limits, and postures, never closures or resolved targets.
 */
final readonly class RateLimitInspection
{
    public function __construct(
        public string $capability,
        public string $name,
        public int $limit,
        public int $windowSeconds,
        public ?string $reason,
    ) {}

    /** @return array{capability: string, name: string, limit: int, window_seconds: int, reason: ?string} */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability,
            'name' => $this->name,
            'limit' => $this->limit,
            'window_seconds' => $this->windowSeconds,
            'reason' => $this->reason,
        ];
    }
}
