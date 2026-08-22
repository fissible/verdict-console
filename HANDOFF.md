# Handoff — 2026-08-22

## Current state

- Clean checkout on `main`; remote `origin/main` is `f593a2f` (`#37` merged).
- v0.1.0 merged: VC-1 through VC-4, VC-7, VC-8.
- Remaining v0.1.0 work: VC-5 (disposition bridge) and VC-6 (resolution bridge).
- Do not start VC-6 before VC-5 is complete.

## VC-5: settled boundary

Implement a listener for Laravel AI `ToolApprovalRequested` using only
`ApprovalManager::challengeForToolCall($toolCallId)`. Never call
`ApprovalReceiptStore::findForToolCall()` from the console.

- Non-null challenge: create a Verdict-backed row using `$challenge->receiptId`.
- Null challenge: create an `unresumable` row with no receipt id. The cause is
  `challenge_unavailable`, but its underlying reason is **unknown**: the manager's null collapses
  absent, ambiguous, non-pending, and expired receipt states. Do not classify it.
- A row is `drivable` only when all three are true: a non-null challenge, a resolver key that
  resolves, and a non-null `conversation_id` (resume uses `continue($conversationId)`).
- If resolving/keying the agent fails, still write the row as `unresumable`; do not refuse ingestion.
  The event fires after the run paused, so refusing the row only hides the stranded run.
- Laravel AI event payload is `ToolApprovalRequested(invocationId, agent, pendingApprovals,
  conversationId, conversationUser)`. Use `LaravelPendingApproval` as the alias for its individual
  `PendingApproval` payload; keep the console Eloquent row unaliased as `PendingApproval`.

## Incident boundary

VC-15's durable ledger is deferred to v0.3.0. VC-5 must use one console
`ApprovalIngestionIncident` event with a typed observation-level cause enum and a default warning-log
listener. This event/log is explicitly ephemeral; VC-15 will later project it alongside Verdict's four
anomaly events.

The `PendingApproval` row must retain the same cause in a nullable durable
`unresumable_reason` column so operators can distinguish `challenge_unavailable` from
`agent_unresolvable` after a restart. This is valid information because it records which bridge check
failed, not an inferred receipt state.

## In-flight work owned by the other agent

The other agent is updating:

1. Design §6.3 and VC-5 wording to say the ingestion event/log is ephemeral until VC-15.
2. VC-15 wording to include `ApprovalIngestionIncident` as a future projection source.
3. The unreleased VC-4 migration stub and model/store tests to add nullable `unresumable_reason`.

Do not independently edit those files until their update has landed. Pull/rebase from `main` before
starting VC-5.

## Review and validation expectations

- Preserve the user-owned working tree; stage only named VC-5 files.
- Add discriminating tests: challenge vs null; at least two distinct null causes must produce the same
  `challenge_unavailable` state; conversationless pause is unresumable even with a valid challenge and
  resolver; resolver failure writes a row plus incident rather than throwing/dropping.
- A test claiming receipt status must not infer it from a null challenge.
- Run Pint, PHPStan, 100% type coverage, and Pest. On this environment the type-coverage process needs
  `php -d memory_limit=1G vendor/bin/pest --type-coverage --min=100`; `composer test` otherwise may
  exhaust its default 128 MB type-coverage subprocess.

## Related decisions

- Receipt status/read/enumeration API remains deferred from v0.1.0. It has two concrete future
  consumers (null-cause distinction and consumed notification), but current behavior is honest:
  unknown cause and no consumed notice rather than a false lifecycle claim.
- `continueLastConversation()` is unsafe for concurrent conversations by one participant; use exact
  `continue($conversationId, $participantOrNull)`. Verdict issue #265 documents the reproduction.
- VC-6 resumes only when the Verdict transition outcome matches the actor request (`Approved` for
  approve, `Rejected` for reject). Never resume on actor intent alone; never use `approveAll()`.
