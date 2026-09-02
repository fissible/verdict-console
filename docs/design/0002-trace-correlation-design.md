# Trace correlation — measured walkability design

**Status:** design proposal. This document defines what a future operator trace may promise, not a
new read model or an authorization path.

**Problem and method:** today an operator reconstructs one incident by hand across four screens:
the decision evidence, approval receipt, pending-approval workflow row, and execution claim. Every
walkability verdict below was measured against the shipped code, with the measurement site named;
none is inferred from a desired console surface.

---

## Premise correction

Issue #103 recorded the decision↔claim edge as unreachable. That premise is wrong. It **is**
walkable today by binding fingerprint: evidence's `execution_claim_binding_fingerprint` carries the
claim row's unique `binding_fingerprint` verbatim, from `ExecutionClaimManager::metadata()`. What
remains unwalkable is the claim-id route, because it is one-way, and the tool-call route, because
the claim row has no `tool_call_id`.

## Edge: decision↔receipt

**Authoritative source:** the Verdict approval-receipt row is authoritative for receipt state;
decision evidence is the observational source for its receipt fingerprint. Measurement sites:
`VerdictManager` approval-fingerprint minting and the `verdict_approval_receipts` migration.

**Walkable today: only by hashing candidate receipt ids — `approval_receipt_fingerprint` is `hash('sha256', $receipt->id)`, a one-way derivation with no direct join.**

**Missing producer data:** a raw receipt id on evidence is intentionally absent. Evidence carries
fingerprints, not raw ids (ADR 0008).

**Cross-connection behavior:** read the evidence fingerprint, hash only receipt ids already selected
from the receipt store in PHP, and compare there. Do not compose a SQL join across the stores.

**Display-safe values:** approval-receipt fingerprint, receipt status, and receipt timestamps; never
the raw receipt id.

## Edge: receipt↔pending approval

**Authoritative source:** the Verdict receipt row is authoritative for receipt state; the console's
pending-approval row is authoritative for its captured workflow context. Measurement sites: the
`verdict_approval_receipts` migration and the console `verdict_console_pending_approvals` migration.

**Walkable today: yes — `pending_approvals.receipt_id` is a nullable unique column joining the receipt row directly; when it is null the only route is the provider-supplied `tool_call_id`, which is collision-ambiguous (verdict#425; the console renders collisions per #96).**

**Missing producer data:** no receipt id is produced for a receiptless or collision-ambiguous
approval. That absence is meaningful; the console must not manufacture a match.

**Cross-connection behavior:** materialize the receipt id and perform a per-row lookup on the
pending-approval connection. A null receipt id does not authorize a tool-call fallback as a unique
route.

**Display-safe values:** receipt status and timestamps, pending-approval resumability and its
reason, and the presentation already owned by that workflow surface; never a raw receipt id.

## Edge: conversation↔decisions

**Authoritative source:** the console-owned invocation-to-conversation projection is authoritative
for the observed mapping; Verdict evidence is authoritative for decision records. Measurement sites:
`ConversationInvocationStore` and `DatabaseEvidenceQuery`.

**Walkable today: yes — the console-owned invocation↔conversation projection (`ConversationInvocationStore`) joins `evidence.invocation_id`; it exists only where `VerdictProvenanceMiddleware` stamped the id, and ids are materialized before crossing connections because a SQL join cannot.**

**Missing producer data:** a conversation id on the evidence row. The projection deliberately
records the boundary observation instead; an unobserved conversation is unknown, not empty.

**Cross-connection behavior:** load invocation ids from the console projection, then pass that
materialized list to the evidence query's `whereIn('invocation_id', ...)` on Verdict's connection.

**Display-safe values:** decision fingerprints, disposition, capability, and timestamps; a
conversation identifier only on a surface that already owns that identifier.

## Edge: decision↔claim

**Authoritative source:** the execution-claim row is authoritative for claim state and its unique
binding fingerprint; decision evidence is the observational copy. Measurement sites:
`ExecutionClaimManager::metadata()`, the `verdict_execution_claims` migration, and
`DatabaseEvidenceQuery`'s published evidence projection.

**Walkable today: yes, by binding fingerprint — evidence records `execution_claim_binding_fingerprint` verbatim from the claim row's unique `binding_fingerprint`; unwalkable by claim id (`execution_claim_fingerprint` is `hash('sha256', $claim->id)`, one-way) and by tool call (the claim row records no `tool_call_id`).**

**Missing producer data:** none is missing by accident — evidence deliberately carries
fingerprints, not raw ids (ADR 0008). The claim-id route and tool-call route need producer changes
before either can become a walkable route.

**Cross-connection behavior:** materialize binding fingerprints from evidence and use them for
per-edge claim lookups. Do not reverse or compare a claim-id fingerprint as though it were a key,
and do not infer a tool call from a claim.

**Display-safe values:** binding and claim fingerprints, claim policy and status, attempt count, and
timestamps; never the raw claim id or a fabricated tool-call association.

## Cross-connection rule

Every Verdict store carries its own connection key (`capability_configurations`, `approvals`, `reviews`, `evidence`, `rate_limits`, `execution_claims`, `intents`), and the console's own tables sit on the default connection: every cross-store hop must materialize keys in PHP rather than compose a SQL join.

## Display safety

Each hop surfaces display-safe values only: fingerprints, statuses, and timestamps — never raw receipt ids, claim ids, or conversation identifiers beyond what the surface already owns.

## Recommendation

**Recommendation: per-edge lookups, not a composed trace read.** That is, a composed trace read would promise a walk it cannot complete: the receipt hop is one-way by design and the tool-call hop is collision-ambiguous, so composition would either lie at those hops or refuse the whole walk.

The console must explicitly refuse to promise an end-to-end trace. Before a composed read could be
honest, producers would need to cross the boundary with the missing keys: a claim id for an
evidence-to-claim route and a `tool_call_id` on the claim row for a claim-to-tool-call route. It
would also need a defined, collision-safe receipt-to-pending-approval contract instead of treating a
null receipt id as a join key.

Implementation tickets are filed from this design with honest efforts. File small tickets for a
decision-to-receipt fingerprint lookup surface (S), a receipt-to-pending-approval lookup surface
(S), a conversation-to-decisions materialized-invocation lookup surface (M), and a
decision-to-claim binding-fingerprint lookup surface (S). File any producer change that adds a
claim id or `tool_call_id` as a separate, cross-package design ticket rather than attaching it to an
operator view.
