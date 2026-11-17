#!/bin/bash
# HTTP checks for the human handoff callback form (issue #27).
# Usage: B=http://localhost:8080 PAGE=http://localhost:8080/?page_id=N bash handoff-http-tests.sh
# PAGE must be a published page that contains the [tzfd_callback_form] shortcode.
# Expects a clean rate-limit counter for this client address (the runner clears it before the run).
set -u
B="${B:-http://localhost:8080}"
PAGE="${PAGE:?set PAGE to the page containing [tzfd_callback_form]}"
ADMIN_POST="$B/wp-admin/admin-post.php"
JAR=$(mktemp); trap 'rm -f "$JAR"' EXIT
pass=0; fail=0
check() { # name expected actual
  if [ "$2" = "$3" ]; then pass=$((pass+1)); echo "PASS $1 (HTTP $3)"; else fail=$((fail+1)); echo "FAIL $1 expected $2 got $3"; fi
}
html=$(curl -s -c "$JAR" -b "$JAR" "$PAGE")
nonce=$(printf '%s' "$html" | grep -o 'name="_tzfd_handoff_nonce" value="[^"]*"' | head -1 | sed 's/.*value="\([^"]*\)"/\1/')
if [ -z "$nonce" ]; then echo "FAIL could not read nonce from $PAGE"; exit 1; fi
tag=$(date +%s%N | tail -c 9)

post() { # extra curl --data args...
  curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" -X POST "$ADMIN_POST" \
    --data-urlencode "action=tzfd_handoff_request" "$@"
}

# 1. No nonce: refused.
code=$(post --data-urlencode "name=No Nonce" --data-urlencode "email=nn-$tag@example.test" --data-urlencode "consent=1")
check "missing nonce refused" 403 "$code"

# 2. Honeypot filled: refused.
code=$(post --data-urlencode "_tzfd_handoff_nonce=$nonce" --data-urlencode "name=Bot" --data-urlencode "email=bot-$tag@example.test" --data-urlencode "consent=1" --data-urlencode "website=spam")
check "honeypot filled refused" 400 "$code"

# 3. No contact details: refused.
code=$(post --data-urlencode "_tzfd_handoff_nonce=$nonce" --data-urlencode "name=No Contact" --data-urlencode "consent=1")
check "no contact details refused" 400 "$code"

# 4. Contact given but no consent: refused.
code=$(post --data-urlencode "_tzfd_handoff_nonce=$nonce" --data-urlencode "name=No Consent" --data-urlencode "email=nc-$tag@example.test")
check "no consent refused" 400 "$code"

# 5. Invalid email: refused.
code=$(post --data-urlencode "_tzfd_handoff_nonce=$nonce" --data-urlencode "name=Bad Mail" --data-urlencode "email=not-an-email" --data-urlencode "consent=1")
check "invalid email refused" 400 "$code"

# 6. Valid requests 1 to 3 accepted (redirect), 4th refused by the rate limit.
for i in 1 2 3; do
  code=$(post --data-urlencode "_tzfd_handoff_nonce=$nonce" --data-urlencode "name=Valid $i" --data-urlencode "email=ok$i-$tag@example.test" --data-urlencode "phone=0400 000 00$i" --data-urlencode "message=Please call about a quote, my number is 0400 000 00$i" --data-urlencode "consent=1")
  check "valid callback request $i accepted" 302 "$code"
done
code=$(post --data-urlencode "_tzfd_handoff_nonce=$nonce" --data-urlencode "name=Valid 4" --data-urlencode "email=ok4-$tag@example.test" --data-urlencode "consent=1")
check "fourth request in window rate limited" 429 "$code"

echo "Summary: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
