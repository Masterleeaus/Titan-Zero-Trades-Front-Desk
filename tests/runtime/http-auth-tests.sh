#!/usr/bin/env bash
# HTTP checks on the disposable test site: unauthenticated refusals, and logged-in nonce and capability checks.
# A secure endpoint must NOT return 2xx for a request that lacks the credential under test.
# Env: TZFD_RT_URL (default http://127.0.0.1:8080), TZFD_RT_ADMIN_PASS, TZFD_RT_SUB_PASS (from provision-wp-site.sh).
# REST is called as ?rest_route= because pretty /wp-json/ paths are not routed on the test server.
B="${TZFD_RT_URL:-http://127.0.0.1:8080}"
pass=0; fail=0; skip=0
report() { # status name detail
  case "$1" in
    PASS) echo "PASS $2 -- $3"; pass=$((pass+1)) ;;
    FAIL) echo "FAIL $2 -- $3"; fail=$((fail+1)) ;;
    SKIP) echo "SKIP $2 -- $3"; skip=$((skip+1)) ;;
  esac
}
check() { # name, code  (refuse = not 2xx)
  local name="$1" code="$2"
  if [[ "$code" =~ ^2 ]]; then report FAIL "$name" "HTTP $code (accepted without credentials)";
  else report PASS "$name" "HTTP $code"; fi
}
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

# Unauthenticated refusals.
check "missed-call, no secret" "$(code -X POST -H 'Content-Type: application/json' -d '{"event":"missed","from":"+61400000000"}' "$B/?rest_route=/titan-zero-front-desk/v1/missed-call")"
check "missed-call, bad signature" "$(code -X POST -H 'Content-Type: application/json' -H "X-TZFD-Timestamp: $(date +%s)" -H 'X-TZFD-Signature: sha256=0000000000000000000000000000000000000000000000000000000000000000' -d '{"event":"missed","from":"+61400000000"}' "$B/?rest_route=/titan-zero-front-desk/v1/missed-call")"
check "twilio-status, unsigned" "$(code -X POST -d 'MessageSid=SM1&MessageStatus=delivered' "$B/?rest_route=/tzfd/v1/twilio-status")"
check "call-events, unsigned" "$(code -X POST -H 'Content-Type: application/json' -d '{"call_id":"c1","event_id":"e1","event":"ended"}' "$B/?rest_route=/titan-zero-front-desk/v1/call-events")"
check "booking status, anonymous" "$(code "$B/?rest_route=/titan-zero-front-desk/v1/bookings/1")"
check "resources catalog, anonymous" "$(code "$B/?rest_route=/titan-zero-front-desk/v1/resources/catalog")"
check "resources preview, anonymous" "$(code -X POST -H 'Content-Type: application/json' -d '{}' "$B/?rest_route=/titan-zero-front-desk/v1/resources/preview")"
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
