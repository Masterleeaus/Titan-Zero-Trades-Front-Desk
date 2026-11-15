=== Titan Zero Front Desk ===

Contributors: titan0

Tags: booking, appointments, calendar, scheduling, booking form

Requires at least: 7.0

Requires PHP: 7.4

Tested up to: 7.1

Stable tag: 3.0.128
License: GPL-2.0-only

License URI: https://www.gnu.org/licenses/gpl-2.0.html



Bookings, calendar, booking pages and phone answering that operates standalone with optional Titan Zero integrations.



== Description ==







Front Desk has its own WordPress admin menu and can run standalone, without Titan Zero Trades: Front Desk.





Titan Zero Front Desk gives front-desk staff a place to take and manage bookings in WordPress. Front Desk includes a local company context, customer records and service catalogue. Optional Titan Zero integrations can extend those capabilities. Quotes and invoices are not required for booking.



It is built for service businesses: plumbers, cleaners, salons, tutors, clinics and similar. You define your services, durations, working hours and staff or resource capacity, and customers pick a free time slot from your booking form.



Main features



* Booking form with calendars, working hours, service durations and capacity limits

* Booking page and client portal, created from Settings. The pages use your theme's layout. Clients sign in with their email address to see and change or cancel their upcoming bookings

* Admin calendar and appointment list, with status changes and CSV export

* Email notifications, customer reminders, follow-ups and customer cancellation or rescheduling

* Industry preset packs (vertical packs) when supplied by an optional source, with example services and reference answers. Review and edit every answer before installation. Price catalogue items are handed to Titan Zero Quotes. Custom packs can still be uploaded or registered.

* Optional text-back to missed calls, using your own phone system and SMS gateway

* Dashboard widget with upcoming appointments

* Personal data exporter for the WordPress Export Personal Data tool



Compatibility modules. In this version these are kept from earlier releases. The Add-ons screen does not list them, so a new install cannot switch them on there. Where they were already switched on, they keep working: the optional AI booking assistant (it uses the WordPress AI Client and provider connectors, answers from your knowledge base and does not write quotes), the WhatsApp and SMS buttons below the booking form, a webhook that sends signed booking-created and booking-status events to an HTTPS endpoint you choose, and an activity log of booking additions, status changes and deletions.



Titan Zero Front Desk is a free, GPL-licensed plugin. Nothing in it is locked behind a paid version or licence key.



The plugin has no paid upgrade requirement. Reminders, when enabled, appear only to administrators on Front Desk screens: one while no AI provider is connected, one about the booking speed add-on, and one until AI setup is finished. The first two can be set aside for 30 days; the setup reminder can be dismissed.



Optional external industry presets are examples; native service setup does not require Workspace. The services, answers and prices in a preset are starting points written for a typical business. Check and change them before you publish your booking form.



== Installation ==



1. Upload the titan-zero-trades-front-desk folder from the submission ZIP to /wp-content/plugins/, or install the plugin from the Plugins screen in WordPress.

   This is a new plugin, not an upgrade. Do not install it over Appointment Hour Booking or Titan Front Desk; see the FAQ below.

2. Activate Titan Zero Front Desk on a supported WordPress installation. No other Titan Zero plugin is needed. Optional WordPress AI provider connectors and SMS providers are only needed for their corresponding optional features.

3. Open Titan Zero Front Desk in the WordPress admin menu and enter your business details and services. You can configure its local customers, calendars, service durations and capacity without Workspace.

4. Create a calendar, then publish its booking form on a page or post. The publishing interface explains the options.


5. Optional: go to Settings, then Booking page and client portal, and click Create or update pages. This creates a booking page and a client portal as normal WordPress pages, which you can add to your menu.



== Frequently Asked Questions ==



= Does Front Desk require Titan Zero Trades: Front Desk? =

No. Front Desk has native business, customer and service storage. Workspace is an optional integration, not an installation dependency.



= Do I need an account or a licence key? =



No.



= Do my customers need an account to use the client portal? =



No. They enter the email address they booked with, and we send them a sign-in link. The link works once and expires after 20 minutes. Signing in lasts 2 hours. The cancel and reschedule links in booking emails do not expire; they work for as long as the booking stays open.



