#!/usr/bin/env bash
# Concurrent replay check for the signed Twilio delivery-status webhook (issue #188).
# Sends N identical, validly signed status callbacks at the same moment for one delivery receipt.
# Exactly one may write the row (response state "delivered"). Every other request must be a replay
# (state "replayed"). None may return a conflict (409) or any other status, because a conflict would show
# that two requests both passed the replay check and raced on the row.
#
# Run on a disposable test site only, with several PHP workers so the requests really overlap:
#   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8091 -t <copy of the WordPress site>
# The site must define TZFD_TWILIO_SMS_ENABLED, TZFD_TWILIO_ACCOUNT_SID, TZFD_TWILIO_AUTH_TOKEN, TZFD_TWILIO_FROM and
# TZFD_TWILIO_STATUS_CALLBACK_URL (an https URL), and filter rest_url so that URL is the one it names.
#   B=http://127.0.0.1:8091 AUTH_TOKEN=<twilio token set on the site> STATUS_URL=https://example.test/wp-json/tzfd/v1/twilio-status \
#     WP_CLI="php /path/wp-cli.phar --allow-root --path=<copy>" N=8 ./twilio-status-replay-concurrency-test.sh
B="${B:-http://127.0.0.1:8091}"
N="${N:-8}"
WP_CLI="${WP_CLI:-}"
if [ -z "${AUTH_TOKEN:-}" ] || [ -z "${STATUS_URL:-}" ]; then echo "Set AUTH_TOKEN and STATUS_URL (see header)."; exit 2; fi

# A fresh receipt per run, in state "accepted", so the first valid callback has something to change.
SID="SM$(openssl rand -hex 16)"
if [ -n "$WP_CLI" ]; then
  SID_ENV="$SID" $WP_CLI eval 'global $wpdb; $t = TZFD_Telephone_Delivery::table(); $company = (int) TZFD_Scoped_Store::company_id();
    $wpdb->insert( $t, array( "company_id" => $company, "event_hash" => hash( "sha256", getenv("SID_ENV") ), "call_id" => 1, "action_type" => "callback",
      "recipient_id" => 1, "state" => "accepted", "available_at" => current_time( "mysql", true ), "receipt_id" => getenv("SID_ENV") ) );
    echo $wpdb->insert_id ? "row ok\n" : "row FAILED\n";' --user=1 2>&1 | tail -1
fi

BODY="MessageSid=$SID&MessageStatus=delivered"
# Twilio signature: base64 HMAC-SHA1 over the callback URL followed by the sorted parameter name and value pairs.
SIG="$(printf '%s%s%s%s' "$STATUS_URL" MessageSid "$SID" "MessageStatusdelivered" | openssl dgst -sha1 -hmac "$AUTH_TOKEN" -binary | base64)"
DIR="$(mktemp -d)"

for i in $(seq 1 "$N"); do
  ( curl -s -w '\n%{http_code}' -X POST \
      -H 'Content-Type: application/x-www-form-urlencoded' \
      -H "X-Twilio-Signature: $SIG" \
      -d "$BODY" "$B/?rest_route=/tzfd/v1/twilio-status" > "$DIR/$i" ) &
done
wait

delivered=0; replayed=0; other=0
for i in $(seq 1 "$N"); do
  code="$(tail -n1 "$DIR/$i")"
  json="$(head -n -1 "$DIR/$i")"
  echo "request $i: HTTP $code $json"
  state="$(printf '%s' "$json" | sed -n 's/.*"state":"\([a-z]*\)".*/\1/p')"
  if [ "$code" = 200 ] && [ "$state" = delivered ]; then delivered=$((delivered+1));
  elif [ "$code" = 200 ] && [ "$state" = replayed ]; then replayed=$((replayed+1));
  else other=$((other+1)); fi
done
rm -rf "$DIR"

echo "delivered=$delivered replayed=$replayed other=$other (expected delivered=1 replayed=$((N-1)) other=0)"
if [ "$delivered" -eq 1 ] && [ "$replayed" -eq $((N-1)) ] && [ "$other" -eq 0 ]; then
  echo "PASS concurrent identical Twilio status callbacks: one write, the rest replayed"
  exit 0
fi
echo "FAIL concurrent identical Twilio status callbacks"
exit 1
