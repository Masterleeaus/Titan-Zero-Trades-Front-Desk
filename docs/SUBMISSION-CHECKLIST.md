# WordPress.org submission checklist: Scheduling & Enquiries Manager

Plugin folder `titan-zero-trades-front-desk/`, version 3.0.128 (header and Stable tag). Checked against `origin/main` at 40928a2 on 2026-11-17.

This checklist does not claim WordPress.org review or approval. Nothing has been submitted. Status values: **Done** (checked, evidence given), **Partial**, **Open** (not done or not run), **Owner decision** (needs a human answer before work can continue). Paths are repo-relative.

## Owner decisions (not decided here)

1. **Clean update or new plugin?** The readme says "This is a new plugin, not an upgrade" (`titan-zero-trades-front-desk/readme.txt` line 90). Issue #3 requires a verified clean update from the previous ZIP. Both cannot stand. Choose one, then align the readme, the FAQ and the test plan.
2. **Encrypt the connector signing secret?** `titan-zero-trades-front-desk/includes/class-tzfd-ai-connector.php` stores the secret with `update_option( SECRET_OPTION, $secret, false )` (line 185). It is not encrypted at rest. PR #65 merged this design, and its "Decisions for review" item 7 records it. Decide whether to encrypt it before submission, and whether a fix is needed before or after the next release.
3. **Connector disclosure.** The connector (default off, consent required) is on main and is loaded by `titan-zero-front-desk.php` line 62. The readme says "This version does not use an AI service" (line 195) and "does not contact any AI provider" (line 147). It does not list the connector under "External services". Decide whether to disclose it, remove it from this build, or gate it off.
4. **Version bump and changelog.** Version and Stable tag are both 3.0.128. A bump, a changelog entry and an upgrade notice are needed for the release. Not done here, per the rules of this lane.
5. **Which Chart.js and notices count to publish.** `THIRD-PARTY-NOTICES.txt` says 92 identical and 52 changed (lines 294-295). This lane measured 91 and 42 (see the Notices section). Decide which count is correct, then correct the notices file.
6. **Non-standard readme sections.** "Optional upgrades", "Template metadata" and "Smart admin wizards" are present. Decide whether to keep them.
7. **Order and go/no-go.** Decide the order in which the 19 open PRs merge, and whether to submit at all. Submission is never automated.
8. **Security audit gap.** No written security audit exists in the repo or on main. Decide whether issue #8 and the open PRs stand in for it, or whether an audit is commissioned.

## 1. Open PRs that must merge first

Source: `gh api repos/Masterleeaus/Titan-Zero-Trades-Front-Desk/pulls?state=open`, 2026-11-17. Nothing was merged or closed in this lane.

