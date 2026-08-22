# `fissible/verdict-console` — design spec (scoping doc)

**Status:** design proposal for a *new* package family. This is the scoping document to hand to the
PM (`~/lib/fissible/projects/`); standing up the repo, milestones, and issues is PM work, not
Worker work. No code is written here.

**Provenance:** shape proposed by the Verdict SME; corrected against the codebase by two independent
reviews — a manual SME review and a read-only Codex pass that cited files. Where a claim below is
load-bearing it carries its source file. Factual assertions about internals were checked against
`src/` and `vendor/laravel/ai`; several first-draft assumptions were wrong and are corrected inline.

---

## 1. Purpose

Verdict decides at the tool boundary and records evidence; Laravel AI runs the agent and persists
conversations. Neither owns the human-in-the-loop tissue between them, nor any UI. `verdict-console`
is that tissue plus the operator surfaces: turn a pending approval into a screen a person acts on,
drive the receipt → resume round trip, and present what Verdict recorded.

## 2. Responsibility split (unchanged, correct)

- **Verdict** — dispositions (permit / deny / require_confirmation / require_review / throttle),
  mints & transitions **approval receipts**, records `DecisionEvidence` (`claimType` +
  `recordDigest`), semantic rate limits, at-most-once execution claims. No UI, no human loop.
- **Laravel AI** — agents, tools, streaming, durable conversations (`ConversationStore`). Verdict's
  confirmable tools surface through Laravel AI's `Approvable` mechanism.
- **The console** — joins the two and draws UI. It never *decides*, never *persists conversations*,
  never becomes a second **authorization** authority. It *does* own operational workflow state
  (below), which the first draft wrongly denied.

## 3. Scope boundary — READ THIS FIRST

The Verdict **receipt-backed** approval loop this package drives exists only for `BoundTool`: the
`requestConfirmation()` branch that mints a receipt needs the bound envelope
[`src/LaravelAi/AbstractVerdictTool.php:138`]. But `AbstractVerdictTool` implements `Approvable` and
**both** `BoundTool` and `GuardedTool` extend it — so a `GuardedTool`, a wrapped tool's own
`shouldRequestApproval()` [`src/LaravelAi/AbstractVerdictTool.php:150`], or an explicit requirement
**can still raise a Laravel AI `ToolApprovalRequested` with no Verdict receipt behind it.** The
console *will* receive those; §6.3 handles the receiptless case explicitly rather than assuming every
approval has a receipt.

**So the *drivable* loop is scoped to: `BoundTool` + an agent that is `Conversational` **and** uses
the `RemembersConversations` concern with a `ConversationStore` + a real, non-fake gateway.** These
are two distinct preconditions, and the first draft conflated them:
- **`Conversational` gates pause/resume itself.** `ResumesToolApprovals::agentCanResumeApprovals()`
  is `$agent instanceof Conversational`, and `throwIfNotResumable()` throws
  `ApprovalNotResumableException` when it is absent — a **loud** failure, not a silent no-op.
  [`vendor/laravel/ai/src/Providers/Concerns/ResumesToolApprovals.php`]
