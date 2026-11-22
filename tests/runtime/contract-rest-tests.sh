#!/usr/bin/env bash
# HTTP checks for the contract v1 REST route (POST /titan-zero-front-desk/v1/contract, issue #349).
# Signed envelopes are built with contract-rest-client.php, which uses the plugin's own contract class.
# Needs the test-only credentials and executor installed by run-wp-runtime.sh (mu-plugin tzfd-test-contract.php):
#   company 7: owner with tz_fd_access only, key TZFD_RT_CONTRACT_KEY; executor answers booking.list only.
#   company 8: no credentials.
# Env: B or TZFD_RT_URL (base URL), TZFD_RT_CONTRACT_KEY (32+ bytes). Test site only.
# Note: the signed fixture in tests/contract/fixtures has a 2027 timestamp, outside the 300 s window, so this
# check signs fresh envelopes with the same rules.
B="${B:-${TZFD_RT_URL:-}}"
KEY="${TZFD_RT_CONTRACT_KEY:-}"
if [ -z "$B" ] || [ -z "$KEY" ]; then
  echo "FAIL harness -- set B (or TZFD_RT_URL) and TZFD_RT_CONTRACT_KEY" >&2
  exit 2
fi
HERE="$(cd "$(dirname "$0")" && pwd)"
CLIENT="$HERE/contract-rest-client.php"
URL="$B/?rest_route=/titan-zero-front-desk/v1/contract"
WORK="$(mktemp -d /tmp/tzfd-contract.XXXXXX)"
pass=0; fail=0
report() { # status name detail
  case "$1" in
    PASS) echo "PASS $2 -- $3"; pass=$((pass+1)) ;;
    *)    echo "FAIL $2 -- $3"; fail=$((fail+1)) ;;
  esac
}
NOW="$(date +%s)"
# Random nonce per call (checks run in subshells, so a counter would repeat). 16 to 128 URL-safe characters.
nonce() { printf 'contract-%s' "$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"; }
sign() { # op tenant payload-json ts nonce [key] [version]
  php "$CLIENT" sign "$1" "$2" "$3" "${6:-$KEY}" "$4" "$5" ${7:-} 2>/dev/null
}
post() { # file content-type name -> sets HTTP_CODE and writes body to $WORK/resp.json
  local f="$1" ct="$2"
  HTTP_CODE="$(curl -s --max-time 30 -o "$WORK/resp.json" -w '%{http_code}' -X POST -H "Content-Type: $ct" --data-binary "@$f" "$URL" || true)"
  HTTP_CODE="${HTTP_CODE:-000}"
}
reason_of() { php -r '$j = json_decode((string) file_get_contents($argv[1]), true); echo is_array($j) ? (($j["code"] ?? "") ?: ($j["reason"] ?? "")) : "";' "$WORK/resp.json" 2>/dev/null; }
expect() { # name, expected-status, expected-reason-substring (optional)
  local name="$1" want="$2" why="$3"
  local got_reason; got_reason="$(reason_of)"
  if [ "$HTTP_CODE" != "$want" ]; then report FAIL "$name" "HTTP $HTTP_CODE, wanted $want (reason '${got_reason}')"; return 1; fi
  if [ -n "$why" ] && [[ "$got_reason" != *"$why"* ]]; then report FAIL "$name" "HTTP $HTTP_CODE, reason '${got_reason}', wanted '$why'"; return 1; fi
  report PASS "$name" "HTTP $HTTP_CODE${got_reason:+, $got_reason}"
}
body() { printf '%s' "$1" > "$WORK/$2.json"; echo "$WORK/$2.json"; }

