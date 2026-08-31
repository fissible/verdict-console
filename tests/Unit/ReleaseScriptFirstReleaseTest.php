<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Runs the real `release.sh` against a throwaway repository with a bare `origin`, answering its
 * prompts over stdin. Nothing leaves the temp directory: the push prompt is declined, and the bare
 * repository is what `git fetch origin main` talks to.
 *
 * @return array{string, string} the work tree and the bare origin
 */
function scaffoldFirstReleaseRepository(): array
{
    $root = sys_get_temp_dir().'/verdict-console-release-sh-'.bin2hex(random_bytes(8));
    $origin = $root.'/origin.git';
    $work = $root.'/work';

    foreach ([$root, $work, $work.'/scripts'] as $directory) {
        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }
    }

    $repo = dirname(__DIR__, 2);
    copy($repo.'/release.sh', $work.'/release.sh');
    copy($repo.'/scripts/prepare-release-changelog.php', $work.'/scripts/prepare-release-changelog.php');

    file_put_contents($work.'/VERSION', "0.1.0\n");
    file_put_contents($work.'/composer.json', json_encode(['name' => 'fissible/example'], JSON_THROW_ON_ERROR)."\n");
    file_put_contents($work.'/README.md', "# Example\n\n```bash\ncomposer require fissible/example:^0.0\n```\n");
    file_put_contents($work.'/CHANGELOG.md', <<<'MARKDOWN'
# Changelog

## [Unreleased]

- **Scaffolded the package.** The first thing worth releasing.

MARKDOWN);

    $git = static function (string $cwd, string ...$arguments): string {
        $process = new Process(['git', ...$arguments], $cwd, [
            'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
        ]);
        $process->mustRun();

        return trim($process->getOutput());
    };

    $git($root, 'init', '--quiet', '--bare', '--initial-branch=main', $origin);
    $git($work, 'init', '--quiet', '--initial-branch=main');
    $git($work, 'add', '-A');
    $git($work, 'commit', '--quiet', '-m', 'feat: scaffold the package');
    $git($work, 'remote', 'add', 'origin', $origin);
    $git($work, 'push', '--quiet', '-u', 'origin', 'main');

    return [$work, $origin];
}

function removeFirstReleaseRepository(string $work): void
{
    $root = dirname($work);
    $process = new Process(['rm', '-rf', $root]);
    $process->run();
}

/** @param list<string> $answers */
function runReleaseScript(string $work, array $answers, string ...$arguments): Process
{
    $process = new Process(['bash', 'release.sh', ...$arguments], $work, [
        'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com',
        'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
    ]);
    $process->setInput(implode("\n", $answers)."\n");
    $process->run();

    return $process;
}

it('cuts a first release from an untagged repository without a bump', function (): void {
    [$work, $origin] = scaffoldFirstReleaseRepository();

    try {
        // first release as-is? → y · Proceed? → y · Push to origin? → n
        $process = runReleaseScript($work, ['y', 'y', 'n']);

        $tags = (new Process(['git', 'tag', '--list'], $work))->mustRun()->getOutput();
        $subject = (new Process(['git', 'log', '-1', '--format=%s'], $work))->mustRun()->getOutput();
        $originTags = (new Process(['git', '--git-dir', $origin, 'tag', '--list'], $work))->mustRun()->getOutput();
        $changelog = (string) file_get_contents($work.'/CHANGELOG.md');
        $readme = (string) file_get_contents($work.'/README.md');

        expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
            ->and($process->getOutput())->toContain('No previous tag exists, so 0.1.0 in VERSION is itself unreleased.')
            ->and(trim($tags))->toBe('v0.1.0')
            ->and(trim($subject))->toBe('chore: release v0.1.0')
            ->and(trim((string) file_get_contents($work.'/VERSION')))->toBe('0.1.0')
            // gmdate, matching release.sh's `date -u`. `date()` follows PHP's configured timezone,
            // which is UTC here and need not be anywhere else; pinning both sides to UTC explicitly
            // is what stops this from passing 17 hours a day and failing the other 7.
            ->and($changelog)->toContain("## [Unreleased]\n\n## [0.1.0] - ".gmdate('Y-m-d'))
            ->and($changelog)->toContain('[Unreleased]: ')
            ->and($changelog)->toContain('/compare/v0.1.0...HEAD')
            ->and($changelog)->toContain('[0.1.0]: ')
            ->and($changelog)->toContain('/releases/tag/v0.1.0')
            ->and($readme)->toContain('composer require fissible/example:^0.1')
            // The push prompt was declined: the bare origin must not have the tag.
            ->and(trim($originTags))->toBe('');
    } finally {
        removeFirstReleaseRepository($work);
    }
})->skipOnWindows();

it('still requires a bump once a previous tag exists', function (): void {
    [$work] = scaffoldFirstReleaseRepository();

    try {
        (new Process(['git', 'tag', '-a', 'v0.1.0', '-m', 'v0.1.0'], $work, [
            'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
        ]))->mustRun();

        // An explicit bump argument, then: Proceed? → n. The first-release prompt must not appear.
        $process = runReleaseScript($work, ['n'], 'patch');

        expect($process->getOutput())->not->toContain('No previous tag exists')
            ->and($process->getOutput())->toContain('New version: 0.1.0 → 0.1.1');
    } finally {
        removeFirstReleaseRepository($work);
    }
})->skipOnWindows();

it('leaves the tree exactly as it found it when a guard refuses after confirmation', function (): void {
    [$work] = scaffoldFirstReleaseRepository();

    try {
        // The filament v0.1.0 incident: a README without the install-constraint line. The refusal
        // must come before any file is rewritten — a mutated CHANGELOG makes the retry die on
        // "Working tree is dirty" with no hint that the script itself dirtied it.
        file_put_contents($work.'/README.md', "# Example\n\nNo install line yet.\n");

        $env = [
            'GIT_AUTHOR_NAME' => 'Test', 'GIT_AUTHOR_EMAIL' => 'test@example.com',
            'GIT_COMMITTER_NAME' => 'Test', 'GIT_COMMITTER_EMAIL' => 'test@example.com',
        ];
        (new Process(['git', 'commit', '--quiet', '-am', 'docs: drop the install line'], $work, $env))->mustRun();
        (new Process(['git', 'push', '--quiet'], $work, $env))->mustRun();

        $before = (string) file_get_contents($work.'/CHANGELOG.md');

        // first release as-is? → y · Proceed? → y (the refusal must arrive before either matters)
        $process = runReleaseScript($work, ['y', 'y']);

        $status = (new Process(['git', 'status', '--porcelain'], $work))->mustRun()->getOutput();
        $tags = (new Process(['git', 'tag', '--list'], $work))->mustRun()->getOutput();

        expect($process->isSuccessful())->toBeFalse()
            ->and($process->getErrorOutput())->toContain("no 'composer require fissible/example:' line in README.md")
            ->and(trim($status))->toBe('')
            ->and((string) file_get_contents($work.'/CHANGELOG.md'))->toBe($before)
            ->and(trim((string) file_get_contents($work.'/VERSION')))->toBe('0.1.0')
            ->and(trim($tags))->toBe('');
    } finally {
        removeFirstReleaseRepository($work);
    }
})->skipOnWindows();
