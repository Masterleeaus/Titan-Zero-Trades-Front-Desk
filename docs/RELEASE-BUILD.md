# Release build verification

How to build and verify the installable Free plugin ZIP for `titan-zero-trades-front-desk`
(WordPress.org plugin name: Scheduling & Enquiries Manager). This document records the
verification steps; it does not cut a release.

## Build

```bash
bash scripts/build-package.sh [OUT_DIR]
```

Outputs (default `dist/`, git-ignored):

- `titan-zero-trades-front-desk-<version>.zip`
- `titan-zero-trades-front-desk-<version>.zip.sha256`
- `SHA256SUMS.txt` (per-file manifest of the packaged tree)

The script reads `Version:` from the main plugin header, copies the plugin folder while
honouring `scripts/package-excludes.txt`, runs `php -l` on the staged tree, sets every
mtime to `SOURCE_DATE_EPOCH` (default: commit time of HEAD), and zips with sorted entries
and `zip -X -D`.

## Verification steps

1. **Reproducibility.** Build twice into two empty directories and compare the archive
   hashes. Expected: identical SHA-256.
2. **Checkout-time independence.** Touch every file in the plugin folder to the current
   time, rebuild, and compare the hash again. Expected: identical SHA-256, because mtimes
   are normalised.
3. **Archive integrity.** `unzip -t <zip>` must report `No errors detected`.
4. **Dev-file scan.** List entries and confirm none of the following are present:
   `tests/`, `scripts/`, `docs/`, `.git*`, `.github/`, `.editorconfig`, `composer.*`,
   `phpunit.xml*`, `phpcs.xml*`, `*.md`, `*.sh`, `*.yml`, `*.map`, `*.bak`, `*.log`, `*.zip`,
   `node_modules/`, `dist/`.
5. **Folder name.** Every entry must sit under a single top-level folder equal to the
   slug `titan-zero-trades-front-desk`.
6. **Install-from-ZIP header.** Extract `titan-zero-trades-front-desk/titan-zero-front-desk.php`
   and confirm the header block (Plugin Name, Version, Requires at least, Requires PHP,
   License, Text Domain). `readme.txt` must carry a `Stable tag` equal to the header
   `Version`.
7. **PHP syntax.** Extract the ZIP and run `php -l` on every `*.php` file.

## Results (main at 40928a2, plugin version 3.0.128)

| Check | Result |
|---|---|
| Two builds, archive SHA-256 | Identical: `894a9eac58427bb939790b2dfe5704cc5758946deda9a9ecdb006647ad68a2ea` |
| Build after mtime reset | Identical hash (same value as above) |
| `SHA256SUMS.txt` across two builds | Identical |
| `unzip -t` | No errors detected (756 entries) |
| Top-level folder | All 756 entries under `titan-zero-trades-front-desk/` |
| Tests, scripts, docs, `.git*`, CI config, composer/phpunit/phpcs files, maps, backups | Not present |
| Main header and `readme.txt` stable tag | Both `3.0.128`; Text Domain matches slug |
| `php -l` on extracted PHP | 266 files, 0 failures |

## Known packaging findings (not changed in this step)

- `js/src/package.json`, `js/src/package-lock.json` and `js/src/build-tzfdform-public.js`
  ship inside the ZIP. They are JavaScript build inputs, not runtime code. Adding them to
  `scripts/package-excludes.txt` would remove them, but that changes the release contents
  and needs an owner decision. Until then, the dev-file scan (step 4) flags these three.
- `changelog.txt`, `UPSTREAM.txt`, `INHERITED-FILE-CHANGES.txt` and `THIRD-PARTY-NOTICES.txt`
  are shipped in the plugin folder. They are provenance and licence notices, so they are
  kept by default. The owner should confirm.

## Before a release

- Bump the version in `titan-zero-front-desk.php` (`Version:` and `TZFD_VERSION`) and in
  `readme.txt` (`Stable tag`), and add a changelog entry. These are owner decisions and are
  not made by the build.
- Rebuild and repeat steps 1 to 7 on the release commit.
- Record the archive SHA-256 in the release notes.
