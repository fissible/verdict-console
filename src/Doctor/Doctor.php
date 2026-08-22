<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Doctor;

use Fissible\Verdict\Capabilities\CapabilityRegistry;
use Fissible\Verdict\LaravelAi\BoundTool;
use Fissible\Verdict\LaravelAi\VerdictApprovalMiddleware;
use Fissible\VerdictConsole\Contracts\ResumableAgents;
use Fissible\VerdictConsole\Exceptions\ResumableAgentFailure;
use Illuminate\Contracts\Config\Repository as Config;
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
            ...$this->inspectAgents(),
            ...$this->inspectCapabilities(),
        ];

        usort($findings, fn (Finding $a, Finding $b): int => [$a->severity === Severity::Warning, $a->subject]
            <=> [$b->severity === Severity::Warning, $b->subject]);

        return $findings;
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
