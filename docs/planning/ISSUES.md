# Verdict Console — issue plan

Every issue is agent-pickup-ready: a self-contained unit with scope, **testable** acceptance criteria,
and references into the design of record ([`../design/0001-verdict-console-design.md`](../design/0001-verdict-console-design.md))
and the real Verdict / Laravel AI code. Milestones are **minor releases**, each independently
shippable as a cumulative release. This plan was reconciled with an independent code-grounded review;
the non-obvious constraints it surfaced are called out inline.

Effort: XS (<1h) · S (1–2h) · M (~half day) · L (~1 day) · XL (2–3 days).
Labels: `area:runtime|evidence|notifications|blade|livewire|filament` · `type:feature|test|contract|docs` · `milestone:vX.Y.0` ·
`blocked:verdict` (cannot start until the named `fissible/verdict` issue ships; such issues sit in the
`verdict-gated` milestone until then).

**Release train — core package `fissible/verdict-console`:**

| Milestone | Theme | Ships |
| --- | --- | --- |
| v0.1.0 | Headless approval round trip | Pause → approve → resume → execute-once, driveable with no UI, with the authorization + presentation contracts the loop needs |
| v0.2.0 | Production-grade workflow | Notification idempotency, resume-failure reconciliation, notifications, tenancy scoping |
| v0.3.0 | Evidence & health projections | Evidence query contract, correlation + incident ledger, execution-claim + config read-models |
| v0.4.0 | Blade surfaces | Embeddable inbox, audit page, basic chat, ops views + the host chat-entry contract |

**Adapter packages (own repos / version streams), after core v0.4.0:** `verdict-console-livewire` v0.1.0,
`verdict-console-filament` v0.1.0.

---

## Milestone v0.1.0 — Headless approval round trip

Build the ugly skeleton first (VC-1), then formalize each piece with it kept green. Everything here is
headless: an app drives the full loop through services, no UI.

### VC-1 · Walking-skeleton spike + hermetic E2E · L · `type:test` `area:runtime`
**Deps:** none (do first). **Context:** the pause→approve→resume round trip is where the design can be
*wrong*, not merely late (design §11).
**Scope:**
- One integration test driving a Verdict `BoundTool` confirmation → pause → resolve → resume → the tool
  executes **exactly once**, plus a deny path returning a clean refusal (no hang).
- **Hermetic:** a real (non-fake) Laravel AI gateway driven by `Http::fake()` sequences — no network, no
  credentials (`Agent::fake()` never resumes, design §12; a bare "real gateway" is not CI-testable).
- May be ugly/inline; its job is to force every §12 hazard into the open.
**Acceptance:** green E2E in CI with no network; asserts exactly-once execution and the deny refusal;
once VC-6 exists, both paths go through the package's public resolution service; §12 hazards it exposed
are listed in the PR for VC-4…VC-6 to inherit.
**Refs:** design §11, §12.

### VC-2 · Resumable-agent contract (`keyFor` + `resolve`) · M · `type:contract` `area:runtime`
**Deps:** none. **Context:** `ToolApprovalRequested` supplies an `Agent` *instance*, not a key; class +
participant can't rebuild an agent needing runtime constructor input / tenant / provider-model (design
§6.1).
**Scope:** a host contract with **both** directions — `keyFor(Agent $agent): string` (stable key derived
at pause time) and `resolve(string $key): Agent` (reconstruct) — a registry/binding point, stable-key
validation, and a documented example.
**Acceptance:** `keyFor` → `resolve` round-trips to an equivalent agent; an unknown/failing key raises a
distinct, catchable error (consumed at ingestion by VC-5); unit-tested.
**Refs:** design §6.1, §6.3; `vendor/laravel/ai/src/Events/ToolApprovalRequested.php`.

### VC-3 · Preflight doctor (structured findings) · M · `type:feature` `area:runtime`
**Deps:** VC-2. **Context:** every §12 silent trap should fail at preflight, not first pause.
**Scope:** a `verdict-console:doctor` command **backed by a reusable structured findings model** (so a
UI can render it later — VC-22). For a host-declared configured agent it checks: uses a Verdict
`BoundTool`; is `Conversational` (else Laravel AI throws `ApprovalNotResumableException`); uses
`RemembersConversations` + a bound `ConversationStore`; `VerdictApprovalMiddleware` registered via
`HasMiddleware`; confirmable capabilities have `executionTarget()` (the #230 dead gate). Class-level
checks only — per-invocation resumability is validated at ingestion (VC-5), not here.
**Acceptance:** each missing precondition yields a distinct finding naming the fix; findings are returned
as data (not only printed); tests cover present/absent for each.
**Refs:** design §3, §8, §12; verdict `src/Console/Commands/ValidateVerdictCommand.php`.

### VC-4 · `PendingApproval` model + migration + store · M · `type:feature` `area:runtime`
**Deps:** VC-1, VC-2, VC-8. **Context:** the console's own table is the queryable index (design §5,
§6.1).
**Scope:**
- Model + published migration (tag `verdict-console-migrations`). **Surrogate PK**; `receiptId`
  **nullable, unique-when-present**; correlation columns `toolCallId`, `conversationId` (nullable),
  `conversationUser`, `invocationId`; the VC-2 resolver key; the VC-8 presentation summary.
