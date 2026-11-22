# Contract v1 readiness (Free plugin side)

Scope: issue #349, remaining work from #285. This note records what is proven on the plugin side of the hosted-service
contract v1, what is not, and the evidence for each. It does not claim that the hosted service exists.

## Status

| Area | Status | Evidence |
|---|---|---|
| Operation registry, deny by default, tenant and owner/agent checks | Done | `tests/contract/service-contract-tests.php` (88 passed), registry equals `tests/contract/fixtures/contract-v1.json` |
| Signed envelope, replay, clock window, malformed input | Done | Same suite, plus `tests/contract/hosted-double-tests.php` (33 passed) |
| Version handshake (plugin and hosted contract major, minimum plugin version) | Done for the contract rules | Same suites |
| REST route `POST /titan-zero-front-desk/v1/contract` | Done, fails closed | `includes/class-tzfd-contract-rest.php`; `tests/runtime/contract-rest-tests.sh` (16 checks over HTTP, runtime suite 378 passed, 0 failed) |
| Operation executors | Not bound. Every registered operation answers 501 until an executor is added through `tzfd_contract_executor_v1` | `tests/runtime/contract-rest-tests.sh` (501 check) |
| Per-site credentials | Supplied only by the server-side filter `tzfd_contract_credentials_v1`. None are stored in the browser or in a default option | Route returns 401 when no resolver answers |
| Local downgrade and local features | Done. Downgrade stops remote use and removes no local data | `tests/ai-services/run-contract-tests.php` |
| Old v3.0.128 site: upgrade and downgrade with the contract code | Done | `tests/runtime/upgrade-from-3.0.128.sh`: 31 passed, 0 failed |
| Hosted outage, cancellation and chargeback | Tested against the local double only | `tests/contract/hosted-double-tests.php` |
| Pairing with the hosted endpoint, entitlement fetch, admin connect and disconnect | Not done. Endpoint URL is not decided | Owner decision recorded: pairing uses a code shown by Supercharged and entered by the site owner. Endpoint still open |
| Hosted-side downgrade data | Not done. Needs entitlement data from the hosted service | Local cache reset exists in `class-tzfd-ai-services.php` |
| Hosted Assistant and Agent Team server side | Not in this repository | Not started here |
| End-to-end booking call through the live hosted service | Not done | Needs the hosted service |
| Hosted sandbox tests (outage, cancellation, chargeback) | Not done | Needs the hosted sandbox |
| WordPress and PHP matrix | Partly. Local run is PHP 8.3.6 and WordPress 7.1.4, single site. | CI runs latest WordPress and PHP 8.3 only. PHP 8.1, 8.2 and 8.4 and older WordPress versions are not provisioned |

## Known issues found while doing this

- `tests/contract/hosted-double-tests.php` exited 0 without running any check. The contract class exits silently when
  `ABSPATH` is not defined, and that suite did not define it. Fixed in this change (33 checks now run and pass). CI runs
  every file in `tests/contract/`, so this suite was not guarding anything before.

## Open owner decisions

- Hosted endpoint URL for pairing and entitlement fetch (open on #349; no decision recorded yet).
- Consent wording and 30-day retention are recorded on #348. Pairing code flow is recorded on #347.

## Not a release claim

The contract is ready on the plugin side for the local, no-hosted path: booking keeps working when the hosted service is
off, cancelled or unreachable. Hosted features must not be sold or switched on until the items above are done.
