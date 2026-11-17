# Former Pro backlog: migration audit (issue #49)

Source: `Masterleeaus/Titan-zero-front-desk-pro` issues #1 to #41. Destination: the one installed Free plugin, or a genuine hosted service through the same plugin (#46, #47, #48).

This is a routing and evidence register. It is not an implementation claim. Status values:

- **Not built**: no matching code found in this repo.
- **Partial, files present**: the file names suggest overlap with shipped Free code. Behaviour and parity were not verified in this lane.
- **Hosted (contract only)**: destination is the hosted service. Only the in-repo descriptor and gate exist (`docs/AI-SERVICES-ENTITLEMENT-AND-DOWNGRADE.md`).
- **Retired**: the separate Pro packaging or licence path. The requirement is dropped, and the test, security or provenance work is kept elsewhere.
- **Superseded**: its scope is covered by an open issue listed in the destination column.

Evidence column lists file names under `titan-zero-trades-front-desk/`. Nothing here is marked done.

## Retired or superseded packaging (kept for provenance)

| Legacy | Title (short) | Disposition | Destination |
|---|---|---|---|
| #1 | Former Pro plugin plan | Superseded by one-plugin architecture | #45, #49 |
| #2 | Separate Pro plugin bootstrap | Retired. Reuse only reusable hook and capability interfaces | #49 (retire installer) |
| #7 | Pro add-on licensing and key service | Retired. Replaced by hosted entitlements | #46, Central #153 |
| #27 | Former Pro + Assistant bundle | Superseded | #45, #47 |

## Local deterministic features (move into Free when built locally)

| Legacy | Title (short) | Class | Free evidence (file names) | Status | Destination |
|---|---|---|---|---|---|
| #3 | Audit shipped Free before delineation | Audit | this document | Partial | #49 |
| #4 | Multi-location and delegated staff permissions | Local | `class-tzfd-multi-location.php`, `class-tzfd-staff-assignment.php`, `class-tzfd-admin-menu.php` | Partial, files present | #11, #49 |
| #5 | Analytics suite and reconciled exports | Local | `class-tzfd-admin-report-scope.php`, `class-tzfd-booking-export-reader.php`, `tzfd-admin-int-report.inc.php` | Partial, files present | #12, #49 |
| #6 | Workflow automations, recurring policies | Local | `class-tzfd-workflow-rules.php`, `class-tzfd-workflow-orchestrator.php`, `class-tzfd-scheduling-rules.php` | Partial, files present | #49 |
| #8 | Release, security and migration test suite | Infra | `tests/runtime/runtime-tests.php`, `tests/runtime/http-auth-tests.sh` | Partial, files present | #9, #44 |
| #9 | Extension contracts, single-plugin services | Infra | `class-tzfd-addon-loader.php`, `tzfd-addons.inc.php` | Partial, files present | #16, #49 |
| #10 | Schema, migration ledger, rollback tools | Infra | `class-tzfd-tables.php`, `class-tzfd-upgrades.php`, `class-tzfd-migration-handoff.php` | Partial, files present | #49 |
| #11 | Admin dashboard, role navigation, setup flow | Local | `class-tzfd-admin-menu.php`, `tzfd-publish-wizzard.inc.php` | Partial, files present | #15, #49 |
| #12 | CRM, segmentation, custom fields, history | Local | `class-tzfd-local-customers.php`, `class-tzfd-customer-admin.php` | Partial. Segmentation not found | #49 |
| #13 | Form designer, conditional fields, approvals | Local | `class-tzfd-form-access.php`, `class-tzfd-customer-field-check.php` | Partial. Approval workflow not found | #49 |
| #14 | Resources, packages, capacity policies | Local | `class-tzfd-resource-catalog.php`, `class-tzfd-resource-availability.php`, `class-tzfd-capacity-lock.php` | Partial, files present | #11, #49 |
| #15 | Recurring bookings, agreements, self-service | Local | `class-tzfd-customer-rescheduling.php`, `class-tzfd-client-portal.php`. Recurrence not found | Partial | #49 |
| #16 | Notifications, templates, deliverability | Local | `class-tzfd-pipeline-notification-outbox.php`, `class-tzfd-status-update-emails.php` | Partial, files present | #49 |
| #17 | Exports, webhooks, safe credentials | Local | `class-tzfd-webhooks.php`, `class-tzfd-integration-events.php`, `class-tzfd-booking-export-reader.php` | Partial, files present | #49 |
| #18 | Payment, deposit and invoice reporting | Local | none found by name | Not built | #49 |
| #19 | Audit logs, approvals, privacy export and erase | Local | `class-tzfd-local-audit.php`, `class-tzfd-privacy-exporter.php` | Partial, files present | #8, #17 |
| #21 | Bulk operations, import, scheduled maintenance | Local | `class-tzfd-pack-import-guard.php` covers vertical packs only | Not built for bulk operations | #49 |
| #22 | Performance and integrity benchmarks | Infra | none found | Not built | #44 |
| #23 | Admin docs, contextual support | Local | `tzfd-help.inc.php` | Partial, files present | #15, #49 |
| #25 | Quotes, estimates, service-to-job conversion | Local | none found by name | Not built | #47, #49 |

