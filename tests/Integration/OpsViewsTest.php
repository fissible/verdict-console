<?php

declare(strict_types=1);

use Fissible\Verdict\Actions\ActionEnvelope;
use Fissible\Verdict\Capabilities\Capability;
use Fissible\Verdict\Contracts\CapabilityAuthorizer;
use Fissible\Verdict\Contracts\ExecutionClaimStore;
use Fissible\Verdict\Decisions\Decision;
use Fissible\Verdict\ExecutionClaims\ExecutionClaim;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimOutcome;
use Fissible\Verdict\ExecutionClaims\ExecutionClaimStatus;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\Verdict\VerdictManager;
use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Doctor\Doctor;
use Fissible\VerdictConsole\Doctor\Finding;
use Fissible\VerdictConsole\Doctor\Severity;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;

/**
 * The doctor screen and the execution-claim queue, rendered against the real Verdict container —
 * the same tier their services are tested in. Both are read-only projections of VC-3 and VC-16.
 */
beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations/2026_01_11_000001_create_agent_conversations_table.php')->up();
    (require dirname(__DIR__, 2).'/database/migrations/create_verdict_console_conversation_invocations_table.php.stub')->up();
    (require dirname(__DIR__, 2).'/vendor/fissible/verdict/database/migrations/create_verdict_execution_claims_table.php.stub')->up();

    config()->set('verdict.approvals.authorizer', AllowAllApprovalAuthorizer::class);

    $this->app->instance(CapabilityAuthorizer::class, new class implements CapabilityAuthorizer
    {
        public function decide(Capability $capability, ActionEnvelope $envelope, mixed $target): Decision
        {
            return Decision::permit('ops test');
        }
    });
});

/** Registered as resumable, has the approval middleware, binds nothing: two warnings by VC-3/#72. */
final class OpsToollessAgent implements Agent, HasMiddleware, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversationsTrait;

    public function instructions(): Stringable|string
    {
        return 'ops fixture';
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [app(VerdictApprovalMiddleware::class)];
    }
}

function opsClaimRow(string $id, ExecutionClaimStatus $status, string $recordedAt, string $capability = 'orders.refund', string $policy = 'refund-once'): ExecutionClaim
{
    $at = new DateTimeImmutable($recordedAt, new DateTimeZone('UTC'));

    return new ExecutionClaim(
        id: $id,
        capability: $capability,
        policy: $policy,
        bindingFingerprint: hash('sha256', $id.'-binding'),
        status: $status,
        attemptCount: 1,
        claimedAt: $at,
        completedAt: null,
        indeterminateAt: null,
        releasedAt: null,
        resolvedBy: null,
        resolutionReason: null,
        createdAt: $at,
        updatedAt: $at,
    );
}

function opsIndeterminateClaim(string $id, string $at, string $capability = 'orders.refund', string $policy = 'refund-once'): void
{
    $store = app(ExecutionClaimStore::class);
    $store->claim(opsClaimRow($id, ExecutionClaimStatus::Claimed, $at, $capability, $policy));
    $transition = $store->markIndeterminate($id, new DateTimeImmutable($at, new DateTimeZone('UTC')));

    expect($transition->outcome)->toBe(ExecutionClaimOutcome::Indeterminate, 'Fixture: Verdict must have marked the claim indeterminate.');
}

function opsActiveClaim(string $id, string $at): void
{
    $transition = app(ExecutionClaimStore::class)->claim(opsClaimRow($id, ExecutionClaimStatus::Claimed, $at));

    expect($transition->outcome)->toBe(ExecutionClaimOutcome::Claimed, 'Fixture: Verdict must have admitted the claim.');
}

function opsDocument(string $html): DOMXPath
{
    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    libxml_clear_errors();

    return new DOMXPath($document);
}

/**
 * The acceptance criterion the issue names: the doctor screen surfaces the #230 dead gate — a
 * capability that asks for confirmation but declares no execution target, so it never pauses and
 * never reaches the inbox. The screen renders VC-3's findings; it derives none of its own.
 */
it('surfaces a confirmable-without-target capability on the doctor screen', function (): void {
    app(VerdictManager::class)->capability(
        Capability::usingPolicy(
            name: 'ops.dead-gate',
            ability: 'update',
            resolveTarget: fn (ActionEnvelope $e): object => new stdClass,
        )
            ->requiresConfirmation(fn (ActionEnvelope $e, object $t): array => ['id' => 1])
            ->executeUsing(fn (): string => 'done'),
    );

    // A warning source alongside the error, so the screen is proven against a MIXED run.
    $registry = new AgentResolverRegistry;
    $registry->register('ops@v1', fn (): OpsToollessAgent => new OpsToollessAgent, fn (Agent $agent): bool => $agent instanceof OpsToollessAgent);
    app()->instance(ResumableAgents::class, $registry);

    // The screen is a projection of Doctor::run() — same rows, same order, same counts.
    $expected = app(Doctor::class)->run();
    $errors = count(array_filter($expected, fn (Finding $f): bool => $f->severity === Severity::Error));

    expect($expected)->not->toBe([])
        ->and($errors)->toBeGreaterThanOrEqual(1)
        ->and(count($expected) - $errors)->toBeGreaterThanOrEqual(1, 'Fixture: the run must mix errors and warnings.');

    $html = (string) $this->blade('<x-verdict-console::doctor />');
    $xpath = opsDocument($html);
    $rendered = [];

    foreach ($xpath->query('//*[@data-finding]') ?: [] as $node) {
        if ($node instanceof DOMElement) {
            $rendered[] = [$node->getAttribute('data-finding'), $node->getAttribute('data-severity')];
        }
    }

    expect($rendered)->toBe(array_map(fn (Finding $f): array => [$f->code->value, $f->severity->value], $expected));

    $deadGate = $xpath->query('//*[@data-finding="confirmation_gate_cannot_pause"]');

    expect($deadGate->length)->toBe(1)
        ->and($deadGate[0]->getAttribute('data-severity'))->toBe('error')
        ->and($deadGate[0]->textContent)->toContain('ops.dead-gate')
        ->and($deadGate[0]->textContent)->toContain('never pauses')
        // A finding renders its fix, or the screen is a list of problems with no way out.
        ->and($deadGate[0]->textContent)->toContain('executionTarget');

    preg_match('/<section\b[^>]*data-verdict-console="doctor"[^>]*data-errors="(\d+)"[^>]*data-warnings="(\d+)"/', $html, $m);

    expect($m)->not->toBe([])
        ->and((int) $m[1])->toBe($errors, 'The counts are the run\'s, not the rendered rows\'.')
        ->and((int) $m[2])->toBe(count($expected) - $errors);
});

