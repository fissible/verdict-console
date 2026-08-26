<?php

declare(strict_types=1);

use Fissible\Verdict\Evidence\AttestEvidenceRecorder;
use Fissible\Verdict\Evidence\DatabaseEvidenceRecorder;
use Fissible\Verdict\Evidence\NullEvidenceRecorder;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Evidence\DatabaseEvidenceQuery;
use Fissible\VerdictConsole\Evidence\EvidenceFilter;
use Fissible\VerdictConsole\Evidence\EvidenceQueryResult;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config()->set('verdict.evidence.table', 'console_test_evidence');
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    $migrations = dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations';

    foreach ([
        'create_verdict_evidence_table.php.stub',
        'add_provenance_to_verdict_evidence_table.php.stub',
        'add_invocation_id_to_verdict_evidence_table.php.stub',
        'add_tool_kind_to_verdict_evidence_table.php.stub',
        'add_configuration_fingerprint_to_verdict_evidence_table.php.stub',
        'add_actor_and_subject_fingerprints_to_verdict_evidence_table.php.stub',
        'add_target_source_to_verdict_evidence_table.php.stub',
        'add_tool_description_fingerprints_to_verdict_evidence_table.php.stub',
        'add_record_identity_to_verdict_evidence_table.php.stub',
    ] as $migration) {
        (require $migrations.'/'.$migration)->up();
    }
});

afterEach(function (): void {
    Schema::dropIfExists('console_test_evidence');
});

it('exposes recording being off separately from an empty enabled evidence table', function (): void {
    $off = app(EvidenceQuery::class)->search(new EvidenceFilter);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $empty = app(EvidenceQuery::class)->search(new EvidenceFilter);

    expect($off->recording)->toBe(EvidenceRecordingState::Off)
        ->and($off->records)->toBe([])
        ->and($empty->recording)->toBe(EvidenceRecordingState::On)
        ->and($empty->records)->toBe([]);
});

it('uses Verdicts narrow writer before the legacy recorder and distinguishes an unreadable writer', function (): void {
    config()->set('verdict.evidence.writer', DatabaseEvidenceRecorder::class);
    config()->set('verdict.evidence.recorder', NullEvidenceRecorder::class);

    $tableWriter = app(EvidenceQuery::class)->search(new EvidenceFilter);

    config()->set('verdict.evidence.writer', 'App\\Evidence\\ExternalWriter');
    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $elsewhere = app(EvidenceQuery::class)->search(new EvidenceFilter);

    config()->set('verdict.evidence.writer', null);
    config()->set('verdict.evidence.recorder', AttestEvidenceRecorder::class);

    $attestWriter = app(EvidenceQuery::class)->search(new EvidenceFilter);

    expect($tableWriter->recording)->toBe(EvidenceRecordingState::On)
        ->and($tableWriter->recordedBy)->toBeNull()
        ->and($elsewhere->recording)->toBe(EvidenceRecordingState::Elsewhere)
        ->and($elsewhere->recordedBy)->toBe('App\\Evidence\\ExternalWriter')
        ->and($elsewhere->records)->toBe([])
        ->and($attestWriter->recording)->toBe(EvidenceRecordingState::On);
});

it('treats Verdicts absent recorder configuration as recording off', function (): void {
    $evidence = config('verdict.evidence');
    unset($evidence['recorder']);
    config()->set('verdict.evidence', $evidence);

    $result = app(EvidenceQuery::class)->search(new EvidenceFilter);

    expect($result->recording)->toBe(EvidenceRecordingState::Off)
        ->and($result->records)->toBe([]);
});

it('reads only decision evidence from Verdicts shipped schema without exposing raw values', function (): void {
    insertEvidence([
        'id' => 'decision-1',
        'record_type' => 'decision',
        'capability' => 'orders.refund',
        'stage' => 'proposal',
        'disposition' => 'throttle',
        'claim_type' => 'rate_limit.refused',
        'record_digest' => 'canonicaljson-sha256:decision-1',
        'reason' => 'The customer email and refund amount must not reach this surface.',
        'argument_fingerprint' => str_repeat('a', 64),
        'idempotency_key_fingerprint' => str_repeat('b', 64),
        'approval_receipt_fingerprint' => str_repeat('c', 64),
        'configuration_fingerprint' => str_repeat('d', 64),
        'actor_fingerprint' => str_repeat('e', 64),
        'subject_fingerprint' => str_repeat('f', 64),
        'proposal_target_identity_fingerprint' => str_repeat('1', 64),
        'execution_target_identity_fingerprint' => str_repeat('2', 64),
        'rate_limit_key_fingerprint' => str_repeat('3', 64),
        'execution_claim_fingerprint' => str_repeat('4', 64),
        'execution_claim_binding_fingerprint' => str_repeat('5', 64),
        'rate_limit_reset_at' => '2026-08-25 12:30:00',
        'recorded_at' => '2026-08-25 12:00:00',
    ]);
    insertEvidence([
        'id' => 'provenance-1',
        'record_type' => 'provenance',
        'stage' => 'input',
        'disposition' => 'recorded',
        'recorded_at' => '2026-08-25 12:01:00',
    ]);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $result = app(EvidenceQuery::class)->search(new EvidenceFilter);

    expect($result->recording)->toBe(EvidenceRecordingState::On)
        ->and($result->records)->toHaveCount(1)
        ->and($result->records[0])->toMatchArray([
            'id' => 'decision-1',
            'capability' => 'orders.refund',
            'stage' => 'proposal',
            'disposition' => 'throttle',
            'claimType' => 'rate_limit.refused',
            'recordDigest' => 'canonicaljson-sha256:decision-1',
            'argumentFingerprint' => str_repeat('a', 64),
            'idempotencyKeyFingerprint' => str_repeat('b', 64),
            'approvalReceiptFingerprint' => str_repeat('c', 64),
            'configurationFingerprint' => str_repeat('d', 64),
            'actorFingerprint' => str_repeat('e', 64),
            'subjectFingerprint' => str_repeat('f', 64),
            'proposalTargetIdentityFingerprint' => str_repeat('1', 64),
            'executionTargetIdentityFingerprint' => str_repeat('2', 64),
            'rateLimitKeyFingerprint' => str_repeat('3', 64),
            'executionClaimFingerprint' => str_repeat('4', 64),
            'executionClaimBindingFingerprint' => str_repeat('5', 64),
            'rateLimitResetAt' => new DateTimeImmutable('2026-08-25 12:30:00 UTC'),
            'recordedAt' => new DateTimeImmutable('2026-08-25 12:00:00 UTC'),
        ])
        ->and(property_exists($result->records[0], 'reason'))->toBeFalse();
});

