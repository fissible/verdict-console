# ADR 0001: The approval-surface contract — how a Verdict decision becomes a console item

Status: Accepted (2026-08-24) — amended 2026-08-25 for Verdict 0.11–0.12 and ADR 0031 (see the
**Amended** notes in §3, §4, Consequences, and §8; [#63](https://github.com/fissible/verdict-console/issues/63))

## Related issues

- Design of record [§6.3–§6.4](../design/0001-verdict-console-design.md) (disposition and resolution
  bridges) and [§7](../design/0001-verdict-console-design.md) (approver authority) are what this ADR
  makes precise. Where the two disagree, this ADR wins and the design doc should be corrected.
- [VC-5](https://github.com/fissible/verdict-console/issues/5) (closed) ingests the pause;
  [VC-6](https://github.com/fissible/verdict-console/issues/6) (open) resolves it;
  [VC-7](https://github.com/fissible/verdict-console/issues/7) (closed) is the authority contract;
  [VC-8](https://github.com/fissible/verdict-console/issues/8) (closed) is the persisted presentation.
  [VC-19](https://github.com/fissible/verdict-console/issues/19) and
  [VC-28](https://github.com/fissible/verdict-console/issues/28) are the first surfaces that render
  what this ADR specifies.
- Verdict [ADR 0026](https://github.com/fissible/verdict/blob/main/docs/adr/0026-what-an-approver-is-shown.md)
  defines the payload an approver is shown and why surfacing provenance is a context release under
  [ADR 0008](https://github.com/fissible/verdict/blob/main/docs/adr/0008-evidence-privacy-model.md).
  This ADR renders that payload; it does not define one.
- Verdict [ADR 0029](https://github.com/fissible/verdict/blob/main/docs/adr/0029-approval-challenge-issuance-is-the-measured-fact.md)
  §1 decides that an unanswered challenge is a legitimate terminal state with no auto-deny. The
  expiry rules below inherit that.
- Verdict [#230](https://github.com/fissible/verdict/issues/230) (open) is the confirmation gate that
  never pauses; the console's obligation toward it is stated in §6.
- The Verdict-side gaps this design needs are filed, and §8 records them with their status frame:
  [#297](https://github.com/fissible/verdict/issues/297) (RequireReview substrate — keystone),
  [#298](https://github.com/fissible/verdict/issues/298) (approval read contract),
  [#299](https://github.com/fissible/verdict/issues/299) (receipt-transition events),
  [#300](https://github.com/fissible/verdict/issues/300) (`ApprovalChallenge::issuedAt`).
  **#297–#299 are Proposed-contract territory**: this ADR designs against their stated shapes as
  dependencies, and the console builds nothing against them until they ship. #300 is ungated.
- **Amended 2026-08-25.** Verdict [#305](https://github.com/fissible/verdict/issues/305) (v0.12.0)
  made `ApprovalDecisionAuthorizer` a required, fail-closed contract on `approve()`/`reject()` and
  added `approval_context` to receipts; Verdict
  [ADR 0031](https://github.com/fissible/verdict/blob/main/docs/adr/0031-approval-reads-are-observational-and-scoped.md)
  settled #298 as `ApprovalStatusReader` (implemented in #327, on `main`, **not yet in a tagged
  release**). Console [#63](https://github.com/fissible/verdict-console/issues/63) is the
  compatibility pass; the authorization-layering decision it asked for is §4's amendment.

Every file reference below was checked against `fissible/verdict` at `5b7050f` (v0.10.0) and this
repository at the commit this ADR lands in; the 2026-08-25 amendments were checked at `a84cbed`
(post-v0.12.0 `main`). Line numbers drift; the symbol names do not.

## Context

Verdict's `Disposition` (`src/Decisions/Disposition.php:7-14`) has five values: `Permit`, `Deny`,
`RequireConfirmation`, `RequireReview`, `Throttle`. Verdict's own framing
(`docs/research-log.md:1774-1779`) is the runtime-verification three-valued domain: `Permit` is ⊤,
`Deny` is ⊥ — terminal — and the two `Require*` cases are `?`, the monitor declining to conclude and
going to acquire the missing information, which for both of them is a human. `Throttle` is a
rate-limit refusal with a reset time, not a verdict about the action itself.

`Decision::permitsExecution()` (`src/Decisions/Decision.php:58-61`) collapses all five to a boolean at
the admission point: only `Permit` executes. That is the fail-closed reduction the console must never
undo.

The console sits between three parties and owns none of the decisions:

- **Verdict** decides, mints and transitions approval receipts, records evidence.
- **Laravel AI** pauses a run on `ToolApprovalRequested` and resumes it from a `Decisions` map.
- **The host** owns who may approve, through its `Gate`.

What has not been written down is the contract in the middle: which dispositions become something a
person can act on, what "act on" is allowed to mean, and what the person must see before acting. The
design doc answers pieces of this in §6.3, §6.4 and §7; the walking skeleton (VC-1) and the bridges
(VC-5, VC-6) have since measured several of the assumptions. This ADR consolidates the contract so
that the first rendering surfaces (VC-19, VC-28) build on a stated rule rather than an inferred one —
and so that one rule in particular, §2, cannot be eroded by a well-meaning button.

## Decision

### 1. Disposition → console item

| Disposition | RV value | What Verdict produces at runtime | Console item | Verbs | Source the console reads |
| --- | --- | --- | --- | --- | --- |
| `RequireConfirmation` | `?` | An `ApprovalReceipt` (pending, TTL) and a Laravel AI pause | **Actionable — synchronous lane** | `approve`, `reject` | `ToolApprovalRequested` → `ApprovalManager::challengeForToolCall()` today; the #298 read contract once it ships |
| `RequireReview` | `?` | Today: evidence and a structured refusal only. Intended (#297, Proposed): a durable `ReviewRequest` record; the invocation still refuses | **Actionable — asynchronous lane** (contract defined here; substrate is #297) | `approve`, `reject` as a recorded human verdict; never an execution | *None today.* #297's record, read through #298 |
| `Permit` | ⊤ | Execution proceeds; `AuthorizationDecision` / `ExecutionAdmission` evidence | **Informational** (decision feed / audit only) | none | Evidence read-model (VC-13) |
| `Throttle` | rate-limited | `RateLimitRefusal` evidence with `rateLimitResetAt` (`src/Evidence/ClaimType.php:123-125`) | **Informational** — "refused until *reset*" | none | Evidence read-model (VC-13) |
| `Deny` | ⊥ | A structured refusal to the model; `AuthorizationDecision` evidence | **Terminal** — visible in audit, **never an item** | none | Evidence read-model (VC-13) |

Three consequences of the table, each of which a contributor could plausibly get wrong:

- **Only one disposition has a live Verdict artefact today.** `RequireConfirmation` is the only path
  that mints a receipt (`VerdictManager::requestConfirmation()`, `src/VerdictManager.php:331-354`,
  through `ApprovalManager::issue()`, `src/Approvals/ApprovalManager.php:38-68`) and the only one
  Laravel AI pauses on (`AbstractVerdictTool::shouldRequestApproval()`,
  `src/LaravelAi/AbstractVerdictTool.php:135-142`, returns `Approval::required($decision->reason)`).
  Everything else reaches the console only through evidence, which is `NullEvidenceRecorder` by
  default (design §6.6). The informational rows are therefore *blank by config* on a default install,
  and the surfaces must say so.
- **`RequireReview` is an actionable lane by contract and an empty lane by implementation.**
  `VerdictManager::runBound()` (`src/VerdictManager.php:205-211`) admits only `Permit` and
  `RequireConfirmation`; a `RequireReview` decision is returned to the model as a structured
  `not_executed` refusal carrying `decision: require_review` (`README.md:125`) and recorded as
  `AuthorizationDecision` evidence. No receipt, no event, no store — a policy author can return it
  and get a silent denial with no human involved, which is #230's failure mode in a second location.
  The console cannot build a review inbox from what Verdict exposes now. #297 is the substrate, and
  its intended shape (§8) matches this lane's contract as written: record-only, the invocation still
  refuses, `reviewed` is a transition and not a token. This ADR fixes the lane's contract (§3) so
  that when #297 ships, nothing here changes shape.
- **Informational rows never grow verbs.** A `Throttle` row does not get "lift the limit" (a config
  write; design §6.8 is inspect-only, and the capability-configuration fingerprint in every evidence
  record is why). A `Permit` row does not get "revoke" — the action has already been admitted. A
  `Deny` row gets nothing at all, per §2.

### 2. A Deny is not approvable — the invariant

**The console never offers any verb on a denied call.** Not "approve anyway", not "override", not
"escalate to someone who can approve", not "edit and retry". A `Deny` is Verdict's ⊥: *all
continuations violate the policy*. There is no human decision left to collect, because the policy
already collected the only one it wanted.

This is the load-bearing rule of the whole design, and it is worth spelling out why it is not
merely conservative:

- **Verdict is the boundary; the console is a window onto it.** The design of record says the console
  "never decides … never becomes a second authorization authority" (§2, §10). An override button is a
  second authority by construction: it turns "Verdict said no" into "Verdict said no, *unless*". The
  first time it is clicked, every downstream guarantee — the argument-bound receipt, the refreshed
  target (ADR 0003), the at-most-once claim — is protecting an action that the authorizer refused.
- **It is an authorization-bypass channel with a login page.** Prompt injection is the threat model
  Verdict exists for. A model that cannot get an action permitted, and cannot get it confirmed, would
  have exactly one avenue left: get a human to click override on a denial. The console would be
  manufacturing the confused-deputy path (Verdict ADR 0014) that the tool boundary closed.
- **The correct escape hatch already exists and is not the console's.** If a class of would-be-denied
  request should sometimes reach a human, that is the *policy's* decision: the authorizer returns
  `Decision::requireReview()` (or `requireConfirmation()` where a target binding exists) instead of
  `deny()`. Routing to a human is a policy outcome, chosen in application code, recorded in evidence
  under the capability's configuration fingerprint. It is not a console feature layered on top of a
  refusal.

The invariant extends past the literal `Deny` disposition to everything that *is* a denial from the
console's point of view:

- **A null challenge is not approvable.** `challengeForToolCall()` returns null for an absent,
  ambiguous, non-pending *or* expired receipt (`src/Approvals/ApprovalManager.php:70-81`), and the
  console cannot tell those apart (design §6.3). A row with no live challenge renders as
  *no longer actionable*, never with approve/reject.
- **An execution-stage denial of an approved receipt is not re-approvable.** After a human approves,
  Verdict still refreshes the target and re-authorizes (ADR 0003, ADR 0013). If that stage denies —
  target changed, claim refused, receipt binding mismatch — the receipt is spent or invalid and the
  denial is final for *that* proposal. A fresh proposal earns a fresh challenge; the console does not
  resurrect the old one.
- **A non-drivable row is not approvable.** `Resumability::Unresumable` is a statement that this
  console cannot drive the run; the row is surfaced for recovery, not for decision.
- **No wildcard, no bulk, no edit.** `ApprovalExecutionContext::push()` deliberately ignores the `'*'`
  key (`src/Approvals/ApprovalExecutionContext.php:33-43`), Laravel AI's `Decision::edit()` breaks
  the receipt's `bindingFingerprint` and is not admitted (VC-28), and bulk approval is deferred until
  per-row authorization and partial failure are specified (VC-28). Each of these is a smaller version
  of the same mistake: widening one human decision beyond the one argument-bound tool call it was
  asked about.

A test pins this in every rendering surface: the set of verbs rendered for a row whose live
disposition is anything but a pending `RequireConfirmation` challenge is empty. See Consequences.

### 3. The two actionable lanes differ in lifecycle, not just in latency

**Amended 2026-09-01 — Verdict #299 shipped as one `ApprovalReceiptTransitioned` event.** Rather
than four separate event classes for issued, approved, rejected, and consumed, the event carries the
receipt and tool-call identities, resulting status, and occurrence time. The console observes only
terminal transitions for an already-indexed matching pair; `Pending` remains solely the
`ToolApprovalRequested` ingestion concern.

| | Synchronous — `RequireConfirmation` | Asynchronous — `RequireReview` |
| --- | --- | --- |
| What is waiting | A paused Laravel AI run and a pending receipt | Nothing. The run already received a refusal and moved on |
| Item created by | `ToolApprovalRequested` ingestion (VC-5) | Reading #297's `ReviewRequest` records through #298 — there is no pause event to listen for, because nothing pauses |
| Authoritative state | Verdict's receipt, read live via `challengeForToolCall()`; the console holds no copy | #297's record status (`pending` → `approved`/`rejected`), read through #298; the console holds no copy |
| Time-box | Receipt TTL: the capability's `requiresConfirmation(ttlSeconds:)` or `verdict.approvals.ttl_seconds` (900 s default, `config/verdict.php:48`), stamped on the receipt at issuance | No Verdict TTL. Any deadline is console-owned operational state (design §6.2: SLA timers) |
| `approve` means | Transition the receipt (`ApprovalManager::approve`) **and** resume the exact conversation with a tool-call-id-keyed `Decision::approve()`; the tool then executes at most once behind the receipt | Drive #297's `reviewed` transition with the actor key and a reviewer reason. **It executes nothing and mints nothing** — no receipt, no re-proposal artefact. What the application does with an approved review is application policy |
| `reject` means | Transition the receipt **and** resume with `Decision::reject()` so the agent gets a clean refusal and never hangs | The same transition with the opposite outcome. Same effect on any run: none — the model already received its refusal, which under #297 carries the review-request id |
| Drivability | The four conditions of design §6.3 (challenge, resolver key, conversation id, participant round-trip) | Not applicable — there is no run to drive |
| Resume precondition | Only an `Approved` / `Rejected` transition outcome resumes; `Expired`, `NotFound`, `Mismatch`, `InvalidState` never do (VC-6) | n/a |

**Why `approve` on the review lane is record-only, and must stay so.** A reviewer who "approves" a
review has not authorized an execution — the run is gone, and there is no argument-bound receipt to
spend. If the console let a review approval mint authority for a *future* proposal, it would be
issuing a blanket allow: exactly the thing §4 says a grant must never be. The honest semantics are
that the review lane produces a recorded human decision the host may act on (perform the action
through its own permissioned path, adjust the policy, or do nothing). #297 states the same intent —
"`reviewed` is a transition, not a token" — and if that ever changes it changes on #297's thread
first, not here.

**What this ADR does not pre-decide about #297.** Three questions live on that issue and this
document takes no position on them: the shape of the refusal payload the model receives; whether a
reviewer reason is mandatory on resolution; table naming and migration. The console's lane design
depends on none of them. When #297 settles, the only console-side consequence is the read shape it
ingests through #298.

**What happens on expiry.**

*Synchronous lane.* The receipt passes `expiresAt`; `challengeForToolCall()` starts returning null;
`approve`/`reject` would return `ApprovalOutcome::Expired` and the console would not resume. Verdict
does not auto-deny, does not sweep, and does not resume anything (ADR 0029 §1: a decision nobody made
must not be written into durable security state). Laravel AI has no expiry concept for a paused turn
at all. So the measured end state is: **receipt lapsed, conversation still paused, human never
decided.** The console's obligations are:

1. Render the row as *no longer actionable* — and because null collapses expired with decided, the
   copy says "expired or already decided", never one or the other (design §6.3). It must not render
   as an error, and it must not render approve/reject. #298's per-receipt status read is what lets
   this copy say which; until it ships, the collapsed wording stands.
   **Amended 2026-08-25:** ADR 0031 §5 defines the split exactly — `Approved`/`Rejected`/`Consumed`
   is *already decided*; `Pending` with `expiresAt` in the past is *lapsed, undecided* — and the
   reader reports persisted status plus the deadline, never a synthesised `Expired`. The console
   compares clocks. That is VC-45's work, gated on the Verdict release that carries #327, which
   `v0.12.0` does not.
   **Expiry has no transition moment, and never will.** A TTL passes silently; Verdict observes
   expiry only at validate/consume time. So #299's events — `issued`, `approved`, `rejected`,
   `consumed` — will never include a reliable `expired`, and the console must not design a listener
   for one. The stale-actionable window for an expired row is bounded by the poll interval
   (`verdict-console.polling.interval_seconds`) regardless of events; #299 shrinks the window only
   for operator-resolved and model-consumed rows. Poll-consistency (read-committed, no push
   guarantee) is the freshness #298 will state, and the inbox is designed to that, not above it.
2. Never write a decision on the human's behalf. No auto-reject on expiry, for the same reason Verdict
   has none.
3. Offer the run a way out that is *not* an authorization act: a `close` verb that resumes the exact
   conversation with a tool-call-id-keyed `Decision::reject()` **without touching the receipt**. The
   tool is not invoked on a reject, so nothing consequential can run; the only effect is that the
   agent receives a refusal and the turn stops waiting. This is a workflow verb, permissioned like a
   decline (§4), and it is VC-10's to build because it is a reconciliation path, not a decision path.
   **One behaviour it depends on has not been measured**: what Laravel AI does when `continue()` +
   `prompt(Decisions)` is issued for a turn that is *not* pending (the already-decided half of the
   null). Per the org's rule that IO behaviour is validated against reality, `close` ships only with a
   test that exercises that case against the real gateway. Until then, expiry ends at obligation 2.

*Asynchronous lane.* Nothing in Verdict lapses. A console-owned SLA passing is an escalation event in
the console's own operational state, not a change to any authoritative record. The item stays open
until a human records a verdict.

### 4. Approval authority is itself authorized

Approving is a permissioned action on a specific row, not a role. The rule, already shipped in VC-7
and restated here as contract:

- **The approver must pass the host's `Gate` for this row**: `Gate::forUser($approver)->allows($ability,
  $pendingApproval)` (`src/Approvals/GateApproverAuthority.php`). The ability name is configurable
  (`verdict-console.approvals.gate`, default `approve-verdict-action`), so the design doc's shorthand
  `can('approve', $pendingApproval)` reads as *the configured ability* — the console never hard-codes
  authority (design §7).
- **It fails closed.** Laravel's Gate returns false for an undefined ability, so a fresh install
  approves nothing until the host defines the ability. An unauthenticated approver is refused *before*
  the Gate is consulted, so no `before()` callback can grant an anonymous approval.
- **The review lane gets its own ability.** Authority to confirm a live, paused, argument-bound action
  and authority to record a review verdict are different grants; the console must not reuse one ability
  for both. A second configurable ability (default `review-verdict-action`) is reserved by this ADR
  so the lane's authorization is settled before its substrate arrives; the config key lands with the
  lane's first implementation issue, not with this document.
- **`close` (§3) is a decline and is gated by the same ability as `reject`.** It is not a lesser verb:
  it ends a paused run with a refusal, and whoever may refuse may close.
- **The actor key is an audit label, not an identity to reconstruct** (`ApproverAuthority::actorKeyFor`).
  It reaches Verdict as `approvedBy` / `rejectedBy` — an opaque identifier per ADR 0008, never an
  email — and nothing in the console parses it back.
- **What the grant produces is single-use and argument-bound.** Approval transitions one
  `ApprovalReceipt` that was minted for one `toolCallId` with one `bindingFingerprint`
  (`ApprovalManager::issue()`), is consumed at execution (`ApprovalReceiptStatus::Consumed`), and
  authorizes nothing further. Verdict re-authorizes the refreshed target at execution regardless of
  the approval (ADR 0013's third binding layer). A human's yes is therefore *one more input* to a
  decision Verdict still makes — not a substitute for it.

**Amended 2026-08-25 — approval authority is authorized twice, by the host both times.** Verdict
0.12 (#305) makes `ApprovalManager::approve()`/`reject()` consult a **required**
`Fissible\Verdict\Contracts\ApprovalDecisionAuthorizer` — `authorize(ApprovalReceipt, ApprovalDecisionKind,
string $decidedBy): bool` — refusing every decision with `ApprovalAuthorizerMissing` when none is
configured, and returning the new `ApprovalOutcome::Unauthorized` without touching the receipt when
the configured one says no. The console now sits above a second authorization, and the layering is
fixed by the contracts' signatures rather than by preference:

- **Two layers, in ADR 0013's vocabulary.** The console's `ApproverAuthority` is the *identity/actor*
  binding — *may this authenticated person act on this row?* — and receives the `Authenticatable` and
  the `PendingApproval`. Verdict's `ApprovalDecisionAuthorizer` is the *authorization-request*
  binding — *is this receipt, with its `approval_context`, one this decider may finalize?* — and
  receives the receipt and an opaque `decidedBy` string. Neither can do the other's job.
- **The console must not bridge them.** A console-shipped authorizer delegating to the Gate would
  have to reconstruct a user from `decidedBy`, and the actor key is *an audit label, not an identity
  to reconstruct* (above). The console therefore ships **no** `ApprovalDecisionAuthorizer` and no
  default for the `verdict.approvals.authorizer` key: an allow-all default would be the override
  button §2 forbids, wearing Verdict's name. Hosts configure their own — Verdict's
  `make-approval-flow` now publishes a working, fail-closed one — and the console's documentation
  points there.
- **The join is the host's own key.** The actor key `ApproverAuthority::actorKeyFor()` emits *is* the
  `decidedBy` the host's authorizer receives. Both ends are host contracts, so the host controls the
  vocabulary; the console passes it through and parses nothing.
- **`Unauthorized` is a named refusal, not a routine non-approval.** It means the console's Gate let
  this person click and the host's Verdict authorizer refused them — the two layers disagree, which is
  a misconfiguration or an attempt. The console never resumes on it, raises the same
  `AuthorizationException` with the same message as a Gate or scope refusal (so membership cannot be
  probed by comparing errors), and dispatches an `ApprovalDecisionRefused` incident so an operator
  sees the disagreement. `ApprovalAuthorizerMissing` is a configuration error and propagates
  untouched; its prevention is a doctor finding, `approval_authorizer_missing`, because it would
  otherwise fire at the moment a human clicks approve.
- **`approval_context` is captured, not set.** The application supplies it in `ActionContext` at
  proposal time; Verdict carries it verbatim on the receipt and folds it into the binding fingerprint.
  The console persists a copy on its row at ingestion, read through `ApprovalStatusReader::statusFor()`,
  nullable. That is a correlation annotation, not mirrored status — Verdict documents the field as
  immutable after issue, which is what lets its own authorizer read it outside the transaction. It
  is the substrate for a scope that guarantees *what the console shows a person is a subset of what
  Verdict would let them decide*: an `ApprovalContextScope` implementation of VC-12's `ApprovalScope`
  keyed on the same identifiers, typed-exact, with `null`/`[]` rows never in scope — the same rule as
  ADR 0031 §3. The published `ApprovalScope` contract is not narrowed; this is the recommended
  binding, shipped additively.

This is the console's differentiator relative to HITL layers that bolt a pause onto an agent loop —
`padosoft/laravel-ai-guardrails` (HITL via `laravel-flow`) and `promptphp` (acts at approval pauses),
per the positioning notes, which are not re-verified against those packages' current releases here.
In those designs the pause *is* the control and whoever can reach the resume endpoint holds it. In
Verdict the pause is a request for one bounded input, the decision to grant it is delegated to the
host's existing authorization, and the grant cannot be widened by the person granting it.

### 5. What the item shows — the ADR 0026 payload, rendered not reinvented

The console has two sources of display data and must keep them distinct:

**(a) The persisted presentation** (`ApprovalPresentation`, VC-8), captured at pause time by the host's
`ApprovalPresenter`. It carries tool, capability, reason, a one-way argument fingerprint, and
host-owned `details`. It deliberately omits `expiresAt` and `provenance`
(`src/Presentation/DefaultApprovalPresenter.php` doc block): a durable copy of either would diverge
from Verdict.

**(b) The live challenge** (`ApprovalChallenge`, `src/Approvals/ApprovalChallenge.php:10-18`), read at
render time through `challengeForToolCall()`: `receiptId`, `toolCallId`, `capability`, `reason`,
`expiresAt`, and `?ProposalProvenance`.

**Decision: the actionable item renders (b) live, on top of (a).** Expiry comes from the challenge
every time the item is drawn, never from a snapshot. Provenance comes from the challenge too, and
rendering it is *not* a second context release: ADR 0026's update and ADR 0029 §2 establish that the
release to the approver audience already happened — or was refused — when `ApprovalManager::issue()`
materialised the payload inside the invocation under the application's `ApproverAudience` policy.
What is on the challenge is, by construction, what the application decided an approver may see. The
console draws it; it does not persist it, does not enrich it from the ledger, and does not infer from
invocation correlation (ADR 0026 §3). The presenter doc block's "never a package default" is about
*persisting a copy*; it does not forbid rendering the live payload, and should be reworded to say so.

**What an approver must see to resist confirmation fatigue.** ADR 0026 is built around one
distinction — a proposal that originated in the user's own instruction versus one that originated in
retrieved, tool-returned, or otherwise untrusted content. The item must make that distinction
impossible to miss and impossible to mistake for its absence:

- **Provenance disclosure state is rendered as one of four distinct things, never collapsed:**
  - `Declared` with sources → list each `UpstreamSource` by *kind* (`user` / `application` /
    `external`), *trust*, *data class*, *channel*. Any non-user, non-trusted source upstream is the
    fatigue-breaking signal and is rendered as a warning, not a footnote.
  - `Declared` with `undescribedSourceCount` or `withheldSourceCount` > 0 → the counts are shown as
    counts ("2 upstream sources withheld by release policy"), so a partially-redacted payload does not
    read as a clean one.
  - `Unknown` → literally "provenance unknown — no derivation was declared". Never an empty list, never
    silence (ADR 0026 §4). Silence reads as "no untrusted sources", the exact inference the ledger
    forbids.
  - `Unreleased` → "the application has not configured provenance release to approvers", which is a
    statement about configuration, not about the proposal (`ProvenanceDisclosure` doc block).
  - `null` on the challenge → "issued before provenance capture" — a storage era, rendered as such.
- **The reason is shown as what it is**: free text written at capability registration
  (`Capability::requiresConfirmation(reason:)`), i.e. *why this capability is gated*, not *why this
  call was made*. Labelling it as the latter is how an approver is taught to trust it.
- **Expiry is live and visible**, so an approver knows a decision is time-boxed against a paused run,
  and a lapsed one is never shown as actionable. "Waiting for *N* minutes" is rendered from
  `ApprovalChallenge::issuedAt` once #300 ships and is **nullable until then** — the console's own
  row timestamp is ingestion time, not issuance time, and must not be relabelled as the latter.
- **Capability name and argument fingerprint** anchor the item to the one call it is about. The
  fingerprint is correlation, not disclosure (ADR 0008): the console does not attempt to reverse it
  and does not claim it identifies the target.
- **No default-selected verb, no keyboard-through, no bulk action.** These are the mechanical
  affordances that manufacture fatigue; the UI does not ship them.

The item **does not** show what the action will do — amount, destination, target. Verdict's payload
is thin by decision (ADR 0026, Consequences), and a host that wants canonical action facts on the
card supplies them through its own `ApprovalPresenter::details`, as an explicit disclosure.

### 6. The #230 dead gate is surfaced, not swallowed

A capability that declares `requiresConfirmation()` without an execution-target policy never pauses:
`requestConfirmation()` returns null (`src/VerdictManager.php:339-343`), `shouldRequestApproval()`
returns null, Laravel AI has nothing to pause on, and the action is denied at execution without a
human ever being asked. Nothing reaches the inbox, so the inbox cannot be where this is caught.

The console's obligations:

- The doctor reports it as `FindingCode::ConfirmationGateCannotPause` (`src/Doctor/FindingCode.php`),
  and the ops surfaces (VC-22, VC-30) render that finding where an operator will see it at boot time.
- The inbox never *infers* the condition from silence. An empty inbox is not evidence that gates are
  wired; only the doctor is.
- The console does not "fix" it by asking the human anyway. A receipt without a target binding would
  authorize an action against a target Verdict never resolved (#230's own rationale). Whether the
  combination should be rejected at registration is Verdict's open question in #230; the console
  waits for that answer rather than compensating around it.

## Consequences

- **Every rendering surface carries the same verb rule**, and it is pinned by a test per surface: for a
  row, the verbs rendered are `{approve, reject}` iff the live challenge is non-null and the row is
  `Drivable`; `{close}` iff the row is `Drivable`, the live challenge is null, and VC-10's measured
  `close` has shipped; and `{}` otherwise. Informational rows render no verbs. The test that fails when
  someone adds "approve anyway" to a `Deny` row is the point of this ADR.
- **The design of record gets three corrections**: §7's `can('approve', …)` shorthand → the configured
  ability; §6.4's "on deny: resume with a clean refusal" is extended with the expiry `close` verb and
  its measurement precondition; the `DefaultApprovalPresenter` doc block's provenance sentence is
  reworded to distinguish persisting from live rendering.
- **The review lane is reserved, not built.** `review-verdict-action` is reserved by name only; no
  config key, model, migration, or listener exists for review items until #297 ships and is readable
  through #298. Building a review inbox off `DecisionEvidence` is explicitly *not* the fallback:
  evidence is fingerprints without arguments or target (ADR 0008), is off by default, and ADR 0007
  says evidence is not a gate — a reviewer shown a fingerprint cannot review anything.
- **#298 becomes the only Verdict surface the console couples to for reads.** Design §5's "the
  console's own `PendingApproval` table is the queryable index" narrows: the console's row stays the
  **workflow and correlation index** (resolver key, participant reference, presentation, resumability
  — things Verdict does not hold), but enumeration and per-receipt status move to #298's DTOs when it
  ships. Until then `challengeForToolCall()` is the whole read surface, and no console code reads a
  Verdict table directly — that is the coupling #298 exists to kill.
- **Informational surfaces must render "recording is off" distinctly from "nothing happened"** for
  `Permit`, `Throttle`, and `Deny` rows, because on a default install they are all blank by config.
- **Nothing here changes Verdict.** The gaps this design needs are in §8 for the PM.
- **Amended 2026-08-25 — the §4 amendment reopens three shipped tickets, each additively.**
  VC-6 (#6) gains the `Unauthorized` path and the `ApprovalDecisionRefused` incident; VC-4/VC-5
  (#4/#5) gain an `approval_context` column, captured at ingestion, with its own migration per the
  v0.2.0 upgrade convention; VC-12 (#12) gains a shipped `ApprovalContextScope` without changing the
  `ApprovalScope` contract. VC-3's doctor gains `approval_authorizer_missing`. #63 itself stays the
  compatibility pass — bound, harness, doctor finding, green round trip — and those three are filed
  on their original threads so the PM sizes them separately.
  **They do not share a gate.** #6 needs only #63's bound: `ApprovalOutcome::Unauthorized` is in
  `v0.12.0`. #5 and #12 wait on **Verdict's next release**: at `v0.12.0`, `approval_context` lives
  only on `ApprovalReceipt`, reachable solely through the store's `find()`/`findForToolCall()` —
  the path design §5 forbids and VC-5's own scope names as the boundary — and `ApprovalChallenge`
  does not carry it (at the tag or on `main`). The boundary-respecting route is
  `ApprovalStatusView::approvalContext` via `ApprovalStatusReader::statusFor()`, which is #327,
  unreleased. That release is therefore the unlock for four console tickets — VC-45, #5, #12, and
  VC-10's durable retry — not one.

## Alternatives rejected

### Offer "approve anyway" (or "override") on a denied call

Rejected, and recorded so it stays rejected. The arguments for it are always the same: an operator
can see the denial was a false positive; the policy is too strict this week; an admin should be able
to push through. Each is a reason to change the *policy* — return `requireReview()` or
`requireConfirmation()` for that class of call, in code, under a configuration fingerprint — not a
reason to give the console a way past a `Deny`. An override converts the console from a window on the
boundary into a hole in it, and it is the one hole a prompt-injected model would aim for. See §2. If a
future contributor believes a specific case needs it, the answer is a Verdict issue proposing a
disposition or policy primitive, never a console verb.

### Treat a `RequireReview` approval as authorization for a re-proposal

Rejected. It would be a blanket allow minted by the console — no tool call, no argument binding, no
target refresh — which is precisely what §4 says a grant must never be. If Verdict wants a single-use,
argument-bound re-proposal token minted from a review, that is a Verdict primitive decided on
#297's thread — which currently states the opposite intent (§8).

### Build the review inbox from `DecisionEvidence` rows with disposition `require_review`

Rejected. Evidence is the wrong substrate for a decision queue: it carries fingerprints and no facts
(ADR 0008), it is `NullEvidenceRecorder` by default, and ADR 0007 forbids using it as a gate. A review
item needs the proposal, not a hash of it.

### Auto-reject on receipt expiry

Rejected, consistent with Verdict ADR 0029 §1. `rejected_by` is an authenticated reviewer; writing a
decision nobody made into durable security state corrupts the evidence-integrity story that is the
product. Expiry leaves the receipt lapsed and the run paused; the `close` verb (§3) is a workflow act
that does not touch the receipt.

### Persist provenance and expiry onto the console's row

Rejected. Expiry would drift from Verdict's clock (VC-4 gave the row no expiry column for this
reason). Provenance would be a second copy of released application data, outside the store whose
release policy governed the first — a divergence in exactly the data ADR 0008 is strictest about.
Render both live from the challenge instead (§5).

### One Gate ability for both lanes

Rejected. Confirming a live argument-bound action and recording a review verdict are different
grants; reusing one ability would let a host accidentally hand review-only staff the power to release
a paused consequential action.

### Ship a default `ApprovalDecisionAuthorizer`, or bridge the console's Gate into Verdict's (added 2026-08-25)

Rejected, both halves. A console-shipped allow-all authorizer would make every install's Verdict
layer a no-op the moment the console is required — §2's override button under another package's
name — and Verdict already reserves `AllowAllApprovalAuthorizer` for test environments and warns
when it appears elsewhere. A bridging authorizer that consults the console's Gate cannot be written
honestly: Verdict hands it a `decidedBy` string, the console's own rule says that string is an audit
label never parsed back, and reconstructing a user from it would invent the identity convention
VC-7 refused to invent. Two layers, both the host's, is the only shape the contracts admit (§4).

### Distinguish "expired" from "already decided" in the inbox

Not rejected — *not possible today*. `challengeForToolCall()` collapses them, and reaching into the
receipt store to tell them apart is the boundary design §5 forbids. This is why the copy says
"expired or already decided" until #298's per-receipt status read ships (§8).

## 8. Verdict dependencies — filed, with their status frame

These began as findings from this design and are now Verdict issues. They are dependencies with
numbers, not available APIs: **#297–#299 are Proposed-contract territory** and the console builds
nothing against them until they ship; #300 is ungated. Verdict's stated build order when the gate
opens is #297 → #298 → #299; #297's design rounds can proceed on the issue at any time.

**Amended 2026-08-25 — status frame as of Verdict `a84cbed`.**

- **The console requires Verdict `^0.12`** (console #63). Not a disjunction with `^0.9.2`: the
  authorizer is required on one side of that range and absent on the other, the status reader exists
  on one side only, and `prefer-lowest` would then test `0.9.2` forever — the version nobody runs.
  `^0.12` puts the real floor under the lowest-dependency cell.
- **#305 (v0.12.0) is a new dependency this ADR did not anticipate**: the required
  `ApprovalDecisionAuthorizer`, the `Unauthorized` outcome, and `approval_context` on receipts. It is
  what §4's amendment absorbs. Verdict `#290` (v0.11.0) makes migration stubs read table names from
  config, which the console's test harness must honour when it requires Verdict's stubs directly.
- **#298 is settled — ADR 0031, implemented in #327 — but not released.** `ApprovalStatusReader`
  (`statusFor()`, `statusForToolCall()`, `pendingWithin(scope)`) and the `ApprovalStatusView` DTO are
  on Verdict `main`; `v0.12.0` does not contain them. VC-45 is therefore gated on Verdict's **next**
  release, not on the `^0.12` bound, and the "expired or already decided" copy stands until then.
  ADR 0031 names this ADR as its first consumer and confirms every shape §3 and the Consequences
  designed against: DTOs never rows, poll-consistency stated, expiry computed by the consumer, and
  enumeration scoped-or-refused (an empty scope throws; `null`/`[]` context never enumerates).
- **#299 is now gated on nothing but itself.** ADR 0031 gave it the status-read contract it is
  defined against; §3's rule that no `expired` event will ever exist is restated there as ADR 0031 §5.
- **#297 is unchanged**, and ADR 0031 §6 reserves its records a ride on the same read contract.

**What the console may rely on today (shipped, stable):** single-use receipts bound by
`bindingFingerprint`; re-authorization at execution regardless of any receipt;
`ApprovalManager::challengeForToolCall()` for the sync lane; ADR 0029 §1 (no auto-reject on expiry);
the configured Gate ability; and, from Verdict 0.12, the host-configured `ApprovalDecisionAuthorizer`
and the receipt's `approval_context`. The standing negative: **no enumeration API exists in any
tagged Verdict release** — `findForToolCall()` is the entire read surface until #327 ships.

**[#297](https://github.com/fissible/verdict/issues/297) — `RequireReview` substrate (keystone) ·
scope: design · L–XL.** Was F1. Intended shape, designed against in §1 and §3: a durable
`ReviewRequest` record written by `runBound()` when the proposal decision is `RequireReview` —
capability, `bindingFingerprint`, argument/target fingerprints (never raw values, ADR 0008),
provenance, `requestedAt`, status (`pending` → `approved`/`rejected`), `reviewedBy`/`reviewedAt`,
reviewer reason. The invocation still refuses; the model's structured refusal carries the
review-request id. `reviewed` is a transition, not a token. Evidence is additive `claimType` entries
under ADR 0028. Open on the issue, not pre-decided here: refusal payload shape, whether a reason is
mandatory, table naming. **Blocks the asynchronous lane entirely.**

**[#298](https://github.com/fissible/verdict/issues/298) — Approval read contract · settled as
ADR 0031, implemented in #327, unreleased as of 2026-08-25.** Was F2. A dedicated read contract
returning DTOs, never rows: pending challenges for the inbox, per-receipt status, and — once #297
exists — review-request reads through the same contract. Freshness stated as poll-consistency. This
is the only Verdict surface the console will ever couple to for reads (Consequences), and what lets
§3's "expired or already decided" copy say which. Its enumeration is scoped on `approval_context`
(ADR 0031 §3), which is why §4's amendment captures that field.

**[#299](https://github.com/fissible/verdict/issues/299) — Receipt-transition events · S–M ·
depends on #298.** Was F4. `final readonly` events in `src/Approvals/Events/`, dispatched by
`ApprovalManager` so every store gets them uniformly: `issued`, `approved`, `rejected`, `consumed`.
**No `expired` event, ever** — expiry has no transition moment (§3). The console's VC-11 notifications
keep publishing from their own observation points; #299 shrinks the stale-row window for
operator-resolved and model-consumed rows only.

**[#300](https://github.com/fissible/verdict/issues/300) — `ApprovalChallenge::issuedAt` · XS ·
ungated, contributor-drivable.** Was F5. Threads the receipt's `createdAt` onto the challenge as an
optional additive constructor parameter. Console side: "waiting *N* minutes" is nullable until it
lands (§5).

**[#230](https://github.com/fissible/verdict/issues/230) — registration-time rejection.** Was F3.
Stays on #230, which now also carries the observation that `RequireReview` is the same class of
silent gate. The console's position (§6) is unchanged: render the doctor finding, do not compensate.

**Console-side, not Verdict-side, and not a spec widening:** the `close` verb's dependency on
Laravel AI's behaviour for resuming a non-pending turn (§3) must be measured before VC-10 ships it.
That is a test in this repository, not a Verdict change.
