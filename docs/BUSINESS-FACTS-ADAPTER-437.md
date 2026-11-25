# Business facts adapter: privacy, consent, release and backout (#437, #22, #269)

Scope: `TZFD_Business_Facts` (`titan-zero-trades-front-desk/includes/class-tzfd-business-facts.php`) and its use by `TZFD_FAQ_Search`.

## What it serves

Only four facts can be served, each read from an existing company setting:

| Fact key        | Answers intent | Source setting                  |
|-----------------|----------------|---------------------------------|
| `service_area`  | area           | `tz_fd_ai_service_area`         |
| `services`      | services       | `tz_fd_ai_services`             |
| `price_ranges`  | price          | `tz_fd_ai_price_ranges`         |
| `emergency_fee` | emergency      | `tz_fd_ai_emergency_fee`        |

Not covered yet: opening hours, locations, coverage terms, and terms and policies. No verified WordPress setting holds them (opening hours live in per-form data). Adding them needs an owner decision on the source of truth, so #269 stays open.

## Privacy and consent note

- Fact values are site-owner settings. They are never customer records, staff notes or secrets. The allowlist is the only read path.
- A fact is served only after a manager publishes it. Any later edit to the setting makes the fact `stale` until it is published again.
- Values are plain text: tags and control bytes are removed and each value is capped at 2000 characters (owner decision #330).
- The facts adapter makes no hosted call. Fact text is not sent to a model by this class.
- Visitor question text leaves the site only through the hosted connector. That path is off unless the site enables the fallback (`tzfd_ai_fallback_enabled`, default off) and the administrator has granted consent to the connector's disclosure version. Without consent the connector returns `consent_required` before any transport call.
- Facts are tenant-scoped. Reads and writes go through `TZFD_Tenant_Storage` with the company id.

## Release note

- No migration and no new option beyond `tz_fd_ai_facts_published_v1`, which is created on first publish.
- Existing installs get no served facts until a manager publishes them. Questions that match no published fact follow the existing handoff path.

## Backout note

- Revert the release. Published-fact records stay in the company option and are ignored by older code.
- To stop serving facts without a code revert, unpublish each fact from the manager screen. Unpublished facts return `unpublished` and the answer path hands off to a person.

## Checks

- Unit coverage: `tests/unit/cases/faq-search-test.php` (publish gate, stale, unpublished, tenant isolation, markup removal, non-allowlisted keys). Run with `php tests/unit/run.php`.
