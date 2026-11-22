# Runtime readiness: diff, threat model, booking-safety report (issue #370)

Recorded 2026-11-23 on branch `a19/issue-149-runtime-harness` (merged with main at `e352141`) and branch `b17/issue-370-runtime-matrix`.
Scope: the remaining items from #252 (which came from #149 and #173). This file is a record of what was measured and tested. It is not a
WordPress.org review, a security audit, or a sign-off for production.

## 1. Measured diff (v3.0.128 to main)

Command: `git diff --numstat 22dc106 origin/main -- titan-zero-trades-front-desk`

- 213 plugin files changed: 78 added, 15 deleted, 120 modified.
- +29,194 / -7,359 lines in total.
- The largest single line count is the generated translation template `languages/titan-zero-trades-front-desk.pot` (+10,151).
- Largest new code files: `includes/ai-assist/` (+844), `class-tzfd-ai-connector.php` (+598), `class-tzfd-legacy-ai-data.php` (+550), `class-tzfd-enquiry-triage-rules.php` (+502), `class-tzfd-faq-retrieval.php` (+489).
- The legacy AI code is still present (removal is not in scope: owner decision on #360).

## 2. Threat model (surfaces and what tests cover)

| Surface | Control in code | Test evidence | Gap |
|---|---|---|---|
| Public booking and callback forms | Nonce checks (26 files call a nonce check); slot holds are company-scoped (`company_id`, `token_hash`, unique key) | `wp-runtime-tests.php`, `slot-holds-schema-runtime-tests.php`, `http-auth-tests.sh` | Oversell through the full public form (A18) is not driven end to end. Moved to the follow-up issue for #370. |
| REST and admin-ajax routes | Authorisation inventory test over every route and handler | `tests/unit/test-rest-ajax-authz-inventory.php` (static), `http-auth-tests.sh` (HTTP) | The HTTP check of a bad nonce on a logged-in request (E6) runs only in `http-auth-tests.sh`; the admin-ajax name check is weak (see README). |
| Missed-call and Twilio webhooks | Signed requests with a stored inbound secret (`inbound_secret`); a replayed request is refused | `runtime-tests.php`, `replay-concurrency-test.sh`, `twilio-status-replay-concurrency-test.sh` | Real provider delivery is not called by design. |
| Cancel and reschedule links | Per-booking token; state checks; compare-and-swap | `wp-runtime-tests.php`, `cancel-reschedule-assistant-tests.php` | End-to-end customer flow with mail capture (B13) not driven. |
| Privacy export and erase | Export and erase run as the site user; checked in the suites listed | `runtime-tests.php`, `tests/unit/privacy-coverage-tests.php` | Covered by these checks; no claim beyond them. |
| Hosted AI connector | Pairing code shown by Supercharged and entered by the site owner (decision #347); consent records kept 30 days (decision #348) | `tests/ai-connector/run-ai-connector-tests.php`, `legacy-ai-runtime-tests.php` | No live hosted endpoint is called. |
| Multisite tenancy | Network-aware provisioning | Multisite runtime suite (392 passed, 0 failed on the merged PR #346 branch, see tests/runtime/README.md) | Upgrade with existing data is not proven (owner decision: not needed, no customers yet). |

Not in this model: refunds and deposits (owner decision: they belong to the invoicing and payments plugin, not this one).

## 3. Booking-safety report (no lost bookings)

What was tested on the disposable site (2026-11-23 runs, PHP 8.3.6, WordPress 7.1.5, MariaDB 10.11.14):

- Booking create, capacity lock and slot holds: PASS (single and multisite suites).
- Slot-hold table install on a second run issues no ALTER and no `SET DEFAULT` on `id`: PASS (5 of 5 checks).
- Reschedule and cancel tokens, and the reminder outbox: PASS. The reminder dispatch used a local transport stub, so no real message was sent.
- Concurrent replay of a signed request: PASS (`replay-concurrency-test.sh`).

What was not tested, so no claim is made:

- Booking loss during an upgrade from v3.0.128 with existing bookings. The owner decided this proof is not needed (no customers yet), so it is dropped from scope.
- End-to-end public-form oversell (A18) and customer cancel and reschedule (B13).

Verdict: the booking primitives keep data consistent in the tests above. The no-loss claim for the public form is not yet proven.

## 4. Production-readiness assessment

Verdict: **not verified for production.** The checks above pass on the disposable site, but these are still open and tracked in the follow-up issue for #370:

- Customer-facing acceptance through the public form (A18), customer cancel and reschedule end to end (B13), and reminder dispatch against a real transport (C11).
- Automated acceptance, regression, malicious-input, tenant and permission, and outage tests for the full runtime surface (#149) and for the legacy AI data (#173).
- PHP and database coverage: the CI matrix in `.github/workflows/tests.yml` ran the runtime suite green on six entries (PHP 8.1, 8.2, 8.3 and 8.4 on latest WordPress; PHP 8.3 on WordPress 6.6; PHP 8.3 on MySQL 8.0) on commit `50d382d`.
- PHP 7.4 unit job: it failed on `tests/unit/test-retention-downgrade-multisite.php` (a private method invoked without `setAccessible`) before main was updated. On the PR #426 merge commit (`d6ccbd7`) the job passed.

Decisions made in this pass are logged in `/home/claude/supercharged-fixes-checklist.md` with the date.

## 5. Missed-call provider decision and callback tasks (issue #357)

Owner decision (#357): the approved missed-call provider is Twilio, and a missed-call event may create a callback task automatically. The route does not send an SMS and does not contact the caller.

Baseline on main before this change (checked 2026-11-23):

- No Twilio voice call-status route existed. Twilio's only inbound route was the SMS delivery status (`tzfd/v1/twilio-status`).
- No credentials resolver is installed for `tzfd_telephony_provider_credentials_v1`, so `/titan-zero-front-desk/v1/call-events` refuses every request. It fails closed.
- No owner decision was recorded in the repository.

| Task | Status on this branch |
| --- | --- |
| Record the owner decision (Twilio; callback task may be automatic) | Done (this section) |
| Twilio voice status route `tzfd/v1/twilio-call-status`: verifies `X-Twilio-Signature`, requires this site's `AccountSid`, maps `no-answer` and `busy` to one missed event and one callback task | Done: `includes/class-tzfd-twilio-call-events.php`, booted from `titan-zero-front-desk.php` |
| Off by default: needs `TZFD_TWILIO_SMS_ENABLED` and `TZFD_TWILIO_CALL_STATUS_CALLBACK_URL` (the exact route URL, https) | Done |
| Functional, negative and replay tests: URL, payload, forged and wrong-URL signatures, wrong tenant, failed-write retry, one task per call, no outbound call | Done: `tests/unit/twilio-call-events-tests.php` (runs in the unit job) |
| Per-call task idempotency on real tables | Existing: `tests/integration/contact-db-tests.php` (DB integration job) |
| Generic call-events credentials resolver (`tzfd_telephony_provider_credentials_v1`) | Not installed. The generic route stays fail closed. Out of scope for #357 |
| Live Twilio account test with a real missed call | Needs the owner's Twilio account and number; not run |

Owner setup: define `TZFD_TWILIO_CALL_STATUS_CALLBACK_URL` in `wp-config.php` as `https://<site>/wp-json/tzfd/v1/twilio-call-status`, and point the Twilio number's voice status callback at the same URL. The route acts only on a final `no-answer` or `busy` status.
