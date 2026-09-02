<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Integrity;

use Fissible\VerdictConsole\Contracts\EvidenceIntegrity;
use Fissible\VerdictConsole\Contracts\EvidenceSinkPosture;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Configuration-only integrity read. It deliberately cannot read chain gaps or verify a chain.
 * Selection and fixed-chain identity come from EvidenceSinkPosture, the existing writer-precedence
 * boundary, rather than a second interpretation of Verdict configuration.
 */
final readonly class NullEvidenceIntegrity implements EvidenceIntegrity
{
    public function __construct(
        private EvidenceSinkPosture $posture,
        private ChainVerificationStore $store,
        private Config $config,
    ) {}

    /** @return list<ChainIntegrityView> */
    public function chains(): array
    {
        $posture = $this->posture->read();
        if ($posture->state !== EvidenceRecordingState::Chained) {
            return [];
        }

        $chain = $posture->recordedBy;
        $hasChain = $posture->configuredChain !== null
            || ($posture->chainConfigured && $chain !== null && $posture->chainResolver === null);
        $hasResolver = $posture->chainResolver !== null;
        if ($hasChain && $hasResolver || ! $hasChain && ! $hasResolver) {
            return [$this->unnameable(UnnameableReason::InvalidTopology)];
        }

        if ($hasChain && $chain !== null) {
            return [$this->view($chain)];
        }

        $chains = $this->namedChains();
        if ($chains === []) {
            return [$this->unnameable(UnnameableReason::NoNamedChains)];
        }

        return array_map($this->view(...), $chains);
    }

    /** @return list<string> */
    private function namedChains(): array
    {
        $chains = $this->config->get('verdict-console.integrity.chains', []);

        if (! is_array($chains)) {
            return [];
        }

        return array_values(array_filter($chains, fn (mixed $chain): bool => is_string($chain) && $chain !== ''));
    }

    private function unnameable(UnnameableReason $reason): ChainIntegrityView
    {
        return new ChainIntegrityView('', ChainIntegrityState::Unnameable, $reason, null, null, null);
    }

    private function view(string $chainId): ChainIntegrityView
    {
        $latest = $this->store->latestFor($chainId);
        $completed = $latest?->lastCompleted;
        $attempt = $latest?->lastAttempt;
        $state = match ($completed?->outcome) {
            'verified' => ChainIntegrityState::Verified,
            'failed' => ChainIntegrityState::Failed,
            default => ChainIntegrityState::Unverified,
        };

        return new ChainIntegrityView($chainId, $state, null, $completed, $attempt, null);
    }
}
