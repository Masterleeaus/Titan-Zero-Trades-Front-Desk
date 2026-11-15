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
echo "Note: REST is called as ?rest_route= because the pretty /wp-json/ path is not routed on this test server."
echo "Summary: $pass passed, $fail failed"
