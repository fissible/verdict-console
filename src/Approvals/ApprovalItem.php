<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

use DateTimeImmutable;
use Fissible\Verdict\Approvals\ApprovalChallenge;
use Fissible\Verdict\Approvals\ApprovalReceiptStatus;
use Fissible\Verdict\Approvals\ApprovalStatusView;
use Fissible\Verdict\Approvals\ApproverSummaryRelease;
use Fissible\Verdict\Approvals\ProposalProvenance;
use Fissible\Verdict\Approvals\ProvenanceDisclosure;
use Fissible\Verdict\Approvals\UpstreamSource;
use Fissible\Verdict\Context\Trust;
use Fissible\Verdict\Support\ApproverSummary;

/**
 * A render-time projection of one console row and its live Verdict status view.
 *
 * Receipt status, expiry, and capability context are copied from the status view for this render
 * and are never written to the console's workflow index. A pending challenge contributes only
 * provenance, which the status view intentionally does not carry.
 *
 * An inbox makes one status read per row and consults `challengeForToolCall()` only to disclose
 * provenance for an accepted, pending, unexpired view.
 */
final readonly class ApprovalItem
{
    /**
     * @param  array<string, mixed>|null  $presentation
     * @param  list<ApprovalVerb>  $verbs
     * @param  array<string, mixed>|null  $provenance
     * @param  array<string, string>|null  $approverSummary
     * @param  list<string>  $collidedReceiptIds
     */
    private function __construct(
        public string $id,
        public string $toolCallId,
        public ?string $receiptId,
        public ?array $presentation,
        public ?string $capability,
        public ?string $reason,
        public string $reasonLabel,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $waitingSince,
        public string $state,
        public ?string $receiptStatus,
        public string $resumability,
        public ?string $unresumableReason,
        public array $verbs,
        public ?array $provenance,
        public ?array $approverSummary,
        public array $collidedReceiptIds = [],
    ) {}

    /**
     * @param  list<ApprovalVerb>  $verbs
     * @param  list<string>  $collidedReceiptIds
     */
    public static function from(
        PendingApproval $approval,
        ?ApprovalStatusView $view,
        ?ApprovalChallenge $challenge,
        array $verbs,
        array $collidedReceiptIds = [],
    ): self {
        $pending = $view?->status === ApprovalReceiptStatus::Pending;
        $unlapsed = $view !== null && $view->expiresAt > now();

        return new self(
            id: (string) $approval->getKey(),
            toolCallId: $approval->tool_call_id,
            receiptId: $view === null ? $approval->receipt_id : $view->receiptId,
            presentation: $approval->presentation,
            capability: $view?->capability,
            reason: $view?->reason,
            reasonLabel: 'Why this capability is gated',
            expiresAt: $view?->expiresAt,
            // The view owns live rendering fields. Its createdAt is the receipt issuance instant
            // that Verdict #300 threads onto the challenge; the console row is only ingestion.
            waitingSince: $view?->createdAt,
            state: $collidedReceiptIds !== []
                ? 'collided'
                : ($view === null
                ? 'receipt_unavailable'
                : ($pending && $unlapsed ? 'pending' : ($pending ? 'lapsed_undecided' : 'already_decided'))),
            receiptStatus: $view?->status->value,
            resumability: $approval->resumability->value,
            unresumableReason: $approval->unresumable_reason?->value,
            verbs: $verbs,
            provenance: $pending && $unlapsed ? self::provenance($challenge?->provenance) : null,
            approverSummary: $pending && $unlapsed
                ? self::approverSummary($challenge?->approverSummaryRelease, $challenge?->approverSummary)
                : null,
            collidedReceiptIds: $collidedReceiptIds,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tool_call_id' => $this->toolCallId,
            'receipt_id' => $this->receiptId,
            'presentation' => $this->presentation,
            'capability' => $this->capability,
            'reason' => $this->reason,
            'reason_label' => $this->reasonLabel,
            'expires_at' => $this->expiresAt?->format(DATE_ATOM),
            'waiting_since' => $this->waitingSince?->format(DATE_ATOM),
            'state' => $this->state,
            'receipt_status' => $this->receiptStatus,
            'resumability' => $this->resumability,
            'unresumable_reason' => $this->unresumableReason,
            'verbs' => array_map(static fn (ApprovalVerb $verb): string => $verb->value, $this->verbs),
            'provenance' => $this->provenance,
            'approver_summary' => $this->approverSummary,
            'collided_receipt_ids' => $this->collidedReceiptIds,
        ];
    }

    /** @return array<string, string>|null */
    private static function approverSummary(
        ?ApproverSummaryRelease $release,
        ?ApproverSummary $summary,
    ): ?array {
        if ($release === null) {
            return null;
        }

        if ($release === ApproverSummaryRelease::Released) {
            if ($summary === null) {
                return null;
            }

            return [
                'state' => $release->value,
                'content' => $summary->content,
                'fingerprint' => $summary->fingerprint,
            ];
        }

        return ['state' => $release->value];
    }

    /** @return array<string, mixed> */
    private static function provenance(?ProposalProvenance $provenance): array
    {
        if ($provenance === null) {
            return ['state' => 'issued_before_provenance_capture', 'message' => 'issued before provenance capture'];
        }

        return match ($provenance->disclosure) {
            ProvenanceDisclosure::Unknown => [
                'state' => 'unknown',
                'message' => 'provenance unknown — no derivation was declared',
            ],
            ProvenanceDisclosure::Unreleased => [
                'state' => 'unreleased',
                'message' => 'the application has not configured provenance release to approvers',
            ],
            ProvenanceDisclosure::Declared => [
                'state' => 'declared',
                'sources' => array_map(self::source(...), $provenance->sources),
                'undescribed_source_count' => $provenance->undescribedSourceCount,
                'withheld_source_count' => $provenance->withheldSourceCount,
            ],
        };
    }

    /** @return array{kind: string, name: string, trust: string, data_class: string, channel: string, warning: bool} */
    private static function source(UpstreamSource $source): array
    {
        return [
            'kind' => $source->source->kind,
            'name' => $source->source->name,
            'trust' => $source->trust->value,
            'data_class' => $source->dataClass->value,
            'channel' => $source->channel->value,
            // Source::user(), Source::application(), and Source::external() define this vocabulary.
            // An untrusted user source is intentionally not a warning: the ADR signal is non-user
            // untrusted upstream content that can be mistaken for the requester's own instruction.
            'warning' => $source->source->kind !== 'user' && $source->trust === Trust::Untrusted,
        ];
    }
}
