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
| `http-auth-tests.sh` | Unauthenticated refusals, plus logged-in admin and subscriber checks for REST nonce and capability. A route that is not registered is reported as FAIL, since a 404 there exercises no authorization. Set `SECRET` to the stored missed-call inbound secret to also check that a valid signature is accepted once and its replay refused. |
| `uninstall-wipe-check.php` | Destructive: opt-in uninstall (`TZ_DELETE_DATA_ON_UNINSTALL`), run in its own process. |
| `upgrade-from-3.0.128.sh` | Installs the base release (default: Front Desk 2.0.1, the release ZIP committed at `4e0c3b3`, read with `git show`), swaps in this checkout, reactivates, checks the header version and the 3.x tables, then downgrades and checks again. Seeds and verifies bookings only when the base has the 3.x booking schema. |
| `upgrade-seed-verify.php` | The seed and verify steps used by the upgrade script. |

## Results on main with the #370 harness, 2026-11-23 (issue #370; PHP 8.3.6, MariaDB 10.11.14, WP-CLI 2.12.0)

- Single site, WordPress 7.1.4: 377 passed, 0 failed, 0 gap(s), 9 skipped.
- Single site, WordPress 6.6 (`TZFD_RT_WP_VERSION=6.6`, the plugin's minimum): 377 passed, 0 failed, 0 gap(s), 9 skipped.
- Multisite, WordPress 7.1.4: 378 passed, 0 failed, 0 gap(s), 7 skipped. The `ai-answer-box: page_id sent as a hidden
  field` check passes here on main, so the multisite failure recorded on 2026-11-16 does not reproduce on this checkout.
- Upgrade from the v3.0.128 release ZIP, single site: 31 passed, 0 failed, 0 gap(s).
- Upgrade from the v3.0.128 release ZIP, network (`UPGRADE_MULTISITE=1`): 31 passed, 0 failed, 0 gap(s).
- dbDelta on MariaDB 10.11.14: the slot-hold table installs, and a second run issues no `ALTER` (`slot-holds` checks, 5 of 5).
  The `Invalid default value for 'id'` message did not appear.
- Not available in this run: PHP 8.1, 8.2 and 8.4. The static CLI builds found for these versions have no `mysqli`
  extension, and no Docker daemon was available, so no WordPress runtime was provisioned on them.

## Last recorded results (2026-11-16, PHP 8.3.6, WordPress 7.1.4, MariaDB 10.11, WP-CLI 2.12.0)

- Single site: 85 passed, 0 failed, 2 gaps, 10 skipped.
- Multisite: 86 passed, 0 failed, 2 gaps, 8 skipped.
- Upgrade from the real v3.0.128 release ZIP (`titan-zero-trades-front-desk-3.0.128-r16.zip`, read from `22dc106^`) to this
  checkout and back: 31 passed, 0 failed, 0 gap(s). Run with `SAME_VERSION_OK=1`, because both sides report 3.0.128, so the
  check asserts which source folder is loaded rather than the version string. Bookings, AI settings and saved chat rows
  survived both directions.
- Multisite (same date): 346 passed, 1 failed (`ai-answer-box: page_id sent as a hidden field`), 7 skipped. The failure
  is in the AI answer box form and reproduces on main; it is tracked as open work on #252, not fixed here.
- Clean install of the built free ZIP (`scripts/build-package.sh`, 782 files): active at 3.0.128 with 15 `tz_` tables.

## Gaps observed (reported, not fixed here)

- **dbDelta on MariaDB 10.11.** Installing the slot-hold table printed `Invalid default value for 'id'` from
  `ALTER TABLE ... SET DEFAULT ''`. The schema check still passed. Not reproduced on 2026-11-23 (see above); the
  `SET DEFAULT` came from the `DEFAULT ''` on the id column, which #186 removed.

## Skipped, and why

- Oversell through the public form (A18), and customer cancel and reschedule end to end (B13): the full submission
  pipeline and the mail capture are not driven. The primitives (capacity lock, hold validation, tokens, compare-and-swap)
  are tested.
- Reminder dispatch (C11) is run with a local transport stub (filter `tzfd_booking_deliver_v1`); no provider is called.
- Uninstall with opt-in (F4): covered by `uninstall-wipe-check.php`, which runs as a separate process.
- Bad nonce on a logged-in request (E6): covered over HTTP in `http-auth-tests.sh`.
- PHP 8.1, 8.2, 8.4 and other WordPress versions: only PHP 8.3.6 and WordPress 7.1.4 are provisioned.
- Refunds and deposits: not in this lane's runtime surface; no check exists.
- The upgrade is a folder swap plus deactivate and reactivate, not a ZIP install. A clean install from the built ZIP was
  checked once by hand (see results above) but is not in a script yet.

## Weak checks, labelled as such

- `http-auth-tests.sh` includes an admin-ajax action name (`tzfd_workspace_customer_sites`). No `wp_ajax_` hook exists
  in the current source, so that check only shows core's refusal. It does not prove the plugin's own nonce handling.
