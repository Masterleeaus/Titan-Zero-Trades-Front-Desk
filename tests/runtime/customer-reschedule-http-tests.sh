#!/usr/bin/env bash
# Customer reschedule end to end over HTTP, with the customer outbox, reminder queue and mail capture (issue #463, B13 reschedule half).
# Runs on the disposable site the runner provisions.
#   R1  GET the link without a date: the change form appears and nothing changes
#   R2  GET the link with a new date and start: the preview shows the nonce and nothing changes
#   R3  a link with a forged token is refused (404)
#   R4  POST confirm without a nonce, and POST with a forged nonce: refused (403), booking unchanged
#   R5  POST confirm with the nonce: redirected (303), booking moved, receipt saved, two outbox events staged
#   R6  GET the completed view: shows that the change is already saved
#   R7  the outbox is dispatched once: both events dispatched, the customer copy captured for the booking e-mail
#   R8  replaying the confirmed POST: no second change and no second outbox event
#   R9  the reminder queue holds one reminder for the new start (skipped when the reminder add-on is off here)
# No live provider is called. wp_mail is captured by a filter inside customer-reschedule-state.php.
# Env: B (base URL), WP_ROOT (site folder), WP_CLI (command). Exit 0 all executed checks passed; 1 a check failed.
# Status: written on 2026-11-25 without a database or WP-CLI in the authoring environment. Not executed there.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
B="${B:-${TZFD_RT_URL:-}}"
WP_CLI="${WP_CLI:-wp}"
[ -n "$B" ] && [ -n "${WP_ROOT:-}" ] || { echo "FAIL customer-reschedule-http -- set B and WP_ROOT"; exit 2; }
pass=0; fail=0
ok()   { echo "PASS $1 -- $2"; pass=$((pass+1)); }
bad()  { echo "FAIL $1 -- $2"; fail=$((fail+1)); }
skip() { echo "SKIP $1 -- $2"; }
wpe()  { $WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/customer-reschedule-state.php" --user=1 2>/dev/null; }
ID=""
# Whatever happens, the fixture booking is removed and calendar 1 is put back (restore is a no-op once it has run).
trap 'TZFD_RS_MODE=restore TZFD_RS_ID="$ID" wpe >/dev/null 2>&1' EXIT

SETUP="$(TZFD_RS_MODE=setup wpe)"
val() { printf '%s\n' "$SETUP" | sed -n "s/^TZFD_RS_$1=//p" | head -1; }
ID="$(val ID)"; FORM="$(val FORM)"; EMAIL="$(val EMAIL)"; TOKEN="$(val TOKEN)"; ISSUED="$(val ISSUED)"; EXPIRES="$(val EXPIRES)"
VERSION="$(val VERSION)"; OLDDATE="$(val OLDDATE)"; OLDSTART="$(val OLDSTART)"; NEWDATE="$(val NEWDATE)"; NEWSTART="$(val NEWSTART)"; REMINDER="$(val REMINDER)"
if [ -z "$ID" ] || [ -z "$TOKEN" ] || [ -z "$VERSION" ] || [ -z "$NEWDATE" ]; then
  bad "R0 setup" "could not store a scheduler-shaped booking and issue a link: $(printf '%s' "$SETUP" | head -c 300)"
  echo "Summary (customer-reschedule-http): $pass passed, $fail failed"; exit 1
fi
ok "R0 setup: booking $ID stored in scheduler form, version-bound reschedule link issued" "old=$OLDDATE $OLDSTART new=$NEWDATE $NEWSTART reminder=$REMINDER"

QS="action=titan_zero_front_desk_customer_reschedule&booking_id=$ID&form_id=$FORM&version=$VERSION&issued=$ISSUED&expires=$EXPIRES&token=$TOKEN"
LINK="$B/wp-admin/admin-post.php?$QS"
POST_URL="$B/wp-admin/admin-post.php"
state_val() { TZFD_RS_MODE=state TZFD_RS_ID="$ID" wpe | sed -n "s/^TZFD_RS_$1=//p" | head -1; }
unchanged() { [ "$(state_val APP_DATE)" = "$OLDDATE" ] && [ "$(state_val RECEIPT)" = "0" ]; }

# R1. GET without a date shows the change form and changes nothing.
page="$(curl -s --max-time 30 "$LINK" || true)"
if echo "$page" | grep -q "Choose a new date and start time"; then ok "R1 GET shows the change form" "no date yet"; else bad "R1 GET shows the change form" "form text missing"; fi
if unchanged; then ok "R1 GET leaves the booking unchanged" "date $OLDDATE"; else bad "R1 GET leaves the booking unchanged" "date=$(state_val APP_DATE)"; fi

# R2. GET with a new date and start shows the preview with a nonce, and changes nothing.
page="$(curl -s --max-time 30 "$LINK&date=$NEWDATE&start=$NEWSTART" || true)"
nonce="$(echo "$page" | sed -n 's/.*name="_wpnonce" value="\([^"]*\)".*/\1/p' | head -1)"
if echo "$page" | grep -q "Review the new appointment time" && echo "$page" | grep -q "Confirm appointment change" && [ -n "$nonce" ]; then
  ok "R2 GET preview shows the new time and a confirmation nonce" "nonce present"; else bad "R2 GET preview shows the new time and a confirmation nonce" "preview or nonce missing"; fi
if unchanged; then ok "R2 preview leaves the booking unchanged" "date $OLDDATE"; else bad "R2 preview leaves the booking unchanged" "date=$(state_val APP_DATE)"; fi

# R3. A forged token is refused.
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' "$B/wp-admin/admin-post.php?action=titan_zero_front_desk_customer_reschedule&booking_id=$ID&form_id=$FORM&version=$VERSION&issued=$ISSUED&expires=$EXPIRES&token=$(printf '0%.0s' $(seq 1 64))&date=$NEWDATE&start=$NEWSTART" || true)"
if [ "$c" = "404" ]; then ok "R3 a forged token is refused" "HTTP 404"; else bad "R3 a forged token is refused" "HTTP ${c:-000}"; fi

# R4. Confirm POST without a nonce, and with a forged nonce: both refused, nothing changes.
BODY=(--data-urlencode "action=titan_zero_front_desk_customer_reschedule" --data-urlencode "booking_id=$ID" --data-urlencode "form_id=$FORM"
  --data-urlencode "version=$VERSION" --data-urlencode "issued=$ISSUED" --data-urlencode "expires=$EXPIRES" --data-urlencode "token=$TOKEN"
  --data-urlencode "date=$NEWDATE" --data-urlencode "start=$NEWSTART" --data-urlencode "confirm_reschedule=1")
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -X POST "$POST_URL" "${BODY[@]}" || true)"
if [ "$c" = "403" ]; then ok "R4 confirm POST without a nonce is refused" "HTTP 403"; else bad "R4 confirm POST without a nonce is refused" "HTTP ${c:-000}"; fi
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -X POST "$POST_URL" "${BODY[@]}" --data-urlencode "_wpnonce=forged" || true)"
if [ "$c" = "403" ]; then ok "R4 confirm POST with a forged nonce is refused" "HTTP 403"; else bad "R4 confirm POST with a forged nonce is refused" "HTTP ${c:-000}"; fi
if unchanged; then ok "R4 refused confirm POSTs leave the booking unchanged" "date $OLDDATE"; else bad "R4 refused confirm POSTs leave the booking unchanged" "date=$(state_val APP_DATE)"; fi

# R5. Confirm POST with the nonce: moved, receipt saved, two events staged.
res="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}|%{redirect_url}' -X POST "$POST_URL" "${BODY[@]}" --data-urlencode "_wpnonce=$nonce" || true)"
case "$res" in 303\|*completed=1*) ok "R5 confirmed POST saves the change" "HTTP 303 to the completed view";; *) bad "R5 confirmed POST saves the change" "result ${res:-none}";; esac
if [ "$(state_val APP_DATE)" = "$NEWDATE" ] && [ "$(state_val APP_SLOT)" = "11:00/12:00" ]; then ok "R5 the stored booking shows the new date and slot" "$NEWDATE 11:00/12:00"; else bad "R5 the stored booking shows the new date and slot" "date=$(state_val APP_DATE) slot=$(state_val APP_SLOT)"; fi
if [ "$(state_val STATUS)" = "Approved" ] && [ "$(state_val RECEIPT)" = "1" ]; then ok "R5 status is kept and the receipt is saved" "Approved, receipt=1"; else bad "R5 status is kept and the receipt is saved" "status=$(state_val STATUS) receipt=$(state_val RECEIPT)"; fi
s="$(TZFD_RS_MODE=state TZFD_RS_ID="$ID" wpe)"
if echo "$s" | grep -q '"event_type":"rescheduled"' && echo "$s" | grep -q '"event_type":"reschedule_email"' && [ "$(echo "$s" | grep -o '"state":"pending"' | wc -l)" = "2" ]; then
  ok "R5 the reschedule and its customer e-mail are staged in the outbox" "2 pending events"; else bad "R5 the reschedule and its customer e-mail are staged in the outbox" "$(echo "$s" | sed -n 's/^TZFD_RS_EVENTS=//p')"; fi

