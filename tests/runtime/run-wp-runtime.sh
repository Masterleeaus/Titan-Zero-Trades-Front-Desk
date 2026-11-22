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

# Contract v1 REST route (#349): a test-only mu-plugin holds the company 7 credentials and a booking.list executor.
# Nothing here reaches the hosted service. The file lives only in the disposable site.
CONTRACT_KEY="$(openssl rand -hex 32)"
mkdir -p "$WP_ROOT/wp-content/mu-plugins"
cat > "$WP_ROOT/wp-content/mu-plugins/tzfd-test-contract.php" <<PHPEOF
<?php
/* TEST ONLY: written by tests/runtime/run-wp-runtime.sh into the disposable site. Never shipped. */
add_filter( 'tzfd_contract_credentials_v1', function ( \$creds, \$tenant_id ) {
    if ( 7 !== (int) \$tenant_id ) { return \$creds; }
    return array( 'secret' => '$CONTRACT_KEY', 'actor' => array( 'type' => 'owner', 'tenant_id' => 7, 'caps' => array( 'tz_fd_access' ) ) );
}, 10, 2 );
add_filter( 'tzfd_contract_executor_v1', function ( \$result, \$op, \$tenant_id, \$payload ) {
    if ( 'booking.list' !== \$op ) { return \$result; }
    return array( 'stub' => true, 'op' => \$op, 'tenant_id' => \$tenant_id, 'items' => array() );
}, 10, 4 );
PHPEOF
export TZFD_RT_CONTRACT_KEY="$CONTRACT_KEY"

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

echo "--- contract v1 REST route (contract-rest-tests.sh, #349)"
TZFD_RT_URL="$URL" "$HERE/contract-rest-tests.sh" 2>&1 | tee -a "$LOG"
CONTRACT_RC=${PIPESTATUS[0]}
[ "$CONTRACT_RC" -eq 0 ] || echo "FAIL contract-rest-tests -- exit status $CONTRACT_RC" | tee -a "$LOG"

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
echo "--- AI answer box form (ai-answer-box-form-runtime-tests.php, issue #185)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/ai-answer-box-form-runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"
echo "--- slot-holds schema (slot-holds-schema-runtime-tests.php, issue #186)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/slot-holds-schema-runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"
echo "--- unreadable booking row (bad-booking-row-runtime-tests.php, issue #183)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/bad-booking-row-runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"
echo "--- calendar add (calendar-add-runtime-tests.php, issue #182)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/calendar-add-runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"
echo "--- booking confirmation (booking-confirmation-runtime-tests.php, issue #184)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/booking-confirmation-runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"

echo "--- placeholder service (placeholder-service-runtime-tests.php, issue #300)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/placeholder-service-runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"
echo "--- default booking form name (booking-default-form-name-runtime-tests.php, issue #299)"
$WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/booking-default-form-name-runtime-tests.php" --user=1 2>&1 | tee -a "$LOG"
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
