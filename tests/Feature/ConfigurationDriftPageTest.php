<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Configuration\ApprovalRules;
use Fissible\VerdictConsole\Configuration\CapabilityInspection;
use Fissible\VerdictConsole\Contracts\ConfigurationDriftQuery;
use Fissible\VerdictConsole\Contracts\ConfigurationInspection;
use Fissible\VerdictConsole\Evidence\ConfigurationDriftResult;
use Fissible\VerdictConsole\Evidence\EvidenceRecordingState;
use Fissible\VerdictConsole\Evidence\ObservedConfiguration;

/**
 * #106's rendering: `<x-verdict-console::configuration-drift />` lists observed fingerprints per
 * capability, marks the one matching the capability's CURRENT declared fingerprint — from the
 * ConfigurationInspection boundary, never from config — and states plainly that this is observed
 * history, not a write log. The page is a pure function of its two contracts; no database exists
 * in this file.
 */
function driftObservation(string $capability, string $fingerprint, string $first, string $last, int $count): ObservedConfiguration
{
    return new ObservedConfiguration(
        capability: $capability,
        configurationFingerprint: $fingerprint,
        firstObservedAt: new DateTimeImmutable($first),
        lastObservedAt: new DateTimeImmutable($last),
        decisionCount: $count,
    );
}

/** @param list<ObservedConfiguration> $observations */
function bindDriftResult(EvidenceRecordingState $recording, array $observations = [], ?string $recordedBy = null): void
{
    $result = new ConfigurationDriftResult(recording: $recording, observations: $observations, recordedBy: $recordedBy);

    app()->instance(ConfigurationDriftQuery::class, new class($result) implements ConfigurationDriftQuery
    {
        public function __construct(private readonly ConfigurationDriftResult $result) {}

        public function observed(): ConfigurationDriftResult
        {
            return $this->result;
        }
    });
}

/** @param array<string, string> $declared capability name => current declared fingerprint */
function bindDriftInspection(array $declared): void
{
    $capabilities = [];

    foreach ($declared as $name => $fingerprint) {
        $capabilities[] = new CapabilityInspection(
            name: $name,
            ability: 'demo.'.$name,
            configurationFingerprint: $fingerprint,
            configurationVersion: null,
            confirmationRequired: false,
            confirmationReason: null,
            confirmationTtlSeconds: null,
            executionTargetPolicy: null,
            executionTargetStrategy: null,
            rateLimit: null,
            executionClaimPolicy: null,
            requiresIntentRecord: null,
            consequential: false,
        );
    }

    app()->instance(ConfigurationInspection::class, new class($capabilities) implements ConfigurationInspection
    {
        /** @param list<CapabilityInspection> $capabilities */
        public function __construct(private readonly array $capabilities) {}

        public function capabilities(): array
        {
            return $this->capabilities;
        }

        public function rateLimits(): array
        {
            return [];
        }

        public function approvalRules(): ApprovalRules
        {
            return new ApprovalRules(ttlSeconds: null, authorizer: null, strictProvenance: false, gateAbility: 'operate-verdict-console');
        }
    });
}

function renderDrift(): string
{
    return (string) test()->blade('<x-verdict-console::configuration-drift />');
}

/**
 * @return list<array{capability: string, fingerprint: string, current: bool, fields: array<string, string>}>
 */
function driftRows(string $html): array
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    $rows = [];

    foreach ((new DOMXPath($document))->query('//*[@data-observation]') ?: [] as $node) {
        if (! $node instanceof DOMElement) {
            continue;
        }

        $fields = [];

        foreach ((new DOMXPath($document))->query('.//*[@data-field]', $node) ?: [] as $field) {
            if ($field instanceof DOMElement) {
                $fields[$field->getAttribute('data-field')] = trim((string) preg_replace('/\s+/', ' ', $field->textContent));
            }
        }

        $rows[] = [
            'capability' => $node->getAttribute('data-capability'),
            'fingerprint' => $node->getAttribute('data-fingerprint'),
            'current' => $node->getAttribute('data-current') === 'true',
            'fields' => $fields,
        ];
    }

    return $rows;
}

beforeEach(function (): void {
    bindDriftInspection([]);
    bindDriftResult(EvidenceRecordingState::On);
});

/**
 * The rows are the drift boundary's observations in its order, each carrying exact bounds and
 * count — and the current marker sits ONLY on the row whose fingerprint matches ITS capability's
 * declared fingerprint. billing's current fingerprint is also observed under orders, where it must
 * not be marked: capabilities never share a marker.
 */
