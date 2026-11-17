# WordPress.org readiness checklist (issues #3, #9, #44)

Plugin: Scheduling & Enquiries Manager (folder and slug `titan-zero-trades-front-desk`, version 3.0.128).
Recorded 2026-11-16 on branch `packaging-provenance-wporg`. Plugin Check style review (section 6) recorded 2026-11-17 on branch `readiness/plugincheck`.

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
| Build produces a reproducible ZIP that excludes dev files | Done | `scripts/build-package.sh`; two builds gave identical SHA-256 on 2026-11-16 |
| Source manifest of the shipped tree | Done | `docs/provenance/SHA256SUMS.txt`, 707 files |
| Licence and third-party notices shipped | Done | `LICENSE.txt`, `THIRD-PARTY-NOTICES.txt`, `UPSTREAM.txt` in the plugin folder |

## 2. Code checks

| Item | Status | Evidence |
|---|---|---|
| `php -l` on every PHP file | Done | 266 files in `titan-zero-trades-front-desk/`, 0 failures, PHP 8.3.6 (re-run 2026-11-17; the earlier count of 219 was for an older tree). Also run inside the build script |
| Compatibility with PHP 7.4 (the declared minimum) | Not run | Only PHP 8.3 is installed here |
| WordPress 6.6 minimum and tested versions | Not run | No WordPress install here. readme says "Tested up to 7.1" |
| WordPress Plugin Check | Not run | Plugin Check needs a WordPress site. Not installed, not run. No result is claimed |
| Gettext calls without a text domain | Done | grep found 0 single-argument `__()`, `_e()`, `esc_html__()` or `esc_attr__()` calls. This is a grep, not a parser |
| Plugin loads its translations | Partial | No `load_plugin_textdomain()` call. WordPress.org-hosted plugins load translations automatically since WordPress 4.6, so none is needed for the WordPress.org package. No `.pot` file is shipped. Not verified on a site |
| Hard-coded strings in the touched files | Done | The only changed PHP line is the header. The readme display name is text, not code |
| Hard-coded strings across all templates and admin markup | Open | Not audited |
| Accessibility (WCAG) of booking forms and admin screens | Open | Not audited in this lane. #9 requires it |
| Outbound HTTP call sites | Partial | Four live call sites match the readme list: missed-call SMS (`addons/messaging/missed-call.addon.php`), Twilio (`includes/class-tzfd-twilio-sms.php`), web import (`addons/core/onboarding.addon.php`), webhook (`includes/class-tzfd-webhooks.php`). A fifth call site, the AI connector transport (`includes/class-tzfd-ai-connector.php`, `wp_remote_post`), is not in the readme and has no caller in the shipped tree; see section 6. Gating code not traced in this pass |
| Asset loads from external hosts on public pages | Done (grep) | Enqueue calls and CSS `url()`/`@import` contain no external host in plugin code. Dynamic URLs are not covered by grep. Not verified on a site |
| Privacy exporter and eraser registered | Done (registration only) | `includes/class-tzfd-privacy-exporter.php` lines 22 and 23 register the filters. Behaviour not run |
| Uninstaller | Not run | `uninstall.php` exists. Not executed |
| Multisite | Not run | Not tested |
| Runtime tests (`tests/runtime/`) | Not run | These need a disposable WordPress site (`wp eval-file`). `wp` is not installed here |
| CI that runs PHP lint and runtime tests on PRs | Open | `.github/workflows/` contains only issue-intake and issue-closure workflows. No PHP or WordPress test workflow exists |

## 3. External-service disclosure (#44)

The readme "External services" section lists five services: an SMS gateway and phone system, a web page for service import, a webhook, WhatsApp and SMS links (not a connection), and Twilio (off by default).

| Item | Status | Evidence |
|---|---|---|
| Every outbound call in code is disclosed | Partial | Four live call sites match a listed service. The WhatsApp and SMS buttons are links only. One dormant call site (AI connector transport) is not disclosed; see section 6, decision 3 |
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

