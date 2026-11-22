# Central company pairing (issue #376)

Status: local side implemented and tested (`includes/class-tzfd-central-tenant.php`). The Central #186 contract could not be read from this session, so the pairing wire format is provisional. Hosted pairing is not live.

## 1. Where the local integer `company_id` is used

Front Desk has one site-local company scope. `TZFD_Local_Business::id()` returns `1` on every site, and `TZFD_Scoped_Store::company_id()` wraps it. The value is a `bigint unsigned` column, not a Central identifier.

| Area | Storage | Local scope |
| --- | --- | --- |
| Main plugin tables (messages, forms, pipeline, enquiry triage, local services, local customers) | `company_id` columns, mostly `DEFAULT 0` or `DEFAULT 1` | Rows belong to the local company. |
| Booking and call tables (booking inbox, action outbox, call lifecycle, telephone delivery, contact actions, escalation state, local audit, smart-task and pipeline notification outboxes) | `company_id bigint unsigned NOT NULL` | Scoped on every query. |
| Add-on tables (iCal export, calendarless interface, cache, addon records, business schema) | `company_id` columns | Scoped on every query. |
| Slot holds | `company_id varchar(80)` | Uses a resource-catalog identifier, not the integer. Requires `verified_company_id`. |
| Connect bridge | Receives a tenant string and a filter-authorised integer | Maps only through an explicit filter. No guessed tenant mapping. |
| Service contract | `authorize()` requires an integer `tenant_id` | Compares against the actor's local tenant. |
| AI connector (`tzfd-ai/1`) | Uses `site_id` (string), not `company_id` | Local integer is not sent. |

About 127 call sites read the local integer through `TZFD_Local_Business::id()` or `TZFD_Scoped_Store::company_id()`. None of them sends it to the hosted service.

Rule set by this change: the local integer stays as it is. The only value sent to the hosted service as a company identifier is a canonical Central company UUID stored in the pairing reference. Those two are never interchangeable (`TZFD_Central_Tenant::is_company_uuid()` rejects every integer).

## 2. What is stored

| Option | Holds | Autoload |
| --- | --- | --- |
| `tzfd_central_tenant` | Record version, contract id, site id, company UUID, operations, paired time, expiry. No secret. | off |
| `tzfd_central_tenant_secret` | Per-site credential (at least 32 characters). | off |
| `tzfd_central_pairing_nonces` | Hashed nonces already used, kept for one hour. | off |
| `tzfd_central_tenant_audit` | Last 20 actions (connect, rotate, reassign, disconnect), company UUID and time. | off |

No option holds the local integer as a company UUID, and no status or audit output contains the credential.

## 3. Rules the hosted call must pass

Before any hosted request, `TZFD_AI_Connector` calls `TZFD_Central_Tenant::resolve()`. The call is refused with `not_configured` and no budget is charged unless all of these hold:

- a pairing record exists and has record version 1;
- the site id matches the pairing;
- the company value is a canonical UUID;
- the pairing has not expired;
- the operation (`hosted.request`) is in the pairing;
- the credential is present.

The pairing assertion itself is checked for: signature (HMAC-SHA256 over timestamp, nonce and raw body), timestamp within 300 seconds, nonce echoed in the signed body, contract id, site match, company UUID, issue time not in the future, lifetime of at most one hour, required fields, and no replay.

## 4. Setup (site owner)

Status: there is no admin screen for pairing yet. The connect call exists in code (`TZFD_Central_Tenant::connect()`) and is tested, but no page calls it.

1. In Supercharged, create a pairing code. (Owner decision, 2026-11-18: the site owner enters a pairing code shown by Supercharged.)
2. Enter the code in Front Desk and connect the site. This step needs the admin screen and the pairing endpoint, both still open.
3. Check the status: connected, company, expiry. The credential is never shown.

## 5. Troubleshooting

| Symptom | Likely cause | Action |
| --- | --- | --- |
| Hosted AI answers `not_configured` | No pairing, pairing expired, or site id changed | Reconnect the site with a new pairing code. Local booking is unaffected. |
| Connect refused with `company_reassign_required` | Site is already paired to another company | Disconnect first, then pair again. Reassignment needs an explicit owner action. |
| Connect refused with `replayed` | The same assertion was submitted twice | Create a new pairing code and retry. |
| Connect refused with `stale` | Site clock is more than five minutes off | Fix the server time, then retry. |
| Connect refused with `bad_signature` | Credential does not match the assertion (for example after rotation) | Pair again. Do not copy credentials between sites. |
| Connect refused with `contract_unsupported` | Hosted side uses another pairing contract version | Update the plugin or the hosted service so both use the same version. |

Disconnecting removes the pairing and credential only. Bookings, customers, settings and the local integer `company_id` stay as they were.

## 6. Open items

- Confirm the Central #186 contract: contract id, field names and signature keys. The current `central-pairing/1` is provisional.
- Confirm the pairing endpoint URL (owner input, tracked on the open remaining-work issue).
- Live end-to-end pairing against the hosted service is not run. It needs the hosted service.