it('lists observations with exact fields and marks the current fingerprint per capability', function (): void {
    bindDriftInspection([
        'billing.refund' => 'sha256:fp-two',
        'orders.cancel' => 'sha256:fp-nine',
    ]);
    bindDriftResult(EvidenceRecordingState::On, [
        driftObservation('billing.refund', 'sha256:fp-two', '2026-09-01 10:20:00+00:00', '2026-09-01 10:20:00+00:00', 1),
        driftObservation('billing.refund', 'sha256:fp-one', '2026-09-01 10:00:00+00:00', '2026-09-01 10:10:00+00:00', 3),
        driftObservation('orders.cancel', 'sha256:fp-two', '2026-09-01 10:01:00+00:00', '2026-09-01 10:02:00+00:00', 2),
    ]);

    $rows = driftRows(renderDrift());

    // Every row's own fields, not a sampled one: a loop that drops or mislabels a bound or count
    // on any row fails here.
    expect(array_map(fn (array $row): array => [
        $row['capability'],
        $row['fingerprint'],
        $row['current'],
        $row['fields']['first_observed'] ?? null,
        $row['fields']['last_observed'] ?? null,
        $row['fields']['decisions'] ?? null,
    ], $rows))->toBe([
        ['billing.refund', 'sha256:fp-two', true, '2026-09-01T10:20:00+00:00', '2026-09-01T10:20:00+00:00', '1'],
        ['billing.refund', 'sha256:fp-one', false, '2026-09-01T10:00:00+00:00', '2026-09-01T10:10:00+00:00', '3'],
        ['orders.cancel', 'sha256:fp-two', false, '2026-09-01T10:01:00+00:00', '2026-09-01T10:02:00+00:00', '2'],
    ]);
});

/**
 * The marker comes from the inspection BOUNDARY: these declared fingerprints are arbitrary strings
 * no config file produced, and a declared capability the trail never observed adds no row of its
 * own — the view lists observations, not declarations.
 */
it('marks nothing when the inspection declares no matching fingerprint', function (): void {
    bindDriftInspection([
        'billing.refund' => 'sha256:never-observed',
        'ghost.capability' => 'sha256:also-never-observed',
    ]);
    bindDriftResult(EvidenceRecordingState::On, [
        driftObservation('billing.refund', 'sha256:fp-old', '2026-09-01 10:00:00+00:00', '2026-09-01 10:00:00+00:00', 1),
        // A capability the inspection no longer declares — removed or renamed since the trail was
        // written. Its history still renders, unmarked, even though its fingerprint IS the one
        // billing currently declares: observations are never filtered to current declarations.
        driftObservation('retired.capability', 'sha256:never-observed', '2026-09-01 09:00:00+00:00', '2026-09-01 09:30:00+00:00', 4),
    ]);

    $rows = driftRows(renderDrift());

    expect(array_map(fn (array $row): array => [$row['capability'], $row['current']], $rows))->toBe([
        ['billing.refund', false],
        ['retired.capability', false],
    ]);

    expect(renderDrift())->not->toContain('ghost.capability');
});

/** The observed-history stance is stated on the page, empty or not, in the issue's own words. */
it('states that this is observed history, not a write log, and renders an empty trail honestly', function (): void {
    $empty = renderDrift();

    expect($empty)->toContain('Observed history, not a write log: a configuration change that never decided anything leaves no row here.')
        ->and($empty)->toContain('No configuration observations have been recorded.')
        ->and($empty)->not->toContain('data-observation')
        ->and($empty)->not->toContain('recording is off');

    bindDriftResult(EvidenceRecordingState::On, [
        driftObservation('billing.refund', 'sha256:fp-one', '2026-09-01 10:00:00+00:00', '2026-09-01 10:00:00+00:00', 1),
    ]);

    expect(renderDrift())->toContain('Observed history, not a write log: a configuration change that never decided anything leaves no row here.')
        ->and(renderDrift())->not->toContain('No configuration observations have been recorded.');
});

