# AI Services: in-plugin entitlement view and downgrade contract

Scope: issues #46 (dashboard upgrade, entitlements, downgrade), #47 (Assistant), #48 (Agent Team), #49 (migration). Parent epic #45.

Canonical architecture: `docs/SINGLE-PLUGIN-AI-SERVICES-ARCHITECTURE.md`.
Transport, signing, identity and proxy contract: owned by #16, #4 and #43. This document consumes that contract and does not redefine it.

## What this repo ships now

| Piece | Path | Behaviour |
|---|---|---|
| Level logic | `titan-zero-trades-front-desk/includes/class-tzfd-ai-services.php` | Pure PHP. Resolves a cached, already verified entitlement payload into `free_ai`, `assistant` or `agent_team`. No network, no storage writes. |
| Capability descriptors | `titan-zero-trades-front-desk/data/ai-services/capabilities-v1.json` | Describes each level, what it includes, its remote services and disclosure text. Prices are `null`. |
| Dashboard view | `titan-zero-trades-front-desk/includes/class-tzfd-ai-services-page.php` | Submenu `AI Services` under Front Desk, capability `tz_fd_access`. Shows status, comparison and a local downgrade. |
| Bootstrap hook | `titan-zero-trades-front-desk/tzfd-bootstrap.inc.php` | Two lines that load and register the page. |
| Contract tests | `tests/ai-services/run-contract-tests.php` with `tests/ai-services/fixtures/*.json` | 12 entitlement fixtures plus matrix and descriptor checks. Run with `php tests/ai-services/run-contract-tests.php`. |

Not in this repo, on purpose: checkout, billing, card or payment data, subscription management, the hosted server, signature verification, and the consent store. Billing is out of scope for this lane.

## Entitlement payload (consumer view)

