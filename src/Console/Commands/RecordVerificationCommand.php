<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Console\Commands;

use Fissible\VerdictConsole\Integrity\ChainVerificationStore;
use Fissible\VerdictConsole\Integrity\RecordedVerification;
use Illuminate\Console\Command;

/** Records an operator-supplied dated claim; it does not run an attest verification. */
final class RecordVerificationCommand extends Command
{
    protected $signature = 'verdict-console:record-verification
        {chain : The named chain id}
        {outcome : verified, failed, or errored}
        {--from= : First sequence examined}
        {--to= : Last sequence requested}
        {--verified-through= : Last sequence actually verified}
        {--broken-at= : Sequence at which verification failed}
        {--attest-outcome= : Attest outcome word}
        {--policy-fingerprint= : Hash of verification inputs}
        {--component-version=* : Executed component version as key=value}
        {--output-digest= : SHA-256 of a run output artifact}
        {--error-class= : Exception class for an errored attempt}';

    protected $description = 'Record a dated chain-verification claim supplied by the executing operator.';

    public function handle(ChainVerificationStore $store): int
    {
        $outcome = $this->argument('outcome');
        if (! in_array($outcome, ['verified', 'failed', 'errored'], true)) {
            return $this->invalid('Outcome must be verified, failed, or errored.');
        }

        $from = $this->integerOption('from', true);
        $to = $this->integerOption('to');
        $verifiedThrough = $this->integerOption('verified-through');
        $brokenAt = $this->integerOption('broken-at');
        $fingerprint = $this->option('policy-fingerprint');
        $digest = $this->option('output-digest');
        $errorClass = $this->option('error-class');

        if (! is_int($from) || $to === false || $verifiedThrough === false || $brokenAt === false
            || ! is_string($fingerprint) || $fingerprint === '') {
            return $this->invalid('A non-negative --from and non-empty --policy-fingerprint are required; sequence options must be integers.');
        }

        if ($digest !== null && preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
            return $this->invalid('--output-digest must be a lowercase SHA-256 digest.');
        }

        if ($outcome !== 'errored' && $errorClass !== null) {
            return $this->invalid('--error-class is only valid for errored outcomes.');
        }

        if ($outcome === 'errored' && (! is_string($errorClass) || $errorClass === '' || preg_match('/^[^\s:]+$/', $errorClass) !== 1)) {
            return $this->invalid('--error-class is required for errored outcomes and must be a class-shaped value without spaces or colons.');
        }

        $versions = $this->versions();
        if ($versions === null) {
            return $this->invalid('Every --component-version must be written as key=value.');
        }

        $chain = $this->argument('chain');
        if ($chain === '') {
            return $this->invalid('Chain must be non-empty.');
        }

        $store->record($chain, new RecordedVerification(
            outcome: $outcome,
            ranAt: now()->toDateTimeImmutable(),
            ranBy: PHP_SAPI.':'.gethostname(),
            fromSeq: $from,
            toSeqRequested: $to,
            verifiedThroughSeq: $verifiedThrough,
            brokenAtSeq: $brokenAt,
            attestOutcome: $this->option('attest-outcome'),
            policyFingerprint: $fingerprint,
            source: 'recorded',
            outputDigest: $digest,
            errorClass: $errorClass,
            verifierVersions: $versions,
        ));

        return self::SUCCESS;
    }

    private function integerOption(string $name, bool $required = false): int|false|null
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return $required ? false : null;
        }

        if (is_int($value) && $value >= 0) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d+$/', $value) === 1 ? (int) $value : false;
    }

    /** @return array<string, string>|null */
    private function versions(): ?array
    {
        $versions = [];
        foreach ($this->option('component-version') as $component) {
            if (! is_string($component) || ! str_contains($component, '=')) {
                return null;
            }
            [$key, $version] = explode('=', $component, 2);
            if ($key === '' || $version === '') {
                return null;
            }
            $versions[$key] = $version;
        }

        return $versions;
    }

    private function invalid(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
