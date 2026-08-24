# Changelog

All notable changes to Verdict Console will be documented in this file.

## [Unreleased]

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

- **Scaffolded the package.** Skeleton, CI (`tests.yml`) and release wiring per the fissible
  convention, and the design of record (`docs/design/0001-verdict-console-design.md`) for the
  human-in-the-loop approval runtime and operator UI over Laravel AI + Verdict. No runtime yet — the
  dependency-ordered build plan is in `MILESTONES.md`.
