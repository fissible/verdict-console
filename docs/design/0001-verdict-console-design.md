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

## 5. Verdict-side read dependency (filed)

`ApprovalReceiptStore` exposes only `findForToolCall($toolCallId)` (which returns `null` on
ambiguity) — **no `find(receiptId)` and no list/query API.**
[`src/Contracts/ApprovalReceiptStore.php`], [`src/Approvals/DatabaseApprovalReceiptStore.php`]

Consequence: Verdict cannot enumerate receipts for an inbox. Resolution of the tension:

- **The console's own `PendingApproval` table is the workflow/correlation index** (the inbox lists
  from it); it is not a substitute receipt read-model.
- **Per-row authoritative status is read from Verdict via `ApprovalManager::challengeForToolCall()`**
  — *not* the store's `findForToolCall()`, which is named above only to describe what the contract
  offers. A non-null challenge means "pending, unexpired, actionable"; null collapses **absent,
  ambiguous, non-pending, and expired** into one answer, and **the console cannot tell them apart** —
  see §6.3, which records the indistinguishability rather than guessing past it. Transitions
  go through `ApprovalManager::approve/reject` keyed by `receiptId + toolCallId + actor`, and the
  **returned outcome** — never the actor's intent — decides whether the run resumes (§6.4).
  [`src/Approvals/ApprovalManager.php`]