- **`RemembersConversations` + `ConversationStore` gates *durable* result persistence**, not the
  resume decision. `storeApprovalResultRecorderFor()` records resolved tool results only when the
  agent uses that concern and has a current conversation; without it, results are not persisted and a
  *later, out-of-band* resume (a different process, the console's whole use case) cannot reconstruct
  — this is the genuinely silent gap.

Because the console resolves approvals out-of-band, it needs **both**. Non-`BoundTool` approvals are
*observable but not Verdict-drivable* — out of the v1 loop, surfaced per §6.3, never silently
dropped. This boundary is a first-class precondition, stated in the README and enforced by a
`verdict:validate`-style preflight (§8), not an implementation footnote.

## 4. Two event sources (not one)

1. **Laravel AI `ToolApprovalRequested`** — the pause signal. Fields:
   `invocationId, agent, pendingApprovals, conversationId (nullable), conversationUser (nullable)`.
   Dispatched on both non-streamed and streamed paths.
   [`vendor/laravel/ai/src/Events/ToolApprovalRequested.php`], [`.../GeneratesText.php`], [`.../StreamsText.php`]
   - **`ToolApprovalResolved` is NOT the human's decision.** It carries `toolResults` and fires when a
     *resumed* gateway has produced results — after the fact. The human decision is a console action
     (§6.3), not this event. (First-draft error, corrected.)
     [`vendor/laravel/ai/src/Events/ToolApprovalResolved.php`]
2. **Verdict's four anomaly events** — the ops **health** source:
   `ConsequentialActionUnrecorded, EvidenceWriteFailed, ChainWriteFailed,
   CapabilityConfigurationUnrecorded`. These are the *only* events Verdict dispatches. They are
   **process-local and ephemeral** — e.g. the null-recorder warning fires at most once per process —
   so they cannot back a queue/history directly; they must be projected durably (§6.7).
   [`src/Evidence/NullRecorderWarning.php`]

Division: **Laravel AI signals pause/resume · Verdict owns receipt state · the console joins and
draws UI, owning only operational state and its own durable projections.**

## 5. Verdict-side dependency this design forces (decision required)

`ApprovalReceiptStore` exposes only `findForToolCall($toolCallId)` (which returns `null` on
ambiguity) — **no `find(receiptId)` and no list/query API.**
[`src/Contracts/ApprovalReceiptStore.php`], [`src/Approvals/DatabaseApprovalReceiptStore.php`]

Consequence: Verdict cannot enumerate receipts for an inbox. Resolution of the tension:

- **The console's own `PendingApproval` table is the queryable index** (the inbox lists from it).
- **Per-row authoritative status is read from Verdict via `ApprovalManager::challengeForToolCall()`**
  — *not* the store's `findForToolCall()`, which is named above only to describe what the contract
  offers. A non-null challenge means "pending, unexpired, actionable"; null collapses **absent,
  ambiguous, non-pending, and expired** into one answer, and **the console cannot tell them apart** —
  see §6.3, which records the indistinguishability rather than guessing past it. Transitions
  go through `ApprovalManager::approve/reject` keyed by `receiptId + toolCallId + actor`, and the
  **returned outcome** — never the actor's intent — decides whether the run resumes (§6.4).
  [`src/Approvals/ApprovalManager.php`]
- The console therefore integrates **through `ApprovalManager`**, not by reaching into the store. If
  a future generic integration needs receipt enumeration, that is an **additive Verdict contract
  change** (a read API) — flag for PM as a possible companion Verdict issue, not assumed here.

## 6. Layer 1 — headless runtime

### 6.1 `PendingApproval` row
- **The row has its own surrogate primary key.** It cannot be the receipt id, because a receiptless
  approval exists (§3). `receiptId` is a **nullable authoritative reference** — set for `BoundTool`
  approvals, null for receiptless/ambiguous ones (§6.3) — **unique when present**.
- **Ingest idempotency** keyed on `toolCallId (+ conversationId)`, so a redelivered
  `ToolApprovalRequested` updates rather than duplicates.
- **Correlation annotations**, captured at pause time (the only chance): `toolCallId`,
  `conversationId`, `participantReference`, `invocationId` (for evidence correlation, §6.6). The
  participant is an **opaque host-supplied reference**, never Laravel AI's participant object and
  never a class-name-plus-id convention — that object is not durable, and rebuilding one by
  convention guesses at the host's identity model.
- **A `resumability` state** — `drivable` / `unresumable` — recording whether *this console* can
  drive the run. Never a statement about the receipt's validity, which is read live from Verdict.
- **An `unresumableReason`** naming which drivability check came back empty, when one did: the same
  typed value the ingestion incident carries, so the two can never disagree. It lives on the row
  because until VC-15 the incident is an ephemeral event (§6.3), and without it the reason survives
  nowhere.
- **A host-supplied resumable-agent key — not just the agent class + participant.** Class +
  participant cannot reconstruct an agent that needs runtime constructor input, tenant context, or a
  specific provider/model. The host must register a **stable resolver** keyed by an identifier the
  console persists, and the bridge (§6.3) **revalidates that it resolves at ingestion — detectively**.
  That revalidation cannot prevent an unresumable approval, and must not be described as though it
  could: `ToolApprovalRequested` fires after the run has paused and a receipt may already be pending.
  The **startup preflight** is the preventive stage; ingestion records. This is a required part of the
  M1 contract, not a later refinement.
- **No expiry field of its own.** Receipt TTL stays Verdict's — a second TTL is exactly the
  divergence §5 avoids; the inbox reads expiry live (§6.4 / §6.6, and the null-challenge hazard).
- Keying on the invocation id would silently never match its resume: each public `prompt()`/
  `stream()` mints a fresh UUIDv7 per call (it preserves an id already supplied in `AgentPrompt`,
  but the public resume path does not supply one). [`vendor/laravel/ai/src/Promptable.php`]
- `toolCallId + conversationId` alone is insufficient as a stable key (conversationId is nullable) —
  hence a surrogate PK, `receiptId` as the authoritative reference, and these as correlation.

### 6.2 Operational state the console MUST own (durably)
Not a second authorization authority — but the console owns, and must persist:
notification idempotency + delivery-failure state; assignment / SLA timers; agent-reconstruction
version; resume-attempt state; and **reconciliation of "receipt approved but resume failed"** (else
retries duplicate notices or strand approved actions).

### 6.3 Disposition bridge (Laravel AI → runtime)
Listener on `ToolApprovalRequested`. For each pending item, call
**`ApprovalManager::challengeForToolCall($toolCallId)`** — not the store's `findForToolCall()`, which
would break the §5 boundary — and take the receipt id from the returned challenge.

- **a challenge** → a Verdict-backed row; `receiptId` is `$challenge->receiptId`.
- **null** → record a **non-drivable** row (`receiptId` null) with `unresumableReason`
  `challenge_unavailable`, and emit an `ApprovalIngestionIncident` carrying the same value.
  `challenge_unavailable` names **which check came back empty, not why**, and is stated as unknown
  wherever it is surfaced.

  This is deliberately *one* state rather than a classification, and the reason is a hard limit
  rather than a simplification. `challengeForToolCall()` returns `?ApprovalChallenge`, and null
  covers four different situations: no Verdict receipt at all (a non-`BoundTool` approval, §3), an
  **ambiguous** tool-call id (the store takes `limit(2)` and returns null unless exactly one row
  matches), a **non-pending** receipt, and an **expired** one. `ApprovalManager`'s remaining public
  methods — `issue`, `approve`, `reject`, `consume`, `validate`, `withinApprovedToolCalls` — either
  mutate or require an `Evaluation` the console never holds, so **no public datum distinguishes the
  four**. Reaching for `findForToolCall()` to tell them apart is precisely the boundary §5 forbids.

  So the bridge must not say which case it was, must not raise a different incident for one of them,
  and must not imply a cause in the record. An incident naming a cause the code could not have
  determined is worse than one admitting it does not know: the first sends an operator to the wrong
  place, the second sends them to the receipt.

  Distinguishing these would need a **new Verdict read contract**, which is a real option and is
  tracked with the receipt-enumeration question in `MILESTONES.md` — this is its second independent
  consumer. Until such a contract exists, one state.

**Drivability needs three conditions, not two.** A row is `drivable` only with a receipt **and** a
resolver key that resolves **and** a `conversationId`. `continue()` requires a string id and
`PendingApproval.conversation_id` is nullable, so a conversationless pause has nothing to continue
into and is `unresumable` however good the other two are.

Correlation annotations (§6.1, incl. `invocationId ↔ conversationId`) are captured at this boundary,
**and the host-supplied resumable-agent key is resolved and validated here — detectively, never as a
refusal.** A row whose agent cannot be reconstructed is still written, marked `unresumable`, recorded
as an incident (§6.7), and handed to the host's recovery protocol.

**The incident is an event, and it is ephemeral until VC-15.** `ApprovalIngestionIncident` is *one*
event carrying a typed `UnresumableReason` — `challenge_unavailable`, `agent_unresolvable`, or
`conversation_absent`, the three drivability conditions — dispatched at ingestion, with a default
listener that logs at warning level. It is **not** a durable history and must not be described as one:
VC-15's ledger (§6.7) will project it alongside Verdict's four anomaly events, and until that ships
the only record surviving a process restart is the row's own `unresumableReason`. That is exactly why
the row carries it. A row failing more than one check records the **first** in the order above, and
the event carries the same value.

Refusing it would be the one thing that cannot help. `ToolApprovalRequested` fires *after* the run has
already paused, and a Verdict receipt may already be pending — so declining to write the row undoes
nothing and hides a run that is already stranded, waiting on a human who will never be shown it. The
preventive stage is the startup preflight above; ingestion is detective by construction. The run suspends via Laravel AI's conversation persistence (the `RememberConversation`
middleware + store); the console triggers, never owns, persistence.