## Cross-app and integration

| Legacy | Title (short) | Class | Evidence | Status | Destination |
|---|---|---|---|---|---|
| #20 | Cross-app enhancements for Scheduled, Sorted, Central | Hosted or local adapter | `class-tzfd-integration-events.php` only | Not built | #16, #48 |
| #24 | Release provenance and security tests | Infra | none found in plugin | Not built | #9, #44 |

## Hosted Assistant (#47)

These run as remote compute through the same plugin. They are not paid gates on local code. Local rule builder, forms and reports stay in Free.

| Legacy | Title (short) | Evidence | Status | Destination |
|---|---|---|---|---|
| #28 | AI-assisted conditional booking form designer | none | Hosted (contract only) | #47 |
| #29 | AI-assisted estimate and quote drafts | none | Hosted (contract only) | #47 |
| #30 | AI reply templates and multilingual drafts | none | Hosted (contract only) | #47 |
| #31 | Enquiry qualification and intake extraction | none | Hosted (contract only) | #47 |
| #32 | AI scheduling, capacity and conflict recommendations | none | Hosted (contract only) | #47 |
| #33 | AI business insights on verified analytics | none | Hosted (contract only) | #47 |
| #34 | Customer history, segmentation, retention suggestions | none | Hosted (contract only) | #47 |
| #35 | Natural-language workflow drafting | none | Hosted (contract only) | #47 |
| #36 | Multi-location approved knowledge and policies | none | Hosted (contract only) | #47 |
| #37 | Recurring-service recommendations and renewal drafts | none | Hosted (contract only) | #47 |
| #38 | Unified Assistant workspace and approvals | none | Hosted (contract only) | #46, #47 |
| #40 | Conversational Assistant as hosted service | `class-tzfd-ai-services.php` (gate only) | Hosted (contract only) | #47 |
| #41 | Hosted entitlements in one installed plugin | `class-tzfd-ai-services.php`, `class-tzfd-ai-services-page.php`, `data/ai-services/capabilities-v1.json` | Partial, in-repo gate and descriptor only. Connector not built | #46, Central #156 |

## Hosted Agent Team (#48)

| Legacy | Title (short) | Evidence | Status | Destination |
|---|---|---|---|---|
| #39 | AI handoffs to Scheduled, Sorted, Supercharged without duplicate authority | none | Hosted (contract only) | #47, #48 |

## Changelog note

Changelog 3.0.127 mentions a chat assistant and AI usage records. This audit did not find code under those names in the file list, so those entries are not counted as existing Free functionality here. Verify before relying on them.

## Migration acceptance still open

- Behaviour and parity check for every "Partial" row (run, not read).
- Plugin Check and WordPress.org review of any local feature moved into Free.
- Clean install and upgrade from v3.0.128 with preserved bookings and customers.
- Hosted rows need the connector (#16, #43) and the agent receipt work (#48) before any status moves past contract only.
