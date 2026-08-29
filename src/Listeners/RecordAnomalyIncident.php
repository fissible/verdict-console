<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Listeners;

use Fissible\Verdict\Approvals\ApprovalOutcome;
use Fissible\Verdict\Capabilities\Events\CapabilityConfigurationUnrecorded;
use Fissible\Verdict\Evidence\Events\ChainWriteFailed;
use Fissible\Verdict\Evidence\Events\ConsequentialActionUnrecorded;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\VerdictConsole\Events\ApprovalDecisionRefused;
use Fissible\VerdictConsole\Events\ApprovalIngestionIncident;
use Fissible\VerdictConsole\Incidents\IncidentStore;

/** Projects the six ephemeral anomaly events into the console's durable incident ledger. */
final readonly class RecordAnomalyIncident
{
    public function __construct(private IncidentStore $incidents) {}

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof ConsequentialActionUnrecorded => $this->incidents->record(
                'consequential_action_unrecorded', $event->message,
            ),
            $event instanceof EvidenceWriteFailed => $this->incidents->record('evidence_write_failed', $event->message, [
                'capability' => $event->capability,
                'stage' => $event->stage,
                'invocation_id' => $event->invocationId,
            ]),
            $event instanceof ChainWriteFailed => $this->incidents->record('chain_write_failed', $event->message, [
                'chain_id' => $event->chainId,
                'correlation_id' => $event->correlationId,
                'record_type' => $event->recordType,
                'phase' => $event->phase,
                'attempts' => $event->attempts,
            ]),
            $event instanceof CapabilityConfigurationUnrecorded => $this->incidents->record(
                'capability_configuration_unrecorded', $event->reason, [
                    'capability' => $event->capability,
                    'configuration_fingerprint' => $event->configurationFingerprint,
                ],
            ),
            $event instanceof ApprovalIngestionIncident => $this->incidents->recordIngestion($event->pendingApproval, $event->reason),
            $event instanceof ApprovalDecisionRefused => $this->incidents->record(
                'approval_decision_refused', ApprovalOutcome::Unauthorized->value, [
                    'decision' => $event->kind->value,
                    'tool_call_id' => $event->pendingApproval->tool_call_id,
                    'pending_approval_id' => $event->pendingApproval->id,
                ],
            ),
            default => null,
        };
    }
}
