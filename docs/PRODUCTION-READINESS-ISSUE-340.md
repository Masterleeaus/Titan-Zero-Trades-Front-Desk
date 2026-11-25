# Production-readiness assessment: remaining REST/AJAX review work (issue #340)

Parent issues: #340 (this), #151 (authorisation review, parent of this), #8 and #57 (source). Authorisation inventory and `.json`-only legacy restore: PR #317 (merge 4a5b87a).

## Scope of this change

Scope is limited to the outage and degradation gap that #151 listed as not evidenced: the optional hosted AI connector must never break local bookings. Local features are not gated by AI state (see `docs/AI-SERVICES-ENTITLEMENT-AND-DOWNGRADE.md`, "Local features are never gated").

| File | Change |
|---|---|
| `titan-zero-trades-front-desk/includes/class-tzfd-ai-connector.php` | `dispatch()` wraps the transport call in `try/catch (Throwable)`. A throwing transport is reported as `offline` with `use_local`, and the budget reservation is released through the existing `offline` branch. |
| `tests/unit/test-ai-outage-degradation.php` | New. 28 assertions: status matrix for outage shapes, throwing transport, no transport, local features stay on. Runs under `tests/unit/run.php` and directly. |
| `docs/PRODUCTION-READINESS-ISSUE-340.md` | This document. |

Measured diff: 1 source file changed (6 insertions, 1 deletion), 1 test file added, 1 doc added. No booking, schema or option changes.

## Threat model (outage and transport paths)

| Threat | Control | Evidence |
|---|---|---|
| Service down or unreachable | Transport error maps to `offline`, `use_local` true | Matrix test, transport reports offline |
| Service returns 5xx | Maps to `service_error`, `use_local` true, no data used | Matrix test, HTTP 500 and 503 |
| Empty or oversize response | Maps to `invalid_response`, `use_local` true, budget released | Matrix test, empty 200 and 70 000-byte 200 |
| Non-array transport answer | Maps to `offline` | Matrix test |
| HTTP layer throws | Now caught, mapped to `offline`, budget released (fixed in this change) | `transport throws` test fails without the fix (3 failures) and passes with it |
| No WordPress HTTP API | Degrades to `offline`, makes no network call | No-transport test |
| Outage blocks local bookings | `TZFD_AI_Services::local_features_enabled()` returns true for every state | Local-features test |
| Budget exhaustion through repeated failure | Budget released on `offline` and `invalid_response`; kept on `service_error` (see decisions) | Existing connector suite, 86 assertions |
| Replay or tampered response | Signature, nonce and contract checks in `interpret()` | Existing connector suite and security suite (57 assertions) |

## Test evidence (run on this branch)

| Command | Result |
|---|---|
| `php -l` on the connector and the new test | No syntax errors |
| `php tests/unit/run.php` | exit 0. Local-free suite 772 passed, 0 failed. Free AI cases 177 passed, 0 failed |
| `php tests/unit/test-ai-outage-degradation.php` | exit 0, 28 passed, 0 failed |
| Every `tests/unit/*.php` run directly | all exit 0 |
| `tests/ai-connector/run-ai-connector-tests.php` | 86 passed, 0 failed |
| `tests/ai-services/run-contract-tests.php` (downgrade and entitlement fixtures) | PASS 73, FAIL 0 |
| `tests/ai-assist/run-tests.php` | 102 passed, 0 failed |
| `tests/hosted-ai-legacy/legacy-ai-tests.php` | 62 passed, 0 failed |
| `tests/security/webhook-adversarial-tests.php` | 57 passed, 0 failed |
| `tests/contract/service-contract-tests.php` | 88 passed, 0 failed |

Not evidenced in this pass: a live WordPress runtime outage run (the runtime harness needs a provisioned site and is run in CI). The outage behaviour is covered by the offline suites above.

## Readiness assessment

- Authorisation (#151): inventory test from PR #317 is on main and passes. No unguarded handler was found in that review.
- Outage handling: now covered for every failure shape the connector can receive. A transport exception no longer escapes into the booking flow.
- Downgrade: the entitlement fixtures keep local features on in every state (tested). Booking data handling on downgrade is not changed by this issue, and was not re-tested here.
- Open items before a release sign-off: the live WordPress runtime check should be confirmed green in CI on this PR.

## Decisions for review

1. A transport exception is treated as `offline` (not as `service_error`), so the reserved budget is released. Reason: nothing reached the model.
2. HTTP 5xx (`service_error`) keeps the budget reservation, as before. Reason: the service may have started a model call before failing. Changing this would be a separate decision.
3. Outage coverage is added to the offline unit suite only. No change to the WordPress runtime harness in this issue.
