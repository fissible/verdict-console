<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;

/**
 * Reads Verdict's published `verdict_evidence` decision-row schema without depending on its writer.
 *
 * The Null recorder check is configuration inspection only. This adapter never resolves Verdict's
 * recorder, imports its recorder contracts, or reaches through a recorder to discover storage.
 */
final readonly class DatabaseEvidenceQuery implements EvidenceQuery
{
    private const string NULL_RECORDER = 'Fissible\\Verdict\\Evidence\\NullEvidenceRecorder';

    private const string DATABASE_RECORDER = 'Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder';

    private const string ATTEST_RECORDER = 'Fissible\\Verdict\\Evidence\\AttestEvidenceRecorder';

    public function __construct(
        private DatabaseManager $database,
        private Config $config,
    ) {}

    public function search(EvidenceFilter $filter): EvidenceQueryResult
    {
        $recording = $this->recording();

        if ($recording['state'] !== EvidenceRecordingState::On) {
            return new EvidenceQueryResult($recording['state'], [], $recording['writer']);
        }

        $connection = $this->config->get('verdict.evidence.connection');
        $table = $this->config->get('verdict.evidence.table', 'verdict_evidence');
        $query = $this->database
            ->connection(is_string($connection) ? $connection : null)
            ->table(is_string($table) ? $table : 'verdict_evidence')
            ->where('record_type', 'decision');

        if ($filter->disposition !== null) {
            $query->where('disposition', $filter->disposition);
        }

        if ($filter->capability !== null) {
            $query->where('capability', $filter->capability);
        }

        if ($filter->recordedFrom !== null) {
            $query->where('recorded_at', '>=', $filter->recordedFrom->setTimezone(new DateTimeZone('UTC')));
        }

        if ($filter->recordedUntil !== null) {
            $query->where('recorded_at', '<=', $filter->recordedUntil->setTimezone(new DateTimeZone('UTC')));
        }

        $records = [];

        foreach ($query->orderBy('recorded_at')->orderBy('id')->get() as $row) {
            $records[] = new EvidenceRecord(
                id: (string) $row->id,
                capability: $this->nullableString($row->capability),
                stage: (string) $row->stage,
                disposition: (string) $row->disposition,
                claimType: $this->nullableString($row->claim_type),
                recordDigest: $this->nullableString($row->record_digest),
                argumentFingerprint: $this->nullableString($row->argument_fingerprint),
                idempotencyKeyFingerprint: $this->nullableString($row->idempotency_key_fingerprint),
                approvalReceiptFingerprint: $this->nullableString($row->approval_receipt_fingerprint),
                configurationFingerprint: $this->nullableString($row->configuration_fingerprint),
                actorFingerprint: $this->nullableString($row->actor_fingerprint),
                subjectFingerprint: $this->nullableString($row->subject_fingerprint),
                proposalTargetIdentityFingerprint: $this->nullableString($row->proposal_target_identity_fingerprint),
                executionTargetIdentityFingerprint: $this->nullableString($row->execution_target_identity_fingerprint),
                rateLimitKeyFingerprint: $this->nullableString($row->rate_limit_key_fingerprint),
                executionClaimFingerprint: $this->nullableString($row->execution_claim_fingerprint),
                executionClaimBindingFingerprint: $this->nullableString($row->execution_claim_binding_fingerprint),
                rateLimitResetAt: $this->nullableDate($row->rate_limit_reset_at),
                recordedAt: $this->date((string) $row->recorded_at),
            );
        }

        return new EvidenceQueryResult(EvidenceRecordingState::On, $records);
    }

    /** @return array{state: EvidenceRecordingState, writer: ?string} */
    private function recording(): array
    {
        // Verdict's narrow writer takes precedence over the legacy mixed recorder. The table is a
        // known sink only for the two shipped durable recorders; an unknown configured writer may
        // retain evidence elsewhere, so calling it an empty table would lie to an operator.
        $writer = $this->config->get('verdict.evidence.writer');
        $effectiveWriter = $writer ?? $this->config->get('verdict.evidence.recorder', self::NULL_RECORDER);

        if ($effectiveWriter === self::NULL_RECORDER) {
            return ['state' => EvidenceRecordingState::Off, 'writer' => null];
        }

        if (in_array($effectiveWriter, [self::DATABASE_RECORDER, self::ATTEST_RECORDER], true)) {
            return ['state' => EvidenceRecordingState::On, 'writer' => null];
        }

        return [
            'state' => EvidenceRecordingState::Elsewhere,
            'writer' => is_string($effectiveWriter) ? $effectiveWriter : null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->date((string) $value);
    }

    private function date(string $value): DateTimeImmutable
    {
        // The published schema is timezone-naive. This adapter treats its values as UTC under
        // Laravel's shipped UTC default; Verdict 0.12 itself does not normalize every writer to
        // UTC, so a host that writes this table in another application timezone owns a custom query.
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
