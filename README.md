# Verdict Console

Human-in-the-loop approval runtime and operator UI for [Laravel AI](https://github.com/laravel/ai)
agents governed by [Verdict](https://github.com/fissible/verdict).

> **Status: design + scaffold, pre-release (`0.1.0`, unreleased).** The runtime and UI are specified
> in [`docs/design/0001-verdict-console-design.md`](docs/design/0001-verdict-console-design.md) but
> **not implemented yet**. This repository currently contains the package skeleton, CI/release
> wiring, and the design of record. Build order is tracked in [`MILESTONES.md`](MILESTONES.md).

## What it is

Verdict decides at the tool boundary and records evidence; Laravel AI runs the agent and persists
conversations. Neither owns the tissue between them and a human. Verdict Console is that tissue plus
the operator surfaces: it turns a Verdict `require_confirmation` into a screen a person acts on,
drives the receipt → resume round trip, and presents what Verdict recorded.

It **never** decides (Verdict does), **never** persists conversations (Laravel AI does), and is
**never** a second authorization authority. It owns the approval workflow and the UI.

## Scope boundary (read first)

The receipt-backed approval loop exists only for Verdict **`BoundTool`** on an agent using Laravel
AI's **`RemembersConversations`** concern with a real (non-fake) gateway. Non-`BoundTool` approvals
are observable but not Verdict-drivable. See design §3.

## Package family

Install only the presentation stack you render in; all three sit on one headless core.

| Package | Contains |
| --- | --- |
| `fissible/verdict-console` (this repo) | Headless runtime + publishable **Blade** stubs (a working UI on install). |
| `fissible/verdict-console-livewire` | End-user chat with inline approval cards. |
| `fissible/verdict-console-filament` | Operator console (approval queue, evidence browser, alarms). |

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `laravel/ai` ^0.11
- `fissible/verdict` ^0.9

## Development

```bash
composer install
composer test        # analyse + lint + type-coverage + pest
```

## License

MIT — see [LICENSE.md](LICENSE.md).
