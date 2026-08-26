# Changelog

All notable changes to Verdict Console will be documented in this file.

## [Unreleased]

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

[Unreleased]: https://github.com/fissible/verdict-console/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/fissible/verdict-console/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/fissible/verdict-console/releases/tag/v0.1.0
