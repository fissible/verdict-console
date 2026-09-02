<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Integrity\ChainVerificationStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Exception\InvalidOptionException;

/**
 * ADR 0002 §4/§7: the recording command is a claim intake, not a verifier. Its instant and actor
 * derive from execution — never from inputs — every record is source 'recorded', and the optional
 * output digest is an audit breadcrumb with no effect on anything rendered.
 */
beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_chain_verifications_table.php.stub')->up();
    Carbon::setTestNow(Carbon::parse('2026-09-02 09:30:00', 'UTC'));
});

afterEach(function (): void {
    Carbon::setTestNow();
    Schema::dropIfExists('verdict_console_chain_verifications');
});

it('records a verified claim with execution-derived instant and actor, as a recorded source', function (): void {
    $this->artisan('verdict-console:record-verification', [
        'chain' => 'orders-chain',
        'outcome' => 'verified',
        '--from' => 1,
        '--verified-through' => 240,
        '--attest-outcome' => 'ok',
        '--policy-fingerprint' => hash('sha256', 'policy'),
        '--component-version' => ['attest-laravel=1.1.0', 'attest=1.3.0'],
    ])->assertSuccessful();

    $record = app(ChainVerificationStore::class)->latestFor('orders-chain');

    expect($record->lastCompleted?->outcome)->toBe('verified')
        ->and($record->lastCompleted?->verifiedThroughSeq)->toBe(240)
        // Derived at execution: the clock, not an input (ADR 0002 §4).
        ->and($record->lastCompleted?->ranAt->format(DATE_ATOM))->toBe('2026-09-02T09:30:00+00:00')
        ->and($record->lastCompleted?->ranBy)->not->toBe('')
        ->and($record->lastCompleted?->source)->toBe('recorded')
        ->and($record->lastCompleted?->verifierVersions)->toBe(['attest-laravel' => '1.1.0', 'attest' => '1.3.0']);
});

it('refuses caller-supplied instants and actors by not defining such inputs at all', function (): void {
    // The trust contract is structural: there is nothing to pass. An unknown option is an error.
    expect(fn () => $this->artisan('verdict-console:record-verification', [
        'chain' => 'orders-chain',
        'outcome' => 'verified',
        '--ran-at' => '2020-01-01T00:00:00+00:00',
    ]))->toThrow(InvalidOptionException::class);

    expect(DB::table('verdict_console_chain_verifications')->count())->toBe(0);
});

it('records an errored attempt without touching a standing completed claim', function (): void {
    $this->artisan('verdict-console:record-verification', [
        'chain' => 'orders-chain',
        'outcome' => 'verified',
        '--from' => 1,
        '--verified-through' => 240,
        '--attest-outcome' => 'ok',
        '--policy-fingerprint' => hash('sha256', 'policy'),
    ])->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-09-02 10:00:00', 'UTC'));

    $this->artisan('verdict-console:record-verification', [
        'chain' => 'orders-chain',
        'outcome' => 'errored',
        '--from' => 1,
        '--policy-fingerprint' => hash('sha256', 'policy'),
        '--error-class' => 'RuntimeException',
    ])->assertSuccessful();

    $record = app(ChainVerificationStore::class)->latestFor('orders-chain');

    expect($record->lastCompleted?->outcome)->toBe('verified')
        ->and($record->lastCompleted?->ranAt->format(DATE_ATOM))->toBe('2026-09-02T09:30:00+00:00')
        ->and($record->lastAttempt?->outcome)->toBe('errored')
        ->and($record->lastAttempt?->errorClass)->toBe('RuntimeException')
        ->and($record->lastAttempt?->ranAt->format(DATE_ATOM))->toBe('2026-09-02T10:00:00+00:00')
        // One row per chain through the command path too — never a second row.
        ->and(DB::table('verdict_console_chain_verifications')->count())->toBe(1);
});

it('refuses a digest that is not sha256-shaped', function (string $digest): void {
    $this->artisan('verdict-console:record-verification', [
        'chain' => 'orders-chain',
        'outcome' => 'verified',
        '--from' => 1,
        '--verified-through' => 10,
        '--attest-outcome' => 'ok',
        '--policy-fingerprint' => hash('sha256', 'policy'),
        '--output-digest' => $digest,
    ])->assertFailed();

    expect(DB::table('verdict_console_chain_verifications')->count())->toBe(0);
})->with([
    'wrong length' => ['not-a-digest'],
    // 64 characters of non-hex: a length-only validator passes this and fails here.
    'right length, not hex' => [str_repeat('g', 64)],
]);

it('confines the error class to errored outcomes and to class-shaped values', function (array $arguments): void {
    $this->artisan('verdict-console:record-verification', array_merge([
        'chain' => 'orders-chain',
        '--from' => 1,
        '--policy-fingerprint' => hash('sha256', 'policy'),
    ], $arguments))->assertFailed();

    expect(DB::table('verdict_console_chain_verifications')->count())->toBe(0);
})->with([
    // errorClass is the errored outcome's datum only (ADR 0002 §4).
    'error class on a verified outcome' => [[
        'outcome' => 'verified', '--verified-through' => 10, '--attest-outcome' => 'ok',
        '--error-class' => 'RuntimeException',
    ]],
    // A class, never a message: message-shaped values commonly carry paths and configuration.
    'message-shaped value' => [[
        'outcome' => 'errored',
        '--error-class' => 'RuntimeException: something broke at /app/Handler.php',
    ]],
]);

it('persists an output digest verbatim as an audit breadcrumb', function (): void {
    $digest = hash('sha256', 'the run output');

    $this->artisan('verdict-console:record-verification', [
        'chain' => 'orders-chain',
        'outcome' => 'verified',
        '--from' => 1,
        '--verified-through' => 10,
        '--attest-outcome' => 'ok',
        '--policy-fingerprint' => hash('sha256', 'policy'),
        '--output-digest' => $digest,
    ])->assertSuccessful();

    expect(app(ChainVerificationStore::class)->latestFor('orders-chain')->lastCompleted?->outputDigest)->toBe($digest);
});

it('refuses an outcome outside the vocabulary', function (): void {
    $this->artisan('verdict-console:record-verification', [
        'chain' => 'orders-chain',
        'outcome' => 'tampered',
        '--policy-fingerprint' => hash('sha256', 'policy'),
    ])->assertFailed();

    expect(DB::table('verdict_console_chain_verifications')->count())->toBe(0);
});