The connector (#16) delivers a signed payload. This plugin reads it only after the connector has verified it, and stores it in the option `tzfd_ai_services_entitlement`.

| Field | Type | Rule |
|---|---|---|
| `schema_version` | int | Must be `1`. Any other value resolves to Free (`unsupported_schema`). |
| `company_id` | int | Must be positive. Must equal the site's company when the caller supplies one (`company_mismatch`). |
| `state` | string | `active`, `grace`, `paused_agents`, or any other value (see the table below). |
| `entitlements` | string[] | Known values: `assistant`, `agent_team`. Other values are ignored, including retired Pro identifiers. |
| `expires_at` | int (unix) | Short TTL. At or after this time the cache is ignored (`cache_expired`). |

The option is a cache of a verified answer. It is never proof of payment by itself.

## Resolution rules

| Condition | Level | Reason |
|---|---|---|
| No cached payload (includes offline and never connected) | `free_ai` | `no_entitlement` |
| Wrong schema version | `free_ai` | `unsupported_schema` |
| Missing or invalid company | `free_ai` | `invalid_company` |
| Company differs from the site's company | `free_ai` | `company_mismatch` |
| Cache past `expires_at` | `free_ai` | `cache_expired` |
| No known entitlement in list | `free_ai` | `no_known_entitlement` |
| `state` = `paused_agents` and `assistant` listed | `assistant` | `paused_agents` |
| `state` = `paused_agents` without `assistant` | `free_ai` | `paused_agents` |
| `state` = `active` or `grace`, `agent_team` listed | `agent_team` | `active` or `grace` |
| `state` = `active` or `grace`, only `assistant` listed | `assistant` | `active` or `grace` |
| `state` = `revoked`, `expired`, `cancelled`, `over_budget`, or any other value | `free_ai` | the state value |

Decisions made here, for review:

1. `agent_team` includes the Assistant experience. The entitlement is `agent_team` only and there is no second billing line for `assistant`. This matches the commercial proposal in #46.
2. `grace` keeps the paid level until the connector moves the state. The connector sets the grace length, not this plugin.
3. `paused_agents` drops Agent Team and keeps Assistant when it is listed, so the owner can pause agents without losing conversations.
4. Unknown states resolve to Free, which is the fail-safe default.

## Local features are never gated

`TZFD_AI_Services::local_features_enabled()` returns `true` for every level and every state. The tests check this for each fixture. Booking, service, customer, calendar, reminder, rules, FAQ, reports, privacy export and local audit stay on in every case. This follows WordPress.org guidelines 5 to 8, as set out in the architecture doc.

## Remote service matrix

| Service | Free | Assistant | Agent Team |
|---|---|---|---|
| `basic_ai` (opt-in, consent required) | yes | yes | yes |
| `assistant` (conversation, drafts, summaries) | no | yes | yes |
| `agent_team` (autonomous agents, approvals, receipts) | no | no | yes |

Remote calls are requested only through `TZFD_AI_Services::remote_service_allowed()`. Consent for each data category is a separate control that is not built in this lane (see the open items below).

## Downgrade contract

Downgrade means the remote service stops. It never means local data or local features are removed.

`TZFD_AI_Services::downgrade_actions( $from, $to )` returns three lists:

- `preserved`: bookings, customers, services, staff, resources, calendars, reminders, FAQ knowledge, local rules, local reports, privacy exports and the local audit log. These are kept on every downgrade.
- `stopped_remote`: for example `agent_schedules_and_queued_agent_tasks` when leaving Agent Team, and `assistant_conversations_and_drafts_requests` when leaving Assistant.
- `disabled_local`: always empty. The test suite checks this.

Local downgrade on the dashboard (`Switch this site to Free`):

1. Requires capability `tz_fd_access` and a valid nonce.
2. Deletes only the cached entitlement option `tzfd_ai_services_entitlement`.
3. Changes no booking, customer, service, calendar, rule or reminder rows.
4. Does not cancel billing. The owner cancels the subscription in the Titan account, and the connector then sends a state change.

Decisions made here, for review:

5. The local downgrade is a cache reset, not a cancellation. It stops hosted use on this site immediately, even if the Titan account still has an active subscription. The next verified connector payload would set the level again.
6. The dashboard does not record a downgrade in the audit log yet. That needs the local audit API, which is open below.

## Dashboard behaviour

- The current level, its reason, and the local-features status are always visible.
- `Connect Titan account` is disabled with the text "Not available yet". No checkout link, no account credential prompt, and no fake plan purchase.
- The comparison table takes its text from the descriptor file. Price cells read "Shown by your Titan account before any charge."
- The downgrade button appears only when a hosted level or a cached payload exists.
- Hosted data consent reads "None recorded" until a consent store exists.

## Open items (not done in this lane)

- Signed payload verification, the connector transport, and identity pairing: #16, #4, #43.
- Consent store per data category, with an audit trail and revocation: #46 (consent items).
- Owner approve/deny and Agent Team pause/resume controls: #48.
- Audit log entry for downgrade: needs the local audit API (see `class-tzfd-local-audit.php`, not wired here).
- Multisite and per-company tests beyond the company-id check in pure logic.
- Plugin Check and a live WordPress run of the admin page. This lane ran PHP lint and the pure logic tests only.

## Outage and malformed payloads (issue #340)

Outage means the connector cannot be reached. The only input is the cached option, so the result depends on its `expires_at` and `state`. Covered by the `outage:` checks in `tests/ai-services/run-contract-tests.php` (run in the CI unit job):

| Situation | Level | Reason | Local features |
|---|---|---|---|
| Never connected, no cache | `free_ai` | `no_entitlement` | on |
| Cache inside TTL, `active` with agent team | `agent_team` | `active` | on |
| Cache inside TTL, `grace` | `agent_team` | `grace` | on |
| Cache at or after `expires_at` | `free_ai` | `cache_expired` | on |
| Expired cache with `paused_agents` and assistant | `free_ai` | `cache_expired` | on |

Downgrade on expiry uses `downgrade_actions()`, and the test checks that every local data list stays in `preserved`.

Malformed payloads must resolve to Free with no PHP warning. The checks cover a string or int payload, a non-string `state`, `entitlements` as a bare string or nested array, an unknown entitlement name, a negative company, a future schema version and a non-numeric `expires_at`. Two defects were found and fixed in `class-tzfd-ai-services.php`:

- A non-string `state` (for example an array) raised "Array to string conversion" and produced the reason `Array`. It now resolves to `unknown_state`.
- `entitlements` given as a bare string (for example `"agent_team"`) was cast to a list and granted Agent Team. Only an array of strings is read now, so a bare string resolves to Free.

Decision for review: a malformed `entitlements` value fails safe to Free, even when it holds a valid name.

### Threat model (summary)

| Threat | Control in this repo | Residual risk |
|---|---|---|
| Forged entitlement payload | None here. The payload is read only after the connector verifies it (#16, #4, #43). | Not evidenced in this repo. Blocks release. |
| Tampered cache option | Only a verified payload is written, and `company_id` must match the site. | A user with `manage_options` can write the option directly. |
| Replay of an old payload after cancellation | Short `expires_at` TTL, then `cache_expired`. | Replay within the TTL is accepted until the connector moves the state. |
| Downgrade causes data loss | `preserved` list and `disabled_local` empty, tested. Runtime upgrade/downgrade run also keeps bookings (see `tests/runtime/README.md`). | None known. |
| Hostile or malformed payload | Type checks in `resolve()`, malformed-input tests. | None known for the cases tested. |

### Readiness assessment (not a sign-off)

Evidenced: pure-logic resolution, downgrade preservation, outage and malformed-input checks (standalone CI job); the recorded runtime upgrade and downgrade run from 2026-11-16 (31 checks, bookings and AI settings survived both directions); the authorisation inventory (PR #317).

Not evidenced: signed payload verification and the connector transport (#16, #4, #43); a live WordPress run of the AI Services admin page; consent records and the downgrade audit entry.

Owner decisions recorded (issue #371, 2026-11-23): the public wording for the Free, Titan Zero AI Assistant and Titan Zero AI Agent Team plan descriptions and the setup consent notice (`wording_status` `owner_approved_issue_348` in `includes/class-tzfd-ai-connector.php`, already on main from #348); the usage and fair-use text ("fair use with published hosted budgets; not unlimited", from `data/ai-services/capabilities-v1.json`). The consent terms link is still a draft pending legal review and is not covered by this sign-off.

Owner decisions still needed (not made here): production readiness sign-off, the release decision, the retention for the hosted data categories, and the live Titan account and billing go-live (depends on the connector being live).
