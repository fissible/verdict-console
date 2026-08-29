<?php

declare(strict_types=1);

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

/** Folded in from the VC-13 review: the UTC reading is a contract from verdict#335 onward, not a hope. */
it('cites the Verdict change that makes the evidence timestamp reading a UTC contract', function (): void {
    expect(documentation('src/Evidence/DatabaseEvidenceQuery.php'))->toContain('fissible/verdict#335');
});

it('tells adopters that require_review awaits its gated Verdict substrate', function (): void {
    expect(documentation('README.md'))
        ->toContain('`require_review` is a separate, gated review lane')
        ->toContain('Verdict #297 and #298');
});
