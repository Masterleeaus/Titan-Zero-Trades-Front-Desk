#!/usr/bin/env bash
# Unauthenticated HTTP checks on the disposable test site. A secure endpoint must NOT return 2xx here.
# A route that is not registered (rest_no_route) is reported as FAIL: a 404 for a missing route
# proves nothing about authorization. Requires the addons that own each route to be active.
# Optional signed replay check: set SECRET to the stored missed-call inbound secret.
B=${B:-http://localhost:8080}
pass=0; fail=0
check() { # name, expected_class (refuse), code, body
  local name="$1" code="$2" body="$3"
  if [[ "$body" == *rest_no_route* ]]; then echo "FAIL $name -- route not registered (HTTP $code), auth not exercised"; fail=$((fail+1));
  elif [[ "$code" =~ ^2 ]]; then echo "FAIL $name -- HTTP $code (accepted without credentials)"; fail=$((fail+1));
  else echo "PASS $name -- HTTP $code"; pass=$((pass+1)); fi
}
req() { # method, url, extra curl args...; prints "code|body"
  local out
  out=$(curl -s -w '\n%{http_code}' "$@")
  printf '%s|%s' "$(tail -n1 <<<"$out")" "$(sed '$d' <<<"$out")"
}
run() { # name, curl args...
  local name="$1"; shift
  local r; r=$(req "$@"); check "$name" "${r%%|*}" "${r#*|}"
}
run "missed-call, no secret" -X POST -H 'Content-Type: application/json' -d '{"event":"missed","from":"+61400000000"}' "$B/?rest_route=/titan-zero-front-desk/v1/missed-call"
run "missed-call, bad signature" -X POST -H 'Content-Type: application/json' -H "X-TZFD-Timestamp: $(date +%s)" -H 'X-TZFD-Signature: sha256=0000000000000000000000000000000000000000000000000000000000000000' -d '{"event":"missed","from":"+61400000000"}' "$B/?rest_route=/titan-zero-front-desk/v1/missed-call"
run "twilio-status, unsigned" -X POST -d 'MessageSid=SM1&MessageStatus=delivered' "$B/?rest_route=/tzfd/v1/twilio-status"
run "call-events, unsigned" -X POST -H 'Content-Type: application/json' -d '{"call_id":"c1","event_id":"e1","event":"ended"}' "$B/?rest_route=/titan-zero-front-desk/v1/call-events"
run "booking status, anonymous" "$B/?rest_route=/titan-zero-front-desk/v1/bookings/1"
run "resources catalog, anonymous" "$B/?rest_route=/titan-zero-front-desk/v1/resources/catalog"
run "resources preview, anonymous" -X POST -H 'Content-Type: application/json' -d '{}' "$B/?rest_route=/titan-zero-front-desk/v1/resources/preview"

# Signed replay: a valid signature is accepted once, then refused. Needs SECRET.
if [[ -n "${SECRET:-}" ]]; then
  body='{"event":"missed","from":"+61400000000"}'; ts=$(date +%s)
  sig="sha256=$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')"
  run_signed() { req -X POST -H 'Content-Type: application/json' -H "X-TZFD-Timestamp: $ts" -H "X-TZFD-Signature: $sig" -d "$body" "$B/?rest_route=/titan-zero-front-desk/v1/missed-call"; }
  first=$(run_signed); second=$(run_signed)
  if [[ "${first%%|*}" =~ ^2 ]]; then echo "PASS missed-call, valid signature accepted first time -- HTTP ${first%%|*}"; pass=$((pass+1)); else echo "FAIL missed-call, valid signature rejected -- HTTP ${first%%|*}"; fail=$((fail+1)); fi
  if [[ "${second%%|*}" =~ ^4 ]]; then echo "PASS missed-call, replayed signature refused -- HTTP ${second%%|*}"; pass=$((pass+1)); else echo "FAIL missed-call, replayed signature accepted -- HTTP ${second%%|*}"; fail=$((fail+1)); fi
else
  echo "SKIP missed-call signed replay -- SECRET not set"
fi
echo "Note: REST is called as ?rest_route= because the pretty /wp-json/ path is not routed on this test server."
echo "Note: the plugin registers no admin-ajax actions (no wp_ajax_ hooks in the 3.0.128 source), so there is no AJAX entry point to test."
echo "Summary: $pass passed, $fail failed"
[[ $fail -eq 0 ]]