= Does it send my customers' data anywhere? =



Only to the external services listed above, and only when you turn them on and enter their details. Without those, bookings stay in your WordPress database.



= Are the prices in the setup presets real? =



No. They are examples. You can edit each price, and each answer, before the preset is installed. Set your own rates before you publish a booking form.



= Can I use an AI model? =



Yes. WordPress 7.0 or later provides the WordPress AI Client and Settings → Connectors. Install and configure a provider connector there; Titan does not collect or store provider API keys. WordPress includes connectors for OpenAI, Anthropic and Google. For OpenAI-compatible services such as Groq, OpenRouter, Cerebras or a local server, install a compatible provider connector, for example https://wordpress.org/plugins/ultimate-ai-connector-compatible-endpoints/. Each provider's terms, privacy policy, limits and charges apply.



= Will my bookings be removed if I delete the plugin? =



No. Uninstalling clears the plugin's scheduled tasks and keeps your bookings and settings. It also deletes the photos customers attached in the chat and the calendar invitation files the plugin created. To delete the plugin's tables and settings as well, add define( 'TZ_DELETE_DATA_ON_UNINSTALL', true ) to wp-config.php before you delete the plugin.



= Can I upgrade from Appointment Hour Booking or Titan Front Desk? =



No. Titan Zero Front Desk does not automatically import the bookings, customers or settings of those plugins. It uses its own tables and option names, so the old data stays where it is. There is no import tool in this version. Keep the old plugin until you have what you need from it, then deactivate and remove it. Enter any bookings you still need here by hand.



= Where do I get help? =



Email support@titanzero.io for support.



= Where do I report a security problem? =



Email support@titanzero.io. Please do not post exploit details publicly until a fix is available.



== External services ==



Titan Zero Front Desk connects to the external services below only when the site administrator configures a connector or turns on the relevant integration. Nothing is sent to the plugin author or to any AI provider by default. The plugin does not load scripts, fonts or images from outside services on public pages.



