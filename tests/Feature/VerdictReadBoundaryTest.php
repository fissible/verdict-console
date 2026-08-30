<?php

declare(strict_types=1);

use Fissible\Verdict\Contracts\ApprovalStatusReader;
use Fissible\VerdictConsole\Approvals\ApprovalItemFactory;
use Fissible\VerdictConsole\Approvals\ApprovalResolutionService;

/**
 * VC-45's architecture rule, pinned as source scans: verdict#298's `ApprovalStatusReader` is the
 * only Verdict surface the console couples to for approval reads (ADR 0001 §8). No console code
 * queries a Verdict table directly, and no console code imports the write-side receipt store.
 *
 * @return array<string, string> path relative to src/ => file contents
 */
function consoleSources(): array
{
    $root = dirname(__DIR__, 2).'/src';
    $sources = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($sources);

    return $sources;
}

it('never names the Verdict receipt table anywhere in console source', function (): void {
    $offenders = array_keys(array_filter(
        consoleSources(),
        static fn (string $source): bool => str_contains($source, 'verdict_approval_receipts'),
    ));

    expect($offenders)->toBe([]);
});

it('imports the Verdict receipt store nowhere: transitions go through the manager, reads through the status reader', function (): void {
    $offenders = array_keys(array_filter(
        consoleSources(),
        static fn (string $source): bool => str_contains($source, 'use Fissible\Verdict\Contracts\ApprovalReceiptStore'),
    ));

    expect($offenders)->toBe([]);
});

/**
 * The evidence page reads Verdict's published `verdict_evidence` decision-row schema through one
 * documented seam (VC-14). That file is the whole allowance: any other console source naming a
 * non-console `verdict_`-prefixed table is a new direct coupling this test exists to refuse.
 */
it('confines direct Verdict table reads to the documented evidence seam', function (): void {
    $offenders = array_keys(array_filter(
        consoleSources(),
        static fn (string $source): bool => preg_match('/verdict_(?!console_)/', $source) === 1,
    ));

    expect($offenders)->toBe(['Evidence/DatabaseEvidenceQuery.php']);
});

it('routes approval status reads through the verdict#298 contract', function (): void {
    $readers = static function (string $class): array {
        $types = [];

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType) {
                $types[] = $type->getName();
            }
        }

        return $types;
    };

    expect($readers(ApprovalItemFactory::class))->toContain(ApprovalStatusReader::class)
        ->and($readers(ApprovalResolutionService::class))->toContain(ApprovalStatusReader::class);
});