- [ ] Plugin Check run on a WordPress site, result attached
- [ ] PHP 7.4 and PHP 8.x lint and runtime tests
- [ ] WordPress minimum and latest tested
- [ ] Clean install, update from the previous ZIP, uninstall, multisite
- [ ] Booking create, cancel and reschedule end to end
- [ ] Service outage (SMS gateway, webhook, import) leaves normal booking working
- [ ] Version bump, changelog and upgrade notice written for the release
- [ ] Rollback: keep the previous ZIP and its `.sha256`; restore from it if the update fails

## 6. Plugin Check style review (2026-11-17, branch `readiness/plugincheck`)

Checked by grep and by reading the files named. No WordPress site was available, so Plugin Check itself was **not run** and no Plugin Check result is claimed. Version 3.0.128 is unchanged.

### 6a. readme.txt against the WordPress.org format

| Item | Status | Evidence |
|---|---|---|
| Contributors | Done | `Contributors: titan0` |
| Tags (5 or fewer) | Done | 5 tags: booking, appointments, calendar, scheduling, booking form |
| Requires at least | Done | 6.6 in readme and in the main-file header |
| Requires PHP | Done | 7.4 in readme and in the main-file header |
| Tested up to | Partial | 7.1 in readme. Not run on any WordPress version in this lane |
| Stable tag equals header Version | Done | 3.0.128 in both |
| License | Open (decision 1) | readme and header say `GPL-2.0-only`. The lane standard is "GPLv2 or later". `LICENSE.txt` is the GPLv2 text. Not changed |
| License URI | Done | `https://www.gnu.org/licenses/gpl-2.0.html` |
| Short description, 150 characters or fewer | Done | 117 characters |
| Required readme sections | Done | Description, Installation, FAQ, Changelog, External services, Privacy, License present |
| Non-standard sections | Open | "Optional upgrades", "Source code", "Credits" and "Support" are present. Upgrade wording in "Optional upgrades" needs a reviewer decision |
| Installation wording "not an upgrade" | Open (decision 4) | Already listed in section 1 |

### 6b. Main file header and text domain

| Item | Status | Evidence |
|---|---|---|
| Plugin Name, Version, Requires at least, Requires PHP, Author, License, License URI, Text Domain | Done | `titan-zero-front-desk.php` lines 3 to 11 |
| Text Domain matches the folder | Done | `titan-zero-trades-front-desk` |
| Single-argument gettext calls | Done | grep found 0 |

### 6c. Direct-access guards

| Item | Status | Evidence |
|---|---|---|
| Every PHP file has a guard | Done | Checked all 266 files. Each has `defined( 'ABSPATH' )` with `exit` within its first 40 lines, except `uninstall.php`, which checks `WP_UNINSTALL_PLUGIN` on line 17 |
| Guard runs before any require in the main file | Done | ABSPATH guard on line 14, first `require_once` on line 19 |

### 6d. Banned or discouraged functions

| Item | Status | Evidence |
|---|---|---|
| `extract()` | Open | Five calls: `ui-pack/ui/tz-app-core.php:196` (phpcs-ignored), `addons/bookings/dashboard-box.addon.php:231`, `tzfd-main-class.inc.php:458`, `:702`, `:3534`. Reviewers flag `WordPress.PHP.DontExtract` |
| `base64_decode()` | Open | `includes/class-tzfd-booking-validation-recovery.php:50` and `includes/class-tzfd-addon-support.php:591`. Used to decode stored tokens and secrets, not code. Still a discouraged function |
| `error_log()` | Open (low) | Four calls in `includes/class-tzfd-post-commit-dispatch.php:14` and `tzfd-main-class.inc.php:3992, 4016, 4078` |
| `tempnam()` and `file_put_contents()` | Open | Scheduled CSV reports, `tzfd-main-class.inc.php:4030-4032` and `:4098-4100`. Uses a private temp directory; review with the security lane |
| `file_get_contents()` | Partial | Four calls, all on local files, each with a phpcs ignore comment: `includes/class-tzfd-builtin-verticals.php:128` (ignore on the preceding line), `includes/class-tzfd-legacy-ai-data.php:412` (uploaded temp file, size-checked), `includes/class-tzfd-ai-services.php:55` and `tzfd-main-class.inc.php:2887` |
| `eval`, `exec`, `shell_exec`, `system`, `curl_*`, `ini_set`, `set_time_limit`, `create_function`, `unlink` | Done | No calls found in plugin code |
| Superglobal access (`$_GET`, `$_POST`, `$_REQUEST`, `$_FILES`, `$_COOKIE`, `$_SERVER`) | Not run | About 376 references. Sanitising and nonce checks were not audited in this lane. Route to the security lane |

