<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Integrity;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/** Stores the current standing claim and newest attempt, not a verification-run history. */
final readonly class ChainVerificationStore
{
    private const string TABLE = 'verdict_console_chain_verifications';

    public function __construct(private ConnectionInterface $database) {}

    public function record(string $chainId, RecordedVerification $verification): void
    {
        $attempt = $this->columns('last_attempt', $verification);
        $completed = in_array($verification->outcome, ['verified', 'failed'], true)
            ? $this->columns('last_completed', $verification)
            : [];

        $existing = $this->database->table(self::TABLE)->where('chain_id', $chainId)->first();
        $values = [...$attempt, ...$completed, 'updated_at' => now()];

        if ($existing === null) {
            $this->database->table(self::TABLE)->insert([
                'chain_id' => $chainId,
                ...$values,
                'created_at' => now(),
            ]);

            return;
        }

        $this->database->table(self::TABLE)->where('chain_id', $chainId)->update($values);
    }

    public function latestFor(string $chainId): ?ChainVerificationRecord
    {
        $row = $this->database->table(self::TABLE)->where('chain_id', $chainId)->first();

        if ($row === null) {
            return null;
        }

        return new ChainVerificationRecord(
            lastCompleted: $row->last_completed_outcome === null ? null : $this->verification($row, 'last_completed'),
            lastAttempt: $row->last_attempt_outcome === null ? null : $this->verification($row, 'last_attempt'),
        );
    }

    /** @return array<string, DateTimeImmutable|int|string|array<string, string>|null> */
    private function columns(string $prefix, RecordedVerification $verification): array
    {
        return [
            $prefix.'_outcome' => $verification->outcome,
            $prefix.'_ran_at' => $verification->ranAt->format('Y-m-d H:i:s'),
            $prefix.'_ran_by' => $verification->ranBy,
            $prefix.'_from_seq' => $verification->fromSeq,
            $prefix.'_to_seq_requested' => $verification->toSeqRequested,
            $prefix.'_verified_through_seq' => $verification->verifiedThroughSeq,
            $prefix.'_broken_at_seq' => $verification->brokenAtSeq,
            $prefix.'_attest_outcome' => $verification->attestOutcome,
            $prefix.'_policy_fingerprint' => $verification->policyFingerprint,
            $prefix.'_source' => $verification->source,
            $prefix.'_output_digest' => $verification->outputDigest,
            $prefix.'_error_class' => $verification->errorClass,
            $prefix.'_verifier_versions' => json_encode($verification->verifierVersions, JSON_THROW_ON_ERROR),
        ];
    }

    private function verification(object $row, string $prefix): RecordedVerification
    {
        /** @var array<string, string> $versions */
        $versions = json_decode($row->{$prefix.'_verifier_versions'}, true, 512, JSON_THROW_ON_ERROR);

        return new RecordedVerification(
            outcome: $row->{$prefix.'_outcome'},
            ranAt: new DateTimeImmutable($row->{$prefix.'_ran_at'}),
            ranBy: $row->{$prefix.'_ran_by'},
            fromSeq: (int) $row->{$prefix.'_from_seq'},
            toSeqRequested: $row->{$prefix.'_to_seq_requested'} === null ? null : (int) $row->{$prefix.'_to_seq_requested'},
            verifiedThroughSeq: $row->{$prefix.'_verified_through_seq'} === null ? null : (int) $row->{$prefix.'_verified_through_seq'},
            brokenAtSeq: $row->{$prefix.'_broken_at_seq'} === null ? null : (int) $row->{$prefix.'_broken_at_seq'},
            attestOutcome: $row->{$prefix.'_attest_outcome'},
            policyFingerprint: $row->{$prefix.'_policy_fingerprint'},
            source: $row->{$prefix.'_source'},
            outputDigest: $row->{$prefix.'_output_digest'},
            errorClass: $row->{$prefix.'_error_class'},
            verifierVersions: $versions,
        );
    }
}
