#!/usr/bin/env bash
# HTTP checks for the hosted-service contract route (issue #285). Posts signed contract v1 envelopes to the
# disposable test site. Env: B (base URL), KEY (contract key stored by the runner), TENANT (site company id),
# plus the scopes the runner granted: read_settings only. Nothing here calls a live hosted service.
# REST is called as ?rest_route= because pretty permalinks are not routed on the test server.
set -u
B="${B:-${TZFD_RT_URL:-}}"
if [ -z "$B" ] || [ -z "${KEY:-}" ] || [ -z "${TENANT:-}" ]; then
  echo "FAIL contract-http -- B, KEY and TENANT must be set (the runner provisions them)" >&2
  exit 2
fi
HERE="$(cd "$(dirname "$0")" && pwd)"
ROUTE="$B/?rest_route=/titan-zero-front-desk/v1/service-contract"
pass=0; fail=0
nonce() { head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24; }
sign()  { php "$HERE/service-contract-sign.php" "$@"; }
# post BODY -> sets CODE and RESP
post() {
  RESP="$(printf '%s' "$1" | curl -s -o - -w '\n%{http_code}' --max-time 20 -H 'Content-Type: application/json' --data-binary @- "$ROUTE")"
  CODE="$(printf '%s' "$RESP" | tail -n1)"
  RESP="$(printf '%s' "$RESP" | sed '$d')"
}
check() { # name, expected code, must-contain (or ''), body
  local name="$1" want="$2" needle="$3" body="$4"
  post "$body"
  if [[ ! "$CODE" =~ ^[1-5][0-9][0-9]$ ]]; then echo "FAIL $name -- no HTTP response"; fail=$((fail+1)); return; fi
  if [ "$CODE" != "$want" ]; then echo "FAIL $name -- HTTP $CODE, expected $want: $RESP"; fail=$((fail+1)); return; fi
  if [ -n "$needle" ] && [[ "$RESP" != *"$needle"* ]]; then echo "FAIL $name -- body lacks '$needle': $RESP"; fail=$((fail+1)); return; fi
  echo "PASS $name -- HTTP $CODE"; pass=$((pass+1))
}

N1="$(nonce)"; GOOD="$(sign "$KEY" capability.check "$TENANT" "$N1")"
RESP="$(printf 'not json' | curl -s -o - -w '\n%{http_code}' --max-time 20 -H 'Content-Type: text/plain' --data-binary @- "$ROUTE")"
CODE="$(printf '%s' "$RESP" | tail -n1)"; RESP="$(printf '%s' "$RESP" | sed '$d')"
if [ "$CODE" = "400" ] && [[ "$RESP" == *'"code":"json_invalid"'* ]]; then echo "PASS malformed body refused -- HTTP $CODE"; pass=$((pass+1)); else echo "FAIL malformed body refused -- HTTP $CODE: $RESP"; fail=$((fail+1)); fi
check "valid signed capability.check allowed" 200 '"allowed":true' "$GOOD"
check "same signed envelope replayed refused" 409 'replay' "$GOOD"
check "tampered payload refused" 401 'signature_invalid' "$(sign "$KEY" capability.check "$TENANT" "$(nonce)" tamper)"
check "wrong key refused" 401 'signature_invalid' "$(sign "$KEY" capability.check "$TENANT" "$(nonce)" badkey)"
check "other tenant refused" 403 'tenant_mismatch' "$(sign "$KEY" capability.check "$((TENANT + 1))" "$(nonce)")"
check "unscoped read refused" 403 'scope_missing' "$(sign "$KEY" booking.list "$TENANT" "$(nonce)")"
check "write refused without its scope" 403 'scope_missing' "$(sign "$KEY" booking.create "$TENANT" "$(nonce)")"
check "unknown operation refused" 400 'unknown_operation' "$(sign "$KEY" booking.delete_all "$TENANT" "$(nonce)")"
check "allowed but unimplemented operation answers 501" 501 'operation_not_implemented' "$(sign "$KEY" settings.get "$TENANT" "$(nonce)")"
check "stale timestamp refused" 400 'timestamp_outside_window' "$(KEY="$KEY" php -r '
  define("ABSPATH", "/"); require $argv[1];
  $e = array("v"=>"1.0.0","op"=>"capability.check","tenant_id"=>(int)$argv[2],"ts"=>time()-3600,"nonce"=>$argv[3],"payload"=>array());
  $e["sig"] = TZFD_Service_Contract::sign($e, getenv("KEY")); echo json_encode($e);' "$HERE/../../titan-zero-trades-front-desk/includes/class-tzfd-service-contract.php" "$TENANT" "$(nonce)")"

echo "Summary: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
