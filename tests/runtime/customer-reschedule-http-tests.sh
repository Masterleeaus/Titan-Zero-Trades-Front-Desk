#!/usr/bin/env bash
# Customer reschedule, end to end over HTTP (issue #428, runtime B13 reschedule half). Runs on the site the runner provisions.
#   1. a booking is made through the public form (calendar 1 via oversell-state.php, one booking per slot)
#   2. its signed reschedule link is issued for the stored booking version
#   3. GET the link: the change form appears and nothing changes
#   4. GET with a new date and time: the preview appears with a nonce and nothing changes
#   5. POST refused: no nonce, a forged nonce, and a nonce without confirmation; the booking is unchanged each time
#   6. POST with the preview nonce and confirmation: 303 to the completed view, the slot moves, one event is staged
#   7. the reschedule event outbox is dispatched once and the event is marked dispatched
#   8. replaying the same confirmed POST: "already saved", no second change or event
#   9. the old link is refused (409) and a link for another booking id is refused (404)
# The customer copy e-mail is off in this fixture (cu_enable_copy_to_user is not set), so no reschedule mail is expected and
# none is asserted. wp_mail is captured by a filter inside customer-reschedule-state.php.
# Env: B (base URL), WP_ROOT (site folder), WP_CLI (command). Exit 0 all checks passed; 1 a check failed; 2 setup could not run.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
B="${B:-${TZFD_RT_URL:-}}"
WP_CLI="${WP_CLI:-wp}"
[ -n "$B" ] && [ -n "${WP_ROOT:-}" ] || { echo "FAIL customer-reschedule-http -- set B and WP_ROOT"; exit 2; }
pass=0; fail=0
ok()  { echo "PASS $1 -- $2"; pass=$((pass+1)); }
bad() { echo "FAIL $1 -- $2"; fail=$((fail+1)); }
wpo() { $WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/oversell-state.php" --user=1 2>&1; }
wpr() { env "$@" $WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/customer-reschedule-state.php" --user=1 2>/dev/null; }
JAR="$(mktemp)"
trap 'rm -f "$JAR"; TZFD_OS_MODE=restore wpo >/dev/null 2>&1' EXIT

# Calendar 1 is set up by the oversell fixture (one booking per slot, a page with the form). Its restore runs on exit.
SETUP="$(TZFD_OS_MODE=setup wpo)"
PAGE_ID="$(echo "$SETUP" | sed -n 's/^TZFD_OS_PAGE=//p')"
DATE="$(echo "$SETUP" | sed -n 's/^TZFD_OS_DATE=//p')"
if [ -z "$PAGE_ID" ] || [ -z "$DATE" ]; then
  echo "FAIL customer-reschedule setup -- $SETUP"; exit 2
fi
FROM_SLOT="14:00/15:00"
FROM_START="14:00"
TO_START="16:00"
TO_SLOT="16:00/17:00"
PAGE="$B/?page_id=$PAGE_ID"
tag="$(date +%s%N | tail -c 9)"
EMAIL="reschedule-$tag@example.test"
ADMIN="$B/wp-admin/admin-post.php"

# Form render: nonce and sequence for the booking POST.
PAGE="$(curl -s -L -o /dev/null -w '%{url_effective}' --max-time 30 "$PAGE" || true)"
html="$(curl -s -L --max-time 30 -c "$JAR" -b "$JAR" "$PAGE" || true)"
nonce="$(printf '%s' "$html" | grep -o '<input name="anonce"[^>]*>' | grep -o 'value="[^"]*"' | head -1 | cut -d'"' -f2)"
seq="$(printf '%s' "$html" | grep -o 'name="tz_fd_pform_psequence" value="[^"]*"' | head -1 | sed 's/.*value="\([^"]*\)"/\1/')"
if [ -z "$nonce" ] || [ -z "$seq" ]; then
  echo "FAIL customer-reschedule setup -- the public form did not render"; exit 2
fi

# 1. Book through the public form.
code="$(curl -s --max-time 60 -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$PAGE" \
  --data-urlencode "tz_fd_frontdesk_pform_process=1" \
  --data-urlencode "tz_fd_frontdesk_id=1" \
  --data-urlencode "tz_fd_pform_psequence=$seq" \
  --data-urlencode "anonce=$nonce" \
  --data-urlencode "fieldname1$seq=$DATE $FROM_SLOT 0 1" \
  --data-urlencode "name$seq=Reschedule $tag" \
  --data-urlencode "email$seq=$EMAIL" || true)"
found="$(wpr TZFD_RS_MODE=find TZFD_RS_EMAIL="$EMAIL")"
ID="$(echo "$found" | sed -n 's/^TZFD_RS_ID=//p')"
if [ -n "$ID" ] && [ "$ID" != "0" ] && [ "$(echo "$found" | sed -n 's/^TZFD_RS_SLOT=//p')" = "$FROM_SLOT" ]; then
  ok "B13R the booking is made through the public form" "HTTP $code, booking $ID, slot $FROM_SLOT"
else
  bad "B13R the booking is made through the public form" "HTTP $code, id=${ID:-none}"
  echo "Summary (customer-reschedule-http): $pass passed, $fail failed"; exit 1
fi

# Signed link for the stored version (the same signing the customer e-mail uses).
tok="$(wpr TZFD_RS_MODE=token TZFD_RS_ID="$ID")"
VERSION="$(echo "$tok" | sed -n 's/^TZFD_RS_VERSION=//p')"
TOKEN="$(echo "$tok" | sed -n 's/^TZFD_RS_TOKEN=//p')"
ISSUED="$(echo "$tok" | sed -n 's/^TZFD_RS_ISSUED=//p')"
EXPIRES="$(echo "$tok" | sed -n 's/^TZFD_RS_EXPIRES=//p')"
if [ -z "$TOKEN" ] || [ -z "$VERSION" ] || [ -z "$ISSUED" ] || [ -z "$EXPIRES" ]; then
  bad "B13R a signed reschedule link is issued" "no token for booking $ID"; echo "Summary (customer-reschedule-http): $pass passed, $fail failed"; exit 1
fi
ok "B13R a signed reschedule link is issued for the stored version" "expires=$EXPIRES"

LINK_Q="action=titan_zero_front_desk_customer_reschedule&booking_id=$ID&form_id=1&version=$VERSION&issued=$ISSUED&expires=$EXPIRES&token=$TOKEN"
state() { wpr TZFD_RS_MODE=state TZFD_RS_ID="$ID"; }
stored_slot() { state | sed -n 's/^TZFD_RS_SLOT=//p'; }

# 2. GET the link: the change form appears and nothing changes.
page="$(curl -s --max-time 30 -b "$JAR" "$ADMIN?$LINK_Q" || true)"
if echo "$page" | grep -q "Choose a new date and start time"; then ok "B13R GET shows the change form" "no change requested yet"; else bad "B13R GET shows the change form" "form text missing"; fi
if [ "$(stored_slot)" = "$FROM_SLOT" ]; then ok "B13R GET leaves the booking unchanged" "slot $FROM_SLOT"; else bad "B13R GET leaves the booking unchanged" "slot=$(stored_slot)"; fi

# 3. GET with a new date and time: the preview with a nonce appears, nothing changes.
page="$(curl -s --max-time 30 -b "$JAR" "$ADMIN?$LINK_Q&date=$DATE&start=$TO_START" || true)"
pnonce="$(echo "$page" | sed -n 's/.*name="_wpnonce" value="\([^"]*\)".*/\1/p' | head -1)"
if echo "$page" | grep -q "Confirm appointment change" && [ -n "$pnonce" ]; then ok "B13R GET with a new time shows the preview with a nonce" "nonce present"; else bad "B13R GET with a new time shows the preview with a nonce" "preview or nonce missing"; fi
if [ "$(stored_slot)" = "$FROM_SLOT" ]; then ok "B13R the preview leaves the booking unchanged" "slot $FROM_SLOT"; else bad "B13R the preview leaves the booking unchanged" "slot=$(stored_slot)"; fi

CONFIRM_FIELDS=(--data-urlencode "action=titan_zero_front_desk_customer_reschedule" --data-urlencode "booking_id=$ID" --data-urlencode "form_id=1" \
  --data-urlencode "version=$VERSION" --data-urlencode "issued=$ISSUED" --data-urlencode "expires=$EXPIRES" --data-urlencode "token=$TOKEN" \
  --data-urlencode "date=$DATE" --data-urlencode "start=$TO_START")

# 4. POST refused: no nonce, a forged nonce, and a valid nonce without confirmation.
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -X POST "$ADMIN" "${CONFIRM_FIELDS[@]}" --data-urlencode "confirm_reschedule=1" || true)"
if [ "$c" = "403" ]; then ok "B13R POST without a nonce is refused" "HTTP 403"; else bad "B13R POST without a nonce is refused" "HTTP ${c:-000}"; fi
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -X POST "$ADMIN" "${CONFIRM_FIELDS[@]}" --data-urlencode "confirm_reschedule=1" --data-urlencode "_wpnonce=forged" || true)"
if [ "$c" = "403" ]; then ok "B13R POST with a forged nonce is refused" "HTTP 403"; else bad "B13R POST with a forged nonce is refused" "HTTP ${c:-000}"; fi
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -X POST "$ADMIN" "${CONFIRM_FIELDS[@]}" --data-urlencode "_wpnonce=$pnonce" || true)"
if [ "$c" = "400" ]; then ok "B13R POST without confirmation is refused" "HTTP 400"; else bad "B13R POST without confirmation is refused" "HTTP ${c:-000}"; fi
if [ "$(stored_slot)" = "$FROM_SLOT" ]; then ok "B13R refused POSTs leave the booking unchanged" "slot $FROM_SLOT"; else bad "B13R refused POSTs leave the booking unchanged" "slot=$(stored_slot)"; fi