1. AI provider configured in WordPress Connectors (optional)

   What it is: a third-party AI provider registered with the WordPress AI Client. Titan uses the WordPress AI Client for booking-assistant replies and AI setup suggestions. The administrator must install and configure a compatible provider connector under Settings → Connectors. Titan does not ask for, store or transmit provider API keys directly.

   Data sent: when an administrator or visitor uses an AI feature, the relevant prompt and conversation context are sent by WordPress to the configured provider. Depending on the feature, this can include customer messages, booking details and service descriptions. Photo attachments are stored by this plugin; a photo URL may be included in the prompt when relevant. The AI provider processes this data under its own terms and privacy policy. Do not enable AI features for personal data unless you have reviewed the provider's policies and your legal obligations.

   Provider terms and privacy: these depend on the connector and provider the administrator installs. Examples include OpenAI (https://openai.com/policies/services-agreement/ and https://openai.com/policies/privacy-policy/), Anthropic (https://www.anthropic.com/legal/commercial-terms and https://www.anthropic.com/legal/privacy), Google AI Studio (https://ai.google.dev/gemini-api/terms and https://policies.google.com/privacy), Groq (https://groq.com/terms-of-use and https://groq.com/privacy-policy), OpenRouter (https://openrouter.ai/terms and https://openrouter.ai/privacy), and Cerebras (https://www.cerebras.ai/terms-of-service and https://www.cerebras.ai/privacy-policy). Other providers and local servers have their own terms.

   Purpose: AI chat replies and setup suggestions. No AI request is sent unless an AI feature is used and a compatible connector is configured.



2. Local or compatible AI server (optional)

   What it is: a local or self-hosted service exposed through a WordPress AI Client provider connector. Its administrator is responsible for securing the endpoint and reviewing the provider connector's behaviour. Data sent is the prompt content described in item 1.

   Purpose: AI replies and drafts using a service selected and configured by the site administrator.



3. SMS gateway and phone system (optional, missed-call text-back)

   What it is: an SMS gateway that you choose, and your phone system. The gateway address must be a secure HTTPS address. Your phone system or gateway sends events to this site's webhook: a missed or unanswered call, and an incoming text such as STOP. Each event must carry the webhook secret that you set (at least 32 characters). This site does not contact the phone system.

   Sent: after a missed call, this site sends the caller's phone number and one text message with the booking link to the gateway address you entered, with any authentication header you entered. If a caller replies STOP, UNSUBSCRIBE, END, CANCEL or QUIT, the number is recorded and no more texts are sent to it until the caller replies START.

   Terms and privacy: the plugin does not include an SMS gateway or phone provider. Check the terms and privacy policy of the gateway and phone system you use before you turn this on. The separate Twilio option is described in item 7.

   Purpose: texting a caller back when a call is missed.



4. Web page for service import (optional, setup wizard)

   What it is: a web page with a price list or list of services that you name in the setup wizard. The address must be a secure HTTPS address.

   Sent: when you press Next on the import step with a web address entered, your site makes one ordinary web request for that page and reads its list items once. The request carries the usual details of a web request, such as your site's IP address. No customer data is sent. You then choose which items to keep. The page's owner sets its own terms and privacy policy.

   Purpose: suggesting services from an existing price list or web page.



5. Webhook endpoint (optional)

   What it is: an HTTPS address and a signing secret (at least 32 characters) that you enter in the webhook add-on settings, such as your own app or automation tool.

   Sent: a JSON message for each booking event, about one minute after the event, signed with HMAC-SHA256 using your secret. For a new booking it contains the booking ID, form ID and name, and for each appointment its ID, service, date, time slot, duration, price, quantity and status. For a status change it contains the booking ID and the new status. The default events do not include the customer's name, email address or phone number.

   If the endpoint cannot be reached, or replies with a temporary error (a timeout, a redirect, 408, 425, 429 or 5xx), the plugin retries. It waits 1, 5, 15 and 60 minutes between attempts, so there are up to 5 attempts in total over about 80 minutes.

   The receiving service sets its own terms and privacy policy.

   Purpose: notifying another system when bookings change.



6. Links that open in a visitor's own app (not a connection)

   The optional WhatsApp and SMS buttons below the booking form are links (a wa.me link for WhatsApp, an sms: link for texts). When a visitor taps one, their own phone or browser opens WhatsApp or their messaging app, and the visitor can edit the message before sending it. This site does not contact WhatsApp. WhatsApp terms: https://www.whatsapp.com/legal/terms-of-service Privacy: https://www.whatsapp.com/legal/privacy-policy



7. Twilio SMS (optional, off by default)

   What it is: Twilio, a third-party messaging provider. It is used only when the site owner sets TZFD_TWILIO_SMS_ENABLED to true in wp-config.php and also defines the Twilio account SID, auth token and sender number there. Nothing is sent to Twilio until then.

   Sent: for a text that the site has approved for a recipient who has consented, this site sends the recipient's phone number, the sender number and the message text (up to 1,200 characters) to api.twilio.com. If the site owner also defines TZFD_TWILIO_STATUS_CALLBACK_URL, Twilio is given that address for delivery status, and Twilio's status reports arrive at this site's tzfd/v1/twilio-status endpoint, which checks Twilio's signature before it accepts them.

   Terms: https://www.twilio.com/legal/tos Privacy: https://www.twilio.com/legal/privacy

   Purpose: sending approved missed-call and escalation texts through Twilio.



== Privacy ==





Bookings, customer details and settings are stored in your WordPress database. Titan Zero Front Desk does not create an account, collect a licence key or report usage to the author.



The personal data exporter is registered with the WordPress Export Personal Data tool. It covers booking records, assistant chats and staff contacts. Front Desk maintains its own local customer records; additional plugins may have their own data stores and privacy handling. Front Desk does not automatically erase historical bookings or local customer records. The WordPress erase tool removes client-portal sign-in links and sessions only. Bookings are kept until you delete them. Booking data remains when the plugin is deactivated or deleted, unless you add the constant described in the FAQ. Booking assistant chats are kept until the retention period ends.



The client portal stores a one-time sign-in link and a 2-hour session in WordPress temporary storage (transients), which expire automatically. Only the keys are hashed. While those records exist, the client's email address is kept with them as entered. The sign-in email is sent through your site's mail setup, not an outside service.



The IP address of each booking request is stored by default. To stop this, turn off "Store user's IP address?" under Settings, Booking rules.



== Credits ==



Titan Zero Front Desk incorporates GPLv2-derived code from Appointment Hour Booking 1.5.98 by CodePeople (https://wordpress.org/plugins/appointment-hour-booking/). It is not endorsed by CodePeople. Upstream authorship and release history are credited in the LICENSE.txt, UPSTREAM.txt and THIRD-PARTY-NOTICES.txt files included with the plugin.



Source code for the third-party scripts bundled with the plugin:

* Papa Parse 5.7.0 (MIT), the CSV parser used by the admin builder: https://github.com/mholt/PapaParse (js/tzfdform-admin-fields/00_jquery.parse.min.js)

* Chart.js 4.6.1: https://github.com/chartjs/Chart.js (js/chart.js)

* rrule 2.8.1: https://github.com/jkbrzt/rrule (tzfd-calendar/js/rrule.js, browser build). The same file includes a Microsoft tslib block (permissive licence); see THIRD-PARTY-NOTICES.txt section 5a.

* jQuery UI 1.14.2 Cupertino theme CSS (css/cupertino) and date picker language files (js/languages), dual licensed MIT or GPL 2: https://jqueryui.com/

* jQuery Validation 1.22.1 (MIT), with local changes listed in THIRD-PARTY-NOTICES.txt: https://github.com/jquery-validation/jquery-validation (js/jquery.validate.js)

* jQuery.stringify helper (js/jQuery.stringify.js): inherited from the upstream 1.5.98 package, GPLv2, by CodePeople (https://wordpress.org/plugins/appointment-hour-booking/). See THIRD-PARTY-NOTICES.txt.

* Calendar (tzfd-calendar/js/jquery.calendar.js) and form builder (js/tzfdform-admin.js): inherited from the upstream 1.5.98 package. These two files are shipped in readable form. See UPSTREAM.txt for the package hash.



== License ==



This plugin is licensed under the GNU General Public License, version 2 (GPLv2). The full licence text is in LICENSE.txt. The third-party components and their licences are listed in THIRD-PARTY-NOTICES.txt.



== Source code ==



The complete source code is in this plugin folder and is licensed under GPLv2. Nothing is obfuscated or encrypted. The public source and build steps for generated JavaScript are listed below.



* PHP, CSS and the plugin's own scripts are shipped as readable source.

* Three files are minified or built and have a readable copy in the js/src folder: js/tzfdform-public.js, js/chart.js and js/tzfdform-admin-fields/00_jquery.parse.min.js. Their readable copies are js/src/tzfdform-public.src.js, js/src/chart.src.js and js/src/00_jquery.parse.src.js.

* Three upstream scripts were minified in the upstream package and are shipped in readable form in their own place: tzfd-calendar/js/jquery.calendar.js, js/tzfdform-admin.js and js/jquery.validate.js. See UPSTREAM.txt.

* To rebuild the public form script (js/tzfdform-public.js): open the js/src folder in a terminal, run "npm ci", then run "npm run build:public". The build needs Node.js 18 or later and the PHP command line. The required package.json, package-lock.json and build helper are bundled in js/src. Run "npm run check:public" to verify that the generated public bundle matches the shipped files without modifying them.

* Chart.js (js/chart.js) is the official browser build (chart.umd.js) of Chart.js 4.6.1. Its readable copy is js/src/chart.src.js, and the full source is at https://github.com/chartjs/Chart.js.

* The other third-party libraries are listed with their source links in the Credits section above.



== Support ==

Support: support@titanzero.io
Licences and upgrades: sales@titanzero.io

== Changelog ==

= 3.0.128 =
* Setup wizard: clearer spacing on every step, plain-language policy questions, one question per row, and a booking page choice.
* Verticals: improved service types, questions and answers for all 65 bundled packs. Unverified facts are now placeholders for the owner to complete.
* Compliance guidance corrected for three packs.

= 3.0.127 =
* Booking submissions are saved under the company that owns the calendar. Requests are refused when the calendar has no valid owner or the active company does not match it.
* Booking-form messages and assistant conversations are limited to the current company's records.
* Chat assistant: photos from a rejected message are deleted from storage, the per-conversation photo limit is enforced before a file is stored, and the site-wide chat bubble no longer appears on any page.
* AI usage records use the token counts reported by the provider when they are available.
* Vertical pack setup keeps saved answers when a details field is left blank, and finishing setup requires the details step.
* Reminders are re-queued after a customer reschedule or a status change.
* Renaming a service is refused when another service in the same calendar already uses that name, before any change is saved.
* Call event storage failures are shown as an administrator notice rather than retried on every page load.
* Client-portal sign-in links are matched to the exact email address.

= 3.0.95 =
Add-on settings now require authorised POST and nonce validation instead of state-changing GET links; selected add-ons are checked against available licensed IDs; administrative writes enforce an aggregate parsed-data byte budget even without a Content-Length header.


= 3.0.93 =
* Verify calendar CRUD database writes; restrict misleading success notices and fail closed on calendar read errors.

= 3.0.92 =
* Restricted industry pack mutations to validated POST requests and rejected malformed request values.
* Prevented forged settings-saved notifications.

= 3.0.71 =
* Complete standalone customer address and notes creation/editing; safely upgrade existing local customer tables.
* Include chosen existing customer in the booking review summary.


= 3.0.28 =
- Customer event staging now refuses non-InnoDB booking tables and fails closed if transactional storage cannot be verified.
* Verify booking/form association before transactional customer-event staging; do not write cross-booking queue events.

= 3.0.24 =

* Harden staging integration probe failure cleanup and collision resistance (development tool only).

= 3.0.22 =

* Verify the queued booking belongs to its form before background notification hooks execute; quarantine mismatches and retry transient lookup failures.

= 3.0.20 =

* Reject malformed required customer rescheduling emails during transaction staging, before booking commit.

= 3.0.19 =
* Correct earlier customer notification retry wake scheduling and recovery on cron registration failures.
* Continue scheduled customer notification processing promptly when more than 50 companies have ready work.

= 3.0.16 =
* Retry transient owner-lookup failures without emitting notification hooks or mail; confirmed tenant mismatches remain blocked.

= 3.0.15 =
* Enforce verified form ownership for interactive dispatch, schedule deferred retries and continue capped background batches.

= 3.0.14 =
* Stage customer cancellation and rescheduling lifecycle hooks transactionally in a tenant-scoped outbox, with bounded retries and crash recovery.
* Require InnoDB storage for customer booking mutations and event staging; release advisory capacity locks on all paths.
* Customer rescheduling email remains direct delivery and is not yet covered by provider-level durable delivery.

= 3.0.11 =
* Capped retrying pipeline notification hooks at eight attempts; operator-scoped recovery and company failure counts. Live provider and database validation remains pending.

= 3.0.10 =
* Customer cancellation now joins the per-form capacity mutex shared with booking intake, approval and rescheduling; stale cancellation writes remain compare-and-swap protected.

= 3.0.5 =
* Explicit request-source validation, identifier-bound SQL, native duplicate-event handling, nullable inbox references and MariaDB regressions.
* Call-event transactions require InnoDB, including the completion outbox when installed. Unsupported storage is refused before writing.

= 3.0.1 =
* Updated Chart.js, protected export entry points and missing-dependency activation, corrected translation and SQL/output checks, and included reproducible build inputs.
* Private export and photo files now fail closed when restrictive permissions cannot be set.




= 3.0 =
* Clean activation notice when Workspace is inactive; slot-holds table installed on activation.

= 2.1.3 =

* Webhook routes (Twilio status and telephony call events) check their signature in the permission step, before the callback runs. They are registered by Front Desk itself; Workspace is optional.
* Fixed: online bookings were saved without their company, so their reminders and follow-ups were never scheduled. They now take the company of the calendar's booking form.

* Use Titan Zero Trades: Front Desk as the only shipped source for built-in vertical packs. Custom registered and company-scoped uploaded packs remain supported.

* Readme and help text: corrected the plugin folder name, the uninstall constant (TZ_DELETE_DATA_ON_UNINSTALL) and the product names used for booking features. Compatibility modules are now described accurately.

* Booking form, dashboard widget, status update emails and personal-data labels now name Titan Zero Front Desk where they describe bookings.

* Client portal: a sign-in link can be used only once, even when two requests use it at the same time.

* Cancel and reschedule pages: messages are escaped once, and missing saved-time fields no longer cause PHP notices.

* Public booking form: the captcha value is URL-encoded before the availability pre-check.



= 2.1.1 =

* Use the selected Workspace vertical packs' emergency and urgent indicators in AI receptionist context and rule-based fallback classification, while retaining general safety cues and human escalation.



= 2.1.0 =

* Historical note (superseded in 3.0.52): Front Desk formerly required Workspace; local infrastructure is now introduced.

* Quotes, invoices, payments, coupons and the customer list moved out to Titan Zero Quotes, Titan Zero Invoicing and Titan Zero Trades: Front Desk.

* Booking status changes for paid bookings go through TZ_FrontDesk::set_booking_status(), called by Titan Zero Invoicing. Front Desk no longer stores a paid flag.

* Read-only TZ_FrontDesk API: bookings_for_customer, booking_exists and booking_form.

* The generic webhook route (titan-zero-front-desk/v1/webhook/{slug}) and the booking.payment_update_attempted event were removed.

* Table, option, hook and REST names moved from tzfd_ to tz_fd_.



= 2.0.54 =

* Count coupon redemptions by exact stored code so overlapping reference prefixes cannot consume one another's usage quotas; existing codes containing # remain supported.

* Bound legacy calendar access-list decoding by input size and nesting depth.



= 2.0.53 =

* Apply each calendar's user access list to the Services screen, dedicated service creation and service address/location updates.

* Hide calendars the current user cannot manage from the service catalog and creation targets.

* Sync the selected time into the required booking field before the form builder's jQuery validation runs.

* Increase contrast for muted text across Front Desk screens.



= 2.0.52 =

* Reconcile an existing unallocated payment to a matching invoice without deleting or re-entering the payment.

* Allow an unlinked payment to be assigned to a selected invoice; keep booking-linked allocations within their booking and calendar.

* Replace payment deletion with a reasoned, timestamped void that records the WordPress user ID and reopens invoice balances.

* Update payment help and privacy information for the new reconciliation and audit behavior.



= 2.0.51 =

* Keep grouped navigation in the Front Desk workspace and remove duplicate top-level WordPress admin menu groups.

* Remove the separate WordPress menu list from the Front Desk sidebar.



= 2.0.50 =

* Group the Front Desk sidebar into Dashboard, Customers, Schedule, Money, Business Resources, AI, Add-ons and Settings.

* Link each section parent to its primary screen, show that section's pages when it is active, and route the Schedule parent to the calendar.



= 2.0.49 =

* Distinguish database read failures from valid empty results in the calendar, service, customer, quote, pipeline, invoice, payment, dashboard and client-portal screens.

* Preserve saved portal calendar settings and stop page creation when calendar reads fail.

* Report calendar lookup failures in WordPress personal-data export and erasure checks instead of treating them as missing tables.



= 2.0.48 =

* Show an explicit warning when invoice booking-payment reference totals cannot be read, instead of making a database failure look like no payments.

* Keep text truncation and admin initials working when the optional PHP mbstring extension is unavailable.

* Raise label, helper, field and disabled-control contrast across Front Desk admin screens.

* Label legacy admin booking appointment fields as service selection and permit multiple service/time entries on that form.

* Synchronize selected booking times from the calendar widget state immediately after selection and again before validation/submission.



= 2.0.47 =

* Keep text truncation and admin initials working when the optional PHP mbstring extension is unavailable.

* Raise label, helper, field and disabled-control contrast across Front Desk admin screens.

* Label legacy admin booking appointment fields as service selection and permit multiple service/time entries on that form.

* Synchronize selected booking times from the calendar widget state immediately after selection and again before validation/submission.



= 2.0.46 =

* Improve label, helper, field and disabled-control contrast throughout Front Desk admin screens.

* Rename legacy admin booking appointment labels to “Choose service” and allow multiple service/time entries on the admin booking form.

* Synchronize selected calendar slots from widget state immediately after selection and before form validation/submission.



= 2.0.45 =

* Strictly validate typed amounts, accepting correctly grouped values and the configured currency code or symbol while rejecting malformed text, invalid grouping, excess decimal places and non-scalar input. Quote prices and calculated totals must fit their decimal columns.

* Bound payment amounts to the database column range and prevent failed payment reads from being shown as zero totals or an empty payment list.

* Reserve limited coupon uses before saving the discounted booking and remove the reservation when the booking insert is known to have failed.

* Recover escaped calendar JSON safely and show an unavailable message instead of calling the missing legacy JSON class.



= 2.0.44 =

* Serialized invoice payment allocations and invoice edits, status changes, deletion and payment removal with a shared per-invoice lock.

* Reconciled invoice balances from recorded payment allocations, made database read failures fail closed, and blocked voiding invoices with recorded payments.

* Added a service creation form that preserves calendar data and detects concurrent calendar edits.

* Checked the selected portal calendar on both page creation routes; the server-side booking selection and capacity checks remain in the submit path.

* Added direct invoice and payment navigation, repaired resource permissions and loaded portal builder styles on both admin routes.

* Serialized coupon usage checks with redemption writes to enforce usage limits across concurrent bookings.



= 2.0.4 =

* Fixed the Front Desk sidebar icon data URI so WordPress admin reliably displays the receptionist SVG icon.





= 2.0.3 =

* Fixed admin stylesheet screen detection without reading GET parameters.

* Changed the pipeline action invocation to a literal, plugin-prefixed hook name.

* Updated AI privacy-policy wording to describe WordPress AI Client connectors.



= 2.0.2 =

* Migrated AI requests to the native WordPress AI Client. Provider credentials and model routing are managed by WordPress Settings → Connectors; Titan no longer collects provider keys or calls AI vendor APIs directly.

* Updated the setup wizard and AI settings to direct administrators to WordPress Connectors and compatible provider connector plugins.

* Removed legacy front-end code paths that printed script and stylesheet tags inside booking forms. Assets are enqueued through WordPress.

* Enqueued the settings and quote-print stylesheets through WordPress rather than printing styles in page content.

* Updated the minimum WordPress version to 7.0 for the native AI Client and Connectors APIs.



= 2.0.1 =

* Removed the custom CSS editor. Saved custom CSS is no longer output on any page. Saved settings are kept.

* Added nonce and permission checks to admin actions and to public requests that change data.

* Moved inline scripts and styles into script and style files that WordPress loads.

* Removed the upgrade card and the "Powered by AI" line from the booking assistant.

* Updated Chart.js to 4.6.1.

* Documented each external service, including Cerebras, and included readable source for the minified scripts.

* Help page: the list of Settings tabs no longer names Custom CSS or Styles.

* Uninstall removes chat photos and invitation files from the plugin's own uploads folder, and chat photos that version 2.0.0 saved in the uploads root. Other files in uploads are not touched.

* Calendar feed: each date range is cached separately, so a different range no longer shows an earlier one.

* Front Desk admin pages check the edit_pages capability before they open.

* Updated jQuery Validation to 1.22.1.

* Calendar feed and public availability return only the bookings inside the requested date range.

* iCal feed: a daylight saving setting of No is respected, and cancelled and rejected bookings are left out of the attachment.

* Quote links: an unknown or wrong link returns the same 403 response.

* Report screen: removed the Export to CSV button, which had no action.

* Report and admin export forms check a nonce before they change anything.

* Quote approval is refused for drafts, declined or expired quotes, and quotes past their valid-until date.

* Quote PDFs no longer show internal notes, and declined or expired quotes no longer show the approval block.

* The dashboard delete link was removed. It did nothing.

* iCal feed: Australia and New Zealand daylight saving dates are corrected to the Southern Hemisphere rules.



= 2.0.0 =

* New brand graphics throughout: Help page feature cards and workflow overview, Preset Setup flow, trade pack banners, status tags, screen heading icons and the WordPress.org banner and icon.

* Renamed to Titan Zero Front Desk.

* Major release. Adds a client portal (one-time sign-in links, view, change or cancel bookings and quotes), a booking page created as normal WordPress pages, a restructured Front Desk menu, a booking pipeline run by agents with staff approval, and calendar event editing in a new browser tab.

* Safer scripts and Plugin Check fixes, including escaped output on the pipeline screen.



= 1.0.6 =

* Request custom changes, Request Custom Modifications and Free Support now open an email to support@titanzero.io. The Help links pointed to the vendor help site.



= 1.0.5 =

* Safer scripts: the booking form and calendar scripts no longer use eval(). Behaviour is unchanged.

* Direct-access guards added to the two rule classes.

* Readme and package checked against the WordPress.org plugin guidelines.



= 1.0.3 =

* Add-on files are grouped into folders: core, bookings, messaging, quotes and assistant. Core loads first, because it holds the base class the others extend.

* The add-on file list is built by its own class, TZFD_Addon_Loader, which has tests. Folders not on the list are never loaded.

* No change for customers or settings. Every add-on loads and works as before.



= 1.0.2 =

* Booking pipeline restructured into agents. Intake is a worker. The booking check, slot check and approver are specialists. The pipeline manager holds the automatic approval setting and can reject escalated bookings. An orchestrator runs them in order.

* Each agent may only do what its tier allows. A lower-tier agent cannot call a higher one. The tier boundaries are covered by tests.

* The supervisor runs the same steps as a new booking, so bookings it retries are checked the same way.

* No change to what customers see. Bookings are still saved as pending, checked, then approved by staff, or automatically when automatic approval is on.



= 1.0.1 =

* New Pipeline screen under Front Desk. Bookings from the AI assistant are saved to the calendar as pending requests and checked before staff approve them.

* Booking manager checks each request: a valid date and time, not in the past, a service, a name, a contact, and a slot length that stays within one day.

* Jobs check confirms the slot is still free before approval, using the calendar's own capacity lock.

* Staff can approve, reject, or ask for another check. Every state change is logged for review.

* A supervisor runs hourly to retry bookings left in processing and to flag any that stall.

* Fixed: AI assistant bookings were saved as drafts only. They are now created in the calendar.

* Fixed: a slot that is taken now returns a clear message to the assistant so it can offer another time.



= 1.0.0 =

* First WordPress.org release. Independent GPLv2 fork of Appointment Hour Booking 1.5.98 by CodePeople.

* Setup routes: industry presets (65 vertical packs) and an AI setup wizard. Every answer and price is reviewed before anything is installed.

* Quotes built from a job description, with print-ready quote pages and optional PDF export through a rendering service you configure.

* Coupons with expiry dates and usage limits.

* Optional AI booking assistant using WordPress Connectors, and missed-call text-back using a configured SMS gateway.

* Optional webhook for signed booking events, WhatsApp and SMS buttons.

* Add-on settings: every add-on has a settings block on the Add-ons page.

* AI provider credentials are managed in WordPress Settings → Connectors.

* Customers can cancel or move a booking from the link in their email, when the form's copy-to-customer option is on.

* Booking page and client portal, created from Settings. Clients sign in with an emailed one-time link to see and change their bookings and quotes.

* Status update emails go to the customer when an administrator changes a booking's status.

* Every add-on in this version is free.


== Upgrade Notice ==

= 3.0.127 =
Booking submissions are now saved under the company that owns the calendar, and a calendar with no valid owner refuses submissions. Check that each calendar has an owner after upgrading. The site-wide chat bubble no longer appears on any page.

== Template metadata ==

The twelve bundled booking-form designs use JSON metadata (templates/01 through templates/12, config.json). The legacy config.ini descriptors are excluded from distribution. The original template IDs, CSS files and thumbnails are preserved so existing saved selections continue to resolve.

== Smart admin wizards ==

The Add Booking, native Customers, Services, Workers, Equipment/Rooms and Locations editing forms use step-by-step navigation when JavaScript is available. Values are never saved by smart fill until the administrator submits the original form.

AI-assisted field suggestions are optional and use the official WordPress AI Client interface (`wp_ai_client_prompt`) available on WordPress 7.0+. Configure an OpenAI or other supported connector under Settings > Connectors and provide the API key THERE; Front Desk does not store provider credentials. WordPress's AI plugin can optionally provide additional experiments and AI administration interfaces but is not required for Front Desk activation. Without a working AI connector, deterministic local suggestions and normal form editing still work. Each suggestion is explicitly requested, reviewed and applied by the administrator.

Only the description typed into the suggestion box is sent for the AI suggestion request. Do not enter confidential personal information into that box. Generated suggestions may be inaccurate; review all fields before saving. AI provider usage may incur charges.