# R6. GET of the completed view says the change is already saved.
page="$(curl -s --max-time 30 "$LINK&date=$NEWDATE&start=$NEWSTART&completed=1" || true)"
if echo "$page" | grep -q "already saved"; then ok "R6 completed view shows the change is saved" "already saved"; else bad "R6 completed view shows the change is saved" "text missing"; fi

# R7. Dispatch the customer outbox once. The reschedule e-mail reaches wp_mail, captured, addressed to the booking e-mail.
d="$(TZFD_RS_MODE=dispatch wpe)"
s="$(TZFD_RS_MODE=state TZFD_RS_ID="$ID" wpe)"
mail_to="$(echo "$d" | sed -n 's/^TZFD_RS_MAIL_TO=//p')"
if [ "$(echo "$s" | grep -o '"state":"dispatched"' | wc -l)" = "2" ]; then ok "R7 both reschedule events are dispatched" "$(echo "$d" | sed -n 's/^TZFD_RS_DISPATCH=//p')"; else bad "R7 both reschedule events are dispatched" "$(echo "$s" | sed -n 's/^TZFD_RS_EVENTS=//p')"; fi
if echo "$mail_to" | grep -q "$EMAIL"; then ok "R7 the customer copy is captured for the booking e-mail" "mail to=$EMAIL, total=$(echo "$d" | sed -n 's/^TZFD_RS_MAIL=//p')"; else bad "R7 the customer copy is captured for the booking e-mail" "mail to=[$mail_to]"; fi

