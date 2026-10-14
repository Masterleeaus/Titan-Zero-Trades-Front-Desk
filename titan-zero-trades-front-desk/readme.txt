=== Titan Zero Trades: Front Desk ===
Contributors: titan0
Tags: booking, appointments, calendar, scheduling, quotes
Requires at least: 6.2
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 2.0.1
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Appointments, service quotes and a guided setup for trades and service businesses. Free, with no account or licence key.

== Description ==

Titan Zero Trades: Front Desk lets customers book appointments on your website and lets you manage them from WordPress.

It is built for service businesses: plumbers, cleaners, salons, tutors, clinics and similar. You define your services, durations, working hours and staff or resource capacity, and customers pick a free time slot from your booking form.

Main features

* Booking form with calendars, working hours, service durations and capacity limits
* Booking page and client portal, created from Settings. The pages use your theme's layout. Clients sign in with their email address to see their bookings and quotes, and to change or cancel upcoming bookings
* Admin calendar and appointment list, with status changes and CSV export
* Email notifications, customer reminders, follow-ups and customer cancellation or rescheduling
* Coupon codes with expiry dates and usage limits. A valid code reduces the total shown in confirmation emails.
* Quotes built from a job description, with a print-ready quote page you can save as PDF from any browser
* Two guided setup routes: an industry preset wizard (65 vertical packs with example services, reference answers and example prices) and an AI setup wizard. You review and edit every answer and price before anything is installed.
* Optional AI booking assistant that uses your own AI provider key, or a local AI server you run
* Optional text-back to missed calls, using your own phone system and SMS gateway
* Optional WhatsApp and SMS buttons below the booking form
* Optional webhook that sends signed booking events to an HTTPS endpoint you choose
* Dashboard widget with upcoming appointments
* Logs of booking additions, status changes and deletions
* Personal data exporter for the WordPress Export Personal Data tool

Titan Zero Trades: Front Desk is a free, GPL-licensed plugin. Nothing in it is locked behind a paid version or licence key.

The plugin has no upgrade menu item, no upgrade link and no upgrade prompts, and the AI settings page has no upgrade card. Reminders appear only to administrators, and only on Front Desk screens: one while no AI key is saved, one about the booking speed add-on, and one until AI setup is finished. The first two can be set aside for 30 days; the setup reminder can be dismissed.

Industry presets are examples. The services, answers and prices in a preset are starting points written for a typical business. Check and change them before you publish your booking form.

== Installation ==

1. Upload the titan-zero-trades-front-desk folder to /wp-content/plugins/, or install the plugin from the Plugins screen in WordPress.
   This is a new plugin, not an upgrade. Do not install it over Appointment Hour Booking or Titan Front Desk; see the FAQ below.
2. Activate Titan Zero Trades: Front Desk from the Plugins screen.
3. Open Front Desk in the admin menu. Follow a guided setup, or skip it and configure everything by hand.
4. Create a calendar, then publish its booking form on a page or post. The publish screen explains the options, and the Insert Titan Zero Trades: Front Desk button in the editor adds the form.
5. Optional: go to Settings, then Booking page and client portal, and click Create or update pages. This creates a booking page and a client portal as normal WordPress pages, which you can add to your menu.

== Frequently Asked Questions ==

= Is Titan Zero Trades: Front Desk free? =

Yes. It is licensed under the GPL, version 2, and every feature in this version is included.

= Do I need an account or a licence key? =

No.

= Do my customers need an account to use the client portal? =

No. They enter the email address they booked with, and we send them a sign-in link. The link works once and expires after 20 minutes. Signing in lasts 2 hours.

= Does it send my customers' data anywhere? =

Only to the external services listed above, and only when you turn them on and enter their details. Without those, bookings stay in your WordPress database.

= Are the prices in the setup presets real? =

No. They are examples. You can edit each price, and each answer, before the preset is installed. Set your own rates before you publish a booking form.

= Can I use an AI model? =

Yes, with your own key from a provider such as OpenAI or Anthropic, or with a local AI server you run yourself. The assistant sends nothing until a provider and key are set.

Google AI Studio, Groq, OpenRouter and Cerebras each let you create an API key on their website. Each provider sets its own limits, prices and terms, and they can change. Ollama and other servers you run need no key.

= Will my bookings be removed if I delete the plugin? =

