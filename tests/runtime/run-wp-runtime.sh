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

# Same setup as the CI WordpressRuntime job: enable the missed-call add-on and store its inbound secret.
$WP_CLI --allow-root --path="$WP_ROOT" eval '$l = array("tzfd-MissedCall","tzfd-Onboarding","tzfd-DashboardWidget"); TZFD_Scoped_Store::update("tz_fd_addons_active_list", $l); update_option("tz_fd_addons_active_list", $l); echo "ok\n";' >/dev/null 2>&1 || echo "FAIL addon setup -- could not enable the missed-call add-on"
$WP_CLI --allow-root --path="$WP_ROOT" eval '$s = bin2hex(random_bytes(32)); TZFD_Addon_Support::save_global_settings("missed_call", TZFD_MissedCall::defaults(), array("inbound_secret" => $s)); echo "ok\n";' >/dev/null 2>&1 || echo "FAIL addon setup -- could not store the missed-call secret"

# Several workers so the concurrent replay check can race (PHP's default single worker serialises requests).
PHP_CLI_SERVER_WORKERS=8 php -S "127.0.0.1:$PORT" -t "$WP_ROOT" >"$WORK/server.log" 2>&1 &
SERVER_PID=$!
sleep 2

# Same setup as the CI workflow: the missed-call addon is enabled and given an inbound secret before any
# suite runs, so its route and refusal checks are exercised rather than skipped.
echo "--- missed-call addon enabled with inbound secret (as CI)"
$WP_CLI --allow-root --path="$WP_ROOT" eval '$l = array("tzfd-MissedCall","tzfd-Onboarding","tzfd-DashboardWidget"); TZFD_Scoped_Store::update("tz_fd_addons_active_list", $l); update_option("tz_fd_addons_active_list", $l); echo "ok\n";' --user=1 2>&1 | tee -a "$LOG"
# Store the inbound secret through the plugin's own save path (as the CI workflow does, which encrypts secret
# fields). Provision-wp-site.sh writes its secret raw with update_option, and signed checks then get HTTP 401.
# Do not store a second secret after this, or the one the checks send no longer matches the stored one.
SECRET_LINE="$($WP_CLI --allow-root --path="$WP_ROOT" eval '$s = bin2hex(random_bytes(32)); TZFD_Addon_Support::save_global_settings("missed_call", TZFD_MissedCall::defaults(), array("inbound_secret" => $s)); echo "\nTZFD_RT_INBOUND_SECRET=" . $s . "\n";' --user=1 2>>"$LOG" | grep '^TZFD_RT_INBOUND_SECRET=')"
[ -n "$SECRET_LINE" ] && eval "$SECRET_LINE" || echo "FAIL addon setup -- could not store the missed-call secret"

echo "--- http-auth-tests"
SECRET="${TZFD_RT_INBOUND_SECRET:-}" TZFD_RT_URL="$URL" "$HERE/http-auth-tests.sh" 2>&1 | tee -a "$LOG"
HTTP_RC=${PIPESTATUS[0]}
[ "$HTTP_RC" -eq 0 ] || echo "FAIL http-auth-tests -- exit status $HTTP_RC (aborted or failed; see above)" | tee -a "$LOG"

echo "--- concurrent replay of a signed missed-call (replay-concurrency-test.sh, issue #52)"
if [ -n "${TZFD_RT_INBOUND_SECRET:-}" ]; then
  B="$URL" SECRET="$TZFD_RT_INBOUND_SECRET" N=8 "$HERE/replay-concurrency-test.sh" 2>&1 | tee -a "$LOG"
  CONC_RC=${PIPESTATUS[0]}
  [ "$CONC_RC" -eq 0 ] || echo "FAIL replay-concurrency -- exit status $CONC_RC" | tee -a "$LOG"
else
  echo "SKIP replay-concurrency -- no inbound secret was provisioned" | tee -a "$LOG"
fi

echo "--- privacy and signed-request checks (runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"

echo "--- real WordPress runtime tests (wp-runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/wp-runtime-tests.php" --user=1 2>/dev/null | tee -a "$LOG"

echo "--- authorisation and tenant checks (security-runtime-tests.php, issue #8)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/security-runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"
echo "--- agent gateway (agent-gateway-runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/agent-gateway-runtime-tests.php" --user=1 2>/dev/null | tee -a "$LOG"
echo "--- legacy AI data (legacy-ai-runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/legacy-ai-runtime-tests.php" --user=1 2>/dev/null | tee -a "$LOG"

echo "--- booking report (booking-report-runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/booking-report-runtime-tests.php" --user=1 2>/dev/null | tee -a "$LOG"
echo "--- staff access (staff-access-runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/staff-access-runtime-tests.php" --user=1 2>/dev/null | tee -a "$LOG"
echo "--- booking confirmation (booking-confirmation-runtime-tests.php, issue #184)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/booking-confirmation-runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"

echo "--- get started (setup-page-runtime-tests.php)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/setup-page-runtime-tests.php" --user=1 2>/dev/null | tee -a "$LOG"

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