# R8. Replaying the confirmed POST: no second change, no second event.
res="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}|%{redirect_url}' -X POST "$POST_URL" "${BODY[@]}" --data-urlencode "_wpnonce=$nonce" || true)"
s="$(TZFD_RS_MODE=state TZFD_RS_ID="$ID" wpe)"
n="$(echo "$s" | grep -o '"event_type"' | wc -l)"
if [ "$(state_val APP_DATE)" = "$NEWDATE" ] && [ "$n" = "2" ]; then ok "R8 replayed confirmation changes nothing" "result ${res}, events=2"; else bad "R8 replayed confirmation changes nothing" "date=$(state_val APP_DATE) events=$n result=$res"; fi

# R9. Reminder queue: one reminder, for the new start (lead time from the reminder add-on).
if [ "$REMINDER" = "1" ]; then
  rem="$(state_val REMINDERS)"; exp="$(state_val REMINDER_EXPECTED)"
  if [ -n "$rem" ] && [ "$rem" = "$exp" ]; then ok "R9 one reminder is queued for the new start" "timestamp=$rem"; else bad "R9 one reminder is queued for the new start" "queued=[$rem] expected=$exp"; fi
else
  skip "R9 reminder queue after reschedule" "the reminder add-on is not active with a lead time on this site"
fi

echo "Summary (customer-reschedule-http): $pass passed, $fail failed"
[ "$fail" -eq 0 ]
