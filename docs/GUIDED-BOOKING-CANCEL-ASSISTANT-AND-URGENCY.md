# Guided booking, cancel/reschedule assistant and lead urgency

Scope: issue #336 (remaining work from #287). All three features are deterministic. None of them calls a hosted AI model, and none of them stores customer text for the guidance itself.

## Guided booking step

- Code: `includes/class-tzfd-guided-booking.php` (rules, step logic, confirmation token, submit gate) and `includes/class-tzfd-guided-booking-runtime.php` (REST entry point).
- Route: `POST /wp-json/tzfd/v1/guided-booking/step`, parameters `text` and optional `service_id`.
- Behaviour: detects intent with fixed rules. Cancel, reschedule and rebook wording is checked before booking wording, so "reschedule my appointment" never starts a new booking. Returns one of `handoff`, `fallback`, `choose_service`, `confirm_service` or `show_availability`. Service IDs come only from the owner's list.
- Service list: supplied by the `tzfd_guided_booking_services` filter, records of `id`, `name`, `duration_minutes`. The default is empty. With no list, a booking request gets the fallback message and no service is invented. The resource catalogue stores service IDs only, with no names or durations, so the filter is the current source.
- Not yet wired: the step does not drive the booking form. Availability (`TZFD_Guided_Booking::propose()`), the confirmation summary and the submit gate (`TZFD_Guided_Booking::submit()`) exist as pure functions but no front-end or persistence path calls them yet.
- Open decision before wiring (#441): the service finder (`TZFD_Service_Finder`, `tzfd_service_id`) uses integer database IDs, while the guided flow and the resource catalogue use string IDs (`TZFD_Resource_Catalog::identifier`). The two ID spaces do not match, so the finder's `tzfd_service_id` cannot be checked against the confirmation token until an owner decides how they map. Also open: the source of the complete company-scoped reservation snapshot, and the hook into the legacy booking submit handler (`tzfd-main-class.inc.php`, which runs captcha and double-booking checks).

## Cancel/reschedule assistant

- Code: `includes/class-tzfd-cancel-reschedule-assistant.php`.
- Route: `POST /wp-json/tzfd/v1/cancel-assistant/plan`, parameter `text`.
- Behaviour: classifies the message and returns policy text and a message. A cancel or reschedule request gets the "use the secure link we emailed you" step. Cancel-and-rebook gets a hand-off to staff. Plain enquiries get `not_applicable`. The route never looks up a booking and never returns a change action. A phone number in the text does not authorise anything.
- Policy hours: the `tzfd_cancel_policy` filter (`cancel_notice_hours`, `reschedule_notice_hours`, integers 0 to 8760). Without valid values the text asks the customer to contact the business.
- Ownership: checked only by the existing signed-link flows (`TZFD_Customer_Cancellation`, `TZFD_Customer_Rescheduling`). The route does not validate ownership, so the item "validate booking ownership" in the issue stays open.

## Lead urgency

- Code: `includes/class-tzfd-enquiry-triage-rules.php` and `includes/class-tzfd-enquiry-triage.php`.
- Rules: explicit urgent phrases, complaints, callback requests and expiring dates within 14 days. Negated phrases ("not urgent") do not count.
- Priority override: an administrator can mark an enquiry urgent or not urgent. The override wins over the rule result in both directions. The precedence is `TZFD_Enquiry_Triage_Rules::effective_urgency()`.
- Alerts: off unless the administrator opts in (`tz_fd_urgent_alert_enabled`, exact value `1`). At most one alert per company per 900 seconds (throttle in `alert_decision()`).

## Privacy and consent

- Both REST routes are read-only and write nothing. Responses are sent with `Cache-Control: private, no-store`.
- Both routes need a valid REST nonce (`X-WP-Nonce`, action `wp_rest`). Logged-out visitors can obtain one, so the public booking page can use the routes without a login. Anonymous calls without the nonce are refused.
- Responses contain fixed copy, owner-supplied service names and IDs, and owner-supplied policy hours. Customer text is never echoed back.
- Urgent alerts are opt-in by the administrator. No alert is sent without that opt-in.

## Release and backout

- No database schema changes. No option or table is created by this change.
- Backout: remove `TZFD_Guided_Booking_Runtime::register()` from `tzfd-bootstrap.inc.php`, or remove the two routes. Nothing else depends on them.
- Filters default to empty, so an installation without owner configuration shows fallback and contact wording only.
- The priority-override refactor keeps the stored `urgency_override` values (`urgent`, `normal`, empty). Existing rows need no migration.

## Tests

- `tests/unit/guided-booking-flow-test.php`: intent, step logic, runtime routes (functional, negative, tenant and injection, privacy, nonce permission), cancel assistant entry point.
- `tests/unit/cancel-reschedule-assistant-tests.php` and `tests/unit/guided-booking-test.php`: existing coverage. The submit gate checks cover tampering, missing token, slot taken, unavailable snapshot, tenant mismatch, a token replayed from another company, an off-grid slot, a service swap and a malformed company scope (31 checks).
- Runtime (WordPress) checks for the submit gate: `tests/runtime/guided-booking-runtime-tests.php`. Not run in the #441 round.
- Privacy wording for this flow still needs owner sign-off before the documentation item on #441 can close.
- `tests/unit/enquiry-triage-rules-test.php`: negation, throttle, alert consent and priority override precedence.
- CI: `.github/workflows/tests.yml` runs every `tests/unit/*.php` file. The WordPress runtime job does not yet include the new routes.
