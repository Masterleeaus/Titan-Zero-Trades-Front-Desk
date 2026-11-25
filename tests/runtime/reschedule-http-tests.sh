#!/usr/bin/env bash
# Customer reschedule end to end over HTTP, with the reminder outbox and mail capture (issue #463, B13 reschedule half).
# Runs on the disposable site the runner provisions.
#   1. a booking is made through the public form at 09:00, with its security code (the CAPTCHA stays on)
#   2. the reschedule link for that booking is issued; GET previews 11:00 and changes nothing
#   3. POST confirm with a forged nonce is refused and the booking does not move
#   4. POST confirm with the nonce: the booking moves to 11:00, and the change and the customer e-mail are staged once
#   5. the customer event outbox is dispatched: the e-mail is captured for the customer and the events are dispatched
#   6. replaying the confirmed POST changes nothing and stages nothing more
#   7. the calendar row and the page are put back
# The security code is read the way a visitor gets it (see oversell-http-tests.sh). No live provider is called.
# Env: B (base URL), WP_ROOT (site folder), WP_CLI (command). Exit 0 all checks passed; 1 a check failed; 2 setup could not run.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
B="${B:-${TZFD_RT_URL:-}}"
WP_CLI="${WP_CLI:-wp}"
[ -n "$B" ] && [ -n "${WP_ROOT:-}" ] || { echo "FAIL reschedule-http -- set B and WP_ROOT"; exit 2; }
pass=0; fail=0
ok()  { echo "PASS $1 -- $2"; pass=$((pass+1)); }
bad() { echo "FAIL $1 -- $2"; fail=$((fail+1)); }
wpe() { $WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/reschedule-state.php" --user=1 2>&1; }
JAR="$(mktemp)"
RESP="$(mktemp)"
trap 'rm -f "$JAR" "$RESP"; TZFD_RS_MODE=restore wpe >/dev/null 2>&1' EXIT

SETUP="$(TZFD_RS_MODE=setup wpe)"
PAGE_ID="$(echo "$SETUP" | sed -n 's/^TZFD_RS_PAGE=//p')"
DATE="$(echo "$SETUP" | sed -n 's/^TZFD_RS_DATE=//p')"
if [ -z "$PAGE_ID" ] || [ -z "$DATE" ]; then
  echo "FAIL reschedule setup -- $SETUP"; exit 2
fi
OLD_SLOT="09:00/10:00"
NEW_START="11:00"
NEW_SLOT="11:00/12:00"
PAGE="$B/?page_id=$PAGE_ID"
tag="$(date +%s%N | tail -c 9)"
EMAIL="reschedule-$tag@example.test"

field() { echo "$1" | sed -n "s/^$2=//p"; }
slot_stored() { # prints the stored slot of booking $1
  TZFD_RS_MODE=state TZFD_RS_ID="$1" wpe | sed -n 's/^TZFD_RS_SLOT=//p'
}

# 1. Book through the public form, as a visitor does, and read back the stored booking.
PAGE="$(curl -s -L -o /dev/null -w '%{url_effective}' --max-time 30 "$PAGE" || true)"
html="$(curl -s -L --max-time 30 -c "$JAR" -b "$JAR" "$PAGE" || true)"
nonce="$(printf '%s' "$html" | grep -o '<input name="anonce"[^>]*>' | grep -o 'value="[^"]*"' | head -1 | cut -d'"' -f2)"
seq="$(printf '%s' "$html" | grep -o 'name="tz_fd_pform_psequence" value="[^"]*"' | head -1 | sed 's/.*value="\([^"]*\)"/\1/')"
captcha_src="$(printf '%s' "$html" | grep -o 'src="[^"]*tz_fd_frontdesk_captcha=captcha[^"]*"' | head -1 | sed 's/^src="//; s/"$//; s/&amp;/\&/g')"
if [ -z "$nonce" ] || [ -z "$seq" ] || [ -z "$captcha_src" ]; then
  bad "B13 reschedule: the public form renders with its nonce and security code" "missing nonce, sequence or code image"
  echo "Summary (reschedule-http): $pass passed, $fail failed"; exit 1
fi

new_code() {
  curl -s --max-time 30 -c "$JAR" -b "$JAR" -o /dev/null "$captcha_src" || return 0
  local id
  id="$(awk '$6 ~ /^rand_code/ {print $7}' "$JAR" | tail -1)"
  [ -n "$id" ] || return 0
  TZFD_RS_MODE=answer TZFD_RS_CHALLENGE="$id" wpe | sed -n 's/^TZFD_RS_CODE=//p'
}

answer="$(new_code)"
code="$(curl -s --max-time 60 -o "$RESP" -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$PAGE" \
  --data-urlencode "tz_fd_frontdesk_pform_process=1" \
  --data-urlencode "tz_fd_frontdesk_id=1" \
  --data-urlencode "tz_fd_pform_psequence=$seq" \
  --data-urlencode "anonce=$nonce" \
  --data-urlencode "hdcaptcha_tz_fd_frontdesk_post=$answer" \
  --data-urlencode "fieldname1$seq=$DATE $OLD_SLOT 0 1" \
  --data-urlencode "name$seq=Reschedule Customer" \
  --data-urlencode "email$seq=$EMAIL" || true)"
