#!/usr/bin/env bash
# Customer cancellation, end to end over HTTP (issue #334, B13). Runs on the disposable site the runner provisions.
#   1. a booking is stored with full form data and a signed, version-bound cancellation link is issued
#   2. GET the link: the confirmation page appears and nothing changes
#   3. POST without confirmation, and POST with a bad nonce: refused, booking unchanged
#   4. POST with the nonce and confirmation: booking cancelled, one customer event staged
#   5. the customer event outbox is dispatched once: the event is marked dispatched, and any customer mail is captured
#   6. replaying the same POST: refused, nothing changes twice
# No live provider is called. wp_mail is captured by a filter inside customer-cancel-state.php.
# Env: B (base URL), WP_ROOT (site folder), WP_CLI (command). Exit 0 all checks passed; 1 a check failed.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
B="${B:-${TZFD_RT_URL:-}}"
WP_CLI="${WP_CLI:-wp}"
[ -n "$B" ] && [ -n "${WP_ROOT:-}" ] || { echo "FAIL customer-cancel-http -- set B and WP_ROOT"; exit 2; }
pass=0; fail=0
ok()  { echo "PASS $1 -- $2"; pass=$((pass+1)); }
bad() { echo "FAIL $1 -- $2"; fail=$((fail+1)); }
state() { # prints the TZFD_B13_* lines for one booking
  TZFD_B13_MODE=state TZFD_B13_ID="$ID" $WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/customer-cancel-state.php" --user=1 2>/dev/null
}

SETUP="$(TZFD_B13_MODE=setup $WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/customer-cancel-state.php" --user=1 2>/dev/null)"
ID="$(echo "$SETUP" | sed -n 's/^TZFD_B13_ID=//p')"
TOKEN="$(echo "$SETUP" | sed -n 's/^TZFD_B13_TOKEN=//p')"
EXPIRES="$(echo "$SETUP" | sed -n 's/^TZFD_B13_EXPIRES=//p')"
if [ -z "$ID" ] || [ -z "$TOKEN" ] || [ -z "$EXPIRES" ]; then
  echo "FAIL B13 setup -- could not store a booking and issue a link"; exit 1
fi
ok "B13 setup: booking $ID stored with full form data and a version-bound link" "expires=$EXPIRES"

LINK="$B/wp-admin/admin-post.php?action=titan_zero_front_desk_customer_cancel&booking_id=$ID&token=$TOKEN&expires=$EXPIRES"
POST_URL="$B/wp-admin/admin-post.php"

# 2. GET shows the confirmation form with a nonce, and changes nothing.
page="$(curl -s --max-time 30 "$LINK" || true)"
nonce="$(echo "$page" | sed -n 's/.*name="_wpnonce" value="\([^"]*\)".*/\1/p' | head -1)"
if echo "$page" | grep -q "Confirm cancellation" && [ -n "$nonce" ]; then ok "B13 GET shows the confirmation form" "nonce present"; else bad "B13 GET shows the confirmation form" "form or nonce missing"; fi
s="$(state)"; if echo "$s" | grep -q "TZFD_B13_CANCELLED=0"; then ok "B13 GET leaves the booking unchanged" "not cancelled"; else bad "B13 GET leaves the booking unchanged" "$s"; fi

# 3a. POST without confirmation: refused.
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -X POST "$POST_URL" --data-urlencode "action=titan_zero_front_desk_customer_cancel" --data-urlencode "booking_id=$ID" --data-urlencode "token=$TOKEN" --data-urlencode "expires=$EXPIRES" --data-urlencode "_wpnonce=$nonce" || true)"
if [ "$c" = "400" ]; then ok "B13 POST without confirmation is refused" "HTTP 400"; else bad "B13 POST without confirmation is refused" "HTTP ${c:-000}"; fi

# 3b. POST with a wrong nonce: refused with 403.
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -X POST "$POST_URL" --data-urlencode "action=titan_zero_front_desk_customer_cancel" --data-urlencode "booking_id=$ID" --data-urlencode "token=$TOKEN" --data-urlencode "expires=$EXPIRES" --data-urlencode "_wpnonce=forged" --data-urlencode "confirm_cancel=1" || true)"
if [ "$c" = "403" ]; then ok "B13 POST with a forged nonce is refused" "HTTP 403"; else bad "B13 POST with a forged nonce is refused" "HTTP ${c:-000}"; fi
s="$(state)"; if echo "$s" | grep -q "TZFD_B13_CANCELLED=0"; then ok "B13 refused POSTs leave the booking unchanged" "not cancelled"; else bad "B13 refused POSTs leave the booking unchanged" "$s"; fi

