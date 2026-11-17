# Provenance record and packaging path (issue #6)

Scope: Scheduling & Enquiries Manager (folder and slug `titan-zero-trades-front-desk`). Recorded 2026-11-16 on branch `packaging-provenance-wporg`, from commit 1094336.

## Inputs

| Input | What it is | Available here | Status |
|---|---|---|---|
| Upstream base | Appointment Hour Booking 1.5.98 (CodePeople, GPLv2) | Yes, downloaded from wordpress.org | SHA-256 `754d4250...74bc4` re-verified 2026-11-16, matches UPSTREAM.txt |
| Plugin candidate | Plugin tree at this commit, header Version 3.0.128 | Yes | Manifest in `docs/provenance/SHA256SUMS.txt` (707 files) |
| "v3.0.128 baseline" | The released v3.0.128 ZIP | No. No archive of it is in this repository | Gap. The version in the header is the candidate's own version, not a separate archive |
| "Pass 03 cumulative candidate" | Earlier cumulative build named in #6 | No | Gap. Not provided to this lane |
| Current GitHub main | Latest main | Not reconciled in this lane | Gap. Branch is cut from 1094336 |

The issue asks for a comparison against v3.0.128. Because no archive of that release exists in the repository, this record uses the verifiable upstream 1.5.98 package as the base.

## Reproducing the comparison

```
scripts/compare-upstream.sh <extracted-upstream-folder> titan-zero-trades-front-desk [report-dir]
```

The upstream ZIP has one top-level folder (`appointment-hour-booking/`). Extract it into an empty directory and pass that folder. The script compares files by relative path using the same exclusion list as the build.

Result on 2026-11-16 (481 upstream files, 707 plugin files):

- Same path, byte-identical: 91
- Same path, content differs: 42
- Upstream path not present at the same path (renamed or removed): 348
- Plugin-only path (new, renamed in, or generated): 574

The 42 changed paths include the expected changes listed in THIRD-PARTY-NOTICES.txt section 7, plus the jQuery UI CSS and the rewritten captcha adapter. The 348 and 574 counts are dominated by the folder and file renames documented in INHERITED-FILE-CHANGES.txt. Those rename mappings have not been checked file by file in this lane.

Note: THIRD-PARTY-NOTICES.txt reports "92 identical, 52 changed" from its own 2026-11-15 pass. This script's same-path comparison gives 91 and 42. The notices pass must have used a different path mapping. The difference is not resolved here.

## Corrections made in this lane

Several provenance statements did not match the files. They were corrected in place:

1. UPSTREAM.txt listed `css/cupertino/jquery-ui-1.8.20.custom.css` as identical to upstream. It is not. It holds jQuery UI 1.14.2 Cupertino CSS (THIRD-PARTY-NOTICES.txt section 4). It is now listed as replaced.
2. UPSTREAM.txt and THIRD-PARTY-NOTICES.txt listed `tzfd-calendar/css/cupertino/calendar.css` as identical. It differs from `mv/css/cupertino/calendar.css` only by one added attribution comment line. Both files now say so.
3. UPSTREAM.txt listed `mv/css/main.css` as identical. It differs by one comment line. Corrected.
4. UPSTREAM.txt said the build ships Chart.js 4.5.1 and that 4.6.1 was still required. The header of `js/chart.js` is Chart.js v4.6.1. Corrected.
5. UPSTREAM.txt called `js/jquery.validate.js` "added from npm, not in upstream". Upstream has a minified copy at that path, which was replaced by jquery-validation 1.22.1. Corrected.
6. UPSTREAM.txt named the calendar language files `tzfdcalendar_lang_*.js`. The files are `tz_fd_calendar_lang_*.js`. Corrected.
7. The display name is now "Scheduling & Enquiries Manager" in the plugin header and the readme title and body. UPSTREAM.txt records this. The folder, main file, text domain and `tzfd` identifiers are unchanged.

Not changed: `tzfd-settings.inc.php` line 28 (`TZFD_Titan Zero Front Desk`) is a stored identifier and was left alone. The `icalexport` PRODID string was also left alone.

## Credits

The upstream author credit remains in readme.txt (Credits), THIRD-PARTY-NOTICES.txt and UPSTREAM.txt. LICENSE.txt is the GPLv2 text. No credit was removed in this lane.

## Build and manifest

- `scripts/package-excludes.txt`: one list of exclusions, shared by the manifest and the build.
- `scripts/source-manifest.sh <plugin-dir>`: SHA-256 for each shipped file, sorted with `LC_ALL=C`. The committed copy is `docs/provenance/SHA256SUMS.txt`.
- `scripts/build-package.sh [out-dir]`: stages the plugin, runs `php -l` on every PHP file, sets mtimes to `SOURCE_DATE_EPOCH` (default: HEAD commit time), and writes `titan-zero-trades-front-desk-<version>.zip`, its `.sha256`, and `SHA256SUMS.txt`. Output goes to `dist/`, which is git-ignored.

Reproducibility check on 2026-11-16: two consecutive builds produced byte-identical ZIPs (`cmp` passed). The build result is recorded in the PR.

## Three-way merge plan

Base: Appointment Hour Booking 1.5.98 (verified above). Ours: this plugin tree at 1094336. Theirs: the Pass 03 candidate and current main, neither of which is available to this lane.

1. Obtain the Pass 03 ZIP and the v3.0.128 ZIP. Extract each into an empty directory, then run `compare-upstream.sh` against each. Use the same path mapping for all three.
2. Diff ours against Pass 03 for every path in `differs-paths.txt`, and against main for every path changed since 1094336. Record each conflict in this file.
3. For every conflict, keep the side that contains the booking, cancellation, reminder, telephony-log, customer-data, Woo/payment and privacy-exporter code. Do not drop any of them.
4. Re-run the manifest and build after the merge, then run the runtime tests in `tests/runtime/` on a disposable WordPress site.

Status: steps 1 to 4 are not done. Only the base and ours sides exist in this lane.

## Decisions for review

- Base for the provenance comparison is upstream 1.5.98, because no v3.0.128 archive exists in the repository.
- The version stays 3.0.128. A release bump, and the matching changelog and upgrade notice, belong to the release lane. This avoids conflicts with the parallel lanes.
- The readme still says "This is a new plugin, not an upgrade" (Installation step 1). That conflicts with the acceptance criterion of a clean update from the previous ZIP. Left for the lead to decide.
- The `shortcode`, option keys, table names and slugs are unchanged.

## Gaps (explicit)

- No v3.0.128 ZIP and no Pass 03 candidate were available. The three-way merge has not been run.
- Current GitHub main was not reconciled with this branch.
- The file-by-file record for renamed files (INHERITED-FILE-CHANGES.txt section A) was not re-checked beyond the two calendar CSS files.
