<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Illuminate\Contracts\Container\Container;
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
    public function __construct(
        private DatabaseManager $database,
        private ConversationInvocationStore $invocations,
        private Container $app,
    ) {}

    public function search(EvidenceFilter $filter): EvidenceQueryResult
    {
        [$invocationIds, $conversation, $posture] = $this->context($filter);

        if ($posture->state !== EvidenceRecordingState::On) {
            return new EvidenceQueryResult($posture->state, [], $posture->recordedBy, $conversation);
        }

        if ($conversation === ConversationCorrelation::Unknown) {
            return new EvidenceQueryResult(EvidenceRecordingState::On, [], null, $conversation);
        }

        $records = $this->records(
            $this->filteredQuery($filter, $invocationIds, $conversation, $posture)->orderBy('recorded_at')->orderBy('id')->get(),
        );

        return new EvidenceQueryResult(EvidenceRecordingState::On, $records, null, $conversation);
    }

    public function searchPage(EvidenceFilter $filter, int $page, int $perPage): EvidencePage
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        [$invocationIds, $conversation, $posture] = $this->context($filter);

        if ($posture->state !== EvidenceRecordingState::On) {
            return new EvidencePage($posture->state, [], 0, $page, $perPage, $posture->recordedBy, $conversation);
        }

        if ($conversation === ConversationCorrelation::Unknown) {
            return new EvidencePage(EvidenceRecordingState::On, [], 0, $page, $perPage, null, $conversation);
        }

        $query = $this->filteredQuery($filter, $invocationIds, $conversation, $posture);
        $total = (clone $query)->count();
        $records = $this->records(
            $query->orderByDesc('recorded_at')->orderByDesc('id')->forPage($page, $perPage)->get(),
        );

        return new EvidencePage(EvidenceRecordingState::On, $records, $total, $page, $perPage, null, $conversation);
    }

    /** @return array{0: list<string>, 1: ?ConversationCorrelation, 2: SinkPosture} */
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

        return [$invocationIds, $conversation, $this->app->make(EvidenceSinkPosture::class)->read()];
    }

    /** @param list<string> $invocationIds */
    private function filteredQuery(EvidenceFilter $filter, array $invocationIds, ?ConversationCorrelation $conversation, SinkPosture $posture): Builder
    {
        if ($posture->table === null) {
            throw new \LogicException('A readable evidence posture must name a table.');
        }

        $query = $this->database
            ->connection($posture->connection)
            ->table($posture->table)
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

        if ($filter->actorFingerprint !== null) {
            $query->where('actor_fingerprint', $filter->actorFingerprint);
        }

        if ($filter->subjectFingerprint !== null) {
            $query->where('subject_fingerprint', $filter->subjectFingerprint);
        }

        if ($filter->argumentFingerprint !== null) {
            $query->where('argument_fingerprint', $filter->argumentFingerprint);
        }

        if ($filter->approvalReceiptFingerprint !== null) {
            $query->where('approval_receipt_fingerprint', $filter->approvalReceiptFingerprint);
        }

        if ($filter->configurationFingerprint !== null) {
            $query->where('configuration_fingerprint', $filter->configurationFingerprint);
        }

        if ($filter->executionClaimFingerprint !== null) {
            $query->where('execution_claim_fingerprint', $filter->executionClaimFingerprint);
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