- **Ingest idempotency via a non-null deterministic `ingest_key`** (e.g. hash of `toolCallId` + a
  null-conversation sentinel) with a **unique index** — SQL nullable-composite uniqueness does not
  enforce idempotency because NULLs don't compare equal.
- **No expiry column** — receipt TTL stays Verdict's (design §6.1).
**Acceptance:** idempotency proven under **concurrent/redelivery** insert (not just sequential upsert);
unique-when-present `receiptId` enforced; migration publishes.
**Refs:** design §5, §6.1.

### VC-5 · Disposition bridge — `ToolApprovalRequested` → rows · L · `type:feature` `area:runtime`
**Deps:** VC-2, VC-4. **Context:** the pause signal is Laravel AI's, and not every approval has a
receipt (design §3, §4, §6.3).
**Scope:** listener on `ToolApprovalRequested`. For each pending item, call
`ApprovalManager::challengeForToolCall($toolCallId)` — **not** the store's `findForToolCall()`, which
would break the §5 boundary — taking the receipt id from the challenge.
**A challenge** → a Verdict-backed row.
**Null** → a non-drivable row (`receiptId` null) with `unresumable_reason` `challenge_unavailable`,
plus an `ApprovalIngestionIncident` carrying the same typed cause. The cause names **which check came
back empty, not why**, and is recorded as unknown wherever surfaced. Null covers absent, ambiguous, non-pending *and* expired, and **no public
`ApprovalManager` datum distinguishes them** — its other methods either mutate or require an
`Evaluation` the console never holds, and reaching for `findForToolCall()` to tell them apart is the
boundary §5 forbids. So do **not** classify, imply a cause, or branch differently for one of them: an
incident naming a cause the code could not determine sends an operator to the wrong place. Never
dropped, never crashed. Distinguishing them needs a new Verdict read contract (`MILESTONES.md`; this
is its second independent consumer).
**The incident is an event, ephemeral until VC-15.** One `ApprovalIngestionIncident` carrying a typed
`UnresumableReason` (`challenge_unavailable` | `agent_unresolvable` | `conversation_absent` |
`participant_unresolvable` — the four drivability conditions), plus a **default warning-log listener**. It is not durable history and must
not be documented as such: VC-15 projects it alongside Verdict's four anomaly events, and until then
the row's `unresumable_reason` (VC-4) is the only record that survives a restart. A row failing more
than one check records the first in that order; the event carries the same value.
Capture correlation + the VC-8 presentation summary; **resolve & validate the VC-2 key here —
detectively, never as a refusal**: a row whose agent can't be reconstructed is still written, marked
`unresumable`, recorded as an incident, and handed to the host's recovery protocol.
Capture `participantReference` through the host `ConversationParticipants` contract; never persist
Laravel AI's live participant object or invent a class-plus-id convention. The package ships no
working default — `UnconfiguredConversationParticipants` refuses — so a participant-bound pause is
`unresumable` until a host binds one, and the opaque reference must round-trip to the same Laravel AI
participant type/key.
`ToolApprovalRequested` fires *after* the run paused, so refusing the row undoes nothing and hides an
already-stranded run. Startup preflight (VC-3) is the preventive stage; ingestion is detective.
**Drivable requires four conditions**: a challenge **and** a resolving VC-2 key **and** a
`conversationId` **and**, when supplied, a participant reference that rebuilds to the same Laravel AI
type/key — `continue()` takes a string and the conversation store also matches approval results by
participant, so a conversationless or participant-mismatched pause is `unresumable` regardless.
**Acceptance:** tests for the challenge branch and the null branch, the latter driven by *at least two*
distinct causes (no receipt at all, and a receipt expired between issue and ingestion) that must produce
the **same** row state and the **same** `challenge_unavailable` cause — the test that fails if someone
later manufactures a classification. Each unresumable row persists its `unresumable_reason` and
dispatches exactly one `ApprovalIngestionIncident` with the matching cause; a drivable row persists
neither. A conversationless pause is `unresumable` even with a challenge and
a resolving key; an unresolvable agent key still produces a row — `unresumable` plus an incident, never
a refusal. A presenter failure stores a null presentation without changing drivability — including a
presenter that returns cleanly but whose host-owned `details` cannot be JSON-encoded, which must not
reach the store's `JSON_THROW_ON_ERROR` and be mistaken for a malformed item. The projection is
**normalized** at the bridge, not merely validated: `details` admits `JsonSerializable`, so handing the
original array on would let the store's encode re-invoke host code that a first encode had already
satisfied. Test the stateful case — serializable once, unencodable on a second call — and require the
row to survive with its presentation intact; a throwing
matcher/factory is recorded as `agent_unresolvable`; a participant reference is captured through the
host seam and a missing, throwing, or identity-mismatched round trip is `participant_unresolvable`; a
malformed item cannot prevent sibling approvals in the same event from ingesting. A
failed row write is logged as critical and is explicitly a lost pause (no row or incident); a receipt
collision is separately critical rather than filed as malformed input. **An end-to-end negative
control pins the upstream rule itself** — a participant-bound pause resumed via
`continue($conversationId, null)` raises `ApprovalMismatchException` *after* executing the action and
spending the receipt, leaving the turn still pending — so the fourth condition is measured, not
inferred, and this test fails if laravel/ai ever relaxes the participant filter.
**Refs:** design §3, §6.3; verdict `src/Contracts/ApprovalReceiptStore.php`, `src/Approvals/DatabaseApprovalReceiptStore.php:70`.

