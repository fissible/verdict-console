<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Integrity;

final readonly class ChainIntegrityView
{
    public function __construct(
        public string $chainId,
        public ChainIntegrityState $state,
        public ?UnnameableReason $unnameableReason,
        public ?RecordedVerification $lastCompleted,
        public ?RecordedVerification $lastAttempt,
        public ?GapTrace $gaps,
    ) {}
}
