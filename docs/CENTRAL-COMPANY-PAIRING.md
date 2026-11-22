# Central company pairing (issue #376)

Status: local side implemented and tested (`includes/class-tzfd-central-tenant.php`). The owner accepted the wire format `central-pairing/1` as the contract on #451 (decision recorded there). The Central #186 text could not be read from this session, so it is not independently confirmed against Central. Hosted pairing is not live. The owner-decided endpoint URL (#456) is not yet wired in code on main.

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

- Central #186 text: not read from this session. The contract id and field names are accepted as `central-pairing/1` by the owner decision on #451. Confirming them against Central #186 needs owner or Central sign-off.
- Pairing endpoint URL: decided by the owner (issue #456) as `https://central.titanzero.io/api/tzfd/pair`. Not yet in code on main; proposed in PR #427, which is open and not merged.
- Admin connect screen (enter the pairing code): not built. `TZFD_Central_Tenant::connect()` has no caller yet (tracked on #452).
- Live end-to-end pairing against the hosted service: not run. It needs the endpoint URL and the hosted side.

## 7. Task breakdown and status (issue #456)

| Task | Status on main |
| --- | --- |
| Baseline: no endpoint constant, filter or default on main (`grep -rn TZFD_AI_PAIRING_ENDPOINT` and `grep -rn central.titanzero.io` return nothing) | Recorded here |
| Endpoint URL decision recorded | Done (section 6) |
| Pairing endpoint constant or filter with https-only validation, off by default | Open in PR #427 (not merged) |
| Pairing handshake and entitlement fetch, admin connect and disconnect | Open in PR #427 and issue #458 |
| Hosted Central contract (#186), signing keys, per-site secret issuance | Needs owner sign-off; not ticked |
| Live end-to-end test against the hosted service | Needs the hosted service; issue #458 |

## 8. Task breakdown for #451

Each task is independently verifiable. "Done" means evidence on main.

| # | Task | Status | Evidence |
| --- | --- | --- | --- |
| 1 | Accept `central-pairing/1` and the seven assertion fields as the plugin contract | Done (owner decision on #451) | This document; `class-tzfd-central-tenant.php` `CONTRACT` and `assertion_fields()` |
| 2 | Verify assertion signature, timestamp, nonce, site, company UUID, lifetime and replay | Done | `tests/central-tenant/run-central-tenant-tests.php`, run by `.github/workflows/tests.yml` (step "Central company pairing tests") |
| 3 | Refuse hosted calls on unpaired, expired, mismatched or unsupported pairings, with no budget charge | Done | `class-tzfd-ai-connector.php` (`TZFD_Central_Tenant::resolve()` before transport); `tests/ai-connector/run-ai-connector-tests.php` (`not_configured`, no transport call) |
| 4 | Keep the local integer `company_id` out of the hosted call and out of company UUID checks | Done | `is_company_uuid()` rejects integers; test "local integer 1 is not a company UUID" in the central tenant runner |
| 5 | Admin screen to enter a pairing code | Open (#452) | No caller of `connect()` in the admin UI |
| 6 | Confirm the Central #186 contract text | Open, owner or Central sign-off | Not readable from this session |
| 7 | Wire the owner-decided endpoint URL as an https-only default | Open in PR #427 (not merged) | No URL in code on main |
| 8 | Live pairing end to end against the hosted service | Open, blocked by 6 and 7 | Not run |