### 6e. Enqueue practice and assets

| Item | Status | Evidence |
|---|---|---|
| Scripts and styles use `wp_enqueue_*` / `wp_register_*` | Done | Found across the admin and public files |
| Inline `<script>` or `<style>` tags in PHP output | Done | None. The only match is a comment in `addons/bookings/calendarless-interface.addon.php:340` |
| Hard-coded `<script src>` or `<link>` | Done | None found |
| Asset versions | Partial | Most calls pass `'1.0.0'` or `'2.0.1'`. Some use `filemtime()`. Versions are hard-coded, not tied to the release |
| External asset hosts on public pages | Done (grep) | No external host in enqueue calls or CSS |

### 6f. Outbound calls and external-service disclosure

| Item | Status | Evidence |
|---|---|---|
| Live call sites match the readme | Partial | Missed-call SMS (`wp_safe_remote_post`), Twilio (`wp_remote_post` to `api.twilio.com`, only when `TZFD_TWILIO_SMS_ENABLED` is true), web import (`wp_safe_remote_get`), webhook (`wp_safe_remote_post`). Gating of the SMS and webhook paths was not traced |
| AI connector transport | Open (decision 3) | `includes/class-tzfd-ai-connector.php:285` calls `wp_remote_post()` (not the safe variant) when `TZFD_AI_Connector::request()` runs. No file in the shipped tree calls `request()` or `configure()`. The readme says the version "does not contact any AI provider" |
| Hosted AI links in code | Done | `includes/class-tzfd-upgrades.php` holds two `titanzero.io` product URLs. The readme describes them under "Optional upgrades" |
| `mailto:` support links | Done | `support@titanzero.io` only |

### 6g. PHP version floor

| Item | Status | Evidence |
|---|---|---|
| PHP 8.0+ syntax in plugin code | Partial | Heuristic grep for `match()`, nullsafe `?->`, `readonly`, `enum`, union types and constructor promotion found none. Arrow functions (`static fn`) are used, which PHP 7.4 accepts. No PHP 7.4 parser was run |

### 6h. Items not changed in this lane

- No code file was edited. Every item above is either passing or needs a decision or another lane's work.
- No readme or header line was edited. The only readme item that fails the WordPress.org format is the licence wording, which needs a decision.

### 6i. Decisions for review

1. **Licence wording.** Keep `GPL-2.0-only` (as now) or change the readme and header to `GPLv2 or later`. This is a licence decision for the owner. Not changed here.
2. **Version.** 3.0.128 is not bumped. Any bump belongs to the release lane.
3. **Dormant AI connector.** Either remove `includes/class-tzfd-ai-connector.php` before submission, or add it to "External services" and state that it is not called. Not changed here.
4. **Installation wording.** "This is a new plugin, not an upgrade" is kept. Decide whether it stays before submission (see section 1).
5. **Banned-function fixes** (`extract`, `base64_decode`, `error_log`) are not edited here. They change code, so they belong to a code lane with tests.

## Summary

Done: header, readme structure, reproducible build, source manifest, provenance corrections, PHP lint, text-domain check, external-service inventory by inspection.

Plugin Check style review (section 6, 2026-11-17): readme format passes except the licence wording (decision 1). Direct-access guards pass on all 266 files. Open items: `extract()`, `base64_decode()`, `error_log()` and temp-file writes, a dormant AI connector call site missing from the readme, and the installation wording.

Not done: Plugin Check, runtime and multisite tests, PHP 7.4 check, accessibility audit, CI, v3.0.128 and Pass 03 comparison, three-way merge. No WordPress.org approval has been sought or claimed.
