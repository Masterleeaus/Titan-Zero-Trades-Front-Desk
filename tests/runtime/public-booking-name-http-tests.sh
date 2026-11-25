#!/usr/bin/env bash
# Public booking with a name and an email only (no phone), POSTed over HTTP to the public form handler (issue #420).
#   1. the public form page renders with its nonce and sequence
#   2. the POST carries name and email, and no phone field, and the handler accepts the request (HTTP 2xx or 3xx)
#   3. exactly one stored booking carries the test email
#   4. that booking keeps the name, and the phone is empty (the phone field is optional)
#   5. the bookings list reads the name as the customer
# The CAPTCHA is not bypassed. When calendar 1 requires a CAPTCHA answer, the POST is not sent and the run prints a GAP
# with the reason. Nothing on the site is changed in that case. The state helper never turns the CAPTCHA off.
# No live provider is called. Env: B (base URL), WP_ROOT (site folder), WP_CLI (command).
# Exit 0: every executed check passed (a GAP does not fail the run); 1: a check failed; 2: setup could not run.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
B="${B:-${TZFD_RT_URL:-}}"
WP_CLI="${WP_CLI:-wp}"
[ -n "$B" ] && [ -n "${WP_ROOT:-}" ] || { echo "FAIL public-booking-name-http -- set B and WP_ROOT"; exit 2; }
pass=0; fail=0; gap=0
ok()   { echo "PASS $1 -- $2"; pass=$((pass+1)); }
bad()  { echo "FAIL $1 -- $2"; fail=$((fail+1)); }
gapl() { echo "GAP $1 -- $2"; gap=$((gap+1)); }

tag="$(date +%s%N | tail -c 9)"
EMAIL="pn-$tag@example.test"
NAME="Name Only $tag"
JAR="$(mktemp)"
BODY="$(mktemp)"
# Helper call: wpe <mode>. The state helper prints KEY=value lines for this script to read.
wpe() { env TZFD_PN_MODE="$1" TZFD_PN_EMAIL="$EMAIL" $WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/public-booking-name-state.php" --user=1 2>&1; }
# Whatever happens, the test rows, the calendar row and the page are put back.
trap 'rm -f "$JAR" "$BODY"; wpe restore >/dev/null 2>&1' EXIT

SETUP="$(wpe setup)"
CAPTCHA="$(echo "$SETUP" | sed -n 's/^TZFD_PN_CAPTCHA=//p')"
if [ "$CAPTCHA" = "on" ]; then
  gapl "public POST with name and email only" "calendar 1 requires the CAPTCHA answer, so the POST was not sent; the CAPTCHA was not bypassed. Run this on a disposable calendar whose CAPTCHA is off (the form setting cv_enable_captcha) to exercise the POST."
  echo "Summary (public-booking-name-http): $pass passed, $fail failed, $gap gap"
  exit 0
fi
if [ "$CAPTCHA" != "off" ]; then echo "FAIL public-booking setup -- $SETUP"; exit 2; fi
PAGE_ID="$(echo "$SETUP" | sed -n 's/^TZFD_PN_PAGE=//p')"
DATE="$(echo "$SETUP" | sed -n 's/^TZFD_PN_DATE=//p')"
if [ -z "$PAGE_ID" ] || [ -z "$DATE" ]; then echo "FAIL public-booking setup -- $SETUP"; exit 2; fi

# 1. The form renders with its nonce and sequence. WordPress may redirect ?page_id=N to the permalink; post to the final URL.
PAGE="$B/?page_id=$PAGE_ID"
PAGE="$(curl -s -L -o /dev/null -w '%{url_effective}' --max-time 30 "$PAGE" || true)"
html="$(curl -s -L --max-time 30 -c "$JAR" -b "$JAR" "$PAGE" || true)"
nonce="$(printf '%s' "$html" | grep -o '<input name="anonce"[^>]*>' | grep -o 'value="[^"]*"' | head -1 | cut -d'"' -f2)"
seq="$(printf '%s' "$html" | grep -o 'name="tz_fd_pform_psequence" value="[^"]*"' | head -1 | sed 's/.*value="\([^"]*\)"/\1/')"
if [ -n "$nonce" ] && [ -n "$seq" ]; then
  ok "public form renders with its nonce" "sequence=$seq"
else
  bad "public form renders with its nonce" "nonce or sequence missing (page redirect target: $PAGE, length ${#html})"
  echo "Summary (public-booking-name-http): $pass passed, $fail failed, $gap gap"
  exit 1
fi

# 2. POST with the name and the email only. There is no phone field in the request at all.
code="$(curl -s --max-time 60 -o "$BODY" -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$PAGE" \
  --data-urlencode "tz_fd_frontdesk_pform_process=1" \
  --data-urlencode "tz_fd_frontdesk_id=1" \
  --data-urlencode "tz_fd_pform_psequence=$seq" \
  --data-urlencode "anonce=$nonce" \
  --data-urlencode "fieldname1$seq=$DATE 13:00/14:00 0 1" \
  --data-urlencode "name$seq=$NAME" \
  --data-urlencode "email$seq=$EMAIL" || true)"
if [[ "$code" =~ ^[23][0-9][0-9]$ ]]; then
  ok "public POST with name and email only reaches the handler" "HTTP $code"
else
  bad "public POST with name and email only reaches the handler" "HTTP ${code:-000}: $(tr '\n' ' ' < "$BODY" | head -c 160)"
fi

# 3. Exactly one stored booking carries the test email.
LOOK="$(wpe lookup)"
rows="$(echo "$LOOK" | sed -n 's/^TZFD_PN_ROWS=//p')"
if [ "$rows" = "1" ]; then
  ok "one booking is stored for the test email" "rows=1"
else
  bad "one booking is stored for the test email" "rows=${rows:-?} (handler message: $(tr '\n' ' ' < "$BODY" | head -c 160))"
fi

# 4. The stored booking keeps the name, and no phone was stored.
stored_name="$(echo "$LOOK" | sed -n 's/^TZFD_PN_NAME=//p')"
stored_phone="$(echo "$LOOK" | sed -n 's/^TZFD_PN_PHONE=//p')"
if [ "$stored_name" = "$NAME" ]; then
  ok "stored booking keeps the name from the POST" "name=$stored_name"
else
  bad "stored booking keeps the name from the POST" "expected '$NAME', stored '${stored_name:-empty}'"
fi
if [ -z "$stored_phone" ]; then
  ok "phone is optional: no phone is stored" "phone empty"
else
  bad "phone is optional: no phone is stored" "stored phone '$stored_phone' from a POST that sent none"
fi

# 5. The bookings list reads the name as the customer.
list_customer="$(echo "$LOOK" | sed -n 's/^TZFD_PN_LIST_CUSTOMER=//p')"
if [ "$list_customer" = "$NAME" ]; then
  ok "bookings list shows the name as the customer" "customer=$list_customer"
else
  bad "bookings list shows the name as the customer" "expected '$NAME', list reads '${list_customer:-empty}'"
fi

echo "Summary (public-booking-name-http): $pass passed, $fail failed, $gap gap"
[ "$fail" -eq 0 ]