### VC-6 · Resolution bridge — approve/reject → receipt → resume · L · `type:feature` `area:runtime`
**Deps:** VC-4, VC-5, VC-7. **Context:** a human decision drives the run to completion (design §6.4).
**Scope:**
- `approve` / `reject` service methods: authorize via VC-7 (host Gate + `actorKey` **string** — Verdict's
  `approve/reject` take `approvedBy`/`rejectedBy` strings, not an actor object); transition via
  `ApprovalManager::approve/reject(receiptId, toolCallId, actorKey)`.
- **Resume only on an `Approved`/`Rejected` transition outcome.** Expired, already-resolved, not-found,
  or race-lost outcomes must **not** resume.
- Resume with a **tool-call-id-keyed decision — never `approveAll()`** (Verdict ignores the wildcard);
  deny → resume with a clean refusal.
- Resume the captured conversation exactly with `continue($conversationId, $participantOrNull)`, never
  `continueLastConversation()`. When `participant_reference` is present, rebuild it through
  `ConversationParticipants::resolve()` and attach the returned object; the null default attaches no
  participant. A failed reference resolution is a resume failure for VC-10 reconciliation, never a
  reason to guess a participant convention.
- **Runs outside any outer `DB::transaction`** (`UnsafeOuterTransaction`). Read status and expiry live
  via **`ApprovalManager::challengeForToolCall()`** — the store's `findForToolCall()` is not a status
  path and must not be used as one. A null challenge means expired *or* already-decided; the row holds
  no copy of either.
**Acceptance:** approve → tool executes exactly once (VC-1 hardened); deny → refusal; a stale/expired
receipt does **not** resume; wildcard-resume and outer-transaction are guarded by failing tests;
unauthorized approver rejected; a stored participant reference is resolved and supplied to exact
`continue()`, while a null reference resumes without one; no path calls `continueLastConversation()`.
**Refs:** design §6.4, §12; verdict `src/Approvals/ApprovalManager.php:83`, `src/Approvals/ApprovalExecutionContext.php:33`, `src/Support/IndependentTransactionGuard.php`.

### VC-7 · Approver-authority contract (Gate + actor key) · S · `type:contract` `area:runtime`
**Deps:** none. **Context:** VC-6 needs a defined authorization boundary for the first shippable round
trip; who-may-approve is the host's call (design §7). Tenancy is deferred to VC-12.
**Scope:** the host `Gate` ability (`can('approve', $pendingApproval)`) with a shipped default and an
override point, and resolution of the acting approver's **actor key string** passed to `ApprovalManager`.
No tenancy/scoping yet.
**Acceptance:** an approver failing the Gate cannot resolve an approval; the actor key reaches
`ApprovalManager::approve/reject`; both tested.
**Refs:** design §7; verdict `src/Approvals/ApprovalManager.php`.

### VC-8 · Approval presentation / redaction contract · M · `type:contract` `area:runtime`
**Deps:** none. **Context:** `PendingApproval` (Laravel AI) carries the tool, reason, and arguments; an
inbox can't present a meaningful request, and raw arguments may be sensitive.
**Scope:** a contract capturing a **display-safe summary** (tool name, human reason, redacted argument
digest) at pause time, with explicit ownership + retention rules; persisted on the row (VC-4) by the
bridge (VC-5). Default redaction is conservative; the host can override.
**Acceptance:** the summary is captured at ingestion and rendered-safe by default (a test asserts raw
sensitive args are not persisted verbatim under the default); the contract is documented.
**Refs:** design §6.1; `vendor/laravel/ai/src/Approvals/PendingApproval.php`.

---

## Milestone v0.2.0 — Production-grade workflow

### VC-9 · Operational state: notification idempotency + resume-attempt · M · `type:feature` `area:runtime`
**Deps:** VC-4. **Scope:** console-owned operational state (design §6.2) — notification
idempotency/delivery state and a resume-attempt counter. **No second authorization state**; status
still read from Verdict. (Assignment/SLA are deferred to the first operator-surface milestone, v0.4.)

**Notification idempotency is a table, not a column on the row.** One approval is notified many times
— assigned, reminder, escalation — each with its own outcome, so the cardinality is one-to-many and a
JSON column would force a read-modify-write whose concurrent claims for *different* keys overwrite
each other. Uniqueness on `(pending_approval_id, notification_key)` makes the database the arbiter,
the same mechanism VC-4's ingest uses. A claim is written **before** the send is attempted: recording
only successes makes "died mid-send" indistinguishable from "never started", and the retry duplicates.

**Anything added after v0.1.0 is a new migration, never an amendment.** A published migration has
already run for every adopter of the release that shipped it, so editing it reaches new installs only
and silently divides the two. `unresumable_reason` was amended in place because that stub was still
unreleased; v0.1.0 ended that licence.

