<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Incidents\Incident;
use Fissible\VerdictConsole\Incidents\IncidentStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The anomaly-incident list over the VC-15 ledger: Verdict's ephemeral events made durable, drawn
 * newest first. Read-only; recording continues to happen in the listeners, never here.
 */
beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_incidents_table.php.stub')->up();
});

afterEach(function (): void {
    Schema::dropIfExists('verdict_console_incidents');
    Schema::dropIfExists('verdict_console_pending_approvals');
});

function opsIncident(string $source, string $cause, string $observedAt, array $context = []): Incident
{
    $incident = app(IncidentStore::class)->record($source, $cause, $context);
    $incident->forceFill(['observed_at' => $observedAt])->save();

    return $incident->fresh() ?? $incident;
}

it('lists incidents newest first with source, cause, and when they were observed', function (): void {
    $older = opsIncident('evidence_write_failed', 'Write failed.', '2026-08-30 09:00:00', ['capability' => 'orders.refund']);
    $newer = opsIncident('chain_write_failed', 'Append failed.', '2026-08-30 10:00:00');

    // Rendering is a read: not one insert, update, or delete may run while the page draws.
    $statements = [];
    DB::listen(function (object $query) use (&$statements): void {
        $statements[] = (string) $query->sql;
    });

    $html = (string) $this->blade('<x-verdict-console::incidents />');

    expect(array_filter($statements, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete)/i', $sql) === 1))
        ->toBe([], 'The list must not write while rendering.');

    preg_match_all('/<tr\b[^>]*\bdata-incident="([^"]+)"[^>]*\bdata-source="([^"]+)"/', $html, $m, PREG_SET_ORDER);

    expect(array_map(fn (array $row): array => [$row[1], $row[2]], $m))->toBe([
        [$newer->id, 'chain_write_failed'],
        [$older->id, 'evidence_write_failed'],
    ])
        ->and($html)->toContain('data-field="cause"')
        ->and($html)->toContain('Append failed.')
        ->and($html)->toContain('datetime="2026-08-30T10:00:00+00:00"')
        ->and($html)->not->toContain('<form');
});

/** The ledger's whole point (§6.7): an install with no incidents is a healthy statement, not a blank. */
it('says no incidents have been recorded when the ledger is empty', function (): void {
    $html = (string) $this->blade('<x-verdict-console::incidents />');

    expect($html)->toContain('data-verdict-console="incidents"')
        ->and($html)->toContain('data-empty')
        ->and($html)->toContain('No incidents recorded.')
        ->and($html)->not->toContain('<table');
});

it('honours a limit, newest first, and defaults to more than a handful', function (): void {
    foreach (range(1, 6) as $i) {
        opsIncident('evidence_write_failed', 'Failure '.$i, sprintf('2026-08-30 09:%02d:00', $i));
    }

    $limited = (string) $this->blade('<x-verdict-console::incidents :limit="2" />');

    preg_match_all('/data-incident="([^"]+)"/', $limited, $m);

    expect($m[1])->toHaveCount(2)
        ->and($limited)->toContain('Failure 6')
        ->and($limited)->toContain('Failure 5')
        ->and($limited)->not->toContain('Failure 4');

    $defaulted = (string) $this->blade('<x-verdict-console::incidents />');

    preg_match_all('/data-incident="([^"]+)"/', $defaulted, $d);

    expect($d[1])->toHaveCount(6, 'The default limit shows an ordinary ledger whole.');
});

/** Causes are host-adjacent free text; drawn as text, never markup. */
it('escapes what it renders', function (): void {
    opsIncident('approval_ingestion', 'cause <b>bold</b> & "quoted"', '2026-08-30 09:00:00', ['tool_call_id' => 'call<i>x</i>']);

    $html = (string) $this->blade('<x-verdict-console::incidents />');

    expect($html)->toContain('cause &lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot;')
        ->and($html)->not->toContain('<b>bold</b>')
        ->and($html)->not->toContain('<i>x</i>');
});
