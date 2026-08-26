<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Evidence;

use DateTimeImmutable;

/**
 * A display-safe projection of one Verdict decision record.
 *
 * Fingerprints are correlation values, not anonymization. Raw arguments and identifiers intentionally
 * do not cross this boundary. Reasons are excluded too: they are host-controlled free text, not a
 * Verdict display-safe vocabulary, and could carry the same application content. A host that has
 * governed that audience may replace EvidenceQuery with its own projection.
 */
final readonly class EvidenceRecord
{
    public function __construct(
        public string $id,
        public ?string $capability,
        public string $stage,
        public string $disposition,
        public ?string $claimType,
        public ?string $recordDigest,
        public ?string $argumentFingerprint,
        public ?string $idempotencyKeyFingerprint,
        public ?string $approvalReceiptFingerprint,
        public ?string $configurationFingerprint,
        public ?string $actorFingerprint,
        public ?string $subjectFingerprint,
        public ?string $proposalTargetIdentityFingerprint,
        public ?string $executionTargetIdentityFingerprint,
        public ?string $rateLimitKeyFingerprint,
        public ?string $executionClaimFingerprint,
        public ?string $executionClaimBindingFingerprint,
        public ?DateTimeImmutable $rateLimitResetAt,
        public DateTimeImmutable $recordedAt,
    ) {}
}