### 6.4 Resolution bridge (human → Verdict → run)
On a human decision: check approver authority (host `Gate`, §7) → `ApprovalManager::approve/reject`
(receiptId, toolCallId, actor) → resume the conversation **with a specific decision keyed by the
tool-call id — never `approveAll()`** (Verdict deliberately ignores the wildcard).
[`src/Approvals/ApprovalExecutionContext.php`]
- On deny: resume with a clean refusal so the agent never hangs.
- **Must run OUTSIDE any outer `DB::transaction`** — Verdict throws `UnsafeOuterTransaction` via
  `SecurityStateTransaction → IndependentTransactionGuard`.
  [`src/Support/SecurityStateTransaction.php`], [`src/Support/IndependentTransactionGuard.php`]

### 6.5 Notifications
Mail / database / Slack / broadcast. Copy obeys the ADR 0028 ceiling: never "the action completed" —
at most *"Verdict recorded a completed claim (admission-side belief, not an executor receipt)."*

### 6.6 Evidence read-models
Over `DecisionEvidence`, honoring ADR 0008 (fingerprints, not raw), surfacing `claimType` +
`recordDigest`. Two hard constraints:
- **Default recorder is `NullEvidenceRecorder`** — these surfaces are dark on a default install *by
  design*. The UI must detect it and say *"recording is off — blank by config,"* never render an
  empty table that reads as "nothing happened." [`config/verdict.php`]
