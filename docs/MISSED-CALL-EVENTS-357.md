# Missed-call call events: baseline, task breakdown and status (issue #357)

Refs #357. Baseline taken on main at `0e42a11fa8ab6f58586693d3ab84a68b5c5d8ebc`.

## 1. Baseline (reproduce with grep and the test suites)

- Route: `/titan-zero-front-desk/v1/call-events` is registered in `routes()` in `titan-zero-trades-front-desk/includes/class-tzfd-telephony-adapter.php`. Its permission step is `authorise()`, which runs before the callback.
- Credentials: the only resolver call sites on main are `apply_filters( 'tzfd_telephony_provider_credentials_v1', null, $key_id )` in `class-tzfd-telephony-adapter.php` (two sites) and `class-tzfd-messaging-replies.php`. No `add_filter` for this hook exists in the plugin. The only resolvers are test fixtures in `tests/unit/signed-event-tests.php` and `tests/runtime/runtime-tests.php`.
- Effect: with no resolver installed, `validate()` returns null and the route answers 401. No provider is wired in production code.
- Provider decision: no file in the repository records an approved call-event provider. The owner's comments on #357 (2026-11-18 and 2026-11-22) name Twilio. The Twilio sections of `titan-zero-trades-front-desk/readme.txt` cover SMS only.
- Callback tasks: `receive()` creates one callback task for a `missed` event through `TZFD_Missed_Call_Tasks::record()`. No owner decision in the repository approves call events creating callback tasks.

## 2. Task breakdown

| Task | Scope | Evidence on main |
|---|---|---|
| T1 | Baseline recorded | This section; commands above |
| T2 | Scope split into tasks | This section |
| T3 | Required behaviour preserved: signed requests only, fail closed without a resolver, company from credentials, replay claim, tenant denial before any write | `class-tzfd-telephony-adapter.php` (`authorise`, `validate`, `receive`) |
| T4 | Tests: functional, negative, integration, regression | `tests/unit/signed-event-tests.php` (forged, tampered, stale, replayed, wrong-tenant key id); `tests/runtime/runtime-tests.php` lines 192-234 and 216-221 (missed-call accepted, redelivery, replay, wrong-tenant 403, unknown key 401, wrong secret 401); `tests/integration/contact-db-tests.php` (#30 tasks); CI gate in `.github/workflows/tests.yml` |
| T5 | Implementation commit and passing CI | Commit `c2641dc` (missed-call runtime fixes and CI gate); CI on `0e42a11` completed with success on all runtime, unit, DB integration and syntax jobs |
| T6 | Acceptance and documentation verified | Open: depends on T7 and T8 |
| T7 | Owner decision: approved provider | Owner comments name Twilio. Not recorded in the repository. Pending owner confirmation of the agent-proposed record |
| T8 | Owner decision: call events may create callback tasks | Not recorded. Pending owner confirmation |

## 3. Status

- Provider approval (T7): the owner's comments name Twilio. The agent-proposed record on #357 is pending owner confirmation.
- Callback-task approval (T8): not given. Missed calls currently create callback tasks on main, so the owner must confirm or the behaviour must change.
- No production provider credentials resolver is installed, so no call events are consumed in production today.
