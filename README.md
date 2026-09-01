# Verdict Console

Human-in-the-loop approval runtime and operator UI for [Laravel AI](https://github.com/laravel/ai)
agents governed by [Verdict](https://github.com/fissible/verdict).

> **Status: pre-1.0, headless.** `0.1.0` ships the headless approval round trip — pause → authorized
> human decision → receipt transition → exact resume → execute at most once — driveable from your own
> code with no UI. Notifications, evidence projections, and the Blade / Livewire / Filament surfaces
> are specified in [`docs/design/0001-verdict-console-design.md`](docs/design/0001-verdict-console-design.md)
> and land in later minors; build order is tracked in [`MILESTONES.md`](MILESTONES.md).
> Substantial decisions are recorded in [`docs/adr/`](docs/adr/); start with
> [ADR 0001](docs/adr/0001-approval-surface-contract.md), the approval-surface contract — including
> the rule that a Verdict `deny` is never approvable from the console.

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

Verdict **`require_review` is a separate, gated review lane**: it has no receipt and does not resume
an agent. Its durable review substrate and read API are planned in Verdict #297 and #298, so this
package does not yet expose review items or treat decision evidence as an inbox.

## Package family

Install only the presentation stack you render in; all three sit on one headless core.

| Package | Contains |
| --- | --- |
| `fissible/verdict-console` (this repo) | Headless runtime + publishable **Blade** stubs (a working UI on install). |
| `fissible/verdict-console-livewire` | End-user chat with inline approval cards. |
| `fissible/verdict-console-filament` | Operator console (approval queue, evidence browser, alarms). |

## Installation

```bash
composer require fissible/verdict-console:^0.8
php artisan vendor:publish --tag=verdict-console   # config + the pending-approvals migration
php artisan migrate
php artisan verdict-console:doctor                 # preflight every silent trap before the first pause
```

Migrations are published, not auto-loaded: a console table must not appear on `migrate` without the
host asking for it. Publish only the config or only the migration with `--tag=verdict-console-config`
or `--tag=verdict-console-migrations`. Then bind the two host seams the round trip needs —
`ResumableAgents` (how to rebuild your agent from a stable key) and, if your conversations carry a
participant, `ConversationParticipants` — and define the `approve-verdict-action` Gate ability:
the shipped authority **fails closed** until you do.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `laravel/ai` ^0.11
- `fissible/verdict` ^0.14

## Development

```bash
composer install
composer test        # analyse + lint + type-coverage + pest
```

## License

MIT — see [LICENSE.md](LICENSE.md).
