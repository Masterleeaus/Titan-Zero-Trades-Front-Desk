# AI service connector contract: tzfd-ai/1

Status: implemented for the plugin side (`includes/class-tzfd-ai-connector.php`). The hosted service is not built or deployed; every test uses a fake transport (`tests/ai-connector/run-ai-connector-tests.php`).

One connector serves all hosted AI levels in the single Scheduling & Enquiries Manager plugin: `free_ai`, `assistant`, `agent_team`. Local booking never depends on it. Every failure returns a structured result with `use_local => true`.

## Plugin API for feature lanes

```php
$result = TZFD_AI_Connector::request( 'faq.answer', array( 'question' => '...' ) );
if ( $result['ok'] ) { /* use $result['data'] */ } else { /* use local rules; $result['status'] says why */ }
```

Result keys: `ok`, `status`, `use_local` (always true unless ok), `contract`, `data`; plus `missing_scopes` (scope_not_granted). No paid tier gates a feature; only consent, scopes and budget do.

Statuses: `ok`, `feature_not_allowed`, `invalid_request`, `consent_required`, `not_configured`, `scope_not_granted`, `allowance_exhausted`, `busy`, `offline`, `service_error`, `invalid_response`, `contract_unsupported`.

Other methods: `status()` (never returns the secret), `usage( $period )`, `configure( $endpoint, $site_id, $secret )`, `grant_consent( array $scopes )`, `revoke_consent()`, `set_entitlements( array $tiers )`, `disconnect()`, `features()`, `budget_limits()`, `disclosure()`, `events()`, `clear_events()`, `retention_days()`, `set_retention_days( int $days )`.

## Privacy disclosure, event log and retention

- `disclosure()` returns a structured array (provider, data classes, quota, consent version, owner decisions). It is the single definition; it does not return a string. `setup_notice()` builds the notice text and terms link from it, both filterable. The consent wording and the 30-day retention default were approved by the owner on issue #348 (`wording_status` = `owner_approved_issue_348`, `owner_review` = false). The terms link is still a draft pending legal review and is not covered by that approval. Consent is stored with `consent_version` (`tzfd-ai-consent-1`). Consent recorded under any other version is ignored, so a changed disclosure needs a fresh grant. `status()` returns the disclosure array, the version and `enabled` (AI is on by default).
- `events()` returns the redacted event log, newest last. Each entry holds only `at`, `feature`, `status` and `latency_ms`. A feature name that is not in the registry is logged as `unknown`. Neither the payload, the response body nor the secret is logged. The log holds at most 50 entries (`EVENT_LIMIT`).
- `set_retention_days( int $days )` accepts an integer from 1 to 365 (default 30). Events older than the window are pruned on the next write. Other integers and non-integer values are refused.
- `clear_events()` deletes the whole log. Revocation and `disconnect()` do not clear it. Usage counters and monthly budgets are kept separately and are not pruned by retention.
- Logging is best effort. It is skipped when the budget lock is busy, so it never delays or fails a request.

## Feature registry

| Feature | Scopes required | Monthly budget (pilot) |
|---|---|---|
| `faq.answer` | faq.read | model_fallback 200 |
| `faq.embed_query` | faq.read | embedding 800 |
| `faq.suggest_batch` | faq.read | faq_batch 16 |
| `insight.monthly` | reports.read | insight 4 |
| `assistant.turn` | faq.read, customer.read | none (server meters) |
| `agent.run` | booking.read, booking.write, customer.read | none (server meters) |

Budgets are the standing per-site monthly contract figures (200 / 800 / 16 / 4). The hosted service stays authoritative. The local check only prevents needless calls.

## Consent and permissions

- Consent is off by default. `grant_consent()` must be called by a site administrator, with the scopes they accept. Unknown scopes are dropped.
- The model never grants itself a scope. Write scopes (`booking.write`) need an explicit admin grant.
- `revoke_consent()` takes effect on the next call. No transport call is made after revocation.
- Entitlements (`free_ai`, `assistant`, `agent_team`) are set from the hosted service's signed entitlement response. The connector does not infer one tier from another.

## Wire format

`POST <endpoint>` (https only), `Content-Type: application/json`.

Request headers:

| Header | Value |
|---|---|
| `X-TZFD-Contract` | `tzfd-ai/1` |
| `X-TZFD-Site` | site id (`^[A-Za-z0-9_.-]{1,64}$`) |
| `X-TZFD-Timestamp` | Unix seconds |
| `X-TZFD-Nonce` | 32 random hex characters, new per request |
| `X-TZFD-Signature` | `sha256=` + HMAC-SHA256 of `<timestamp>.<nonce>.<body>`, keyed with the site secret |

Request body (max 16 KB):

```json
{"contract":"tzfd-ai/1","site":"<site id>","feature":"faq.answer",
 "consent":{"version":"tzfd-ai-consent-1","scopes":["faq.read"]},
 "payload":{ ... }}
```

Response body (max 64 KB), HTTP 200 required:

```json
{"contract":"tzfd-ai/1","site":"<site id>","status":"ok|allowance_exhausted|service_error|contract_unsupported",
 "data":{ ... },
 "usage":{"tokens_in":0,"tokens_out":0,"embedding_tokens":0,"cache_hit":false}}
```

Response headers `X-TZFD-Timestamp`, `X-TZFD-Nonce` (must echo the request nonce) and `X-TZFD-Signature` (over `<timestamp>.<nonce>.<body>`) are all required. The plugin rejects a response when:

- the signature does not match, or the timestamp is more than 300 seconds from the site clock;
- the nonce is not the one just sent (replayed or cross-request response);
- the body names a different site (tenant mismatch);
- the body is not JSON, is over 64 KB, or carries an unknown status.

## Budget and concurrency

- Reservation happens before any transport call. Each reservation is a read-modify-write under an atomic `add_option` lock (`tzfd_ai_budget_lock`, stale after 10 seconds). A request that cannot take the lock returns `busy` without a call.
- A reservation is released only on `offline` or `invalid_response`, when nothing reached the model. Other outcomes keep it, which is conservative.
- Usage counters are kept per month in `tzfd_ai_usage_YYYY-MM` (autoload off). Counters are best effort and skipped when the lock is busy. The month 13 back is removed on write.

## Fail-safe rules

- Unset endpoint, missing secret, or no consent: returns before any transport call.
- Transport error or timeout: `offline`, with `use_local => true`.
- Secret is sent only in the HMAC, never in a result, status, usage record, or log line.

## Server obligations (not in this plugin)

The hosted service must check the nonce for replay within the signature window, check tenant scope from `X-TZFD-Site`, enforce its own budgets before provider calls, and sign every response. Endpoints, secrets, pairing tokens and checkout are out of scope for this contract.

## Known gaps

- No admin screen yet. Connect, disconnect and consent controls belong to the AI Services view (#46).
- No approved-answer cache or cost report yet (#43 acceptance). Usage records token counts only; no cost calculation.
- Entitlement fetch and pairing handshake are not implemented.
