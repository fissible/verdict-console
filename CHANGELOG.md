# Changelog

All notable changes to Verdict Console will be documented in this file.

## [Unreleased]

- **Reviewer queue (#48).** The console now renders the scoped, poll-consistent review queue over
  Verdict 0.15's review substrate. `verdict-console.reviews.gate` (default
  `review-verdict-action`) and `verdict-console.reviews.scope` keep this separate from approval
  authority and refuse an unscoped list. Approve and reject record only through Verdict's review
  manager: they never mint a receipt, resume an agent, or execute a tool. The reader exposes no
  provenance, so that limitation remains recorded rather than fabricated by the surface.

- **Receipt-transition notifications (#46).** Decisions resolved through other clients and
  model-consumed receipts now notify from Verdict's observed transition event. `Consumed` is a new
  host-visible `ApprovalNotificationRecipients` routing key (`approval-consumed`).

- **Require Verdict `^0.15` — the review-lane release.** The 0.15 compatibility pass: the bound
  moves to the current minor per the standing prefer-lowest reasoning. What reaches this package:
  the new `add_review_outcome` evidence stub joins both fixture guards (the column is additive and
  `EvidenceRecord` does not expose it, so evidence surfaces are unchanged); the review-lane
  substrate (#297, ADR 0035) shipped upstream, so the README now points its reviewer-queue surface
  at #48 instead of calling the substrate planned. What does not: the #320/#436 decision-outcome
  reordering — the resolution service re-reads live status before deciding and keys on no
  refused-outcome string — and receipt retention pruning, whose pruned receipts read back as
  absent, which the `receipt_unavailable` state already renders honestly.

- **Evidence-sink posture read boundary (#105).** One console-owned, host-replaceable contract —
  `EvidenceSinkPosture::read(): SinkPosture` — now answers "what is the evidence sink and can this
  console read it": the effective writer (matching Verdict's own `EvidenceWriter` resolution,
  parity-tested), the honesty state including 0.8's chained sinks, the table and connection only
  while the sink is a readable table, and whether an attest chain is configured. The evidence read
  boundary consumes it instead of keeping a second config derivation. Named divergence, decided:
  an empty-string `writer`/`recorder` reads as unset for the posture (Verdict itself throws at
  first resolution rather than falling back — measured; the old reading named a nonexistent
  writer). Configuration proves selection only — the posture never implies recording is verified
  or complete.

## [0.8.0] - 2026-09-01

- **Fingerprint pivot filters (#102).** `EvidenceFilter` grows six nullable pivot fields over the
  opaque fingerprint vocabulary — `actorFingerprint`, `subjectFingerprint`, `argumentFingerprint`,
  `approvalReceiptFingerprint`, `configurationFingerprint`, `executionClaimFingerprint` — so a
  surface can ask "everything sharing this value". Exact equality only (ADR 0008: opaque values
  admit no honest prefix or pattern question), AND-composed with each other and every existing
  filter, honored by `search()` and `searchPage()` together — slice and total cut as one. The
  fields append after the existing six, so positional construction and replacement boundary
  implementations compile unchanged.

- **Chained-sink recording state (#104).** `EvidenceRecordingState::Chained`: an attest
  configuration now answers "a chained sink is configured; decisions are not readable from this
  table" — naming the fixed chain id or the resolver class configuration proves, and resolving
  nothing to learn it — instead of "On" over a table holding only chain-gap markers, which read
  as "nothing happened". The state claims configuration only: never that any append succeeded,
  that the chain verifies, or that no gap exists — those claims belong to the integrity ADR
  (#108).

## [0.7.0] - 2026-08-31

- **Paged evidence read (#99).** `EvidenceQuery::searchPage()` now offers volume-bound evidence
  surfaces a newest-first page and matching filtered total, while `search()` remains the complete
  projection used by the existing audit component.

## [0.6.0] - 2026-08-31

- **Require Verdict `^0.14` (#93).** The 0.14 compatibility pass: the bound moves to the current
  minor (the standing prefer-lowest reasoning — a disjunction would pin the lowest-dependency cell
  to a superseded floor). Nothing in 0.14 reaches this package's behaviour: `Decision::edit()` now
  throws `UnsupportedApprovalDecision` upstream, but the console only ever resumes with tool-call-id-keyed
  approve/reject and offers no widened decision shape; the new `verdict:validate` session-timezone
  audit is host-side tooling; verdict#306 (challenge contents) did not land, so the approval item
  read-model is untouched.

## [0.5.0] - 2026-08-30

- **Recommended approval-context scope (VC-69).** `ApprovalContextScope` implements the existing
  `ApprovalScope` contract keyed on the captured `approval_context`, with the same typed-exact
  containment as Verdict's ADR 0031 §3 — matching in PHP, mirroring verdict#327's portability
  decision, so no database backend's number/string coercion can widen it. Rows whose context is
  null or empty are never in scope, an empty scope is refused at construction, and every scoped
  read (`visible()`, `findVisible()`, `isVisible()`) honors the same rule — keeping what the
  console shows a person a subset of what Verdict's `pendingWithin()` would let them decide,
  proven against the real reader. Additive and host-opt-in: the contract is unchanged, arbitrary
  host scopes keep working, and the package default remains unscoped.

- **Durable retry for approved-but-unresumed reconciliations (VC-86).**
  `ApprovalResolutionService::retry()` re-drives a continuation whose decision Verdict already
  holds: the decision is re-read live through `ApprovalStatusReader` at retry time — never
  persisted for replay — and re-sent as the same tool-call-id-keyed continuation. An approved
  receipt executes its tool at most once (a consumed receipt refuses the retry, under every
  failure geometry); a rejected one resumes to a clean refusal; a pending receipt has no decision
  to re-send and is refused rather than auto-decided; Laravel AI's measured already-resolved
  answer is relayed as `RetryOutcome::AlreadyResumed`. Retry requires the recorded failed
  continuation, rides the existing `resume_attempts` counter, ignores (and preserves) an
  operator's abandonment note, and announces nothing on refusal.

- **Capture `approval_context` at ingestion (VC-68).** The pause row gains a nullable
  `approval_context` column (its own published migration — released migrations are never amended)
  holding the receipt's application-owned binding identifiers, read once at ingestion through
  `ApprovalStatusReader::statusFor()` and captured verbatim; Verdict documents the field as
  immutable after issue, so the one-time copy cannot go stale and mirrors no receipt status or
  expiry. An identifier-less issuance records `[]`; `null` is reserved for receipts predating
  capture. A host that composer-updates before running the migration still indexes every pause —
  the store omits the column it cannot write instead of failing ingestion.

- **Adopt Verdict's approval read contract (VC-45).** Approval status is now read through
  `ApprovalStatusReader` (verdict#298, ADR 0031): the inbox and chat interrupt render
  `already_decided` (with the persisted receipt status), `lapsed_undecided` (deadline compared by
  the console's clock), and `receipt_unavailable` distinctly where one collapsed
  expired-or-already-decided state stood, and the resolution service's actionability and `close`
  pre-checks read the same contract. `challengeForToolCall()` remains only for provenance
  disclosure and the ingestion-time drivability observation. A boundary test pins that no console
  code queries a Verdict table directly.

## [0.4.0] - 2026-08-30

- **Blade ops views (VC-22).** `<x-verdict-console::doctor />`,
  `<x-verdict-console::execution-claims />`, and `<x-verdict-console::incidents />` render the
  diagnostic findings, human-needed-first unresolved-claim queue, and durable incident ledger as
  read-only operations views, closing the v0.4.0 Blade scope.

- **Blade audit / evidence page (VC-20).** `<x-verdict-console::evidence />` renders the VC-13
  evidence boundary as a read-only, newest-first paginated audit table, preserving its recording and
  conversation-correlation states instead of reading configuration or Verdict tables directly.

- **Blade basic chat thread (VC-21).** `<x-verdict-console::chat />` posts through the new
  `verdict-console.chat.send` route, renders an owned thread without streaming, and places its
  conversation-scoped approval interrupt inline. Resolving that interrupt continues the thread on
  reload; the approvals widget now accepts a `conversation` prop for this scoped read.

- **Blade approval inbox widget (VC-19).** `<x-verdict-console::approvals />` renders the ADR 0001
  verb and provenance contract in its pending, expired-or-already-decided, and
  not-console-actionable states, and form-posts each offered verb through VC-6. Routes mount at
  boot by default, with opt-out through `VerdictConsoleRoutes::ignoreRoutes()` or
  `verdict-console.routes.register`; the boot mount is guarded under route:cache and the helper is
  idempotent. Unmounted rendering is retained for opted-out hosts. Views are publishable, and the
  package now requires `illuminate/view` and `illuminate/routing`.

- **Host chat-entry contract (VC-18).** `ChatEntry` lets a host name the participant and a
  resumable-agent key — a key rather than an agent instance, so VC-2 can rebuild a chat after a
  pause. The shipped entry refuses until `verdict-console.chat.entry_key` names a registered key.
  Foreign and unknown conversations receive the same ownership refusal; continuation deliberately
  uses the participant's current entry key, and thread messages are read through the host's
  `ConversationStore` rather than a console-owned message table.

## [0.3.0] - 2026-08-30

- **Execution-claim read model and authorized reconciliation (VC-16).** `ExecutionClaimService`
  lists Verdict's unresolved claims and resolves them through its `ExecutionClaimManager`; its
  fail-closed authority uses the host-configured `verdict-console.execution_claims.gate` ability.
  Still-active claims require an explicit force after investigation, each item carries Verdict's
  evidence-correlation fingerprint, and a successful call returns Verdict's own outcome rather
  than treating the requested resolution as fact.

- **Configuration inspection read-models (VC-17).** `ConfigurationInspection` now projects
  declared capabilities with Verdict's own fingerprint, rate limits, and approval rules for an
  operator surface. It is inspect-only because the fingerprint is recorded in every decision
  record: the displayed value can be matched against evidence rows'
  `configuration_fingerprint`, while a config write would change what already-recorded evidence
  means. No write path exists by design.

- **Warns when the evidence-correlation surface is unavailable (#72).**
  `verdict-console:doctor` now reports `evidence_correlation_middleware_missing` when a resumable
  agent lacks `VerdictProvenanceMiddleware`, and `evidence_correlation_table_missing` when the
  console's correlation migration has not run. They are warnings because approvals remain usable
  while conversation-scoped decision evidence goes dark; a host that depends on that surface can
  use `--strict` to make either finding fail its build. [#72](https://github.com/fissible/verdict-console/issues/72)

- **Requires Verdict `^0.13`** (#74). `v0.13.0` is the first release carrying
  `ApprovalStatusReader` (Verdict ADR 0031), the read contract the gated approval work was designed
  against, and it mints every evidence timestamp in UTC (verdict#335), which `DatabaseEvidenceQuery`
  previously had to state as an assumption. The bound is `^0.13` alone for the same reason `^0.12`
  was: a `0.x` disjunction would pin the `prefer-lowest` cell to a minor nobody runs. Nothing in this
  package needed to change for 0.13 itself; the evidence-query fixture now applies Verdict's new
  `add_intent_id_to_verdict_evidence_table` stub, and a test holds that fixture equal to every
  evidence-table stub the installed Verdict publishes, so the next additive column cannot leave the
  fixture behind the real table. **Upgrading:** require `fissible/verdict:^0.13` and publish and run
  Verdict's `add_intent_id_to_verdict_evidence_table` migration if you record evidence to the database.

- **Evidence-to-conversation correlation projection (VC-14).** The console now records each
  remembered Laravel AI `invocation_id` against its `conversation_id` and can scope the VC-13
  evidence query by either. `AgentPrompted` and `AgentStreamed` are the capture boundaries: approval
  events fire afterward in the same gateway call with the same response, so they would only
  re-observe the completed invocation. This requires the host to run
  `VerdictProvenanceMiddleware`, which places the invocation context Verdict reads when stamping
  decision evidence; without it the evidence-side join is empty. A conversation with no remembered
  invocation is returned as **Unknown**, never mistaken for empty evidence. [#72](https://github.com/fissible/verdict-console/issues/72)
  tracks doctor findings for a missing middleware or correlation-table migration. **Upgrading:**
  publish `verdict-console-migrations` and run the new migration; before then, the listener logs an
  error for each completed turn and continues, and conversations read as **Unknown**.

- **Names a Verdict authorization refusal without spending its receipt (#67).** When Verdict's
  required host `ApprovalDecisionAuthorizer` returns `unauthorized` after the console's own Gate
  permitted an approver, the console now raises the same non-disclosing authorization refusal as
  its Gate and scope boundaries, dispatches `ApprovalDecisionRefused`, and records each attempt in
  the incident ledger — one row per refused attempt, linked through `context` because the ledger's
  `(source, pending_approval_id)` unique index makes that column a one-per-row slot. The receipt
  remains pending and no Laravel AI continuation is attempted. A missing authorizer still surfaces
  as `ApprovalAuthorizerMissing` and a throwing one as its own exception: those are configuration
  and host defects, not refusals, and relabelling them would send an operator to the wrong place.

- **Evidence query contract and the shipped table adapter (VC-13).** `EvidenceQuery` is a
  console-owned, host-replaceable read boundary over Verdict's published decision-evidence table;
  it projects fingerprints plus `claimType`/`recordDigest`, never raw arguments, identifiers, or
  host-controlled reason text. Its result distinguishes recording **Off**, table-readable **On**,
  and **Elsewhere** (with the configured writer class), so an audit surface never mistakes an
  opt-out or a different sink for "nothing happened." The default adapter supports disposition,
  capability, and time filters and honors Verdict's `writer ?? recorder` configuration precedence.

- **Requires Verdict `^0.12`, and the doctor now catches its new failure mode at startup (#63).**
  The previous `^0.9.2` locked the console below `0.10.0` — caret pins the minor on a `0.x` — so an
  adopter on current Verdict could not install this package at all. The bound is `^0.12` **alone**
  rather than a disjunction: `prefer-lowest` tests only the bottom of a range, so `^0.9.2 || ^0.12`
  would have made 0.9.2 the lowest-dependency cell and CI would have silently stopped testing the
  version every adopter runs. Verdict 0.12 also makes `ApprovalDecisionAuthorizer` **required and
  fail-closed**, so without a host authorizer every decision now throws at the moment a person
  clicks approve. The console ships neither an authorizer nor a bridge (ADR 0001 §4); instead
  `verdict-console:doctor` gains three findings — `approval_authorizer_missing`,
  `approval_authorizer_invalid`, and `approval_authorizer_allows_all` — which resolve the configured
  class through the container rather than checking that a config string is non-empty. That catches
  **fail-open** as well: Verdict's test-only `AllowAllApprovalAuthorizer` configured outside
  `local`/`testing` authorizes every decision, which is the god-mode button §2 forbids reached from
  the other direction. **Upgrading:** require `fissible/verdict:^0.12`, configure
  `verdict.approvals.authorizer` (`php artisan verdict:make-approval-flow` publishes a working
  example), then run `php artisan verdict-console:doctor`.

- **ADR 0001 amended for Verdict 0.11–0.12 and ADR 0031** (console #63). Approval authority is now
  authorized **twice, by the host both times**: the console's `ApproverAuthority` is the actor
  binding (*may this person act on this row?*) and Verdict 0.12's required, fail-closed
  `ApprovalDecisionAuthorizer` is the request binding (*is this receipt theirs to finalize?*). The
  console ships neither an authorizer nor a bridge — the authorizer's `decidedBy` string is the
  console's own audit-label actor key, which nothing may parse back — and treats Verdict's
  `unauthorized` outcome as a named refusal (same exception and message as a Gate or scope refusal,
  plus an `ApprovalDecisionRefused` incident). `approval_context` is captured at ingestion as an
  immutable correlation annotation and is the substrate for a shipped `ApprovalContextScope`, added
  without narrowing the `ApprovalScope` contract. Records that #298 is settled as Verdict ADR 0031 but
  **not in a tagged release**, so VC-45 waits on Verdict's next release rather than the `^0.12`
  bound, and that the bound is `^0.12` alone so `prefer-lowest` tests a version people run.
  Design-only; the code follows on #6, #4/#5, #12, and #63.

## [0.2.0] - 2026-08-25

Operational state, reconciliation, and the surface contract every UI will render from — plus the two
host seams this release makes mandatory for anyone adopting notifications or multi-tenancy. This is
the v0.2.0 milestone (VC-9 … VC-12, VC-41, VC-43, VC-44); the dependency-ordered plan is in
`MILESTONES.md`.

**Upgrading:** three new migrations ship in this release — `add_operational_state_to_…`,
`create_verdict_console_approval_notifications_table`, and
`create_verdict_console_approval_reconciliations_table`. Re-run
`php artisan vendor:publish --tag=verdict-console-migrations` and `php artisan migrate`. The v0.1.0
create migration is untouched: a published migration has already run for every adopter, so anything
added after a release is a new file rather than an amendment.

- **Two host contracts, both shipping refusing or silent defaults.**
  `ApprovalNotificationRecipients` decides who may be told about an approval, and
  `ApprovalScope` constrains what a host's operators may see and act on. Neither has a working
  default, and that is the feature: the console has no tenant identifier, no operator directory, and
  no authority to derive either from a participant or a conversation. Until a host binds them,
  notifications go nowhere and no query is constrained — an installation adopts each deliberately
  rather than inheriting a guess. Both follow the existing pattern of `ResumableAgents` and
  `ConversationParticipants`.

- **Console-owned operational state (VC-9).** A `verdict_console_approval_notifications` table and
  two columns on the approval row — `resume_attempts`, `last_resume_attempt_at`. Notification
  idempotency is a unique index on `(pending_approval_id, notification_key)` rather than a
  check-then-act, and a claim is written **before** a send is attempted: recording only successes
  makes "died mid-send" indistinguishable from "never started", and the retry then sends twice. The
  resume counter is read back under a lock, because reconciliation decides what to do from *which*
  attempt this is, and an increment-then-read can hand a caller a concurrent writer's number. No
  column mirrors Verdict's receipt status or expiry, asserted against the schema.

- **Resume-failure reconciliation (VC-10).** When Verdict has recorded a decision and the
  continuation then fails, a `verdict_console_approval_reconciliations` row records it durably with
  one of **two** phases:
  `definitely_pre_execution` (raised before `prompt()`) and `indeterminate` (raised *by* `prompt()`,
  which executes the approved tools before handing results to the recorder — so nothing in Laravel
  AI's API says which side of execution the throw fell on). Mark-abandoned is idempotent, first
  detection wins including its phase, and a row needing two observations would need two records
  rather than a mutable field. **Durable retry is deliberately absent**: after `approve()`/`reject()`
  the receipt is no longer pending, `challengeForToolCall()` returns null by construction, and
  retrying would mean the console persisting the human's decision so it could be re-sent — a second
  copy of authorization state under another name. It waits on verdict
  [#298](https://github.com/fissible/verdict/issues/298).

- **One verb resolver every surface renders from (VC-41).** `ApprovalVerbs::resolve()` is the only
  place the rule lives: `{approve, reject}` iff the row is drivable **and** a live challenge exists
  **and** that challenge belongs to this tool call; `{close}` iff drivable with no live challenge;
  `{}` otherwise, including every `UnresumableReason`. `ApprovalVerb` has three cases and no others,
  so `approveAll()` and `Decision::edit()` are unreachable *by type* rather than by assertion.
  `ApprovalSurfaceContract` is the order-insensitive assertion every rendering surface must use —
  ADR 0001 names it as the test that fails when someone adds "approve anyway".

- **A non-authorizing exit for a lapsed approval (VC-43).** When a receipt lapses, Verdict does not
  auto-deny and Laravel AI has no expiry for a paused turn, so the measured end state is *receipt
  lapsed, conversation still paused, human never decided*. `close()` resumes that exact conversation
  with a tool-call-keyed rejection and never calls `ApprovalManager::approve/reject`; an expired
  close leaves the receipt `pending`. It returns a `CloseOutcome` — `Closed`, `AlreadyResolved`, or
  `DecisionStillAvailable` when a live challenge reappeared between render and click — because a
  void return would make that race indistinguishable from success. Shipped only after a
  real-gateway test measured what Laravel AI actually does against a non-pending turn.

- **Notifications from the console's own observation points (VC-11).** Verdict emits no
  receipt-transition events, so notices are published from ingestion, from the console's own returned
  transition, and from Laravel AI's `ToolApprovalResolved` — never by inferring a lifecycle from a
  null challenge. There is **no completion notice and no `consumed` notice**, because neither is
  observable: `challengeForToolCall()` returning null after a resume means expired, rejected,
  ambiguous, *or* absent, and reading receipt status directly would break the boundary. Notification
  faults cannot interrupt the run they observe — a delivery failure is recorded on the claim, never
  raised into the continuation.

- **Tenancy scoping the host owns (VC-12).** `ApprovalScope` receives the query and returns it
  constrained; the console stores no tenant column. Scope guards run **before** the Verdict
  transition and before any notification dispatch, so a row outside the host's boundary can neither
  spend its receipt nor cause a notification about itself. A refused scope raises the same
  `AuthorizationException` message as a refused approver, so membership cannot be probed by comparing
  errors. Scope constrains what a human may see or act on — **not** the console finding its own row:
  ingestion read-backs, the resume lock, and event correlation stay unscoped so a queue worker
  without tenant context can still record and correlate a pause.

- **The design of record reconciled with ADR 0001 (VC-44).** §5 narrows the console's table to a
  workflow and correlation index; §6.4 gains `close` and the fact that motivates it (expiry has no
  transition moment and Verdict never auto-rejects); §7 replaces the `can('approve', …)` shorthand
  with the configured ability; §14 replaces "companion Verdict issue?" with the filed cluster
  verdict #297–#300. The `DefaultApprovalPresenter` doc block no longer describes rendering
  provenance live as a fresh context release — `ApprovalManager::issue()` already applied or refused
  that release while the invocation frame existed.


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

[Unreleased]: https://github.com/fissible/verdict-console/compare/v0.8.0...HEAD
[0.8.0]: https://github.com/fissible/verdict-console/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/fissible/verdict-console/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/fissible/verdict-console/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/fissible/verdict-console/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/fissible/verdict-console/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/fissible/verdict-console/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/fissible/verdict-console/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/fissible/verdict-console/releases/tag/v0.1.0