- **`DecisionEvidence` has `invocationId` but no `conversationId`.** [`src/Evidence/DecisionEvidence.php`]
  "Filter evidence by conversation" is therefore **not native** — it depends on a console-owned
  correlation projection (invocationId ↔ conversationId), captured at the `ToolApprovalRequested`
  boundary where both are present.

### 6.7 Durable incident projection (for the ops health surface)
A listener persists the ephemeral events into a console-owned incidents table, because they do not
survive the process. The alarm queue/history reads from that projection, not from the events.

**Five sources, not four.** Verdict's four anomaly events, plus this package's own
`ApprovalIngestionIncident` (§6.3). Until this section ships, that fifth source is an event and a log
line only — which is why the ingestion row carries its `unresumableReason` durably instead of relying
on a ledger that does not exist yet.

### 6.8 Config read-models — inspect-first
Verdict policy is application *code*. This surface shows capabilities/limits/approval rules; it
writes only genuinely-data parts. Read-only in v1, for the sharper reason: the capability-
configuration fingerprint is recorded in every decision record, so a config write changes what the
evidence trail *means* — any future write surface must announce that, not just save a value.

## 7. Cross-cutting
- **Who may approve is the host's call** — Laravel `Gate` (`can('approve', $pendingApproval)`); ship
  a default, never hard-code authority.
- **Tenancy/scoping delegates to the host.**
- **Real-time transport degrades:** polling default (no infra); broadcast (Reverb/Pusher) opt-in.

## 8. Layer 2 — surfaces, specialized by audience
Each read-heavy surface carries its **projection dependency** explicitly (§6.6–6.7); none is assumed
free.

