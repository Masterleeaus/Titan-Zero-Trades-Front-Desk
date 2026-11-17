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
