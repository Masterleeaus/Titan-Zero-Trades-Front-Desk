#!/usr/bin/env bash
# Upgrade and downgrade check on a disposable site:
#   1. install the v3.0.128 source (git commit BASE_SHA, read with git archive, not a checkout)
#   2. seed bookings and settings on 3.0.128
#   3. replace the plugin folder with this checkout, deactivate and reactivate, verify the data is unchanged
#   4. put v3.0.128 back, reactivate, verify again (downgrade)
# WordPress does not run the activation hook on a file-replacement update; deactivate and reactivate is the
# closest reproducible equivalent, and it is what the plugin's install routines run on.
#
# Usage: tests/runtime/upgrade-from-3.0.128.sh      Env: BASE_SHA (default 22dc106), WP_CLI, TZFD_RT_DB_ADMIN
# Exit:  0 all upgrade checks passed; 1 a check failed; 3 prerequisites missing.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
NEW_PLUGIN="$(cd "$HERE/../../titan-zero-trades-front-desk" && pwd)"
REPO_DIR="$(git -C "$HERE" rev-parse --show-toplevel 2>/dev/null)"
BASE_SHA="${BASE_SHA:-22dc106}"
WP_CLI="${WP_CLI:-wp}"
PORT="${PORT:-8080}"
WORK="$(mktemp -d /tmp/tzfd-upg.XXXXXX)"
OLD_DIR="$WORK/old"
mkdir -p "$OLD_DIR" "$WORK/wp"
LOG="$WORK/upgrade.log"
PLUGIN_LINK=""
SERVER_PID=""

say() { echo "$*" | tee -a "$LOG"; }
cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null
  if [ -n "${DB:-}" ]; then mysql -u"${TZFD_RT_DB_ADMIN:-root}" -e "DROP DATABASE IF EXISTS \`$DB\`" 2>/dev/null; fi
  rm -rf "$WORK/wp"
}
trap cleanup EXIT

$WP_CLI --allow-root --version >/dev/null 2>&1 || { say "SKIP upgrade -- WP-CLI not runnable"; exit 3; }
command -v mysql >/dev/null 2>&1 && mysql -u"${TZFD_RT_DB_ADMIN:-root}" -e 'SELECT 1' >/dev/null 2>&1 || { say "SKIP upgrade -- no reachable MySQL"; exit 3; }
[ -n "$REPO_DIR" ] || { say "SKIP upgrade -- not inside a git checkout"; exit 3; }

# 1. v3.0.128 source, extracted from git history into a new empty folder.
git -C "$REPO_DIR" archive "$BASE_SHA" titan-zero-trades-front-desk | tar -x -C "$OLD_DIR" || { say "FAIL upgrade -- cannot read $BASE_SHA"; exit 1; }
OLD_PLUGIN="$OLD_DIR/titan-zero-trades-front-desk"
OLD_VER="$(sed -n 's/^.*Version: *//p' "$OLD_PLUGIN/titan-zero-front-desk.php" | head -1)"
NEW_VER="$(sed -n 's/^.*Version: *//p' "$NEW_PLUGIN/titan-zero-front-desk.php" | head -1)"
say "Baseline $BASE_SHA reports version $OLD_VER; this checkout reports $NEW_VER"

# 2. Site on the old plugin.
PROV="$("$HERE/provision-wp-site.sh" "$WORK/wp" "$OLD_PLUGIN" 2>>"$LOG")" || { say "FAIL provision -- see $LOG"; exit 1; }
eval "$(echo "$PROV" | grep '^TZFD_RT_')"
DB="$TZFD_RT_DB_NAME"
PLUGIN_LINK="$WORK/wp/wp-content/plugins/titan-zero-trades-front-desk"
say "PASS provision on $OLD_VER"

FP="$WORK/fingerprint.json"
run_php() { TZFD_RT_PHASE="$1" TZFD_RT_FINGERPRINT="$FP" TZFD_RT_EXPECT_DIR="$CURRENT_SRC" $WP_CLI --allow-root --path="$WORK/wp" eval-file "$HERE/upgrade-seed-verify.php" --user=1 2>/dev/null | tee -a "$LOG"; }
CURRENT_SRC="$OLD_PLUGIN"
say "--- seed on $OLD_VER"
run_php seed
grep -q '^PASS seed' "$LOG" || { say "FAIL seed -- see $LOG"; exit 1; }

# 3. Upgrade: replace the plugin folder, deactivate, reactivate.
swap_to() { # folder
  CURRENT_SRC="$1"
  rm -f "$PLUGIN_LINK" && ln -s "$1" "$PLUGIN_LINK" || return 1
  $WP_CLI --allow-root --path="$WORK/wp" plugin deactivate titan-zero-trades-front-desk --quiet 2>>"$LOG" || return 1
  $WP_CLI --allow-root --path="$WORK/wp" plugin activate titan-zero-trades-front-desk --user=1 --quiet 2>>"$LOG" || return 1
}
say "--- upgrade to this checkout ($NEW_VER)"
swap_to "$NEW_PLUGIN" || { say "FAIL upgrade -- activation on $NEW_VER failed; see $LOG"; exit 1; }
run_php verify

# 4. Downgrade back to the old folder: the same data must still read.
say "--- downgrade back to $OLD_VER"
swap_to "$OLD_PLUGIN" || { say "FAIL downgrade -- activation on $OLD_VER failed; see $LOG"; exit 1; }
run_php verify

FAILS=$(grep -c '^FAIL ' "$LOG")
PASSES=$(grep -c '^PASS ' "$LOG")
GAPS=$(grep -c '^GAP ' "$LOG")
say "Summary (upgrade $OLD_VER -> $NEW_VER and back): $PASSES passed, $FAILS failed, $GAPS gap(s) (log: $LOG)"
[ "$FAILS" -eq 0 ] || exit 1
exit 0