| Status | Item | Evidence |
|---|---|---|
| Open | 21 PRs are open: 9 are not drafts (#52, #88 to #95) and 12 are drafts | REST pull list above |
| Done (merged) | #65 signed AI connector | `gh api .../pulls/65` shows merged=true |
| Done (merged) | #18 and #86 | Already on main (#18 merge commit at 40928a2, #86 runtime harness) |
| Open | #87 is not in the open list and is not touched by this lane | REST pull list |

Open PRs, in the order the list returned them:

| PR | Draft | Title |
|---|---|---|
| #95 | no | feat(free-ai): deterministic guided booking core (#24) |
| #94 | no | docs(provenance): correct bundled Chart.js version (refs #3) |
| #93 | no | feat(legacy-ai): admin-only audit, export and confirmed deletion (#17) |
| #92 | no | Free edition: eliminate AI execution paths (#2) |
| #91 | no | Missed-call and callback planner checks (#30, partial) |
| #90 | no | P0 PLUGIN: final free plugin packaging (#9): remove Pro upgrade link |
| #89 | no | Human handoff and escalation core (#27) |
| #88 | no | feat(enquiry): deterministic spam and duplicate signals (#34) |
| #82 | yes | P0 FREE AI 09: Deterministic Enquiry Triage (#28) |
| #79 | yes | Lead urgency detection (#29, rule-only) |
| #77 | yes | P0 FREE AI 03: Business Knowledge Assistant (#22) |
| #76 | yes | feat(ai): Free AI 01 Answer Box (#20) |
| #74 | yes | FAQ retrieval: tenant-scoped approved index, keyword-first ranking (#21) |
| #73 | yes | test(runtime): partial #7 runtime checks, fail unregistered routes |
| #67 | yes | Smart Service Finder deterministic core (#23) |
| #66 | yes | feat(enquiries): quote request classification draft (#32) |
| #63 | yes | P0: versioned one-plugin service contract and tool permissions (#16) |
| #60 | yes | feat(free): cancel/reschedule assistant decisions (#25) |
| #58 | yes | Issue #6: release tooling, manifest and preservation check (draft) |
| #57 | yes | test(security): adversarial tests for inbound webhook verifiers (#8, partial) |
| #52 | no | P0 PLUGIN: make missed-call webhook replay guard atomic |

## 2. Runtime tests

| Item | Status | Evidence |
|---|---|---|
| Runtime harness exists on main | Done (exists) | `tests/runtime/README.md`, `tests/runtime/run-wp-runtime.sh`, `tests/runtime/wp-runtime-tests.php` |
| Runtime tests run against a real WordPress site | Open | Not run in this lane. `wp` and a WordPress site are not available here. The README says the run needs MySQL/MariaDB and WP-CLI |
| Runtime tests passing on a PR or main | Open | Not verified. `.github/workflows/tests.yml` runs php lint and standalone unit harnesses. It does not run the WordPress runtime suite. The last 5 "Tests" runs on main succeeded (2026-11-16) |
| Clean install, update from previous ZIP, uninstall, multisite | Open | `tests/runtime/upgrade-from-3.0.128.sh` exists. Not run. Readiness doc section 5 |
| Booking create, cancel and reschedule end to end | Open | Covered by `wp-runtime-tests.php` per the README. Not run |
| Outage of SMS gateway, webhook or import leaves booking working | Open | Not run |

## 3. Security findings from the audit

| Item | Status | Evidence |
|---|---|---|
| A written security audit with findings | Open | No audit document was found in the repo, the readiness doc or the migration audit. `docs/migration/FORMER-PRO-MIGRATION-AUDIT.md` covers migration, not security findings. Owner decision 8 |
| Webhook replay guard atomic | Open | PR #52 open. Not reviewed here |
| Adversarial tests for inbound webhook verifiers | Open (partial) | PR #57 is a draft, labelled partial |
| Booking REST and AJAX authorization and nonces | Open | Issue #8 is open. Not verified here |
| Connector secret stored unencrypted | Owner decision | Owner decision 2 |
| Connector secret never returned by status, results or usage | Done (by inspection) | `class-tzfd-ai-connector.php` line 148 comment; PR #65 body, decision 7 |
| Connector is off by default (consent gate) | Done (by inspection) | `class-tzfd-ai-connector.php` line 85 returns `consent_required` |
| HTTPS only for the connector endpoint | Done (by inspection) | PR #65 body, decision 8 |

## 4. Plugin Check

| Item | Status | Evidence |
|---|---|---|
| Plugin Check run on a WordPress site | Open | `docs/WORDPRESS-ORG-READINESS.md` section 2 says "Not run" |
| Plugin Check result attached | Open | None exists |
| WordPress Coding Standards run | Open | Not run |

## 5. Readme

| Item | Status | Evidence |
|---|---|---|
| Name matches display name | Done | `readme.txt` line 1 |
| Contributors, Tags, Requires at least 6.6, Requires PHP 7.4, Tested up to 7.1 | Done (values present) | `readme.txt` lines 3, 4, 7, 9, 11 |
| Stable tag matches Version | Done | Both 3.0.128 |
| Installation wording "new plugin, not an upgrade" | Owner decision | Owner decision 1. `readme.txt` line 90 |
| FAQ on upgrading from Appointment Hour Booking or Titan Front Desk | Partial | `readme.txt` line 157 says there is no import tool. It conflicts with a clean-update criterion if owner decision 1 chooses an update path |
| FAQ "Does this version use AI?" | Open | Line 147 says the version contains no AI feature and does not contact any AI provider. See owner decision 3 |
| External services section | Partial | `readme.txt` line 191 onward. See section 6 |
| Screenshots section | Open | Not added (`WORDPRESS-ORG-READINESS.md` section 1) |
| Non-standard sections | Owner decision | Owner decision 6 |
| Changelog entry 3.0.128 and upgrade notice | Done (present) | `readme.txt` line 337; `changelog.txt` line 4 |
| Changelog and upgrade notice for the next release | Open | Depends on owner decision 4 |

## 6. External-service disclosure

| Item | Status | Evidence |
|---|---|---|
| SMS gateway (missed-call text-back) disclosed | Done (by inspection) | `readme.txt` line 199, item 1 |
| Web page for service import disclosed | Done (by inspection) | `readme.txt` line 211, item 2 |
| Webhook endpoint disclosed | Done (by inspection) | `readme.txt` line 221, item 3 |
| Twilio (off by default) disclosed | Done (by inspection) | `readme.txt` line 241, item 5. Code at `includes/class-tzfd-twilio-sms.php` |
| Every outbound call in code is listed | Partial | `WORDPRESS-ORG-READINESS.md` section 3 lists four call sites by inspection |
| AI connector (merged in PR #65) disclosed | Open | Not in External services. Its readme statements say no AI provider is contacted. Owner decision 3 |
| Consent controls for external services | Open | Not traced in any lane |
| Nothing sent to plugin author by default | Partial | Stated in readme line 195. Not traced on every path |
| Safe endpoints (HTTPS, safe remote requests) | Partial | Readiness doc section 3 |

## 7. Notices and provenance counts

| Item | Status | Evidence |
|---|---|---|
| Chart.js 4.6.1 notice is correct | Done | `THIRD-PARTY-NOTICES.txt` section 2 lines 77-99. PR #94 corrects `UPSTREAM.txt` |
| Notices file counts (92 identical, 52 changed) | Open | `THIRD-PARTY-NOTICES.txt` lines 294-295 |
| Measured counts (91 identical, 42 changed) | Done (measured here) | `scripts/compare-upstream.sh` run 2026-11-17 against the upstream extract in `/home/claude/fd-scan/upstream-x/appointment-hour-booking`: 481 upstream files, 756 plugin files, 91 identical, 42 differ, 348 upstream paths absent. Same result as `docs/PROVENANCE-RECORD.md` line 27 |
| Why the two counts differ | Open | `docs/PROVENANCE-RECORD.md` line 34 says the notices pass used a different path mapping. Not resolved |
| Correct the notices file to the measured counts | Owner decision | Owner decision 5 |
| Upstream package hash | Done | `docs/PROVENANCE-RECORD.md` line 9 |
| Comparison against the v3.0.128 ZIP | Open | No archive present (`WORDPRESS-ORG-READINESS.md` section 4) |
| Three-way merge against current main | Open | Not run |

## 8. Version bump

| Item | Status | Evidence |
|---|---|---|
| Version is 3.0.128 in header and Stable tag | Done (current state) | `titan-zero-trades-front-desk/titan-zero-front-desk.php` line 5 (`Version:`), `readme.txt` line 13 (`Stable tag:`) |
| Version bump | Owner decision | Owner decision 4. Not bumped in this lane |

## 9. Other readiness items (from `docs/WORDPRESS-ORG-READINESS.md`)

| Item | Status | Evidence |
|---|---|---|
| php -l on every PHP file | Done | `.github/workflows/tests.yml` `php-lint` job; readiness doc section 2 |
| CI runs lint and standalone harnesses on PRs | Done (exists) | `.github/workflows/tests.yml`. Readiness doc section 2 says "Open" and is out of date on this point |
| PHP 7.4 compatibility check | Open | Only PHP 8.3 available. Readiness doc section 2 |
| WordPress 6.6 minimum tested | Open | Not run |
| Accessibility audit (WCAG) | Open | Not audited (#9 requires it) |
| Hard-coded strings across templates and admin markup | Open | Not audited |
| Uninstaller | Open | `uninstall.php` exists. Not run |
| Privacy exporter and eraser | Partial | Registration only, `includes/class-tzfd-privacy-exporter.php` lines 22-23. Behaviour not run |
| Reproducible build | Done | `scripts/build-package.sh` (readiness doc section 1) |
| Source manifest | Done | `docs/provenance/SHA256SUMS.txt` |
| Rollback plan (keep previous ZIP and its .sha256) | Open | Readiness doc section 5 |

## Stale statements to correct

- `docs/WORDPRESS-ORG-READINESS.md` section 2 says CI has only issue-intake and closure workflows. `.github/workflows/tests.yml` and `interserver-release-readiness.yml` exist on main.
- `docs/WORDPRESS-ORG-READINESS.md` section 3 does not mention the AI connector merged in PR #65.

## Summary

Done: header and readme structure, reproducible build, source manifest, provenance, PHP lint in CI, external-service inventory by inspection, connector default-off and secret-hidden behaviour.

Open: open PRs to merge (21), runtime and multisite tests, Plugin Check, written security audit, PHP 7.4 and WordPress minimum checks, accessibility audit, clean-update path, connector disclosure, notices counts, release version.

No WordPress.org approval has been sought or claimed.
