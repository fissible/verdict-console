<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Listeners;

use Fissible\VerdictConsole\Events\ApprovalIngestionIncident;
use Psr\Log\LoggerInterface;

/** The default, intentionally non-durable sink until VC-15's incident projection exists. */
final readonly class LogApprovalIngestionIncident
{
    public function __construct(private LoggerInterface $logger) {}

    public function handle(ApprovalIngestionIncident $incident): void
    {
        $this->logger->warning('Verdict Console recorded a paused approval it cannot resume.', [
            'pending_approval_id' => $incident->pendingApproval->id,
            'tool_call_id' => $incident->pendingApproval->tool_call_id,
            'unresumable_reason' => $incident->reason->value,
        ]);
    }
}