issue="$(TZFD_RS_MODE=issue TZFD_RS_EMAIL="$EMAIL" wpe)"
if [ "$(field "$issue" TZFD_RS_ISSUE)" = "ok" ]; then
  ok "B13 reschedule: the public form stores the booking" "HTTP $code, booking $(field "$issue" TZFD_RS_ID)"
else
  bad "B13 reschedule: the public form stores the booking" "HTTP $code, issue=$(field "$issue" TZFD_RS_ISSUE)"
  echo "Summary (reschedule-http): $pass passed, $fail failed"; exit 1
fi
ID="$(field "$issue" TZFD_RS_ID)"
VERSION="$(field "$issue" TZFD_RS_VERSION)"
TOKEN="$(field "$issue" TZFD_RS_TOKEN)"
ISSUED="$(field "$issue" TZFD_RS_ISSUED)"
EXPIRES="$(field "$issue" TZFD_RS_EXPIRES)"
FORM_ID=1
ENDPOINT="$B/wp-admin/admin-post.php"
LINK="$ENDPOINT?action=titan_zero_front_desk_customer_reschedule&booking_id=$ID&form_id=$FORM_ID&version=$VERSION&issued=$ISSUED&expires=$EXPIRES&token=$TOKEN"
# The fields a confirm POST carries, as the preview form posts them.
confirm_fields=(--data-urlencode "action=titan_zero_front_desk_customer_reschedule" --data-urlencode "booking_id=$ID" --data-urlencode "form_id=$FORM_ID"
  --data-urlencode "version=$VERSION" --data-urlencode "issued=$ISSUED" --data-urlencode "expires=$EXPIRES" --data-urlencode "token=$TOKEN"
  --data-urlencode "date=$DATE" --data-urlencode "start=$NEW_START")

# 2. GET previews the new time with a confirmation nonce, and changes nothing.
page="$(curl -s --max-time 30 "$LINK&date=$DATE&start=$NEW_START" || true)"
preview_nonce="$(echo "$page" | sed -n 's/.*name="_wpnonce" value="\([^"]*\)".*/\1/p' | head -1)"
if echo "$page" | grep -q "Review the new appointment time" && [ -n "$preview_nonce" ]; then ok "B13 reschedule: GET previews the new time" "nonce present"; else bad "B13 reschedule: GET previews the new time" "preview or nonce missing"; fi
s="$(slot_stored "$ID")"; if [ "$s" = "$OLD_SLOT" ]; then ok "B13 reschedule: the preview leaves the booking unchanged" "slot $s"; else bad "B13 reschedule: the preview leaves the booking unchanged" "slot ${s:-?}"; fi

# 3. POST confirm with a forged nonce: refused with 403, and the booking does not move.
c="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' -X POST "$ENDPOINT" "${confirm_fields[@]}" --data-urlencode "_wpnonce=forged" --data-urlencode "confirm_reschedule=1" || true)"
if [ "$c" = "403" ]; then ok "B13 reschedule: a confirm with a forged nonce is refused" "HTTP 403"; else bad "B13 reschedule: a confirm with a forged nonce is refused" "HTTP ${c:-000}"; fi
s="$(slot_stored "$ID")"; if [ "$s" = "$OLD_SLOT" ]; then ok "B13 reschedule: the refused confirm leaves the booking unchanged" "slot $s"; else bad "B13 reschedule: the refused confirm leaves the booking unchanged" "slot ${s:-?}"; fi

# 4. POST confirm with the nonce: the change is saved and the POST redirects to the completed view (303).
redirect="$(curl -s --max-time 60 -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$ENDPOINT" "${confirm_fields[@]}" --data-urlencode "_wpnonce=$preview_nonce" --data-urlencode "confirm_reschedule=1" || true)"
case "$redirect" in
  "303 "*completed=1*) ok "B13 reschedule: confirmed POST saves the change" "$redirect" ;;
  *) bad "B13 reschedule: confirmed POST saves the change" "got ${redirect:-nothing}" ;;
