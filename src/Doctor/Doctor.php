<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Doctor;

use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer;
use Fissible\Verdict\LaravelAi\BoundTool;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\Verdict\LaravelAi\VerdictProvenanceMiddleware;
use Fissible\Verdict\Testing\AllowAllApprovalAuthorizer;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Exceptions\ResumableAgentFailure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Laravel\Ai\Concerns\RemembersConversations as RemembersConversationsTrait;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;

/**
 * Moves every silent precondition to boot time.
 *
 * Each check here corresponds to a design §12 trap that otherwise fails at the first pause — and
 * several of them fail *silently* there, which is why they cost real time to diagnose. A dead
 * confirmation gate simply never appears in the inbox; a missing middleware turns an approved
 * receipt into `invalid_state`; a missing conversation trait makes the resume a no-op. None of those
 * announces itself.
 *
 * **Class-level only.** Whether a *particular* paused run can be resumed depends on what was
 * captured at pause time and is validated at ingestion (VC-5). This answers the different question:
 * is this wiring capable of working at all?
 */
final readonly class Doctor
{
    public function __construct(
        private ResumableAgents $agents,
        private CapabilityRegistry $capabilities,
        private SchemaBuilder $schema,
        private Config $config,
        private Application $app,
    ) {}

    /**
     * Every finding, worst first.
     *
     * @return list<Finding>
     */
    public function run(): array
    {
        $findings = [
            ...$this->inspectPersistence(),
            ...$this->inspectEvidenceCorrelation(),
            ...$this->inspectApprovalAuthorizer(),
            ...$this->inspectAgents(),
            ...$this->inspectCapabilities(),
        ];

        usort($findings, fn (Finding $a, Finding $b): int => [$a->severity === Severity::Warning, $a->subject]
            <=> [$b->severity === Severity::Warning, $b->subject]);

        return $findings;
    }

    /** @return list<Finding> */
    private function inspectApprovalAuthorizer(): array
    {
        $authorizer = $this->config->get('verdict.approvals.authorizer');

        if (! is_string($authorizer) || $authorizer === '') {
            return [new Finding(
                code: FindingCode::ApprovalAuthorizerMissing,
                severity: Severity::Error,
                subject: 'verdict.approvals.authorizer',
                summary: 'Verdict 0.12 refuses every approval and rejection decision when no approval decision '
                    .'authorizer is configured (fail-closed), so a person reaches a broken action only after '
                    .'the console has allowed them to click it.',
                fix: 'Configure the application\'s ApprovalDecisionAuthorizer at verdict.approvals.authorizer. '
                    .'Run verdict:make-approval-flow to publish Verdict\'s working example, then adapt it to '
                    .'the application\'s receipt ownership rules.',
            )];
        }

        try {
            // Inside the try because class_exists() autoloads: a ParseError or a failing
            // side-effectful include in the host's authorizer file would otherwise escape the
            // doctor entirely, and a diagnostic that dies on the thing it is diagnosing is worse
            // than no diagnostic.
            if (! class_exists($authorizer)) {
                return [$this->invalidApprovalAuthorizer("The configured approval decision authorizer [{$authorizer}] does not exist.")];
            }

            $resolved = $this->app->make($authorizer);
        } catch (\Throwable $error) {
            return [$this->invalidApprovalAuthorizer(
                "The configured approval decision authorizer [{$authorizer}] could not be resolved: {$error->getMessage()}",
            )];
        }

        if (! $resolved instanceof ApprovalDecisionAuthorizer) {
            return [$this->invalidApprovalAuthorizer(
                "The configured approval decision authorizer [{$authorizer}] must implement ".ApprovalDecisionAuthorizer::class.'.',
            )];
        }

        if ($resolved instanceof AllowAllApprovalAuthorizer && ! $this->app->environment(['local', 'testing'])) {
            return [new Finding(
                code: FindingCode::ApprovalAuthorizerAllowsAll,
                severity: Severity::Warning,
                subject: 'verdict.approvals.authorizer',
                summary: 'Verdict\'s test-only AllowAllApprovalAuthorizer authorizes every decision. Outside '
                    .'local and testing this removes the per-receipt authorization the host must provide.',
                fix: 'Configure the application\'s own ApprovalDecisionAuthorizer that verifies the receipt '
                    .'belongs to a conversation the decision maker may decide.',
            )];
        }

        return [];
    }

    private function invalidApprovalAuthorizer(string $summary): Finding
    {
        return new Finding(
            code: FindingCode::ApprovalAuthorizerInvalid,
            severity: Severity::Error,
            subject: 'verdict.approvals.authorizer',
            summary: $summary,
            fix: 'Configure verdict.approvals.authorizer as a container-resolvable class implementing '
                .ApprovalDecisionAuthorizer::class.'.',
        );
    }

    /**
     * @return list<Finding>
     *
     * Checks the tables rather than the binding. `ConversationStore` is bound by Laravel AI's own
     * service provider, which this package hard-depends on, so "is it bound" can never be false and
     * would be a check that always passes. What actually breaks a host is publishing the package and
     * never running its migrations: the binding resolves, the writes fail, and the paused turn is
     * simply never persisted.
     *
     * **Both tables, not just the conversations one.** Laravel AI's single migration creates two,
     * and the *messages* table is the one that matters most here: it stores the paused assistant
     * turn — its `tool_calls` and `approval_state` — and the approval results a resume records. A
     * host with only the conversations table migrated would pass a one-table check and then fail at
     * the moment of the pause, which is precisely the silent setup failure this command exists to
     * catch.
     */
    private function inspectPersistence(): array
    {
        $missing = array_values(array_filter(
            [$this->conversationTable('conversations'), $this->conversationTable('messages')],
            fn (string $table): bool => ! $this->schema->hasTable($table),
        ));

        if ($missing === []) {
            return [];
        }

        return [new Finding(
            code: FindingCode::ConversationTablesMissing,
            severity: Severity::Error,
            subject: implode(', ', $missing),
            summary: 'Laravel AI\'s conversation tables are not fully migrated, so a paused turn is never '
                .'persisted and no resume can reconstruct the pending tool call. The messages table in '
                .'particular holds the paused assistant turn and its approval state, so a run can pause '
                .'and be lost even when the conversations table exists.',
            fix: 'Publish and run Laravel AI\'s conversation migration, which creates both tables.',
        )];
    }

    /**
     * The table, not the listener binding. The listener is registered by this package's own service
     * provider and cannot be unbound, so a binding check would always pass. What breaks a host is
     * publishing the package and never migrating it: the listener resolves, but its projection write fails.
     *
     * **The default connection, not Verdict's evidence connection.** This projection is console-owned
     * and written by the listener on the application's default connection.
     * `verdict.evidence.connection` is where Verdict's evidence lives and where the read adapter looks;
     * checking it would pass on the wrong database.
     *
     * @return list<Finding>
     */
    private function inspectEvidenceCorrelation(): array
    {
        if ($this->schema->hasTable('verdict_console_conversation_invocations')) {
            return [];
        }

        return [new Finding(
            code: FindingCode::EvidenceCorrelationTableMissing,
            severity: Severity::Warning,
            subject: 'verdict_console_conversation_invocations',
            summary: 'The conversation-invocation correlation table is not migrated, so the correlation '
                .'listener logs an error for every completed turn and every conversation-scoped evidence '
                .'query reads as Unknown until it exists.',
            fix: 'Run php artisan vendor:publish --tag=verdict-console-migrations, then php artisan migrate.',
        )];
    }

    /** @param  'conversations'|'messages'  $which */
    private function conversationTable(string $which): string
    {
        $default = $which === 'conversations' ? 'agent_conversations' : 'agent_conversation_messages';
        $configured = $this->config->get('ai.conversations.tables.'.$which, $default);

        return is_string($configured) && $configured !== '' ? $configured : $default;
    }

    /** @return list<Finding> */
    private function inspectAgents(): array
    {
        $findings = [];

        foreach ($this->agents->keys() as $key) {
            try {
                $agent = $this->agents->resolve($key);
            } catch (ResumableAgentFailure $e) {
                // The preventive stage of design §6.3: the only point at which an unresumable agent
                // can be caught before a run is already paused and waiting on it.
                $findings[] = new Finding(
                    code: FindingCode::ResolverKeyUnresolvable,
                    severity: Severity::Error,
                    subject: $key,
                    summary: 'This resolver key does not rebuild an agent, so any run it paused cannot '
                        .'be resumed: '.$e->getMessage(),
                    fix: 'Repair the resolver registered for this key, or retire the key once no pending '
                        .'approval still references it.',
                );

                continue;
            }

            $findings = [...$findings, ...$this->inspectAgent($key, $agent)];
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function inspectAgent(string $key, Agent $agent): array
    {
        $findings = [];
        $subject = $agent::class;

        // Design §3 names two conversation preconditions. Only one of them is checked here, and the
        // reason is worth stating: `ResumableAgents::resolve()` returns `Agent&RemembersConversations`,
        // so a non-`Conversational` agent can never reach this method — VC-2 refuses it first, and it
        // surfaces above as an unresolvable key whose message names the fix. Re-checking it here
        // would be an unreachable branch pretending to be a safeguard.
        //
        // The *trait* is a different matter: an agent can satisfy the contract by hand and still lack
        // the trait, which is the silent failure — durable recording no-ops and nothing raises.
        if (! in_array(RemembersConversationsTrait::class, class_uses_recursive($agent), true)) {
            $findings[] = new Finding(
                code: FindingCode::AgentDoesNotRememberConversations,
                severity: Severity::Error,
                subject: $subject,
                summary: 'Without the RemembersConversations trait the provider silently skips durable '
                    .'approval recording, so a resume driven from another process has nothing to '
                    .'reconstruct. Nothing raises; the resume simply does not happen.',
                fix: 'Use the Laravel\Ai\Concerns\RemembersConversations trait on this agent.',
            );
        }

        if (! $agent instanceof HasMiddleware || ! $this->declaresApprovalMiddleware($agent)) {
            $findings[] = new Finding(
                code: FindingCode::ApprovalMiddlewareMissing,
                severity: Severity::Error,
                subject: $subject,
                summary: 'VerdictApprovalMiddleware is not auto-registered. Without it '
                    .'ApprovalExecutionContext::allows() is false for every tool call, and an approved '
                    .'receipt fails proposal-validation with invalid_state.',
                fix: 'Implement Laravel\Ai\Contracts\HasMiddleware and return '
                    .'app(VerdictApprovalMiddleware::class) from middleware().',
            );
        }

        if (! $agent instanceof HasMiddleware || ! $this->declaresProvenanceMiddleware($agent)) {
            $findings[] = new Finding(
                code: FindingCode::EvidenceCorrelationMiddlewareMissing,
                severity: Severity::Warning,
                subject: $subject,
                summary: 'VerdictProvenanceMiddleware is not registered, so decision evidence rows carry a '
                    .'null invocation_id and a conversation-scoped EvidenceQuery answers Known with zero '
                    .'records — indistinguishable from "this conversation decided nothing".',
                fix: 'Implement Laravel\Ai\Contracts\HasMiddleware and return a '
                    .'VerdictProvenanceMiddleware from middleware() alongside the approval middleware.',
            );
        }

        if (! $this->hasBoundTool($agent)) {
            $findings[] = new Finding(
                code: FindingCode::AgentHasNoBoundTool,
                severity: Severity::Warning,
                subject: $subject,
                summary: 'This agent is registered as resumable but has no Verdict-bound tool, so it can '
                    .'never raise a receipt-backed approval. Nothing breaks; the registration does '
                    .'nothing.',
                fix: 'Bind at least one tool through Verdict::bound(), or unregister the resolver key ['
                    .$key.'].',
            );
        }

        return $findings;
    }

    /** @return list<Finding> */
    private function inspectCapabilities(): array
    {
        $findings = [];

        foreach ($this->capabilities->all() as $capability) {
            // The #230 dead gate. Checked over the whole registry rather than per agent, because a
            // capability that can never pause is broken regardless of which agent reaches it.
            if ($capability->confirmationRequired() && $capability->executionTargetPolicy() === null) {
                $findings[] = new Finding(
                    code: FindingCode::ConfirmationGateCannotPause,
                    severity: Severity::Error,
                    subject: $capability->name,
                    summary: 'This capability asks for confirmation but declares no execution-target '
                        .'policy, so requestConfirmation() returns null: it never pauses, never reaches '
                        .'the approval inbox, and is denied at execution without a human being asked.',
                    fix: 'Declare an executionTarget() policy on this capability, or remove '
                        .'requiresConfirmation().',
                );
            }
        }

        return $findings;
    }

    private function declaresApprovalMiddleware(HasMiddleware $agent): bool
    {
        foreach ($agent->middleware() as $middleware) {
            if ($middleware instanceof VerdictApprovalMiddleware) {
                return true;
            }
        }

        return false;
    }

    private function declaresProvenanceMiddleware(HasMiddleware $agent): bool
    {
        foreach ($agent->middleware() as $middleware) {
            if ($middleware instanceof VerdictProvenanceMiddleware) {
                return true;
            }
        }

        return false;
    }

    private function hasBoundTool(Agent $agent): bool
    {
        if (! $agent instanceof HasTools) {
            return false;
        }

        foreach ($agent->tools() as $tool) {
            if ($tool instanceof BoundTool) {
                return true;
            }
        }

        return false;
    }
}
