# Milestones

Dependency-ordered build plan for `fissible/verdict-console`. Leaves → roots, **risk-first within a
level**: the walking skeleton comes before the CRUD because the round trip is where the design can be
*wrong*, not merely late (design §11). The design of record is
[`docs/design/0001-verdict-console-design.md`](docs/design/0001-verdict-console-design.md).

Effort key: XS (<1h), S (1–2h), M (~half day), L (~1 day), XL (2–3 days).

Current version: `0.1.0` (unreleased). See the fissible standards in
[`fissible/.github`](https://github.com/fissible/.github) for the release procedure.

## M0 — Scaffold ✅ (this repo)
Package skeleton, CI/release wiring, design of record. No runtime.

## M1 — Walking skeleton — **build first** · L
`ToolApprovalRequested` → `PendingApproval` → human approve → `ApprovalManager::approve` → resume
with a tool-call-id-keyed decision → the tool executes **exactly once**. Ugly is fine; this proves
the design. Must clear every hazard in design §12 (esp. `VerdictApprovalMiddleware` registration,
`approveAll()` wildcard, `UnsafeOuterTransaction`, the `Conversational` + `RemembersConversations`
preconditions) **and settle the host-supplied resumable-agent resolver contract** (§6.1/§6.3) — an
approval whose agent can't be reconstructed must be refused at ingestion, never committed. Deps: M0.

## M2 — `PendingApproval` store + bridges · M
Formalize the store (surrogate PK, nullable `receiptId`, correlation annotations, ingest
idempotency), the disposition bridge (incl. the receiptless/ambiguous null branch, §6.3), and the
resolution bridge. Deps: M1.

## M3 — Operational state + reconciliation · M
Notification idempotency, SLA timers, resume-attempt state, and "receipt approved but resume failed"
reconciliation (§6.2). Deps: M2.

## M4 — Notifications · S
Pending / consumed / completed notices; copy obeys the ADR 0028 claim ceiling. Deps: M2.

## M5 — Durable projections · M
Evidence↔conversation correlation projection (§6.6) and the incident projection over Verdict's
ephemeral anomaly events (§6.7). Gates the read-heavy surfaces. Deps: M2.

## M6 — Blade surfaces (in core) · M
Embeddable approval widget + audit page + basic chat thread. Deps: M2, M5.

## M7 — Livewire package (`fissible/verdict-console-livewire`) · L
End-user chat with inline approval cards, live inbox, live decision feed. Deps: M6.

## M8 — Filament package (`fissible/verdict-console-filament`) · L
Operator console: approval queue, evidence browser, execution-claim queue, `verdict:validate`
screen, anomaly alarms. Deps: M5, M6.

## Open items for PM
- Companion Verdict issue? A receipt read/enumeration API on `ApprovalReceiptStore` for generic
  (non-`ApprovalManager`) integrations (design §5). Not required for v1.
- Sequencing vs. reference app #237. Verdict #218 (Conversational resume) is **closed / proven**
  (v0.8.0, #233/#235) — this package leans on that shipped mechanism, not deferred work.
