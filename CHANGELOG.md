# Changelog

All notable changes to Verdict Console will be documented in this file.

## [Unreleased]

- **`release.sh` can cut a first release.** The script (and `scripts/prepare-release-changelog.php`)
  were derived from Verdict's, which assumed a release history: a previous tag, a release section
  after Unreleased, and an existing `[Unreleased]: …/compare/…` footer. A repository with none of
  those — every freshly scaffolded package, including the coming `verdict-console-livewire` and
  `-filament` — died at `A previous release tag is required`, which is why `0.1.0` here was
  bootstrapped by hand. The script now follows the org script's semantics: with no tag it offers to
  release the version already in `VERSION` with no bump, and the preparer accepts an empty previous
  tag, an Unreleased-only changelog, and creates the link footer from `composer.json`'s `homepage`
  (falling back to the `origin` remote). Pinned by a new `tests/Unit` suite that runs the real
  `release.sh` against a throwaway repository with a bare origin and declines the push.

## [0.1.0] - 2026-08-24

The headless approval round trip. A Verdict `require_confirmation` pause is ingested into the
console, a human decision is authorized by the host and turned into exactly one Laravel AI
continuation, and the tool executes at most once behind a single-use receipt — driveable with no UI.
Everything below is the v0.1.0 milestone (VC-1 … VC-8); the dependency-ordered plan is in
`MILESTONES.md`.

- **Specified the approval-surface contract** in
  [`docs/adr/0001-approval-surface-contract.md`](docs/adr/0001-approval-surface-contract.md): which
  Verdict dispositions become a human-actionable item (`require_confirmation` — synchronous, against
  a paused run; `require_review` — asynchronous, record-only), which are informational
  (`permit`, `throttle`), and which never appear (`deny`). The load-bearing invariant is that **a
  Deny is not approvable** — no override, no edit-and-retry, no wildcard — recorded as a first-class
  rejected alternative so it stays rejected. Also settles expiry behaviour per lane (no auto-reject,
  consistent with Verdict ADR 0029), that approving is a Gate-permissioned act producing a
  single-use argument-bound receipt, and that the ADR 0026 payload (reason, expiry, provenance
  disclosure state) is rendered live from the challenge rather than persisted. Design-only; the
  Verdict-side gaps it needs — chiefly that `require_review` has no runtime substrate — are filed as
  verdict [#297](https://github.com/fissible/verdict/issues/297)–[#300](https://github.com/fissible/verdict/issues/300)
  and designed against as Proposed-contract dependencies, not built against.

- **Resolution bridge (VC-6).** `ApprovalResolutionService::approve()` / `reject()` turn one
  authorized human decision into one exact continuation: the host's `ApproverAuthority` is consulted
  first, the receipt is transitioned through `ApprovalManager::approve/reject` under the approver's
  actor key, and the run resumes **only** on an `Approved`/`Rejected` outcome — expired, not-found,
  mismatched, and race-lost outcomes never resume. The resume is `continue($conversationId,
  $participant)` with a tool-call-id-keyed decision, never `continueLastConversation()` and never a
  wildcard. A row the console already knows it cannot drive is refused before its live receipt is
  spent (`ApprovalNotDrivable`); a resume that fails *after* Verdict recorded the decision is raised
  as `ApprovalResumeFailed` for reconciliation rather than retried or guessed at.

- **Disposition bridge (VC-5).** `IngestToolApprovalRequests` listens for Laravel AI's
  `ToolApprovalRequested` and records one `PendingApproval` per pending call, reading authoritative
  state through `ApprovalManager::challengeForToolCall()` and never the receipt store. Ingestion is
  **detective, never a refusal**: a pause the console cannot drive is still written, marked
  `unresumable` with the first failed drivability check recorded durably as `UnresumableReason`
  (`challenge_unavailable` · `agent_unresolvable` · `conversation_absent` ·
  `participant_unresolvable`), and announced by an `ApprovalIngestionIncident` carrying the same
  value. A null challenge is deliberately one state — absent, ambiguous, non-pending and expired are
  indistinguishable through the public API and are not classified past what was observed. The host
  presenter is a disclosure seam, not a drivability condition, and its output is normalized to
  JSON-native values before storage. A failed row write is logged as critical and is explicitly a lost
  pause; a receipt collision is critical in its own right.

- **`PendingApproval` index (VC-4).** The console's queryable index of paused approvals: a surrogate
  key, a nullable unique-when-present `receipt_id`, correlation columns (`tool_call_id`,
  `conversation_id`, `participant_reference`, `invocation_id`), the host's `resolver_key`, the
  display-safe `presentation`, `resumability`, and `unresumable_reason`. Ingest idempotency is a
  deterministic non-null `ingest_key` under a unique index, first-write-wins, proven under concurrent
  redelivery. **No expiry column** — receipt TTL stays Verdict's and is read live. Published with
  `--tag=verdict-console-migrations`.

- **Approver-authority contract (VC-7).** `ApproverAuthority` answers who may decide an approval and
  what audit label the decision is recorded under. The shipped `GateApproverAuthority` checks the
  host's Gate ability (`verdict-console.approvals.gate`, default `approve-verdict-action`) against
  the row, **fails closed** on a fresh install, refuses an unauthenticated approver before the Gate is
  consulted, and passes the bare auth identifier to Verdict as `approvedBy`/`rejectedBy` — an audit
  label, never an identity to reconstruct.

- **Approval presentation contract (VC-8).** `ApprovalPresenter` projects a Laravel AI pending
  approval into the only data the console may persist for display. The shipped
  `DefaultApprovalPresenter` records tool, capability, reason and a one-way argument fingerprint —
  never raw arguments, never a copy of the receipt's expiry, never provenance (a context release
  governed by Verdict ADR 0026 / ADR 0008 that a host discloses through its own presenter).

- **Resumable-agent contract (VC-2).** `ResumableAgents` (`keyFor(Agent): string` /
  `resolve(string): Agent`) with an `AgentResolverRegistry`, because class + participant cannot
  rebuild an agent that needs runtime constructor input, tenant context, or a specific
  provider/model. The key is captured at pause time and revalidated at ingestion.
  `ConversationParticipants` is the matching host seam for Laravel AI's non-durable participant
  object; the package ships `UnconfiguredConversationParticipants`, which refuses rather than
  guessing an identity convention.

- **Preflight doctor (VC-3).** `verdict-console:doctor` (`--strict` treats warnings as failures),
  backed by a structured `Finding` model a UI can render later. Class-level checks for each silent
  trap in design §12: a resolver key that does not resolve, an agent without
  `RemembersConversations`, missing conversation tables, `VerdictApprovalMiddleware` not registered,
  an agent with no Verdict-bound tool, and the verdict#230 dead gate — a capability that asks for
  confirmation, declares no execution target, and therefore never pauses.

- **Hermetic end-to-end suite (VC-1).** A real (non-fake) Laravel AI gateway driven by `Http::fake()`
  sequences — no network, no credentials — proves pause → approve → resume → execute **exactly once**,
  deny → clean refusal, and the negative controls the design's hazards demand, including that a
  participant-bound pause resumed with a null attachment runs the action, spends the receipt, and
  leaves the turn still pending (which is why ingestion checks participant identity up front).

- **Scaffolded the package.** Skeleton, CI (`tests.yml`) and release wiring per the fissible
  convention, and the design of record (`docs/design/0001-verdict-console-design.md`) for the
  human-in-the-loop approval runtime and operator UI over Laravel AI + Verdict.

[Unreleased]: https://github.com/fissible/verdict-console/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/fissible/verdict-console/releases/tag/v0.1.0
