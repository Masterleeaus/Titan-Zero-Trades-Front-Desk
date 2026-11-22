# Release readiness and threat notes (issue #340, from #151 and #8)

Status date: 2026-11-23. Scope: the REST, AJAX and callback entry points, the downgrade path, and the outage behaviour of the SMS gateway and outbound webhooks. This file records evidence. It does not claim WordPress.org approval, and it does not replace the owner decisions in `docs/SUBMISSION-CHECKLIST.md`.

## 1. Verdict

Not ready for a public WordPress.org release yet. The checks below pass, but the open items in section 6 still block release.

## 2. Evidence

| Area | What was run | Result |
|---|---|---|
| Entry point inventory (#151) | `tests/unit/test-rest-ajax-authz-inventory.php`, merged in PR #317 (merge 4a5b87a) | Inventories 9 `register_rest_route` calls and 41 `admin_post` / `wp_ajax` registrations. Logged-in handlers must check a nonce and a capability or company scope. Logged-out handlers must check a nonce, signed request or token. Exit 0. |
| Webhook and malformed-event adversarial tests | `php tests/security/webhook-adversarial-tests.php` | 57 passed, 0 failed |
| Standalone unit suite | `php tests/unit/run.php` | Local-free suite 572 passed, 0 failed. Free AI cases 177 passed, 0 failed. |
| Downgrade (and upgrade) on a disposable site | `tests/runtime/upgrade-from-3.0.128.sh`, base 2.0.1 from commit 4e0c3b3, run under the runtime lock | 10 passed, 0 failed, 0 gap. Version and table checks pass both ways. |
| SMS gateway and webhook outage | `tests/runtime/outage-runtime-tests.php` (new in this PR), run in the runtime suite | 13 passed, 0 failed (details in section 4). |
| Full WordPress runtime suite | `tests/runtime/run-wp-runtime.sh` on port 8091, under the runtime lock | 376 passed, 0 failed, 0 gap, 9 skipped (multisite, PHP and WordPress matrix, live provider are skipped by design). |
| Hosted AI outage | `tests/contract/hosted-double-tests.php` (existing) | An outage returns 503 `hosted_unavailable`, runs no executor, and a retry with the same nonce succeeds after recovery. |

## 3. Downgrade: what the test does and does not prove

- The script installs the 2.0.1 release, activates the current 3.0.128 checkout, deactivates and reactivates, and then goes back to 2.0.1. Version headers and table presence are checked at each step.
- The 2.0.1 base has its own booking schema, so the script does not seed or verify booking rows. The "no loss of existing bookings" item is therefore not proven by a test here.
- Owner decision (2026-11-18, recorded in `supercharged-fixes-checklist.md`): upgrade proof with existing data is not needed because there are no customers yet. The no-loss item relies on that decision. Jason should confirm it.

## 4. Outage behaviour

Simulated at the HTTP layer (`pre_http_request`), so no request leaves the sandbox.

- SMS gateway (`tzfd_telephone_deliver_v1`): a transport error, an HTTP 503 and a non-JSON 201 each return no receipt, make exactly one attempt to the Twilio host, and do not throw. A repeat attempt also returns no receipt.
- Outbound webhooks: a `booking.created` event is queued for cron while the endpoint is down. A delivery attempt makes one request, fails closed, and does not throw. The booking path makes no HTTP call. Delivery runs later from cron.
- Local bookings: a booking row can be written and read back while the gateway is down, and the failures leave the booking count unchanged.
- Not covered: the web-page and CSV import in onboarding. The web fetch in `addons/core/onboarding.addon.php` returns a user-facing error on failure, but no runtime test exercises it. This is tracked as a new issue (see section 6).

## 5. Threat notes (where relevant)

| Threat | Control | Evidence |
|---|---|---|
| Forged or unauthenticated REST/AJAX calls | Nonce or signed request on every handler; capability or company scope on logged-in handlers | Inventory test (section 2) |
| Webhook replay and forged webhook calls | HMAC signature, replay claim | Adversarial tests, 57 passed |
| Malformed SMS or call events | Strict schema checks before any state change | Adversarial tests |
| Tampered or oversized inbound payloads | Size caps, MIME and extension checks on upload paths | Inventory test; upload checks in PR #317 |
| SSRF through the onboarding web import | `wp_safe_remote_get` and size limit | Code inspection only |
| Secret exposure through the AI connector | Secret never returned by status responses | Code inspection (PR #65); the secret is stored unencrypted, see section 6 |
| Downgrade leaves data unsafe | Downgrade stops remote services only; local data kept | Downgrade test (section 3) |

## 6. Open before release

1. Owner decision: encrypt the connector signing secret at rest (`docs/SUBMISSION-CHECKLIST.md`, owner decision 2).
2. Owner decision: commission a written security audit with findings (owner decision 8). No audit document exists in the repo.
3. Import outage test for the onboarding web import and CSV path. New issue linked from #340.
4. Plugin Check run on a WordPress site and PHP 7.4 lint (both open in the submission checklist).
5. PR #314 was closed as a duplicate of PR #317 and is not merged. PR #317 holds the same commit and is merged.
