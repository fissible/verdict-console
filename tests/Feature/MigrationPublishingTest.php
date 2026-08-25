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

/**
 * Every migration publishes, and later ones sort after the table they alter.
 *
 * The second half is not cosmetic: Laravel runs published migrations in filename order, so an
 * operational-state migration dated before the create migration would fail on a fresh install with a
 * missing table — and pass forever on the developer's machine, where the table already exists.
 */
it('publishes every migration, in an order that can actually run', function (): void {
    $target = database_path('migrations');

    File::exists($target) && File::cleanDirectory($target);

    $this->artisan('vendor:publish', ['--tag' => 'verdict-console-migrations', '--force' => true])
        ->assertSuccessful();

    $published = collect(File::files($target))
        ->map(fn ($file): string => $file->getFilename())
        ->sort()
        ->values();

    expect($published)->toHaveCount(5);

    $position = fn (string $fragment): int => $published->search(fn (string $name): bool => str_contains($name, $fragment));

    expect($position('create_verdict_console_pending_approvals_table'))
        ->toBeLessThan($position('add_operational_state_to_verdict_console_pending_approvals_table'), 'A column cannot be added to a table that does not exist yet.')
        ->and($position('create_verdict_console_pending_approvals_table'))
        ->toBeLessThan($position('create_verdict_console_approval_notifications_table'), 'The notifications foreign key requires the pause table.')
        ->and($position('create_verdict_console_pending_approvals_table'))
        ->toBeLessThan($position('create_verdict_console_approval_reconciliations_table'), 'The reconciliation foreign key requires the pause table.')
        ->and($position('create_verdict_console_pending_approvals_table'))
        ->toBeLessThan($position('create_verdict_console_incidents_table'), 'The incident foreign key requires the pause table.');

    File::cleanDirectory($target);
});

/**
 * A published migration has already run for every adopter of the release that shipped it.
 *
 * Adding a column to the create migration therefore reaches new installs only, and silently divides
 * adopters into those whose schema matches the code and those whose does not. `unresumable_reason`
 * was amended in place because its stub was still unreleased; v0.1.0 ended that licence. This asserts
 * the shipped create migration still describes v0.1.0's table and nothing later.
 */
it('leaves the released create migration alone and adds operational state separately', function (): void {
    $create = file_get_contents(dirname(__DIR__, 2).'/database/migrations/create_verdict_console_pending_approvals_table.php.stub');
    $added = file_get_contents(dirname(__DIR__, 2).'/database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub');

    expect($create)->not->toContain('resume_attempts', 'v0.1.0 shipped without this column; adding it here reaches new installs only.')
        ->and($create)->not->toContain('last_resume_attempt_at')
        ->and($added)->toContain('resume_attempts')
        ->and($added)->toContain('last_resume_attempt_at');
});

/** The package must not run its own migrations behind the host's back. */
it('does not load the migration automatically', function (): void {
    expect(app('migrator')->paths())->not->toContain(dirname(__DIR__, 2).'/database/migrations');
});
