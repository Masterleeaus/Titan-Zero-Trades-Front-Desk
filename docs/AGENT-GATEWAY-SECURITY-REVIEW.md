# Agent gateway: security review and readiness (#335, from #292 and #14)

Status: review written from the code on main (commit e352141) by agent b13. It is a code review with test runs, not an external audit. Owner sign-off is still needed (see "Sign-off").

## What the gateway does

The gateway sits between a hosted agent and the WordPress site. Each proposed tool call is an envelope. The site:

1. Checks the shape and the HMAC-SHA256 signature. A bad signature writes nothing.
2. Looks up the grant for the company. No grant means refuse, no receipt.
3. Checks freshness (300 seconds either side) and the single-use nonce.
4. Runs the policy decision: state, expiry, tool granted, hourly ceiling, arguments, human approval.
5. Runs the executor only for an allow decision, then writes one HMAC-chained receipt for every decision.

Main files:

- `titan-zero-trades-front-desk/includes/class-tzfd-agent-gateway.php` (handle, record, append)
- `titan-zero-trades-front-desk/includes/class-tzfd-agent-tool-permissions.php` (catalogue, grant validation, decide)
- `titan-zero-trades-front-desk/includes/class-tzfd-action-receipt.php` and `class-tzfd-action-receipt-viewer.php` (receipts and chain check)
- `titan-zero-trades-front-desk/includes/class-tzfd-agent-gateway-admin.php` (Agent permissions page)
- `titan-zero-trades-front-desk/includes/class-tzfd-ai-services-page.php` (Agent team block)

## Controls checked

- Signature, freshness and replay: tampered, stale, replayed and company-switched calls are refused before any executor runs. Tests: `tests/unit/test-agent-gateway.php`.
- Stop, pause and dry run: enforced in the decision step, before the tool is checked. Tests: `tests/unit/test-agent-permissions.php`.
- Per-tool grants and hourly ceilings: ten tools in the catalogue, each with a risk level, an approval flag and a default ceiling. The grant validator refuses any ceiling above the catalogue default.
- Outbound safety: a tool with external risk (follow_up) cannot be pre-approved with auto_approve. Refused by `validate_grant()` since PR #350 (merge eb7b8bd). The admin form always saves auto_approve as empty.
- Receipts: append-only, each receipt carries the hash of the one before. Edits, deletions and reordering break the chain. Tests: `tests/unit/test-action-receipts.php`.
- Receipt outcomes: written from the gateway decision or the executor result (executed, failed, denied, dry_run, needs_approval). The viewer reads only stored receipts; it has no sample or prompt data.
- Admin screens: every change needs the manage_options capability and a nonce. The receipt viewer is read-only.

## Findings

1. No executor is registered in this plugin. Nothing in the repo adds the `tzfd_agent_execute_tool` filter. An allowed call is therefore recorded as `failed` with reason `no_executor`. This fails closed, so no action can happen by accident. Live actions need an executor, which belongs with the hosted side or a later PR.
2. There is no approval path yet. `handle()` always passes `approved_by_human = false`. A call that needs approval (book, cancel, reschedule, follow_up by default) gets `needs_approval` and stays there. Nothing on the site lets a person approve it. This is fail closed but blocks those tools. Tracked under the approvals item, which moved to a new issue.
3. Tamper evidence depends on the site secret. The chain is HMAC-signed with a secret stored in an option (autoload off). Someone with database access and that secret could rebuild the whole chain. The check catches partial edits, deletions and reordering, not a full rewrite. Residual risk, accepted for this build.
4. The nonce store holds up to 5,000 nonces and refuses new calls when full (`nonce_capacity`). Old nonces expire after the window. Someone with the secret could fill it with valid calls and block the gateway for up to five minutes. Fail closed.
5. The executor filter runs any callback that other code registers. The gateway does not check those callbacks beyond catching exceptions. This follows the normal WordPress trust model.
6. Grants cannot set hourly ceilings from the admin screen. The screen saves an empty list, so the catalogue defaults apply. The validator accepts lower ceilings only.
7. Calls that need approval count toward the hourly limit. This is intended, so a flood of pending requests is limited too.

## Readiness

- Local gate (signing, state, permissions, receipts, admin): ready. Fails closed, and the unit tests pass.
- Live agent actions: not ready. Finding 1 (no executor) and finding 2 (no approval path) must be closed first, and the hosted role tests (moved issue) must pass.
- Unattended outbound messages: not allowed. Blocked by the grant validator and the catalogue.

## Checks run for this review

- `tests/unit/run.php` on main: 572 passed, 0 failed; free AI cases 177 passed, 0 failed.
- `tests/unit/ai-services-admin-tests.php` after the Agent team block was added: 29 passed, 0 failed.
- `php -l` on the changed PHP files: no syntax errors.

## Sign-off

- Reviewer: agent b13, code review only.
- Owner sign-off: pending, owner decision. Not recorded here.
