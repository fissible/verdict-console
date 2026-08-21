# Milestones

Release-train summary for `fissible/verdict-console`. The detailed, agent-pickup-ready issues live in
[`docs/planning/ISSUES.md`](docs/planning/ISSUES.md) and are filed as GitHub issues under the matching
milestones. Milestones are **minor releases**, each independently shippable as a cumulative release.

Ordering is leaves → roots, **risk-first within a milestone**: the pause→approve→resume round trip
(VC-1) is built before the CRUD around it, because that is where the design can be *wrong*, not merely
late (design [§11](docs/design/0001-verdict-console-design.md)).

**Issue numbering:** filed 1:1, so `VC-N` is GitHub issue `#N`.

Current version: `0.1.0` (unreleased). Release procedure: fissible standards in
[`fissible/.github`](https://github.com/fissible/.github).

## Core package `fissible/verdict-console`

| Milestone | Theme | Issues |
| --- | --- | --- |
| **v0.1.0** | Headless approval round trip — pause → approve → resume → execute-once, driveable with no UI, plus the authorization + presentation contracts the loop needs | VC-1 … VC-8 |
| **v0.2.0** | Production-grade workflow — notification idempotency, resume-failure reconciliation, notifications, tenancy scoping | VC-9 … VC-12 |
| **v0.3.0** | Evidence & health projections — evidence query contract, correlation + incident ledger, execution-claim + config read-models | VC-13 … VC-17 |
| **v0.4.0** | Blade surfaces — embeddable inbox, audit page, basic chat, ops views + the host chat-entry contract | VC-18 … VC-22 |

## Adapter packages (own repos, own version streams)

Built after core v0.4.0. Their issues are tracked here for now and migrate to the new repos when those
are stood up.

| Package | Milestone | Issues |
| --- | --- | --- |
| `fissible/verdict-console-livewire` | livewire-v0.1.0 | VC-23 … VC-26 |
| `fissible/verdict-console-filament` | filament-v0.1.0 | VC-27 … VC-30 |

## Open items for PM

- **Companion Verdict issues (optional, none blocking v1):** a receipt read/enumeration API on
  `ApprovalReceiptStore` for generic (non-`ApprovalManager`) integrations (design §5); and — only if a
  surface must mirror `verdict:validate` output rather than the console doctor's own findings model — a
  structured validation-findings API in Verdict.
- Sequencing vs. reference app **#237**. Verdict **#218** (Conversational resume) is closed/proven
  (v0.8.0, #233/#235) — this package leans on that shipped mechanism, not deferred work.
