<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Integrity;

/** The two independently replaced record groups held for one chain. */
final readonly class ChainVerificationRecord
{
    public function __construct(
        public ?RecordedVerification $lastCompleted,
        public ?RecordedVerification $lastAttempt,
    ) {}
}
