# Gold Bot

Intelligent XAU/USD market analysis and trading-signal platform.
Internal system owned by **The Paragon Design**.

Gold Bot ingests live gold market data and a high-impact economic calendar, computes
indicators and market structure, evaluates configurable strategies, and publishes
qualifying setups to Telegram.

**Version 1 is signals only.** Gold Bot does not execute trades and places no orders
with any broker.

---

## Status

**Design phase — awaiting approval.** No production code has been written.

The complete design is in [`docs/`](docs/):

| Document | Contents |
|---|---|
| [00 — Naming & Decisions](docs/00-DECISIONS-AND-NAMING.md) | Repository naming, 14 architectural decisions, open questions |
| [01 — System Architecture](docs/01-SYSTEM-ARCHITECTURE.md) | Topology, layers, data flows, security, observability |
| [02 — Database Design](docs/02-DATABASE-DESIGN.md) | Full schema specification and retention policy |
| [03 — Folder Structure](docs/03-FOLDER-STRUCTURE.md) | Directory layout and its rationale |
| [04 — Delivery Roadmap](docs/04-DELIVERY-ROADMAP.md) | Eleven phases with verification steps |

Start with document 00.

---

## Stack

PHP 8.3 · MySQL 8 · Composer · PSR-4 · MVC · Apache on cPanel · Cron
Tailwind CSS · Alpine.js · Chart.js · TradingView Advanced Chart
Twelve Data (market data) · Trading Economics (economic calendar) · Telegram Bot API

No framework, no Node.js backend, no Python in the core application.
Everything runs on standard cPanel shared hosting.

---

## Open Questions

Three answers are needed before the phases they block can begin:

1. **The 714 Method rules** — blocks Phase 6. The architecture treats it as a fully
   configurable five-pillar weighted rubric with nothing hardcoded; the specific rules
   are yours to define. See document 00, §3.
2. **Trading Economics subscription** — blocks Phase 5.
3. **Twelve Data plan tier** — shapes Phase 3 fetch cadences.

Phases 0–4 are unblocked by all three.
