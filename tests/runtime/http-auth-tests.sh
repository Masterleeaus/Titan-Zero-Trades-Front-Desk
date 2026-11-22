#!/usr/bin/env bash
# HTTP checks on the disposable test site: unauthenticated refusals, logged-in nonce and capability checks,
# forged public enquiry refusal, and an optional signed missed-call replay check.
# A secure endpoint must NOT return 2xx for a request that lacks the credential under test.
# A route that is not registered (rest_no_route) is reported as FAIL: a 404 there proves nothing about authorization.
# Env: B (base URL, set by the CI workflow; TZFD_RT_URL is accepted as a fallback), TZFD_RT_ADMIN_PASS, TZFD_RT_SUB_PASS (from provision-wp-site.sh),
# SECRET (optional: the stored missed-call inbound secret; enables the signed replay check). The missed-call route
# needs its addon (tzfd-MissedCall) active.
# REST is called as ?rest_route= because pretty /wp-json/ paths are not routed on the test server.
# The script refuses to run without a base URL. A request that gets no response (curl status 000, or an empty code) is a FAIL, never a PASS.
B="${B:-${TZFD_RT_URL:-}}"
if [ -z "$B" ]; then
  echo "FAIL harness -- base URL not set: export B (or TZFD_RT_URL) before running http-auth-tests.sh" >&2
  exit 2
fi
pass=0; fail=0; skip=0
report() { # status name detail
  case "$1" in
    PASS) echo "PASS $2 -- $3"; pass=$((pass+1)) ;;
    FAIL) echo "FAIL $2 -- $3"; fail=$((fail+1)) ;;
    SKIP) echo "SKIP $2 -- $3"; skip=$((skip+1)) ;;
  esac
}
check() { # name, code  (refuse = a real HTTP status that is not 2xx)
  local name="$1" code="$2"
  if [[ ! "$code" =~ ^[1-5][0-9][0-9]$ ]]; then report FAIL "$name" "no HTTP response (code '${code:-empty}'): request never reached the server";
  elif [[ "$code" =~ ^2 ]]; then report FAIL "$name" "HTTP $code (accepted without credentials)";
  else report PASS "$name" "HTTP $code"; fi
}
# Connection failures give curl status 000; --max-time stops a silent server from hanging the run.
code() { curl -s --max-time 30 -o /dev/null -w '%{http_code}' "$@" || true; }
# Diagnostics (issue #190): HTTP status and Location header of one request, printed as DIAG lines.
# DIAG lines never count as PASS or FAIL.
diag() { # label, curl args...
  local label="$1"; shift
  local hdr dcode loc
  hdr=$(mktemp)
  dcode=$(curl -s --max-time 30 -D "$hdr" -o /dev/null -w '%{http_code}' "$@" || true)
  loc=$(tr -d '\r' < "$hdr" | grep -i '^location:' | tail -1)
  echo "DIAG $label -- HTTP ${dcode:-000}; ${loc:-no Location header}"
  rm -f "$hdr"
}

