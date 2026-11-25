# Threat model and verified readiness: Scheduling & Enquiries Manager

Plugin: `titan-zero-trades-front-desk` (folder and slug). Covers issue #334, which carries the open work from #263 and #7 and #8.
Recorded 2026-11-23 on branch `b14/issue-334-session-switch`. This is an engineering record. It does not claim WordPress.org review or approval.

## 1. What is protected

- Booking rows: customer name, contact details, notes, and the appointments themselves.
- Customer links: cancellation and reschedule links, and the portal sign-in links.
- Portal sessions: a signed-in client can see only their own bookings.
- Staff actions: changing bookings, settings, staff access and exports.
- Tenant data: each company's calendars, bookings and queue rows, kept apart by company id.
- Secrets: the missed-call inbound secret and the hosted AI key, held server side.

## 2. Who can reach the plugin

- Anonymous visitors: booking page, enquiry form, cancellation and reschedule links, portal sign-in.
- Customers with a link or a signed-in portal session.
- Logged-in WordPress users: staff, administrators and subscribers.
- Outside systems: signed missed-call and SMS callbacks, and the hosted AI service (only with consent).

## 3. Main threats and the control for each

- Forged or replayed customer link. Control: HMAC token bound to the booking version and an expiry (14 days for cancellation). A change to the booking makes an old link stop working. Tests: B13 in `customer-cancel-http-tests.sh`, and the token checks in `wp-runtime-tests.php`.
- Cancelling or changing a booking without a form post. Control: GET only shows the form. POST needs a nonce tied to the booking id and a confirmation button. Tests: B13 (400 without confirmation, 403 with a forged nonce).
- Replay of a confirmed cancel. Control: the stored booking changes, so the link no longer matches. Test: B13 replay returns 404 and stages no second event.
- Portal session switching and session reuse. Control: a link sign-in ends the session already held in that browser. Sessions last 2 hours. Sign-out deletes the session. Tests: `portal-session-switch-tests.php` (P1 to P10).
- One client reading another client's bookings. Control: bookings are read by the session email and company only. Tests: P9 and P10 in `portal-session-switch-tests.php`.
- Cross-tenant access. Control: company id is checked on every read and on every queued customer event. Tests: P2 (other-company session), and the tenant checks in `security-runtime-tests.php`.
- Brute force on sign-in. Control: a per-address window and a per-email throttle (`class-tzfd-client-portal.php`). Not re-tested in this round.
- Forged signed requests (missed-call, SMS). Control: signed-request check, with replay refused. Tests: `runtime-tests.php` and `http-auth-tests.sh`, including the concurrent replay test.
- Unauthenticated admin actions and REST routes. Control: capability and nonce checks in every admin-post handler, and a `permission_callback` on every REST route. Tests: `http-auth-tests.sh` (anonymous, invalid nonce, subscriber refusals).
- Overselling a slot. Control: a per-calendar capacity lock and a re-check before the write. Tests: the lock primitives A4 to A17 pass. The full public-form run (A18) is still open.
- Data exposure on uninstall. Control: data is kept unless the owner opts in. Tests: F1 to F3 pass. F4 (opt-in wipe) runs in its own process through `uninstall-wipe-check.php`.
- Downgrade or upgrade breaking bookings. Control: the upgrade and downgrade script. Tests: see section 5.
- Hosted AI leaking data. Control: no hosted call without recorded consent. Tests: H6 and the unit suite.

## 4. What this round added

- Session switch ends the older session in the same browser (`open_session`). Logged as a decision, see section 7.
- Customer cancel is now run end to end over HTTP on the disposable site.
- Portal session and booking exposure checks are in the runtime run.

## 5. Verified readiness, 2026-11-23

Each line is a run of the runtime suite on the shared site under `flock /home/claude/team/locks/runtime.lock`. The run logs are kept by the team, not in the repo.

- Single site, WordPress 7.1.4, PHP 8.3.6: 391 passed, 0 failed, 0 gap, 9 skipped.
- Single site, WordPress 6.6 (oldest supported, `Requires at least: 6.6`), PHP 8.3.6: 391 passed, 0 failed, 0 gap, 9 skipped.
- Multisite, WordPress 7.1.5, PHP 8.3.6: 392 passed, 0 failed, 0 gap, 7 skipped.
- Upgrade and downgrade from the 2.0.1 base (`upgrade-from-3.0.128.sh`): 10 passed, 0 failed. Data carry-over is skipped by owner decision (no customers yet).
- HTTP authorization, replay and customer cancel checks: passing in each run above.

## 6. Not verified yet

- PHP 8.1, 8.2 and 8.4. Only PHP 8.3.6 is installed on the test machine, and no other PHP version can be installed from the package manager here.
- Oversell through the public front desk form (A18). The form is rendered by script and needs a security code. The guided booking engine in `class-tzfd-guided-booking.php` is not on any public path.
- Customer reschedule end to end (part of B13).
- Session-switch exposure through the public form, beyond the unit-level checks above.

## 7. Decisions

- 2026-11-23: A new sign-in ends the older session held in the same browser. Reason: session switching must not leave the older session valid. The checklist decision of 2026-11-18 (#263 scan) defines "session-switch" as portal session switching.
- 2026-11-23: The cancel test expects HTTP 404 on a replayed confirmation, not 409. Reason: the link is bound to the booking version, and the cancel changes that version, so the link no longer resolves.
- 2026-11-23: A cancellation by the customer does not send a customer e-mail in the test configuration (no e-mail is captured). Recorded as an observation for the owner, not changed.

## 8. For the owner to review

- Confirm that no e-mail should go to the customer on a self-cancel, or name the template to send.
- Confirm the 14-day cancellation link lifetime and the 2-hour portal session.