- **Blade (embed):** `<x-verdict-console::approvals />`, server-rendered, form-post, no build step.
  Approval widget + paginated audit page + basic (non-streaming) chat thread. Publishable views.
- **Livewire (end-user, flagship):** chat with **inline approval cards** (stream → card mid-thread →
  resolve in-flow → resume), live inbox, live decision feed. *Feed depends on §6.6/§6.7.*
- **Filament (ops console, a plugin):** approval **queue** Resource; evidence **browser** Resource
  (*depends on a non-null recorder + §6.6 correlation*); decision monitor widget; capability/limit
  inspection pages. Plus three ops surfaces mirroring existing commands:
  1. **Unresolved execution claims** — an indeterminate claim = executor threw after admission; the
     one queue where a human's action is genuinely required. Higher urgency than the evidence
     browser. [`src/Console/Commands/ListExecutionClaimsCommand.php` → `verdict:execution-claims`],
     [`ResolveExecutionClaimCommand.php` → `verdict:resolve-execution-claim`]
  2. **`verdict:validate` as a screen** — surfaces the #230 dead gate: `requiresConfirmation()` with
     no `executionTarget()` → `requestConfirmation()` returns `null`, so it never pauses and never
     reaches the inbox; execution later denies. [`src/Console/Commands/ValidateVerdictCommand.php`],
     [`src/VerdictManager.php` (requestConfirmation)]
  3. **Evidence-write / chain-gap alarms** off the §6.7 incident projection.

## 9. Packaging — three packages
- `fissible/verdict-console` — headless core (contracts, persistence, projections) **+ publishable
  Blade stubs**, no Livewire types. Working UI on install.
- `fissible/verdict-console-livewire`.
- `fissible/verdict-console-filament` (heavy dep, correctly isolated).
Blade forces no heavy dependency, so it lives in core, not a 4th package. Shared presentation goes in
a small view/presenter layer, not by merging Livewire into core.

## 10. What it deliberately does NOT do
Doesn't decide (Verdict) · doesn't persist conversations (Laravel AI) · doesn't define policy (app
code; config inspects) · doesn't own who-may-approve (host Gate) · isn't tamper-evidence (Attest,
if enabled) · isn't a second authorization authority (but *does* own operational state, §6.2).

## 11. Build order — risk-first
Walking skeleton FIRST, before any CRUD, because this round trip is where the design can be *wrong*
(everything else is where it can only be *late*):

**`ToolApprovalRequested` → PendingApproval → human approve → `ApprovalManager::approve` → resume
with a tool-call-id-keyed decision → the tool executes exactly once.**

Only once that holds end-to-end: notifications, evidence/correlation projections, incident
projection, then Blade → Livewire → Filament surfaces. Per Codex, **defer the live decision feed and
the broad evidence browser** until their durable projections exist — they are not safe to build on
the default (blank recorder, ephemeral events, no conversationId).

## 12. Hazards / learned the hard way (do not re-discover these)
Empirical, from #218/#234 and the two reviews. Each has cost real time before.
- **`approveAll()`'s wildcard is deliberately ignored** by Verdict's execution context — resume with a
  specific tool-call-id decision. [`src/Approvals/ApprovalExecutionContext.php`]