# Preflight: abort rather than report a dead server as a pass.
preflight=$(code "$B/?rest_route=/")
if [[ "$preflight" != "200" ]]; then echo "ABORT: test site not answering at $B (HTTP $preflight)"; exit 2; fi
echo "DIAG WordPress version: $(curl -s --max-time 30 "$B/?feed=rss2" | grep -o '?v=[0-9][0-9.]*' | head -1)"
echo "DIAG login form action: $(curl -s --max-time 30 "$B/wp-login.php" | grep -o 'action="[^"]*"' | grep -i 'login' | head -1)"
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
rest "messaging-events (SMS reply), unsigned" -X POST -H 'Content-Type: application/json' -d '{"event":"inbound_sms","event_id":"m1","from":"+61400000000","body":"STOP","consent":"customer_initiated"}' "$B/?rest_route=/titan-zero-front-desk/v1/messaging-events"
rest "messaging-events (SMS reply), forged signature" -X POST -H 'Content-Type: application/json' -H 'X-TZFD-Key-Id: forged-key' -H "X-TZFD-Timestamp: $(date +%s)" -H 'X-TZFD-Signature: 0000000000000000000000000000000000000000000000000000000000000000' -d '{"event":"inbound_sms","event_id":"m2","from":"+61400000000","body":"STOP","consent":"customer_initiated"}' "$B/?rest_route=/titan-zero-front-desk/v1/messaging-events"
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
    # DIAG: the login response's redirect and cookie names and paths (cookie values are redacted).
    dh=$(mktemp)
    curl -s -o /dev/null -D "$dh" -H 'Cookie: wordpress_test_cookie=WP%20Cookie%20check' --data-urlencode "log=rtadmin" --data-urlencode "pwd=$TZFD_RT_ADMIN_PASS" -d 'wp-submit=Log+In&testcookie=1' "$B/wp-login.php" || true
    tr -d '\r' < "$dh" | grep -i '^location:\|^set-cookie:' | sed -E 's/^(Set-Cookie: [^=]*)=[^;]*/\1=<redacted>/I' | sed 's/^/DIAG login response /'
    rm -f "$dh"
    diag "admin dashboard /wp-admin/index.php (admin cookie)" -b "$JAR_ADMIN" "$B/wp-admin/index.php"
    echo "DIAG nonce-like markers on admin dashboard: $(curl -s -b "$JAR_ADMIN" "$B/wp-admin/index.php" | grep -c '"nonce"' || true)"
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
  # FAQ gap admin screen and admin-post handler: capability refused first, then nonce. Refusals are exact 403s.
  ADMIN_POST="$B/wp-admin/admin-post.php"
  ADMIN_PAGE="$B/wp-admin/admin.php?page=tzfd-ai-gaps"
  POST_BODY='action=tzfd_ai_gap_merge&proposal_id=none&decision=reject'
  ADMIN_PAGE_REVIEW="$B/wp-admin/admin.php?page=tzfd-ai-faq-review"
  POST_BODY_REVIEW='action=tzfd_ai_faq_decide&proposal_id=none&decision=reject'
  # DIAG: status and Location for each FAQ gap and review request (admin, subscriber, anonymous, no or bad nonce).
  diag "FAQ gap screen, anonymous" "$ADMIN_PAGE"
  diag "FAQ gap screen, admin" -b "$JAR_ADMIN" "$ADMIN_PAGE"
  diag "FAQ gap screen, subscriber" -b "$JAR_SUB" "$ADMIN_PAGE"
  diag "FAQ gap handler, anonymous" -d "$POST_BODY" "$ADMIN_POST"
  diag "FAQ gap handler, subscriber" -b "$JAR_SUB" -d "$POST_BODY" "$ADMIN_POST"
  diag "FAQ gap handler, admin no nonce" -b "$JAR_ADMIN" -d "$POST_BODY" "$ADMIN_POST"
  diag "FAQ gap handler, admin bad nonce" -b "$JAR_ADMIN" -d "$POST_BODY&_wpnonce=0123456789" "$ADMIN_POST"
  diag "FAQ review screen, anonymous" "$ADMIN_PAGE_REVIEW"
  diag "FAQ review screen, admin" -b "$JAR_ADMIN" "$ADMIN_PAGE_REVIEW"
  diag "FAQ review screen, subscriber" -b "$JAR_SUB" "$ADMIN_PAGE_REVIEW"
  diag "FAQ review handler, anonymous" -d "$POST_BODY_REVIEW" "$ADMIN_POST"
  diag "FAQ review handler, subscriber" -b "$JAR_SUB" -d "$POST_BODY_REVIEW" "$ADMIN_POST"
  diag "FAQ review handler, admin no nonce" -b "$JAR_ADMIN" -d "$POST_BODY_REVIEW" "$ADMIN_POST"
  diag "FAQ review handler, admin bad nonce" -b "$JAR_ADMIN" -d "$POST_BODY_REVIEW&_wpnonce=0123456789" "$ADMIN_POST"
  expect() { # name, expected, actual
    if [ "$3" = "$2" ]; then report PASS "$1" "HTTP $3"; else report FAIL "$1" "expected HTTP $2, got HTTP $3"; fi
  }
  c=$(code -b "$JAR_ADMIN" "$ADMIN_PAGE")
  [[ "$c" =~ ^2 ]] && report PASS "FAQ gap screen, admin (positive control)" "HTTP $c" || report FAIL "FAQ gap screen, admin (positive control)" "HTTP $c"
  expect "FAQ gap screen, subscriber (capability refusal)" 403 "$(code -b "$JAR_SUB" "$ADMIN_PAGE")"
  # FAQ gap filters over HTTP (#354): an unknown coverage value is ignored, a bad min_count is clamped, and the
  # search text is echoed escaped, never as markup.
  FILTER_URL="$ADMIN_PAGE&coverage=bogus&min_count=-5&search=%3Cscript%3Ex%3C%2Fscript%3E"
  FILTER_BODY=$(curl -s --max-time 30 -b "$JAR_ADMIN" "$FILTER_URL" || true)
  FILTER_CODE=$(code -b "$JAR_ADMIN" "$FILTER_URL")
  if [[ "$FILTER_CODE" =~ ^2 ]] && ! grep -q '<script>x</script>' <<<"$FILTER_BODY" && grep -q '&lt;script&gt;x' <<<"$FILTER_BODY"; then
    report PASS "FAQ gap filters, admin: bad coverage/min_count ignored, search escaped" "HTTP $FILTER_CODE"
  else
    report FAIL "FAQ gap filters, admin: bad coverage/min_count ignored, search escaped" "HTTP $FILTER_CODE"
  fi
  FILTER_OK=$(curl -s --max-time 30 -b "$JAR_ADMIN" "$ADMIN_PAGE&coverage=missing&min_count=2" || true)
  OPT_LINE=$(grep -o '<option value="missing"[^>]*>' <<<"$FILTER_OK" | head -1)
  if grep -q 'name="coverage"' <<<"$FILTER_OK" && grep -q "selected='selected'\|selected=\"selected\"" <<<"$OPT_LINE"; then
    report PASS "FAQ gap filters, admin: coverage=missing is kept in the form" "selected"
  else
    report FAIL "FAQ gap filters, admin: coverage=missing is kept in the form" "option line: ${OPT_LINE:-none}"
  fi
  c=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -d "$POST_BODY" "$ADMIN_POST")
  case "$c" in
    "400 "*|"403 "*|"302 "*wp-login*) report PASS "FAQ gap handler, anonymous" "refused ($c)" ;;
    *) report FAIL "FAQ gap handler, anonymous" "unexpected ($c)" ;;
  esac
  expect "FAQ gap handler, subscriber (capability refusal)" 403 "$(code -b "$JAR_SUB" -d "$POST_BODY" "$ADMIN_POST")"
  expect "FAQ gap handler, admin without nonce" 403 "$(code -b "$JAR_ADMIN" -d "$POST_BODY" "$ADMIN_POST")"
  expect "FAQ gap handler, admin with invalid nonce" 403 "$(code -b "$JAR_ADMIN" -d "$POST_BODY&_wpnonce=0123456789" "$ADMIN_POST")"
  # FAQ review screen: capability refused for subscribers; nonce and capability checked before the handler acts.
  c=$(code -b "$JAR_ADMIN" "$ADMIN_PAGE_REVIEW")
  [[ "$c" =~ ^2 ]] && report PASS "FAQ review screen, admin (positive control)" "HTTP $c" || report FAIL "FAQ review screen, admin (positive control)" "HTTP $c"
  expect "FAQ review screen, subscriber (capability refusal)" 403 "$(code -b "$JAR_SUB" "$ADMIN_PAGE_REVIEW")"
  c=$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' -d "$POST_BODY_REVIEW" "$ADMIN_POST")
  case "$c" in
    "400 "*|"403 "*|"302 "*wp-login*) report PASS "FAQ review handler, anonymous" "refused ($c)" ;;
    *) report FAIL "FAQ review handler, anonymous" "unexpected ($c)" ;;
  esac
  expect "FAQ review handler, subscriber (capability refusal)" 403 "$(code -b "$JAR_SUB" -d "$POST_BODY_REVIEW" "$ADMIN_POST")"
  expect "FAQ review handler, admin without nonce" 403 "$(code -b "$JAR_ADMIN" -d "$POST_BODY_REVIEW" "$ADMIN_POST")"
  expect "FAQ review handler, admin with invalid nonce" 403 "$(code -b "$JAR_ADMIN" -d "$POST_BODY_REVIEW&_wpnonce=0123456789" "$ADMIN_POST")"
  rm -f "$JAR_ADMIN" "$JAR_SUB"
fi

# Forged public enquiry: the nonce gate must answer exactly 403. A success path redirects (302), so require the exact code.
forged_body='name=Forged&email=forged@example.test&enquiry=forged&website='
for label in "no nonce" "wrong nonce"; do
  if [ "$label" = "no nonce" ]; then extra=''; else extra='&_tzfd_enquiry_nonce=0000000000'; fi
  fcode=$(code -X POST -H 'Content-Type: application/x-www-form-urlencoded' -d "action=tzfd_public_enquiry&$forged_body$extra" "$B/wp-admin/admin-post.php")
  if [ "$fcode" = "403" ]; then report PASS "forged public enquiry ($label) refused" "HTTP $fcode"; else report FAIL "forged public enquiry ($label)" "HTTP $fcode (expected 403)"; fi
done

echo "Note: a weak check is labelled above; it does not prove the plugin's own nonce handling."
echo "Summary: $pass passed, $fail failed, $skip skipped"
[ "$fail" -eq 0 ]
