# WordPress.org readiness checklist (issues #3, #9, #44)

Plugin: Scheduling & Enquiries Manager (folder and slug `titan-zero-trades-front-desk`, version 3.0.128).
Recorded 2026-11-16. Evidence refreshed 2026-11-17 on branch `issue-3` (issue #3; code fixes in commit e93a634).

This checklist records what was checked, what was not, and why. **It does not claim WordPress.org review or approval.** No submission has been made. Status values: **Done** (checked here with evidence), **Partial**, **Not run** (needs a WordPress environment or a human), **Open** (known gap).

## 1. Package and header

| Item | Status | Evidence |
|---|---|---|
| Plugin header Name is the official display name | Done | `titan-zero-front-desk.php` line 3: "Scheduling & Enquiries Manager" |
| Text Domain is `titan-zero-trades-front-desk` and matches the folder | Done | Header line 11 |
| Requires at least 6.6, Requires PHP 7.4, License GPL-2.0-only | Done | Header lines 6, 7 and 9 |
| Stable tag equals plugin Version | Done | Both 3.0.128 (readme "Stable tag", header "Version") |
| readme.txt title matches the display name | Done | `readme.txt` line 1 |
| Contributors, Tags (5 or fewer), Tested up to | Done | `readme.txt` header lines |
| Standard readme sections (Description, Installation, FAQ, Changelog, Upgrade Notice, License) | Done | Present in readme.txt |
| Screenshots section | Open | Not added. No screenshot set has been prepared; `images/tz_fd_form.gif` is not a screenshot |
| Non-standard sections ("Optional upgrades", "Template metadata", "Smart admin wizards") | Open | Present; need a reviewer decision on keeping them |
| Installation step 1 says "This is a new plugin, not an upgrade" | Open | Conflicts with the clean-update acceptance criterion. Decision for the lead |
| Version bump and changelog entry for the rename | Open | Deferred to the release lane (see PROVENANCE-RECORD.md, Decisions) |
| Build produces a reproducible ZIP that excludes dev files | Done | `scripts/build-package.sh`; two builds gave identical SHA-256 on 2026-11-16 and again on 2026-11-17 (`b2ca0c4b62246c7830f0094092125e3c4dead5e115db653db51962564ef63611`, 756 files) |
| Source manifest of the shipped tree | Done | `docs/provenance/SHA256SUMS.txt`, 756 files, regenerated 2026-11-17 with `scripts/source-manifest.sh titan-zero-trades-front-desk` (the 707-file version predated files merged from main) |
| Licence and third-party notices shipped | Done | `LICENSE.txt`, `THIRD-PARTY-NOTICES.txt`, `UPSTREAM.txt` in the plugin folder |

## 2. Code checks

| Item | Status | Evidence |
|---|---|---|
| `php -l` on every PHP file | Done | 297 files in the repository, 0 failures, PHP 8.3.6 (2026-11-17). Also run inside the build script |
| Compatibility with PHP 7.4 (the declared minimum) | Not run | Only PHP 8.3 is installed here |
| WordPress 6.6 minimum and tested versions | Partial | Only WordPress 7.1.4 was run (the test site). The 6.6 minimum is not run. readme says "Tested up to 7.1" |
| WordPress Plugin Check | Partial | Run 2026-11-17 on WordPress 7.1.4 with Plugin Check 2.2.0 (`wp plugin check`). Before the fixes in e93a634: 30 ERROR, 705 findings. After: 0 ERROR, 672 WARNING. See section 6 |
| Gettext calls without a text domain | Done | grep found 0 single-argument `__()`, `_e()`, `esc_html__()` or `esc_attr__()` calls. This is a grep, not a parser |
| Plugin loads its translations | Partial | No `load_plugin_textdomain()` call. WordPress.org-hosted plugins load translations automatically since WordPress 4.6, so none is needed for the WordPress.org package. No `.pot` file is shipped. Not verified on a site |
| Hard-coded strings in the touched files | Done | The only changed PHP line is the header. The readme display name is text, not code |
| Hard-coded strings across all templates and admin markup | Open | Not audited |
| Accessibility (WCAG) of booking forms and admin screens | Open | Not audited in this lane. #9 requires it |
| Outbound HTTP call sites | Partial | Four call sites: missed-call SMS (`addons/messaging/missed-call.addon.php`), Twilio (`includes/class-tzfd-twilio-sms.php`), web import (`addons/core/onboarding.addon.php`), webhook (`includes/class-tzfd-webhooks.php`). Each is in the external-services list. Gating code not traced in this pass |
| Asset loads from external hosts on public pages | Partial | A grep of enqueue calls and src/href attributes found none. Dynamic URLs are not covered by grep. Not verified on a site |
| Privacy exporter and eraser registered | Done | `includes/class-tzfd-privacy-exporter.php` lines 22 and 23 register the filters. Export and erase run in the runtime suite (PASS on export, erase and the other-customer check) |
| Uninstaller | Done (opt-in path) | `tests/runtime/uninstall-wipe-check.php` run 2026-11-17: bookings table, all 13 owned tables and plugin settings removed. `tzfd_local_customers` is kept on opt-in uninstall (owner decision, section 6) |
| Multisite | Not run | Not tested. No multisite test site exists |
| Runtime tests (`tests/runtime/`) | Done | 2026-11-17, WordPress 7.1.4, PHP 8.3.6: runtime 26 passed, 0 failed; HTTP auth 8 passed, 0 failed, 1 skipped. Includes export and erase (privacy) and signed-request checks |
| CI that runs PHP lint and runtime tests on PRs | Partial | `.github/workflows/tests.yml` on main runs `php -l`, the standalone harnesses, runtime and HTTP tests. Locally the 15 standalone harnesses in its glob exit 0. PR CI result still to be linked |

## 3. External-service disclosure (#44)

The readme "External services" section lists five services: an SMS gateway and phone system, a web page for service import, a webhook, WhatsApp and SMS links (not a connection), and Twilio (off by default).

| Item | Status | Evidence |
|---|---|---|
| Every outbound call in code is disclosed | Done (matching by inspection) | Four call sites, each matching a listed service. The WhatsApp and SMS buttons are links only |
| Disclosure states that nothing is sent to the plugin author by default | Partial | Stated in readme. Not verified by tracing every path |
| No hosted AI in the Free build | Done | `titan-zero-front-desk.php`: `tz_fd_ai_assistant` shortcode returns empty. readme says "This version does not use AI" |
| Hosted Assistant and Agent Team are optional and separate | Done (documentation) | readme "Optional upgrades" section. No hosted client code is shipped in this lane |
| Consent controls for external services | Not run | Not traced |
| Safe endpoints (HTTPS, safe remote requests) | Partial | Webhook and import use `wp_safe_remote_*`. SMS gateway uses `wp_safe_remote_post`. Twilio uses `wp_remote_post` with the Twilio URL |

## 4. Provenance and licence (#6)

| Item | Status | Evidence |
|---|---|---|
| Upstream package hash re-verified | Done | `754d4250...74bc4` matches UPSTREAM.txt, 2026-11-16 |
| Path-level comparison against upstream 1.5.98 | Done | `scripts/compare-upstream.sh`. See PROVENANCE-RECORD.md |
| Provenance statements corrected where wrong | Done | Seven corrections listed in PROVENANCE-RECORD.md |
| Comparison against the v3.0.128 ZIP | Open | No archive present |
| Comparison against the Pass 03 candidate | Open | Not provided |
| Three-way merge against current main | Open | Not run |
| Credits to Appointment Hour Booking and CodePeople retained | Done | readme Credits, UPSTREAM.txt, THIRD-PARTY-NOTICES.txt |

## 5. Release and rollback checklist (not run)

- [ ] Plugin Check run on a WordPress site, result attached (run on 2026-11-17: 0 ERROR; 672 WARNING, of which 633 are the prefix-naming owner decision)
- [ ] PHP 7.4 and PHP 8.x lint and runtime tests
- [ ] WordPress minimum and latest tested
- [ ] Clean install, update from the previous ZIP, uninstall, multisite (2026-11-17: install-and-replace upgrade and downgrade from git 3.0.128 passed, 16 checks, 2 known gaps; uninstall passed; no previous ZIP was supplied, so the ZIP upgrade and multisite are not run)
- [ ] Booking create, cancel and reschedule end to end
- [ ] Service outage (SMS gateway, webhook, import) leaves normal booking working
- [ ] Version bump, changelog and upgrade notice written for the release
- [ ] Rollback: keep the previous ZIP and its `.sha256`; restore from it if the update fails

## Summary

Done: header, readme structure, reproducible build, source manifest, provenance corrections, PHP lint, text-domain check, external-service inventory by inspection.

Not done: Plugin Check, runtime and multisite tests, PHP 7.4 check, accessibility audit, CI, v3.0.128 and Pass 03 comparison, three-way merge. No WordPress.org approval has been sought or claimed.

## 6. Plugin Check warnings (2026-11-17, commit e93a634)

Total: 672 WARNING, 0 ERROR. Counted from `wp plugin check titan-zero-trades-front-desk --format=csv`.

- **633 prefix-naming warnings** (`WordPress.NamingConventions.PrefixAllGlobals.*`: 552 global variables, 52 hook names, 22 functions, 7 dynamic hook names). **Owner decision**: rename the globals and hooks to the `tzfd_` prefix, or accept them with a recorded reason. Renaming touches many files and needs a compatibility plan for existing hook users. Not changed in this pass.
- **39 other warnings**, reviewed by hand:
  - 16 `WordPress.DB.DirectDatabaseQuery.DirectQuery` and 12 `NoCaching`: direct queries on the plugin's own tables. Caching is not applied. Not changed.
  - 7 `WordPress.Security.NonceVerification.Missing` in `class-tzfd-legacy-ai-data.php` (handle_delete, handle_restore and export): **false positive**. Each handler calls `self::guard()`, which runs `current_user_can( manage_options )` and `check_admin_referer()` first.
  - 1 `ValidatedSanitizedInput` on `$_GET` in `class-tzfd-ai-widget.php`: read-only public question, already annotated and sanitised with `sanitize_text_field`.
  - 2 `ValidatedSanitizedInput` in `class-tzfd-legacy-ai-data.php` (`$_FILES`): the upload is checked for error code, `is_uploaded_file` and size before use.
  - 1 `ReplacementsWrongNumber` and 1 `InterpolatedNotPrepared` in `class-tzfd-enquiry-triage.php` (`map_for_company`): **false positive**. The placeholder list is built from the number of ids; the runtime suite covers this path.

## 7. Acceptance items not verified in this pass (issue #3)

- Booking create is covered by the runtime suite. Cancel and reschedule end to end are not verified.
- Isolated external-service opt-in and the outage check (SMS gateway, webhook, import): not verified on a site.
- No fatal error when the AI service is offline: not verified on a site.
- Clean update from the previous ZIP (not the git base): not run. No previous ZIP was supplied.
- PHP 7.4 (declared minimum) and WordPress 6.6: not run.
- Multisite: not run.
- Accessibility (section 2): not audited.
- Owner decisions: the 633 prefix-naming warnings; whether `tzfd_local_customers` is erased on opt-in uninstall; whether the Installation step "This is a new plugin, not an upgrade" stays (it conflicts with the clean-update criterion); the release sign-off.

## Summary (updated 2026-11-17)

Done: header, readme structure, reproducible build (identical hashes on two builds), refreshed source manifest, provenance corrections, PHP lint on 297 files, Plugin Check with 0 errors (warnings listed in section 6), runtime and HTTP suites, opt-in uninstall, upgrade and downgrade from git 3.0.128 (same version), external-service inventory by inspection, the privacy export and erase checks.

Not done: PHP 7.4 and WordPress 6.6 runs, multisite, accessibility audit, PR CI link, previous-ZIP upgrade, booking cancel and reschedule end to end, external-service isolation and AI-offline checks, and the owner decisions above. No WordPress.org approval has been sought or claimed; nothing has been submitted.
