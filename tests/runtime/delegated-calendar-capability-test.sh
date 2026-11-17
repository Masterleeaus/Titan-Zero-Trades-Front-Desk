#!/usr/bin/env bash
# Issue #189: a user listed in a calendar's tz_fd_user_access but without the tz_fd_access capability must be
# refused by the calendar's list screen. The page capability is the gate that does this; the per-calendar list
# check in tzfd-admin-int-message-list.inc.php is not reached for such a user.
#
# Control: the same user, once given tz_fd_access, must reach the list screen (HTTP 200). That shows the
# refusal above is the capability, not a broken page.
#
# Creates a temporary subscriber with a random password (kept in memory only, never written to disk) and
# removes it, and restores the calendar's access value, on exit. Run on the disposable test site only.
#   B=http://127.0.0.1:8080 WP_CLI="php /path/wp-cli.phar --allow-root --path=/path/wordpress" CAL=1 ./delegated-calendar-capability-test.sh
B="${B:-}"
WP_CLI="${WP_CLI:-wp}"
CAL="${CAL:-1}"
export CAL
if [ -z "$B" ]; then echo "FAIL harness -- set B (base URL of the test site)"; exit 2; fi

pass=0; fail=0
report() { echo "$1 $2 -- $3"; if [ "$1" = PASS ]; then pass=$((pass+1)); else fail=$((fail+1)); fi; }

login="tzfd_r2_deleg_$(date +%s)_$RANDOM"
pass_word="$(openssl rand -base64 24 | tr -d '/+=')"
jar="$(mktemp)"
uid=""
# The calendar's current access value (base64, or __NULL__), so cleanup puts back exactly what was there.
orig_access="$($WP_CLI eval 'global $wpdb; $v = $wpdb->get_var( $wpdb->prepare( "SELECT tz_fd_user_access FROM {$wpdb->prefix}tz_forms WHERE id = %d", (int) getenv("CAL") ) ); echo null === $v ? "__NULL__" : base64_encode( $v );' 2>/dev/null | tail -1)"
set_access() { # value (plain text) or "" for NULL
  VAL_B64="$(printf '%s' "$1" | base64 | tr -d '\n')" $WP_CLI eval 'global $wpdb; $raw = getenv("VAL_B64"); $v = "" === $raw ? null : base64_decode( $raw ); $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}tz_forms SET tz_fd_user_access = %s WHERE id = %d", $v, (int) getenv("CAL") ) );' >/dev/null 2>&1
}
cleanup() {
  if [ -n "$uid" ]; then
    $WP_CLI user remove-cap "$uid" tz_fd_access >/dev/null 2>&1 || true
    $WP_CLI user delete "$uid" --yes >/dev/null 2>&1 || true
  fi
  if [ "$orig_access" = "__NULL__" ]; then set_access ""; else set_access "$(printf '%s' "$orig_access" | base64 -d)"; fi
  rm -f "$jar"
}
trap cleanup EXIT

uid="$($WP_CLI user create "$login" "$login@example.invalid" --role=subscriber --user_pass="$pass_word" --porcelain 2>/dev/null | tail -1)"
if ! [[ "$uid" =~ ^[0-9]+$ ]]; then echo "FAIL harness -- could not create the temporary user"; exit 2; fi

# Put the user on the calendar's delegated list, in the serialized form the plugin decodes.
set_access "a:1:{i:0;i:$uid;}"

login_as() {
  curl -s -o /dev/null -c "$jar" -b "$jar" --max-time 20 \
    --data-urlencode "log=$login" --data-urlencode "pwd=$pass_word" \
    -d "wp-submit=Log In&redirect_to=/wp-admin/&testcookie=1" "$B/wp-login.php"
}
list_code() {
  local out; out="$(mktemp)"
  code="$(curl -s -o "$out" -w '%{http_code}' -b "$jar" --max-time 20 \
    "$B/wp-admin/admin.php?page=tz_fd_calendar&cal=$CAL&list=1")"
  body="$(cat "$out")"; rm -f "$out"
}

login_as
list_code
if [[ "$code" =~ ^[1-5][0-9][0-9]$ ]] && [ "$code" = 403 ] && [[ "$body" == *"not allowed to access this page"* ]]; then
  report PASS "listed user without tz_fd_access is refused by the page" "HTTP $code, WordPress capability refusal"
else
  report FAIL "listed user without tz_fd_access is refused by the page" "HTTP ${code:-empty}"
fi

$WP_CLI user add-cap "$uid" tz_fd_access >/dev/null 2>&1
login_as
list_code
if [ "$code" = 200 ]; then
  report PASS "control: same user with tz_fd_access reaches the list screen" "HTTP $code"
else
  report FAIL "control: same user with tz_fd_access reaches the list screen" "HTTP ${code:-empty}"
fi

echo "delegated-calendar-capability: $pass PASS, $fail FAIL"
[ "$fail" -eq 0 ]
