# Threat model and readiness report (issue #334, from #263, #7, #8)

Status date: 2026-11-23. Scope: the free local booking path of Titan Zero Trades: Front Desk, with the AI downgrade
path as it affects that path. Evidence below is from this repository at the commit of the PR that closes #334.

## Assets

- Booking rows and their posted data (customer name, e-mail, phone, notes, service address).
- Local customer records (CRM rows, scoped by company).
- Calendar capacity per form (oversell protection).
- Public links sent by e-mail (cancel and reschedule).
- Plugin settings, including the missed-call inbound secret and any AI credential.

## Trust boundaries and threats

| Entry point | Threat | Control in the code | Evidence |
|---|---|---|---|
| Public enquiry form (admin-post, no login) | Spam, forged company, oversubmission | Nonce, honeypot, consent required, one enquiry per address per company per minute (atomic claim), company must resolve | `includes/class-tzfd-lead-intake.php` `submit_public()`; `tests/unit/test-rate-limit.php` |
| Booking submit path (pipeline intake) | Two customers take the same slot | Per-form capacity lock; occupancy re-counted under the lock; `slot_taken` refusal | `includes/class-tzfd-pipeline.php` `intake()`; runtime check A18 in `tests/runtime/wp-runtime-tests.php` |
| Customer cancel link (GET preview, POST confirm) | Forged or reused link, cancel of another booking, replay | Token bound to booking id, e-mail, booking version and expiry; POST nonce per booking; compare-and-swap on the row; cancel stages one outbox event | `includes/class-tzfd-customer-cancellation.php`; runtime checks B4 to B12 and B13a to B13d |
| Customer reschedule link | Same as cancel, plus moving into a taken slot | Token bound to version and expiry; POST nonce; capacity lock shared with intake | `includes/class-tzfd-customer-rescheduling.php`; runtime checks B1 to B3 (end to end not yet driven, B13e) |
| Customer record access (CRM-linked bookings) | Session switch to another company reads a customer | A customer is exposed only when its stored company_id equals the request company as an exact integer | `includes/class-tzfd-workspace-customer-owner.php`; `tests/unit/test-customer-record-exposure.php` (session switch, lookalike ids, missing or forged company) |
| Admin and staff screens | Subscriber reads or changes data | Capability checks; REST and AJAX authorisation inventory | `tests/unit/test-rest-ajax-authz-inventory.php`; `tests/runtime/http-auth-tests.sh` |
| Signed inbound calls (missed-call add-on) | Replayed or forged signed request | Signature check, replay refusal under concurrency | `tests/runtime/replay-concurrency-test.sh`; `tests/security/webhook-adversarial-tests.php` |
| AI downgrade (entitlement lost) | Paid features keep running, or free booking breaks | Level logic resolves only a cached, verified entitlement; free booking path does not depend on AI | `docs/AI-SERVICES-ENTITLEMENT-AND-DOWNGRADE.md`; `tests/unit/test-retention-downgrade-multisite.php` |
| Uninstall | Data removed without consent | Destructive wipe only with the opt-in; without it bookings and settings stay | runtime F1 to F3; `tests/runtime/uninstall-wipe-check.php` |

## Tested downgrade

- Unit: `tests/unit/test-retention-downgrade-multisite.php` (run by `tests/unit/run.php`) passes as part of the
  local-free suite (723 passed, 0 failed on this commit).
- Runtime: the free booking path is run on real WordPress with no AI entitlement (runtime suites in
  `tests/runtime/run-wp-runtime.sh`), so the local path is exercised without the hosted service.

## Verified readiness (this run)

| Lane | Result |
|---|---|
| `php tests/unit/run.php` | exit 0; 723 local-free assertions and 177 AI case assertions, 0 failed |
| Runtime, single site, latest WordPress 7.1.5, PHP 8.3.6, MariaDB 10.11 | 380 passed, 0 failed, 8 skipped |
| Runtime, multisite, WordPress 7.1.5, PHP 8.3.6 | 381 passed, 0 failed, 6 skipped |
| Runtime, minimum supported WordPress 6.6 (plugin header "Requires at least"), PHP 8.3.6 | 380 passed, 0 failed, 8 skipped |

## Not verified (open)

- PHP 8.1, 8.2 and 8.4: not provisioned in the local environment (only PHP 8.3.6 is installed). Not claimed as tested.
- Customer reschedule end to end (B13e): not driven. The engine refuses bookings made through the submit path
  (`unsupported_booking`); a stored single-appointment record in the engine's shape is needed. The token rules are tested.
- Live providers (SMS, e-mail transport, telephony, hosted AI) are not called by design.
- Weak check kept as stated in `tests/runtime/README.md`: the admin-ajax action name in `http-auth-tests.sh`
  does not prove the plugin's own nonce handling.
- dbDelta on MariaDB 10.11 prints `Invalid default value for 'id'` when the slot-hold table is installed. The schema
  check passes. Not fixed here.

## Decisions taken for this report

- The setup-page discovery check now follows the stored facts (a link is shown only when a service or calendar
  exists). The previous assertion assumed an empty site, which the runtime harness does not provide.
- The runtime runner passes its URL to site provisioning, so multisite runs on a port other than 8080 work.
- Provisioning accepts `TZFD_RT_WP_VERSION` to pin a WordPress core version for the version matrix.
