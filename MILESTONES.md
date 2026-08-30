# Milestones

Release-train summary for `fissible/verdict-console`. The detailed, agent-pickup-ready issues live in
[`docs/planning/ISSUES.md`](docs/planning/ISSUES.md) and are filed as GitHub issues under the matching
milestones. Milestones are **minor releases**, each independently shippable as a cumulative release.

Ordering is leaves → roots, **risk-first within a milestone**: the pause→approve→resume round trip
(VC-1) is built before the CRUD around it, because that is where the design can be *wrong*, not merely
late (design [§11](docs/design/0001-verdict-console-design.md)).

**Issue numbering:** the original plan filed 1:1, so `VC-N` is GitHub issue `#N` for VC-1 … VC-48.
That convention **ended there** — the number space is shared with pull requests, so issues filed
after #48 (#51, #63, #67–#69) carry descriptive titles and no `VC-` prefix. Refer to them by issue
number; do not mint new `VC-` numbers.

Current version: `0.4.0` (released 2026-08-30; v0.1.0–v0.4.0 milestones closed). Release procedure: fissible standards in
[`fissible/.github`](https://github.com/fissible/.github).

## Core package `fissible/verdict-console`

| Milestone | Theme | Issues |
| --- | --- | --- |
| **v0.1.0** | Headless approval round trip — pause → approve → resume → execute-once, driveable with no UI, plus the authorization + presentation contracts the loop needs | VC-1 … VC-8 |
| **v0.2.0** | Production-grade workflow — notification idempotency, resume-failure reconciliation, notifications, tenancy scoping, plus the [ADR 0001](docs/adr/0001-approval-surface-contract.md) verb contract, the measured expiry `close`, and the design-doc corrections | VC-9 … VC-12, VC-41, VC-43, VC-44 |
| **v0.3.0** | Evidence & health projections — evidence query contract, correlation + incident ledger, execution-claim + config read-models, and the approval item read-model | VC-13 … VC-17, VC-42, [#67](https://github.com/fissible/verdict-console/issues/67) |
| **v0.4.0** | Blade surfaces — embeddable inbox, audit page, basic chat, ops views + the host chat-entry contract | VC-18 … VC-22 |
| **verdict-gated** | Designed against Verdict Proposed-contract issues [#297](https://github.com/fissible/verdict/issues/297)–[#300](https://github.com/fissible/verdict/issues/300); built against nothing until each ships, then migrated to a release milestone. Label `blocked:verdict` | VC-45 … VC-48, [#68](https://github.com/fissible/verdict-console/issues/68), [#69](https://github.com/fissible/verdict-console/issues/69) |

## Adapter packages (own repos, own version streams)

Built after core v0.4.0. Their issues are tracked here for now and migrate to the new repos when those
are stood up.

| Package | Milestone | Issues |
| --- | --- | --- |
| `fissible/verdict-console-livewire` | livewire-v0.1.0 | VC-23 … VC-26 |
| `fissible/verdict-console-filament` | filament-v0.1.0 | VC-27 … VC-30 |

## Open items for PM

- **Companion Verdict issue — a receipt read API, deliberately deferred past v0.1.0.** It now covers
  **two** things, not one: *enumeration* (for generic non-`ApprovalManager` integrations, design §5)
  and a **status read**. It has two real consumers rather than an anticipated one:
  1. **Distinguishing why a challenge is unavailable.** `challengeForToolCall()` returns
     `?ApprovalChallenge`, so null collapses absent, ambiguous, non-pending and expired with no public
     way to tell them apart (design §6.3, VC-5).
  2. **Detecting that a receipt was consumed.** Same null, so no honest `consumed` notification can be
     built on it (VC-11).

  **Deferred rather than pulled forward, because the current behaviour is honest and safe**: an
  incident whose cause is explicitly unknown, a non-drivable row, and no false consumed notice. Neither
  gap blocks the core round trip; both would be *improved* by the contract, and neither is being
  approximated in the meantime.
- **Verdict approval-surface cluster — filed, Proposed-contract, build nothing against it yet.**
  [ADR 0001 §8](docs/adr/0001-approval-surface-contract.md) carries the detail and status frame.
  [verdict#297](https://github.com/fissible/verdict/issues/297) (`RequireReview` substrate — keystone,
  L–XL; blocks the console's asynchronous lane entirely) →
  [#298](https://github.com/fissible/verdict/issues/298) (approval read contract — absorbs the
  receipt read/status item above, now with three consumers) →
  [#299](https://github.com/fissible/verdict/issues/299) (receipt-transition events; never an
  `expired` event). [#300](https://github.com/fissible/verdict/issues/300) (`ApprovalChallenge::issuedAt`,
  XS) is ungated and independent. The registration-time half of the silent-gate problem stays on
  [#230](https://github.com/fissible/verdict/issues/230). Sequencing against the attack-surface lane
  is a `projects/` PM call.
- **Optional, and only if a surface must mirror `verdict:validate` output** rather than the console
  doctor's own findings model: a structured validation-findings API in Verdict.
- Sequencing vs. reference app **#237**. Verdict **#218** (Conversational resume) is closed/proven
  (v0.8.0, #233/#235) — this package leans on that shipped mechanism, not deferred work.