**`agent_reconstruction_version` is deferred — scoped out, not overlooked.** Nothing can write it:
`ResumableAgents` exposes `keyFor`/`resolve`/`keys` and reports no reconstruction version, so shipping
the column would add a field with no producer — the defect `participant_reference` had before VC-5
supplied its seam. It needs an explicit `ResumableAgents` contract change, owned and justified as
VC-2 work (VC-2 is closed, so that is a new issue: **#51**). Whatever consumes it — VC-10 deciding whether a
row's resolver key predates a redeploy — must wait for that contract, not infer a version here.

**Acceptance:** transitions tested; no column duplicates Verdict receipt status/expiry; the released
create migration is asserted *not* to carry the new columns; published migrations sort in an order
that can run on a fresh install.
**Refs:** design §6.2.

### VC-10 · Resume-failure reconciliation (phase-specific) · M · `type:feature` `area:runtime`
**Deps:** VC-6, VC-9. **Context:** "receipt approved in Verdict but resume failed" must not strand or
double-fire (design §6.2). At-most-once is **capability-policy dependent**, not universal.
**Scope:** durable detection of the divergence, and idempotent **mark-abandoned**.

**Durable retry is deferred, not descoped by preference.** After `approve()`/`reject()` the receipt is
no longer `Pending`, so `challengeForToolCall()` returns null by construction, and `ApprovalManager`
publishes no read-back of a resolved decision. A retry would therefore need the console to persist the
human's decision so it could be re-sent — which is a second copy of authorization state under another
name, whatever it is labelled. The read that makes retry possible is
[verdict#298](https://github.com/fissible/verdict/issues/298) (per-receipt status), already filed from
ADR 0001 F2 and already carrying a console adoption ticket in VC-45. Retry belongs to that follow-on,
not here.

**Two observable phases, not three.** `definitely_pre_execution` — the failure was raised before
`prompt()`. `indeterminate` — it was raised *by* `prompt()`, which Laravel AI executes the approved
tools inside before handing results to the recorder, so nothing in its API proves which side of
execution the throw fell on. Execution-claim status would answer it, and **cannot be reached from
here**: `ExecutionClaimManager::find()` takes the raw claim id, which Verdict passes only into the
executor as `AuthorizedAction::executionIdentity()`; the claim row carries capability/policy/binding
fingerprint and no `tool_call_id`, and evidence keeps only `sha256(claimId)`. Correlating the two is
**VC-16**'s (execution-claim read-model), or an upstream issue raised from it — never inferred here.
Verdict's own `verdict.execution.claim-indeterminate` sets the precedent: a status whose producing
transition cannot be recovered is labelled as such, and the label does not pretend otherwise.

**Acceptance:** the two phases above are asserted distinctly, and no third is claimed; mark-abandoned
is idempotent; detection is durable across a restart; no path re-sends a decision and no path
persists one.
**Refs:** design §6.2, §12; verdict#298 (gates retry); VC-16 (gates claim correlation).

### VC-11 · Notifications · S · `type:feature` `area:notifications`
**Deps:** VC-9. **Context:** Verdict emits **no** receipt-transition events (its only events are the
four anomalies) — the console publishes notifications from its **own** observation points, not by
subscribing to Verdict.
**Scope:** Laravel `Notification`s at: **approval pending** (VC-5 ingestion); **approved / rejected**
(VC-6's own resolution outcome); and **resume outcome** (from Laravel AI). Idempotency source is the
VC-9 operational state. **No "action completed" notice** — Verdict emits no execution-claim-completed
event, and `ToolApprovalResolved` is post-resume tool results, not a claim lifecycle signal. Copy obeys
the ADR 0028 ceiling.
**There is no `consumed` notice, and there cannot be one yet.** An earlier draft detected it from
`ApprovalManager::challengeForToolCall()` returning null after a resume — but null also means expired,
rejected, ambiguous, or absent, so that detector asserts a lifecycle transition it cannot observe.
Reading the receipt's status directly would break the §5 boundary. A real `consumed` notice needs a
Verdict status-read contract (`MILESTONES.md`); until one exists the notice is omitted rather than
approximated.
**Acceptance:** each notice fires from its stated observation point, idempotent (VC-9); a test asserts
no completion-claim copy exists, and that none of the shipped notices claims a receipt was consumed.
**Refs:** design §6.5; verdict ADR 0028, `src/Approvals/ApprovalReceiptStore.php`; `vendor/laravel/ai/src/Providers/Concerns/GeneratesText.php` (ToolApprovalResolved dispatch).

### VC-12 · Tenancy scoping · M · `type:feature` `area:runtime`
**Deps:** VC-7. **Scope:** delegate tenancy/scoping to the host — queries scoped by a host-provided
resolver; the console is never the tenancy authority.
**Acceptance:** cross-tenant rows are not visible/actionable under the host scope; tested.
**Refs:** design §7.

### VC-41 · Verb-set resolver + per-surface contract test · S · `type:contract` `type:test` `area:runtime`
**Deps:** VC-6. **Context:** [ADR 0001](../adr/0001-approval-surface-contract.md) §2 — *a Deny is not
approvable* — must be one function every surface renders from, not a convention each re-derives.
**Scope:** a headless `ApprovalVerbs` resolver over the only item current code can produce: a
`PendingApproval`, which exists only for `RequireConfirmation`. It yields `{approve, reject}` iff the
live challenge is non-null, belongs to that tool call, and the row is `Drivable`; `{close}` iff
`Drivable`, null challenge, **and** VC-43 has shipped (else `{}`). Every `UnresumableReason` yields
`{}`, and the typed vocabulary has no wildcard/bulk/edit verb. Informational dispositions have no
`PendingApproval` producer; VC-13/42 own their evidence item/read model and must render `{}` there,
never fabricate a disposition parameter here. `ApprovalSurfaceContract` is mandatory in the rendering
tests for VC-19/21/24/25/28.
**Acceptance:** every current resolver cell unit-tested; all `UnresumableReason` cases empty; the
named helper and its mandatory consumers are documented.
**Waiting on Verdict:** nothing.
**Refs:** ADR 0001 §2, §3, Consequences. ([#41](https://github.com/fissible/verdict-console/issues/41))

### VC-43 · `close` verb for lapsed rows (measured) · M · `type:feature` `area:runtime`
**Deps:** VC-6, VC-9, VC-10, VC-41. **Context:** ADR 0001 §3 expiry obligation 3 — a lapsed receipt
leaves the run paused with no auto-deny (Verdict ADR 0029 §1); the run needs a non-authorization exit.
**Scope:** resume the exact conversation with a tool-call-id-keyed `reject()` **without touching the
receipt**; gated by the same ability as `reject`. **Ships only with a real-gateway test** of what
Laravel AI does when `continue()` + `prompt(Decisions)` targets a turn that is *not* pending (the
already-decided half of a null challenge). Until then VC-41 yields `{}` for lapsed rows.
**Acceptance:** non-pending-turn behaviour measured and recorded in the PR; `close` never calls
`ApprovalManager::approve/reject`; no tool execution; Gate-refused approver cannot close.
**Waiting on Verdict:** nothing blocking. **The both-halves defence is intentional, safe, and
scheduled for removal — not a reason to delay this issue.** A null challenge covers *expired* and
*already decided*; the console cannot tell them apart, so defending both is the correct behaviour for
that state of knowledge. verdict#298's per-receipt status read makes them distinguishable and **VC-45
removes the defence when it lands**. Waiting instead would stall the milestone for a read that does
not exist yet and leave lapsed rows with no exit at all — worse than a defence with a known deletion
date. #298 now gates three console simplifications (VC-10's durable retry, VC-45's status-aware
handling, and this issue's narrower semantics); that is upstream prioritisation input, recorded on
verdict#298, not a dependency here.
([#43](https://github.com/fissible/verdict-console/issues/43))

### VC-44 · Design-doc and presenter doc corrections after ADR 0001 · XS · `type:docs`
**Deps:** none. **Scope:** design §7 → configured ability; §6.4 + expiry `close`; §5 narrowed to
workflow/correlation index (reads move to verdict#298); §14 → filed cluster; `DefaultApprovalPresenter`
doc block: persisting provenance (never) vs rendering the live challenge (yes); README mentions the
gated `require_review` lane.
**Waiting on Verdict:** nothing. ([#44](https://github.com/fissible/verdict-console/issues/44))

---

## Milestone v0.3.0 — Evidence & health projections

### VC-13 · Evidence query contract + default table adapter · M · `type:contract` `area:evidence`
**Deps:** VC-4. **Context:** Verdict's `EvidenceWriter` is **write-only**, and `DatabaseEvidenceRecorder`
exposes no decision-evidence query API — a generic "read-model over `DecisionEvidence`" would otherwise
couple to private storage.
**Scope:** a **console-owned `EvidenceQuery` contract** (filter by disposition/capability/time), with a
**default adapter over the shipped `verdict_evidence` table schema**, honoring ADR 0008 (fingerprints,
not raw) and surfacing `claimType` + `recordDigest`. **`NullEvidenceRecorder` is the default** — the
contract exposes a distinct "recording is off" state so surfaces render *blank-by-config*.
**Acceptance:** default adapter reads the shipped schema; a recording-disabled state is distinguishable
from "no rows"; the contract does not import Verdict recorder internals; tested.
**Refs:** design §6.6; verdict `src/Contracts/EvidenceWriter.php`, `src/Evidence/DatabaseEvidenceRecorder.php`, `config/verdict.php`.

### VC-14 · Evidence ↔ conversation correlation projection · M · `type:feature` `area:evidence`
**Deps:** VC-5, VC-13. **Context:** `DecisionEvidence` has `invocationId` but **no** `conversationId`
(design §6.6), so conversation-scoped evidence is not native.
**Scope:** capture `invocationId ↔ conversationId` at the `ToolApprovalRequested` boundary into a
console-owned projection; expose conversation-scoped queries through VC-13.
**Acceptance:** evidence for a conversation is retrievable; a missing mapping degrades explicitly.
**Refs:** design §6.6; verdict `src/Evidence/DecisionEvidence.php`.

### VC-15 · Anomaly incident ledger · M · `type:feature` `area:evidence`
**Deps:** VC-4, VC-5. **Context:** Verdict's four anomaly events are **ephemeral / once-per-process**
(design §6.7) — and so is this package's own ingestion incident.
**Scope:** a listener persisting **five** sources into a console-owned incidents table: Verdict's
`ConsequentialActionUnrecorded`, `EvidenceWriteFailed`, `ChainWriteFailed`,
`CapabilityConfigurationUnrecorded`, **and the console's own `ApprovalIngestionIncident`** (VC-5),
whose typed `UnresumableReason` is projected as the incident cause. Until this ships, that fifth
source is an event and a warning log line only, which is why VC-4's row carries
`unresumable_reason` durably.
**Acceptance:** each of the five events lands exactly one durable incident row; tested per event; an
ingestion incident's persisted cause matches the `unresumable_reason` on its row.
**Refs:** design §6.7; verdict `src/Evidence/NullRecorderWarning.php`, `src/Evidence/Events/*`.

### VC-16 · Execution-claim read-model + resolve · M · `type:feature` `area:evidence`
**Deps:** VC-7. **Context:** an unresolved (indeterminate) claim needs a human (design §8).
**Scope:** a read-model over unresolved execution claims (mirrors `verdict:execution-claims`) and a
resolve action requiring **Gate/tenant authorization, an actor, and a non-blank reconciliation reason**,
asserting the actual `Completed`/`Released` transition via `ExecutionClaimManager::resolve`.
**Acceptance:** lists unresolved claims; resolve without auth/reason is refused; a successful resolve
asserts the real transition outcome; tested.
**Refs:** design §8; verdict `src/ExecutionClaims/ExecutionClaimManager.php:113`, `src/Console/Commands/ListExecutionClaimsCommand.php`, `ResolveExecutionClaimCommand.php`.

### VC-17 · Config inspection read-models · S · `type:feature` `area:runtime`
**Deps:** none. **Scope:** **inspect-only** read-models for capabilities / rate limits / approval rules
— a config write changes the capability-configuration fingerprint in every decision record (design §6.8).
**Acceptance:** read-models list configuration; no write path exists; documented why.
**Refs:** design §6.8, §13.

### #67 · Verdict's `unauthorized` outcome is a named refusal · S · `type:feature` `area:runtime`
**Deps:** none — **ungated**; `ApprovalOutcome::Unauthorized` is in `v0.12.0`, already required.
**Context:** [ADR 0001](../adr/0001-approval-surface-contract.md) §4 (amended) decided this and the
amendment assigned it to VC-6, which had already closed — so a decided behaviour lived only in the
ADR. The safety half is pinned (#65 put `unauthorized` in the never-resumes dataset); the naming half
does not exist.
**Scope:** raise `AuthorizationException` with the *same* message as the approver and scope refusals,
so authority cannot be probed by comparing errors; dispatch `ApprovalDecisionRefused` and record it
through the shipped ledger (`IncidentStore::record()`); never touch the receipt;
`ApprovalAuthorizerMissing` keeps propagating as the configuration error the doctor already prevents.
**Acceptance:** identical exception and message to a Gate refusal, asserted against the shared
fixture rather than a re-typed literal; exactly one incident row; receipt stays `pending`; the
never-resumes dataset stays green.
**Waiting on Verdict:** nothing.
([#67](https://github.com/fissible/verdict-console/issues/67))

### VC-42 · Approval item read-model — live challenge over persisted presentation · M · `type:feature` `area:runtime`
**Deps:** VC-6, VC-41. **Context:** ADR 0001 §5 — the item renders the ADR 0026 payload **live** from
the challenge on top of the VC-8 presentation; expiry and provenance are never persisted.
**Scope:** a headless DTO per row from the `PendingApproval` row + live `ApprovalChallenge` + VC-41's
verbs, consumed by VC-19/24/25/28. Provenance disclosure rendered as **four distinct states** plus
`null` (issued before capture): `Declared` (sources by kind/trust/data class/channel; untrusted
upstream = warning; withheld/undescribed shown as counts), `Unknown` ("no derivation was declared",
never an empty list), `Unreleased` (a configuration statement). Reason labelled as *why this capability
is gated*. `waiting_since` **nullable** until verdict#300 (VC-47). No Verdict table is read.
**Acceptance:** all five states snapshot-tested; untrusted source → warning; `Unknown` never
serialises empty; expiry always from the live challenge; no Verdict table queried (asserted).
**Waiting on Verdict:** nothing blocking; verdict#300 enriches `waiting_since`.
**Refs:** ADR 0001 §5; verdict ADR 0026, ADR 0029 §2. ([#42](https://github.com/fissible/verdict-console/issues/42))

---

## Milestone v0.4.0 — Blade surfaces (in core)

Blade ships in core as publishable stubs (design §9). Assignment/SLA operational fields (deferred from
VC-9) land here alongside the inbox that consumes them.

### VC-18 · Host chat-entry contract · M · `type:contract` `area:runtime`
**Deps:** VC-2. **Context:** VC-2 reconstructs an agent only after a pause; starting a *new* chat needs
to know which agent to start and who owns conversation/message rendering.
**Scope:** a host contract for new-conversation agent selection + conversation/message ownership,
consumed by Blade (VC-21) and Livewire (VC-24).
**Acceptance:** a host can register an entry agent; a new conversation starts through it; tested.
**Refs:** design §8.

### VC-19 · Blade approval inbox widget · M · `type:feature` `area:blade`
**Deps:** VC-6, VC-12, VC-13. **Scope:** `<x-verdict-console::approvals />` — server-rendered, form-post,
no build. Renders **drivable** (approve/deny), **non-drivable** (not console-actionable), and **expired /
null-challenge** (already-decided) rows distinctly, using the VC-8 presentation summary and VC-12 scope.
Publishable views.
**Acceptance:** the three row states render correctly; approve/deny post through VC-6; HTTP/snapshot
tested.
**Refs:** design §6.3, §8, §12.

### VC-20 · Blade audit / evidence page · M · `type:feature` `area:blade`
**Deps:** VC-13. **Scope:** paginated evidence table surfacing `claimType`/`recordDigest`; the
**recording-off** state reads "recording is off — blank by config."
**Acceptance:** paginates; recording-off renders the config message, not an empty "nothing happened"
table.
**Refs:** design §6.6, §8.

### VC-21 · Blade basic chat thread · M · `type:feature` `area:blade`
**Deps:** VC-6, VC-18. **Scope:** a non-streaming server-rendered thread (post → reload) rendering an
approval interrupt inline and resolving it through VC-6; honestly documents the no-streaming limitation.
**Acceptance:** a message that triggers a confirmation shows the interrupt; resolving continues the
thread; tested.
**Refs:** design §8.

### VC-22 · Blade ops views · M · `type:feature` `area:blade`
**Deps:** VC-3, VC-15, VC-16. **Scope:** server-rendered ops pages — the **console-doctor** results
screen (VC-3's findings model, incl. the #230 dead gate), the execution-claim queue (VC-16), and the
anomaly-incident list (VC-15).
**Acceptance:** each renders its data; the doctor screen surfaces a confirmable-without-target
capability.
**Refs:** design §8.

---

## Adapter package — `fissible/verdict-console-livewire` (own repo, v0.1.0)

Each issue: install/registration smoke test + public-component test + **no Livewire types leak into
core** + an end-to-end approval path for interactive surfaces.

### VC-23 · Package scaffold + transport · M · `type:feature` `area:livewire`
**Deps:** core v0.4.0. **Scope:** new repo per fissible convention; depends on core; polling default,
broadcast (Reverb/Pusher) opt-in. **Acceptance:** installs into a clean app; polling and broadcast both
smoke-tested; no core dependency on Livewire.

### VC-24 · Chat with inline approval cards · L · `type:feature` `area:livewire`
**Deps:** VC-23, VC-18. **Scope:** the flagship — agent streams; an approval card appears mid-thread;
resolve in-flow; the run resumes; reactive, no reloads. **Acceptance:** an approval interrupt renders and
resolves live end-to-end through the core services; component-tested.

### VC-25 · Reactive inbox · M · `type:feature` `area:livewire`
**Deps:** VC-23, VC-12. **Scope:** a live-updating inbox over the scoped pending-approval query.
**Acceptance:** a new pending approval appears without reload; approve/deny drive VC-6; tested.

### VC-26 · Live decision feed · M · `type:feature` `area:livewire`
**Deps:** VC-23, VC-13, VC-14. **Scope:** a decision feed gated on the durable projections existing.
**Acceptance:** dispositions stream into the feed; recording-off renders the config state; tested.

---

## Adapter package — `fissible/verdict-console-filament` (own repo, v0.1.0)

Each issue: plugin registration smoke test + Resource/page test + **no Filament types leak into core**.

### VC-27 · Filament plugin scaffold · M · `type:feature` `area:filament`
**Deps:** core v0.4.0. **Scope:** new repo per fissible convention; a `FilamentPlugin` registering into
an existing panel. **Acceptance:** the plugin registers into a test panel; smoke-tested.

### VC-28 · Approval queue Resource · L · `type:feature` `area:filament`
**Deps:** VC-27, VC-6, VC-12. **Scope:** a queue Resource — triage, **approve / deny only** (no
edit-and-approve: Verdict does not admit `Decision::edit()` into `ApprovalExecutionContext`, and modified
arguments break the receipt binding), filters, drivable/non-drivable/expired states. **Bulk approval
deferred** until per-row authorization + partial-failure behavior is specified. **Acceptance:** approve/deny
drive VC-6; the three states render; tested.
**Refs:** verdict `src/Approvals/ApprovalExecutionContext.php:33`.

### VC-29 · Evidence browser Resource · L · `type:feature` `area:filament`
**Deps:** VC-27, VC-13. **Scope:** an evidence browser Resource — filters, an infolist showing provenance
+ `claimType`/`recordDigest`, recording-off empty state. **Acceptance:** filters work; recording-off state
renders; tested.

### VC-30 · Ops surfaces (claims + doctor + alarms) · L · `type:feature` `area:filament`
**Deps:** VC-27, VC-15, VC-16, VC-3, VC-17. **Scope:** execution-claim queue Resource (VC-16),
console-doctor page (VC-3), anomaly alarms widget (VC-15), config inspection page (VC-17).
**Acceptance:** each surface renders and (for claims) drives resolve with auth + reason; tested.

---

## Milestone `verdict-gated` — designed against Verdict Proposed-contract issues, built against nothing

[ADR 0001 §8](../adr/0001-approval-surface-contract.md) carries the status frame. Each issue below
names the `fissible/verdict` issue it waits on and migrates to a release milestone when that ships.
Label: `blocked:verdict`. Verdict's build order when its gate opens: #297 → #298 → #299; #300 is
independent and ungated.

### VC-45 · Adopt verdict#298 approval read contract · M · `blocked:verdict` `area:runtime`
**Blocked on:** verdict#298 (read contract returning DTOs: pending challenges, per-receipt status,
later review-request reads; poll-consistency freshness). **Deps:** VC-6, VC-42.
**Scope:** route every status read through #298; split "expired **or** already decided" into two
states; polling designed to poll-consistency; an architecture test forbids direct Verdict table reads;
the console row stays the workflow/correlation index, not a mirror.
([#45](https://github.com/fissible/verdict-console/issues/45))

### VC-46 · Adopt verdict#299 receipt-transition events · S · `blocked:verdict` `area:notifications`
**Blocked on:** verdict#299 (`issued`/`approved`/`rejected`/`consumed` from `ApprovalManager`), which
waits on #298. **Deps:** VC-11, VC-45.
**Scope:** listeners for `approved`/`rejected` (resolved elsewhere) and `consumed` (the honest notice
VC-11 could not build). **No `expired` listener, ever** — expiry has no transition moment. `issued` is
informational; ingestion stays on `ToolApprovalRequested`.
([#46](https://github.com/fissible/verdict-console/issues/46))

### VC-47 · Adopt verdict#300 `ApprovalChallenge::issuedAt` · XS · `blocked:verdict` `area:runtime`
**Blocked on:** verdict#300 (ungated on the Verdict side, likely first to land). **Deps:** VC-42.
**Scope:** `waiting_since` from the challenge when present, null otherwise; never the row's
`created_at` as a fallback. ([#47](https://github.com/fissible/verdict-console/issues/47))

### VC-48 · Asynchronous review lane · L · `blocked:verdict` `area:runtime`
**Blocked on:** verdict#297 (`ReviewRequest` substrate — keystone; today `RequireReview` is denied at
admission with no record, event, or store) **then** verdict#298 for reads. **Deps:** VC-45, VC-41, VC-42.
**Scope:** review items read through #298 and rendered with VC-42; `approve`/`reject` drive #297's
`reviewed` transition and **execute nothing, mint nothing**; a second Gate ability
`review-verdict-action`; console-owned SLA whose passing is an escalation only. Not pre-decided
(owned by #297): refusal payload shape, mandatory reason, table naming. **Nothing speculative is
built before #297 ships** — ADR 0001 reserves the ability name only.
([#48](https://github.com/fissible/verdict-console/issues/48))

### #68 · Capture Verdict's `approval_context` at ingestion · S · `blocked:verdict` `area:runtime`
**Blocked on:** the next Verdict tag carrying verdict#327 (`ApprovalStatusReader`) — verify with
`git tag --contains a84cbed`. **Deps:** #45.
**Scope:** a nullable `approval_context` column in its own migration file, populated at ingestion from
`ApprovalStatusReader::statusFor()`. A **correlation annotation, not mirrored status**: Verdict
documents the field immutable after issue, which is what separates it from the receipt status and
expiry this package deliberately never copies. At `v0.12.0` the field's only route is the store call
design §5 forbids, which is why this waits rather than shipping now.
**Acceptance:** captured when present, null when the receipt predates capture; a schema assertion
proves no column mirrors Verdict receipt status or expiry; the migration publishes.
([#68](https://github.com/fissible/verdict-console/issues/68))

### #69 · Ship an `ApprovalContextScope` keyed on `approval_context` · S · `blocked:verdict` `area:runtime`
**Blocked on:** #68. **Scope:** an `ApprovalScope` implementation over the captured context — non-empty
map, **typed-exact** (integer `1` never matches string `'1'`), `null`/`[]` rows never in scope — the same
rule as verdict ADR 0031 §3, so console scoping and Verdict's `pendingWithin()` cannot drift. **Additive:
the published `ApprovalScope` contract is not narrowed**; a host's own scope keeps working, and this
ships as the recommended binding because it is the one that guarantees *what the console shows a person
is a subset of what Verdict would let them decide*.
**Acceptance:** typed-exact matching proven; `null`/`[]` excluded; an existing host scope still works;
the recommendation and its reason documented.
([#69](https://github.com/fissible/verdict-console/issues/69))

---

## Open items for PM (not issues yet)

- **Companion Verdict issues (optional, none blocking v1):** a receipt read/enumeration API on
  `ApprovalReceiptStore` for generic (non-`ApprovalManager`) integrations (design §5); and — only if a
  surface must mirror `verdict:validate` output rather than the console doctor's own findings model — a
  structured validation-findings API in Verdict.
- Sequencing vs. reference app **#237**. Verdict **#218** (Conversational resume) is closed/proven
  (v0.8.0, #233/#235) — this package leans on shipped work.
