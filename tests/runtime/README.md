# Runtime tests (not shipped in the plugin)

These run against a disposable real WordPress site with MySQL/MariaDB and WP-CLI. The `tests/` folder is outside
the plugin folder, so the WordPress.org package does not include it. No test calls a live external service: no SMS,
e-mail transport, telephony or hosted AI.

## Run

```sh
# Needs: php, WP-CLI, a reachable MySQL/MariaDB for the admin user (default root, socket auth), curl.
WP_CLI="php /path/to/wp-cli.phar" tests/runtime/run-wp-runtime.sh              # single site
WP_CLI="php /path/to/wp-cli.phar" tests/runtime/run-wp-runtime.sh --multisite  # network
WP_CLI="php /path/to/wp-cli.phar" tests/runtime/upgrade-from-3.0.128.sh        # upgrade and downgrade
```

Exit codes: `0` every executed check passed (SKIP and GAP lines do not fail a run); `1` a check failed; `3`
prerequisites missing, nothing executed (every suite is printed as SKIP). Logs are kept in the `/tmp/tzfd-rt.*`
folder that the run prints. The site and its database are removed unless `KEEP_SITE=1`.

Each check prints one line: `PASS`, `FAIL`, `SKIP` (not run, reason given) or `GAP` (a product gap observed; reported,
not counted as a pass).

## Files

| File | What it does |
| --- | --- |
| `run-wp-runtime.sh` | Wrapper: provisions a site, starts `php -S`, runs every suite, prints SKIP lines for coverage not run here. |
| `provision-wp-site.sh` | Fresh database, WordPress core, plugin symlinked from the checkout, activated as user 1, one subscriber. |
| `wp-runtime-tests.php` | Real checks: booking create, slot holds, capacity lock, reschedule and cancel tokens, reminder outbox, capability, uninstall keep path, multisite detection. |
| `runtime-tests.php` | Privacy export and erase, and signed missed-call requests (21 checks). |
| `http-auth-tests.sh` | Unauthenticated refusals, plus logged-in admin and subscriber checks for REST nonce and capability. |
| `uninstall-wipe-check.php` | Destructive: opt-in uninstall (`TZ_DELETE_DATA_ON_UNINSTALL`), run in its own process. |
| `upgrade-from-3.0.128.sh` | Installs v3.0.128 from git (`git archive`), seeds bookings and settings, swaps in this checkout, reactivates, verifies, then downgrades and verifies again. |
| `upgrade-seed-verify.php` | The seed and verify steps used by the upgrade script. |

## Last recorded results (2026-11-16, PHP 8.3.6, WordPress 7.1.4, MariaDB 10.11, WP-CLI 2.12.0)

- Single site: 85 passed, 0 failed, 2 gaps, 10 skipped.
- Multisite: 86 passed, 0 failed, 2 gaps, 8 skipped.
- Upgrade 3.0.128 (`22dc106`) to this checkout and back: 16 passed, 0 failed, 2 gaps. Both sides report version
  3.0.128, so the upgrade check asserts which source folder is loaded rather than the version string.

## Gaps observed (reported, not fixed here)

- **Reminder outbox table is never created.** `tz_booking_actions` is absent after activation; nothing in the plugin
  calls `TZFD_Booking_Action_Outbox::install()`. Enqueue refuses until an administrator triggers an install.
- **Opt-in uninstall keeps `tzfd_local_customers`.** The table is outside `TZFD_Tables::owned()`. Decide whether
  customer records should be erased when the owner opts in.
- **Activation without an administrator.** `TZFD_Slot_Holds::install()` and the call-lifecycle install require
  `manage_options`. Activating with no user (for example `wp plugin activate` without `--user`) leaves `tz_slot_holds`
  and `tz_calls` uncreated and does not report it.
- **dbDelta on MariaDB 10.11.** Installing the slot-hold table printed `Invalid default value for 'id'` from
  `ALTER TABLE ... SET DEFAULT ''`. The schema check still passed.

## Skipped, and why

- Oversell through the public form (A18), and customer cancel and reschedule end to end (B13): the full submission
  pipeline and the mail capture are not driven. The primitives (capacity lock, hold validation, tokens, compare-and-swap)
  are tested.
- Reminder dispatch and delivery (C11): sends through a transport; no live provider in this harness.
- Uninstall with opt-in (F4): covered by `uninstall-wipe-check.php`, which runs as a separate process.
- Bad nonce on a logged-in request (E6): covered over HTTP in `http-auth-tests.sh`.
- PHP 8.1, 8.2, 8.4 and other WordPress versions: only PHP 8.3.6 and WordPress 7.1.4 are provisioned.
- Refunds and deposits: not in this lane's runtime surface; no check exists.
- The upgrade is a folder swap plus deactivate and reactivate, not a ZIP install. A clean install from the packaged ZIP
  has not been tested.

## Weak checks, labelled as such

- `http-auth-tests.sh` includes an admin-ajax action name (`tzfd_workspace_customer_sites`). No `wp_ajax_` hook exists
  in the current source, so that check only shows core's refusal. It does not prove the plugin's own nonce handling.
