# Guided booking: release and backout note (issue #440, T6)

Scope: `titan-zero-trades-front-desk/includes/class-tzfd-guided-booking.php` (Free plugin, WordPress.org package).
Owner-confirmed defect name: **"Cancel or reschedule requests started a new booking"** (accepted on #440).

## 1. What changed

| Change | Where | Status on main |
| --- | --- | --- |
| Change and cancel wording is checked before the booking rule. Such text returns the `change` intent and the `handoff` step, so it never reaches availability. | `detect_intent()` in `class-tzfd-guided-booking.php` | PR #401, merge commit `cbbe1f5` (#336) |
| Handoff step returns `CHANGE_MESSAGE`, which points the customer to the emailed link or to staff. | `next_step()` | PR #401, `cbbe1f5` |
| Unit regression checks for the defect. | `tests/unit/guided-booking-flow-test.php` | PR #401 |
| WordPress runtime checks that drive the flow the way a form handler would. | `tests/runtime/guided-booking-runtime-tests.php` | PR #487, merge commit `1d8a35d` |

This PR (T6) changes documentation only. It does not change PHP code, tests or CI.

Before the fix, "I need to reschedule my appointment" and "Please cancel my booking" returned `book`. On main they return `change`. "I want to book a deep clean" still returns `book`.

## 2. Customer-visible effect in this release

None on the live site. `TZFD_Guided_Booking` has no caller in the plugin on main. Nothing in `titan-zero-trades-front-desk/` requires the class, so the guided flow does not run for customers.

**Wiring is not part of this release.** Connecting the flow to the booking form stays open under #24. Box 6 on #440 does not depend on it.

## 3. How it is tested

Run from the repository root:

```sh
php tests/unit/guided-booking-flow-test.php   # 24 checks, expected "24 passed, 0 failed"
php tests/unit/guided-booking-test.php        # 27 checks, expected "27 passed, 0 failed"
```

Both files need only PHP. They are run by the CI unit job (`tests/unit/*.php`).

The WordPress runtime check needs a disposable WordPress site:

```sh
WP_CLI="php /path/to/wp-cli.phar" tests/runtime/run-wp-runtime.sh
```

`guided-booking-runtime-tests.php` is picked up by the CI "Extra WordPress test files" step (`*-runtime-tests.php`). It covers the change and cancel hand-off, the happy path, tampered and missing tokens, a slot taken after confirmation, an unavailable snapshot and a tenant mismatch.

Mutation check (recorded on #440): with the change check disabled in a copy of the class, the unit test and the runtime test both fail on the hand-off checks.

## 4. Backout

Do not revert merged PRs on main. Use a forward fix on a new branch and a new PR. Choose one of these, depending on the problem.

**A. Routing defect in the change check.** Remove or narrow the change rule at the top of `detect_intent()`. Keep the `handoff` step only if the rule still matches. Add or update the matching cases in `tests/unit/guided-booking-flow-test.php` and `tests/unit/guided-booking-test.php`. Run both unit files and the CI matrix before merge.

**B. Guided flow should not ship at all.** Because nothing calls the class, this has no customer impact now. If the file must leave the package, open a forward PR that:

1. deletes `includes/class-tzfd-guided-booking.php` and the three guided-booking test files;
2. confirms that no `require` of the class remains (`grep -rn "class-tzfd-guided-booking" titan-zero-trades-front-desk/`);
3. regenerates the provenance manifests under `docs/provenance/` that list the file;
4. confirms `tests/unit/removed-files-since-3.0.128-test.php` still passes. It checks removed paths, so any change to the removed list must be reviewed there.

**C. Once wiring exists (#24).** Remove the caller added by #24 first. The flow then has no caller again and the case reduces to A or B.

After any backout, the customer-facing booking form stays available. The fallback text is `FALLBACK_MESSAGE`, and the standard booking path does not depend on this class.

## 5. Open items

- WordPress wiring of the guided flow to the booking form: open under #24.
- Owner sign-off on the name: accepted on #440.