/** A clean wiring reads as the command's own words, not as an empty list of problems. */
it('says every precondition is satisfied when the doctor finds nothing', function (): void {
    $html = (string) $this->blade('<x-verdict-console::doctor />');

    expect($html)->toContain('data-verdict-console="doctor"')
        ->and($html)->toContain('data-errors="0"')
        ->and($html)->toContain('Every console precondition is satisfied.')
        ->and($html)->not->toContain('data-finding=');
});

it('lists unresolved claims with the indeterminate ones first', function (): void {
    opsActiveClaim('claim-active-newer', '2026-08-30 12:05:00');
    opsIndeterminateClaim('claim-needs-human', '2026-08-30 12:00:00');
    opsIndeterminateClaim('claim-needs-human-too', '2026-08-30 12:03:00');
    opsActiveClaim('claim-active-older', '2026-08-30 12:01:00');

    $html = (string) $this->blade('<x-verdict-console::execution-claims />');
    $xpath = opsDocument($html);
    $order = [];

    foreach ($xpath->query('//tr[@data-claim]') ?: [] as $row) {
        if ($row instanceof DOMElement) {
            $order[] = [$row->getAttribute('data-claim'), $row->getAttribute('data-status')];
        }
    }

    // The indeterminate claim is the one that genuinely needs a person (design §8); it outranks
    // recency. Within a status, oldest first: the longest-waiting work at the top.
    expect($order)->toBe([
        ['claim-needs-human', 'indeterminate'],
        ['claim-needs-human-too', 'indeterminate'],
        ['claim-active-older', 'claimed'],
        ['claim-active-newer', 'claimed'],
    ])
        ->and($html)->toContain('data-field="capability"')
        ->and($html)->toContain('orders.refund')
        ->and($html)->toContain('data-field="policy"')
        ->and($html)->toContain('refund-once')
        ->and($html)->toContain('data-field="attempts"')
        ->and($html)->toContain('datetime="2026-08-30T12:00:00+00:00"')
        // The fingerprint is the evidence-correlation join (design §6.2), shown beside the raw id.
        ->and($html)->toContain(hash('sha256', 'claim-needs-human'));
});

/**
 * Read-only by scope: resolution stays with VC-16's authorized service, reached today through the
 * artisan command the queue names per row. A resolve form is follow-up work, not this page.
 */
it('names the resolve command per claim instead of offering a form', function (): void {
    opsIndeterminateClaim('claim-1', '2026-08-30 12:00:00');

    $html = (string) $this->blade('<x-verdict-console::execution-claims />');

    expect($html)->toContain('verdict:resolve-execution-claim claim-1')
        ->and($html)->not->toContain('<form')
        ->and($html)->not->toContain('<button');
});

/** Capability and policy names are host-authored strings; ids come from a table. All drawn as text. */
it('escapes what the queue renders', function (): void {
    opsIndeterminateClaim('claim-hostile', '2026-08-30 12:00:00', 'orders.<b>refund</b>', 'once & "only"');

    $html = (string) $this->blade('<x-verdict-console::execution-claims />');

    expect($html)->toContain('orders.&lt;b&gt;refund&lt;/b&gt;')
        ->and($html)->toContain('once &amp; &quot;only&quot;')
        ->and($html)->not->toContain('<b>refund</b>');
});

/** The doctor's subjects are host-authored too — a capability name renders as text, never markup. */
it('escapes what the doctor screen renders', function (): void {
    app(VerdictManager::class)->capability(
        Capability::usingPolicy(
            name: 'ops.<i>dead</i>-gate',
            ability: 'update',
            resolveTarget: fn (ActionEnvelope $e): object => new stdClass,
        )
            ->requiresConfirmation(fn (ActionEnvelope $e, object $t): array => ['id' => 1])
            ->executeUsing(fn (): string => 'done'),
    );

    $html = (string) $this->blade('<x-verdict-console::doctor />');

    expect($html)->toContain('ops.&lt;i&gt;dead&lt;/i&gt;-gate')
        ->and($html)->not->toContain('<i>dead</i>');
});

it('says the queue is empty in the commands own words', function (): void {
    $html = (string) $this->blade('<x-verdict-console::execution-claims />');

    expect($html)->toContain('data-verdict-console="execution-claims"')
        ->and($html)->toContain('data-empty')
        ->and($html)->toContain('No unresolved Verdict execution claims.')
        ->and($html)->not->toContain('<table');
});
