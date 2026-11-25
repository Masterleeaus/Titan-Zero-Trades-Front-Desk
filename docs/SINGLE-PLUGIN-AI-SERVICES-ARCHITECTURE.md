# Scheduling & Enquiries Manager — Single-Plugin AI Services Architecture

> **Authoritative product plan: 2026-11-15.** Status: **specification only**. This document supersedes any planned separately installed Pro extension or AI-only plugin. Do not claim the hosted plans are implemented before end-to-end tests.

## Official WordPress plugin name

**Titan Zero Scheduling & Enquiries Manager** is the official user-facing WordPress plugin display name. **Titan Zero** is the vendor/service brand, and **Titan Zero AI Assistant** / **Titan Zero AI Agent Team** are its optional hosted service upgrades. Do not rebrand the AI services as plugins.

Legacy "Front Desk" descriptions, repository names, previously distributed slugs, PHP namespaces, DB options, shortcodes and API identifiers may remain for backward compatibility; audit and migrate only with verified WordPress upgrade safety. Follow the WordPress.org-approved plugin display name/slug, do not assume its slug from this document. Never rename a repository or published WordPress plugin slug merely to match display-name wording.

## The single-install promise

A service business installs **Scheduling & Enquiries Manager** once from WordPress.org (after approval). It continues to own and use its booking, calendars, service catalogue, customers, standard notification, rule-based enquiry handling and AI knowledge tools without payment or an external AI connection.

In the plugin's **AI Services** admin view, the business can optionally connect a Titan hosted account and activate:
1. **Free / basic AI:** tenant-scoped rules/FAQ, cache, semantic lookup where available and limited opted-in hosted model fallback.
2. **Titan Zero AI Assistant:** paid **hosted conversational AI service** for full interactive text enquiries, guided booking, context-rich answers, draft replies/quotes, multilingual text, owner summaries, intelligent suggestions and escalation.
3. **Titan Zero AI Agent Team:** paid **hosted autonomous multi-agent service** for enquiries, booking/calendar work, quote intake, consented follow-up/callback and supervisory approvals; can include the Assistant experience within the same entitlement. Live voice and SMS require real provider integrations and separate disclosed carrier costs.

**No separate Pro WordPress plugin, Pro licence, Pro ZIP, second chatbot plugin or WordPress reinstall.** Switching levels only changes genuine remotely delivered service entitlements and related UI state. There are still four independent connected At.Work apps — Supercharged, Scheduled, Sorted, Skilled — neither required nor silently bundled.

## Hard boundary: locally distributed PHP is not paid-locked

The [WordPress.org plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) prohibit trialware/locked local functionality (guideline 5), permit genuine disclosed SaaS (6), require consent for third-party transfers (7) and prohibit remote executable code delivery (8). All local code shipped in the WordPress.org plugin must work for Free users. Never add `if (paid) { enable_local_report(); }` or a timer/quota blocking a locally executable feature.

When migrating old Pro specifications, either:
- incorporate local deterministic features into the fully usable Free plugin; or
- implement a *real* remote service with substantive independent processing if it genuinely needs off-site compute (not an arbitrary licensing proxy); or
- defer out-of-scope functionality with documented reasons.

The previous Pro repository is a **historical planning input** and should not be deleted until feature/code audit completes.

## UX: one plugin, two upgrade steps

**WordPress Admin → Scheduling & Enquiries Manager → AI Services**
- Show current level, status, consent and last successful link; buttons/links for *Connect account*, *Activate Assistant*, *Upgrade to Agent Team*, *Manage subscription*, *View usage* and *Disconnect*, only when real account/checkout APIs exist.
- Basic AI and all deterministic booking tools continue operating without creating a Titan account. No signup requirement for standard Free usage.
- Pair via safe server-created, short-lived one-time token; avoid embedding provider secrets in JS or URL; confirm business/site ownership with Central.
- Purchase/renewal executes in the verified hosted checkout/account system. Return with signed service provisioning notice and server-to-server reconciliation; never trust query parameters or browser plan selection as proof of payment.
- Capabilities are discovered from a versioned, signed entitlement API (`free_ai`, `assistant`, `agent_team`). They refer to **remote service requests**, not local PHP gates. Pro service IDs are retired.
- Visitor-facing Answer Box maintains same widget and known customer context on an upgraded plan. No second embed, duplicate booking table or migration of local records to a parallel system.
- Agent Team introduces in-dashboard specialist agent configuration: business knowledge, channels, delegated tool scopes, booking/cancellation approval policy, escalation owner, pause/resume and audit receipts.