No. Uninstalling clears the plugin's scheduled tasks and keeps your bookings and settings. It also deletes the photos customers attached in the chat and the calendar invitation files the plugin created. To delete everything, add define( 'TZFD_DELETE_DATA_ON_UNINSTALL', true ) to wp-config.php before you delete the plugin.

= Can I upgrade from Appointment Hour Booking or Titan Front Desk? =

No. Titan Zero Trades: Front Desk does not read the bookings, customers or settings of those plugins. It uses its own tables and option names, so the old data stays where it is. There is no import tool in this version. Keep the old plugin until you have what you need from it, then deactivate and remove it. Enter any bookings you still need here by hand.

= Where do I get help? =

Visit https://titanzero.io/support/ or email support@titanzero.io.

= Where do I report a security problem? =

Email support@titanzero.io. Please do not post exploit details publicly until a fix is available.

== External services ==

Titan Zero Trades: Front Desk connects to the external services below only when the site administrator turns them on and enters their own details. Nothing is sent to the plugin author or to any other service by default. The plugin does not load scripts, fonts or images from outside services on public pages.

1. AI provider that you choose (optional)
   What it is: an AI company's service, or a server you run (see item 2). The booking assistant uses it to reply to customers in chat, and the quote assistant uses it to draft quotes. You choose the provider and enter your own API key.
   Providers you can choose, with their terms and privacy policy:
   * OpenAI (api.openai.com). Terms: https://openai.com/policies/services-agreement/ Privacy: https://openai.com/policies/us-privacy-policy/
   * Anthropic (api.anthropic.com). Terms: https://www.anthropic.com/legal/commercial-terms Privacy: https://www.anthropic.com/legal/privacy
   * Groq (api.groq.com). Terms: https://groq.com/terms-of-use Privacy: https://groq.com/privacy-policy
   * OpenRouter (openrouter.ai). Terms: https://openrouter.ai/terms Privacy: https://openrouter.ai/privacy
   * Google AI Studio (generativelanguage.googleapis.com). Terms: https://ai.google.dev/gemini-api/terms Privacy: https://policies.google.com/privacy
   * Cerebras (api.cerebras.ai). Terms: https://www.cerebras.ai/terms-of-service Privacy: https://www.cerebras.ai/privacy-policy
   * Any other OpenAI-compatible server that you enter. Its own terms and privacy policy apply.
   If you save an API key without choosing a provider, the key is used with OpenAI (api.openai.com).
   Nothing is sent to a provider until you save a provider and, where it needs one, a key.
   Sent to the provider you chose:
   * Booking assistant: when a customer sends a message in the chat, the chat messages so far, the assistant's instructions, the booking details collected so far, and the link to any photo the customer attaches. The provider receives the photo's link as text, not the image itself.
   * Quote assistant: when you ask for quote suggestions, the job's description, service type and urgency, the customer's name and address, equipment and access notes, the number of photos attached (not the photos), your business name, currency, tax rate and markup, the catalogue items that match the job (name, SKU and unit price), and up to five earlier accepted quotes for similar work (reference, description, total and line items).
   * Test button: a short test message.
   Photos: a photo is saved in a folder for this plugin inside the site's uploads folder, under a random file name. Anyone with its link can open it while it exists. Chat conversations and photos are deleted after the retention period set in the AI settings (30 days by default).
   Purpose: AI chat replies in the booking assistant, and AI-assisted quote drafts. The API key is stored encrypted in the site's database and is used only for these requests.

