#!/usr/bin/env bash
# Upgrade and downgrade check on a disposable site:
#   1. install the v3.0.128 source (git commit BASE_SHA, read with git archive, not a checkout)
#   2. seed bookings and settings on 3.0.128
#   3. replace the plugin folder with this checkout, deactivate and reactivate, verify the data is unchanged
#   4. put v3.0.128 back, reactivate, verify again (downgrade)
# WordPress does not run the activation hook on a file-replacement update; deactivate and reactivate is the
# closest reproducible equivalent, and it is what the plugin's install routines run on.
#
# Usage: tests/runtime/upgrade-from-3.0.128.sh      Env: SAME_VERSION_OK (default 1, same-version release ZIP), BASE_SHA (default 22dc106^), BASE_ZIP (release ZIP path at BASE_SHA), WP_CLI, TZFD_RT_DB_ADMIN
# Exit:  0 all upgrade checks passed; 1 a check failed; 3 prerequisites missing.
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
NEW_PLUGIN="$(cd "$HERE/../../titan-zero-trades-front-desk" && pwd)"
REPO_DIR="$(git -C "$HERE" rev-parse --show-toplevel 2>/dev/null)"
# The base is a real earlier release, read from git history. The default is the Front Desk 2.0.1 release ZIP committed
# at 4e0c3b3 (version 2.0.1, a different version from this checkout). The 3.0.128 extraction commit (22dc106) cannot be
# the base any more: this checkout is also 3.0.128, so the upgrade would not change the version (issue #194).
# Issue #349: the default base is now the v3.0.128 release ZIP, read from the commit before its extraction (22dc106^).
# Same version as this checkout, so SAME_VERSION_OK defaults to 1: the check asserts which source folder is loaded.
BASE_SHA="${BASE_SHA:-22dc106^}"
BASE_ZIP="${BASE_ZIP:-titan-zero-trades-front-desk-3.0.128-r16.zip}"
SAME_VERSION_OK="${SAME_VERSION_OK:-1}"
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

# 1. Earlier release from git history, extracted into a new empty folder. BASE_ZIP is a release archive committed at
#    BASE_SHA; without it the folder is read from the commit with git archive.
if [ -n "$BASE_ZIP" ]; then
  git -C "$REPO_DIR" show "$BASE_SHA:$BASE_ZIP" > "$WORK/base.zip" 2>/dev/null || { say "FAIL upgrade -- cannot read $BASE_ZIP at $BASE_SHA"; exit 1; }
  unzip -q "$WORK/base.zip" -d "$OLD_DIR" || { say "FAIL upgrade -- cannot unzip $BASE_ZIP"; exit 1; }
else
  git -C "$REPO_DIR" archive "$BASE_SHA" titan-zero-trades-front-desk | tar -x -C "$OLD_DIR" || { say "FAIL upgrade -- cannot read $BASE_SHA"; exit 1; }
fi
OLD_PLUGIN="$OLD_DIR/titan-zero-trades-front-desk"
[ -d "$OLD_PLUGIN" ] || { say "FAIL upgrade -- base archive has no titan-zero-trades-front-desk folder"; exit 1; }
OLD_MAIN="$(grep -l 'Plugin Name:' "$OLD_PLUGIN"/*.php 2>/dev/null | head -1)"
OLD_VER="$(sed -n 's/^.*Version: *//p' "$OLD_MAIN" | head -1)"
NEW_VER="$(sed -n 's/^.*Version: *//p' "$NEW_PLUGIN/titan-zero-front-desk.php" | head -1)"
say "Baseline $BASE_SHA${BASE_ZIP:+ ($BASE_ZIP)} reports version $OLD_VER; this checkout reports $NEW_VER"
# Safety: the base must be a different version, otherwise the check does not exercise an upgrade.
# SAME_VERSION_OK=1 allows a same-version base (the 3.0.128 release ZIP against a 3.0.128 checkout). The folder swap is
# still checked by the schema step, which asserts the loaded source folder (CURRENT_SRC), not the version string.
[ -n "$OLD_VER" ] || { say "FAIL upgrade -- base version is empty"; exit 1; }
if [ "$OLD_VER" = "$NEW_VER" ] && [ "${SAME_VERSION_OK:-0}" != 1 ]; then
  say "FAIL upgrade -- base version '$OLD_VER' must differ from this checkout '$NEW_VER' (set SAME_VERSION_OK=1 for a same-version release ZIP)"; exit 1
