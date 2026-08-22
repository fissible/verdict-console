<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the headless core of `fissible/verdict-console`.
 *
 * The runtime described in `docs/design/0001-verdict-console-design.md` is being built milestone by
 * milestone (see `MILESTONES.md`). Landed so far: the `PendingApproval` index and its migration
 * (VC-4). Still to come: the disposition/resolution bridges, the durable projections, and the
 * operator surfaces. This provider merges and publishes configuration and migrations, so a clean
 * install boots without error at every point along the way.
 */
final class VerdictConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/verdict-console.php', 'verdict-console');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/verdict-console.php' => config_path('verdict-console.php'),
        ], ['verdict-console', 'verdict-console-config']);

        // Published rather than loaded from the package: the host owns when its schema changes, and
        // a console table appearing on `migrate` without the host having asked for it is the kind of
        // surprise a security-adjacent package should never spring. Verdict publishes its own
        // migrations the same way.
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/create_verdict_console_pending_approvals_table.php.stub' => database_path(
                'migrations/2026_08_21_000001_create_verdict_console_pending_approvals_table.php',
            ),
        ], ['verdict-console', 'verdict-console-migrations']);
    }
}
