# Production-readiness record: remaining work from #149 (carried from #7 and #73)

Scope: the nine checkboxes in #149. Recorded 2026-11-22 on branch `claude/issue-149`, base `origin/main` at `cb8144e`.
Plugin version in the checkout: 3.0.128 (Requires WordPress 6.6, Requires PHP 7.4, Tested up to 7.1).

This is an evidence record, not a sign-off. Nothing is ticked in #149 unless the evidence below proves it.

## What this pass checked

- Repository inventory: 291 PHP files under `titan-zero-trades-front-desk/`. Upstream provenance is in `UPSTREAM.txt`
  and licences in `THIRD-PARTY-NOTICES.txt`.
- Database schema: 16 `CREATE TABLE` statements across the plugin and its add-ons. A field-by-field map of which
  tables hold customer personal data was not written in this pass.
- CI already present on `main` (`.github/workflows/tests.yml`): PHP syntax on every file, standalone unit and contract
  harnesses, WordPress runtime on PHP 8.3 / latest WordPress / MariaDB 10.11, and a `wordpress-multisite` job.
- MariaDB 10.11 was installed in this session with apt. Starting it was refused by the permission classifier, so
  no WordPress runtime suite, HTTP auth check or upgrade test was run in this pass.

## Task status

| # | Task (from #149) | Status | Evidence / what is missing |
|---|---|---|---|
| 1 | Inspect source, interfaces, customer data, dependencies | Partly done | Inventory above. Missing: customer-data field map and dependency review. Left unticked. |
| 2 | Provision WordPress + MySQL across PHP/WP versions and multisite | Open | CI runs one PHP/WP pair plus multisite. No other PHP or WordPress versions are provisioned. Local DB start was refused in this pass. |
| 3 | Install, upgrade from v3.0.128 with bookings, booking/hold/cancel/refund/reminder checks | Open | `tests/runtime/upgrade-from-3.0.128.sh` refuses a base with the same version as the checkout (per the 2026-11-18 status comment on #149). Owner decision needed on the test base and seed data. Refunds and deposits have no runtime surface (per `tests/runtime/README.md`). |
| 4 | Privacy export/erase, uninstall safety | Ticked before this pass | Unchanged. |
| 5 | Fail closed on invalid nonce/capability | Ticked before this pass | Unchanged. |
| 6 | Document tests with truthful status; rollback and migrations | Partly done | This file records status. Rollback and migration notes for the live site are not written. Left unticked. |
| 7 | Source changes, tests, measured diff, threat model, downgrade | Open | No threat model exists for this scope (per `docs/PRODUCTION-READINESS-REMAINING-151.md`). No source change in this pass. |
| 8 | Acceptance, regression, malicious-input, tenant, outage tests | Partly done | Static and pure-PHP suites run in CI. Live HTTP auth, replay concurrency and outage-on-booking-path tests need a running site. |
| 9 | Attach commits, evidence, readiness assessment | Open | Left for the owner after a live matrix run. |

## Verdict

Not ready for a production claim on the #149 scope. No new task is ticked by this pass. The open items need either
a live disposable WordPress and database run, or an owner decision (test base version for the upgrade, threat model
owner, and the final readiness sign-off).
