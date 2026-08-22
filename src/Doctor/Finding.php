<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Doctor;

/**
 * One thing that is wrong, what it will do if left, and how to fix it.
 *
 * Returned as data rather than printed so a UI can render the same findings later (VC-22) without
 * re-deriving them from console output.
 */
final readonly class Finding
{
    public function __construct(
        public FindingCode $code,
        public Severity $severity,
        /** What the finding is about: an agent class, a resolver key, or a capability name. */
        public string $subject,
        /** What is wrong, and what it causes at runtime. */
        public string $summary,
        /** What to change. Never "check your configuration". */
        public string $fix,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity->value,
            'subject' => $this->subject,
            'summary' => $this->summary,
            'fix' => $this->fix,
        ];
    }
}
