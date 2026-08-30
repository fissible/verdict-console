<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Configuration\VerdictConfigurationInspection;
use Fissible\VerdictConsole\Presentation\DefaultApprovalPresenter;

function documentation(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/'.$path);
}

it('keeps ADR 0001 corrections in the design of record', function (): void {
    $design = documentation('docs/design/0001-verdict-console-design.md');

    expect($design)
        ->toContain('`verdict-console.approvals.gate` ability')
        ->toContain('`close` resumes the exact conversation')
        ->toContain('without calling')
        ->toContain('`ApprovalManager::approve/reject`')
        ->toContain('filed per-receipt read')
        ->toContain('enumeration dependency')
        ->toContain('Expiry has no transition moment and Verdict never auto-rejects it.')
        ->toContain('ADR 0029 postdates')
        ->toContain('direct upstream link is the auditable source')
        ->toContain('## 14. Filed dependency cluster')
        ->not->toContain('Companion Verdict issue?');
});

it('distinguishes durable presentation from live challenge rendering', function (): void {
    $docblock = (new ReflectionClass(DefaultApprovalPresenter::class))->getDocComment();

    expect($docblock)
        ->toContain('must never persist provenance')
        ->toContain('may render provenance live from the challenge')
        ->toContain('does not initiate a second context release');
});

/**
 * Design §6.6 placed the correlation capture at the `ToolApprovalRequested` boundary. Building it
 * showed that boundary alone omits every decision that never paused and the resume's own
 * invocation; that the completion events precede every approval event with the same response, so
 * they are the boundary; that the join is empty unless the host runs the middleware that stamps
 * invocation ids on Verdict's evidence; and that the projection therefore retains every remembered
 * invocation, decision or not. The design of record must say all four.
 */
it('records in the design which boundaries feed the conversation correlation and what it depends on', function (): void {
    $design = documentation('docs/design/0001-verdict-console-design.md');

    expect($design)
        ->toContain('`AgentPrompted`')
        ->toContain('`AgentStreamed`')
        ->toContain('`VerdictProvenanceMiddleware`')
        ->toContain('produced no decision evidence')
        ->not->toContain('captured at the `ToolApprovalRequested`');

    // The issue plan is what an implementer reads first; it must not still prescribe the boundary
    // the design has corrected.
    expect(documentation('docs/planning/ISSUES.md'))
        ->toContain('`AgentPrompted`')
        ->not->toContain('at the `ToolApprovalRequested` boundary');
});

/** #72 shipped the doctor findings the VC-14 design text promised; the design must name them, not the promise. */
it('names the evidence-correlation doctor findings in the design of record', function (): void {
    expect(documentation('docs/design/0001-verdict-console-design.md'))
        ->toContain('`evidence_correlation_middleware_missing`')
        ->toContain('`evidence_correlation_table_missing`');
});

/**
 * Inspect-only is a decision with a reason, and the reason has to travel with the code: the
 * capability-configuration fingerprint is recorded in every decision record, so a config write
 * changes what the evidence trail means. The class that could grow a write path must say so.
 */
it('documents why configuration inspection has no write path', function (): void {
    $docblock = (string) (new ReflectionClass(VerdictConfigurationInspection::class))->getDocComment();

    // The causal statement, not keywords: the fingerprint travels with every capability-resolved
    // decision record, so a configuration write changes what already-recorded evidence means.
    expect($docblock)->toContain('inspect-only')
        ->and($docblock)->toContain('configuration fingerprint is recorded in every capability-resolved decision record')
        ->and($docblock)->toContain('a configuration write changes what already-recorded evidence means');

    expect(documentation('docs/design/0001-verdict-console-design.md'))
        ->toContain('`ConfigurationInspection`')
        ->toContain('recorded in every decision record');
});

/** Design §8's first ops surface now has a headless service; the design must name it beside the commands it mirrors. */
it('names the execution-claim service in the design of record', function (): void {
    expect(documentation('docs/design/0001-verdict-console-design.md'))
        ->toContain('`ExecutionClaimService`')
        ->toContain('`verdict-console.execution_claims.gate`');
});

/** The chat surfaces (VC-21, VC-24) start conversations through a host contract; the design must name it and its key. */
it('names the chat-entry contract in the design of record', function (): void {
    expect(documentation('docs/design/0001-verdict-console-design.md'))
        ->toContain('`ChatEntry`')
        ->toContain('`verdict-console.chat.entry_key`');
});

/**
 * The routes follow Laravel's first-party shape — mounted at boot, opt-out — and the design must
 * say so, name both opt-outs, give the reason it is safe (every endpoint is fail-closed behind the
 * host's Gate), and carry the rule for any future install command: it asks whether to mount.
 */
it('records that console routes mount by default with an opt-out, and that an install command must ask', function (): void {
    expect(documentation('docs/design/0001-verdict-console-design.md'))
        ->toContain('`VerdictConsoleRoutes::ignoreRoutes()`')
        ->toContain('`verdict-console.routes.register`')
        ->toContain('every endpoint is fail-closed behind the host\'s Gate')
        ->toContain('must ask whether to mount the console routes')
        ->not->toContain('must ask before registering routes')
        ->not->toContain('Routes are opt-in');
});

/** Folded in from the VC-13 review: the UTC reading is a contract from verdict#335 onward, not a hope. */
it('cites the Verdict change that makes the evidence timestamp reading a UTC contract', function (): void {
    expect(documentation('src/Evidence/DatabaseEvidenceQuery.php'))->toContain('fissible/verdict#335');
});

it('tells adopters that require_review awaits its gated Verdict substrate', function (): void {
    expect(documentation('README.md'))
        ->toContain('`require_review` is a separate, gated review lane')
        ->toContain('Verdict #297 and #298');
});
