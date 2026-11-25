#!/usr/bin/env bash
# Issue #389: clean install of the BUILT free ZIP, as a scripted CI gate.
#
# The ZIP is unpacked into an empty WordPress site (not copied from the checkout), activated, and checked. The
# existing clean-install-ai-check.php runs on top of it. Upgrade-with-existing-data is out of scope (no customers yet,
# owner decision of 2026-11-18).
#
# Usage: clean-install-zip.sh <plugin.zip> <wp_root>
# Env:   WP_CLI (default: wp)   WP_URL (default: http://localhost:8080; the server must already be running)
# Exit:  0 every check passed; 1 at least one check failed. One PASS or FAIL line per check.
set -u

ZIP="${1:?usage: clean-install-zip.sh <plugin.zip> <wp_root>}"
WP="${2:?usage: clean-install-zip.sh <plugin.zip> <wp_root>}"
WP_CLI="${WP_CLI:-wp}"
WP_URL="${WP_URL:-http://localhost:8080}"
SLUG="titan-zero-trades-front-desk"
HERE="$(cd "$(dirname "$0")" && pwd)"
FAILS=0

pass() { echo "PASS $*"; }
fail() { echo "FAIL $*"; FAILS=$((FAILS + 1)); }
wpc() { $WP_CLI --allow-root --path="$WP" "$@"; }

[ -f "$ZIP" ] || { fail "zip -- not found: $ZIP"; exit 1; }
echo "zip sha256 $(sha256sum "$ZIP" | cut -d' ' -f1)"

# 1. Archive integrity and layout: one top-level folder, the plugin's main file, no development files.
if unzip -tq "$ZIP" >/dev/null 2>&1; then pass "zip archive is intact"; else fail "zip archive is corrupt"; fi
entries="$(unzip -Z1 "$ZIP")"
if [ -n "$entries" ] && [ -z "$(echo "$entries" | grep -v "^$SLUG/")" ]; then
  pass "every entry sits under $SLUG/"
else
  fail "zip entries outside $SLUG/ or empty"
fi
if echo "$entries" | grep -q "^$SLUG/titan-zero-front-desk.php$"; then pass "main plugin file is in the archive"; else fail "main plugin file missing from the archive"; fi
leaks="$(echo "$entries" | grep -E '(^|/)(\.git|\.github|tests|node_modules|\.DS_Store|__MACOSX)(/|$)' || true)"
if [ -z "$leaks" ]; then pass "no development files in the archive"; else fail "development files in the archive: $(echo "$leaks" | head -n 3 | tr '\n' ' ')"; fi

# 2. Unpack into the empty site's plugin folder, then check the installed tree matches the archive byte for byte.
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
unzip -q "$ZIP" -d "$STAGE" || fail "unzip failed"
rm -rf "$WP/wp-content/plugins/$SLUG"
cp -r "$STAGE/$SLUG" "$WP/wp-content/plugins/$SLUG" || fail "copy of the unpacked plugin failed"
if diff -r "$STAGE/$SLUG" "$WP/wp-content/plugins/$SLUG" >/dev/null; then
  pass "installed tree matches the archive"
else
  fail "installed tree differs from the archive"
fi

# 3. Activation on a clean install: no error, no PHP notice, plugin reported active.
ACT_OUT="$(wpc plugin activate "$SLUG" --user=1 2>&1)"; ACT_RC=$?
if [ "$ACT_RC" -eq 0 ]; then pass "plugin activates from the archive"; else fail "activation exit $ACT_RC: $ACT_OUT"; fi
if echo "$ACT_OUT" | grep -Eqi 'fatal|parse error|warning|notice'; then fail "activation printed a PHP error: $ACT_OUT"; else pass "activation prints no PHP error or notice"; fi
if wpc plugin list --status=active --field=name 2>/dev/null | grep -qx "$SLUG"; then pass "plugin is listed as active"; else fail "plugin not active after activation"; fi

# 4. Clean-install AI check (no AI call, no credential prompt) on the archive install.
AI_LOG="$(mktemp)"
wpc eval-file "$HERE/clean-install-ai-check.php" >"$AI_LOG" 2>&1; AI_RC=$?
cat "$AI_LOG"
if [ "$AI_RC" -eq 0 ] && ! grep -q '^FAIL ' "$AI_LOG" && [ "$(grep -c '^PASS ' "$AI_LOG")" -gt 0 ]; then
  pass "clean-install AI check passes on the archive install"
else
  fail "clean-install AI check failed (exit $AI_RC)"
fi
rm -f "$AI_LOG"

# 5. The front end answers on the clean install (the server is started by the workflow).
CODE="$(curl -s -o /dev/null -w '%{http_code}' "$WP_URL/" || true)"
if [ "$CODE" = "200" ]; then pass "front page answers 200 with the plugin active"; else fail "front page answered $CODE"; fi

echo "Summary: $FAILS failed"
[ "$FAILS" -eq 0 ]