# 5. Confirmed POST with the preview nonce: redirected to the completed view, the slot moves.
redir="$(curl -s --max-time 30 -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$ADMIN" "${CONFIRM_FIELDS[@]}" --data-urlencode "confirm_reschedule=1" --data-urlencode "_wpnonce=$pnonce" || true)"
case "$redir" in
  "303 "*"completed=1"*) ok "B13R confirmed POST saves the change" "HTTP 303 to the completed view" ;;
  *) bad "B13R confirmed POST saves the change" "got: ${redir:-none}" ;;
esac
if [ "$(stored_slot)" = "$TO_SLOT" ]; then ok "B13R the stored booking shows the new time" "slot $TO_SLOT"; else bad "B13R the stored booking shows the new time" "slot=$(stored_slot)"; fi
s="$(state)"
if echo "$s" | grep -q '"event_type":"rescheduled"'; then ok "B13R one reschedule event is staged with the change" "outbox row present"; else bad "B13R one reschedule event is staged with the change" "$(echo "$s" | sed -n 's/^TZFD_RS_EVENTS=//p')"; fi

# 6. Dispatch the reschedule event once.
wpr_dispatch="$(wpr TZFD_RS_MODE=dispatch)"
s="$(state)"
if echo "$s" | grep -q '"state":"dispatched"'; then ok "B13R the reschedule event is dispatched from the outbox" "$(echo "$wpr_dispatch" | sed -n 's/^TZFD_RS_DISPATCH=//p')";
else bad "B13R the reschedule event is dispatched from the outbox" "$(echo "$s" | sed -n 's/^TZFD_RS_EVENTS=//p') dispatch=$(echo "$wpr_dispatch" | sed -n 's/^TZFD_RS_DISPATCH=//p')"; fi