fi

# 2. Site on the old plugin.
PROV="$("$HERE/provision-wp-site.sh" "$WORK/wp" "$OLD_PLUGIN" 2>>"$LOG")" || { say "FAIL provision -- see $LOG"; exit 1; }
eval "$(echo "$PROV" | grep '^TZFD_RT_')"
DB="$TZFD_RT_DB_NAME"
PLUGIN_LINK="$WORK/wp/wp-content/plugins/titan-zero-trades-front-desk"
say "PASS provision on $OLD_VER"

FP="$WORK/fingerprint.json"
run_php() { TZFD_RT_PHASE="$1" TZFD_RT_FINGERPRINT="$FP" TZFD_RT_EXPECT_DIR="$CURRENT_SRC" $WP_CLI --allow-root --path="$WORK/wp" eval-file "$HERE/upgrade-seed-verify.php" --user=1 2>/dev/null | tee -a "$LOG"; }
# Schema check on the active folder: expected version from the plugin header, and the current release's tables when asked.
run_schema() { # expected version, check tables (1 or 0)
  TZFD_RT_PHASE=schema TZFD_RT_EXPECT_DIR="$CURRENT_SRC" TZFD_RT_EXPECT_VERSION="$1" TZFD_RT_CHECK_TABLES="$2" $WP_CLI --allow-root --path="$WORK/wp" eval-file "$HERE/upgrade-seed-verify.php" --user=1 2>/dev/null | tee -a "$LOG"
}
CURRENT_SRC="$OLD_PLUGIN"
# The booking data seed and verify use the 3.x booking schema (includes/class-tzfd-tables.php). A base that does not have it
# (the 2.0.1 release keeps bookings in its own tables) gets the version and table checks only, and the log says so.
SEEDED=0
if [ -f "$OLD_PLUGIN/includes/class-tzfd-tables.php" ]; then
  say "--- seed on $OLD_VER"
  run_php seed
  grep -q '^PASS seed' "$LOG" || { say "FAIL seed -- see $LOG"; exit 1; }
  SEEDED=1
else
  say "SKIP data seed and verify -- base $OLD_VER has no includes/class-tzfd-tables.php (its own booking schema); carry-over needs an owner decision (issue #194)"
fi

# 3. Upgrade: replace the plugin folder, deactivate, reactivate.
swap_to() { # folder
  CURRENT_SRC="$1"
  rm -f "$PLUGIN_LINK" && ln -s "$1" "$PLUGIN_LINK" || return 1
  $WP_CLI --allow-root --path="$WORK/wp" plugin deactivate titan-zero-trades-front-desk --quiet 2>>"$LOG" || return 1
  $WP_CLI --allow-root --path="$WORK/wp" plugin activate titan-zero-trades-front-desk --user=1 --quiet 2>>"$LOG" || return 1
}
say "--- upgrade to this checkout ($NEW_VER)"
swap_to "$NEW_PLUGIN" || { say "FAIL upgrade -- activation on $NEW_VER failed; see $LOG"; exit 1; }
run_schema "$NEW_VER" 1
if [ "$SEEDED" = 1 ]; then run_php verify; fi

# 4. Downgrade back to the old folder: the same data must still read.
say "--- downgrade back to $OLD_VER"
swap_to "$OLD_PLUGIN" || { say "FAIL downgrade -- activation on $OLD_VER failed; see $LOG"; exit 1; }
run_schema "$OLD_VER" 0
if [ "$SEEDED" = 1 ]; then run_php verify; fi

FAILS=$(grep -c '^FAIL ' "$LOG")
PASSES=$(grep -c '^PASS ' "$LOG")
GAPS=$(grep -c '^GAP ' "$LOG")
say "Summary (upgrade $OLD_VER -> $NEW_VER and back): $PASSES passed, $FAILS failed, $GAPS gap(s) (log: $LOG)"
[ "$FAILS" -eq 0 ] || exit 1
exit 0
