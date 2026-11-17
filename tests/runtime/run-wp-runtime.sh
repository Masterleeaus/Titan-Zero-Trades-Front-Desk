#!/usr/bin/env bash
# Runs the real-WordPress runtime suite against a disposable site. Nothing here calls a live external service.
#
# Usage: tests/runtime/run-wp-runtime.sh [--multisite]
# Env:   WP_CLI (default: wp)  PORT (default: 8080)  TZFD_RT_DB_ADMIN (default: root)  KEEP_SITE=1 keeps the site
#
# Exit:  0 every executed check passed (SKIP and GAP lines do not fail the run)
#        1 at least one executed check FAILED
#        3 prerequisites missing: nothing was executed, every suite is reported as SKIP
set -u

HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/../../titan-zero-trades-front-desk" && pwd)"
WP_CLI="${WP_CLI:-wp}"
PORT="${PORT:-8080}"
URL="http://127.0.0.1:$PORT"
MULTISITE_FLAG="${1:-}"
PHP_VER="$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo none)"

skip_all() {
  echo "SKIP suite -- $1"
  echo "SKIP wp-runtime-tests -- $1"
  echo "SKIP http-auth-tests -- $1"
  echo "SKIP uninstall-wipe-check -- $1"
  echo "Summary: nothing executed; prerequisites missing"
  exit 3
}

command -v php >/dev/null 2>&1 || skip_all "php not found"
command -v curl >/dev/null 2>&1 || skip_all "curl not found"
command -v mysql >/dev/null 2>&1 || skip_all "mysql client not found"
$WP_CLI --allow-root --version >/dev/null 2>&1 || skip_all "WP-CLI not runnable (set WP_CLI)"
mysql -u"${TZFD_RT_DB_ADMIN:-root}" -e 'SELECT 1' >/dev/null 2>&1 || skip_all "no reachable MySQL/MariaDB for ${TZFD_RT_DB_ADMIN:-root}"

WORK="$(mktemp -d /tmp/tzfd-rt.XXXXXX)"
WP_ROOT="$WORK/wp"
mkdir -p "$WP_ROOT"
LOG="$WORK/run.log"
echo "Work folder (logs kept): $WORK"

cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null
  # Logs stay in $WORK; the disposable site and its database are removed unless KEEP_SITE=1.
  if [ "${KEEP_SITE:-0}" != "1" ]; then
    rm -rf "$WP_ROOT"
    [ -n "${TZFD_RT_DB_NAME:-}" ] && mysql -u"${TZFD_RT_DB_ADMIN:-root}" -e "DROP DATABASE IF EXISTS \`$TZFD_RT_DB_NAME\`" 2>/dev/null
  fi
}
trap cleanup EXIT

export TZFD_RT_DB_NAME="tzfd_rt_$$"  # chosen here so cleanup can drop it even if provisioning fails
PROVISION_OUT="$("$HERE/provision-wp-site.sh" "$WP_ROOT" "$PLUGIN_DIR" $MULTISITE_FLAG 2>"$WORK/provision.err")"
if [ $? -ne 0 ]; then
  echo "FAIL provision -- $(cat "$WORK/provision.err")"
  echo "Summary: provisioning failed; nothing executed"
  exit 1
fi
eval "$(echo "$PROVISION_OUT" | grep '^TZFD_RT_')"
export TZFD_RT_URL="$URL" TZFD_RT_ADMIN_PASS TZFD_RT_SUB_PASS
echo "PASS provision -- WordPress $(grep -m1 -o "wp_version = '[^']*'" "$WP_ROOT/wp-includes/version.php" | cut -d"'" -f2) on PHP $PHP_VER, plugin linked from source$( [ -n "$MULTISITE_FLAG" ] && echo ', multisite' )"

php -S "127.0.0.1:$PORT" -t "$WP_ROOT" >"$WORK/server.log" 2>&1 &
SERVER_PID=$!
sleep 2

echo "--- http-auth-tests"
TZFD_RT_URL="$URL" "$HERE/http-auth-tests.sh" | tee -a "$LOG"

echo "--- privacy and signed-request checks (runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"

echo "--- real WordPress runtime tests (wp-runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/wp-runtime-tests.php" --user=1 2>/dev/null | tee -a "$LOG"

echo "--- agent gateway (agent-gateway-runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/agent-gateway-runtime-tests.php" --user=1 2>/dev/null | tee -a "$LOG"
echo "--- legacy AI data (legacy-ai-runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/legacy-ai-runtime-tests.php" --user=1 2>/dev/null | tee -a "$LOG"

# Destructive: last, in its own process.
echo "--- opt-in uninstall (uninstall-wipe-check.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/uninstall-wipe-check.php" --user=1 2>/dev/null | tee -a "$LOG"

{
  echo "--- coverage not executed here"
  echo "SKIP php-matrix -- only PHP $PHP_VER is available locally; PHP 8.1, 8.2 and 8.4 are not provisioned"
  echo "SKIP wp-matrix -- only one WordPress core version was provisioned"
  [ -n "$MULTISITE_FLAG" ] || echo "SKIP multisite -- run with --multisite to provision and test a network"
  echo "SKIP live-provider -- SMS, e-mail transport, telephony and hosted AI are not called by design"
} | tee -a "$LOG"

FAILS=$(grep -c '^FAIL ' "$LOG")
PASSES=$(grep -c '^PASS ' "$LOG")
GAPS=$(grep -c '^GAP ' "$LOG")
SKIPS=$(grep -c '^SKIP ' "$LOG")
echo "Summary: $PASSES passed, $FAILS failed, $GAPS gap(s), $SKIPS skipped (log: $LOG)"
[ "$FAILS" -eq 0 ] || exit 1
exit 0
