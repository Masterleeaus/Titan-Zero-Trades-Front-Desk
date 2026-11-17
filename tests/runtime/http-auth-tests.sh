#!/usr/bin/env bash
# HTTP checks on the disposable test site: unauthenticated refusals, logged-in nonce and capability checks,
# and an optional signed missed-call replay check.
# A secure endpoint must NOT return 2xx for a request that lacks the credential under test.
# A route that is not registered (rest_no_route) is reported as FAIL: a 404 there proves nothing about authorization.
# Env: TZFD_RT_URL (default http://127.0.0.1:8080), TZFD_RT_ADMIN_PASS, TZFD_RT_SUB_PASS (from provision-wp-site.sh),
# SECRET (optional: the stored missed-call inbound secret; enables the signed replay check). The missed-call route
# needs its addon (tzfd-MissedCall) active.
# REST is called as ?rest_route= because pretty /wp-json/ paths are not routed on the test server.
# B (set by the CI workflow and WP-TESTSITE.md to WP_URL) wins over TZFD_RT_URL; both default to the local test site.
B="${B:-${TZFD_RT_URL:-http://127.0.0.1:8080}}"
pass=0; fail=0; skip=0
report() { # status name detail
  case "$1" in
    PASS) echo "PASS $2 -- $3"; pass=$((pass+1)) ;;
    FAIL) echo "FAIL $2 -- $3"; fail=$((fail+1)) ;;
    SKIP) echo "SKIP $2 -- $3"; skip=$((skip+1)) ;;
  esac
}
check() { # name, code  (refuse = not 2xx; 000 means no response, which is not a refusal)
  local name="$1" code="$2"
  # 000 means no HTTP response at all: that proves nothing about the endpoint, so it must not count as a refusal.
  if [[ "$code" == "000" ]]; then report FAIL "$name" "HTTP 000 (no response from $B)";
  elif [[ "$code" =~ ^2 ]]; then report FAIL "$name" "HTTP $code (accepted without credentials)";
  else report PASS "$name" "HTTP $code"; fi
}
code() { curl -s -o /dev/null -m 20 -w '%{http_code}' "$@"; }

# Preflight: abort rather than report a dead server as a pass.
preflight=$(code "$B/")
if [[ "$preflight" != "200" ]]; then echo "ABORT: test site not answering at $B (HTTP $preflight)"; exit 2; fi
# REST route check: a missing route (rest_no_route) is a FAIL, because it exercises no authorization.
rest() { # name, curl args...
  local name="$1"; shift
  local out rcode body
  out=$(curl -s -w '\n%{http_code}' "$@")
  rcode=$(tail -n1 <<<"$out"); body=$(sed '$d' <<<"$out")
  if [[ "$body" == *rest_no_route* ]]; then report FAIL "$name" "route not registered (HTTP $rcode), auth not exercised";
  else check "$name" "$rcode"; fi
}

# Unauthenticated refusals.
rest "missed-call, no secret" -X POST -H 'Content-Type: application/json' -d '{"event":"missed","from":"+61400000000"}' "$B/?rest_route=/titan-zero-front-desk/v1/missed-call"
rest "missed-call, bad signature" -X POST -H 'Content-Type: application/json' -H "X-TZFD-Timestamp: $(date +%s)" -H 'X-TZFD-Signature: sha256=0000000000000000000000000000000000000000000000000000000000000000' -d '{"event":"missed","from":"+61400000000"}' "$B/?rest_route=/titan-zero-front-desk/v1/missed-call"
rest "twilio-status, unsigned" -X POST -d 'MessageSid=SM1&MessageStatus=delivered' "$B/?rest_route=/tzfd/v1/twilio-status"
rest "call-events, unsigned" -X POST -H 'Content-Type: application/json' -d '{"call_id":"c1","event_id":"e1","event":"ended"}' "$B/?rest_route=/titan-zero-front-desk/v1/call-events"
rest "booking status, anonymous" "$B/?rest_route=/titan-zero-front-desk/v1/bookings/1"
rest "resources catalog, anonymous" "$B/?rest_route=/titan-zero-front-desk/v1/resources/catalog"
rest "resources preview, anonymous" -X POST -H 'Content-Type: application/json' -d '{}' "$B/?rest_route=/titan-zero-front-desk/v1/resources/preview"