- **Two invocation ids per two-turn resume** — never key durable state on the invocation id.
- **`ToolApprovalResolved` ≠ human decision** (post-resume tool results).
- **`UnsafeOuterTransaction`** — resolution can't run inside a wrapping `DB::transaction`.
- **`NullEvidenceRecorder` is the default** — evidence surfaces are blank until configured; say so.
- **Anomaly events are once-per-process / ephemeral** — project them or lose them.
- **Never resume with `continueLastConversation()`.** It selects the participant's *most recently
  updated* conversation (`DatabaseConversationStore::latestConversationId()` orders by `updated_at
  desc`), not the paused one. With one conversation per participant those coincide, which is why the
  pattern looks correct in single-run tests; with two concurrent runs the approved decision lands on
  the wrong one. Verdict's gates still hold — the receipt is bound to a tool call — so nothing fails
  closed and nothing detects it. Resume with `continue($conversationId, $participantOrNull)` using
  the id captured at pause time. Measured and filed as
  [fissible/verdict#265](https://github.com/fissible/verdict/issues/265), which also fixes the
  reference test that taught it.
- **Laravel AI's participant is an object, and it is not durable.** Do not persist
  `conversationUser`, and do not encode it as class-name-plus-id and rebuild by convention — that
  invents an identity model on the host's behalf and breaks the moment a participant needs a tenant,
  a guard, or a constructor argument. The default resume attaches no participant at all; a host that
  needs one supplies an opaque reference *and* the resolver that rebuilds it (VC-4's
  `participant_reference`).
- **A conversationless pause cannot be resumed at all.** `continue()` requires a string conversation
  id, and `PendingApproval.conversation_id` is nullable, so drivability is *receipt* **and** *resolver
  key* **and** *conversation id* — not the first two alone.
- **Agent reconstruction needs a host-supplied resolver key, not just class + participant** —
  class+participant can't rebuild an agent with runtime constructor args, tenant context, or a
  specific provider/model. Validate the resolver at ingestion, or an approval commits and becomes
  unresumable (§6.1, §6.3).
- **`requiresConfirmation()` without `executionTarget()` never pauses** (#230) — preflight for it.
- **`Agent::fake()` never resumes** — the skeleton needs a real gateway.
- **Resume needs `Conversational`; *durable* resume also needs `RemembersConversations` + a
  `ConversationStore`.** `Conversational` gates pause/resume and its absence throws
  `ApprovalNotResumableException` (**loud**). `RemembersConversations` (+ a current conversation) gates
  durable persistence of resolved results; *its* absence is the silent one — a later out-of-band
  resume can't reconstruct. Don't conflate them.
  [`vendor/laravel/ai/src/Providers/Concerns/ResumesToolApprovals.php`]
- **`VerdictApprovalMiddleware` is NOT auto-registered** — the agent must declare it via
  `HasMiddleware`, or `ApprovalExecutionContext::allows()` is false for every call and an approved
  receipt fails proposal-validation with `invalid_state`. **The single most expensive trap in #218.**
  (`RememberConversation` *is* auto-registered when the agent uses `RemembersConversations`
  [`vendor/laravel/ai/src/Providers/Concerns/GeneratesText.php:155`] — so it is *not* the trap;
  `VerdictApprovalMiddleware` is.)
- **`challengeForToolCall()` returns null for an expired or non-pending receipt** — the inbox must
  render "row exists, null challenge" as expired / already-decided, not as an error or an actionable
  item.
- **Cross-actor receipt consumption is not yet end-to-end tested (#167 open).** The console's
  multi-approver inbox is the first thing that makes "actor A consumes actor B's receipt" reachable
  in anger — the loop stands on a guarantee no test pins yet.
- Other trap: receipt not approved in Verdict before resume.

## 13. Decisions made
- **Name:** `verdict-console` (fissible-literal, discoverable from Verdict).
- **Config surface:** inspect-only in v1 (fingerprint reasoning, §6.8).
- **Real-time:** polling default, broadcast opt-in.
- **Scope:** full console is the destination; walking skeleton is built first; read-heavy surfaces
  each carry a named projection dependency (they are not deferred *out*, but not built until their
  projection exists).

## 14. Open items for PM
- Companion Verdict issue? — whether to add a receipt **read/enumeration API** to
  `ApprovalReceiptStore` for generic (non-`ApprovalManager`) integrations (§5). Not required for v1.
- Relationship to reference app **#237** and **#218** (Conversational resume): #218 is **closed
  (milestone v0.8.0), proven on both transports — streamed #233, queued #235** — *not deferred*. This
  package leans on that shipped mechanism; the implementer should read the #233/#235 reference tests
  before writing the skeleton. Sequence this after #218 (done), alongside/after #237 as PM decides.