2. Local AI server that you run (optional)
   What it is: an AI server on your own computer or network, such as Ollama, LM Studio, vLLM or LocalAI. The Ollama choice is filled in with localhost on port 11434 (Ollama's default). You can enter any other address.
   Sent: the same messages, photo links and quote details as item 1, sent only to the address you enter. When you press Detect server, the plugin asks that address for its list of models, which sends no customer data. When you press a test button, it sends the short test message. If you enter an address that is not on your own computer or network, that server receives the data like any other provider.
   Purpose: AI replies and quote drafts on a server you run. The plugin does not install, download or run those tools.

3. SMS gateway and phone system (optional, missed-call text-back)
   What it is: an SMS gateway that you choose, and your phone system. The gateway address must be a secure HTTPS address. Your phone system or gateway sends events to this site's webhook: a missed or unanswered call, and an incoming text such as STOP. Each event must carry the webhook secret that you set (at least 32 characters). This site does not contact the phone system.
   Sent: after a missed call, this site sends the caller's phone number and one text message with the booking link to the gateway address you entered, with any authentication header you entered. If a caller replies STOP, UNSUBSCRIBE, END, CANCEL or QUIT, the number is recorded and no more texts are sent to it until the caller replies START.
   Terms and privacy: the plugin does not include a gateway or phone provider. Check the terms and privacy policy of the provider you use before you turn this on.
   Purpose: texting a caller back when a call is missed.

4. Web page for service import (optional, setup wizard)
   What it is: a web page with a price list or list of services that you name in the setup wizard. The address must be a secure HTTPS address.
   Sent: when you press Next on the import step with a web address entered, your site makes one ordinary web request for that page and reads its list items once. The request carries the usual details of a web request, such as your site's IP address. No customer data is sent. You then choose which items to keep. The page's owner sets its own terms and privacy policy.
   Purpose: suggesting services from an existing price list or web page.

5. Webhook endpoint (optional)
   What it is: an HTTPS address and a signing secret (at least 32 characters) that you enter in the webhook add-on settings, such as your own app or automation tool.
   Sent: a JSON message for each booking event, about one minute after the event, signed with HMAC-SHA256 using your secret. For a new booking it contains the booking ID, form ID and name, and for each appointment its ID, service, date, time slot, duration, price, quantity and status. For a status change it contains the booking ID and the new status. For a payment update it contains the booking ID and the payment status, and only when a payment extension reports one. The default events do not include the customer's name, email address or phone number.
   If the endpoint cannot be reached, or replies with a temporary error (a timeout, 408, 425, 429 or 5xx), the plugin retries, making up to 5 attempts in total over about an hour.
   The receiving service sets its own terms and privacy policy.
   Purpose: notifying another system when bookings change.

6. Links that open in a visitor's own app (not a connection)
   The optional WhatsApp and SMS buttons below the booking form are links (a wa.me link for WhatsApp, an sms: link for texts). When a visitor taps one, their own phone or browser opens WhatsApp or their messaging app, and the visitor can edit the message before sending it. This site does not contact WhatsApp. WhatsApp terms: https://www.whatsapp.com/legal/terms-of-service Privacy: https://www.whatsapp.com/legal/privacy-policy

== Privacy ==

Privacy policy: https://titanzero.io/privacy/

Bookings, customer details and settings are stored in your WordPress database. Titan Zero Trades: Front Desk does not create an account, collect a licence key or report usage to the author.

The personal data exporter is registered with the WordPress Export Personal Data tool. The WordPress erase tool removes client-portal sign-in links and sessions only. Bookings, quotes, customer records and payments are kept until you delete them in Front Desk. Booking assistant chats are kept until the retention period ends. Booking and quote records are kept until you delete them. Deactivating the plugin does not delete your bookings or settings. Deleting the plugin keeps them too, unless you add the constant described in the FAQ.

The client portal stores a one-time sign-in link and a 2-hour session in WordPress temporary storage (transients), which expire automatically. Only the keys are hashed. While those records exist, the client's email address is kept with them as entered. The sign-in email is sent through your site's mail setup, not an outside service.

The IP address of each booking request is stored by default. To stop this, turn off "Store user's IP address?" under Settings, Booking rules.

== Credits ==

Titan Zero Trades: Front Desk is an independent GPLv2 fork of Appointment Hour Booking 1.5.98 by CodePeople (https://wordpress.org/plugins/appointment-hour-booking/). It is not endorsed by CodePeople. Upstream authorship and release history are credited in the LICENSE.txt, UPSTREAM.txt and THIRD-PARTY-NOTICES.txt files included with the plugin.

Source code for the third-party scripts bundled with the plugin:
* Papa Parse 5.7.0 (MIT), the CSV parser used by the admin builder: https://github.com/mholt/PapaParse (js/tzfdform-admin-fields/00_jquery.parse.min.js)
* Chart.js 4.5.1: https://github.com/chartjs/Chart.js (js/chart.js)
* rrule 2.8.1: https://github.com/jkbrzt/rrule (tzfd-calendar/js/rrule.js, browser build)
* jQuery UI 1.14.2 Cupertino theme CSS (css/cupertino) and date picker language files (js/languages), dual licensed MIT or GPL 2: https://jqueryui.com/
* jQuery Validation 1.22.1 (MIT), with local changes listed in THIRD-PARTY-NOTICES.txt: https://github.com/jquery-validation/jquery-validation (js/jquery.validate.js)
* jQuery.stringify helper (js/jQuery.stringify.js): inherited from the upstream 1.5.98 package, GPLv2, by CodePeople (https://wordpress.org/plugins/appointment-hour-booking/). See THIRD-PARTY-NOTICES.txt.
* Calendar (tzfd-calendar/js/jquery.calendar.js) and form builder (js/tzfdform-admin.js): inherited from the upstream 1.5.98 package. These two files are shipped in readable form. See UPSTREAM.txt for the package hash.

== License ==

This plugin is licensed under the GNU General Public License, version 2 (GPLv2). The full licence text is in LICENSE.txt. The third-party components and their licences are listed in THIRD-PARTY-NOTICES.txt.

== Source code ==

The complete source code is in this plugin folder and is licensed under GPLv2. Nothing is obfuscated or encrypted.

* PHP, CSS and the plugin's own scripts are shipped as readable source.
* Three files are minified or built and have a readable copy in the js/src folder: js/tzfdform-public.js, js/chart.js and js/tzfdform-admin-fields/00_jquery.parse.min.js. The file js/src/README.md lists each one, its readable copy and how to rebuild it.
* Three upstream scripts were minified in the upstream package and are shipped in readable form in their own place: tzfd-calendar/js/jquery.calendar.js, js/tzfdform-admin.js and js/jquery.validate.js. See UPSTREAM.txt.
* To rebuild the public form script (js/tzfdform-public.js): open the js/src folder in a terminal, run "npm ci", then run "npm run build:public". The build needs Node.js 18 or later and the PHP command line.
* Chart.js (js/chart.js) is the official browser build (chart.umd.js) of Chart.js 4.5.1. Its readable copy is js/src/chart.src.js, and the full source is at https://github.com/chartjs/Chart.js.
* The other third-party libraries are listed with their source links in the Credits section above.

== Changelog ==

= 2.0.1 =
* Removed the custom CSS editor. Saved custom CSS is no longer output on any page. Saved settings are kept.
* Added nonce and permission checks to admin actions and to public requests that change data.
* Moved inline scripts and styles into script and style files that WordPress loads.
* Removed the upgrade card and the "Powered by AI" line from the booking assistant.
* Updated Chart.js to 4.5.1.
* Documented each external service, including Cerebras, and included readable source for the minified scripts.
* Help page: the list of Settings tabs no longer names Custom CSS or Styles.
* Uninstall removes chat photos and invitation files from the plugin's own uploads folder, and chat photos that version 2.0.0 saved in the uploads root. Other files in uploads are not touched.
* Calendar feed: each date range is cached separately, so a different range no longer shows an earlier one.
* Front Desk admin pages check the edit_pages capability before they open.
* AI provider calls report a failed response as an error. The provider address must be http or https.
* Help links point to the titanzero.io support page.
* Updated jQuery Validation to 1.22.1.
* Calendar feed and public availability return only the bookings inside the requested date range.
* iCal feed: a daylight saving setting of No is respected, and cancelled and rejected bookings are left out of the attachment.
* Local AI server checks run only when an administrator presses the check button, and accept only http or https addresses.
* Quote links: an unknown or wrong link returns the same 403 response.
* Report screen: removed the Export to CSV button, which had no action.
* Report and admin export forms check a nonce before they change anything.
* Quote approval is refused for drafts, declined or expired quotes, and quotes past their valid-until date.
* Quote PDFs no longer show internal notes, and declined or expired quotes no longer show the approval block.
* The dashboard delete link was removed. It did nothing.
* iCal feed: Australia and New Zealand daylight saving dates are corrected to the Southern Hemisphere rules.

= 2.0.0 =
* New brand graphics throughout: Help page feature cards and workflow overview, Preset Setup flow, trade pack banners, status tags, screen heading icons and the WordPress.org banner and icon.
* Renamed to Titan Zero Trades: Front Desk.
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
* Optional AI booking assistant and missed-call text-back, each with your own keys or servers.
* Optional webhook for signed booking events, WhatsApp and SMS buttons.
* Add-on settings: every add-on has a settings block on the Add-ons page.
* AI has its own tab in Settings, with your own key.
* Customers can cancel or move a booking from the link in their email, when the form's copy-to-customer option is on.
* Booking page and client portal, created from Settings. Clients sign in with an emailed one-time link to see and change their bookings and quotes.
* Status update emails go to the customer when an administrator changes a booking's status.
* Every add-on in this version is free.