# Signed replay: a valid signature is accepted once, then refused. Needs SECRET.
if [[ -n "${SECRET:-}" ]]; then
  body='{"event":"missed","from":"+61400000000"}'; ts=$(date +%s)
  sig="sha256=$(printf '%s.%s' "$ts" "$body" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')"
  # Route registration: the REST index must list the route, otherwise the signed checks prove nothing.
  # The index is JSON, so slashes may arrive escaped as \/ : the pattern allows an optional backslash.
  if curl -s "$B/?rest_route=/" | grep -Eq 'titan-zero-front-desk\\?/v1\\?/missed-call'; then
    report PASS "missed-call route registered" "listed in REST index"
  else
    report FAIL "missed-call route registered" "not in REST index at $B (is the tzfd-MissedCall addon active?)"
  fi
  # -sS shows a curl error (e.g. connection refused) on stderr instead of a bare 000; --max-time stops a hang.
  signed() { curl -sS --max-time 30 -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H "X-TZFD-Timestamp: $ts" -H "X-TZFD-Signature: $sig" -d "$body" "$B/?rest_route=/titan-zero-front-desk/v1/missed-call" 2>&1 | tr '\n' ' '; }
  first=$(signed); second=$(signed)
  if [[ "$first" =~ ^2 ]]; then report PASS "missed-call, valid signature accepted first time" "HTTP $first"; else report FAIL "missed-call, valid signature rejected" "HTTP $first"; fi
  if [[ "$second" =~ ^4 ]]; then report PASS "missed-call, replayed signature refused" "HTTP $second"; else report FAIL "missed-call, replayed signature accepted" "HTTP $second"; fi
else
  report SKIP "missed-call signed replay" "SECRET not set"
fi
# No plugin AJAX action is registered in the current source (no wp_ajax_ hook), so this is a weak check: it only shows the core admin-ajax refusal.
check "unregistered admin-ajax action, anonymous (weak check)" "$(code -X POST -d 'action=tzfd_workspace_customer_sites&nonce=x' "$B/wp-admin/admin-ajax.php")"

# Logged-in checks: capability and nonce. Skipped when the credentials were not provisioned.
if [ -z "${TZFD_RT_ADMIN_PASS:-}" ] || [ -z "${TZFD_RT_SUB_PASS:-}" ]; then
  report SKIP "logged-in nonce and capability checks" "TZFD_RT_ADMIN_PASS / TZFD_RT_SUB_PASS not set (run provision-wp-site.sh)"
else
  login() { # user pass jar
    curl -s -o /dev/null -c "$3" -H 'Cookie: wordpress_test_cookie=WP%20Cookie%20check' \
      --data-urlencode "log=$1" --data-urlencode "pwd=$2" -d 'wp-submit=Log+In&testcookie=1' "$B/wp-login.php"
    grep -q 'wordpress_logged_in' "$3"
  }
  nonce_of() { # jar -> REST nonce from an admin page, empty if absent
    curl -s -b "$1" "$B/wp-admin/index.php" | grep -o '"nonce":"[a-f0-9]\{10\}"' | head -1 | cut -d'"' -f4
  }
  JAR_ADMIN=$(mktemp); JAR_SUB=$(mktemp)
  if login rtadmin "$TZFD_RT_ADMIN_PASS" "$JAR_ADMIN"; then
    ADMIN_NONCE=$(nonce_of "$JAR_ADMIN")
    if [ -n "$ADMIN_NONCE" ]; then
      report PASS "admin login yields a REST nonce" "positive control"
      c=$(code -b "$JAR_ADMIN" -H "X-WP-Nonce: $ADMIN_NONCE" "$B/?rest_route=/titan-zero-front-desk/v1/resources/catalog")
      [[ "$c" =~ ^2 ]] && report PASS "admin with valid nonce reads resource catalog" "HTTP $c (positive control)" || report FAIL "admin with valid nonce reads resource catalog" "HTTP $c"
      c=$(code -b "$JAR_ADMIN" -H 'X-WP-Nonce: 0123456789' -X POST -H 'Content-Type: application/json' -d '{}' "$B/?rest_route=/titan-zero-front-desk/v1/resources/preview")
      check "admin cookie with invalid nonce, POST preview" "$c"
      c=$(code -b "$JAR_ADMIN" -X POST -H 'Content-Type: application/json' -d '{}' "$B/?rest_route=/titan-zero-front-desk/v1/resources/preview")
      check "admin cookie with no nonce, POST preview" "$c"
    else
      report SKIP "admin nonce checks" "no REST nonce found on /wp-admin/index.php for this WordPress version"
    fi
  else
    report FAIL "admin login" "could not sign in as rtadmin"
  fi
  if login rtsub "$TZFD_RT_SUB_PASS" "$JAR_SUB"; then
    SUB_NONCE=$(nonce_of "$JAR_SUB")
    c=$(code -b "$JAR_SUB" ${SUB_NONCE:+-H "X-WP-Nonce: $SUB_NONCE"} "$B/?rest_route=/titan-zero-front-desk/v1/resources/catalog")
    check "subscriber reads resource catalog (capability refusal)" "$c"
    c=$(code -b "$JAR_SUB" ${SUB_NONCE:+-H "X-WP-Nonce: $SUB_NONCE"} -X POST -H 'Content-Type: application/json' -d '{}' "$B/?rest_route=/titan-zero-front-desk/v1/resources/preview")
    check "subscriber POST preview (capability refusal)" "$c"
  else
    report FAIL "subscriber login" "could not sign in as rtsub"
  fi
  rm -f "$JAR_ADMIN" "$JAR_SUB"
fi

echo "Note: a weak check is labelled above; it does not prove the plugin's own nonce handling."
echo "Summary: $pass passed, $fail failed, $skip skipped"
[ "$fail" -eq 0 ]
