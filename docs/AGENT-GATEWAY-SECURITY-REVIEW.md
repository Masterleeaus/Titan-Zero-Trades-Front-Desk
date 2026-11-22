# Agent gateway: security review and readiness (issue #335, parent #292 / #14)

**Scope:** the local agent gateway in this plugin: signed envelopes, grant and tool catalogue, pause/resume/stop,
dry run, rate ceilings, HMAC-chained action receipts and the admin screens (Agent permissions, AI Services
"Agent Team controls" panel). **Not in scope:** the hosted Agent Team service, its role agents, telephony and
provider integrations. Those are not in this repository and cannot be tested here.

**Review basis:** code read in `titan-zero-trades-front-desk/includes/class-tzfd-agent-*.php`,
`class-tzfd-action-receipt*.php` and `class-tzfd-ai-services-page.php`, plus the unit suites below.

## Test evidence (this review)

| Suite | Command | Result |
| --- | --- | --- |
| Gateway gate | `php tests/unit/test-agent-gateway.php` | 44 passed, 0 failed |
| Permissions and ceilings | `php tests/unit/test-agent-permissions.php` | 40 passed, 0 failed |
| Receipt chain and viewer | `php tests/unit/test-action-receipts.php` | 40 passed, 0 failed |
| AI Services screen | `php tests/unit/ai-services-admin-tests.php` | 25 passed, 0 failed |
| Full local suite | `php tests/unit/run.php` | Local-free suite: 572 passed, 0 failed |
| Gateway WordPress runtime | `php tests/runtime/agent-gateway-runtime-tests.php` | BLOCKED: needs a WordPress runtime (`wp eval-file`); not run in this environment |

## Controls checked in code

- **Authentication first.** The gate checks envelope shape, then the weak-secret guard, then `hash_equals` on the
  HMAC signature before it reads the grant or writes anything. A call that fails authentication writes no receipt.
- **Grant scope.** A grant is per company. A missing grant refuses with `no_grant`; an invalid grant refuses with
  `invalid_grant`. The admin grant form only accepts tool names present in the catalogue.
- **No self-approval.** The gate always reports `approved_by_human => false`. Admin grants always save an empty
  `auto_approve` list. External-risk tools (follow-up) are not auto-approved (PR #350).
- **Stop, pause and dry run** are enforced in the gate, and covered by the unit suites above.
- **Receipts.** Each receipt is HMAC-signed and linked to the previous hash. Append refuses a non-sequential
  receipt. The viewer is read-only and escapes every value.
- **Screen writes.** Every admin change needs `manage_options` (Agent permissions) or `tz_fd_access` (AI Services)
  and a nonce. The new AI Services "Agent Team controls" panel is read-only; pause, resume, stop and grant changes
  stay on the Agent permissions screen.

## Findings and gaps (not fixed in this pass)

1. **Approval path is incomplete locally.** Actions that need approval are recorded with outcome
   `needs_approval`, and the panel counts them. This repository has no code that records a human approval and then
   executes the held action. Approved execution is therefore not available locally, and the hosted service is the
   only place it can happen.
2. **Panel populated state is not unit tested.** `ai-services-admin-tests.php` covers the "gateway not loaded" branch.
   The branch that reads a live grant and receipts needs WordPress options and `TZFD_Scoped_Store`, so it is covered
   only by a runtime test that was not run here.
3. **Hosted role negative cases are not testable here.** Replay across services, changed availability, duplicate
   webhook, opt-out, runaway loops, provider down and downgrade through the hosted Agent Team need the hosted service
   and its contract tests. These stay open under #335.
4. **Runtime proof is outstanding.** The gateway WordPress runtime check needs a provisioned site (see
   `tests/runtime/README.md`).

## Readiness

- **Local gateway controls (signing, grant scope, stop/pause/dry run, rate ceilings, receipt chain):** unit-tested,
  ready for review.
- **Approval execution, hosted orchestration and hosted negative cases:** not ready. Do not advertise a live
  "agent team" until those pass against the hosted service.
- **Sign-off:** not recorded. This document is a review of the code and unit evidence only. A human security review
  is still required before this is recorded as complete for #14.
