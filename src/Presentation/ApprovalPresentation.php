<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Presentation;

/**
 * A durable, display-safe projection of a requested tool call.
 *
 * `details` is deliberately host-owned: putting an application value there is an explicit disclosure
 * decision by that host's presenter. The shipped default always leaves it empty.
 */
final readonly class ApprovalPresentation
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $tool,
        public string $argumentsFingerprint,
        public ?string $reason = null,
        public ?string $capability = null,
        public array $details = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'capability' => $this->capability,
            'reason' => $this->reason,
            'arguments_fingerprint' => $this->argumentsFingerprint,
            'details' => $this->details,
        ];
    }
}
