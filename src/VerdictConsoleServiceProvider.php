<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole;

use Fissible\Verdict\Capabilities\Events\CapabilityConfigurationUnrecorded;
use Fissible\Verdict\Evidence\Events\ChainWriteFailed;
use Fissible\Verdict\Evidence\Events\ConsequentialActionUnrecorded;
use Fissible\Verdict\Evidence\Events\EvidenceWriteFailed;
use Fissible\VerdictConsole\Agents\AgentResolverRegistry;
use Fissible\VerdictConsole\Approvals\ApprovalChallengeReader;
use Fissible\VerdictConsole\Approvals\GateApproverAuthority;
use Fissible\VerdictConsole\Approvals\UnscopedApprovalScope;
use Fissible\VerdictConsole\Approvals\VerdictApprovalChallengeReader;
use Fissible\VerdictConsole\Chat\ChatService;
use Fissible\VerdictConsole\Chat\ConfiguredChatEntry;
use Fissible\VerdictConsole\Configuration\VerdictConfigurationInspection;
use Fissible\VerdictConsole\Console\Commands\DoctorCommand;
use Fissible\VerdictConsole\Contracts\ApprovalNotificationRecipients;
use Fissible\VerdictConsole\Contracts\ApprovalPresenter;
use Fissible\VerdictConsole\Contracts\ApprovalScope;
use Fissible\VerdictConsole\Contracts\ApproverAuthority;
use Fissible\VerdictConsole\Contracts\ChatEntry;
use Fissible\VerdictConsole\Contracts\ConfigurationInspection;
use Fissible\VerdictConsole\Contracts\ConversationParticipants;
use Fissible\VerdictConsole\Contracts\EvidenceQuery;
use Fissible\VerdictConsole\Contracts\ExecutionClaimAuthority;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Events\ApprovalDecisionRefused;
use Fissible\VerdictConsole\Events\ApprovalIngestionIncident;
use Fissible\VerdictConsole\Evidence\DatabaseEvidenceQuery;
use Fissible\VerdictConsole\ExecutionClaims\GateExecutionClaimAuthority;
use Fissible\VerdictConsole\Http\VerdictConsoleRoutes;
use Fissible\VerdictConsole\Incidents\IncidentStore;
use Fissible\VerdictConsole\Listeners\IngestToolApprovalRequests;
use Fissible\VerdictConsole\Listeners\LogApprovalIngestionIncident;
use Fissible\VerdictConsole\Listeners\NotifyApprovalResumeOutcome;
use Fissible\VerdictConsole\Listeners\RecordAnomalyIncident;
use Fissible\VerdictConsole\Listeners\RecordConversationInvocation;
use Fissible\VerdictConsole\Notifications\UnconfiguredApprovalNotificationRecipients;
use Fissible\VerdictConsole\Participants\UnconfiguredConversationParticipants;
use Fissible\VerdictConsole\Presentation\DefaultApprovalPresenter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;

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

        $this->app->singleton(ApprovalScope::class, UnscopedApprovalScope::class);

        $this->app->singleton(ApprovalChallengeReader::class, VerdictApprovalChallengeReader::class);

        // Bound to the Gate-backed authority, which denies until the host defines the ability. A
        // host whose authority model is not a Gate rebinds this contract.
        $this->app->singleton(ApproverAuthority::class, GateApproverAuthority::class);

        // Reconciliation authority is host policy too. The shipped Gate authority denies until its
        // ability is defined; hosts with tenant or ownership rules can replace this contract.
        $this->app->singleton(ExecutionClaimAuthority::class, GateExecutionClaimAuthority::class);

        // A singleton because registrations must survive resolution: a host registers its resolvers
        // once at boot, and every later resolve() must see them. Bound to the shipped registry so
        // the common case needs no class, and overridable because reconstruction is host knowledge.
        $this->app->singleton(ResumableAgents::class, AgentResolverRegistry::class);

        $this->app->singleton(ConversationParticipants::class, UnconfiguredConversationParticipants::class);

        $this->app->singleton(ChatEntry::class, ConfiguredChatEntry::class);

        $this->app->bind(ChatService::class);

        $this->app->singleton(ApprovalNotificationRecipients::class, UnconfiguredApprovalNotificationRecipients::class);

        $this->app->singleton(EvidenceQuery::class, DatabaseEvidenceQuery::class);

        $this->app->singleton(ConfigurationInspection::class, VerdictConfigurationInspection::class);

        $this->app->singleton(IncidentStore::class);
    }

    public function boot(Dispatcher $events): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'verdict-console');
        Blade::componentNamespace('Fissible\\VerdictConsole\\View\\Components', 'verdict-console');

        // route:cache already holds the mounted routes, so boot must not register replacements.
        if (VerdictConsoleRoutes::$registersRoutes
            && config('verdict-console.routes.register', true)
            && ! $this->app->routesAreCached()) {
            VerdictConsoleRoutes::register();
        }

        // Listener registration belongs in boot, after every provider has registered its bindings.
        // It must precede the console-only guard: approvals are ingested on ordinary web requests.
        $events->listen(ToolApprovalRequested::class, IngestToolApprovalRequests::class);
        $events->listen(ToolApprovalResolved::class, NotifyApprovalResumeOutcome::class);
        // Laravel's dispatcher matches an event's class and interfaces, not its parent classes, so
        // AgentStreamed must be explicit despite extending AgentPrompted.
        $events->listen(AgentPrompted::class, RecordConversationInvocation::class);
        $events->listen(AgentStreamed::class, RecordConversationInvocation::class);

        $events->listen(ConsequentialActionUnrecorded::class, RecordAnomalyIncident::class);
        $events->listen(EvidenceWriteFailed::class, RecordAnomalyIncident::class);
        $events->listen(ChainWriteFailed::class, RecordAnomalyIncident::class);
        $events->listen(CapabilityConfigurationUnrecorded::class, RecordAnomalyIncident::class);
        $events->listen(ApprovalIngestionIncident::class, RecordAnomalyIncident::class);
        $events->listen(ApprovalDecisionRefused::class, RecordAnomalyIncident::class);

        // The ledger is the durable projection; the released opt-out log sink remains a separate
        // operational alerting surface for hosts that already route it.
        if (config('verdict-console.ingestion_incidents.log', true)) {
            $events->listen(ApprovalIngestionIncident::class, LogApprovalIngestionIncident::class);
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([DoctorCommand::class]);

        $this->publishes([
            __DIR__.'/../config/verdict-console.php' => config_path('verdict-console.php'),
        ], ['verdict-console', 'verdict-console-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/verdict-console'),
        ], ['verdict-console', 'verdict-console-views']);

        // Published rather than loaded from the package: the host owns when its schema changes, and
        // a console table appearing on `migrate` without the host having asked for it is the kind of
        // surprise a security-adjacent package should never spring. Verdict publishes its own
        // migrations the same way.
        // Dated filenames are fixed, and the ordering between them is load-bearing: the operational
        // columns and the notifications table both require the pause table to exist. New migrations
        // are *added* here, never folded into an earlier one — a published migration has already run
        // for every adopter of the release that shipped it, so amending one changes new installs only
        // and silently divides the two.
        $this->publishesMigrations([
            __DIR__.'/../database/migrations/create_verdict_console_pending_approvals_table.php.stub' => database_path(
                'migrations/2026_08_21_000001_create_verdict_console_pending_approvals_table.php',
            ),
            __DIR__.'/../database/migrations/add_operational_state_to_verdict_console_pending_approvals_table.php.stub' => database_path(
                'migrations/2026_08_25_000001_add_operational_state_to_verdict_console_pending_approvals_table.php',
            ),
            __DIR__.'/../database/migrations/create_verdict_console_approval_notifications_table.php.stub' => database_path(
                'migrations/2026_08_25_000002_create_verdict_console_approval_notifications_table.php',
            ),
            __DIR__.'/../database/migrations/create_verdict_console_approval_reconciliations_table.php.stub' => database_path(
                'migrations/2026_08_25_000003_create_verdict_console_approval_reconciliations_table.php',
            ),
            __DIR__.'/../database/migrations/create_verdict_console_incidents_table.php.stub' => database_path(
                'migrations/2026_08_25_000004_create_verdict_console_incidents_table.php',
            ),
            __DIR__.'/../database/migrations/create_verdict_console_conversation_invocations_table.php.stub' => database_path(
                'migrations/2026_08_29_000001_create_verdict_console_conversation_invocations_table.php',
            ),
        ], ['verdict-console', 'verdict-console-migrations']);
    }
}
