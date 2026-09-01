<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Approvals\UnresumableReason;
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

/** The Blade thread is the non-streaming surface, and the design must say so rather than imply parity with Livewire. */
it('documents the Blade chat threads non-streaming limitation', function (): void {
    expect(documentation('docs/design/0001-verdict-console-design.md'))
        ->toContain('`<x-verdict-console::chat />`')
        ->toContain('does not stream')
        ->toContain('`verdict-console.chat.send`');
});

/** The audit page is the surface §6.6's blank-by-config rule was written for; the design must name it and its audience rule. */
it('names the evidence page in the design of record', function (): void {
    expect(documentation('docs/design/0001-verdict-console-design.md'))
        ->toContain('`<x-verdict-console::evidence />`')
        ->toContain('recording is off — blank by config')
        ->toContain('the host embeds it behind its own authorization');
});

/** The three ops screens close v0.4.0's Blade scope; the design must name them and their read-only stance. */
it('names the Blade ops views in the design of record', function (): void {
    expect(documentation('docs/design/0001-verdict-console-design.md'))
        ->toContain('`<x-verdict-console::doctor />`')
        ->toContain('`<x-verdict-console::execution-claims />`')
        ->toContain('`<x-verdict-console::incidents />`')
        ->toContain('read-only')
        ->toContain('a resolve form is follow-up work');
});

/**
 * The adapter-layering decision (2026-08-30): the package split is by dependency, and single-engine
 * completeness lives in core. Adapter planning must answer to this rule, so it is pinned.
 */
it('records that core is the complete baseline and adapters are upgrades', function (): void {
    expect(documentation('docs/design/0001-verdict-console-design.md'))
        ->toContain('complete, dependency-free baseline')
        ->toContain('an upgrade of a core surface')
        ->toContain('never the only implementation of it')
        ->toContain('The split is by dependency, not by audience');
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

/**
 * VC-45: verdict#298's status read (ADR 0031) un-collapses `challengeForToolCall()`'s null. The
 * design of record and the enum that recorded the collapse must both say what is now readable —
 * and must stop claiming the distinction is impossible.
 */
it('records the adopted approval status read in the design and the unresumable-reason doc', function (): void {
    $design = documentation('docs/design/0001-verdict-console-design.md');

    expect($design)
        ->toContain('`ApprovalStatusReader`')
        ->toContain('lapsed, undecided')
        ->toContain('the status read un-collapses it for the inbox')
        ->not->toContain('Until such a contract exists, one state');

    $case = (string) (new ReflectionEnumBackedCase(UnresumableReason::class, 'ChallengeUnavailable'))->getDocComment();

    expect($case)
        ->toContain('ApprovalStatusReader')
        ->not->toContain('future Verdict status-read contract');
});

/**
 * VC-86: reconciliation is no longer detect-and-abandon only. The design of record must carry the
 * retry protocol — the decision is re-read live at retry time, never persisted for replay — and
 * must stop saying retry is unbuilt and waiting.
 */
it('records the durable-retry protocol in the design of record', function (): void {
    $design = documentation('docs/design/0001-verdict-console-design.md');

    expect($design)
        ->toContain('re-read live through `ApprovalStatusReader`')
        ->toContain('never persists the decision it re-sends')
        ->toContain('a consumed receipt refuses the retry')
        ->not->toContain('it does not retry, and it names two phases')
        ->not->toContain('retry waits on');
});

/**
 * VC-69: the recommended scope and its reason travel in the design of record — the subset
 * guarantee only means something if the rule it relies on (typed-exact, ADR 0031 §3) is named
 * beside the recommendation.
 */
it('records the recommended context scope and its subset guarantee in the design of record', function (): void {
    $design = documentation('docs/design/0001-verdict-console-design.md');

    expect($design)
        ->toContain('`ApprovalContextScope`')
        ->toContain('a subset of what Verdict would let them decide')
        ->toContain('typed-exact');
});

/** #104's honest state: the design must state the chained-sink rule in the surfaces' own words. */
it('documents the chained-sink recording state in the design of record', function (): void {
    expect(documentation('docs/design/0001-verdict-console-design.md'))
        ->toContain('chained sink is configured; decisions are not readable from this table')
        ->toContain('never that any append succeeded');
});