/** The recording states are the boundary's answer, rendered in the console's standard words. */
it('renders the boundarys recording state instead of observations', function (): void {
    bindDriftResult(EvidenceRecordingState::Off);

    $off = renderDrift();

    bindDriftResult(EvidenceRecordingState::Elsewhere, recordedBy: 'App\\Evidence\\ExternalWriter');

    $elsewhere = renderDrift();

    bindDriftResult(EvidenceRecordingState::Chained, recordedBy: 'main-ledger');

    $chained = renderDrift();

    bindDriftResult(EvidenceRecordingState::Chained);

    $chainedUnnamed = renderDrift();

    expect($off)->toContain('data-recording="off"')
        ->and($off)->toContain('recording is off — blank by config.')
        ->and($off)->not->toContain('No configuration observations')
        ->and($elsewhere)->toContain('data-recording="elsewhere"')
        ->and($elsewhere)->toContain('Evidence is recorded elsewhere by App\Evidence\ExternalWriter.')
        ->and($chained)->toContain('data-recording="chained"')
        ->and($chained)->toContain('A chained sink (main-ledger) is configured; decisions are not readable from this table.')
        ->and($chainedUnnamed)->toContain('A chained sink is configured; decisions are not readable from this table.');

    // The observed-history qualification is the page's nature, not a branch of the On state.
    foreach ([$off, $elsewhere, $chained, $chainedUnnamed] as $html) {
        expect($html)->toContain('Observed history, not a write log: a configuration change that never decided anything leaves no row here.');
    }
});

/** Everything rendered is escaped: a hostile fingerprint or capability never becomes markup. */
it('escapes every value it renders', function (): void {
    bindDriftInspection(['<script>alert(1)</script>' => '<b>fp</b>']);
    bindDriftResult(EvidenceRecordingState::On, [
        driftObservation('<script>alert(1)</script>', '<b>fp</b>', '2026-09-01 10:00:00+00:00', '2026-09-01 10:00:00+00:00', 1),
    ]);

    $html = renderDrift();

    // Escaped, not dropped: the literal values survive as text while never becoming markup.
    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<b>fp</b>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->toContain('&lt;b&gt;fp&lt;/b&gt;');

    // And the recording states' writer/chain identity is external input too: escaped, still shown.
    bindDriftResult(EvidenceRecordingState::Elsewhere, recordedBy: '<img src=x onerror=alert(1)>');

    $elsewhere = renderDrift();

    bindDriftResult(EvidenceRecordingState::Chained, recordedBy: '<i>ledger</i>');

    $chained = renderDrift();

    expect($elsewhere)->not->toContain('<img src=x onerror=alert(1)>')
        ->and($elsewhere)->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->and($chained)->not->toContain('<i>ledger</i>')
        ->and($chained)->toContain('&lt;i&gt;ledger&lt;/i&gt;');

    // Attribute context too: a double quote in a value must not open a second attribute. The DOM
    // must hold the literal value on the row's own attribute, and no injected attribute may exist
    // anywhere.
    bindDriftResult(EvidenceRecordingState::On, [
        driftObservation('cap" data-injected="yes', 'sha256:fp" onmouseover="alert(1)', '2026-09-01 10:00:00+00:00', '2026-09-01 10:00:00+00:00', 1),
    ]);

    $quoted = renderDrift();
    $rows = driftRows($quoted);

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$quoted);
    libxml_clear_errors();

    expect($rows[0]['capability'])->toBe('cap" data-injected="yes')
        ->and($rows[0]['fingerprint'])->toBe('sha256:fp" onmouseover="alert(1)')
        ->and((new DOMXPath($document))->query('//*[@data-injected]')->length)->toBe(0)
        ->and((new DOMXPath($document))->query('//*[@onmouseover]')->length)->toBe(0);
});

/**
 * One render is one read: the recording state and the rows come from the SAME answer. A component
 * calling observed() twice would render this fake's contradictory second answer somewhere — or
 * fail the call count outright.
 */
it('reads the drift boundary exactly once per render', function (): void {
    $query = new class implements ConfigurationDriftQuery
    {
        public int $calls = 0;

        public function observed(): ConfigurationDriftResult
        {
            $this->calls++;

            if ($this->calls > 1) {
                return new ConfigurationDriftResult(recording: EvidenceRecordingState::Off, observations: [], recordedBy: null);
            }

            return new ConfigurationDriftResult(
                recording: EvidenceRecordingState::On,
                observations: [driftObservation('billing.refund', 'sha256:fp-one', '2026-09-01 10:00:00+00:00', '2026-09-01 10:00:00+00:00', 1)],
                recordedBy: null,
            );
        }
    };

    app()->instance(ConfigurationDriftQuery::class, $query);

    $html = renderDrift();

    expect($query->calls)->toBe(1)
        ->and($html)->toContain('data-recording="on"')
        ->and($html)->toContain('sha256:fp-one')
        ->and($html)->not->toContain('recording is off');
});

/** An audit surface mutates nothing. */
it('renders read-only markup with no form', function (): void {
    bindDriftResult(EvidenceRecordingState::On, [
        driftObservation('billing.refund', 'sha256:fp-one', '2026-09-01 10:00:00+00:00', '2026-09-01 10:00:00+00:00', 1),
    ]);

    expect(renderDrift())->not->toContain('<form');
});
