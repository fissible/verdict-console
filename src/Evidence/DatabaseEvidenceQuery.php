<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

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
        private ConversationInvocationStore $invocations,
    ) {}

    public function search(EvidenceFilter $filter): EvidenceQueryResult
    {
        [$invocationIds, $conversation, $recording] = $this->context($filter);

        if ($recording['state'] !== EvidenceRecordingState::On) {
            return new EvidenceQueryResult($recording['state'], [], $recording['writer'], $conversation);
        }

        if ($conversation === ConversationCorrelation::Unknown) {
            return new EvidenceQueryResult(EvidenceRecordingState::On, [], null, $conversation);
        }

        $records = $this->records(
            $this->filteredQuery($filter, $invocationIds, $conversation)->orderBy('recorded_at')->orderBy('id')->get(),
        );

        return new EvidenceQueryResult(EvidenceRecordingState::On, $records, null, $conversation);
    }

    public function searchPage(EvidenceFilter $filter, int $page, int $perPage): EvidencePage
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        [$invocationIds, $conversation, $recording] = $this->context($filter);

        if ($recording['state'] !== EvidenceRecordingState::On) {
            return new EvidencePage($recording['state'], [], 0, $page, $perPage, $recording['writer'], $conversation);
        }

        if ($conversation === ConversationCorrelation::Unknown) {
            return new EvidencePage(EvidenceRecordingState::On, [], 0, $page, $perPage, null, $conversation);
        }

        $query = $this->filteredQuery($filter, $invocationIds, $conversation);
        $total = (clone $query)->count();
        $records = $this->records(
            $query->orderByDesc('recorded_at')->orderByDesc('id')->forPage($page, $perPage)->get(),
        );

        return new EvidencePage(EvidenceRecordingState::On, $records, $total, $page, $perPage, null, $conversation);
    }

    /** @return array{0: list<string>, 1: ?ConversationCorrelation, 2: array{state: EvidenceRecordingState, writer: ?string}} */
    private function context(EvidenceFilter $filter): array
    {
        $invocationIds = $filter->conversationId === null
            ? []
            : $this->invocations->invocationIdsFor($filter->conversationId);
        // Materialize console-owned ids before querying evidence: verdict.evidence.connection may be
        // a different connection, across which a SQL subquery cannot be composed.
        $conversation = $filter->conversationId === null
            ? null
            : ($invocationIds === [] ? ConversationCorrelation::Unknown : ConversationCorrelation::Known);

        return [$invocationIds, $conversation, $this->recording()];
    }

    /** @param list<string> $invocationIds */
    private function filteredQuery(EvidenceFilter $filter, array $invocationIds, ?ConversationCorrelation $conversation): Builder
    {
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

        if ($conversation === ConversationCorrelation::Known) {
            $query->whereIn('invocation_id', $invocationIds);
        }

        if ($filter->invocationId !== null) {
            $query->where('invocation_id', $filter->invocationId);
        }

        return $query;
    }

    /**
     * @param  iterable<\stdClass>  $rows
     * @return list<EvidenceRecord>
     */
    private function records(iterable $rows): array
    {
        $records = [];

        foreach ($rows as $row) {
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
                invocationId: $this->nullableString($row->invocation_id),
                rateLimitResetAt: $this->nullableDate($row->rate_limit_reset_at),
                recordedAt: $this->date((string) $row->recorded_at),
            );
        }

        return $records;
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
        // The published schema is timezone-naive, and Verdict 0.13 (fissible/verdict#335) mints
        // every evidence timestamp in UTC, so reading as UTC is the write side's own contract. Rows
        // written by an earlier Verdict on a non-UTC application timezone predate that contract; a
        // host with such history owns a custom query rather than this adapter guessing an offset.
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
