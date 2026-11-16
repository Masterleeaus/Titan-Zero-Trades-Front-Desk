# Runtime checks (not shipped in the plugin)

These sit outside the plugin folder, so the WordPress.org package does not include them.

## 1. Pure-PHP unit tests (no WordPress, no database)

`unit/run-unit-tests.php` loads the plugin's own classes against stubs in `unit/bootstrap.php` (a fake `wpdb` with transactional rollback, and WordPress function stand-ins). It needs only PHP 8.x.

```
php tests/runtime/unit/run-unit-tests.php
```

Run from the repository root. If the plugin is elsewhere, set `TZFD_PLUGIN_DIR=/path/to/titan-zero-trades-front-desk`. Exit code 0 means no failures.

What it covers:
- Booking create gating (`TZFD_Booking_Policy`): notice, advance window, duration, capacity, per-customer limit, prerequisites, approval and deposit hand-off, fail-closed inputs.
- Create transaction (`TZFD_Booking_Create_Transaction`): success commit, insert failure rolls back, lost COMMIT reported `unknown` with no rollback, company 0 and non-InnoDB refused before any statement.
- Capacity and double-booking rules: over-assigned snapshots refused, forecast shortfall ranking, paged reservation reads (failure is `null`, not empty), per-form lock acquire, re-entry, forged and stale release, busy timeout.
- Cancellation: link token binding (booking, email, version, expiry), cancelable states, no second cancel, compare-and-swap guarded by the whole stored snapshot.
- Rescheduling: link token binding, same-day slot rule, version change invalidates old links.
- Upgrade safety: legacy object payloads are not restored, malformed or oversized data returns `null`, projections return only named fields.
- Slot holds: company-scoped unique index check, hold request validation, confirmation payload checks.
- Migrations: dry-run plan, authorization, lock behaviour, stale lock not taken over.

Limit: these prove the logic in these classes, not behaviour inside WordPress, MySQL locking across real connections, or the HTTP handlers.

## 2. On-site checks (disposable WordPress test site, WP-CLI)

`wp-site-checks.php` runs on the test site with the plugin active at `/var/www/html/wp-content/plugins/titan-zero-trades-front-desk/`.

Upgrade from v3.0.128 with existing bookings:
```
# On a site running 3.0.128, with at least one booking:
wp --allow-root eval-file tests/runtime/wp-site-checks.php seed /tmp/tzfd-upgrade-manifest.json
# Upgrade the plugin to the build under test (copy the ZIP over and activate), then:
wp --allow-root eval-file tests/runtime/wp-site-checks.php verify /tmp/tzfd-upgrade-manifest.json
```
`verify` fails if any pre-upgrade booking is missing, its stored payload bytes changed, or it no longer decodes.

Per-form lock contention (two connections):
```
wp --allow-root eval-file tests/runtime/wp-site-checks.php lock-hold 1 8 &
sleep 2
wp --allow-root eval-file tests/runtime/wp-site-checks.php lock-try 1
wait
```
`lock-try` must report PASS while `lock-hold` is running.

Existing checks (unchanged):
- `runtime-tests.php`: create, rollback, privacy export and erase. Run with `wp --allow-root eval-file runtime-tests.php`.
- `http-auth-tests.sh`: unauthenticated requests to each REST and AJAX entry point must refuse. Edit `B` for your site.

## Status

- Unit tests: executed on PHP 8.3.6, 121 passed, 0 failed. Mutation check (two faults seeded into a copy of the plugin: capacity boundary and CAS snapshot guard) produced 2 expected failures.
- On-site checks in `wp-site-checks.php`: written, not executed. No WordPress or MySQL was available here.

## Not covered

- Real concurrent double-booking through HTTP: needs two live requests on MySQL (lock-try above is a partial substitute).
- Cancellation and rescheduling through their `admin-post` handlers, including email and nonce checks: need a WordPress request cycle.
- Refunds and deposits: the policy only flags a deposit hand-off to invoicing (`TZFD_Booking_Policy`); no payment or refund flow is tested.
- Reminders and outbox delivery: not covered.
- Upgrade from v3.0.128 itself: the `seed`/`verify` pair needs a real 3.0.128 site and is not yet run.
- Uninstall, multisite, webhook replay with a valid secret: not covered.
- The unit tests do not touch `TZFD_Customer_Rescheduling::reconcile()` (needs the scheduling engine).