- The console therefore integrates **through `ApprovalManager`**, not by reaching into the store.
  [verdict#298](https://github.com/fissible/verdict/issues/298) is the filed per-receipt read and
  enumeration dependency: it is where generic receipt reads belong, and every console feature that
  needs more than `challengeForToolCall()` waits for that contract rather than inferring state here.

## 6. Layer 1 — headless runtime

### 6.1 `PendingApproval` row
- **The row has its own surrogate primary key.** It cannot be the receipt id, because a receiptless
  approval exists (§3). `receiptId` is a **nullable authoritative reference** — set for `BoundTool`
  approvals, null for receiptless/ambiguous ones (§6.3) — **unique when present**.
- **Ingest idempotency** keyed on `toolCallId (+ conversationId)`, so a redelivered
  `ToolApprovalRequested` returns the row that already records the pause rather than adding a second
  one. **First-write-wins: it does not update that row.** By the time a redelivery arrives the
  original may have been annotated — marked unresumable, given a resolver key, given an
  `unresumableReason` — and a duplicate event carries no newer truth than the row it duplicates, so
  an upsert would discard a real observation in favour of a stale repeat.
- **Correlation annotations**, captured at pause time (the only chance): `toolCallId`,
  `conversationId`, `participantReference`, `invocationId` (for evidence correlation, §6.6). The
  participant is an **opaque host-supplied reference**, never Laravel AI's participant object and
  never a class-name-plus-id convention — that object is not durable, and rebuilding one by
  convention guesses at the host's identity model. `ConversationParticipants` is the host seam that
  turns the live object into that reference and back. The package ships **no working default** —
  `UnconfiguredConversationParticipants` refuses, because returning null would be a *claim* that this
  pause needs no participant and it cannot know that. A participant-bound pause without a faithful
  round trip is `unresumable`; it is never resumed with a null attachment.
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

**Not all of it lands at once, and one item cannot land yet.** VC-9 ships notification idempotency,
delivery state, and resume attempts; assignment and SLA timers wait for the first operator surface
(v0.4). The **agent-reconstruction version has no producer**: `ResumableAgents` reports no such value,
so recording one would mean this package inventing a version on the host's behalf — the same mistake
as reconstructing a participant from a class name and an id. It is deferred until that contract gains
a way to report it (#51), and the column and its consumer are separately scheduled after that.

**Reconciliation detects and abandons; it does not retry, and it names two phases rather than three.**
A retry after `approve()`/`reject()` needs the decision back, and there is no read for it:
`challengeForToolCall()` is pending-only by construction and `ApprovalManager` publishes no resolved
status. Persisting the decision so it could be re-sent would be a second copy of authorization state
under another name — the one thing §5 forbids — so retry waits on
[verdict#298](https://github.com/fissible/verdict/issues/298)'s per-receipt read (VC-45), and is not
built here in the meantime. Likewise the phase: a failure raised *before* `prompt()` is definitely
pre-execution, and one raised *by* it is **indeterminate**, because Laravel AI executes the approved
tools inside `prompt()` before handing results to the recorder. Execution-claim status would settle it
and is not reachable — the raw claim id goes only to the executor, the claim row has no `tool_call_id`,
and evidence keeps only its fingerprint. That correlation is VC-16's.

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

  **This is also the intentional stopping point for reconstruction.** Challenge availability is the
  first failed drivability check, so the bridge does not call a host resolver after it returns null
  and leaves `resolverKey` null. A known key is retained only when the later resolver check itself
  fails, where it gives recovery something concrete to repair or retire.

**Drivability needs four conditions, not three.** A row is `drivable` only with a receipt **and** a
resolver key that resolves **and** a `conversationId` **and**, when Laravel AI supplied a participant,
a participant reference that round-trips to the same Laravel AI type/key. `continue()` requires a
string id and `PendingApproval.conversation_id` is nullable, so a conversationless pause has nothing
to continue into and is `unresumable` however good the other conditions are. Likewise,
`DatabaseConversationStore::storeApprovalResults()` filters a paused turn by participant type/key as
well as conversation id; attaching null to a participant-bound row cannot match it.

Correlation annotations (§6.1, incl. `invocationId ↔ conversationId`) are captured at this boundary,
**and the host-supplied resumable-agent key is resolved and validated here — detectively, never as a
refusal.** A row whose agent cannot be reconstructed is still written, marked `unresumable`, recorded
as an incident (§6.7), and handed to the host's recovery protocol.

**The incident is an event, and it is ephemeral until VC-15.** `ApprovalIngestionIncident` is *one*
event carrying a typed `UnresumableReason` — `challenge_unavailable`, `agent_unresolvable`, or
`conversation_absent`, or `participant_unresolvable`, the four drivability conditions — dispatched at ingestion, with a default
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

A host presenter is a disclosure seam, not a drivability condition. If it throws, the bridge stores
the row with no presentation and logs the failure; it must not turn an otherwise drivable run into a
false `unresumable` diagnosis. **The guard covers the encode, and it normalizes rather than
validates.** `ApprovalPresentation::details` is host-owned, so a presenter can return cleanly and
still hand back something JSON cannot represent — and `mixed` also admits `JsonSerializable`, which is
host code that runs *during* encoding. The store encodes again with `JSON_THROW_ON_ERROR` after this
guard, and a second encode of the same array re-invokes that host code: a stateful implementation can
satisfy a check and fail the write, whose exception is then filed as a malformed item — writing no row
at all, the outcome this rule exists to forbid. So the bridge hands the store the value **decoded back
from the bytes it proved**, which holds JSON-native values only. Nothing of the host's is left to run,
and the store's encode can neither invoke anything nor fail for this input. Likewise, a host resolver or matcher throwing is observed as
`agent_unresolvable`, and one malformed pending call is isolated so its siblings still ingest.

Participant identity is per-pause and therefore cannot be covered by VC-3's startup doctor. Ingestion
is the only point that has both the live participant and the chance to persist its opaque reference;
it checks this last, after challenge → agent key → agent resolution → conversation id, and records
`participant_unresolvable` for either mint/rebuild failure or a type/key mismatch.

**What a wrong answer here costs, measured rather than assumed.** Resuming a participant-bound pause
with a null attachment does not decline harmlessly. `TextGenerationLoop` executes the approved tools
in `resumeFromApproval()` and only then hands the results to the recorder that rejects them, and the
rejection happens inside `storeApprovalResults()`'s own transaction — so the end state is three
things at once: the consequential action **ran**, the Verdict receipt is **spent**, and the
conversation turn **still says it is waiting for a human**. That is a divergence between what
happened and what is recorded, discovered only after a human has already approved. It is the reason
this condition is checked at ingestion instead of found at resume, and it is pinned by an end-to-end
negative control rather than inferred from reading laravel/ai.

**A database write failure is the hard limit.** If the console cannot durably insert the row, it
cannot surface or resume that already-paused run. The listener keeps its siblings going, emits no
false ingestion incident, and logs a **critical** `could not durably record` failure for the host's
alert/retry path. A unique receipt collision is separately critical: it means two pauses attempted to
index one authorization receipt, not a malformed event item. Neither outcome may be filed as routine
per-item input noise.

### 6.4 Resolution bridge (human → Verdict → run)
On a human decision: check approver authority (host `Gate`, §7) → `ApprovalManager::approve/reject`
(receiptId, toolCallId, actor) → resume the conversation **with a specific decision keyed by the
tool-call id — never `approveAll()`** (Verdict deliberately ignores the wildcard).
[`src/Approvals/ApprovalExecutionContext.php`]
- On deny: resume with a clean refusal so the agent never hangs.
- **Expiry `close` is a workflow exit, never an authorization decision.** When the live challenge is
  null, `close` resumes the exact conversation with a keyed `Decision::reject()` without calling
  `ApprovalManager::approve/reject`. It is gated by the configured approval ability, records a
  resume attempt, and cannot execute a tool through that rejection. Null also covers an
  already-decided receipt; Laravel AI's measured already-resolved response is reported as such,
  while every other continuation failure remains phase-specific reconciliation rather than a false
  success. Expiry has no transition moment and Verdict never auto-rejects it. VC-45 narrows that
  both-halves defence after verdict#298 exposes receipt status.
- **Must run OUTSIDE any outer `DB::transaction`** — Verdict throws `UnsafeOuterTransaction` via
  `SecurityStateTransaction → IndependentTransactionGuard`.
  [`src/Support/SecurityStateTransaction.php`], [`src/Support/IndependentTransactionGuard.php`]

### 6.5 Notifications
Mail / database / Slack / broadcast. VC-11's notifications are limited to observations the console
actually receives: a newly indexed pause, its own returned approval/rejection transition, and Laravel
AI's `ToolApprovalResolved` continuation event. They never say an action completed or a receipt was
consumed: the event carries post-resume tool results, not an execution-claim lifecycle, and a null
challenge cannot distinguish consumption from expiry, rejection, ambiguity, or absence. Copy obeys
the ADR 0028 ceiling by reporting the observation rather than inventing its unobservable consequence.

### 6.6 Evidence read-models
Over `DecisionEvidence`, honoring ADR 0008 (fingerprints, not raw), surfacing `claimType` +
`recordDigest`. Two hard constraints:
- **Default recorder is `NullEvidenceRecorder`** — these surfaces are dark on a default install *by
  design*. The UI must detect it and say *"recording is off — blank by config,"* never render an
  empty table that reads as "nothing happened." [`config/verdict.php`]
- **`DecisionEvidence` has `invocationId` but no `conversationId`.**
  [`src/Evidence/DecisionEvidence.php`] "Filter evidence by conversation" is therefore **not native** —
  it depends on a console-owned correlation projection (invocationId ↔ conversationId), captured at
  the `AgentPrompted` and `AgentStreamed` completion boundaries. The approval events fire after those
  boundaries in the same gateway call with the same response, so they only re-observe a correlation
  already recorded. The host must run `VerdictProvenanceMiddleware`: it pushes the
  `InvocationContext` frame VerdictManager reads when stamping `invocation_id` on decision evidence;
  without it every decision row carries null and the join is empty.
  [#72](https://github.com/fissible/verdict-console/issues/72) ships the `verdict-console:doctor`
  findings `evidence_correlation_middleware_missing` and `evidence_correlation_table_missing`. Until
  the migration runs, the listener logs an error for each completed turn and continues, and every
  conversation reads as **Unknown**. The projection retains every remembered invocation, including
  one that **produced no decision evidence**. A conversation with no remembered invocation is reported
  as **Unknown**, never as empty.

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
`ConfigurationInspection` is the shipped read boundary for capabilities with their fingerprints,
rate limits, and approval rules; it deliberately has no write path.

## 7. Cross-cutting
- **Who may approve is the host's call** — Laravel `Gate` evaluates the configured
  `verdict-console.approvals.gate` ability (default `approve-verdict-action`) for the pending row;
  the package ships that default but never hard-codes authority.
- **Tenancy/scoping delegates to the host.** `ApprovalScope` receives the console's
  `PendingApproval` query and applies the host's tenant or ownership boundary; the console neither
  adds a tenant column nor derives one from a conversation or participant. The same scoped read
  gates rendered verbs and a direct resolve/close call, so a model retained across a tenant switch
  is neither visible nor actionable. **It does not scope console correlation work:** ingest
  read-backs, resume locks, and Laravel AI event joins must survive a worker with no current tenant,
  because visibility is an operator boundary rather than a condition for recording a pause.
  [`src/Contracts/ApprovalScope.php`]
- **Real-time transport degrades:** polling default (no infra); broadcast (Reverb/Pusher) opt-in.

## 8. Layer 2 — surfaces, specialized by audience
Each read-heavy surface carries its **projection dependency** explicitly (§6.6–6.7); none is assumed
free.

- **Blade (embed):** `<x-verdict-console::approvals />`, server-rendered, form-post, no build step.
  The approval-inbox widget renders ADR 0001's state, verb, and provenance contract from its live
  read model; `VerdictConsoleRoutes` mounts its forms at boot by default because
  every endpoint is fail-closed behind the host's Gate.
  A host opts out with `VerdictConsoleRoutes::ignoreRoutes()`
  or `verdict-console.routes.register`; an opted-out widget renders its rows without forms, and any
  future install or setup command must ask whether to mount the console routes. The package follows
  the first-party convention here despite publishing (not loading) its migrations: mounting exposes
  nothing an unauthorized caller can use, whereas a migration changes the host's schema.
  Approval widget + paginated audit page + basic (non-streaming) chat thread. Publishable views.
  `<x-verdict-console::evidence />` is the paginated audit table over the VC-13 boundary: when
  §6.6 says "recording is off — blank by config," it stays blank rather than implying no decisions.
  Its evidence-display audience is host-governed: the host embeds it behind its own authorization;
  the page adds no gate of its own.
  Chat surfaces start and continue conversations through the host's `ChatEntry` contract
  (participant plus resumable-agent key, `verdict-console.chat.entry_key`), check ownership
  against Laravel AI's recorded participant, and read the thread through the host's
  `ConversationStore`.
  `<x-verdict-console::chat />` posts messages to `verdict-console.chat.send`, then renders the
  thread again on reload. Its approval interrupt is inline through the approvals widget scoped to
  the conversation; resolution through VC-6's forms continues that thread on reload.
  It does not stream: streaming is the Livewire surface's job.
- **Livewire (end-user, flagship):** chat with **inline approval cards** (stream → card mid-thread →
  resolve in-flow → resume), live inbox, live decision feed. *Feed depends on §6.6/§6.7.*
- **Filament (ops console, a plugin):** approval **queue** Resource; evidence **browser** Resource
  (*depends on a non-null recorder + §6.6 correlation*); decision monitor widget; capability/limit
  inspection pages. Plus three ops surfaces mirroring existing commands:
  1. **Unresolved execution claims** — an indeterminate claim = executor threw after admission; the
     one queue where a human's action is genuinely required. Higher urgency than the evidence
     browser. [`src/Console/Commands/ListExecutionClaimsCommand.php` → `verdict:execution-claims`],
     [`ResolveExecutionClaimCommand.php` → `verdict:resolve-execution-claim`]. The headless
     `ExecutionClaimService` behind that surface lists and authorizes resolution through
     `ExecutionClaimManager`, checking the `verdict-console.execution_claims.gate` ability.
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
  a guard, or a constructor argument. A participant-less pause resumes with no attachment; a
  participant-bound pause needs an opaque reference *and* the resolver that rebuilds the same Laravel
  AI type/key (VC-4's `participant_reference`).
- **A conversationless pause cannot be resumed at all.** `continue()` requires a string conversation
  id, and `PendingApproval.conversation_id` is nullable, so drivability is *receipt* **and** *resolver
  key* **and** *conversation id* **and** *participant identity, when the pause has one* — not the
  first two alone.
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

## 14. Filed dependency cluster
- [verdict#297](https://github.com/fissible/verdict/issues/297) supplies the durable
  `require_review` substrate; the console does not synthesize a review inbox from evidence.
- [verdict#298](https://github.com/fissible/verdict/issues/298) supplies per-receipt status and
  enumeration reads (§5), gating status-aware console consumers including VC-45.
- [verdict#299](https://github.com/fissible/verdict/issues/299) supplies receipt-transition events;
  it does not create an expiry event because expiry has no transition moment.
- [verdict#300](https://github.com/fissible/verdict/issues/300) supplies `ApprovalChallenge::issuedAt`.
- [Verdict ADR 0029](https://github.com/fissible/verdict/blob/main/docs/adr/0029-approval-challenge-issuance-is-the-measured-fact.md)
  records the no-auto-reject rule used by §6.4. ADR 0029 postdates the Verdict documentation pinned
  in this checkout, so this direct upstream link is the auditable source until the dependency updates.
- Relationship to reference app **#237** and **#218** (Conversational resume): #218 is **closed
  (milestone v0.8.0), proven on both transports — streamed #233, queued #235** — *not deferred*. This
  package leans on that shipped mechanism; the implementer should read the #233/#235 reference tests
  before writing the skeleton. Sequence this after #218 (done), alongside/after #237 as PM decides.
