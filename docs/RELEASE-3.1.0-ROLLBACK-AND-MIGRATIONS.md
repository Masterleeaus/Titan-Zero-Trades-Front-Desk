# Release 3.1.0: rollback and migrations

Scope: Titan Zero Trades: Front Desk 3.1.0 (`titan-zero-front-desk.php`, `TZFD_VERSION`), upgraded from 3.0.128.
Issue #462 (open item: "Document rollback and migrations for this release"). This document records what the code does.
It is not evidence that a live upgrade or rollback has been run. The runtime checks named below were not executed for
this document.

## 1. Previous release (rollback target)

| Item | Value |
| --- | --- |
| Version | 3.0.128 |
| Release ZIP | `titan-zero-trades-front-desk-3.0.128-r16.zip`, committed at git commit `22dc106^` (the parent of `22dc106`) |
| Tree manifest | `docs/provenance/SHA256SUMS-v3.0.128.txt` (SHA-256 per file) |

Keep the 3.0.128 ZIP and its SHA-256 before the 3.1.0 install. Check the ZIP against the manifest before reinstalling it.

## 2. Migrations in this release

Versioned migration steps: none. `TZFD_Migrations::definitions()` (`includes/class-tzfd-migrations.php`) returns an
empty array, so `plan()` reports zero steps and `run()` completes without changing anything. The coordinator is
reserved for future steps, which need reviewed callbacks and individual backups.

Schema work happens on activation. `tzfd_activate()` (`titan-zero-front-desk.php`) runs these idempotent steps, with no
logged-in administrator required:

- `TZFD_Slot_Holds::create_schema()`: slot-hold table (dbDelta).
- `TZFD_Booking_Action_Outbox::create_schema()`: booking action outbox (dbDelta, then a column check).
- `TZFD_Call_Lifecycle::run_install()`, `TZFD_Business_Schema::run()` and `$tzfd_plugin->install( false )`: tables for
  those components.

Forward-only column changes, each guarded so it runs once:

| Change | Where | Guard | Reversible by rollback? |
| --- | --- | --- | --- |
| `company_id` column and key added to existing tables; rows with `company_id = 0` set to the site's default company | `TZFD_Business_Schema::ensure_company_column()` | Column missing | The column stays. Old code does not read it. The backfill is not undone. |
| Legacy site-wide unique key `addon_kind_ref` dropped from the addon records table | `TZFD_Addon_Records` | Both old and new keys present | Not automatically. Restoring the old key can fail if rows now share a reference across companies. |
| Column privacy consent added to the lead intake table | `TZFD_Lead_Intake` | Column missing | The column stays. Old code ignores it. |
| TEXT column added when missing (`add_field_verify`) | `classes/tzfd-base-class.inc.php` | Column missing | The column stays. |

Not a migration: the collation change on the bookings and forms tables (`ALTER TABLE ... CHANGE`, four column types)
runs only from the admin "fixarea" save in `tzfd-main-class.inc.php`. That handler checks a nonce
(`tz_fd_actions_admin`) and the `tz_fd_access` capability, and it runs only when an administrator submits a charset.
It does not run on upgrade, and a rollback does not reverse it.

`DROP TABLE` statements (legacy tables and the AI chat table) run only in `TZFD_Uninstaller`, on an explicit opt-in
uninstall. A rollback does not restore data removed by that path. Plan a backup before any uninstall.

## 3. Rollback procedure

1. Take a database backup and a file backup of the 3.1.0 plugin folder.
2. Deactivate 3.1.0 in wp-admin or with WP-CLI.
3. Replace the plugin folder with the 3.0.128 tree from the ZIP.
4. Activate 3.0.128. Its activation steps are idempotent and do not drop tables.
5. Check the header version (3.0.128) and that bookings are still listed.

What remains after rollback:

- Tables and columns added by 3.1.0 stay in place. 3.0.128 ignores them.
- Options introduced in 3.1.0 stay in place (for example the location registry under the `tzfd_locations_` prefix).
- Hooks: 3.1.0 renames hooks (issue #455) and still fires each old name after the new one. Listeners written against
  the new names will not run on 3.0.128.
- The removed-since-3.0.128 list in `titan-zero-trades-front-desk/changelog.txt` describes code that a rollback brings back.

## 4. Known limits (not verified here)

- Booking survival through the upgrade and the downgrade on a live site: `tests/runtime/upgrade-from-3.0.128.sh` (seeded
  bookings and AI settings), not run here. See `docs/PRODUCTION-READINESS-REMAINING-151.md`.
- The list in section 2 comes from a grep of `ALTER TABLE` and `DROP TABLE` statements in the plugin folder (seven
  statements in the upgrade path, plus the admin charset action and the uninstaller). It was not checked against a
  live database. Whether each table's columns match 3.0.128 after an upgrade is a runtime question.
- The `CREATE TABLE` statements passed through `dbDelta` change tables on every activation when their SQL changes. This
  document does not list each one.
- A clean install from the built ZIP is recorded in `docs/RUNTIME-READINESS.md`; it is not part of this rollback path.
