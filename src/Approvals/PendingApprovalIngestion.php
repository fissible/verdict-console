<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/**
 * The result of atomically recording a pause.
 *
 * `created` is not cosmetic. A redelivered pause returns the original row, but must not emit a
 * second ingestion incident or notification. Only the writer that won the unique-index race owns
 * those one-time side effects.
 */
final readonly class PendingApprovalIngestion
{
    public function __construct(
        public PendingApproval $pendingApproval,
        public bool $created,
    ) {}
}
