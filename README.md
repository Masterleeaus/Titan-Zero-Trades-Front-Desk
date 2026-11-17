# Scheduling & Enquiries Manager — the one WordPress Front Desk plugin

**Repository:** `Masterleeaus/Titan-Zero-Trades-Front-Desk` · **Document review:** 18 November 2026

**Scheduling & Enquiries Manager** is the user-facing name of Titan Zero's **single installable WordPress plugin** for enquiries, service calendars, staff/resources, customers and local business booking. It is built for cleaning, trades, home services and other appointment-led service businesses.

**One-plugin rule:** there is **no separately installed Pro plugin, Pro ZIP, second Assistant plugin, or paid lock on bundled local PHP features**. Optional hosted **Titan Zero AI Assistant** and **Titan Zero AI Agent Team** services are activated inside the existing plugin, after account pairing, consent and verified entitlement. This is the authoritative [architecture specification](docs/SINGLE-PLUGIN-AI-SERVICES-ARCHITECTURE.md); old issue descriptions and the historical Pro repository are reference material only.

## Free capability and service boundary

| Surface | One-plugin capability | Operational distinction |
| --- | --- | --- |
| Local booking and calendars | Service/staff schedules, appointment booking, capacity controls, rescheduling/cancellation, customer records and notifications | Free local deterministic operation must work without Titan hosting or AI entitlement; see [#7](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/7) and runtime defect [#183](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/183) |
| Enquiry and AI foundations | FAQ/rules, deterministic triage, local knowledge, safe draft-answer and retrieval functions where implemented | Specific 22-feature Free AI programme has mixed delivery/test status ([#19](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/19), [#42](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/42)); never assert all 22 are live |
| Hosted AI Assistant | Full conversations, grounded answers, guided booking, quote/reply drafts, multilingual help and handoff | Optional genuine remote processing, only after consent, signed pairing and end-to-end tests ([#47](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/47)) |
| Hosted AI Agent Team | Governed enquiry, booking, callback/follow-up, quote intake, human escalation and supervisor agents | Paid **hosted** services, scoped signed tool calls, approval and receipts; real telephony tests required ([#48](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/48)) |
| Legacy Pro-derived functionality | Local multi-location, roles, staff/teams, reports, customer exports, forms and workflows | Audit/merge distinct local features into **this same Free plugin**, preserve customer data ([#49](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/49)) |

The core plugin cannot depend on remote AI to book a customer or recover its local appointments. The plugin may expose optional hosted-services controls, but **remote model inference, autonomous calls and paid subscriptions are not certified as active until real contracts and billing are tested**.

## One installation, three service levels

1. **Free:** fully usable deterministic local business operations, local rules/knowledge and any free opt-in basic hosted fallback where available.
2. **Titan Zero AI Assistant:** opt-in paid hosted text conversations, response guidance and knowledge-assisted drafts.
3. **Titan Zero AI Agent Team:** opt-in paid hosted execution using governed specialist agents, approval, audit receipts and optional providers.

An authorised site administrator controls connection, consent, service scopes, subscription and downgrade **inside the same WordPress admin**. Do not infer a customer's paid plan from UI state or a URL; verify signed entitlement server-side. Provider SMS/voice costs are separately disclosed when applicable.

**Pricing:** this repository's commercial and public-site documentation may contain prices or price bands; treat these as **marketing/catalogue data subject to approved status** rather than proof of a working billing product. Reconcile with the [website upgrade catalogue](https://github.com/Masterleeaus/Titan-zero-website/blob/main/assets/upgrade-catalogue.json) and [Central billing issue #153](https://github.com/Masterleeaus/Central/issues/153) before updating public rates. Do not invent new price tiers.

## Connected-app contract

- **Standalone / offline SaaS:** local Free WordPress booking is authoritative, with no dependency on Central or Scheduled availability.
- **Integrated Titan deployment:** Scheduled becomes authoritative for booking/calendar changes; WordPress remains the customer-facing interface and local data must be reconciled by a versioned, reversible authority-switch contract. Never enable two simultaneously writable booking systems.
- **Central:** verified company/site pairing, Logto-backed identity for hosted services, consent, entitlement, capabilities and event audit.
- **Supercharged:** hosted AI Assistant/Agent Team conversation and agent execution; all booking changes use the approved deterministic booking API, not direct SQL or invented availability.
- **Sorted:** authorized customer, service quote, job and invoice handoff; **Skilled:** published service knowledge and staff competency only with scoped access.

See [service connector contract](docs/AI-SERVICE-CONNECTOR-CONTRACT.md), [entitlement/downgrade rules](docs/AI-SERVICES-ENTITLEMENT-AND-DOWNGRADE.md), [Scheduled integration #67](https://github.com/Masterleeaus/Scheduled/issues/67) and [canonical epic #45](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/45).

## Repository anatomy

| Path | Responsibility |
| --- | --- |
| `titan-zero-trades-front-desk/` | Actual WordPress plugin package: bootstrap, `includes/`, templates/assets, WordPress `readme.txt` and changelog |
| `docs/` | Current one-plugin architecture, hosted API, service security, billing and release records |
| `tests/`, `tests/runtime/` | WP runtime/activation, authorization, booking, security and failure regressions |
| `scripts/`, `tools/` | Packaging and reproducible release/test tooling |
| `THIRD-PARTY-NOTICES.txt` | Upstream licence/provenance, retained as supplied |

**Do not rename plugin directory, database tables, shortcodes, public classes, option keys or historical slug solely to match the display name.** Audit upgrade safety and real installed user data before migration.

## Development and quality checks

Requirements: disposable WordPress/PHP/MariaDB or MySQL test site appropriate to the legacy plugin, verified WP version and required PHP extensions. Follow [runtime harness guidance](tests/runtime/README.md); provision a test site rather than running partially skipped unit scripts as proof of integration.

Required checks include activation/upgrade rollback from real previous versions; REST/AJAX nonce and capability enforcement; per-calendar delegation; booking concurrency, slot-hold migration and confirmation UX; cross-company/site authorization; webhook HMAC/timestamps/replay; consent, privacy export/erase; mobile accessibility and package/WordPress.org compliance.

**Current tracked regressions include:**

- **Booking and setup:** [#183](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/183) (one booking blocking a calendar), [#186](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/186) (slot-hold activation migration), [#184](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/184) (confirmation feedback) and [#182](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/182) (calendar Add New).
- **Privacy and security:** [#8](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/8), [#188](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/188) (webhook replay must be atomic), [#189](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/189) (delegated calendar access), [#187](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/187) (403 vs 500).
- **Runtime quality:** [#191](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/191), [#193](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/193), [#194](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/194), [#196](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/196).
- **Brand/accessibility:** [#175](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/175)–[#181](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/181), including responsive admin screens and the official name.

A source PR, a merged feature, an installed WordPress admin page and a passing isolated PHP test are **different evidence levels**. Check each open issue's `[ ]` tasks only after its specified runtime/CI/security evidence exists; [view open tasks](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues?q=is%3Aissue+is%3Aopen).

## Commercial, licensing and release safety

Source uses GPL-2.0-only plus retained original notices. WordPress.org submission requires approved plugin metadata, accessibility, lawful third-party service consent, no remote executable code, no locked local functionality and reproducible packages. Hosted AI terms, cost controls, safe degradation, subscription cancellation and retention must be verified before advertising live services.

**Read first:** [single-plugin architecture](docs/SINGLE-PLUGIN-AI-SERVICES-ARCHITECTURE.md) · [subscription/downgrade](docs/AI-SERVICES-ENTITLEMENT-AND-DOWNGRADE.md) · [WordPress plugin readme](titan-zero-trades-front-desk/readme.txt) · [source issue tracker](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues).
