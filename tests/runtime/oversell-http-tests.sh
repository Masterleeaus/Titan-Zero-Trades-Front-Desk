#!/usr/bin/env bash
# Oversell prevention through the public booking form, over HTTP (issue #389, runtime A18; issue #463). Runs on the site the runner provisions.
#   1. calendar 1 is set to one booking per slot, and a page with its shortcode is published (the CAPTCHA stays on)
#   2. the page shows the booking form with its nonce and its security code image
#   3. a POST with a wrong security code is refused and nothing is stored
#   4. the first POST for a free slot, with the code the visitor was shown, is accepted and one booking is stored
#   5. a second POST for the same slot is refused and the stored count stays at one
#   6. a POST for a different free slot is still accepted (the guard refuses only what is full)
#   7. the calendar row and the page are put back
# The security code is read the way a visitor gets it: the image is requested with the visitor's cookie jar, which
# receives the challenge id in the rand_code cookie. The answer for that id is read back by oversell-state.php (the
# server's own challenge store), because the image itself cannot be read here. The check is not disabled.
# No live provider is called. Env: B (base URL), WP_ROOT (site folder), WP_CLI (command).
# Exit 0 all checks passed; 1 a check failed; 2 the setup could not run.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
B="${B:-${TZFD_RT_URL:-}}"
WP_CLI="${WP_CLI:-wp}"
[ -n "$B" ] && [ -n "${WP_ROOT:-}" ] || { echo "FAIL oversell-http -- set B and WP_ROOT"; exit 2; }
pass=0; fail=0
ok()  { echo "PASS $1 -- $2"; pass=$((pass+1)); }
bad() { echo "FAIL $1 -- $2"; fail=$((fail+1)); }
wpe() { $WP_CLI --allow-root --path="$WP_ROOT" eval-file "$HERE/oversell-state.php" --user=1 2>&1; }
JAR="$(mktemp)"
RESP="$(mktemp)"
# Whatever happens, the calendar row and page are put back (restore is a no-op once it has run).
trap 'rm -f "$JAR" "$RESP"; TZFD_OS_MODE=restore wpe >/dev/null 2>&1' EXIT

SETUP="$(TZFD_OS_MODE=setup wpe)"
PAGE_ID="$(echo "$SETUP" | sed -n 's/^TZFD_OS_PAGE=//p')"
DATE="$(echo "$SETUP" | sed -n 's/^TZFD_OS_DATE=//p')"
if [ -z "$PAGE_ID" ] || [ -z "$DATE" ]; then
  echo "FAIL oversell setup -- $SETUP"; exit 2
fi
SLOT="09:00/10:00"
SLOT2="11:00/12:00"
PAGE="$B/?page_id=$PAGE_ID"
tag="$(date +%s%N | tail -c 9)"

count() { # prints the occupied count for $1 (slot)
  TZFD_OS_MODE=count TZFD_OS_DATE="$DATE" TZFD_OS_SLOT="$1" wpe | sed -n 's/^TZFD_OS_OCCUPIED=//p'
}

# 2. The form renders with its booking nonce, its submission fields and its security code image.
# WordPress may redirect ?page_id=N to the page's permalink (canonical redirect); follow it and post to the final URL.
PAGE="$(curl -s -L -o /dev/null -w '%{url_effective}' --max-time 30 "$PAGE" || true)"
html="$(curl -s -L --max-time 30 -c "$JAR" -b "$JAR" "$PAGE" || true)"
nonce="$(printf '%s' "$html" | grep -o '<input name="anonce"[^>]*>' | grep -o 'value="[^"]*"' | head -1 | cut -d'"' -f2)"
seq="$(printf '%s' "$html" | grep -o 'name="tz_fd_pform_psequence" value="[^"]*"' | head -1 | sed 's/.*value="\([^"]*\)"/\1/')"
# The security code image URL, as the page prints it (HTML-escaped ampersands turned back into &).
captcha_src="$(printf '%s' "$html" | grep -o 'src="[^"]*tz_fd_frontdesk_captcha=captcha[^"]*"' | head -1 | sed 's/^src="//; s/"$//; s/&amp;/\&/g')"
if [ -n "$nonce" ] && [ -n "$seq" ] && [ -n "$captcha_src" ]; then ok "A18 the public form renders with its nonce and security code" "sequence=$seq"; else
  # Diagnostics for the log: what the page returned, without any page content.
  diag="$(curl -s --max-time 30 -o /dev/null -w '%{http_code} %{redirect_url}' "$PAGE" || true)"
  text="$(printf '%s' "$html" | sed 's/<[^>]*>/ /g' | tr -s ' \n\t' ' ' | head -c 240)"
  bad "A18 the public form renders with its nonce and security code" "nonce, sequence or code image missing (page HTTP and redirect: $diag, length ${#html}, form markup: $(printf '%s' "$html" | grep -c 'tz_fd_frontdesk_pform'), text: $text)"
  echo "Summary (oversell-http): $pass passed, $fail failed"; exit 1