## Shared service topology

```text
Client website + WordPress Admin
        |
Single Front Desk WordPress plugin
  - Local booking/service/customer DB (authority)
  - Rules-first FAQ/triage/cache
  - Secure signed REST/tool connector
  - AI Services settings/approvals/audit viewer
        |
Optional signed, tenant-scoped requests + consent
        |
Titan Central identity / billing / service entitlements
        |
Titan AI Proxy (caches, embeddings, model budgets)
        |
   +---- Assistant conversation service
   |
   +---- Agent Team orchestration + signed tool calls
             |
       Approved local Free booking APIs
       Optional third-party telephony/connected apps
```

The LLM cannot execute arbitrary PHP/SQL, assign itself permissions, invent slot availability or edit bookings directly. WordPress validates company, service, capacity, customer authority and idempotency, and generates a signed outcome receipt. Separate hosted budgets, abuse controls, data retention and consent settings apply.

## Agent Team division of responsibility

- **Enquiry agent:** source-grounded responses, classification, routing and human handoff.
- **Booking agent:** availability and booking changes only with customer verification and owner policy, through deterministic booking engine.
- **Follow-up/callback agent:** consent-aware, frequency-limited messaging or callback tasks; external transport is not included for free.
- **Quote intake agent:** capture service scope and produce drafts, never autonomously bind customer to quotes.
- **Supervisor agent:** permissions, escalation, monitoring, incident stop/pause, receipts and approval queues.

Specific names and number of agents may evolve, but these five responsibilities must be present before advertising a "full team of agents." Voice live call handling must be tested separately before advertising.

## Where old Pro feature specs go

Source: https://github.com/Masterleeaus/Titan-zero-front-desk-pro

| Old Pro areas | Target |
|---|---|
| Local location/staff controls, permissions, reporting, CRM, conditional forms, deterministic automations, recurrence, exports, notifications, approvals and resource policies | Local Free plugin after audit for duplicates and GPL migration; all supplied features usable for Free |
| Natural-language forms/workflows, multi-turn customer assistance, smart reply drafts, quote intake, summaries, guidance, semantic knowledge, reporting explanations | Hosted Assistant, controlled through same plugin |
| Autonomous service booking, outbound communications/voice, background multi-agent runs, cross-app execution | Hosted Agent Team, authorised tool connector |
| Separate Pro plugin bootstrap, Pro licence, ZIP updates, paid local feature gates | **Retire** product requirement (retain relevant test/security/provenance work) |

## Technical acceptance and release gates

- Clean install Free and safe upgrade from original Front Desk v3.0.128 and reconciled latest cumulative candidate. Preserve customer bookings, calendars, reminders, call logs and privacy records.
- Offline or proxy-down: local booking, schedule, rules, customer access and any locally shipped advanced UI continue to work.
- Hosted consent: no secret leakage, signed tenant-bound data scopes, revocation, export/delete, retention and no opportunistic telemetry.
- Every level switch: Free→Assistant→Agent Team→Assistant→Free, cancellation, chargeback, grace period, pause, revoked site pairing, agent stop and multiple-site ownership transfers.
- Real WordPress/PHP/MySQL integration tests, multisite, plugin security/Plugin Check, negative tests for IDOR/replay/nonce/overbooking, phone/SMS consent and sandbox provider failures.
- Measure actual model/embedding/hosting costs, token budgets and cache hits; do not promise <$0.01/site overall or unlimited free service until measured.
- No staging of enabled features, pricing/checkout links or production status in customer-facing marketing before true backend readiness.
- Reconcile website source/generated pages and Central catalogue; preserve /frontdeskpro legacy SEO links as redirects/explanations, not a product for sale.

## Free edition AI audit: code map and legacy migration (issue #2)

Audit of the add-on registry, admin pages, AJAX, REST, scripts, settings and shortcodes in the plugin tree at `titan-zero-trades-front-desk/`.

**Result:** the free build contains no AI execution path. It makes no AI provider call, sends no AI credential and prompts for none in a clean install. The only outbound HTTP call in the plugin is the Twilio SMS transport (`includes/class-tzfd-twilio-sms.php`), which runs only when administrator-provisioned constants are set.