# 1. Transport refusals.
f=$(body '{"v":"1.0.0"}' unsigned); post "$f" application/json; expect "contract: unsigned body refused" 401 ""
f=$(body '{"v":' malformed); post "$f" application/json; expect "contract: malformed JSON refused" 400 ""
f=$(sign booking.list 7 '{"page":1}' "$NOW" "$(nonce)" > "$WORK/ct.json"; body "$(cat "$WORK/ct.json")" wrongtype); post "$f" text/plain; expect "contract: non-JSON content type refused" 400 ""
f=$(body "$(head -c 70000 /dev/zero | tr '\0' ' ')" oversize); post "$f" application/json; expect "contract: oversized body refused" 400 ""
code="$(curl -s --max-time 30 -o /dev/null -w '%{http_code}' "$URL" || true)"
if [[ "$code" =~ ^4[0-9][0-9]$ ]]; then report PASS "contract: GET not accepted" "HTTP $code"; else report FAIL "contract: GET not accepted" "HTTP ${code:-000}"; fi

# 2. Signature, clock, and company checks.
f=$(sign booking.list 8 '{"page":1}' "$NOW" "$(nonce)" > "$WORK/t8.json"; body "$(cat "$WORK/t8.json")" tenant8); post "$f" application/json; expect "contract: company without credentials refused" 401 "contract_unauthorised"
f=$(sign booking.list 7 '{"page":1}' "$NOW" "$(nonce)" "wrong-key-wrong-key-wrong-key-00" > "$WORK/wk.json"; body "$(cat "$WORK/wk.json")" wrongkey); post "$f" application/json; expect "contract: wrong key refused" 401 "signature_invalid"
f=$(sign booking.list 7 '{"page":1}' "$((NOW-400))" "$(nonce)" > "$WORK/stale.json"; body "$(cat "$WORK/stale.json")" stale); post "$f" application/json; expect "contract: stale timestamp refused" 401 "timestamp_outside_window"
f=$(sign db.raw 7 '{}' "$NOW" "$(nonce)" > "$WORK/unk.json"; body "$(cat "$WORK/unk.json")" unknownop); post "$f" application/json; expect "contract: unknown operation refused" 401 "unknown_operation"
sign booking.list 7 '{"page":1}' "$NOW" "$(nonce)" > "$WORK/tamper.json"
php -r '$j = json_decode(file_get_contents($argv[1]), true); $j["payload"]["page"] = 2; echo json_encode($j);' "$WORK/tamper.json" > "$WORK/tamper2.json"
post "$WORK/tamper2.json" application/json; expect "contract: tampered payload refused" 401 "signature_invalid"

# 3. Happy path, reply signature, and replay.
ok_nonce="$(nonce)"
sign booking.list 7 '{"page":1}' "$NOW" "$ok_nonce" > "$WORK/ok.json"
post "$WORK/ok.json" application/json; expect "contract: signed booking.list accepted" 200 "ok"
if php "$CLIENT" verify-reply "$WORK/resp.json" "$KEY" "$ok_nonce"; then report PASS "contract: signed reply verifies and echoes nonce" "reply signature checked"; else report FAIL "contract: signed reply verifies and echoes nonce" "reply signature did not verify"; fi
post "$WORK/ok.json" application/json; expect "contract: replayed envelope refused" 409 "contract_replay"

# 4. Authorisation at the wire (owner has tz_fd_access only).
f=$(sign privacy.export 7 '{}' "$NOW" "$(nonce)" > "$WORK/priv.json"; body "$(cat "$WORK/priv.json")" priv); post "$f" application/json; expect "contract: owner without manage_options refused for privacy.export" 403 "capability_missing"
priv_nonce="$(nonce)"
sign privacy.export 7 '{}' "$NOW" "$priv_nonce" > "$WORK/priv2.json"
post "$WORK/priv2.json" application/json
post "$WORK/priv2.json" application/json; expect "contract: refused call burns its nonce" 409 "contract_replay"
f=$(sign staff.list 7 '{}' "$NOW" "$(nonce)" > "$WORK/staff.json"; body "$(cat "$WORK/staff.json")" staff); post "$f" application/json; expect "contract: registered op with no executor answers 501" 501 "operation_not_bound"

rm -rf "$WORK"
echo "Summary: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
