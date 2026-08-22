<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole;

use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the headless core of `fissible/verdict-console`.
 *
 * Scaffold only. The runtime described in `docs/design/0001-verdict-console-design.md` — the
 * `PendingApproval` store, the disposition/resolution bridges, the durable projections, and the
 * operator surfaces — is not implemented here yet. This provider currently does nothing but merge
 * and publish the package configuration, so a clean install boots without error while the runtime is
 * built milestone by milestone (see `MILESTONES.md`).
 */
final class VerdictConsoleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/verdict-console.php', 'verdict-console');

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

        $this->publishes([
            __DIR__.'/../config/verdict-console.php' => config_path('verdict-console.php'),
        ], ['verdict-console', 'verdict-console-config']);
    }
}