# 7. Replay of the same confirmed POST: no second change. The POST path redirects to the completed view, which says "already saved".
redir="$(curl -s --max-time 30 -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$ADMIN" "${CONFIRM_FIELDS[@]}" --data-urlencode "confirm_reschedule=1" --data-urlencode "_wpnonce=$pnonce" || true)"
done_url="$(echo "$redir" | sed -n 's/^303 //p')"
if [ -n "$done_url" ] && curl -s --max-time 30 -b "$JAR" "$done_url" | grep -q "already saved"; then
  ok "B13R replayed confirmation is reported as already saved" "303, completed view says already saved"
else bad "B13R replayed confirmation is reported as already saved" "got: ${redir:-none}"; fi
s="$(state)"; n="$(echo "$s" | sed -n 's/^TZFD_RS_EVENTS=//p' | grep -o '"event_type"' | wc -l)"
if [ "$n" = "1" ] && [ "$(stored_slot)" = "$TO_SLOT" ]; then ok "B13R replay stages no second event and changes nothing" "events=1, slot $TO_SLOT"; else bad "B13R replay stages no second event and changes nothing" "events=$n slot=$(stored_slot)"; fi

# 8. The old link no longer resolves to a change (its booking version has moved on), and a link for another booking is refused.
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -b "$JAR" "$ADMIN?$LINK_Q" || true)"
if [ "$c" = "409" ]; then ok "B13R the old link is refused once the booking has changed" "HTTP 409"; else bad "B13R the old link is refused once the booking has changed" "HTTP ${c:-000}"; fi
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -b "$JAR" "$ADMIN?${LINK_Q/booking_id=$ID/booking_id=$((ID+1))}" || true)"
if [ "$c" = "404" ]; then ok "B13R a link for another booking is refused" "HTTP 404"; else bad "B13R a link for another booking is refused" "HTTP ${c:-000}"; fi

restored="$(TZFD_OS_MODE=restore wpo | sed -n 's/^TZFD_OS_RESTORED=//p')"
if [ "$restored" = "1" ]; then ok "B13R the calendar row and test page are restored" "calendar 1 as before"; else bad "B13R the calendar row and test page are restored" "restore did not run"; fi

echo "Summary (customer-reschedule-http): $pass passed, $fail failed"
[ "$fail" -eq 0 ]