| Location | Status | Action |
|---|---|---|
| `includes/class-tzfd-addon-loader.php` manifest | No AI file listed; unlisted files cannot load | none |
| `includes/class-tzfd-addon-loader.php` `domain_ids()` | `tzfd-AISetup` and `tzfd-AIAssistant` had no shipped module | removed; a saved legacy selection is dropped on the next catalogue save |
| `includes/class-tzfd-upgrades.php` | Pro, AI Assistant and AI Agent sales URLs | URL constants emptied; the `tzfd_upgrade_url` filter still accepts a verified https URL |
| `includes/class-tzfd-admin-notices.php` | `ai_key` dismiss key with no notice rendered | removed; only `speed` remains |
| `tz_fd_ai_assistant` stub | Not registered (#185) | `includes/class-tzfd-retired-shortcodes.php` strips the tag from content so old pages do not show raw shortcode text |
| `includes/class-tzfd-pipeline.php` filter `tz_fd_ai_create_booking` | Compatibility hook, no caller in the free build | kept; bookings still pass the same pipeline checks |
| `includes/tzfd-cron-hooks.php` `tz_fd_ai_cascade_tick`, `tz_fd_ai_prune_cron` | Nothing schedules them now | kept so deactivation and uninstall clear events from older installs |
| `includes/class-tzfd-scoped-store.php` `tz_fd_ai_*` keys | Stored text for the receptionist prompt, service list, area, greeting, fee and price ranges; never executed | kept as data |
| `addons/core/vertical-packs.addon.php` | Writes pack text into `tz_fd_ai_system_prompt` and related keys | kept as data; the copy still says "AI receptionist" and is a follow-up |

**Migration of pre-existing AI settings and data**
- No data is deleted. Legacy `tz_fd_ai_*` options move with the company-scoped store exactly as before, so an older install keeps its booking, customer and pack data and can roll back.
- Saved `tzfd-AISetup` or `tzfd-AIAssistant` selections in the add-on list have no module to run. They are removed on the next add-on save.
- Pre-existing scheduled AI cron events are still stopped by the shared hook list on deactivation and uninstall.
- No remote delete or opt-out is needed, because the free build sends nothing to an AI service.

**Regression evidence:** `tests/runtime/ai-free-edition-unit-tests.php` (17 checks, PHP CLI, no WordPress needed). It covers the manifest, the catalogue, negative cases for legacy AI selections and unlisted files, upgrade-link refusal of non-https URLs, the dismiss-key list, the retired shortcode, and a static scan for outbound AI calls.

## Authoritative issue tracker

- [Single-plugin master epic #45](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/45)
- [In-dashboard upgrade and entitlements #46](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/46)
- [Conversational Assistant #47](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/47)
- [AI Agent Team #48](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/48)
- [Former Pro feature migration #49](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/49)
- [Free AI base #19](https://github.com/Masterleeaus/Titan-Zero-Trades-Front-Desk/issues/19)
- [Central catalog #156](https://github.com/Masterleeaus/Central/issues/156)
- [Website master #9](https://github.com/Masterleeaus/Titan-zero-website/issues/9)

## Service contract v1 (issue #16)

Implemented in `titan-zero-trades-front-desk/includes/class-tzfd-service-contract.php` (pure PHP, no WordPress calls, not yet registered as REST routes). Tests: `php tests/contract/service-contract-tests.php`. Frozen registry and signature vector: `tests/contract/fixtures/contract-v1.json`.

- **Operations:** read `service.catalogue.list`, `staff.list`, `locations.list`, `form_fields.list`, `booking.list`, `booking.get`, `transaction.list`, `report.summary`, `settings.get`, `capability.check`, `privacy.export`; write `booking.create`, `booking.status.set`. Unlisted operations are refused. Changing the registry requires a new contract major.
- **Authorisation:** owner actors need the plugin capability (`tz_fd_access`), or `manage_options` for settings and privacy export. Agent actors need a delegated scope and, for writes and privacy export, owner approval. Visitors are refused everywhere. Every check requires the actor tenant to equal the request tenant.
- **Handshake:** the contract major must match on both sides, and the plugin version must be at least the hosted `min_plugin_version`. Older plugins are refused with `plugin_upgrade_required`.
- **Signed envelope:** HMAC-SHA256 over canonical JSON of `v, op, tenant_id, ts, nonce, payload`. The window is ±300 s. Nonces are single-use. Bodies are capped at 64 KiB, payloads at depth 6 and 10,000-byte strings. The key must be at least 32 bytes and is never sent to the browser.

Not yet covered: REST route registration, legacy Pro option migration, the hosted Central and AI Proxy endpoints, and WordPress runtime, outage and downgrade tests.
