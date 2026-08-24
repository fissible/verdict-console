<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return array{string, string} */
function releaseChangelogFixture(string $contents): array
{
    $directory = sys_get_temp_dir().'/verdict-console-release-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Unable to create the release-test directory.');
    }

    $path = $directory.'/CHANGELOG.md';
    file_put_contents($path, $contents);

    return [$directory, $path];
}

/** @param list<string> $arguments */
function runReleaseChangelogScript(array $arguments): Process
{
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 2).'/scripts/prepare-release-changelog.php',
        ...$arguments,
    ]);
    $process->run();

    return $process;
}

function removeReleaseChangelogFixture(string $directory, string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }

    rmdir($directory);
}

it('promotes documentation-only notes without rewriting curated release history', function (): void {
    $original = <<<'MARKDOWN'
# Changelog

## [Unreleased]

- Replace private VCS instructions with the Packagist command.

## [0.1.1] - 2026-08-02

- Preserve this hand-written historical note exactly.

[Unreleased]: https://github.com/fissible/verdict-console/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/fissible/verdict-console/compare/v0.1.0...v0.1.1
MARKDOWN;
    [$directory, $path] = releaseChangelogFixture($original);

    try {
        $process = runReleaseChangelogScript([$path, '0.1.2', 'v0.1.1', 'v0.1.2', '2026-08-03']);
        $updated = (string) file_get_contents($path);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($updated)->toContain("## [Unreleased]\n\n## [0.1.2] - 2026-08-03")
            ->and($updated)->toContain('- Replace private VCS instructions with the Packagist command.')
            ->and($updated)->toContain('- Preserve this hand-written historical note exactly.')
            ->and($updated)->toContain('[Unreleased]: https://github.com/fissible/verdict-console/compare/v0.1.2...HEAD')
            ->and($updated)->toContain('[0.1.2]: https://github.com/fissible/verdict-console/compare/v0.1.1...v0.1.2');
    } finally {
        removeReleaseChangelogFixture($directory, $path);
    }
});

it('refuses to prepare a release with no curated notes', function (): void {
    $original = <<<'MARKDOWN'
# Changelog

## [Unreleased]

## [0.1.1] - 2026-08-02

- Existing release.

[Unreleased]: https://github.com/fissible/verdict-console/compare/v0.1.1...HEAD
MARKDOWN;
    [$directory, $path] = releaseChangelogFixture($original);

    try {
        $process = runReleaseChangelogScript([$path, '0.1.2', 'v0.1.1', 'v0.1.2', '2026-08-03']);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('Unreleased changelog section is empty.')
            ->and(file_get_contents($path))->toBe($original);
    } finally {
        removeReleaseChangelogFixture($directory, $path);
    }
});

// --- first release: no previous tag, no release section, no link footer -------------------------
//
// Verdict's variant of this script assumed a release history exists. A repository cutting its first
// release has none of the three things it required, and that cost verdict-console a hand-bootstrapped
// v0.1.0. An empty previous tag is the signal; the repository URL is what the footer is built from.

it('prepares a first release from an Unreleased-only changelog and creates the link footer', function (): void {
    $original = <<<'MARKDOWN'
# Changelog

All notable changes to this package will be documented in this file.

## [Unreleased]

- **Scaffolded the package.** The first thing worth releasing.
MARKDOWN;
    [$directory, $path] = releaseChangelogFixture($original);

    try {
        $process = runReleaseChangelogScript([
            $path, '0.1.0', '', 'v0.1.0', '2026-08-24', 'https://github.com/fissible/example',
        ]);
        $updated = (string) file_get_contents($path);

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
            ->and($updated)->toContain("## [Unreleased]\n\n## [0.1.0] - 2026-08-24\n\n- **Scaffolded the package.** The first thing worth releasing.")
            ->and($updated)->toContain('All notable changes to this package will be documented in this file.')
            ->and($updated)->toEndWith(
                "[Unreleased]: https://github.com/fissible/example/compare/v0.1.0...HEAD\n"
                ."[0.1.0]: https://github.com/fissible/example/releases/tag/v0.1.0\n",
            )
            // The shape a *second* release then needs is exactly the one the first produced.
            ->and(substr_count($updated, '[Unreleased]:'))->toBe(1);
    } finally {
        removeReleaseChangelogFixture($directory, $path);
    }
});

it('refuses a first release when the changelog already carries an Unreleased comparison link', function (): void {
    // A footer with no previous tag is a contradiction a human should resolve, not one the script
    // should paper over by inventing or discarding a link.
    $original = <<<'MARKDOWN'
# Changelog

## [Unreleased]

- Something.

[Unreleased]: https://github.com/fissible/example/compare/v0.0.9...HEAD
MARKDOWN;
    [$directory, $path] = releaseChangelogFixture($original);

    try {
        $process = runReleaseChangelogScript([
            $path, '0.1.0', '', 'v0.1.0', '2026-08-24', 'https://github.com/fissible/example',
        ]);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('no previous tag')
            ->and(file_get_contents($path))->toBe($original);
    } finally {
        removeReleaseChangelogFixture($directory, $path);
    }
});

it('refuses a first release without a repository URL to build the footer from', function (): void {
    $original = <<<'MARKDOWN'
# Changelog

## [Unreleased]

- Something.
MARKDOWN;
    [$directory, $path] = releaseChangelogFixture($original);

    try {
        $process = runReleaseChangelogScript([$path, '0.1.0', '', 'v0.1.0', '2026-08-24']);

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain('repository URL')
            ->and(file_get_contents($path))->toBe($original);
    } finally {
        removeReleaseChangelogFixture($directory, $path);
    }
});
