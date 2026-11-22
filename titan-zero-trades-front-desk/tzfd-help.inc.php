<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if ( ! is_admin() ) { echo esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' ); exit; }

// Support goes to the titanzero.io support page for this plugin.
$tzfd_help_support_url = 'mailto:support@titanzero.io';
wp_enqueue_script( 'tzfd-adminb-help', plugins_url( 'js/inline/adminb-help.js', __FILE__ ), array(), '2.0.1', true );

$tzfd_help_sections = array(

    array(
        'id'    => 'start',
        'title' => __( 'Getting started', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'What does Scheduling & Enquiries Manager do?', 'titan-zero-trades-front-desk' ),
                   __( 'It takes appointments and bookings for services that have set schedules, durations and capacity. You define calendars, services and working hours, customers book through a form on your site, and you manage every booking from the WordPress admin.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I set it up?', 'titan-zero-trades-front-desk' ),
                   __( 'On Dashboard, choose Start setup. The setup wizard starts a calendar from a vertical preset with ready-made services and answers, and asks whether bookings are instant or held as requests. You can also reopen it from Settings, then Preset Setup, You can skip any of these and configure everything by hand.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Do I need to write any code?', 'titan-zero-trades-front-desk' ),
                   __( 'No. Settings, Addons and the setup wizards cover everything for a normal site. The Developers section describes optional hooks for people who extend the plugin.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I test it without taking real bookings?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Open your booking page in a private browser window, make a test booking, then check that it appears in Booking Orders and that the confirmation email arrives. Use Block Times to close the test date afterwards if needed.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Where is the Front Desk menu?', 'titan-zero-trades-front-desk' ),
                   __( 'Front Desk is in the main WordPress admin menu. Its items are Dashboard, Calendar, Bookings, Services, Workers & locations, Add-ons and Settings. Settings holds the other tools as tabs: Email reports, Troubleshooting, CSV export, Calendar display, Other settings, Help, Status update emails, Scheduling Preview, Preset Setup and Vertical Packs. Booking Orders, Schedule, Stats and the other calendar tools open from each calendar.', 'titan-zero-trades-front-desk' ) ),
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
                   __( 'It is a read-only planner for administrators under Front Desk. It previews daily, weekly or monthly visits against the calendar’s rules, recorded occupancy, capacity and service buffers. It does not create bookings or send messages.', 'titan-zero-trades-front-desk' ) ),
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
                   __( 'Activity Logs shows recorded activity. Switch it on from the Add-ons screen. Only administrators can clear the log, using the Clear log button.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can a staff member see messages sent to customers?', 'titan-zero-trades-front-desk' ),
                   __( 'Administrators choose who can open the messages list, using the Users with access to the messages list setting.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can customers send a request instead of booking straight away?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Each calendar can be set to Instant or Request. A request is saved as Pending, and it still takes up the places it asks for. The customer gets a Booking request received email that says it is not a confirmation. Choose the default in Settings, then Other settings, under Booking intake, or choose it in the Preset Setup wizard. You can change each calendar there too.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I approve or decline a request?', 'titan-zero-trades-front-desk' ),
                   __( 'Open the booking in Booking Orders and change each appointment’s status. Choose Approved to accept it, or a cancelled or rejected status to decline it. If only some appointments in a request are approved, the customer receives a Booking request status update listing each appointment’s status.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Do bookings I add by hand skip the captcha?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Bookings added from the admin area skip the captcha, so you can add phone and walk-in bookings.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'rules',
        'title' => __( 'Booking rules and add-ons', 'titan-zero-trades-front-desk' ),
        'items' => array(
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
                   __( 'It accepts missed-call events from your phone system and texts the caller a booking link through the SMS address you set. The plugin generates a secret when you turn this on. Your phone system signs each request with it. Nothing is accepted until a secret is set.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I see bookings in my phone or calendar app?', 'titan-zero-trades-front-desk' ),
                   __( 'Turn on iCal Export under Addons. Each calendar gets a feed link. Subscribe to it in Google Calendar, Outlook or Apple Calendar, and the bookings will update there.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I send an email report for one form only?', 'titan-zero-trades-front-desk' ),
                   __( 'Yes. Edit that form’s settings and use the report settings there. The Email reports tab in Settings covers all forms.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'packs',
        'title' => __( 'Vertical packs', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'What are vertical packs?', 'titan-zero-trades-front-desk' ),
                   __( 'Packs are ready-made presets for a vertical, with usual services and reference answers. Install one from Vertical Packs, then review the services and answers it adds and change any you do not want. You can also upload your own pack as a JSON file of up to 512 KB.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Why do I see a notice about booking availability?', 'titan-zero-trades-front-desk' ),
                   __( 'Administrators see reminders on Front Desk screens. The availability reminder suggests turning on Cache Booking Availability under Add-ons. Choose Remind me in 30 days to hide a reminder for a month. Only administrators see them.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'access',
        'title' => __( 'Access and permissions', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'Who can open the Front Desk menu?', 'titan-zero-trades-front-desk' ),
                   __( 'Administrators and the Titan Zero Worker and Titan Zero Manager roles can open it. Editors cannot. Each person then sees only the calendars they have been given access to.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Who can change the settings?', 'titan-zero-trades-front-desk' ),
                   __( 'Front Desk screens are for Administrators and for the Titan Zero Worker and Titan Zero Manager WordPress roles. Editors and other roles have no Front Desk access. Which screens each role may use is set in Scheduling & Enquiries Manager.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I let a staff member manage one calendar?', 'titan-zero-trades-front-desk' ),
                   __( 'Open that calendar’s settings and turn on Allow selected users access also to the calendar settings. Then choose the users under Select users with access. Administrators always see every calendar.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'settings',
        'title' => __( 'Settings reference', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'What is on the Settings screen?', 'titan-zero-trades-front-desk' ),
                   __( 'Settings is split into tabs: Email reports, Troubleshooting, CSV export, Calendar display, Other settings, Help, Status update emails, Scheduling Preview, Preset Setup and Vertical Packs. Each tab has its own save button, so save before you move to another tab.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What does Other settings control?', 'titan-zero-trades-front-desk' ),
                   __( 'It holds site-wide options. Turn on Block spam bots to add a hidden field that stops most spam bots from submitting the booking form. Admin language sets the language of the admin screens. Booking intake sets the default for calendars and whether each calendar is Instant or Request; only administrators can change it.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is the Troubleshooting tab for?', 'titan-zero-trades-front-desk' ),
                   __( 'It holds tools that check the bookings database table and apply any missing updates. Back up your database before you use them.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I change how the forms look?', 'titan-zero-trades-front-desk' ),
                   __( 'Front Desk has no custom CSS editor in this version. Add any CSS you need through your theme’s Additional CSS screen, then clear any page cache so visitors see the change.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What happens to my data if I uninstall?', 'titan-zero-trades-front-desk' ),
                   __( 'By default, your bookings and settings are kept. To delete all plugin data when the plugin is removed, add <code>define( \'TZ_DELETE_DATA_ON_UNINSTALL\', true );</code> to wp-config.php before you uninstall. This cannot be undone.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Where are the add-on settings?', 'titan-zero-trades-front-desk' ),
                   __( 'On the Addons page. Each add-on has an on or off switch and its own settings block, with a Help link.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Which tabs can I reach from the Front Desk menu?', 'titan-zero-trades-front-desk' ),
                   __( 'Settings opens the settings tabs. Help, Status update emails, Scheduling Preview, Preset Setup and Vertical Packs are tabs there too, and open their own pages with the same tab bar.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I answer a personal data request?', 'titan-zero-trades-front-desk' ),
                   __( 'Front Desk adds its booking records to the WordPress personal data export, matched by the exact email address. Use Tools, then Export Personal Data in WordPress. To erase, use Tools, then Erase Personal Data: this deletes that address’s booking records automatically, and it cannot be undone.', 'titan-zero-trades-front-desk' ) ),
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
            array( __( 'My customer portal styles do not show.', 'titan-zero-trades-front-desk' ),
                   __( 'Check that you clicked Save styles and create pages on the Customer Portal screen, clear your caching plugin’s cache, and test in a private browser window.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'A staff member sees the menu but no calendars.', 'titan-zero-trades-front-desk' ),
                   __( 'That is expected until the calendar’s user access setting includes them under Select users with access. Administrators always see every calendar.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'The Missed-Call add-on rejects every request.', 'titan-zero-trades-front-desk' ),
                   __( 'Check three things: the request carries X-TZFD-Timestamp (Unix seconds, within five minutes of the server clock) and X-TZFD-Signature (sha256= followed by the HMAC-SHA256 of the timestamp, a dot, and the exact raw body, keyed with the secret), and the request is sent to the address ending in /wp-json/titan-zero-front-desk/v1/missed-call. A repeated signature is refused.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'The webhook signature does not match.', 'titan-zero-trades-front-desk' ),
                   __( 'Use the raw request body, with no reformatting, and the same secret saved in WebHook settings. Remember the header value starts with sha256= before the hex signature.', 'titan-zero-trades-front-desk' ) ),
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
                   __( '<code>[TZFD_FRONT_DESK id="N"]</code> shows a calendar’s booking form. <code>[TZFD_FRONT_DESK_LIST]</code> lists bookings for the calendar and date range you set.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Who can see the booking list shortcode?', 'titan-zero-trades-front-desk' ),
                   __( 'Only administrators. Visitors see nothing where <code>[TZFD_FRONT_DESK_LIST]</code> is placed, because the list shows customer details. You can still set the fields attribute to leave out anything you do not want shown.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I change the total a customer pays?', 'titan-zero-trades-front-desk' ),
                   __( 'Use the filter <code>tz_fd_invoice_amount</code>. It receives the amount and an array with <code>formid</code> and <code>itemnumber</code>, and returns the new amount.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How do I clear the availability cache from code?', 'titan-zero-trades-front-desk' ),
                   __( 'Run the action <code>tz_fd_cache_clean</code> with the calendar’s form ID as its only argument.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What REST endpoints does the plugin expose?', 'titan-zero-trades-front-desk' ),
                   __( '<code>/wp-json/titan-zero-front-desk/v1/missed-call</code> receives events from your phone system and checks the signature on every request. Resource routes under <code>/wp-json/titan-zero-front-desk/v1/</code> need an administrator login.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What do webhook events contain?', 'titan-zero-trades-front-desk' ),
                   __( 'Each event is a JSON body with a schema version of 1. Check the signature before you trust a request, and treat a repeated Event-ID as a duplicate you have already handled.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Which scheduled tasks does the plugin use?', 'titan-zero-trades-front-desk' ),
                   __( 'Reminders, follow-ups, automatic cancellation, webhook delivery and the message outbox each run as a WordPress scheduled event. You can list them with <code>wp cron event list</code> if you have WP-CLI. Deactivating the plugin removes them.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Can I set the intake mode from code?', 'titan-zero-trades-front-desk' ),
                   __( 'Settings is the easier route. From code, call <code>TZFD_Intake_Mode::set_for_form( $form_id, \'request\' )</code>. It must run as a logged-in administrator. It returns true, or an error if the form does not exist or the mode is not valid. Use <code>instant</code> to switch back. The setting is stored per form in the option <code>titan_zero_front_desk_intake_mode_{id}</code>.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'How can another system read a booking’s status?', 'titan-zero-trades-front-desk' ),
                   __( 'Send a GET request to <code>/wp-json/titan-zero-front-desk/v1/bookings/{id}</code>. It needs an administrator login and returns the status only. Customer and appointment details are left out on purpose.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Which actions send WebHook events?', 'titan-zero-trades-front-desk' ),
                   __( 'A new booking fires <code>tz_fd_process_data</code>, which sends booking.created. A status change fires <code>tz_fd_update_status</code>, which sends booking.status_update_attempted.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),

    array(
        'id'    => 'glossary',
        'title' => __( 'Glossary', 'titan-zero-trades-front-desk' ),
        'items' => array(
            array( __( 'What is an add-on?', 'titan-zero-trades-front-desk' ),
                   __( 'An optional feature, such as Reminder notifications, that you switch on from the Addons page. Each one has its own settings.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is a service?', 'titan-zero-trades-front-desk' ),
                   __( 'Something a customer can book, such as a 60-minute consultation or a site visit. Each service has a duration, and it belongs to a calendar.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is a shortcode?', 'titan-zero-trades-front-desk' ),
                   __( 'A short code in square brackets, such as <code>[TZFD_FRONT_DESK id="5"]</code>, that WordPress replaces with a feature when the page is shown.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'What is a vertical pack?', 'titan-zero-trades-front-desk' ),
                   __( 'A preset for one vertical or business type. It contains usual services and reference answers that you can install and then adjust.', 'titan-zero-trades-front-desk' ) ),
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
        __( 'Turn on the add-ons you need: reminders, follow-ups and the email blacklist.', 'titan-zero-trades-front-desk' ),
        __( 'Set up a real cron job for scheduled tasks, if your site is quiet.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Create your first calendar', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Calendar, then Manage calendars, then New Calendar.', 'titan-zero-trades-front-desk' ),
        __( 'Give the calendar a name and set its services, durations and working hours.', 'titan-zero-trades-front-desk' ),
        __( 'Use Block Times for any closed dates.', 'titan-zero-trades-front-desk' ),
        __( 'Click Publish and copy the shortcode into a page.', 'titan-zero-trades-front-desk' ),
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
    array( __( 'Install an industry pack', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Vertical Packs and find your vertical.', 'titan-zero-trades-front-desk' ),
        __( 'Install it, then review the services and answers it adds.', 'titan-zero-trades-front-desk' ),
        __( 'Change or remove anything that does not fit your business.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Verify webhook events on your side', 'titan-zero-trades-front-desk' ), array(
        __( 'Switch WebHook on from the Add-ons screen, then enter your receiving URL and a secret of at least 32 characters.', 'titan-zero-trades-front-desk' ),
        __( 'On your side, compute HMAC-SHA256 of the raw body with the same secret.', 'titan-zero-trades-front-desk' ),
        __( 'Compare it with the value after sha256= in X-TZFD-Signature.', 'titan-zero-trades-front-desk' ),
        __( 'Accept the event only when the signature matches, and ignore repeated event IDs.', 'titan-zero-trades-front-desk' ),
    ) ),
    array( __( 'Block spam bots and a known spammer', 'titan-zero-trades-front-desk' ), array(
        __( 'Go to Settings, then Other settings, and turn on Block spam bots.', 'titan-zero-trades-front-desk' ),
        __( 'Go to Addons, then Email Blacklist.', 'titan-zero-trades-front-desk' ),
        __( 'Enter the address, domain or @domain on its own line, then save.', 'titan-zero-trades-front-desk' ),
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
    array( __( 'Activity Logs', 'titan-zero-trades-front-desk' ), __( 'Records activity; administrators can clear the log.', 'titan-zero-trades-front-desk' ), __( 'Add-ons screen', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Automatically cancel pending bookings', 'titan-zero-trades-front-desk' ), __( 'Cancels bookings still pending after the hours you set.', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Cache Booking Availability', 'titan-zero-trades-front-desk' ), __( 'Keeps a copy of availability for busy sites; cleared when bookings change.', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Dashboard Widget: Upcoming appointments', 'titan-zero-trades-front-desk' ), __( 'Shows upcoming appointments on the WordPress dashboard.', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Email Blacklist', 'titan-zero-trades-front-desk' ), __( 'Refuses public bookings from listed addresses or domains.', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ) ),
    array( __( 'FollowUp notifications for bookings', 'titan-zero-trades-front-desk' ), __( 'Sends an email a set number of hours after the booking ends.', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ) ),
    array( __( 'iCal Export Addon', 'titan-zero-trades-front-desk' ), __( 'Provides a calendar feed link for each calendar.', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Limit the number of appointments per user', 'titan-zero-trades-front-desk' ), __( 'Caps how many appointments one customer can book.', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Missed-Call Text-Back', 'titan-zero-trades-front-desk' ), __( 'Accepts missed-call events from your phone system, protected by a secret.', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Password for making bookings', 'titan-zero-trades-front-desk' ), __( 'Customers must enter a password before booking.', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Reminder notifications for bookings', 'titan-zero-trades-front-desk' ), __( 'Sends an email a set number of hours before the appointment.', 'titan-zero-trades-front-desk' ), __( 'Addons', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Single Days Selection Interface', 'titan-zero-trades-front-desk' ), __( 'Offers an alternative time slot picker on each booking form, with its own display settings.', 'titan-zero-trades-front-desk' ), __( 'Addons, then each booking form', 'titan-zero-trades-front-desk' ) ),
    array( __( 'SMS open chat button', 'titan-zero-trades-front-desk' ), __( 'Button that opens an SMS chat with you.', 'titan-zero-trades-front-desk' ), __( 'Add-ons screen', 'titan-zero-trades-front-desk' ) ),
    array( __( 'WhatsApp open chat button', 'titan-zero-trades-front-desk' ), __( 'Button that opens a WhatsApp chat with you.', 'titan-zero-trades-front-desk' ), __( 'Add-ons screen', 'titan-zero-trades-front-desk' ) ),
    array( __( 'WebHook', 'titan-zero-trades-front-desk' ), __( 'Sends signed booking events to your own system.', 'titan-zero-trades-front-desk' ), __( 'Add-ons screen', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Vertical Packs (industry presets)', 'titan-zero-trades-front-desk' ), __( 'Trade presets with services, prices and reference answers.', 'titan-zero-trades-front-desk' ), __( 'Vertical Packs', 'titan-zero-trades-front-desk' ) ),
    array( __( 'Preset Setup', 'titan-zero-trades-front-desk' ), __( 'Starts a calendar from a vertical preset.', 'titan-zero-trades-front-desk' ), __( 'Settings, then Preset Setup', 'titan-zero-trades-front-desk' ) ),
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
            array( __( 'Bookings', 'titan-zero-trades-front-desk' ), __( 'Upcoming bookings from every calendar, with a button to open each calendar’s Booking Orders.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Services', 'titan-zero-trades-front-desk' ), __( 'The services each calendar offers, with address, location, price, duration and capacity. Use Address to set where a service is given, and Edit calendar to change its other settings.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Workers & locations', 'titan-zero-trades-front-desk' ), __( 'One page with three tabs. Team lists the workers, with their hours, days off and contact details, and the availability preview. Locations lists the places where they are based, with address, phone, timezone and postcodes covered. Equipment & rooms lists the resources bookings use. None of these change existing bookings.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Addons', 'titan-zero-trades-front-desk' ), __( 'Switch add-ons on or off, and set each add-on’s options.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Settings', 'titan-zero-trades-front-desk' ), __( 'The settings sections include General, Booking rules, Business hours, Notifications, Integrations and Advanced.', 'titan-zero-trades-front-desk' ) ),
        ),
    ),
    array(
        'title' => __( 'Setup', 'titan-zero-trades-front-desk' ),
        'rows'  => array(
            array( __( 'Preset Setup', 'titan-zero-trades-front-desk' ), __( 'Starts a calendar from a vertical preset: its services, answers and example prices. It also asks whether bookings are instant or held as requests. Review every item before anything is saved.', 'titan-zero-trades-front-desk' ) ),
            array( __( 'Vertical Packs', 'titan-zero-trades-front-desk' ), __( 'The industry packs in this build. Install one to add its services and answers.', 'titan-zero-trades-front-desk' ) ),
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
    <?php TZFD_Local_Brand::logo_img(); ?>
    <?php TZFD_Local_Brand::help_gallery(); ?>
    <h1 class="wp-heading-inline"><?php echo wp_kses( TZFD_UI_Graphics::brand_html( 'support' ), TZFD_UI_Graphics::allowed_html() ); ?><?php esc_html_e( 'Help and FAQ', 'titan-zero-trades-front-desk' ); ?></h1>
    <hr class="wp-header-end">

    <div class="notice notice-info inline tzfd-help-notice">
        <p><?php esc_html_e( 'Search below first. If you still need help, contact support.', 'titan-zero-trades-front-desk' ); ?></p>
        <p><a href="<?php echo esc_url( $tzfd_help_support_url ); ?>" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e( 'Contact support', 'titan-zero-trades-front-desk' ); ?></a></p>
    </div>

    <figure class="tzfd-help-figure"><img src="<?php echo esc_url( plugins_url( 'images/brand/marketing/workflow-overview-1600x1000.svg', __FILE__ ) ); ?>" alt="<?php echo esc_attr__( 'Workflow overview: an enquiry is captured by phone, message or website, the next step is prepared as a quote summary and reviewed before sending, and appointment options are checked for availability before the booking is confirmed.', 'titan-zero-trades-front-desk' ); ?>" width="1600" height="1000" loading="lazy" decoding="async" /></figure>

    <div class="postbox tzfd-help-search">
        <label for="tzfd-help-search-input"><?php esc_html_e( 'Search the help', 'titan-zero-trades-front-desk' ); ?></label>
        <input type="search" id="tzfd-help-search-input" class="regular-text" placeholder="<?php esc_attr_e( 'Try: email, reminder, shortcode', 'titan-zero-trades-front-desk' ); ?>">
        <p class="description"><?php esc_html_e( 'Use one or two keywords. Results from every section are shown together.', 'titan-zero-trades-front-desk' ); ?></p>
        <p id="tzfd-help-empty" class="tzfd-help-empty tzfd-help-hidden"><?php esc_html_e( 'No matches. Try a shorter word, or browse the sections below.', 'titan-zero-trades-front-desk' ); ?></p>
    </div>

    <nav class="tzfd-help-toc" aria-label="<?php esc_attr_e( 'Sections', 'titan-zero-trades-front-desk' ); ?>">
        <ul>
            <li><a href="#tzfd-help-guides"><?php esc_html_e( 'Step-by-step guides', 'titan-zero-trades-front-desk' ); ?></a></li>
            <?php foreach ( $tzfd_help_sections as $tzfd_section ) : ?>
                <li><a href="<?php echo esc_url( '#tzfd-help-' . sanitize_html_class( $tzfd_section['id'] ) ); ?>"><?php echo esc_html( $tzfd_section['title'] ); ?></a></li>
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
            <p><?php esc_html_e( 'Each page of Scheduling & Enquiries Manager, what it is for, and where to find it. The Front Desk menu lists the main pages; the Settings tabs hold the rest.', 'titan-zero-trades-front-desk' ); ?></p>
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




