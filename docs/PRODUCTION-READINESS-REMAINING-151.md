# Production-readiness assessment: remaining work from #151 (issue #340)

Scope: the three unchecked items in #340, which carry forward #151 (authorisation inventory, PR #317 merge 4a5b87a)
and #57/#8 (REST/AJAX authorisation review). Recorded 2026-11-23 on branch `issue-340-remaining-151`, PHP 8.3.6.

This is an evidence record, not a certificate. Each row states what was run here and what remains open.
No WordPress site, MySQL/MariaDB server or WP-CLI was available in this run, so no live WordPress runtime test
was executed.

## 1. Source changes, tests, threat model, bookings, downgrade, readiness report

| Item | Status | Evidence |
|---|---|---|
| Authorisation inventory (REST, admin-post, AJAX) | Done (static) | `tests/unit/test-rest-ajax-authz-inventory.php`, run through `tests/unit/run.php` |
| Hosted-double outage contract (#256) | Fixed in this lane | `tests/contract/hosted-double-tests.php` silently exited 0 with zero checks because it did not define `ABSPATH` before loading the guarded contract class. It now runs 32 checks, 0 failed, including outage checks 10 (503 with no executor run, and retry with the same nonce after recovery) |
| Downgrade keeps local data (pure logic) | Done (pure PHP) | `tests/ai-services/run-contract-tests.php`: 73 passed, 0 failed |
| Downgrade keeps bookings on a live site | Open | Needs the WordPress runtime run (`tests/runtime/upgrade-from-3.0.128.sh` and `run-wp-runtime.sh`). Not run here |
| Threat model for the hosted connector and AI services | Open | No threat model document exists for this scope. The architecture doc is `docs/SINGLE-PLUGIN-AI-SERVICES-ARCHITECTURE.md`; it does not contain one |
| Measured diff | Recorded at merge | `git diff --stat origin/main` on the landed branch |

## 2. Acceptance, regression, malicious-input, tenant/permission and outage tests

| Test class | Status | Evidence |
|---|---|---|
| Unit regression (local-free and Free AI cases) | Pass | `php tests/unit/run.php`: local-free suite passed 572, failed 0; Free AI cases 177 assertions, 0 failed |
| Service contract | Pass | `php tests/contract/service-contract-tests.php`: 88 passed, 0 failed |
| Hosted-double contract (tenant, signature, nonce, outage) | Pass | 32 passed, 0 failed (see section 1) |
| AI connector (race, cost units) | Pass | `php tests/ai-connector/run-ai-connector-tests.php`: 86 passed, 0 failed |
| Legacy hosted AI data (outage of chat table, restore refusal) | Pass | `php tests/hosted-ai-legacy/legacy-ai-tests.php`: 62 passed, 0 failed |
| Lint | Pass | `php -l` on every changed PHP file |
| Malicious-input and tenant/permission runtime (REST nonce, capability, IDOR over HTTP) | Open | `tests/runtime/http-auth-tests.sh` needs a live site. Not run |
| Outage of SMS gateway, webhook or import with normal booking still working | Open | Not run on a live site. The outbox delivery call is wrapped in `try/catch` (`class-tzfd-booking-action-outbox.php`), and the contract double covers the hosted side only. Booking-path outage is not evidenced |
| Replay concurrency (webhook atomicity, #188) | Open | `tests/runtime/replay-concurrency-test.sh` and `twilio-status-replay-concurrency-test.sh` need a running disposable WordPress test site with several PHP workers. Not run |

Environment blockers recorded for this run: no `mysqld`/`mariadb`, no `wp` (WP-CLI). `tests/runtime/provision-wp-site.sh`
requires an empty WordPress root and was not run.

## 3. Production-readiness verdict

Not ready for a production claim on the #151 scope. Passing here: static authorisation inventory, pure-PHP contract
and entitlement logic, and the hosted-double outage contract. Open: live WordPress runtime evidence for booking
survival under outage, downgrade on a live site, HTTP-level authorisation, replay concurrency, and a threat model.
The release gate in `docs/WORDPRESS-ORG-READINESS.md` section 5 still lists "Service outage leaves normal booking
working" as unchecked, and this record does not change that.

## 4. Known test-harness defect (fixed)

Standalone test files that load a class guarded by `defined( 'ABSPATH' ) || exit;` must define `ABSPATH` first.
Without it the process exits 0 with no output, so the suite appears to pass. A scan of standalone files under
`tests/` found no other file with this pattern outside `tests/unit/`, whose function-only files are loaded by
`tests/unit/run.php`.
