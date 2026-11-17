# WordPress.org readiness checklist (issues #3, #9, #44)

Plugin: Scheduling & Enquiries Manager (folder and slug `titan-zero-trades-front-desk`, version 3.0.128).
Recorded 2026-11-16 on branch `packaging-provenance-wporg`.

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
| `php -l` on every PHP file | Done | 219 files, 0 failures, PHP 8.3.6. Also run inside the build script |
| Compatibility with PHP 7.4 (the declared minimum) | Not run | Only PHP 8.3 is installed here |
| WordPress 6.6 minimum and tested versions | Not run | No WordPress install here. readme says "Tested up to 7.1" |
| WordPress Plugin Check | Not run | Plugin Check needs a WordPress site. Not installed, not run. No result is claimed |
| Gettext calls without a text domain | Done | grep found 0 single-argument `__()`, `_e()`, `esc_html__()` or `esc_attr__()` calls. This is a grep, not a parser |
| Plugin loads its translations | Partial | No `load_plugin_textdomain()` call. WordPress.org-hosted plugins load translations automatically since WordPress 4.6, so none is needed for the WordPress.org package. No `.pot` file is shipped. Not verified on a site |
| Hard-coded strings in the touched files | Done | The only changed PHP line is the header. The readme display name is text, not code |
| Hard-coded strings across all templates and admin markup | Open | Not audited |
| Accessibility (WCAG) of booking forms and admin screens | Open | Not audited in this lane. #9 requires it |
| Outbound HTTP call sites | Partial | Four call sites: missed-call SMS (`addons/messaging/missed-call.addon.php`), Twilio (`includes/class-tzfd-twilio-sms.php`), web import (`addons/core/onboarding.addon.php`), webhook (`includes/class-tzfd-webhooks.php`). Each is in the external-services list. Gating code not traced in this pass |
| Asset loads from external hosts on public pages | Partial | A grep of enqueue calls and src/href attributes found none. Dynamic URLs are not covered by grep. Not verified on a site |
| Privacy exporter and eraser registered | Done (registration only) | `includes/class-tzfd-privacy-exporter.php` lines 22 and 23 register the filters. Behaviour not run |
| Uninstaller | Not run | `uninstall.php` exists. Not executed |
| Multisite | Not run | Not tested |
| Runtime tests (`tests/runtime/`) | Not run | These need a disposable WordPress site (`wp eval-file`). `wp` is not installed here |
| CI that runs PHP lint and runtime tests on PRs | Open | `.github/workflows/` contains only issue-intake and issue-closure workflows. No PHP or WordPress test workflow exists |

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

- [ ] Plugin Check run on a WordPress site, result attached
- [ ] PHP 7.4 and PHP 8.x lint and runtime tests
- [ ] WordPress minimum and latest tested
- [ ] Clean install, update from the previous ZIP, uninstall, multisite
- [ ] Booking create, cancel and reschedule end to end
- [ ] Service outage (SMS gateway, webhook, import) leaves normal booking working
- [ ] Version bump, changelog and upgrade notice written for the release
- [ ] Rollback: keep the previous ZIP and its `.sha256`; restore from it if the update fails

## Summary

Done: header, readme structure, reproducible build, source manifest, provenance corrections, PHP lint, text-domain check, external-service inventory by inspection.

Not done: Plugin Check, runtime and multisite tests, PHP 7.4 check, accessibility audit, CI, v3.0.128 and Pass 03 comparison, three-way merge. No WordPress.org approval has been sought or claimed.
