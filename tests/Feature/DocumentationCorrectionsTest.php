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

it('tells adopters that require_review awaits its gated Verdict substrate', function (): void {
    expect(documentation('README.md'))
        ->toContain('`require_review` is a separate, gated review lane')
        ->toContain('Verdict #297 and #298');
});