esac
s="$(slot_stored "$ID")"
if [ "$s" = "$NEW_SLOT" ]; then ok "B13 reschedule: the stored booking shows the new slot" "slot $s"; else bad "B13 reschedule: the stored booking shows the new slot" "slot ${s:-?}, expected $NEW_SLOT"; fi
st="$(TZFD_RS_MODE=state TZFD_RS_ID="$ID" wpe)"
if echo "$st" | grep -q '"event_type":"rescheduled"' && echo "$st" | grep -q '"event_type":"reschedule_email"'; then ok "B13 reschedule: the change and the customer e-mail are staged once" "outbox rows present"; else bad "B13 reschedule: the change and the customer e-mail are staged once" "$(field "$st" TZFD_RS_EVENTS)"; fi

# 5. Dispatch the outbox: the customer e-mail is captured for the customer, and the events are dispatched.
d="$(TZFD_RS_MODE=dispatch wpe)"
if echo "$(field "$d" TZFD_RS_MAIL)" | grep -q "$EMAIL"; then ok "B13 reschedule: the customer e-mail is captured for the customer" "recipient matches"; else bad "B13 reschedule: the customer e-mail is captured for the customer" "$(field "$d" TZFD_RS_MAIL)"; fi
st="$(TZFD_RS_MODE=state TZFD_RS_ID="$ID" wpe)"
if [ "$(echo "$st" | grep -o '"state":"dispatched"' | wc -l)" -ge 2 ]; then ok "B13 reschedule: both outbox events are dispatched" "$(field "$d" TZFD_RS_DISPATCH)"; else bad "B13 reschedule: both outbox events are dispatched" "$(field "$st" TZFD_RS_EVENTS) dispatch=$(field "$d" TZFD_RS_DISPATCH)"; fi

# 6. Replay of the confirmed POST: the saved change is reported, nothing changes and nothing is staged again.
redirect="$(curl -s --max-time 60 -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$ENDPOINT" "${confirm_fields[@]}" --data-urlencode "_wpnonce=$preview_nonce" --data-urlencode "confirm_reschedule=1" || true)"
s="$(slot_stored "$ID")"
n="$(TZFD_RS_MODE=state TZFD_RS_ID="$ID" wpe | sed -n 's/^TZFD_RS_EVENTS=//p' | grep -o '"event_type"' | wc -l)"
if [ "$s" = "$NEW_SLOT" ] && [ "$n" = "2" ]; then ok "B13 reschedule: replayed confirmation changes nothing" "slot $s, events=2 ($redirect)"; else bad "B13 reschedule: replayed confirmation changes nothing" "slot ${s:-?}, events=${n:-?} ($redirect)"; fi

# 7. Put back the calendar row and remove the page.
restored="$(TZFD_RS_MODE=restore wpe | sed -n 's/^TZFD_RS_RESTORED=//p')"
if [ "$restored" = "1" ]; then ok "B13 reschedule: calendar row and test page are restored" "calendar 1 as before"; else bad "B13 reschedule: calendar row and test page are restored" "restore did not run"; fi

echo "Summary (reschedule-http): $pass passed, $fail failed"
[ "$fail" -eq 0 ]
