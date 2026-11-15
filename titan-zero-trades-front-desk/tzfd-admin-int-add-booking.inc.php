<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( defined( 'ABSPATH' ) ) { require_once __DIR__ . '/includes/class-tzfd-request-scalar.php'; }
require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';

require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';
if ( ! defined( 'TZFD_ADMIN_BOOKING_MULTIPLE_SERVICES' ) ) {
    define( 'TZFD_ADMIN_BOOKING_MULTIPLE_SERVICES', true );
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only calendar selection; booking submission is validated by the booking handler.
$this->item = TZFD_Admin_Request_Ids::calendar( $_GET );
if ( null === $this->item ) {
    wp_die( esc_html__( 'Invalid calendar identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}

require_once __DIR__ . '/includes/class-tzfd-admin-report-scope.php';
$tzfd_report_company = TZFD_Admin_Report_Scope::enforce( $this->item );

$current_user = wp_get_current_user();
$tz_current_user_access = current_user_can('tz_fd_access');
$tz_calendar_access_ids = TZFD_Access_List::decode( $this->get_option( 'tz_fd_user_access', '' ) );

if ( !is_admin() || (!$tz_current_user_access && !in_array($current_user->ID, $tz_calendar_access_ids, true)))
{
    echo esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' );
    exit;
}

$tz_message = '';

// A valid form nonce does not establish that a booking was saved. The booking
// handler is responsible for reporting success after persistence succeeds.

// Page assets (review R2). Enqueued here because this template renders after admin_enqueue_scripts.
wp_register_style( 'tzfd-add-booking-inline', false, array(), '2.0.1' );
wp_enqueue_style( 'tzfd-add-booking-inline' );
wp_add_inline_style( 'tzfd-add-booking-inline', '.clear{clear:both}.tzfd-first-button{margin-right:10px!important}.tzfd-buttons-container{margin:1em 1em 1em 0}.tzfd-return-link{float:right}.wrap .tzfd-admin-booking-help{font-size:15px;line-height:1.6}.wrap .tzfd-admin-booking-panel{background:#1f2937;color:#f9fafb;padding:16px 20px;border-radius:8px;margin:12px 0 20px}.wrap .tzfd-admin-booking-panel p{color:#f9fafb;font-size:15px;line-height:1.6;margin:0}.wrap .tzfd-admin-booking-panel a{color:#93c5fd}' );

// Flag read by the public form script (tz_fd_hour_in_admin). Printed in the footer before the form scripts.
wp_register_script( 'tzfd-add-booking-inline', false, array( 'jquery' ), '2.0.1', true );
wp_enqueue_script( 'tzfd-add-booking-inline' );
wp_add_inline_script( 'tzfd-add-booking-inline', 'var tz_fd_hour_in_admin=true;window.tzfdBookingServiceLabel=' . wp_json_encode( __( 'Choose service', 'titan-zero-trades-front-desk' ) ) . ';', 'before' );

// Reconcile the appointment widget's visible selected slots with its hidden
// required field before jQuery Validate checks the form. The widget can show
// a selected time while its serialized field is empty after a redraw.
wp_add_inline_script( 'tzfd-add-booking-inline', <<<'TZFD_JS'
(function () {
    'use strict';
    function two(n) { return ('0' + Number(n)).slice(-2); }
    function bookingInstance(form) {
        var jq = window.tzfdformjQuery || window.jQuery;
        var match = form && form.id ? form.id.match(/_(\d+)$/) : null;
        if (!jq || !jq.tzfdform || !jq.tzfdform.forms || !match) return null;
        return jq.tzfdform.forms['_' + match[1]] || null;
    }
    function bookingWidget(form, field) {
        var instance = bookingInstance(form);
        return instance && typeof instance.getItem === 'function' ? instance.getItem(field.name) : null;
    }
    function syncBookingSlots(form) {
        if (!form || !form.querySelectorAll) return;
        var fields = form.querySelectorAll('.dfield.fapp input[summary="usedSlots"]');
        Array.prototype.forEach.call(fields, function (field) {
            var widget = bookingWidget(form, field);
            var slots = [];
            var stateAvailable = widget && Array.isArray(widget.allUsedSlots);
            if (stateAvailable) {
                widget.allUsedSlots.forEach(function (slot) {
                    var d = slot && slot.d;
                    var service = Number(slot && slot.serviceindex);
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(d || '') || !Number.isInteger(service) || service < 0) return;
                    var start = widget.formattime ? widget.formattime(Number(slot.h1) * 60 + Number(slot.m1), 2) : two(slot.h1) + ':' + two(slot.m1);
                    var end = widget.formattime ? widget.formattime(Number(slot.h2) * 60 + Number(slot.m2), 2) : two(slot.h2) + ':' + two(slot.m2);
                    slots.push(d + ' ' + start + '/' + end + ' ' + service + ' ' + (slot.quantity || '1'));
                });
            }
            // A redraw can leave the widget array empty briefly while its visible
            // selected row is already present. Preserve that displayed selection.
            var canSync = stateAvailable;
            if (!slots.length) {
                var wrapper = field.closest('.dfield.fapp');
                var rows = wrapper ? wrapper.querySelectorAll('.usedSlots .tz_fd_list[d][h1][m1][h2][m2][s]') : [];
                if (rows.length) canSync = true;
                Array.prototype.forEach.call(rows, function (row) {
                    var d = row.getAttribute('d');
                    var service = Number(row.getAttribute('s'));
                    if (!/^\d{4}-\d{2}-\d{2}$/.test(d || '') || !Number.isInteger(service) || service < 0) return;
                    slots.push(d + ' ' + two(row.getAttribute('h1')) + ':' + two(row.getAttribute('m1')) + '/' +
                        two(row.getAttribute('h2')) + ':' + two(row.getAttribute('m2')) + ' ' + service + ' ' + (row.getAttribute('quantity') || '1'));
                });
            }
            // If the widget is not ready and the rendered list is empty, its
            // hidden value may already contain a valid selection. Do not erase it.
            if (!canSync) return;
            var value = slots.join(';');
            if (field.value !== value) {
                field.value = value;
                if (window.jQuery) window.jQuery(field).trigger('change');
                else field.dispatchEvent(new Event('change', {bubbles:true}));
            }
        });
    }
    function configureBookingForm(form) {
        if (!form) return;
        var fields = form.querySelectorAll('.dfield.fapp input[summary="usedSlots"]');
        Array.prototype.forEach.call(fields, function (field) {
            var widget = bookingWidget(form, field);
            if (widget) widget.maxNumberOfApp = 0;
            var label = document.getElementById(field.id + '_label');
            if (!label || !/^appointments?$/i.test(label.textContent.replace(/\*/g, '').trim())) return;
            var required = label.querySelector('.r');
            Array.prototype.slice.call(label.childNodes).forEach(function (node) {
                if (node !== required) label.removeChild(node);
            });
            label.insertBefore(document.createTextNode(window.tzfdBookingServiceLabel || 'Choose service'), required || null);
            label.setAttribute('for', field.id + '_service');
        });
    }
    function bindBookingSubmit(form) {
        var jq = window.tzfdformjQuery || window.jQuery;
        if (!form || !jq || !jq.fn) return;
        // The public builder submits with jQuery's synthetic .submit(). Bind
        // directly to the form before its validator is initialized so the
        // selected time reaches the required hidden field first.
        jq(form).off('submit.tzfdBookingSlotSync').on('submit.tzfdBookingSlotSync', function () {
            configureBookingForm(this);
            syncBookingSlots(this);
        });
    }
    function bindBookingForms() {
        var forms = document.querySelectorAll('.wrap form.tz_fd_p_form');
        Array.prototype.forEach.call(forms, function (form) {
            bindBookingSubmit(form);
        });
    }
    function bindSlotEvents() {
        var jq = window.tzfdformjQuery || window.jQuery;
        if (!jq || !jq.fn || jq(document).data('tzfdBookingSlotSyncBound')) return;
        jq(document).data('tzfdBookingSlotSyncBound', true).on('beforeClickSlot.tzfdBookingSlotSync', function (event, detail) {
            if (!detail || !detail.name) return;
            var field = document.getElementById(detail.name);
            if (field && field.form) syncBookingSlots(field.form);
        });
    }
    bindSlotEvents();
    // This inline script is queued before the public builder scripts, so the
    // form-level sync runs before jQuery Validate's submit handler.
    bindBookingForms();
    document.addEventListener('DOMContentLoaded', function () {
        bindSlotEvents();
        bindBookingForms();
        var form = document.querySelector('.wrap form.tz_fd_p_form');
        if (form) {
            configureBookingForm(form);
            syncBookingSlots(form);
        }
    });
    document.addEventListener('change', function (event) {
        if (event.target && event.target.closest && event.target.closest('.usedSlots')) {
            var form = event.target.closest('form');
            if (form) syncBookingSlots(form);
        }
    }, true);
    // The multi-step wizard validates the hidden appointment selection.
    // Synchronise the authoritative widget state immediately before its check.
    document.addEventListener('tzfd:sync-booking-slots', function (event) {
        if (event.target && event.target.matches && event.target.matches('form.tz_fd_p_form')) {
            syncBookingSlots(event.target);
        }
    });
    // Capture phase runs before the existing jQuery Validate submit handler.
    document.addEventListener('submit', function (event) {
        if (event.target && event.target.querySelector('.dfield.fapp input[summary="usedSlots"]')) {
            configureBookingForm(event.target);
            syncBookingSlots(event.target);
        }
    }, true);
    // Removing a selected appointment updates the widget state during its click handler.
    document.addEventListener('click', function (event) {
        var submit = event.target && event.target.closest ? event.target.closest('.pbSubmit') : null;
        if (submit) {
            var submitForm = submit.closest('form.tz_fd_p_form');
            if (submitForm) {
                configureBookingForm(submitForm);
                syncBookingSlots(submitForm);
            }
        }
        var cancel = event.target && event.target.closest ? event.target.closest('.usedSlots a.cancel') : null;
        if (cancel) {
            var form = cancel.closest('form');
            if (form) window.setTimeout(function () { syncBookingSlots(form); }, 0);
        }
    }, true);
}());
TZFD_JS
);


// Booking/customer selection always uses the native Front Desk customer store.
// Workspace may be installed later, but cannot replace local booking ownership.
require_once __DIR__ . '/includes/class-tzfd-local-customers.php';
require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';
$tz_customer_query_id = tzfd_request_text( 'get', 'tz_fd_customer_id' ); // Read-only preselection; never trusted for a write.
$tz_selected_customer_id = '' !== $tz_customer_query_id ? TZFD_Admin_Request_Ids::positive( $tz_customer_query_id ) : 0;
if ( null === $tz_selected_customer_id ) { $tz_selected_customer_id = 0; }
$tz_selected_customer = null;
$tz_booking_customers = array();
if ( current_user_can( 'tz_fd_access' ) ) {
    if ( $tz_selected_customer_id ) {
        $tz_row = TZFD_Local_Customers::find( $tz_selected_customer_id );
        $tz_selected_customer = is_array( $tz_row ) ? (object) $tz_row : null;
    }
    $tz_rows = TZFD_Local_Customers::search( '', array( 'limit' => 200 ) );
    $tz_booking_customers = is_array( $tz_rows ) ? array_map( static function ( $row ) { return (object) $row; }, $tz_rows ) : array();
}
// Workspace sites are an optional external feature. Do not offer its locations
// for native customer IDs: numeric IDs can overlap between independent stores.
$tz_workspace_sites = array();
$tzfd_workspace_location_mode = false;
// Render customer data once and switch contact fields in place, without reloading
// or discarding a partially completed appointment.
$tz_customer_options = array();
foreach ( $tz_booking_customers as $tz_booking_customer ) {
    $tz_customer_options[ (string) $tz_booking_customer->id ] = array(
        'name'  => (string) $tz_booking_customer->name,
        'email' => (string) $tz_booking_customer->email,
        'phone' => (string) $tz_booking_customer->phone,
    );
}
if ( $tz_selected_customer ) {
    $tz_customer_options[ (string) $tz_selected_customer_id ] = array(
        'name'  => (string) $tz_selected_customer->name,
        'email' => (string) $tz_selected_customer->email,
        'phone' => (string) $tz_selected_customer->phone,
    );
}
// Only admin users with customer access reach this screen.
wp_add_inline_script( 'tzfd-add-booking-inline', 'window.tzfdBookingCustomers=' . wp_json_encode( $tz_customer_options ) . ';', 'before' );
wp_add_inline_script( 'tzfd-add-booking-inline', 'window.tzfdBookingExternalLocationMode=false;', 'before' );
wp_add_inline_script( 'tzfd-add-booking-inline', 'window.tzfdWorkspaceSiteEndpoint=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ';window.tzfdWorkspaceSiteNonce=' . wp_json_encode( wp_create_nonce( 'tzfd_workspace_customer_sites' ) ) . ';', 'before' );
wp_add_inline_script( 'tzfd-add-booking-inline', 'window.tzfdBookingWorkspaceCustomer=' . wp_json_encode( (string) $tz_selected_customer_id ) . ';', 'before' );
wp_add_inline_script( 'tzfd-add-booking-inline', 'window.tzfdBookingWorkspaceSites=' . wp_json_encode( array_map( function ( $site ) {
    return array( 'id' => (int) $site['id'], 'label' => (string) $site['label'] );
}, $tz_workspace_sites ) ) . ';', 'before' );
// Report renderer failures explicitly rather than displaying an empty booking pane.
wp_add_inline_script( 'tzfd-add-booking-inline', <<<'TZFD_RENDER_HEALTH_JS'
(function () {
    'use strict';
    function inspect() {
        var box = document.getElementById('tzfd-admin-booking-health');
        if (!box) return;
        var form = document.querySelector('.wrap form.tz_fd_p_form');
        var fields = form ? form.querySelector('#fieldlist_1') : null;
        if (!fields && form) fields = form.querySelector('[id^="fieldlist_"]');
        var ready = !!(fields && fields.querySelector('.fields, .dfield, input:not([type="hidden"]), select'));
        if (ready) { box.style.display = 'none'; return; }
        box.classList.remove('notice-warning');
        box.classList.add('notice-error');
        box.textContent = '';
        var p = document.createElement('p');
        p.textContent = form ? 'The booking form could not initialise. Check that the booking calendar has services and that JavaScript files load without errors; then reload this page.' : 'No booking form was generated. Check the calendar configuration and reload this page.';
        box.appendChild(p);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { window.setTimeout(inspect, 3500); });
    else window.setTimeout(inspect, 3500);
}());
TZFD_RENDER_HEALTH_JS
);
wp_add_inline_script( 'tzfd-add-booking-inline', <<<'TZFD_CUSTOMER_JS'
(function () {
    'use strict';
    var previous = {};
    function customerField(el) {
        if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return null;
        var label = ((el.name || '') + ' ' + (el.id || '') + ' ' + (el.placeholder || '') + ' ' + (el.getAttribute('aria-label') || '')).toLowerCase();
        if (/e.?mail/.test(label)) return 'email';
        if (/phone|mobile|tel/.test(label)) return 'phone';
        if (/customer.?name|full.?name|your.?name/.test(label)) return 'name';
        return null;
    }
    function applyCustomer() {
        var select = document.getElementById('tz_fd_booking_customer_select');
        if (!select) return;
        var customer = (window.tzfdBookingCustomers || {})[select.value] || {};
        document.querySelectorAll('.wrap .fapp input').forEach(function (field) {
            var key = customerField(field);
            if (!key) return;
            // Do not overwrite contact data that an administrator has manually edited.
            if (field.value && field.value !== (previous[key] || '')) return;
            var next = customer[key] || '';
            if (field.value !== next) {
                field.value = next;
                field.dispatchEvent(new Event('input', {bubbles:true}));
                field.dispatchEvent(new Event('change', {bubbles:true}));
            }
        });
        previous = customer;
    }
    document.addEventListener('DOMContentLoaded', function () {
        var select = document.getElementById('tz_fd_booking_customer_select');
        if (!select) return;
        select.addEventListener('change', applyCustomer);
        function findBookingForm() {
            var slot = document.querySelector('.wrap .dfield.fapp input[summary="usedSlots"]');
            if (slot && slot.form) return slot.form;
            var forms = document.querySelectorAll('.wrap form');
            for (var i = 0; i < forms.length; i++) {
                if (forms[i].querySelector('.dfield.fapp input[summary="usedSlots"]')) return forms[i];
            }
            return null;
        }
        function syncCustomerId(targetForm) {
            var bookingForm = targetForm && targetForm.tagName === 'FORM' ? targetForm : findBookingForm();
            if (!bookingForm) return;
            var hidden = bookingForm.querySelector('input[name="tz_fd_customer_id"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'tz_fd_customer_id';
                bookingForm.appendChild(hidden);
            }
            hidden.value = select.value || '';
        }
        // External location mapping is disabled for native Front Desk customers.
        // Discover sites only if a future verified ID mapping enables the adapter.
        var workspaceSites = window.tzfdBookingWorkspaceSites || [];
        var workspaceCustomer = String(window.tzfdBookingWorkspaceCustomer || '');
        var requestSequence = 0;
        var siteSelect = document.createElement('select');
        siteSelect.id = 'tz_fd_workspace_site_select';
        siteSelect.setAttribute('aria-label', 'Workspace location');
        var siteLabel = document.createElement('label');
        siteLabel.textContent = 'Workspace location (optional) ';
        siteLabel.appendChild(siteSelect);
        siteLabel.style.display = 'block';
        siteLabel.style.margin = '10px 0';
        if (window.tzfdBookingExternalLocationMode === true) select.insertAdjacentElement('afterend', siteLabel);
        function syncWorkspaceSite(targetForm) {
            var bookingForm = targetForm && targetForm.tagName === 'FORM' ? targetForm : findBookingForm();
            if (!bookingForm) return;
            var hidden = bookingForm.querySelector('input[name="tz_fd_workspace_site_id"]');
            if (!hidden) {
                hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'tz_fd_workspace_site_id';
                bookingForm.appendChild(hidden);
            }
            hidden.value = siteSelect.value || '';
        }
        function populateSites(sites, placeholder) {
            siteSelect.replaceChildren();
            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = placeholder || 'No Workspace location';
            siteSelect.appendChild(blank);
            sites.forEach(function (site) {
                var option = document.createElement('option');
                option.value = String(site.id);
                option.textContent = String(site.label || 'Location ' + site.id);
                siteSelect.appendChild(option);
            });
            syncWorkspaceSite();
        }
        function refreshWorkspaceSites() {
            var customerId = String(select.value || '');
            var sequence = ++requestSequence;
            // Never submit a location belonging to the previously selected customer.
            populateSites([], customerId ? 'Loading locations…' : 'No Workspace location');
            if (!customerId) return;
            if (customerId === workspaceCustomer) {
                populateSites(workspaceSites);
                return;
            }
            if (!window.fetch || !window.tzfdWorkspaceSiteEndpoint) {
                populateSites([], 'Locations unavailable');
                return;
            }
            var body = new URLSearchParams({
                action: 'tzfd_workspace_customer_sites',
                nonce: window.tzfdWorkspaceSiteNonce || '',
                customer_id: customerId
            });
            fetch(window.tzfdWorkspaceSiteEndpoint, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString()
            }).then(function (response) {
                if (!response.ok) throw new Error('Location lookup failed');
                return response.json();
            }).then(function (payload) {
                if (sequence !== requestSequence || String(select.value) !== customerId) return;
                if (!payload.success || !payload.data || String(payload.data.customer_id) !== customerId) throw new Error('Location lookup rejected');
                populateSites(Array.isArray(payload.data.sites) ? payload.data.sites : []);
            }).catch(function () {
                if (sequence === requestSequence && String(select.value) === customerId) populateSites([], 'Locations unavailable');
            });
        }
        siteSelect.addEventListener('change', function () { syncWorkspaceSite(); });
        select.addEventListener('change', function () { syncCustomerId(); if (window.tzfdBookingExternalLocationMode === true) refreshWorkspaceSites(); });
        if (window.tzfdBookingExternalLocationMode === true) refreshWorkspaceSites();
        // The legacy form builder can invoke AJAX submission directly from its
        // generated button, bypassing the native submit event. Synchronise the
        // selected native customer ID during capture before that handler runs.
        document.addEventListener('click', function (event) {
            var button = event.target && event.target.closest ? event.target.closest('.pbSubmit') : null;
            if (!button) return;
            var bookingForm = button.closest('form');
            if (bookingForm && bookingForm.querySelector('.dfield.fapp input[summary="usedSlots"]')) {
                syncCustomerId(bookingForm);
            }
        }, true);
        // AJAX booking submission can serialize form fields without a native
        // submit event. Keep the ID synchronized as selection changes.
        // Booking forms can be injected or rebuilt after DOMContentLoaded.
        // Attach the selected customer ID immediately before each real booking submit.
        document.addEventListener('submit', function (event) {
            if (event.target && event.target.matches('form') && event.target.querySelector('.dfield.fapp input[summary="usedSlots"]')) {
                syncCustomerId(event.target);
                if (window.tzfdBookingExternalLocationMode === true) syncWorkspaceSite(event.target);
            }
        }, true);
        syncCustomerId();
        applyCustomer();
        var form = document.querySelector('.wrap .fapp');
        if (form && window.MutationObserver) {
            var observer = new MutationObserver(applyCustomer);
            observer.observe(form, {childList:true, subtree:true});
        }
    });
}());
TZFD_CUSTOMER_JS
);

?>
<div class="wrap">

<h1><?php esc_html_e('Add Booking','titan-zero-trades-front-desk'); ?></h1>

<div class="tzfd-buttons-container">
	<a href="<?php print esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
    <div class="clear"></div>
</div>

<?php if ( current_user_can( 'tz_fd_access' ) ) : ?>
<div class="tzfd-admin-booking-panel"><label for="tz_fd_booking_customer_select" style="color:#fff;font-weight:600;display:block;margin-bottom:8px"><?php esc_html_e( 'Existing customer (optional)', 'titan-zero-trades-front-desk' ); ?></label>
<select id="tz_fd_booking_customer_select" style="max-width:100%;min-width:280px">
<option value=""><?php esc_html_e( 'Enter a new customer below', 'titan-zero-trades-front-desk' ); ?></option>
<?php foreach ( $tz_booking_customers as $tz_booking_customer ) : ?>
<option value="<?php echo esc_attr( $tz_booking_customer->id ); ?>" <?php selected( $tz_selected_customer_id, (int) $tz_booking_customer->id ); ?>><?php echo esc_html( $tz_booking_customer->name . ( $tz_booking_customer->email ? ' — ' . $tz_booking_customer->email : '' ) ); ?></option>
<?php endforeach; ?>
<?php if ( $tz_selected_customer && ! array_filter( $tz_booking_customers, static function( $c ) use ( $tz_selected_customer_id ) { return (int) $c->id === $tz_selected_customer_id; } ) ) : ?>
<option value="<?php echo esc_attr( $tz_selected_customer_id ); ?>" selected><?php echo esc_html( $tz_selected_customer->name ); ?></option>
<?php endif; ?>
</select><p style="margin-top:8px;color:#fff"><?php esc_html_e( 'Selecting a customer fills available contact fields without resetting the booking. Manually edited fields are preserved.', 'titan-zero-trades-front-desk' ); ?></p></div>
<?php endif; ?>



<div class="tzfd-admin-booking-panel"><p><?php esc_html_e('Add a booking from the administration area. CAPTCHA and payment are disabled for this form. Choose a service, date and available time. To add multiple services, select a time for each service before submitting.','titan-zero-trades-front-desk'); ?></p></div>


<div id="tzfd-admin-booking-health" class="notice notice-warning inline" role="status" aria-live="polite"><p><?php esc_html_e( 'Loading the booking fields…', 'titan-zero-trades-front-desk' ); ?></p></div>
<?php $this->output_filter_content(array('id' => $this->item)); ?>
<noscript><div class="notice notice-error"><p><?php esc_html_e( 'JavaScript is required for the interactive booking form. Enable JavaScript and reload this page.', 'titan-zero-trades-front-desk' ); ?></p></div></noscript>

</div>