# 4. POST with nonce and confirmation: cancelled.
body="$(curl -s --max-time 30 -w '\n%{http_code}' -X POST "$POST_URL" --data-urlencode "action=titan_zero_front_desk_customer_cancel" --data-urlencode "booking_id=$ID" --data-urlencode "token=$TOKEN" --data-urlencode "expires=$EXPIRES" --data-urlencode "_wpnonce=$nonce" --data-urlencode "confirm_cancel=1" || true)"
code="$(echo "$body" | tail -1)"
if [ "$code" = "200" ] && echo "$body" | grep -q "has been cancelled"; then ok "B13 confirmed POST cancels the booking" "HTTP 200"; else bad "B13 confirmed POST cancels the booking" "HTTP ${code:-000}"; fi
s="$(state)"
if echo "$s" | grep -q "TZFD_B13_CANCELLED=1"; then ok "B13 the stored booking shows Cancelled" "apps updated"; else bad "B13 the stored booking shows Cancelled" "$s"; fi
if echo "$s" | grep -q '"event_type":"cancelled"'; then ok "B13 one customer event is staged with the cancellation" "outbox row present"; else bad "B13 one customer event is staged with the cancellation" "$s"; fi

# 5. Dispatch the customer event outbox once.
d="$(TZFD_B13_MODE=dispatch TZFD_B13_ID="$ID" $WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/customer-cancel-state.php" --user=1 2>/dev/null)"
s="$(state)"
if echo "$s" | grep -q '"state":"dispatched"'; then ok "B13 the customer event is dispatched from the outbox" "$(echo "$d" | sed -n 's/^TZFD_B13_DISPATCH=//p')";
else bad "B13 the customer event is dispatched from the outbox" "$(echo "$s" | sed -n 's/^TZFD_B13_EVENTS=//p') dispatch=$(echo "$d" | sed -n 's/^TZFD_B13_DISPATCH=//p')"; fi

# 6. Replay of the same confirmed POST: refused, no second change.
body="$(curl -s --max-time 30 -w '\n%{http_code}' -X POST "$POST_URL" --data-urlencode "action=titan_zero_front_desk_customer_cancel" --data-urlencode "booking_id=$ID" --data-urlencode "token=$TOKEN" --data-urlencode "expires=$EXPIRES" --data-urlencode "_wpnonce=$nonce" --data-urlencode "confirm_cancel=1" || true)"
code="$(echo "$body" | tail -1)"
# The link is bound to the booking version, and the cancel changed the version, so the old link no longer resolves (404).
if [ "$code" = "404" ]; then ok "B13 replayed confirmation is refused" "HTTP 404, link bound to the old booking version"; else bad "B13 replayed confirmation is refused" "HTTP ${code:-000}"; fi
s="$(state)"; n="$(echo "$s" | sed -n 's/^TZFD_B13_EVENTS=//p' | grep -o '"event_type"' | wc -l)"
if [ "$n" = "1" ]; then ok "B13 replay stages no second customer event" "events=1"; else bad "B13 replay stages no second customer event" "events=$n"; fi

echo "Summary (customer-cancel-http): $pass passed, $fail failed"
[ "$fail" -eq 0 ]
