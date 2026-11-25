#!/usr/bin/env bash
# Oversell prevention through the public booking form, over HTTP (issue #389, runtime A18). Runs on the site the runner provisions.
#   1. calendar 1 is set to one booking per slot (no CAPTCHA), and a page with its shortcode is published
#   2. the page shows the booking form with its nonce
#   3. the first POST for a free slot is accepted and one occupied booking is stored
#   4. a second POST for the same slot is refused and the stored count stays at one
#   5. a POST for a different free slot is still accepted (the guard refuses only what is full)
#   6. the calendar row and the page are put back
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
# Whatever happens, the calendar row and page are put back (restore is a no-op once it has run).
trap 'rm -f "$JAR"; TZFD_OS_MODE=restore wpe >/dev/null 2>&1' EXIT

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

# 2. The form renders with its booking nonce and the submission fields.
# WordPress may redirect ?page_id=N to the page's permalink (canonical redirect); follow it and post to the final URL.
PAGE="$(curl -s -L -o /dev/null -w '%{url_effective}' --max-time 30 "$PAGE" || true)"
html="$(curl -s -L --max-time 30 -c "$JAR" -b "$JAR" "$PAGE" || true)"
nonce="$(printf '%s' "$html" | grep -o '<input name="anonce"[^>]*>' | grep -o 'value="[^"]*"' | head -1 | cut -d'"' -f2)"
seq="$(printf '%s' "$html" | grep -o 'name="tz_fd_pform_psequence" value="[^"]*"' | head -1 | sed 's/.*value="\([^"]*\)"/\1/')"
if [ -n "$nonce" ] && [ -n "$seq" ]; then ok "A18 the public form renders with its nonce" "sequence=$seq"; else
  # Diagnostics for the log: what the page returned, without any page content.
  diag="$(curl -s --max-time 30 -o /dev/null -w '%{http_code} %{redirect_url}' "$PAGE" || true)"
  text="$(printf '%s' "$html" | sed 's/<[^>]*>/ /g' | tr -s ' \n\t' ' ' | head -c 240)"
  bad "A18 the public form renders with its nonce" "nonce or sequence missing (page HTTP and redirect: $diag, length ${#html}, form markup: $(printf '%s' "$html" | grep -c 'tz_fd_frontdesk_pform'), text: $text)"
  echo "Summary (oversell-http): $pass passed, $fail failed"; exit 1
fi

book() { # book <slot> <customer tag>; prints "<http code>"
  curl -s --max-time 60 -o /dev/null -w '%{http_code}' -b "$JAR" -c "$JAR" -X POST "$PAGE" \
    --data-urlencode "tz_fd_frontdesk_pform_process=1" \
    --data-urlencode "tz_fd_frontdesk_id=1" \
    --data-urlencode "tz_fd_pform_psequence=$seq" \
    --data-urlencode "anonce=$nonce" \
    --data-urlencode "fieldname1$seq=$DATE $1 0 1" \
    --data-urlencode "name$seq=Oversell $2" \
    --data-urlencode "email$seq=oversell-$2-$tag@example.test" || true
}

# 3. First booking for a free slot is accepted.
code="$(book "$SLOT" first)"
n="$(count "$SLOT")"
if [ "$n" = "1" ]; then ok "A18 the first booking for a free slot is stored" "HTTP $code, occupied=1"; else bad "A18 the first booking for a free slot is stored" "HTTP $code, occupied=${n:-?}"; fi

# 4. A second booking for the same slot is refused and the count does not change.
code="$(book "$SLOT" second)"
n="$(count "$SLOT")"
if [ "$n" = "1" ]; then ok "A18 a second booking for a full slot is refused" "HTTP $code, occupied=1"; else bad "A18 a second booking for a full slot is refused" "HTTP $code, occupied=${n:-?}"; fi

# 5. A different free slot is still accepted.
code="$(book "$SLOT2" third)"
n="$(count "$SLOT2")"
if [ "$n" = "1" ]; then ok "A18 a different free slot is still bookable" "HTTP $code, occupied=1"; else bad "A18 a different free slot is still bookable" "HTTP $code, occupied=${n:-?}"; fi

# 6. Put back the calendar row and remove the page.
restored="$(TZFD_OS_MODE=restore wpe | sed -n 's/^TZFD_OS_RESTORED=//p')"
if [ "$restored" = "1" ]; then ok "A18 the calendar row and test page are restored" "calendar 1 as before"; else bad "A18 the calendar row and test page are restored" "restore did not run"; fi

echo "Summary (oversell-http): $pass passed, $fail failed"
[ "$fail" -eq 0 ]
