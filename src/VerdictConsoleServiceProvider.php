<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole;

use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Approvals\GateApproverAuthority;
use Fissible\VerdictConsole\Console\Commands\DoctorCommand;
use Fissible\VerdictConsole\Contracts\ApprovalPresenter;
use Fissible\VerdictConsole\Contracts\ApproverAuthority;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Presentation\DefaultApprovalPresenter;
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

        $this->app->singleton(ApprovalPresenter::class, DefaultApprovalPresenter::class);

        // Bound to the Gate-backed authority, which denies until the host defines the ability. A
        // host whose authority model is not a Gate rebinds this contract.
        $this->app->singleton(ApproverAuthority::class, GateApproverAuthority::class);

        // A singleton because registrations must survive resolution: a host registers its resolvers
        // once at boot, and every later resolve() must see them. Bound to the shipped registry so
        // the common case needs no class, and overridable because reconstruction is host knowledge.
        $this->app->singleton(ResumableAgents::class, AgentResolverRegistry::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([DoctorCommand::class]);

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
