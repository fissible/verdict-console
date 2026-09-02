<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Doctor;

/**
 * One code per precondition, because "your agent is misconfigured" is not an actionable finding.
 *
 * Every case here corresponds to a trap in design §12 that fails *silently or confusingly* at first
 * pause. The whole point of the doctor is to move each one to boot time, where a person is looking.
 */
enum FindingCode: string
{
    /** An attest writer requires exactly one fixed chain or chain resolver. */
    case ChainTopologyInvalid = 'chain_topology_invalid';

    /**
     * An Off recorder is an error until the host decides it is acceptable: the complaint ends by
     * decision, not dismissal, because an attest chain configured later cannot repair this gap.
     */
    case EvidenceRecordingUnacknowledged = 'evidence_recording_unacknowledged';

    /** A registered resolver key does not rebuild an agent. The preventive stage of design §6.3. */
    case ResolverKeyUnresolvable = 'resolver_key_unresolvable';

    /** Without the trait, durable recording silently no-ops and a cross-process resume cannot work. */
    case AgentDoesNotRememberConversations = 'agent_does_not_remember_conversations';

    /** The conversation tables are not migrated: nothing persists the paused turn. */
    case ConversationTablesMissing = 'conversation_tables_missing';

    /** Not auto-registered — without it `ApprovalExecutionContext::allows()` is false for every call. */
    case ApprovalMiddlewareMissing = 'approval_middleware_missing';

    /**
     * The approval loop still works, but Verdict cannot stamp `invocation_id` onto decision evidence.
     *
     * This is deliberately a **warning**: approvals remain usable, while the evidence correlation
     * surface goes dark. Detected by **identity, not behaviour**: this recognises a
     * `VerdictProvenanceMiddleware` instance and cannot prove that another middleware reproduces its
     * invocation-context behaviour. A clean result means the shipped middleware is present, not that
     * arbitrary host middleware records equivalent provenance.
     */
    case EvidenceCorrelationMiddlewareMissing = 'evidence_correlation_middleware_missing';

    /**
     * The approval loop is unaffected, but without this projection table correlation writes fail and
     * conversation-scoped evidence is unavailable.
     *
     * This is deliberately a **warning**: the evidence surface is what goes dark; an approval can
     * still be issued, decided, and resumed.
     */
    case EvidenceCorrelationTableMissing = 'evidence_correlation_table_missing';

    /** A resumable agent with no Verdict-bound tool can never produce a receipt-backed approval. */
    case AgentHasNoBoundTool = 'agent_has_no_bound_tool';

    /** The #230 dead gate: asks for confirmation, declares no execution target, never pauses. */
    case ConfirmationGateCannotPause = 'confirmation_gate_cannot_pause';

    /**
     * Verdict 0.12 refuses every approval decision until the host configures its own authorizer.
     *
     * Deliberately an **error** here where `verdict:validate` only warns, and that asymmetry is
     * correct rather than an oversight: Verdict warns because it cannot know whether an install has
     * confirmation-gated capabilities, and the console can — every console install has them by
     * definition, or there would be nothing to approve. Do not "align" this severity downward.
     */
    case ApprovalAuthorizerMissing = 'approval_authorizer_missing';

    /** The configured authorizer cannot become Verdict's required decision contract. */
    case ApprovalAuthorizerInvalid = 'approval_authorizer_invalid';

    /**
     * Verdict's test-only allow-all authorizer removes per-receipt authorization outside tests.
     *
     * Detected by **identity, not behaviour**: this recognises `AllowAllApprovalAuthorizer` itself
     * and cannot see a host's own authorizer that happens to `return true`. Nothing can inspect an
     * arbitrary implementation for that. Read a clean result as "not the shipped test double",
     * never as "this install authorizes selectively".
     */
    case ApprovalAuthorizerAllowsAll = 'approval_authorizer_allows_all';
}
