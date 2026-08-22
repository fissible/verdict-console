<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * The migration is published, not loaded from the package.
 *
 * The host owns when its schema changes. A console table appearing on `migrate` because a package
 * was installed is the kind of surprise a security-adjacent dependency should never spring, so this
 * asserts the publish path works rather than assuming the tag is wired.
 */
it('publishes the pending-approvals migration under its own tag', function (): void {
    $target = database_path('migrations');

    File::exists($target) && File::cleanDirectory($target);

    $this->artisan('vendor:publish', ['--tag' => 'verdict-console-migrations', '--force' => true])
        ->assertSuccessful();

    $published = collect(File::files($target))
        ->map(fn ($file): string => $file->getFilename())
        ->filter(fn (string $name): bool => str_contains($name, 'create_verdict_console_pending_approvals_table'));

    expect($published)->toHaveCount(1)
        ->and($published->first())->toEndWith('.php', 'A published migration must be runnable, not left as a .stub.');

    File::cleanDirectory($target);
});

/** The package must not run its own migrations behind the host's back. */
it('does not load the migration automatically', function (): void {
    expect(app('migrator')->paths())->not->toContain(dirname(__DIR__, 2).'/database/migrations');
});
