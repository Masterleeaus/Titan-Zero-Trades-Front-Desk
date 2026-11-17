#!/usr/bin/env bash
# Concurrent replay check for the signed missed-call webhook (issue #52).
# Sends N identical, validly signed requests at the same moment. Exactly one must be accepted (2xx)
# and every other must be refused with 401. Run on the disposable test site only.
#   B=http://localhost:8080 SECRET=<inbound secret shown once in settings> N=8 ./replay-concurrency-test.sh
# The test site must run with several workers, e.g. PHP_CLI_SERVER_WORKERS=8 php -S localhost:8080 -t <wp>.
# PHP's built-in server defaults to one worker, which serialises requests and hides the race.
B="${B:-http://localhost:8080}"
N="${N:-8}"
if [ -z "${SECRET:-}" ]; then echo "Set SECRET to the inbound secret from the test site."; exit 2; fi

BODY='{"event":"missed","from":"+61400000000"}'
TS="$(date +%s)"
SIG="sha256=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $NF}')"
DIR="$(mktemp -d)"

for i in $(seq 1 "$N"); do
  ( curl -s -o /dev/null -w '%{http_code}' -X POST \
      -H 'Content-Type: application/json' \
      -H "X-TZFD-Timestamp: $TS" \
      -H "X-TZFD-Signature: $SIG" \
      -d "$BODY" "$B/?rest_route=/titan-zero-front-desk/v1/missed-call" > "$DIR/$i" ) &
done
wait

accepted=0; refused=0; other=0
for i in $(seq 1 "$N"); do
  code="$(cat "$DIR/$i")"
  case "$code" in
    2*) accepted=$((accepted+1)) ;;
    401) refused=$((refused+1)) ;;
    *) other=$((other+1)) ;;
  esac
done
rm -rf "$DIR"

echo "accepted=$accepted refused_401=$refused other=$other (expected accepted=1 refused_401=$((N-1)))"
if [ "$accepted" -eq 1 ] && [ "$refused" -eq $((N-1)) ] && [ "$other" -eq 0 ]; then
  echo "PASS concurrent identical signed requests: one accepted"
  exit 0
fi
echo "FAIL concurrent identical signed requests"
exit 1
