<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( ! is_admin() ) { echo 'Direct access not allowed.'; exit; }

// Support goes to the titanzero.io support page for this plugin.
$tzfd_help_support_url = 'https://titanzero.io/support/';
wp_enqueue_script( 'tzfd-adminb-help', plugins_url( 'js/inline/adminb-help.js', __FILE__ ), array(), '2.0.1', true );

$tzfd_help_sections = array(

    array(
        'id'    => 'start',
        'title' => __( 'Getting started', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'What does Titan Zero Trades: Front Desk do?', 'titan-zero-trades-front-desk' ),
                   __( 'It takes appointments and bookings for services that have set schedules, durations and capacity. You define calendars, services and working hours, customers book through a form on your site, and you manage every booking from the WordPress admin.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I set it up?', 'titan-zero-trades-front-desk' ),
                   __( 'On Dashboard, choose Start setup. The setup wizard starts a calendar from a trade preset with ready-made services and answers, and asks whether bookings are instant or held as requests. You can also reopen it from Settings, then Preset Setup, or connect an AI provider on the AI page. You can skip any of these and configure everything by hand.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Do I need to write any code?', 'titan-zero-trades-front-desk' ),
                   __( 'No. Settings, Addons and the setup wizards cover everything for a normal site. The Developers section describes optional hooks for people who extend the plugin.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I test it without taking real bookings?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Open your booking page in a private browser window, make a test booking, then check that it appears in Booking Orders and that the confirmation email arrives. Use Block Times to close the test date afterwards if needed.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Where is the Front Desk menu?', 'titan-zero-trades-front-desk' ),
                   __( 'Front Desk is in the main WordPress admin menu. Its items are Dashboard, Calendar, Customers, Bookings, Quotes, Services, Workers & locations, AI, Add-ons and Settings. Payments opens from the Total payments card on the Dashboard. Settings holds the other tools as tabs: Email reports, Troubleshooting, CSV export, Calendar display, Other settings, AI, Business and money, Help, Status update emails, Scheduling Preview, Preset Setup and Vertical Packs. Booking Orders, Schedule, Stats and the other calendar tools open from each calendar.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I place the booking form with Elementor?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Elementor has a Titan Zero Trades: Front Desk widget. Insert it on the page, then choose your booking form under Select Form.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'calendars',
        'title' => __( 'Calendars and services', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'What is a calendar?', 'titan-zero-trades-front-desk' ),
                   __( 'A calendar is one bookable schedule with its own services, working hours, booking form and settings. Run several, for example one per team member, location or type of work.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Where do I create a calendar?', 'titan-zero-trades-front-desk' ),
                   __( 'Go to Calendar, then Manage calendars, then New Calendar. Give it a name, set its services and working hours, and save it.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Where do I see all my bookings?', 'titan-zero-trades-front-desk' ),
                   __( 'Calendar shows every booking from every calendar. Switch between Day, Week and Month, and filter to one calendar with the Calendar box. Each booking links to its calendar’s Booking Orders. Cancelled bookings stay visible, marked by their status.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can a service have an address?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Open Services and choose Address beside a service. Enter where it is given and, optionally, pick one of your saved locations. The address is saved for your records. It is kept apart from the calendar, so editing the calendar does not remove it. Renaming a service starts its address again.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I put the booking form on a page?', 'titan-zero-trades-front-desk' ),
                   __( 'Open the calendar’s Publish section and copy the shortcode labelled Shortcode for Pages and Posts. It looks like <code>[TZFD_FRONT_DESK id="5"]</code>, where 5 is the calendar’s ID. Paste it into a page, a post or a shortcode block. The Publish wizard can also insert the form into a new or existing page or post for you.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I hide a calendar from the schedule view?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. On the Calendar display tab in Settings, open Advanced and tick Hide this calendar from the schedule.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is the difference between admin and website titles?', 'titan-zero-trades-front-desk' ),
                   __( 'The Calendar display tab has two versions of the event title and details: one for the admin area and one for your website. Keep internal notes out of the website version.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can staff see blocked times in the admin calendar?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes, if Show blocked times in the admin calendar is ticked under Advanced on the Calendar display tab.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is Scheduling Preview?', 'titan-zero-trades-front-desk' ),
                   __( 'It is a read-only planner for administrators under Front Desk. It previews daily, weekly or monthly visits against the calendar’s rules, recorded occupancy, capacity and service buffers. It does not create bookings, send messages or take payments.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I record a payment?', 'titan-zero-trades-front-desk' ),
                   __( 'Open Payments and fill in Record a payment: the amount, how it was paid (PayPal, bank transfer or cash), the date received and, optionally, the booking it is for and a reference such as a transaction ID. The totals at the top show what was received today, this month and in total. Remove deletes a payment that was recorded by mistake. Payments are recorded for your records.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I tell customers how to pay?', 'titan-zero-trades-front-desk' ),
                   __( 'On the Payments page, under Payment methods, turn on PayPal, bank transfer or cash and enter your account details and any note for customers. The Payment instructions card then has text you can copy into a booking confirmation or invoice. Only switched-on methods appear there. Nothing is sent to customers automatically.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is Business and money?', 'titan-zero-trades-front-desk' ),
                   __( 'It is a Settings tab for your business name, phone, email, tax number, website and address, and for the currency every amount is shown in: the three-letter code, the symbol, where the symbol goes and the decimal places. Changes apply straight away to the Dashboard, Payments and quotes.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What are Workers, and what is the availability preview?', 'titan-zero-trades-front-desk' ),
                   __( 'Workers is for the people, equipment and rooms bookings use. Locations, a tab of Workers & locations, hold the places where they work: each location has a name, address, phone, timezone and the postcodes it covers. Each worker has a name, role, type, location, phone, email, notes, working days and hours, days off, how many bookings they take at once, and any time needed before or after each booking. Each location has a name, address, phone, timezone and the postcodes it covers. The availability preview checks who could take a booking. It does not book anything.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I offer more than one place for a service?', 'titan-zero-trades-front-desk' ),
                   __( 'If a service has capacity, such as a group class, the form shows a Quantity field so the customer can book more than one place. The label can be changed in the form’s text settings. Scheduling rules check capacity and overlaps when a booking is made, and again when it is confirmed.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I block some places on a multi-place service?', 'titan-zero-trades-front-desk' ),
                   __( 'On Block Times, set the Quantity to the number of places you want closed. Leave it at 1 for a single place.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do time zones work?', 'titan-zero-trades-front-desk' ),
                   __( 'Times use the WordPress site timezone, so set it under Settings, then General in WordPress. Opening hours, closed dates, booking limits, daylight-saving changes, capacity and blocks are all checked again when a booking is confirmed.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'bookings',
        'title' => __( 'Bookings and staff', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'Where do I see bookings?', 'titan-zero-trades-front-desk' ),
                   __( 'Each calendar has Booking Orders, which lists its bookings. Schedule shows them on a calendar view, and Stats shows totals. Reports includes the full statistics view.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What do the booking statuses mean?', 'titan-zero-trades-front-desk' ),
                   __( 'Each appointment has one status: Approved (the default), Pending, Cancelled, Cancelled by customer, Rejected or Attended. Change it in Booking Orders. Pending bookings can be cancelled automatically if you turn on Automatically cancel pending bookings.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I add a booking by hand?', 'titan-zero-trades-front-desk' ),
                   __( 'Open the calendar and choose Add Booking. Use this for phone calls and walk-in customers.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I close a day or a time?', 'titan-zero-trades-front-desk' ),
                   __( 'Use Block Times on the calendar to stop customers booking specific dates or times, for example public holidays or staff leave.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I see the next appointments on my dashboard?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Turn on Dashboard Widget: Upcoming appointments under Addons. It shows the next appointments on the WordPress dashboard.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I export bookings to a spreadsheet?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Export booking or schedule data as CSV. On the CSV export tab in Settings, list any fields you want left out of each export.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Where can I see what happened and who changed it?', 'titan-zero-trades-front-desk' ),
                   __( 'Turn on Activity Logs under Addons. The log shows recorded activity. Only administrators can clear it, using the Clear log button.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can a staff member see messages sent to customers?', 'titan-zero-trades-front-desk' ),
                   __( 'Administrators choose who can open the messages list, using the Users with access to the messages list setting.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can customers send a request instead of booking straight away?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Each calendar can be set to Instant or Request. A request is saved as Pending, and it still takes up the places it asks for. The customer gets a Booking request received email that says it is not a confirmation. Choose the default in Settings, then Other settings, under Booking intake, or choose it in the Preset Setup wizard. You can change each calendar there too.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I approve or decline a request?', 'titan-zero-trades-front-desk' ),
                   __( 'Open the booking in Booking Orders and change each appointment’s status. Choose Approved to accept it, or a cancelled or rejected status to decline it. If only some appointments in a request are approved, the customer receives a Booking request status update listing each appointment’s status.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Do bookings I add by hand skip the captcha?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Bookings added from the admin area skip the captcha and the payment step, so you can add phone and walk-in bookings.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'rules',
        'title' => __( 'Booking rules and add-ons', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'How do discount codes work?', 'titan-zero-trades-front-desk' ),
                   __( 'Open Addons and turn on Coupons. In the codes box, enter one code per line in the form CODE,percent or fixed,value,expiry,max uses. Example: <code>SPRING10,percent,10,2030-12-31,0</code>. Leave the expiry blank for no end date, and use 0 for unlimited uses. The discount reduces the total in the booking and in confirmation emails. Each booking counts as one use.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can a discount be a fixed amount instead of a percentage?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Use fixed in the second field, for example <code>WELCOME20,fixed,20,,0</code>. A fixed discount never takes the total below zero.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Why does a discount code not work?', 'titan-zero-trades-front-desk' ),
                   __( 'Customers see a message if the code is not recognised, has expired, or has been used up. On your side, check the line format, that the expiry date is written as YYYY-MM-DD, and that the percentage is between 1 and 100. Lines that do not match the format are skipped, so check every code after you save.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I limit appointments per customer?', 'titan-zero-trades-front-desk' ),
                   __( 'Turn on Limit the number of appointments per user under Addons and set the maximum in its settings.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I require a password to book?', 'titan-zero-trades-front-desk' ),
                   __( 'Turn on Password for making bookings under Addons and set the password. Customers must enter it before they can submit a booking. Share it only with customers who should book. The password is stored as a one-way hash, so it cannot be read back. To change it, enter a new one.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What do reminders do?', 'titan-zero-trades-front-desk' ),
                   __( 'Reminder notifications send an email before the appointment. The timing is set in hours before the appointment, and you can write the subject and message yourself on the Addons page.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What do follow-ups do?', 'titan-zero-trades-front-desk' ),
                   __( 'FollowUp notifications send an email after the booking ends. The timing is set in hours after the end, with its own subject and message.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What does “Automatically cancel pending bookings” do?', 'titan-zero-trades-front-desk' ),
                   __( 'It cancels bookings that are still pending after the number of hours you set in its settings, so unconfirmed time slots become free again.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is Email Blacklist for?', 'titan-zero-trades-front-desk' ),
                   __( 'It refuses public bookings made with listed email addresses or domains. Enter one entry per line: a full address, a domain, or @domain. The list applies to the whole site, and you can set the message the customer sees when a booking is refused.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Why is availability cached?', 'titan-zero-trades-front-desk' ),
                   __( 'Cache Booking Availability keeps a copy of the availability display for busy sites. The cache is cleared when bookings change, so customers still see current times.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do customers choose single days?', 'titan-zero-trades-front-desk' ),
                   __( 'Turn on Single Days Selection Interface under Addons, then on each booking form turn on the alternative time slot selection interface. For that form you can set how many days and how many slots show first, the CSS class for the calendar, the date format, and the text for more slots.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Does the booking form have a captcha?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Each booking form has a Use Captcha Verification setting, which is on by default. The captcha needs the PHP GD image library on your server. If GD is not installed, the captcha is not shown. Bookings you add from the admin area never show it.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'messaging',
        'title' => __( 'Emails, messages and integrations', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'Do the SMS and WhatsApp buttons send messages?', 'titan-zero-trades-front-desk' ),
                   __( 'No. They add a button that lets visitors open a chat with you on SMS or WhatsApp. They do not send messages to customers in bulk.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do automatic email reports work?', 'titan-zero-trades-front-desk' ),
                   __( 'On the Email reports tab, turn on Send reports automatically. Choose how many days between reports, the hour to send them, the sender address and the recipient addresses. Set the subject and message, and choose plain text or HTML. The CSV file is attached automatically. The hour uses the server’s time.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Why are some messages still waiting?', 'titan-zero-trades-front-desk' ),
                   __( 'Queued messages are sent by WordPress scheduled tasks. They are sent when those tasks run, so on a quiet site they can wait for the next visitor. See Troubleshooting for how to run them on a timer.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How does the WebHook add-on work?', 'titan-zero-trades-front-desk' ),
                   __( 'It sends booking events to a secure URL you control. The events are booking.created and booking.status_update_attempted. Each request is a JSON body with the headers X-TZFD-Event (the event name), X-TZFD-Event-ID, X-TZFD-Timestamp, Idempotency-Key (the same value as the event ID) and X-TZFD-Signature. Failed deliveries are retried after 1, 5, 15 and 60 minutes.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I check the webhook signature?', 'titan-zero-trades-front-desk' ),
                   __( 'The signature header has the form <code>sha256=</code> followed by the HMAC-SHA256 of the raw request body, using the secret you set in WebHook settings. Compute it on the exact body you received, not on re-encoded JSON, and compare the two values. Accept the event only when they match.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is Missed-Call Text-Back?', 'titan-zero-trades-front-desk' ),
                   __( 'It accepts missed-call events from your phone system and texts the caller a booking link through the SMS address you set. Your phone system must send a secret of at least 32 characters in the X-TZFD-Secret header. Nothing is accepted until a secret is set.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I see bookings in my phone or calendar app?', 'titan-zero-trades-front-desk' ),
                   __( 'Turn on iCal Export under Addons. Each calendar gets a feed link. Subscribe to it in Google Calendar, Outlook or Apple Calendar, and the bookings will update there.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I send an email report for one form only?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Edit that form’s settings and use the report settings there. The Email reports tab in Settings covers all forms.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'ai',
        'title' => __( 'AI assistant, quotes and vertical packs', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'What can the AI assistant do?', 'titan-zero-trades-front-desk' ),
                   __( 'Place <code>[tzfd_ai_assistant]</code> on a page to add a chat assistant. It collects contact and job details, checks availability, gives a price range, and can pass the conversation to a person.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Are bookings from the assistant confirmed automatically?', 'titan-zero-trades-front-desk' ),
                   __( 'Not by default. The assistant saves each booking to the calendar as a pending request and marks it for the booking pipeline. Staff approve it under Front Desk, then Pipeline. A booking that fails a check is flagged there for staff to decide.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is a draft booking?', 'titan-zero-trades-front-desk' ),
                   __( 'A draft is a booking that has been captured but not confirmed. It is kept for review so nothing is lost.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I set up AI?', 'titan-zero-trades-front-desk' ),
                   __( 'Go to Settings, then AI. Choose an option under Use, paste its key and save. The key is encrypted before it is saved, and afterwards only its last four characters are shown. AI features appear once a choice is saved.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Which AI options are there?', 'titan-zero-trades-front-desk' ),
                   __( 'Groq, OpenRouter, Cerebras and Google AI Studio each give you a key from their website. Ollama runs on a server you control and needs no key. The options are listed at the top of Settings, then AI, and each one has a Get a key link.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Which AI providers are supported?', 'titan-zero-trades-front-desk' ),
                   __( 'Options: Groq, OpenRouter, Cerebras, Google AI Studio and Ollama on your own server. Your own key: OpenAI and Anthropic (Claude). Other: any server with an OpenAI-compatible API.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I use an AI model on my own server?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Install Ollama on a server you control. Then go to Settings, then AI, and choose Ollama (your own server). The address is filled in for you, http://localhost:11434/v1. If Ollama runs on another machine, choose Other and enter its address instead. No key is needed. The plugin does not install anything; it talks to the server you run over HTTP.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Where does customer information go when the AI runs?', 'titan-zero-trades-front-desk' ),
                   __( 'Prompts are sent to the provider you choose, or to your own local server. Check the plugin readme for the full list of outbound endpoints.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do AI quotes work?', 'titan-zero-trades-front-desk' ),
                   __( 'Describe the job on New quote and the AI drafts line items from your price catalog. Review the lines, then choose Save quote or Save and email to customer. The customer opens an approval link, where they can approve or decline it. Approved and declined quotes are recorded on the Quotes page. Turn on Quote PDF Export to attach a PDF to quote emails.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Where are quote prices, tax and terms set?', 'titan-zero-trades-front-desk' ),
                   __( 'The services and prices are on the Price catalog tab of Quotes. Tax, the default deposit, validity period and terms are on the Settings tab of Quotes. Both need an administrator. Quotes stay valid for 14 days unless you change the validity period.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Why did my quote not save?', 'titan-zero-trades-front-desk' ),
                   __( 'Every save shows a message at the top of the Quotes page, so read it first. A quote needs a customer name, and an emailed quote needs at least one line item. Approved quotes cannot be changed, so Duplicate them to make a new draft.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I copy, delete or resend a quote?', 'titan-zero-trades-front-desk' ),
                   __( 'Open the quote on the Quotes list. Duplicate makes a new draft with the same customer, lines and terms. Delete removes a quote that has not been approved. Approved quotes are kept as a record and cannot be deleted. Send again emails the quote again to the customer’s email address.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What are vertical packs?', 'titan-zero-trades-front-desk' ),
                   __( 'Packs are ready-made presets for a trade, with usual services and reference answers for the assistant. Install one from Vertical Packs, then review the services and answers it adds and change any you do not want. You can also upload your own pack as a JSON file of up to 512 KB.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is the difference between Preset Setup and the AI page?', 'titan-zero-trades-front-desk' ),
                   __( 'Preset Setup builds a calendar from a trade preset, with services and answers. The AI page connects an AI provider and its key, so the assistant and quotes can run.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Why do I see a notice about the AI key or booking availability?', 'titan-zero-trades-front-desk' ),
                   __( 'Administrators see two reminders on Front Desk screens. The AI reminder stays until AI is set up under Settings, then AI. Any AI option or your own key both count. The availability reminder suggests turning on Cache Booking Availability under Addons. Choose Remind me in 30 days to hide either one for a month. Only administrators see them.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can customers send a photo to the AI assistant?', 'titan-zero-trades-front-desk' ),
                   __( 'The chat has an Attach a photo button. Photos must be PNG, JPEG or WebP images of up to 4 MB, and each one is checked to be a real image. The assistant cannot see the photo yet: its link is saved with the request, and the assistant asks the customer to describe the problem in words.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'access',
        'title' => __( 'Access and permissions', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'Who can open the Front Desk menu?', 'titan-zero-trades-front-desk' ),
                   __( 'Any logged-in user with the WordPress read capability can open it. Each person then sees only the calendars they have been given access to.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Who can change the settings?', 'titan-zero-trades-front-desk' ),
                   __( 'Dashboard, Calendar, Bookings, Services, Customers, Addons and Settings need the edit_pages capability, which Editors and Administrators have by default. Payments (recording), AI, Workers, Locations, Business and money, the Quotes price catalog and quote Settings, and the Settings tabs for Calendar display, AI, Status update emails, Scheduling Preview, Preset Setup and Vertical Packs need the manage_options capability, which only Administrators have. Quotes itself (New quote, saving, duplicating, deleting and sending) needs edit_pages, so Editors can prepare quotes.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Who can see the AI tab?', 'titan-zero-trades-front-desk' ),
                   __( 'Only Administrators. The AI tab holds keys, so it is restricted even for Editors.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I let a staff member manage one calendar?', 'titan-zero-trades-front-desk' ),
                   __( 'Open that calendar’s settings and turn on Allow selected users access also to the calendar settings. Then choose the users under Select users with access. Administrators always see every calendar.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'settings',
        'title' => __( 'Settings reference', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'What is on the Settings screen?', 'titan-zero-trades-front-desk' ),
                   __( 'Settings is split into tabs: Email reports, Troubleshooting, CSV export, Calendar display, Other settings, AI, Help, Status update emails, Scheduling Preview, Preset Setup and Vertical Packs. Each tab has its own save button, so save before you move to another tab.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What does Other settings control?', 'titan-zero-trades-front-desk' ),
                   __( 'It holds site-wide options. Turn on Block spam bots to add a hidden field that stops most spam bots from submitting the booking form. Admin language sets the language of the admin screens. Booking intake sets the default for calendars and whether each calendar is Instant or Request; only administrators can change it.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is the Troubleshooting tab for?', 'titan-zero-trades-front-desk' ),
                   __( 'It holds tools that check the bookings database table and apply any missing updates. Back up your database before you use them.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I change how the forms look?', 'titan-zero-trades-front-desk' ),
                   __( 'Use the Custom styles tab to add CSS. It applies to every booking form on the site. Clear any page cache after saving so visitors see the change.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What happens to my data if I uninstall?', 'titan-zero-trades-front-desk' ),
                   __( 'By default, your bookings and settings are kept. To delete all plugin data when the plugin is removed, add <code>define( \'TZFD_DELETE_DATA_ON_UNINSTALL\', true );</code> to wp-config.php before you uninstall. This cannot be undone.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Where are the add-on settings?', 'titan-zero-trades-front-desk' ),
                   __( 'On the Addons page. Each add-on has an on or off switch and its own settings block, with a Help link.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Which tabs can I reach from the Front Desk menu?', 'titan-zero-trades-front-desk' ),
                   __( 'Settings opens the settings tabs. Styles, Help, Status update emails, Scheduling Preview, Preset Setup and Vertical Packs are tabs there too, and open their own pages with the same tab bar. Quotes has its own tabs: the quote list, with filters for Draft, Sent, Accepted and other statuses, New quote, the price catalog and quote settings.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I answer a personal data request?', 'titan-zero-trades-front-desk' ),
                   __( 'Front Desk adds its booking records to the WordPress personal data export, matched by the exact email address. Use Tools, then Export Personal Data in WordPress. This version does not delete personal data automatically, so remove a customer’s records yourself if they ask.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'troubleshooting',
        'title' => __( 'Troubleshooting', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'The form shows, but no times appear.', 'titan-zero-trades-front-desk' ),
                   __( 'Check that the calendar has working hours on those dates, that the dates are not blocked under Block Times, and that the slots are not already full.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Booking emails do not arrive.', 'titan-zero-trades-front-desk' ),
                   __( 'Check that your site can send email. An SMTP plugin is the usual fix. Then check the sender address in the calendar’s email settings, and look in the customer’s spam folder.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Reminders, follow-ups or reports are late.', 'titan-zero-trades-front-desk' ),
                   __( 'WordPress runs scheduled tasks when someone visits the site. On a quiet site, set up a real server cron job that calls wp-cron.php regularly, for example every five minutes, and turn off WordPress’s own visit-triggered cron if your host supports it.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Times look wrong in emails or on the schedule.', 'titan-zero-trades-front-desk' ),
                   __( 'The site uses the timezone set in WordPress under Settings, then General. Set it there first. The email report hour uses the server’s time, so check that too.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'The shortcode shows as plain text.', 'titan-zero-trades-front-desk' ),
                   __( 'Copy the shortcode from the calendar’s Publish section again, so the calendar number is right, and put it in a shortcode block or a shortcode field.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'My custom styles do not show.', 'titan-zero-trades-front-desk' ),
                   __( 'Check that the styles were saved, clear your caching plugin’s cache, and test in a private browser window.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'A staff member sees the menu but no calendars.', 'titan-zero-trades-front-desk' ),
                   __( 'That is expected until the calendar’s user access setting includes them under Select users with access. Administrators always see every calendar.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'A discount code is not applied.', 'titan-zero-trades-front-desk' ),
                   __( 'See Why does a discount code not work? in Booking rules and add-ons. Also check that the code is on the calendar you are booking, and that the add-on is turned on.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'The Missed-Call add-on rejects every request.', 'titan-zero-trades-front-desk' ),
                   __( 'Check three things: the secret is at least 32 characters, your phone system sends it in the X-TZFD-Secret header with exactly the same value, and the request is sent to the address ending in /wp-json/tzfd/v1/missed-call.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'The webhook signature does not match.', 'titan-zero-trades-front-desk' ),
                   __( 'Use the raw request body, with no reformatting, and the same secret saved in WebHook settings. Remember the header value starts with sha256= before the hex signature.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'The AI assistant gives errors or no answers.', 'titan-zero-trades-front-desk' ),
                   __( 'For an AI option or your own key, check the key and the choice under Settings, then AI. For Ollama, check that it is running and that your site can reach its address.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'AI bookings do not appear in Booking Orders.', 'titan-zero-trades-front-desk' ),
                   __( 'The assistant saves each booking to the calendar as a pending request. Open Front Desk, then Pipeline, to see its state and the reason it was flagged, if any.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'A quote link says it has expired.', 'titan-zero-trades-front-desk' ),
                   __( 'Quotes stay valid for the validity period, 14 days by default. Check the valid-until date on the quote on the Quotes page, then send a new quote with a later date.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'The page still shows old form content after a change.', 'titan-zero-trades-front-desk' ),
                   __( 'Clear your caching plugin’s cache and any CDN cache, then test in a private browser window.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'The captcha does not appear on my form.', 'titan-zero-trades-front-desk' ),
                   __( 'Check that Use Captcha Verification is on for that form, and that the server has the PHP GD extension. Without GD, the captcha is hidden.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'A request still shows as Pending.', 'titan-zero-trades-front-desk' ),
                   __( 'That is expected until you change each appointment’s status in Booking Orders.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can a customer cancel or change their own booking?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes, if the calendar sends a confirmation to the customer. Set “Send confirmation/thank you message to user?” to Yes on the calendar. The customer’s email then links to a page where they can cancel the booking. A single-appointment booking can also be rescheduled from a link in that email. Both pages use a secure token that is sent only to the customer, so nobody else can change the booking. Check the result in Booking Orders.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'developers',
        'title' => __( 'Developers', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'Which shortcodes are available?', 'titan-zero-trades-front-desk' ),
                   __( '<code>[TZFD_FRONT_DESK id="N"]</code> shows a calendar’s booking form. <code>[tzfd_ai_assistant]</code> shows the AI chat. <code>[tzfd_quote]</code>, <code>[tzfd_quote_button]</code> and <code>[tzfd_quote_print]</code> handle quotes. <code>[TZFD_FRONT_DESK_LIST]</code> lists bookings for the calendar and date range you set.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Who can see the booking list shortcode?', 'titan-zero-trades-front-desk' ),
                   __( 'Only administrators. Visitors see nothing where <code>[TZFD_FRONT_DESK_LIST]</code> is placed, because the list shows customer details. You can still set the fields attribute to leave out anything you do not want shown.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I create bookings from the AI assistant?', 'titan-zero-trades-front-desk' ),
                   __( 'The assistant creates each booking through the pipeline, so nothing needs hooking for a standard setup. To save bookings in your own way, hook the filter <code>tzfd_ai_create_booking</code>. It receives <code>null</code> and an array with <code>formid</code> and <code>draft</code>. Return <code>array( "ok" =&gt; true, "booking_id" =&gt; N, "status" =&gt; "pending_confirmation" )</code> once the booking is saved.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I change the total a customer pays?', 'titan-zero-trades-front-desk' ),
                   __( 'Use the filter <code>tzfd_invoice_amount</code>. It receives the amount and an array with <code>formid</code> and <code>itemnumber</code>, and returns the new amount.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I clear the availability cache from code?', 'titan-zero-trades-front-desk' ),
                   __( 'Run the action <code>tzfd_cache_clean</code> with the calendar’s form ID as its only argument.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What REST endpoints does the plugin expose?', 'titan-zero-trades-front-desk' ),
                   __( '<code>/wp-json/tzfd/v1/missed-call</code> and <code>/wp-json/tzfd/v1/webhook/{slug}</code> receive events from other systems and check a secret. <code>/wp-json/tzfd/v1/ai/message</code> and <code>/ai/photo</code> serve the AI assistant. <code>/wp-json/tzfd/v1/quotes/approve</code>, <code>/quotes/decline</code> and <code>/quotes/pdf/{token}</code> serve quote links. Resource routes under <code>/wp-json/tzfd/v1/</code> need an administrator login.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What do webhook events contain?', 'titan-zero-trades-front-desk' ),
                   __( 'Each event is a JSON body with a schema version of 1. Check the signature before you trust a request, and treat a repeated Event-ID as a duplicate you have already handled.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Which scheduled tasks does the plugin use?', 'titan-zero-trades-front-desk' ),
                   __( 'Reminders, follow-ups, automatic cancellation, webhook delivery and the message outbox each run as a WordPress scheduled event. You can list them with <code>wp cron event list</code> if you have WP-CLI. Deactivating the plugin removes them.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I set the intake mode from code?', 'titan-zero-trades-front-desk' ),
                   __( 'Settings is the easier route. From code, call <code>TZFD_Intake_Mode::set_for_form( $form_id, \'request\' )</code>. It must run as a logged-in administrator. It returns true, or an error if the form does not exist or the mode is not valid. Use <code>instant</code> to switch back. The setting is stored per form in the option <code>titan_zero_front_desk_intake_mode_{id}</code>.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How can another system read a booking’s status?', 'titan-zero-trades-front-desk' ),
                   __( 'Send a GET request to <code>/wp-json/tzfd/v1/bookings/{id}</code>. It needs an administrator login and returns the status only. Customer and appointment details are left out on purpose.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do payment extensions report results?', 'titan-zero-trades-front-desk' ),
                   __( 'A trusted payment extension can verify its provider’s result and then fire the action <code>titan_zero_front_desk_payment_update_attempted</code>. The plugin also fires <code>titan_zero_front_desk_booking_event</code> with the event name <code>booking.payment_update_attempted</code>. The plugin does not verify, store or change payments itself.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Which actions send WebHook events?', 'titan-zero-trades-front-desk' ),
                   __( 'A new booking fires <code>tzfd_process_data</code>, which sends booking.created. A status change fires <code>tzfd_update_status</code>, which sends booking.status_update_attempted.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'glossary',
        'title' => __( 'Glossary', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'What is an add-on?', 'titan-zero-trades-front-desk' ),
                   __( 'An optional feature, such as Coupons or Reminder notifications, that you switch on from the Addons page. Each one has its own settings.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is a service?', 'titan-zero-trades-front-desk' ),
                   __( 'Something a customer can book, such as a 60-minute consultation or a site visit. Each service has a duration, and it belongs to a calendar.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is a shortcode?', 'titan-zero-trades-front-desk' ),
                   __( 'A short code in square brackets, such as <code>[TZFD_FRONT_DESK id="5"]</code>, that WordPress replaces with a feature when the page is shown.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is a vertical pack?', 'titan-zero-trades-front-desk' ),
                   __( 'A preset for one trade or business type. It contains usual services and reference answers that you can install and then adjust.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is a secret?', 'titan-zero-trades-front-desk' ),
                   __( 'A long password shared between your site and another system, used to check that a request really comes from that system. Use at least 32 random characters and do not share it in public.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is capacity?', 'titan-zero-trades-front-desk' ),
                   __( 'The number of places a service or time slot can hold, such as the seats in a class. Customers can book more than one place, up to the capacity, using the Quantity field.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is request mode?', 'titan-zero-trades-front-desk' ),
                   __( 'A form setting where bookings arrive as requests for review, not as confirmed bookings. Requests show as Pending until you approve them.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),
);

$tzfd_help_guides = array(
    array( __( 'Before you go live', 'titan-zero-trades-front-desk' ), array(
        __( 'Set your timezone in WordPress under Settings, then General.', 'titan-zero-trades-front-desk' ),
        __( 'Create the calendar, its services and working hours, and block any closed dates.', 'titan-zero-trades-front-desk' ),
        __( 'Publish the form on a page and copy its shortcode.', 'titan-zero-trades-front-desk' ),
        __( 'Make a test booking in a private browser window, then check Booking Orders and the confirmation email.', 'titan-zero-trades-front-desk' ),
        __( 'Turn on the add-ons you need: reminders, follow-ups, coupons and the email blacklist.', 'titan-zero-trades-front-desk' ),
        __( 'Set up a real cron job for scheduled tasks, if your site is quiet.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Create your first calendar', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Calendar, then Manage calendars, then New Calendar.', 'titan-zero-trades-front-desk' ),
        __( 'Give the calendar a name and set its services, durations and working hours.', 'titan-zero-trades-front-desk' ),
        __( 'Use Block Times for any closed dates.', 'titan-zero-trades-front-desk' ),
        __( 'Click Publish and copy the shortcode into a page.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Add a discount code', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Addons and turn on Coupons.', 'titan-zero-trades-front-desk' ),
        __( 'In the codes box, add one line per code: CODE,percent or fixed,value,expiry,max uses.', 'titan-zero-trades-front-desk' ),
        __( 'Save, then book with the code to check the discounted total.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Block a holiday or a day off', 'titan-zero-trades-front-desk' ), array(
        __( 'Open the calendar and choose Block Times.', 'titan-zero-trades-front-desk' ),
        __( 'Pick the date or the time range you want closed.', 'titan-zero-trades-front-desk' ),
        __( 'Check the booking form in a private window to confirm customers cannot pick that day.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Turn on automatic email reports', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Settings, then Email reports.', 'titan-zero-trades-front-desk' ),
        __( 'Turn on Send reports automatically and choose how often and at what hour.', 'titan-zero-trades-front-desk' ),
        __( 'Enter the sender address and the recipient addresses.', 'titan-zero-trades-front-desk' ),
        __( 'Save the report settings. The report is sent at the hour you chose.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Set up the AI assistant', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Settings, then AI.', 'titan-zero-trades-front-desk' ),
        __( 'Choose an AI option or your own provider, paste the key and save.', 'titan-zero-trades-front-desk' ),
        __( 'Add the shortcode [tzfd_ai_assistant] to a page.', 'titan-zero-trades-front-desk' ),
        __( 'Test the chat, then decide how bookings from it will be created (see Developers).', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Connect a local AI server', 'titan-zero-trades-front-desk' ), array(
        __( 'Install Ollama on a server you control, and start it.', 'titan-zero-trades-front-desk' ),
        __( 'Go to Settings, then AI, and choose Ollama (your own server). The address is filled in for you.', 'titan-zero-trades-front-desk' ),
        __( 'Leave the key blank for a default install.', 'titan-zero-trades-front-desk' ),
        __( 'Save, then test the assistant from a private browser window.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Install an industry pack', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Vertical Packs and find your trade.', 'titan-zero-trades-front-desk' ),
        __( 'Install it, then review the services and answers it adds.', 'titan-zero-trades-front-desk' ),
        __( 'Change or remove anything that does not fit your business.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Send a quote for a job', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Quotes, then New quote. Pick a booking to fill in the customer and service, or describe the job for an AI draft.', 'titan-zero-trades-front-desk' ),
        __( 'Review the line items, tax and total, then choose Save quote.', 'titan-zero-trades-front-desk' ),
        __( 'Choose Save and email to customer to send the approval link.', 'titan-zero-trades-front-desk' ),
        __( 'Check the quote’s status on the Quotes page once the customer responds.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Verify webhook events on your side', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Addons, then WebHook, and enter your receiving URL and a secret of at least 32 characters.', 'titan-zero-trades-front-desk' ),
        __( 'On your side, compute HMAC-SHA256 of the raw body with the same secret.', 'titan-zero-trades-front-desk' ),
        __( 'Compare it with the value after sha256= in X-TZFD-Signature.', 'titan-zero-trades-front-desk' ),
        __( 'Accept the event only when the signature matches, and ignore repeated event IDs.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Block spam bots and a known spammer', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Settings, then Other settings, and turn on Block spam bots.', 'titan-zero-trades-front-desk' ),
        __( 'Go to Addons, then Email Blacklist.', 'titan-zero-trades-front-desk' ),
        __( 'Enter the address, domain or @domain on its own line, then save.', 'titan-zero-trades-front-desk' ),
    ) ),    array( __( 'Place the booking form with Elementor', 'titan-zero-trades-front-desk' ), array(
        __( 'Edit the page in Elementor.', 'titan-zero-trades-front-desk' ),
        __( 'Add the Titan Zero Trades: Front Desk widget.', 'titan-zero-trades-front-desk' ),
        __( 'Choose your booking form under Select Form.', 'titan-zero-trades-front-desk' ),
        __( 'Publish the page, then check the form in a private browser window.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Approve or decline a booking request', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Booking Orders and open the request.', 'titan-zero-trades-front-desk' ),
        __( 'Change each appointment’s status: Approved to accept it, or a cancelled or rejected status to decline it.', 'titan-zero-trades-front-desk' ),
        __( 'If only some appointments are approved, the customer receives a status update listing each one.', 'titan-zero-trades-front-desk' ),
    ) ),    array( __( 'Switch a calendar to request mode', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Settings, then Other settings.', 'titan-zero-trades-front-desk' ),
        __( 'Under Booking intake, choose Request for the calendar, or change the default for calendars that have no setting of their own.', 'titan-zero-trades-front-desk' ),
        __( 'Click Save changes.', 'titan-zero-trades-front-desk' ),
        __( 'Make a test booking in a private browser window. It should show as a request, not a confirmed booking.', 'titan-zero-trades-front-desk' ),
    ) ),
);

$tzfd_help_addons = array(
    array( 'AI Booking Assistant (Field Service)', __( 'Chat assistant on your site that collects job details and checks availability.', 'titan-zero-trades-front-desk' ), 'Settings → AI; Addons' ),
    array( 'AI-Assisted Quotes', __( 'Drafts quotes from a job description, with a customer approval link.', 'titan-zero-trades-front-desk' ), 'Quotes' ),
    array( 'Quote PDF Export', __( 'Attaches a PDF of the quote to quote emails.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Activity Logs', __( 'Records activity; administrators can clear the log.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Automatically cancel pending bookings', __( 'Cancels bookings still pending after the hours you set.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Cache Booking Availability', __( 'Keeps a copy of availability for busy sites; cleared when bookings change.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Coupons', __( 'Discount codes with expiry dates and use limits.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Dashboard Widget: Upcoming appointments', __( 'Shows upcoming appointments on the WordPress dashboard.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Email Blacklist', __( 'Refuses public bookings from listed addresses or domains.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'FollowUp notifications for bookings', __( 'Sends an email a set number of hours after the booking ends.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'iCal Export Addon', __( 'Provides a calendar feed link for each calendar.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Limit the number of appointments per user', __( 'Caps how many appointments one customer can book.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Missed-Call Text-Back', __( 'Accepts missed-call events from your phone system, protected by a secret.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Password for making bookings', __( 'Customers must enter a password before booking.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Reminder notifications for bookings', __( 'Sends an email a set number of hours before the appointment.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Single Days Selection Interface', __( 'Offers an alternative time slot picker on each booking form, with its own display settings.', 'titan-zero-trades-front-desk' ), 'Addons, then each booking form' ),
    array( 'SMS open chat button', __( 'Button that opens an SMS chat with you.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'WhatsApp open chat button', __( 'Button that opens a WhatsApp chat with you.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'WebHook', __( 'Sends signed booking events to your own system.', 'titan-zero-trades-front-desk' ), 'Addons' ),
    array( 'Vertical Packs (industry presets)', __( 'Trade presets with services, prices and reference answers.', 'titan-zero-trades-front-desk' ), 'Vertical Packs' ),
    array( 'AI Setup Wizard', __( 'Connects an AI provider or a local model.', 'titan-zero-trades-front-desk' ), 'AI' ),
    array( 'Preset Setup', __( 'Starts a calendar from a trade preset.', 'titan-zero-trades-front-desk' ), 'Settings, then Preset Setup' ),
);
/* Page reference: every Front Desk page, what it is for and where it is found. */
$tzfd_help_pages = array(
    array(
        'title' => __( 'Calendars', 'titan-zero-trades-front-desk' ),
        'rows'  => array(
            array( __( 'Calendars List', 'titan-zero-trades-front-desk' ), __( 'Every calendar you have. Create, edit, clone or delete a calendar, and open its booking form.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Form Settings', 'titan-zero-trades-front-desk' ), __( 'The services, booking form fields, rules, button labels, confirmation page and emails for one calendar.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Booking Orders', 'titan-zero-trades-front-desk' ), __( 'The bookings for one calendar. Filter them, change their status, delete them or export them to CSV.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Schedule', 'titan-zero-trades-front-desk' ), __( 'The bookings for one calendar as a calendar view. Switch to the list view with the button on the page.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Stats', 'titan-zero-trades-front-desk' ), __( 'Booking totals for one calendar.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Add Booking', 'titan-zero-trades-front-desk' ), __( 'Enter a booking by hand, for example for a phone or walk-in customer.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Block Times', 'titan-zero-trades-front-desk' ), __( 'Stop customers booking chosen dates or times, for example holidays or staff leave.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Publish', 'titan-zero-trades-front-desk' ), __( 'The shortcode for one calendar, for example [TZFD_FRONT_DESK id="5"]. Paste it into a page or post.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),
    array(
        'title' => __( 'Front Desk menu', 'titan-zero-trades-front-desk' ),
        'rows'  => array(
            array( __( 'Dashboard', 'titan-zero-trades-front-desk' ), __( 'Set up Front Desk (Start setup opens the setup wizard), at-a-glance counts, and the next appointments.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Calendar', 'titan-zero-trades-front-desk' ), __( 'Every booking from every calendar, in Day, Week or Month view. Filter to one calendar, and open a booking to go to its Booking Orders. Manage calendars opens the Calendars List, where you create, edit, clone or delete a calendar.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Customers', 'titan-zero-trades-front-desk' ), __( 'Your customer list. Each customer has a name, company, email, phone, preferred contact, street address, city, region, postcode, country, how they found you and notes. Their quotes and bookings are shown on their page.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Payments', 'titan-zero-trades-front-desk' ), __( 'Record money received by PayPal, bank transfer or cash, and see totals for today, this month and in total. Open it from the Total payments card on the Dashboard. The payment methods and the instructions customers see are set under Settings.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Bookings', 'titan-zero-trades-front-desk' ), __( 'Upcoming bookings from every calendar, with a button to open each calendar’s Booking Orders.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Quotes', 'titan-zero-trades-front-desk' ), __( 'Every quote, the New quote form, the quote price catalog and quote settings, each on its own tab.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Services', 'titan-zero-trades-front-desk' ), __( 'The services each calendar offers, with address, location, price, duration and capacity. Use Address to set where a service is given, and Edit calendar to change its other settings.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Workers & locations', 'titan-zero-trades-front-desk' ), __( 'One page with three tabs. Team lists the workers, with their hours, days off and contact details, and the availability preview. Locations lists the places where they are based, with address, phone, timezone and postcodes covered. Equipment & rooms lists the resources bookings use. None of these change existing bookings.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'AI', 'titan-zero-trades-front-desk' ), __( 'The AI setup wizard, which connects an AI provider or a local AI server.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Addons', 'titan-zero-trades-front-desk' ), __( 'Switch add-ons on or off, and set each add-on’s options.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Settings', 'titan-zero-trades-front-desk' ), __( 'The settings tabs: Email reports, Troubleshooting, CSV export, Calendar display, Other settings, AI, Help, Status update emails, Scheduling Preview, Preset Setup and Vertical Packs.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),
    array(
        'title' => __( 'Setup', 'titan-zero-trades-front-desk' ),
        'rows'  => array(
            array( __( 'Preset Setup', 'titan-zero-trades-front-desk' ), __( 'Starts a calendar from a trade preset: its services, answers and example prices. It also asks whether bookings are instant or held as requests. Review every item before anything is saved.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'AI', 'titan-zero-trades-front-desk' ), __( 'Connects an AI provider or a local AI server for the booking assistant. Shown only after an AI key is saved.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Vertical Packs', 'titan-zero-trades-front-desk' ), __( 'The industry packs in this build. Install one to add its services and answers.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),
    array(
        'title' => __( 'Quotes', 'titan-zero-trades-front-desk' ),
        'rows'  => array(
            array( __( 'Quotes', 'titan-zero-trades-front-desk' ), __( 'Every quote, its status, and the customer approval page. Found under Quotes.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Quote price catalog', 'titan-zero-trades-front-desk' ), __( 'The services and prices that quotes are built from.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'New quote', 'titan-zero-trades-front-desk' ), __( 'Build a quote from a booking or a job description, then review and save or email it. Saving shows a message at the top of the page.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Duplicate, delete and send again', 'titan-zero-trades-front-desk' ), __( 'Each quote can be copied as a new draft, deleted unless it is approved, or emailed again. Approved quotes are kept as a record.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Quote settings', 'titan-zero-trades-front-desk' ), __( 'Tax, deposits, validity period and terms.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),
    array(
        'title' => __( 'WordPress dashboard', 'titan-zero-trades-front-desk' ),
        'rows'  => array(
            array( __( 'Upcoming appointments widget', 'titan-zero-trades-front-desk' ), __( 'Shows the next bookings on the WordPress Dashboard. Turn it on under Addons, in Dashboard Widget.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),
);
?>

<div class="wrap tzfd-help-wrap">
    <?php TZFD_Brand::logo_img(); ?>
    <?php TZFD_Brand::feature_cards(); TZFD_Brand::workflow_img(); ?>
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Help and FAQ', 'titan-zero-trades-front-desk' ); ?></h1>
    <hr class="wp-header-end">

    <div class="notice notice-info inline tzfd-help-notice">
        <p><?php esc_html_e( 'Search below first. If you still need help, contact support.', 'titan-zero-trades-front-desk' ); ?></p>
        <p><a href="<?php echo esc_url( $tzfd_help_support_url ); ?>" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e( 'Contact support', 'titan-zero-trades-front-desk' ); ?></a></p>
    </div>

    <div class="postbox tzfd-help-search">
        <label for="tzfd-help-search-input"><?php esc_html_e( 'Search the help', 'titan-zero-trades-front-desk' ); ?></label>
        <input type="search" id="tzfd-help-search-input" class="regular-text" placeholder="<?php esc_attr_e( 'Try: coupon, email, reminder, shortcode', 'titan-zero-trades-front-desk' ); ?>">
        <p class="description"><?php esc_html_e( 'Use one or two keywords. Results from every section are shown together.', 'titan-zero-trades-front-desk' ); ?></p>
        <p id="tzfd-help-empty" class="tzfd-help-empty tzfd-help-hidden"><?php esc_html_e( 'No matches. Try a shorter word, or browse the sections below.', 'titan-zero-trades-front-desk' ); ?></p>
    </div>

    <nav class="tzfd-help-toc" aria-label="<?php esc_attr_e( 'Sections', 'titan-zero-trades-front-desk' ); ?>">
        <ul>
            <li><a href="#tzfd-help-guides"><?php esc_html_e( 'Step-by-step guides', 'titan-zero-trades-front-desk' ); ?></a></li>
            <?php foreach ( $tzfd_help_sections as $tzfd_section ) : ?>
                <li><a href="#tzfd-help-<?php echo esc_attr( $tzfd_section['id'] ); ?>"><?php echo esc_html( $tzfd_section['title'] ); ?></a></li>
            <?php endforeach; ?>
            <li><a href="#tzfd-help-pages"><?php esc_html_e( 'Page reference', 'titan-zero-trades-front-desk' ); ?></a></li>
            <li><a href="#tzfd-help-addons"><?php esc_html_e( 'Add-on reference', 'titan-zero-trades-front-desk' ); ?></a></li>
        </ul>
    </nav>

    <div id="tzfd-help-content">

        <h2 id="tzfd-help-guides"><?php esc_html_e( 'Step-by-step guides', 'titan-zero-trades-front-desk' ); ?></h2>
        <div class="tzfd-help-section">
            <?php foreach ( $tzfd_help_guides as $tzfd_guide ) : ?>
                <details class="tzfd-help-item">
                    <summary><?php echo esc_html( $tzfd_guide[0] ); ?></summary>
                    <ol>
                        <?php foreach ( $tzfd_guide[1] as $tzfd_step ) : ?>
                            <li><?php echo esc_html( $tzfd_step ); ?></li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            <?php endforeach; ?>
        </div>

        <?php foreach ( $tzfd_help_sections as $tzfd_section ) : ?>
            <h2 id="tzfd-help-<?php echo esc_attr( $tzfd_section['id'] ); ?>"><?php echo esc_html( $tzfd_section['title'] ); ?></h2>
            <div class="tzfd-help-section">
                <?php foreach ( $tzfd_section['items'] as $tzfd_item ) : ?>
                    <details class="tzfd-help-item">
                        <summary><?php echo esc_html( $tzfd_item[0] ); ?></summary>
                        <div class="tzfd-help-answer"><?php echo wp_kses( $tzfd_item[1], array( 'code' => array(), 'strong' => array(), 'em' => array() ) ); ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <h2 id="tzfd-help-pages"><?php esc_html_e( 'Page reference', 'titan-zero-trades-front-desk' ); ?></h2>
        <div class="tzfd-help-section">
            <p><?php esc_html_e( 'Each page of Titan Zero Trades: Front Desk, what it is for, and where to find it. The Front Desk menu lists the main pages; the Settings tabs hold the rest.', 'titan-zero-trades-front-desk' ); ?></p>
            <?php foreach ( $tzfd_help_pages as $tzfd_page_area ) : ?>
                <h3><?php echo esc_html( $tzfd_page_area['title'] ); ?></h3>
                <table class="widefat striped tzfd-help-table">
                    <thead>
                        <tr>
                            <th style="width:260px;"><?php esc_html_e( 'Page', 'titan-zero-trades-front-desk' ); ?></th>
                            <th><?php esc_html_e( 'What it is for', 'titan-zero-trades-front-desk' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $tzfd_page_area['rows'] as $tzfd_page_row ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $tzfd_page_row[0] ); ?></strong></td>
                            <td><?php echo esc_html( $tzfd_page_row[1] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        </div>

        <h2 id="tzfd-help-addons"><?php esc_html_e( 'Add-on reference', 'titan-zero-trades-front-desk' ); ?></h2>
        <div class="tzfd-help-section">
            <table class="widefat striped tzfd-help-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Add-on', 'titan-zero-trades-front-desk' ); ?></th>
                        <th><?php esc_html_e( 'What it does', 'titan-zero-trades-front-desk' ); ?></th>
                        <th><?php esc_html_e( 'Where to set it up', 'titan-zero-trades-front-desk' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $tzfd_help_addons as $tzfd_addon ) : ?>
                        <tr class="tzfd-help-item">
                            <td><?php echo esc_html( $tzfd_addon[0] ); ?></td>
                            <td><?php echo esc_html( $tzfd_addon[1] ); ?></td>
                            <td><?php echo esc_html( $tzfd_addon[2] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tzfd-help-footer">
        <a href="<?php echo esc_url( $tzfd_help_support_url ); ?>" target="_blank" rel="noopener" class="button button-secondary"><?php esc_html_e( 'Help centre', 'titan-zero-trades-front-desk' ); ?></a>
        <a href="<?php echo esc_url( $tzfd_help_support_url ); ?>" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e( 'Contact support', 'titan-zero-trades-front-desk' ); ?></a>
        <span class="description"><?php esc_html_e( 'The readme.txt file in the plugin folder has installation notes and the changelog.', 'titan-zero-trades-front-desk' ); ?></span>
    </div>
</div>




