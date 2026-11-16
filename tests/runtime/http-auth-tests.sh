#!/usr/bin/env bash
# Unauthenticated HTTP checks on the disposable test site. A secure endpoint must NOT return 2xx here.
B=http://localhost:8080
pass=0; fail=0
check() { # name, expected_class (refuse), code
  local name="$1" code="$2"
  if [[ "$code" =~ ^2 ]]; then echo "FAIL $name -- HTTP $code (accepted without credentials)"; fail=$((fail+1));
  else echo "PASS $name -- HTTP $code"; pass=$((pass+1)); fi
}
check "missed-call, no secret" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"event":"missed","from":"+61400000000"}' $B/?rest_route=/titan-zero-front-desk/v1/missed-call)"
check "missed-call, bad signature" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-TZFD-Timestamp: $(date +%s)" -H 'X-TZFD-Signature: sha256=0000000000000000000000000000000000000000000000000000000000000000' -d '{"event":"missed","from":"+61400000000"}' $B/?rest_route=/titan-zero-front-desk/v1/missed-call)"
check "twilio-status, unsigned" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d 'MessageSid=SM1&MessageStatus=delivered' $B/?rest_route=/tzfd/v1/twilio-status)"
check "call-events, unsigned" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"call_id":"c1","event_id":"e1","event":"ended"}' $B/?rest_route=/titan-zero-front-desk/v1/call-events)"
check "booking status, anonymous" "$(curl -s -o /dev/null -w '%{http_code}' $B/?rest_route=/titan-zero-front-desk/v1/bookings/1)"
check "resources catalog, anonymous" "$(curl -s -o /dev/null -w '%{http_code}' $B/?rest_route=/titan-zero-front-desk/v1/resources/catalog)"
check "resources preview, anonymous" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' $B/?rest_route=/titan-zero-front-desk/v1/resources/preview)"
check "workspace customer-sites AJAX, anonymous" "$(curl -s -o /dev/null -w '%{http_code}' -X POST -d 'action=tzfd_workspace_customer_sites&nonce=x' $B/wp-admin/admin-ajax.php)"
# Forged public enquiry: the nonce gate must answer exactly 403. A success path redirects (302), so require the exact code.
forged_body='name=Forged&email=forged@example.test&enquiry=forged&website='
for label in "no nonce" "wrong nonce"; do
  if [ "$label" = "no nonce" ]; then extra=''; else extra='&_tzfd_enquiry_nonce=0000000000'; fi
  code=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/x-www-form-urlencoded' -d "action=tzfd_public_enquiry&$forged_body$extra" "$B/wp-admin/admin-post.php")
  if [ "$code" = "403" ]; then echo "PASS forged public enquiry ($label) refused -- HTTP $code"; pass=$((pass+1)); else echo "FAIL forged public enquiry ($label) -- HTTP $code (expected 403)"; fail=$((fail+1)); fi
done
# Valid-secret replay: a correctly signed missed-call is accepted once, then refused as a replay.
if [ -n "${SECRET:-}" ]; then
  body='{"event":"missed_call","from":"+61400000000"}'
  ts=$(date +%s)
  sig="sha256=$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')"
  first=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-TZFD-Timestamp: $ts" -H "X-TZFD-Signature: $sig" -d "$body" "$B/?rest_route=/titan-zero-front-desk/v1/missed-call")
  second=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-TZFD-Timestamp: $ts" -H "X-TZFD-Signature: $sig" -d "$body" "$B/?rest_route=/titan-zero-front-desk/v1/missed-call")
  if [[ "$first" =~ ^2 ]]; then echo "PASS missed-call, valid signature accepted once -- HTTP $first"; pass=$((pass+1)); else echo "FAIL missed-call, valid signature -- HTTP $first"; fail=$((fail+1)); fi
  if [[ "$second" =~ ^4 ]]; then echo "PASS missed-call, identical signed replay refused -- HTTP $second"; pass=$((pass+1)); else echo "FAIL missed-call, replay accepted -- HTTP $second"; fail=$((fail+1)); fi
else
  echo "SKIP missed-call signed replay (SECRET not set)"
fi
echo "Note: REST is called as ?rest_route= because the pretty /wp-json/ path is not routed on this test server."
echo "Summary: $pass passed, $fail failed"