it('filters decision evidence by disposition, capability, and inclusive recorded-at bounds', function (): void {
    insertEvidence(['id' => 'permit', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'permit', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'early-deny', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'recorded_at' => '2026-08-25 10:00:00']);
    insertEvidence(['id' => 'first-deny', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'recorded_at' => '2026-08-25 11:00:00']);
    insertEvidence(['id' => 'second-deny', 'record_type' => 'decision', 'capability' => 'billing.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'recorded_at' => '2026-08-25 12:00:00']);
    insertEvidence(['id' => 'late-deny', 'record_type' => 'decision', 'capability' => 'orders.refund', 'stage' => 'proposal', 'disposition' => 'deny', 'recorded_at' => '2026-08-25 13:00:00']);

    config()->set('verdict.evidence.recorder', DatabaseEvidenceRecorder::class);

    $records = app(EvidenceQuery::class)->search(new EvidenceFilter(
        disposition: 'deny',
        capability: 'orders.refund',
        recordedFrom: new DateTimeImmutable('2026-08-25 06:00:00-05:00'),
        recordedUntil: new DateTimeImmutable('2026-08-25 07:00:00-05:00'),
    ))->records;

    expect(array_map(fn ($record) => $record->id, $records))->toBe(['first-deny']);
});

it('keeps the read boundary independent of Verdicts writer and recorder implementations', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/src/Evidence/DatabaseEvidenceQuery.php');

    expect($source)->not->toContain('Fissible\\Verdict\\Contracts\\EvidenceWriter')
        ->and($source)->not->toContain('Fissible\\Verdict\\Contracts\\EvidenceRecorder')
        ->and($source)->not->toContain('Fissible\\Verdict\\Evidence\\DatabaseEvidenceRecorder')
        ->and($source)->not->toContain('Fissible\\Verdict\\Evidence\\DecisionEvidence');
});

it('binds the shipped table adapter to a contract a host may replace', function (): void {
    expect(app(EvidenceQuery::class))->toBeInstanceOf(DatabaseEvidenceQuery::class);

    $replacement = new class implements EvidenceQuery
    {
        public function search(EvidenceFilter $filter): EvidenceQueryResult
        {
            return new EvidenceQueryResult(EvidenceRecordingState::On, []);
        }
    };

    app()->instance(EvidenceQuery::class, $replacement);

    expect(app(EvidenceQuery::class))->toBe($replacement);
});

/** @param array<string, mixed> $attributes */
function insertEvidence(array $attributes): void
{
    DB::table('console_test_evidence')->insert([
        'id' => $attributes['id'],
        'record_type' => $attributes['record_type'],
        'capability' => $attributes['capability'] ?? null,
        'stage' => $attributes['stage'],
        'disposition' => $attributes['disposition'],
        'reason' => $attributes['reason'] ?? null,
        'argument_fingerprint' => $attributes['argument_fingerprint'] ?? null,
        'idempotency_key_fingerprint' => $attributes['idempotency_key_fingerprint'] ?? null,
        'approval_receipt_fingerprint' => $attributes['approval_receipt_fingerprint'] ?? null,
        'proposal_target_identity_fingerprint' => $attributes['proposal_target_identity_fingerprint'] ?? null,
        'execution_target_identity_fingerprint' => $attributes['execution_target_identity_fingerprint'] ?? null,
        'rate_limit_key_fingerprint' => $attributes['rate_limit_key_fingerprint'] ?? null,
        'execution_claim_fingerprint' => $attributes['execution_claim_fingerprint'] ?? null,
        'execution_claim_binding_fingerprint' => $attributes['execution_claim_binding_fingerprint'] ?? null,
        'configuration_fingerprint' => $attributes['configuration_fingerprint'] ?? null,
        'actor_fingerprint' => $attributes['actor_fingerprint'] ?? null,
        'subject_fingerprint' => $attributes['subject_fingerprint'] ?? null,
        'claim_type' => $attributes['claim_type'] ?? null,
        'record_digest' => $attributes['record_digest'] ?? null,
        'rate_limit_reset_at' => $attributes['rate_limit_reset_at'] ?? null,
        'recorded_at' => $attributes['recorded_at'],
    ]);
}