fi

# Fetches a fresh security code image with the visitor's cookie jar, then prints the code that image shows.
# A booking clears its challenge, so every submission gets a new image and a new code.
new_code() {
  curl -s --max-time 30 -c "$JAR" -b "$JAR" -o /dev/null "$captcha_src" || return 0
  local id
  id="$(awk '$6 ~ /^rand_code/ {print $7}' "$JAR" | tail -1)"
  [ -n "$id" ] || return 0
  TZFD_OS_MODE=answer TZFD_OS_CHALLENGE="$id" wpe | sed -n 's/^TZFD_OS_CODE=//p'
}

# book <slot> <customer tag> <right|wrong>: posts the form. The body goes to $RESP; prints the HTTP code.
book() {
  local answer
  if [ "$3" = "wrong" ]; then
    answer="iiiiiii" # "i" is not in the code alphabet, so this can never be the right answer
  else
    answer="$(new_code)"
  fi
  curl -s --max-time 60 -o "$RESP" -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$PAGE" \
    --data-urlencode "tz_fd_frontdesk_pform_process=1" \
    --data-urlencode "tz_fd_frontdesk_id=1" \
    --data-urlencode "tz_fd_pform_psequence=$seq" \
    --data-urlencode "anonce=$nonce" \
    --data-urlencode "hdcaptcha_tz_fd_frontdesk_post=$answer" \
    --data-urlencode "fieldname1$seq=$DATE $1 0 1" \
    --data-urlencode "name$seq=Oversell $2" \
    --data-urlencode "email$seq=oversell-$2-$tag@example.test" || true
}

# 3. A wrong security code is refused, and nothing is stored.
code="$(book "$SLOT" wrongcode wrong)"
n="$(count "$SLOT")"
if [ "$n" = "0" ] && grep -q 'captchafailed' "$RESP"; then ok "A18 a wrong security code is refused" "HTTP $code, occupied=0"; else bad "A18 a wrong security code is refused" "HTTP $code, occupied=${n:-?}, refusal text present: $(grep -c captchafailed "$RESP")"; fi

# 4. First booking for a free slot, with the code the visitor was shown, is accepted.
code="$(book "$SLOT" first right)"
n="$(count "$SLOT")"
if [ "$n" = "1" ]; then ok "A18 the first booking for a free slot is stored" "HTTP $code, occupied=1"; else bad "A18 the first booking for a free slot is stored" "HTTP $code, occupied=${n:-?}"; fi

# 5. A second booking for the same slot is refused and the count does not change.
code="$(book "$SLOT" second right)"
n="$(count "$SLOT")"
if [ "$n" = "1" ]; then ok "A18 a second booking for a full slot is refused" "HTTP $code, occupied=1"; else bad "A18 a second booking for a full slot is refused" "HTTP $code, occupied=${n:-?}"; fi

# 6. A different free slot is still accepted.
code="$(book "$SLOT2" third right)"
n="$(count "$SLOT2")"
if [ "$n" = "1" ]; then ok "A18 a different free slot is still bookable" "HTTP $code, occupied=1"; else bad "A18 a different free slot is still bookable" "HTTP $code, occupied=${n:-?}"; fi

# 7. Put back the calendar row and remove the page.
restored="$(TZFD_OS_MODE=restore wpe | sed -n 's/^TZFD_OS_RESTORED=//p')"
if [ "$restored" = "1" ]; then ok "A18 the calendar row and test page are restored" "calendar 1 as before"; else bad "A18 the calendar row and test page are restored" "restore did not run"; fi

echo "Summary (oversell-http): $pass passed, $fail failed"
[ "$fail" -eq 0 ]
