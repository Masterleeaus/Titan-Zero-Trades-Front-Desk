# Scheduling & Enquiries (Titan Zero Front Desk)

Scheduling & Enquiries is a free WordPress plugin for service businesses such as plumbers, cleaners, salons, tutors and clinics. It takes bookings, keeps calendars, customers and reminders, and handles enquiries with built-in rules.

The WordPress plugin is distributed as **Scheduling & Enquiries Manager**. The source is in `titan-zero-trades-front-desk/` and keeps the legacy Front Desk identifiers for backward compatibility. See `docs/SINGLE-PLUGIN-AI-SERVICES-ARCHITECTURE.md`.

## What the free plugin includes

- Booking form with calendars, working hours, service durations and capacity limits.
- Booking page and client portal, created from Settings, in the theme's layout.
- Admin calendar, appointment list, status changes and CSV export.
- Email notifications, customer reminders, follow-ups and customer cancellation or rescheduling.
- Rules-based enquiry handling, and a limited free AI allowance once hosted AI is connected and allowed.
- Optional text-back to missed calls, through the site owner's own Twilio account.

Local features are free and are never locked behind a paid upgrade or a licence key.

## AI upgrades

Two optional hosted subscriptions activate inside the same plugin. They are not separate plugins.

| Upgrade | What it adds | Price (AUD, incl. GST) | Status |
|---|---|---|---|
| Titan Zero AI Assistant | Conversational enquiries, guided booking, draft replies and quotes, owner summaries | A$29 per month | In development |
| Titan Zero AI Agent Team | Specialist agents for enquiries, booking, quote intake and approved follow-up. Includes the Assistant. | A$99 per month | In development |

Hosted AI stays off until the site connects its account and allows it. Text messages use the site owner's Twilio account. Twilio is the only SMS provider supported today.

## Repository layout

| Path | Purpose |
| --- | --- |
| `titan-zero-trades-front-desk/` | The WordPress plugin: `readme.txt`, `changelog.txt`, `includes/`, `assets/` |
| `docs/` | Architecture, AI services entitlement and downgrade rules, release and submission notes |
| `tests/` | Runtime and unit test harnesses |
| `scripts/`, `tools/` | Build, release and maintenance scripts |

Key documents:

- `docs/SINGLE-PLUGIN-AI-SERVICES-ARCHITECTURE.md`: the one-plugin structure and the AI services.
- `docs/AI-SERVICES-ENTITLEMENT-AND-DOWNGRADE.md`: how the service level is decided and what happens on downgrade.
- `docs/AI-SERVICE-CONNECTOR-CONTRACT.md`: the connector contract for hosted AI.
- `titan-zero-trades-front-desk/readme.txt`: the WordPress.org readme.

## Status

- The Pro plugin is retired. There is no Pro plugin, Pro licence or Pro download.
- The hosted AI connector is not yet live in this version. Hosted AI features must not be described as released until they pass end-to-end tests.
- The plugin is not yet listed on WordPress.org.

## Licence

GPL-2.0-only. See `titan-zero-trades-front-desk/readme.txt` and `THIRD-PARTY-NOTICES.txt`.
