# Agent gateway security review

Status: code review recorded, readiness not assessed. Tracks issue #335 (checklist item "Security review, readiness assessment and documentation for the agent gateway"), parent #292, original issue #14.

This document records a static review of the agent gateway as it stands on `main` at commit `e35214154fa51f3a0df3baa4d3d4b7f40e6caf69`. It is not a production-readiness sign-off. The readiness conclusion is an owner decision and is left open below.

## Scope

- `titan-zero-trades-front-desk/includes/class-tzfd-agent-gateway.php` (signature, freshness, nonce, policy call, receipt write, executor dispatch)
- `titan-zero-trades-front-desk/includes/class-tzfd-agent-tool-permissions.php` (tool catalogue, grant validation, `decide()`)
- `titan-zero-trades-front-desk/includes/class-tzfd-agent-gateway-admin.php` (admin screen for state, grants and receipts)
- Receipt chain and viewer: `TZFD_Action_Receipt` and `TZFD_Action_Receipt_Viewer`

Out of scope: the hosted role agents and the hosted service. Neither is in this repository, so their behaviour cannot be reviewed here.

Tests cited by the parent issue, which were not executed as part of this review: `tests/unit/test-agent-gateway.php`, `tests/unit/test-agent-permissions.php`, `tests/unit/test-action-receipts.php`, `tests/runtime/agent-gateway-runtime-tests.php`.

## Controls confirmed in code

1. **Authentication first.** `handle()` checks the envelope shape, then the secret length, then an HMAC-SHA256 signature over the canonical JSON compared with `hash_equals`. A call that fails here writes no receipt.
2. **Grant must exist.** A company with no grant is refused with `no_grant`. The stored grant is re-validated before use.
3. **Freshness window.** A signed envelope more than 300 seconds from `now` is refused as `stale_request` and recorded.
4. **Single use.** The nonce is stored before the policy decision, so a replayed nonce is refused as `replay` whatever the outcome of the first call.
5. **Policy order in `decide()`.** Unknown tool, argument validation, company match, agent match, time validity, grant expiry, stop, pause, tool grant, rate ceiling, dry run, then owner approval. Stop and pause are checked before the tool grant.
6. **Rate ceiling.** Per-tool usage is counted from the same company's receipts in the last hour, against the catalogue default or the grant override.
7. **Executor only on allow.** The executor filter runs only when the decision is `allow`. With no executor registered the call is recorded as `failed` with reason `no_executor`.
8. **Append-only receipts.** Each receipt carries a sequence number and the previous hash. The store exposes no edit or delete method for receipts.

## Findings

### F1. The approval path cannot complete (high for item 3)

`handle()` always passes `approved_by_human => false` to `decide()`. Any tool whose catalogue entry sets `approval` therefore returns `needs_approval`, and no code path turns that into an execution. The admin screen has no approve action. Consequence: approval-gated tools can never run through the gateway in this repository. The "approvals" part of #335 item 3 is not implemented, and the design of how a human approval reaches the gateway is an owner decision.

### F2. Saving permissions resets two grant fields (medium)

`handle_grant()` rebuilds the grant with `expires_at => null`, `auto_approve => array()` and `max_per_hour => array()`. Saving the "Tools the agent may use" form therefore clears any previously stored auto-approve list, per-tool ceilings and expiry. The admin screen has no control for these fields, so the reset is silent. Owner to confirm whether these should be preserved or exposed.

### F3. Executors are not in this repository (informational)

Allowed calls depend on a registered `tzfd_agent_execute_tool` filter. Nothing in this repository was reviewed as a complete executor for the nine catalogue tools, so end-to-end outcomes (for example, that a booking hold is actually created and that oversell is prevented) cannot be confirmed from this review. This ties to the hosted-to-WordPress tests in #335 item 2.

### F4. Nonce store can be filled (low)

Nonces are pruned only after twice the freshness window, and the store is capped at 5000. Once the cap is reached the gateway refuses further calls with `nonce_capacity`. This fails closed, which is the correct direction, but a holder of the signing secret could use valid signed calls to fill the store and deny service. Risk depends on the secret staying private; secret storage was not reviewed in this pass.

### F5. Unauthenticated refusals are not receipted (informational, by design)

Calls that fail shape or signature checks write nothing. This keeps forged calls out of the audit chain but means forgery attempts are not visible in the receipt viewer. Owner to decide whether a separate security log is wanted.

## Not covered by this review

- Secret generation, storage and rotation (`TZFD_Agent_Gateway_Option_Store::secret()`).
- Behaviour of hosted role agents: negative cases for unauthorised booking, changed availability, duplicate webhook, opt-out, runaway loops, provider down and downgrade.
- Whether the cited test suites pass on the head of `main`.
- Privacy, retention and consent for receipt contents.

## Owner decisions still needed

1. Readiness conclusion for the agent gateway (not assessed here).
2. How a human approval is given and passed to the gateway (F1).
3. Whether `auto_approve`, `max_per_hour` and `expires_at` should be editable and preserved on save (F2).
4. Whether unauthenticated refusals need a separate security log (F5).
