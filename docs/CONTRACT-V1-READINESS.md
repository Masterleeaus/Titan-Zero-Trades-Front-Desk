# Contract v1 readiness (Free plugin side)

Scope: issue #349, remaining work from #285. This note records what is proven on the plugin side of the hosted-service
contract v1, what is not, and the evidence for each. It does not claim that the hosted service exists.

## Status

| Area | Status | Evidence |
|---|---|---|
| Operation registry, deny by default, tenant and owner/agent checks | Done | `tests/contract/service-contract-tests.php` (88 passed), registry equals `tests/contract/fixtures/contract-v1.json` |
| Signed envelope, replay, clock window, malformed input | Done | Same suite, plus `tests/contract/hosted-double-tests.php` (33 passed, now including a chargeback refusal) |
| Version handshake (plugin and hosted contract major, minimum plugin version) | Done for the contract rules | Same suites |
| Signed REST route `POST /titan-zero-front-desk/v1/service-contract` | Done on main (PR #432, merge 3418edd). Refused with 403 until a contract key is stored. Only `capability.check` has a handler; other operations answer 501 | `tests/runtime/service-contract-http-tests.sh`, run by `tests/runtime/run-wp-runtime.sh` |
| Operation handlers beyond `capability.check` | Partly. `service.catalogue.list` and `locations.list` have handlers (scope and tenant checked, minimal fields, written, not run). The other 10 still answer 501 or are refused before a handler runs. The three approval-gated operations (`booking.create`, `booking.status.set`, `privacy.export`) get 403 `approval_required`, because `handle()` never sets approval. The rest need a field-projection decision (booking data holds customer PII, settings may hold secrets) or a tenant-scoped reader that has not been reviewed yet | PR #432; this change |
| Local downgrade and local features | Done. Downgrade stops remote use and removes no local data | `tests/ai-services/run-contract-tests.php` |
| Old v3.0.128 site: upgrade and downgrade with the contract code | Done. Base is the v3.0.128 release ZIP at `22dc106^` | `tests/runtime/upgrade-from-3.0.128.sh`: 31 passed, 0 failed |
| Hosted outage, cancellation and chargeback | Tested against the local double only | `tests/contract/hosted-double-tests.php` |
| Pairing with the hosted endpoint, entitlement fetch, admin connect and disconnect | Not done. Endpoint URL is not decided | Owner decision recorded: pairing uses a code shown by Supercharged and entered by the site owner. Endpoint still open |
| Hosted-side downgrade data | Not done. Needs entitlement data from the hosted service | Local cache reset exists in `class-tzfd-ai-services.php` |
| Hosted Assistant and Agent Team server side | Not in this repository | Not started here |
| End-to-end booking call through the live hosted service | Not done | Needs the hosted service |
| Hosted sandbox tests (outage, cancellation, chargeback) | Not done | Needs the hosted sandbox |
| WordPress and PHP matrix | Partly. Local run is PHP 8.3.6 and WordPress 7.1.4, single site | `.github/workflows/tests.yml` defines the runtime WordPress job on PHP 8.1, 8.2, 8.3 and 8.4 with latest WordPress, PHP 8.3 with WordPress 6.6 and MySQL 8.0, and the standalone harnesses on PHP 7.4, 8.1 and 8.3. No CI run for the contract route is recorded here yet |

## Known issues found while doing this

- `tests/contract/hosted-double-tests.php` exited 0 without running any check. The contract class exits silently when
  `ABSPATH` is not defined, and that suite did not define it. Main now defines it, and the suite runs 33 checks.
  CI runs every file in `tests/contract/`, so this suite was not guarding anything before.
- `tests/unit/test-retention-downgrade-multisite.php` (from main, #397) fails on PHP 7.4 in the standalone job. It calls a
  private method through `ReflectionMethod::invoke` without `setAccessible`, which PHP 7.4 rejects. Not part of the contract work.

## Open owner decisions

- Hosted endpoint URL for pairing and entitlement fetch (#456).
- Consent wording and 30-day retention are recorded on #348. Pairing code flow is recorded on #347.

## Not a release claim

The contract is ready on the plugin side for the local, no-hosted path: booking keeps working when the hosted service is
off, cancelled or unreachable. Hosted features must not be sold or switched on until the items above are done.
