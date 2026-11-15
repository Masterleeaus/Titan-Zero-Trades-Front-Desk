<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-request-scalar.php';

require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';
require_once __DIR__ . '/includes/class-tzfd-stored-data.php';

class TZFD_FrontDeskPlugin extends TZFD_BOOK_BaseClass {

    private $menu_parameter = 'tzfd';
    private $prefix = 'tz_fd_frontdesk';
    private $plugin_name = 'Titan Zero Trades: Front Desk';
    private $plugin_URL = '';
    public $table_items = TZFD_Tables::FORMS;
    public $table_messages = TZFD_Tables::BOOKINGS;
    public $print_counter = 1;
    private $include_user_data_csv = false;
    // true: every booking submission must carry a valid 'tz_fd_actions_bookingform' nonce (the hidden anonce field) before any posted value is read.
    private $booking_form_nonce = true;
    public $TZFD_CFPP_global_templates;
    protected $postURL;

    protected $paid_statuses = array('Pending','Cancelled','Cancelled by customer','Rejected','Attended');
    public $shorttag = 'TZFD_FRONT_DESK';
    public $blocked_by_admin_indicator = 'BLOCKED@BY.ADMIN';

    protected $tags_allowed = array(
                                  'a' => array(
                                      'href' => array(),
                                      'title' => array(),
                                      'target' => array(),
                                      'style' => array(),
                                      'class' => array(),
                                  ),
                                  'br' => array(),
                                  'em' => array(),
                                  'b' => array(),
                                  'strong' => array(),
                                  'img' => array(
                                            'src' => array(),
                                            'width' => array(),
                                            'height' => array(),
                                            'border' => array(),
                                            'style' => array(),
                                            'class' => array(),
                                            ),
                              );

    public function _install() {
        global $wpdb;

        // New installs start with Onboarding and the dashboard widget on. Quotes now belong to Titan Zero Quotes.
        // An existing site keeps whatever it has chosen on the Add-ons page.
        if ( false === get_option( 'tz_fd_addons_active_list', false ) ) {
            update_option( 'tz_fd_addons_active_list', array( 'tzfd-Onboarding', 'tzfd-DashboardWidget' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $table_messages = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        $sql_messages   = "CREATE TABLE {$table_messages} (
            id int(10) NOT NULL AUTO_INCREMENT,
            formid INT NOT NULL,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            ipaddr VARCHAR(250) DEFAULT '' NOT NULL,
            notifyto VARCHAR(250) DEFAULT '' NOT NULL,
            data mediumtext,
            posted_data mediumtext,
            whoadded VARCHAR(250) DEFAULT '' NOT NULL,
            company_id bigint(20) unsigned NOT NULL DEFAULT 0,
            KEY company_id (company_id),
            UNIQUE KEY id (id)
        ) {$charset_collate};";

        dbDelta( $sql_messages );

        $table_forms = $wpdb->prefix . TZFD_Tables::FORMS;
        $sql_forms   = "CREATE TABLE {$table_forms} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL DEFAULT 0,
            form_name VARCHAR(250) DEFAULT '' NOT NULL,
            form_structure mediumtext,
            calendar_language VARCHAR(250) DEFAULT '' NOT NULL,
            date_format VARCHAR(250) DEFAULT '' NOT NULL,
            product_name VARCHAR(250) DEFAULT '' NOT NULL,
            pay_later_label VARCHAR(250) DEFAULT '' NOT NULL,
            defaultstatus VARCHAR(250) DEFAULT '' NOT NULL,
            defaultpaidstatus VARCHAR(250) DEFAULT '' NOT NULL,
            fp_from_email VARCHAR(250) DEFAULT '' NOT NULL,
            fp_from_name text,
            fp_destination_emails text,
            fp_subject text,
            fp_inc_additional_info VARCHAR(10) DEFAULT '' NOT NULL,
            fp_return_page VARCHAR(250) DEFAULT '' NOT NULL,
            fp_message text,
            fp_emailformat VARCHAR(10) DEFAULT '' NOT NULL,
            fp_emailtomethod VARCHAR(10) DEFAULT '' NOT NULL,
            fp_destination_emails_field VARCHAR(200) DEFAULT '' NOT NULL,
            cu_enable_copy_to_user VARCHAR(10) DEFAULT '' NOT NULL,
            cu_user_email_field VARCHAR(250) DEFAULT '' NOT NULL,
            cu_subject VARCHAR(250) DEFAULT '' NOT NULL,
            cu_message text,
            cu_emailformat VARCHAR(10) DEFAULT '' NOT NULL,
            fp_emailfrommethod VARCHAR(10) DEFAULT '' NOT NULL,
            vs_text_maxapp TEXT,
            vs_text_is_required TEXT,
            vs_text_is_email TEXT,
            vs_text_datemmddyyyy TEXT,
            vs_text_dateddmmyyyy TEXT,
            vs_text_number TEXT,
            vs_text_digits TEXT,
            vs_text_max TEXT,
            vs_text_min TEXT,
            vs_text_pageof TEXT,
            vs_text_submitbtn TEXT,
            vs_text_previousbtn TEXT,
            vs_text_nextbtn TEXT,
            vs_text_quantity TEXT,
            vs_text_cancel TEXT,
            vs_text_cost TEXT,
            vs_text_nomore TEXT,
            vs_text_nmore TEXT,
            tz_fd_user_access text,
            tz_fd_user_access_settings VARCHAR(10) DEFAULT '' NOT NULL,
            display_emails_endtime VARCHAR(10) DEFAULT '' NOT NULL,
            rep_enable VARCHAR(10) DEFAULT '' NOT NULL,
            rep_days VARCHAR(10) DEFAULT '' NOT NULL,
            rep_hour VARCHAR(10) DEFAULT '' NOT NULL,
            rep_emails text,
            rep_subject text,
            rep_emailformat VARCHAR(10) DEFAULT '' NOT NULL,
            rep_message text,
            cv_enable_captcha VARCHAR(20) DEFAULT '' NOT NULL,
            cv_width VARCHAR(20) DEFAULT '' NOT NULL,
            cv_height VARCHAR(20) DEFAULT '' NOT NULL,
            cv_chars VARCHAR(20) DEFAULT '' NOT NULL,
            cv_font VARCHAR(20) DEFAULT '' NOT NULL,
            cv_min_font_size VARCHAR(20) DEFAULT '' NOT NULL,
            cv_max_font_size VARCHAR(20) DEFAULT '' NOT NULL,
            cv_noise VARCHAR(20) DEFAULT '' NOT NULL,
            cv_noise_length VARCHAR(20) DEFAULT '' NOT NULL,
            cv_background VARCHAR(20) DEFAULT '' NOT NULL,
            cv_border VARCHAR(20) DEFAULT '' NOT NULL,
            cv_text_enter_valid_captcha VARCHAR(200) DEFAULT '' NOT NULL,
            UNIQUE KEY id (id),
            KEY company_id (company_id)
        ) {$charset_collate};";

        // Existing forms tables get company_id (and the default-company backfill) before dbDelta runs.
        // The default form insert below writes company_id, so it must exist even when Workspace's step 600 has not run.
        if ( class_exists( 'TZFD_Business_Schema' ) ) {
            $tz_forms_default = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::legacy_owner() : 0;
            TZFD_Business_Schema::ensure_company_column( TZFD_Tables::FORMS, $tz_forms_default );
        }

        dbDelta( $sql_forms );


        // insert initial data
        $tz_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM %i", $wpdb->prefix.TZFD_Tables::FORMS ) );
        // Seed only for a real company. With no membership the default form waits for tz_fd_seed_form_for_member(), never company 0.
        if ( !$tz_count && TZFD_Scoped_Store::company_id() > 0 )
        {
            define('TZFD_BOOK_DEFAULT_fp_from_email', get_the_author_meta('user_email', get_current_user_id()) );
            define('TZFD_BOOK_DEFAULT_fp_destination_emails', TZFD_BOOK_DEFAULT_fp_from_email);
            $wpdb->insert( $wpdb->prefix.TZFD_Tables::FORMS, array( 'company_id' => TZFD_Scoped_Store::company_id(), 'id' => 1,
                                      'form_name' => 'Form 1',

                                      'form_structure' => $this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure),

                                      'calendar_language' => $this->get_option('calendar_language', ''),
                                      'date_format' => $this->get_option('date_format', 'mm/dd/yy'),
                                      'product_name' => $this->get_option('product_name', 'Booking'),
                                      'pay_later_label' => $this->get_option('pay_later_label', 'Pay later'),

                                      'fp_from_email' => $this->get_option('fp_from_email', TZFD_BOOK_DEFAULT_fp_from_email),
                                      'fp_destination_emails' => $this->get_option('fp_destination_emails', TZFD_BOOK_DEFAULT_fp_destination_emails),
                                      'fp_subject' => $this->get_option('fp_subject', TZFD_BOOK_DEFAULT_fp_subject),
                                      'fp_inc_additional_info' => $this->get_option('fp_inc_additional_info', TZFD_BOOK_DEFAULT_fp_inc_additional_info),
                                      'fp_return_page' => $this->get_option('fp_return_page', TZFD_BOOK_DEFAULT_fp_return_page),
                                      'fp_message' => $this->get_option('fp_message', TZFD_BOOK_DEFAULT_fp_message),
                                      'fp_emailformat' => $this->get_option('fp_emailformat', TZFD_BOOK_DEFAULT_email_format),

                                      'cu_enable_copy_to_user' => $this->get_option('cu_enable_copy_to_user', TZFD_BOOK_DEFAULT_cu_enable_copy_to_user),
                                      'cu_user_email_field' => $this->get_option('cu_user_email_field', TZFD_BOOK_DEFAULT_cu_user_email_field),
                                      'cu_subject' => $this->get_option('cu_subject', TZFD_BOOK_DEFAULT_cu_subject),
                                      'cu_message' => $this->get_option('cu_message', TZFD_BOOK_DEFAULT_cu_message),
                                      'cu_emailformat' => $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format),

                                      'vs_text_maxapp' => $this->get_option('vs_text_maxapp', TZFD_BOOK_DEFAULT_vs_text_maxapp),
                                      'vs_text_is_required' => $this->get_option('vs_text_is_required', TZFD_BOOK_DEFAULT_vs_text_is_required),
                                      'vs_text_is_email' => $this->get_option('vs_text_is_email', TZFD_BOOK_DEFAULT_vs_text_is_email),
                                      'vs_text_datemmddyyyy' => $this->get_option('vs_text_datemmddyyyy', TZFD_BOOK_DEFAULT_vs_text_datemmddyyyy),
                                      'vs_text_dateddmmyyyy' => $this->get_option('vs_text_dateddmmyyyy', TZFD_BOOK_DEFAULT_vs_text_dateddmmyyyy),
                                      'vs_text_number' => $this->get_option('vs_text_number', TZFD_BOOK_DEFAULT_vs_text_number),
                                      'vs_text_digits' => $this->get_option('vs_text_digits', TZFD_BOOK_DEFAULT_vs_text_digits),
                                      'vs_text_max' => $this->get_option('vs_text_max', TZFD_BOOK_DEFAULT_vs_text_max),
                                      'vs_text_min' => $this->get_option('vs_text_min', TZFD_BOOK_DEFAULT_vs_text_min),
                                      'vs_text_pageof' => $this->get_option('vs_text_pageof', 'Page {0} of {0}'),
                                      'vs_text_submitbtn' => $this->get_option('vs_text_submitbtn', 'Submit'),
                                      'vs_text_previousbtn' => $this->get_option('vs_text_previousbtn', 'Previous'),
                                      'vs_text_nextbtn' => $this->get_option('vs_text_nextbtn', 'Next'),

                                      'vs_text_quantity' => $this->get_option_not_empty('vs_text_quantity', __( 'Quantity', 'titan-zero-trades-front-desk' )),
                                      'vs_text_cancel' => $this->get_option_not_empty('vs_text_cancel', __( 'Cancel', 'titan-zero-trades-front-desk' )),
                                      'vs_text_cost' => $this->get_option_not_empty('vs_text_cost', __( 'Cost', 'titan-zero-trades-front-desk' )),
									  'vs_text_nomore' => $this->get_option_not_empty('vs_text_nomore', __( 'No more slots available.', 'titan-zero-trades-front-desk' )),
                                      'vs_text_nmore' => $this->get_option_not_empty('vs_text_nmore', __( 'Selected time is no longer available. Please select a different time.', 'titan-zero-trades-front-desk' )),

                                      'rep_enable' => $this->get_option('rep_enable', 'no'),
                                      'rep_days' => $this->get_option('rep_days', '1'),
                                      'rep_hour' => $this->get_option('rep_hour', '0'),
                                      'rep_emails' => $this->get_option('rep_emails', ''),
                                      'rep_subject' => $this->get_option('rep_subject', 'Submissions report...'),
                                      'rep_emailformat' => $this->get_option('rep_emailformat', 'text'),
                                      'rep_message' => $this->get_option('rep_message', 'Attached you will find the data from the form submissions.'),

                                      'cv_enable_captcha' => $this->get_option('cv_enable_captcha', TZFD_BOOK_DEFAULT_cv_enable_captcha),
                                      'cv_width' => $this->get_option('cv_width', TZFD_BOOK_DEFAULT_cv_width),
                                      'cv_height' => $this->get_option('cv_height', TZFD_BOOK_DEFAULT_cv_height),
                                      'cv_chars' => $this->get_option('cv_chars', TZFD_BOOK_DEFAULT_cv_chars),
                                      'cv_font' => $this->get_option('cv_font', TZFD_BOOK_DEFAULT_cv_font),
                                      'cv_min_font_size' => $this->get_option('cv_min_font_size', TZFD_BOOK_DEFAULT_cv_min_font_size),
                                      'cv_max_font_size' => $this->get_option('cv_max_font_size', TZFD_BOOK_DEFAULT_cv_max_font_size),
                                      'cv_noise' => $this->get_option('cv_noise', TZFD_BOOK_DEFAULT_cv_noise),
                                      'cv_noise_length' => $this->get_option('cv_noise_length', TZFD_BOOK_DEFAULT_cv_noise_length),
                                      'cv_background' => $this->get_option('cv_background', TZFD_BOOK_DEFAULT_cv_background),
                                      'cv_border' => $this->get_option('cv_border', TZFD_BOOK_DEFAULT_cv_border),
                                      'cv_text_enter_valid_captcha' => $this->get_option('cv_text_enter_valid_captcha', TZFD_BOOK_DEFAULT_cv_text_enter_valid_captcha)
                                     )
                      );
        }
    }


    public function get_status_list() {
        $statuses = array('Approved');
        foreach ($this->paid_statuses as $tz_item)
            $statuses[] = $tz_item;
        return $statuses;
    }


    public function render_status_box( $name, $tz_selected, $displayall = false ) {
        echo '<select name="'.esc_attr($name).'" id="'.esc_attr($name).'">';
        if ($displayall)
            echo '<option value="all"'.($tz_selected == 'all'?' selected':'').'>'.esc_html(__('[All]','titan-zero-trades-front-desk')).'</option>';
        echo '<option value=""'.($tz_selected == ''?' selected':'').'>'.esc_html(__('Approved','titan-zero-trades-front-desk')).'</option>';
        foreach ($this->paid_statuses as $tz_item)
            echo '<option value="'.esc_attr($tz_item).'"'.($tz_selected == $tz_item?' selected':'').'>'.esc_html(titan_zero_front_desk_translate_legacy_value($tz_item)).'</option>';
        echo '</select>';
    }


    /**
     * Tags and attributes of the booking form HTML: the markup in tzfd-public-int.inc.php and what the add-ons add
     * through the tz_fd_the_form filter (password fields, captcha widget and nonce, SMS and WhatsApp
     * buttons, admin status select). Used for deferred script loading, where that HTML has no script or style tags.
     * Anything not listed is dropped. Add-ons that need another tag must enqueue their script and style instead.
     */
    public function booking_form_markup_allowed() {
        $tz_common = array(
            'class' => true, 'id' => true, 'style' => true, 'role' => true, 'data-*' => true,
            'aria-live' => true, 'aria-atomic' => true,
        );
        $tz_field = array_merge( $tz_common, array(
            'type' => true, 'name' => true, 'value' => true, 'size' => true, 'autocomplete' => true,
            'checked' => true, 'disabled' => true, 'required' => true, 'vt' => true,
        ) );
        return array(
            'form'     => array_merge( $tz_common, array( 'name' => true, 'action' => true, 'method' => true, 'enctype' => true ) ),
            'input'    => $tz_field,
            'select'   => array_merge( $tz_common, array( 'name' => true ) ),
            'option'   => array_merge( $tz_common, array( 'value' => true, 'selected' => true ) ),
            'label'    => array_merge( $tz_common, array( 'for' => true ) ),
            'img'      => array_merge( $tz_common, array( 'src' => true, 'alt' => true, 'border' => true ) ),
            'a'        => array_merge( $tz_common, array( 'href' => true, 'target' => true, 'rel' => true ) ),
            'div'      => array_merge( $tz_common, array( 'generated' => true ) ),
            'p'        => $tz_common,
            'fieldset' => $tz_common,
            'legend'   => $tz_common,
            'br'       => array(),
        );
    }

    /**
     * Tags and attributes used by the admin booking lists and the add-on settings forms.
     * Admin markup that comes from add-on code or from filter_list() is passed through
     * wp_kses() with this list before it is echoed. Anything not listed is dropped.
     */
    public function admin_markup_allowed() {
        $tz_global = array(
            'class' => true, 'id' => true, 'style' => true, 'title' => true, 'role' => true,
            'lang' => true, 'tabindex' => true, 'hidden' => true, 'data-*' => true,
            'aria-hidden' => true, 'aria-label' => true, 'aria-labelledby' => true,
            'aria-describedby' => true, 'aria-live' => true,
        );
        $tz_field = array_merge( $tz_global, array(
            'name' => true, 'value' => true, 'type' => true, 'size' => true, 'placeholder' => true,
            'checked' => true, 'selected' => true, 'disabled' => true, 'readonly' => true,
            'required' => true, 'multiple' => true, 'min' => true, 'max' => true, 'step' => true,
            'maxlength' => true, 'autocomplete' => true, 'rows' => true, 'cols' => true,
            'for' => true, 'accept' => true,
        ) );
        $tz_cell = array_merge( $tz_global, array(
            'colspan' => true, 'rowspan' => true, 'align' => true, 'valign' => true,
            'scope' => true, 'width' => true, 'height' => true,
        ) );
        $tz_allowed = array(
            'a'        => array_merge( $tz_global, array( 'href' => true, 'target' => true, 'rel' => true, 'name' => true ) ),
            'img'      => array_merge( $tz_global, array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'hspace' => true, 'vspace' => true, 'align' => true, 'border' => true ) ),
            'form'     => array_merge( $tz_global, array( 'action' => true, 'method' => true, 'enctype' => true, 'name' => true, 'target' => true, 'autocomplete' => true ) ),
            'input'    => $tz_field,
            'select'   => $tz_field,
            'textarea' => $tz_field,
            'option'   => array_merge( $tz_global, array( 'value' => true, 'selected' => true, 'disabled' => true, 'label' => true ) ),
            'optgroup' => array_merge( $tz_global, array( 'label' => true, 'disabled' => true ) ),
            'button'   => array_merge( $tz_global, array( 'type' => true, 'name' => true, 'value' => true, 'disabled' => true ) ),
            'label'    => array_merge( $tz_global, array( 'for' => true ) ),
            'fieldset' => array_merge( $tz_global, array( 'name' => true, 'disabled' => true ) ),
            'td'       => $tz_cell,
            'th'       => $tz_cell,
            'table'    => array_merge( $tz_global, array( 'width' => true, 'align' => true, 'border' => true, 'cellspacing' => true, 'cellpadding' => true ) ),
            'col'      => array_merge( $tz_global, array( 'span' => true, 'width' => true ) ),
            'tr'       => array_merge( $tz_global, array( 'align' => true, 'valign' => true ) ),
            'ol'       => array_merge( $tz_global, array( 'type' => true, 'start' => true ) ),
            'ul'       => array_merge( $tz_global, array( 'type' => true ) ),
            'li'       => array_merge( $tz_global, array( 'value' => true ) ),
            'details'  => array_merge( $tz_global, array( 'open' => true ) ),
        );
        // Text containers and layout tags take only the global attributes.
        foreach ( array( 'div', 'span', 'p', 'em', 'strong', 'b', 'i', 'small', 'code', 'pre', 'nobr', 'br', 'hr',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'summary', 'legend', 'dl', 'dt', 'dd', 'thead', 'tbody', 'tfoot',
            'caption', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'sup', 'sub', 'kbd' ) as $tz_tag ) {
            if ( ! isset( $tz_allowed[ $tz_tag ] ) ) {
                $tz_allowed[ $tz_tag ] = $tz_global;
            }
        }
        return $tz_allowed;
    }


    public function update_status($id, $status, $indexonly = '-1', $company_id = null)
    {
        global $wpdb;
        if ( (!is_int($id) && !is_string($id)) ||
             !filter_var($id, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1))) ||
             !is_string($status) || strlen($status)>250 ||
             1!==preg_match('//u',$status) || preg_match('/[\x00-\x1F\x7F]/',$status) ) { return false; }
        if ( (!is_int($indexonly) && !is_string($indexonly)) ||
             false===filter_var($indexonly,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>-1))) ) { return false; }
        // Enforcement belongs at the mutation layer, not only in a screen that calls it.
        // Background jobs pass the company resolved from their authoritative form row.
        require_once __DIR__ . '/includes/class-tzfd-scoped-store.php';
        $tz_company = null === $company_id ? TZFD_Scoped_Store::company_id() : absint( $company_id );
        if ( $tz_company < 1 ) { return false; }
        // A browser request cannot override the active Workspace company by supplying an ID.
        if ( is_user_logged_in() && TZFD_Scoped_Store::company_id() > 0 &&
             $tz_company !== TZFD_Scoped_Store::company_id() ) { return false; }
        $tz_table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        $tz_events = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i WHERE id=%d AND company_id=%d', $tz_table, $id, $tz_company
        ) );
        if (!is_array($tz_events) || !isset($tz_events[0]->posted_data) || !empty($wpdb->last_error)) { return false; }
        $tz_posted_data = TZFD_Stored_Data::decode_booking($tz_events[0]->posted_data);
        if (!is_array($tz_posted_data) || !isset($tz_posted_data['apps']) || !is_array($tz_posted_data['apps'])) { return false; }
        $countapps = count($tz_posted_data['apps']);
        if (!$countapps || array_keys($tz_posted_data['apps'])!==range(0,$countapps-1) || $indexonly>=$countapps) { return false; }
        $something_changed = false;
        $status_changed = false;
        // Optional cancel reason (rs) from the admin list action. It is read only when the list nonce is valid
        // (the same tz_fd_actions_booking nonce that the list branches check before they call this method).
        // Unslash once, then sanitise before the value is used.
        // A cancellation reason is accepted only from the same POST request as the
        // authorised booking-list mutation. Never merge a GET 'rs' with a POST nonce.
        // Nonce and method are verified first; $_POST['rs'] is read only after that check passes.
        $tz_reason_nonce_ok = 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
            && wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_booking' );
        $tz_reason_read_ok = $tz_reason_nonce_ok && isset( $_POST['rs'] ) && is_string( $_POST['rs'] );
        $tz_cancel_reason = $tz_reason_read_ok ? sanitize_textarea_field( wp_unslash( $_POST['rs'] ) ) : '';
        // Length cap applies to the sanitised reason that would be stored.
        $tz_reason_nonce_ok = $tz_reason_read_ok && strlen( $tz_cancel_reason ) <= 1000;
        $tz_cancel_reason = $tz_reason_nonce_ok ? $tz_cancel_reason : '';
        $tz_reason_is_string = $tz_reason_nonce_ok;
        $tz_has_reason = $tz_reason_nonce_ok && '' !== $tz_cancel_reason;
        for($tz_k=0; $tz_k<$countapps; $tz_k++)
            if (($indexonly == '-1' || $indexonly == $tz_k) && (!isset($tz_posted_data["apps"][$tz_k]["cancelled"]) || $tz_posted_data["apps"][$tz_k]["cancelled"] != $status || $tz_has_reason))
            {
                 if (!isset($tz_posted_data["apps"][$tz_k]["cancelled"]) || $tz_posted_data["apps"][$tz_k]["cancelled"] != $status)
                     $status_changed = true;
                 $tz_posted_data["apps"][$tz_k]["cancelled"] = $status;
                 $tz_posted_data["app_status_".($tz_k+1)] = $status;
                 if ($tz_reason_is_string)
                 {
                     $tz_posted_data["apps"][$tz_k]["cancelreason"] = $tz_cancel_reason;
                     $tz_posted_data["app_cancelreason_".($tz_k+1)] = $tz_posted_data["apps"][$tz_k]["cancelreason"];
                 }
                 $something_changed = true;
            }
        if ($something_changed)
        {
            $tz_posted_data = serialize($tz_posted_data);
            $update_result = $wpdb->update(
                $tz_table,
                array( 'posted_data' => $tz_posted_data ),
                array( 'id' => $id, 'company_id' => $tz_company ),
                array( '%s' ),
                array( '%d', '%d' )
            );
            if ( false === $update_result ) { return false; }
            if ( $update_result > 0 ) { do_action( 'tz_fd_update_status', $id, $status ); }
            if ( $status_changed && $update_result > 0 ) {
                do_action( 'titan_zero_front_desk_booking_status_persisted', $id, $status, $indexonly );
            }
            return $update_result > 0;
        }
        return false;
    }


    /* Using timezone from WordPress settings */
    public function localtimezone_strtotime( $datestring ) {
        $timezone_string = get_option('timezone_string');
        $gmt_offset = get_option('gmt_offset', 0);
        if (empty($timezone_string))
        {
            if ($gmt_offset == 0)
                $timezone_string = 'UTC';
            else
            {
                $timezone_string = $gmt_offset;
                if(substr($gmt_offset, 0, 1) != "-" && substr($gmt_offset, 0, 1) != "+" && substr($gmt_offset, 0, 1) != "U")
                    $timezone_string = "+" . $gmt_offset;
            }
        }
        try
        {
            $result = new DateTime($datestring, new DateTimeZone($timezone_string));
            return strtotime($result->format('Y-m-d H:i:s'));
        }
        catch (Exception $e)
        {
            return strtotime($datestring);
        }
    }


    /* Filter for placing the item into the contents */
    public function filter_list( $atts ) {
        global $wpdb;
        // Booking lists show customer details, so the shortcode is for administrators only.
        if ( ! current_user_can( 'tz_fd_access' ) ) { return ''; }
        extract( shortcode_atts( array(
	    	'calendar' => '',  // accepts comma separated IDs
	    	'fields' => 'DATE,TIME,email',
	    	'from' => "today",
	    	'to' => "today +30 days",
            'paidonly' => "",
            'status' => "notcancelled",   // "all" means all statuses
            'service' => "",
            'email' => "",
            'onlycurrentuser' => "",
            'maxitems' => "",
            'limit' => "10000"
	    ), $atts ) );

        if ( !is_admin() )
        {
            wp_enqueue_style('tzfdapp-publicstyle', plugins_url('css/stylepublic.css', __FILE__), array(), '1.0.0');
        }

        if (strtolower($status) == 'approved')
            $status = '';

        ob_start();

        if ($this->get_option('date_format', 'mm/dd/yy') == 'dd/mm/yy')
        {
            $from = str_replace('/','.',$from);
            $to = str_replace('/','.',$to);
        }

        // calculate dates
        $from = gmdate("Y-m-d",$this->localtimezone_strtotime($from));
        $to = gmdate("Y-m-d",$this->localtimezone_strtotime($to));
        $to_query = gmdate("Y-m-d",$this->localtimezone_strtotime($to." +1 day"));




        $tz_table_name = $wpdb->prefix . TZFD_Tables::BOOKINGS;

        // Each optional filter is a pair of placeholders. A flag of 0 switches the filter off, so its clause is always true.
        // The query is one literal string, which $wpdb->prepare() can check.
        $tz_only_current = 0;
        $tz_current_id   = 0;
        if ( strtolower( $onlycurrentuser ) === 'yes' || strtolower( $onlycurrentuser ) === 'true' ) {
            $current_user      = wp_get_current_user();
            $tz_only_current = 1;
            // No logged-in user: -1 matches no booking, the same result as the old "1=0" clause.
            $tz_current_id   = $current_user->ID ? (int) $current_user->ID : -1;
        }

        $tz_email_filter = ( $email !== '' ) ? 1 : 0;

        $cal_ids      = array();
        $calendar_arr = explode( ',', $calendar );

        foreach ( $calendar_arr as $cal ) {
            $cal_clean = trim( $cal );
            if ( $cal_clean ) {
                $cal_int   = intval( $cal_clean );
                $cal_ids[] = $cal_int;
                $this->setId( $cal_int );
            }
        }

        // An empty list means no calendar filter. FIND_IN_SET matches the same ids as the old IN (...) list.
        $tz_calendar_list = implode( ',', $cal_ids );

        $tz_selection = array();

        $tz_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT notifyto, posted_data, data, formid, id, time FROM %i WHERE notifyto <> %s AND (%d = 0 OR whoadded = %d) AND (%d = 0 OR notifyto = %s) AND (%s = '' OR FIND_IN_SET(formid, %s)) AND time <= %s ORDER BY time DESC LIMIT 0, %d",
                $tz_table_name,
                $this->blocked_by_admin_indicator,
                $tz_only_current,
                $tz_current_id,
                $tz_email_filter,
                (string) $email,
                $tz_calendar_list,
                $tz_calendar_list,
                $to_query,
                intval( $limit )
            )
        );

        foreach($tz_rows as $tz_item)
        {
            $tz_data = TZFD_Stored_Data::decode_booking($tz_item->posted_data);
            if (null === $tz_data || !isset($tz_data["apps"])) { continue; }
            if (!$paidonly || (!empty($tz_data['paid']) && $tz_data['paid']))
            {
                $appindex = 0;
                if (is_array($tz_data["apps"]))
                    foreach($tz_data["apps"] as $tz_app)
                    {
                        $appindex++;
                        if ($tz_app["date"] >= $from && $tz_app["date"] <= $to &&
                           ($status == 'notcancelled' || $status == 'all' || $status == $tz_app["cancelled"]) &&
                           ($status != 'notcancelled' || ('Cancelled by customer' != $tz_app["cancelled"] && 'Cancelled' != $tz_app["cancelled"]) )
                           )
                        {
                            if ($service == '' || $service == $tz_app["service"])
                                $tz_selection[] = array(
                                                     $tz_app["date"]." ".$tz_app["slot"],
                                                     $tz_app["date"],
                                                     str_replace("/","-",$tz_app["slot"]),
                                                     $tz_data,
                                                     sanitize_email($tz_item->notifyto),
                                                     $tz_item->data,
                                                     $tz_app["cancelled"],
                                                     $tz_app["service"],
                                                     $appindex,                       // 8
                                                     $tz_item->formid,                   // 9
                                                     $tz_app["quant"],              // 10
                                                     $tz_item->time,                   // 11
                                                     $tz_item->id                   // 12                                                     
                                                    );
                        }
                    }
            }
        }

        // order time-slots
        if ( !function_exists( 'tzfdbkfastsortfn' ) )
        {
            function tzfdbkfastsortfn( $a, $b ) { return ( $a[0] > $b[0] ? 1 : -1 ); }
        }
        usort( $tz_selection, "tzfdbkfastsortfn" );

        // clean fields IDs
        $fields = explode(",",trim($fields));
        for($tz_j=0; $tz_j<count($fields); $tz_j++)
            $fields[$tz_j] = strtolower(trim($fields[$tz_j]));

        // print table
        if (!count($tz_selection))
        {
            echo '<div class="tz_fd_noapps">';
            if (class_exists('TZFD_Local_Brand')) { TZFD_Local_Brand::empty_state('search',__('No appointments found with the selected filters','titan-zero-trades-front-desk')); } else { echo esc_html(__('No appointments found with the selected filters','titan-zero-trades-front-desk')); }
            echo '</div>';
        }
        else for($tz_i=0; $tz_i<count($tz_selection) && ($maxitems == '' || $tz_i < $maxitems); $tz_i++)
        {
            echo '<div class="tz_fd_app_no_wrap">';
            for($tz_j=0; $tz_j<count($fields); $tz_j++)
            {
                echo '<div class="tz_fd_field_'.intval($tz_j).($tz_selection[$tz_i][6]!=''?' tz_fd_cancelled':'').'">';
                switch ($fields[$tz_j]) {
                    case 'rownumber':
                        echo intval($tz_i)+1;
                        break;
                    case 'weekday':
                        echo esc_html(ucfirst(titan_zero_front_desk_translate_legacy_value(gmdate('l',strtotime($tz_selection[$tz_i][1])))));
                        break;
                    case 'date':
                        echo esc_html($this->format_date($tz_selection[$tz_i][1]));
                        break;
                    case 'time':
                        echo esc_html($tz_selection[$tz_i][2]);
                        break;
                    case 'email':
                        $tz_mail = is_scalar( $tz_selection[$tz_i][4] ?? null ) ? sanitize_email( (string) $tz_selection[$tz_i][4] ) : '';
                        if ( $tz_mail && is_email( $tz_mail ) ) {
                            echo '<a href="' . esc_url( 'mailto:' . $tz_mail ) . '">' . esc_html( $tz_mail ) . '</a>&nbsp;';
                        }
                        break;
                    case 'service':
                        echo esc_html($tz_selection[$tz_i][7])."&nbsp;";
                        break;
                    case 'quantity':
                        echo esc_html($tz_selection[$tz_i][10])."&nbsp;";
                        break;
                    case 'cancelled':
                        if ($tz_selection[$tz_i][6] == '')
                            echo esc_html(__('Approved','titan-zero-trades-front-desk'));
                        else
                            echo esc_html(titan_zero_front_desk_translate_legacy_value($tz_selection[$tz_i][6]));
                        echo '&nbsp;';
                        break;
                    case 'data':
                        echo esc_html(substr($tz_selection[$tz_i][5],strpos($tz_selection[$tz_i][5],"\n\n")+2));
                        break;
                    case 'paid':
                        echo esc_html(( (!empty($tz_selection[$tz_i][3]['paid']) && $tz_selection[$tz_i][3]['paid'])?__('Yes','titan-zero-trades-front-desk'):'&nbsp;'));
                        break;
                    case 'options':
                        if ( is_admin() && $this->check_current_user_access( intval( $tz_selection[$tz_i][9] ) ) )
                        {
                            echo '<div style="width:100px; zoom:75%; overflow:none">';                            
                            ?><div style="clear:both;"><nobr><?php $this->render_status_box('sb'.intval($tz_selection[$tz_i][12]).'_'.intval($tz_selection[$tz_i][8]), $tz_selection[$tz_i][6]); ?><br><input style="float:left" class="button tzfd-ups-item" type="button" name="calups_<?php echo intval($tz_selection[$tz_i][12]); ?>" data-tzfd-id="<?php echo intval($tz_selection[$tz_i][12]); ?>" data-tzfd-item="<?php echo intval($tz_selection[$tz_i][8]); ?>" value="<?php esc_html_e('Update Status','titan-zero-trades-front-desk'); ?>" /></nobr></div><?php
                            echo '</div>';
                        }
                        else
                            echo "&nbsp;";
                        break;                         
                    default:
                        $tz_cell_value = isset($tz_selection[$tz_i][3][$fields[$tz_j]]) ? $tz_selection[$tz_i][3][$fields[$tz_j]] : '';
                        if (is_array($tz_cell_value))
                            echo esc_html(implode(",",$tz_cell_value));
                        else
                            echo esc_html(($tz_cell_value==''?'&nbsp;':$tz_cell_value)."&nbsp;");
                }
                echo '</div>';
            }
            echo '</div>';
            echo '<div class="tz_fd_app_break"></div>';
        }
        $tz_nonce = wp_create_nonce( 'tz_fd_actions_booking' );
        if ( is_admin() && current_user_can('tz_fd_access') ) {
            // The Update Status buttons (data-tzfd-id, data-tzfd-item) are wired by js/inline/m1-upsitem.js.
            wp_enqueue_script( 'tzfd-upsitem', plugins_url( 'js/inline/m1-upsitem.js', __FILE__ ), array(), '1.0.0', true );
            wp_localize_script( 'tzfd-upsitem', 'tzfdUpsItem', array(
                'page'   => $this->menu_parameter,
                'anonce' => $tz_nonce,
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only value, cast to int below.
                'cal'    => intval( tzfd_request_text( 'get', "cal" ) ),
            ) );
        }
        $buffered_contents = ob_get_contents();
        ob_end_clean();
        // Shortcode output (TZFD_FRONT_DESK_LIST): admin list markup only, so it goes through the admin allow-list.
        return wp_kses( $buffered_contents, $this->admin_markup_allowed() );
    }
    


    /* output of the booking form */
    public function output_filter_content( $atts ) {
        $tz_form_html = $this->build_booking_form( $atts );
        echo wp_kses( $tz_form_html, $this->booking_form_markup_allowed() );
    }


    /** Shortcode and block callback: only allowed form markup is returned; assets are enqueued separately. */
    public function filter_content( $atts ) {
        $tz_form_html = $this->build_booking_form( $atts );
        return wp_kses( $tz_form_html, $this->booking_form_markup_allowed() );
    }


    /* Builds the booking form HTML. Returns unescaped markup; callers escape it (filter_content, output_filter_content). */
    private function build_booking_form( $atts ) {
        global $wpdb;
        extract( shortcode_atts( array(
    		                           'id'       => '',
    		                           'calendar' => '',
    	                        ), $atts ) );
        // [TZFD_FRONT_DESK calendar="ID"] from the setup wizard. An ID that is not a calendar of this company is ignored, so the default form renders.
        if ( '' === $id && is_scalar( $calendar ) && '' !== (string) $calendar ) {
            $tzfd_calendar_id      = absint( $calendar );
            $tzfd_calendar_company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
            if ( $tzfd_calendar_id > 0 && $tzfd_calendar_company > 0 &&
                (int) TZFD_Scoped_Store::company_for_form( $tzfd_calendar_id ) === $tzfd_calendar_company ) {
                $id = (string) $tzfd_calendar_id;
            }
        }
        // Shortcode attributes are public input. Never allow a cross-business
        // calendar ID (or a malformed value coerced to a different ID) to render.
        if ( '' !== $id ) {
            if ( ( ! is_string( $id ) && ! is_int( $id ) ) ||
                ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $id ) ) {
                return '';
            }
            $tzfd_public_form_id = absint( $id );
            $tzfd_public_company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
            if ( $tzfd_public_company < 1 ||
                (int) TZFD_Scoped_Store::company_for_form( $tzfd_public_form_id ) !== $tzfd_public_company ) {
                return '';
            }
            $this->item = $tzfd_public_form_id;
        }

    	/**
    	 * Filters applied before generate the form,
    	 * is passed as parameter an array with the forms attributes, and return the list of attributes
    	 */
        $atts = apply_filters( 'tz_fd_pre_form',  $atts );

        ob_start();
        $this->insert_public_item();
        $buffered_contents = ob_get_contents();
        ob_end_clean();

	    /**
	     * Filters applied after generating the form. Asset files are enqueued separately through WordPress.
	     */
	    $buffered_contents = apply_filters( 'tz_fd_the_form', $buffered_contents,  $this->item );

        return $buffered_contents;
    }


    function insert_public_item() {
        global $wpdb;

        // Initializing the $form_counter
        if(!isset($GLOBALS['titan_zero_front_desk_form_sequence_number'])) $GLOBALS['titan_zero_front_desk_form_sequence_number'] = 0;
        $GLOBALS['titan_zero_front_desk_form_sequence_number']++;
        $this->print_counter = $GLOBALS['titan_zero_front_desk_form_sequence_number']; // Current form

        $pageof_label = $this->get_option('vs_text_pageof', 'Page {0} of {0}');
        $pageof_label = ($pageof_label==''?'Page {0} of {0}':$pageof_label);
        $previous_label = $this->get_option('vs_text_previousbtn', 'Previous');
        $previous_label = ($previous_label==''?'Previous':$previous_label);
        $next_label = $this->get_option('vs_text_nextbtn', 'Next');
        $next_label = ($next_label==''?'Next':$next_label);

        $calendar_language = $this->get_option('calendar_language','');
        if ($calendar_language == '') $calendar_language = $this->autodetect_language();

        // Front-end scripts and styles are always enqueued; never print assets inside shortcode content.
        wp_enqueue_style('tzfdapp-calendarstyle', plugins_url('css/cupertino/calendar.css', __FILE__), array(), '1.0.0');
            wp_enqueue_style('tzfdapp-publicstyle', plugins_url('css/stylepublic.css', __FILE__), array(), '1.0.0');
            wp_enqueue_style('wp-jquery-ui-dialog');

            $has_lang_file = false;
            if ($calendar_language != '' && file_exists(dirname( __FILE__ ).'/js/languages/jquery.ui.datepicker-'.$calendar_language.'.js'))
            {
                $has_lang_file = true;
                wp_enqueue_script($this->prefix.'_language_file', plugins_url('js/languages/jquery.ui.datepicker-'.$calendar_language.'.js', __FILE__), array("jquery","jquery-ui-core","jquery-ui-sortable","jquery-ui-tabs","jquery-ui-droppable","jquery-ui-button","jquery-ui-datepicker"), '1.0.0', true);
            }

            $js_dependencies = array("jquery","jquery-ui-core","jquery-ui-datepicker","jquery-ui-widget","jquery-ui-position","jquery-ui-tooltip","jquery-ui-dialog");
            if ($has_lang_file)
                $js_dependencies[] = $this->prefix.'_language_file';
            
            wp_enqueue_script($this->prefix.'_validatejs'.$this->print_counter, $this->fixurl($this->get_site_url( false ),'tz_fd_resources=validatejs&cal='.$this->item.'&counter='.$this->print_counter), array('jquery'), '1.0.0', true);
            $js_dependencies[] = $this->prefix.'_validatejs'.$this->print_counter;
            
            
            if (defined("TZFD_DYNAMIC_LOADING"))
            {
                wp_enqueue_script( $this->prefix.'_builder_script',
                     $this->fixurl($this->get_site_url( false ), 'tz_fd_resources=public'), $js_dependencies, '1.0.0', true );
            }
            else                
            {
                wp_enqueue_script( $this->prefix.'_builder_script',
                     plugins_url('js/tzfdform-public.js', __FILE__), $js_dependencies, (string) filemtime(__DIR__.'/js/tzfdform-public.js'), true );
            }
            
            


            wp_localize_script($this->prefix.'_builder_script', $this->prefix.'_tz_fd_form_config'.('_'.$this->print_counter), array('obj' =>
            '{"pub":true,"identifier":"'.('_'.$this->print_counter).'","messages": {
                                    "required": "'.str_replace(array('"'),array('\\"'),titan_zero_front_desk_translate_legacy_value($this->get_option('vs_text_is_required', TZFD_BOOK_DEFAULT_vs_text_is_required))).'",
                                    "maxapp": "'.str_replace(array('"'),array('\\"'),titan_zero_front_desk_translate_legacy_value($this->get_option('vs_text_maxapp', TZFD_BOOK_DEFAULT_vs_text_maxapp))).'",
                                    "language": "'.str_replace(array('"'),array('\\"'),$calendar_language).'",
                                    "date_format": "'.str_replace(array('"'),array('\\"'),$this->get_option('date_format', 'mm/dd/yy')).'",
                                    "email": "'.str_replace(array('"'),array('\\"'),titan_zero_front_desk_translate_legacy_value($this->get_option('vs_text_is_email', TZFD_BOOK_DEFAULT_vs_text_is_email))).'",
                                    "datemmddyyyy": "'.str_replace(array('"'),array('\\"'),titan_zero_front_desk_translate_legacy_value($this->get_option('vs_text_datemmddyyyy', TZFD_BOOK_DEFAULT_vs_text_datemmddyyyy))).'",
                                    "dateddmmyyyy": "'.str_replace(array('"'),array('\\"'),titan_zero_front_desk_translate_legacy_value($this->get_option('vs_text_dateddmmyyyy', TZFD_BOOK_DEFAULT_vs_text_dateddmmyyyy))).'",
                                    "number": "'.str_replace(array('"'),array('\\"'),titan_zero_front_desk_translate_legacy_value($this->get_option('vs_text_number', TZFD_BOOK_DEFAULT_vs_text_number))).'",
                                    "digits": "'.str_replace(array('"'),array('\\"'),titan_zero_front_desk_translate_legacy_value($this->get_option('vs_text_digits', TZFD_BOOK_DEFAULT_vs_text_digits))).'",
                                    "max": "'.str_replace(array('"'),array('\\"'),titan_zero_front_desk_translate_legacy_value($this->get_option('vs_text_max', TZFD_BOOK_DEFAULT_vs_text_max))).'",
                                    "min": "'.str_replace(array('"'),array('\\"'),titan_zero_front_desk_translate_legacy_value($this->get_option('vs_text_min', TZFD_BOOK_DEFAULT_vs_text_min))).'",
                                    "maxlength": "'.str_replace(array('"'),array('\\"'),__('Please enter no more than {0} characters.','titan-zero-trades-front-desk')).'",
                                    "minlength": "'.str_replace(array('"'),array('\\"'),__('Please enter at least {0} characters.','titan-zero-trades-front-desk')).'",
    	                    	    "previous": "'.str_replace(array('"'),array('\\"'),$previous_label).'",
    	                    	    "next": "'.str_replace(array('"'),array('\\"'),$next_label).'",
    	                    	    "pageof": "'.str_replace(array('"'),array('\\"'),$pageof_label).'"
            	                }}'
            ));

        $button_label = $this->get_option('vs_text_submitbtn', 'Submit');
        $button_label = ($button_label==''?'Submit':$button_label);


        // START:: code to load form settings
        $tz_raw_form_str = str_replace("\r"," ",str_replace("\n"," ",$this->cleanJSON($this->translate_json($this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure)))));

        $tz_form_data = json_decode( $tz_raw_form_str );
        if( is_null( $tz_form_data ) ){
            // Retain the legacy escaped-JSON recovery without depending on the removed JSON class.
            $tz_form_data = json_decode( stripslashes( $tz_raw_form_str ) );
        }
        if ( ! is_array( $tz_form_data ) ) {
            echo esc_html__( 'This booking form is temporarily unavailable. Please contact the site owner.', 'titan-zero-trades-front-desk' );
            return;
        }

        $tz_template_style = '';
        if( !is_null( $tz_form_data ) )
        {
        	if( !empty( $tz_form_data[ 0 ] ) )
        	{
        		foreach( $tz_form_data[ 0 ] as $tz_key => $object )
        		{
        			if ($object->ftype == 'fcheck' || $object->ftype == 'fradio' || $object->ftype == 'fdropdown')
        			{
        			    if ( isset( $object->choices ) && is_array( $object->choices ) ) {
        			        foreach ( $object->choices as $ki => $tz_choice_item ) {
        			            if ( is_string( $tz_choice_item ) ) {
        			                $object->choices[ $ki ] = str_replace('@', TZFD_BOOK_REP_ARR, $tz_choice_item);
        			            }
        			        }
        			    }
        			    $tz_form_data[ 0 ][ $tz_key ] = $object;
        				$tz_raw_form_str = wp_json_encode( $tz_form_data );
        			}
                    else if ($object->ftype == 'fapp')
                    {
                        if (is_admin() && defined('TZFD_ADMIN_BOOKING_MULTIPLE_SERVICES') && TZFD_ADMIN_BOOKING_MULTIPLE_SERVICES) {
                            $object->maxNumberOfApp = 0;
                            if (isset($object->title) && is_string($object->title) && preg_match('/^appointments?$/i', trim($object->title))) {
                                $object->title = __('Choose service', 'titan-zero-trades-front-desk');
                            }
                        }
                        $object->csslayout = apply_filters ('tz_fd_csslayout', $object->csslayout, $this->item);
                        if (!defined('TZFD_HOUR_BLOCK_TIMES')) {
                            try { $object->titan_zero_front_desk_calendar = TZFD_Calendar::site_policy($object,$this->get_option('date_format','mm/dd/yy')); }
                            catch (InvalidArgumentException $error) { $object->titan_zero_front_desk_calendar = array('version'=>0); }
                        }
                    }

                    if (defined('TZFD_HOUR_BLOCK_TIMES'))
                    {
                        if ($object->ftype != 'fapp')
                        {
                            $object->csslayout = 'donotdisplayfield';
                            if (property_exists($object, 'required') && $object->required)
                                $object->required = false;
                            if (!empty($object->minlength))
                                $object->minlength = '';
                            if (!empty($object->maxlength))
                                $object->maxlength = '';
                            if (property_exists($object, 'equalTo') && $object->equalTo)
                                $object->equalTo = '';
                            $object->ftype = 'ftext';
                        }
                        else
                        {
                            $object->maxNumberOfApp = 9999;
                            $object->showQuantity = 1;
                        }
                        $tz_form_data[ 0 ][ $tz_key ] = $object;
                        //$tz_raw_form_str = wp_json_encode( $tz_form_data );
                    }
                    $tz_raw_form_str = wp_json_encode( $tz_form_data );
        		}
        	}
            $tz_form_template = '';
        	if( isset( $tz_form_data[ 1 ] ) && isset( $tz_form_data[ 1 ][ 0 ] ) && isset( $tz_form_data[ 1 ][ 0 ]->formtemplate ) )
        	{
        		$tz_templatelist = $this->available_templates();
                $tz_form_template = substr(md5($tz_form_data[ 1 ][ 0 ]->formtemplate),0,10);
        		if(  isset( $tz_templatelist[ $tz_form_data[ 1 ][ 0 ]->formtemplate ] ) )
                {
                    $tz_template_style = 'tzfd-template-'.$tz_form_template;
                    wp_enqueue_style( $tz_template_style, $tz_templatelist[ $tz_form_data[ 1 ][ 0 ]->formtemplate ][ 'file' ], array(), '1.0.0' );
                }
        	}
            
        }

        $tz_raw_form_str = str_replace('"','&quot;',esc_attr($tz_raw_form_str));
        // END:: code to load form settings

        if (!defined('TZFD_AUTH_INCLUDE')) define('TZFD_AUTH_INCLUDE',true);
        include_once $this->part_path( 'tzfd-public-int.inc.php' );
        // Public scripts and styles remain enqueued for WordPress to print in wp_head/wp_footer.
        // $this->print_counter++;
    }


    public function getValidateScript() {
        
        ob_start();        
        
        ?>
         var tz_fd_hour_cancel_label = '<?php echo esc_js( titan_zero_front_desk_translate_legacy_value( $this->get_option_not_empty('vs_text_cancel', __( 'Cancel', 'titan-zero-trades-front-desk' )) ) ); ?>';
         var tz_fd_hour_close_label = '<?php echo esc_js( __( 'Close', 'titan-zero-trades-front-desk' ) ); ?>';
         var tz_fd_hour_quantity_label = '<?php echo esc_js( titan_zero_front_desk_translate_legacy_value( $this->get_option_not_empty('vs_text_quantity', __( 'Quantity', 'titan-zero-trades-front-desk' )) ) ); ?>';
         var tz_fd_hour_cost_label = '<?php echo esc_js( titan_zero_front_desk_translate_legacy_value( $this->get_option_not_empty('vs_text_cost', __( 'Cost', 'titan-zero-trades-front-desk' )) ) ); ?>';
         var tz_fd_hour_overlapping_label = '<?php echo esc_js( titan_zero_front_desk_translate_legacy_value( $this->get_option_not_empty('vs_text_nmore', __( 'Selected time is no longer available. Please select a different time.', 'titan-zero-trades-front-desk' )) ) ); ?>';
		 var tz_fd_hour_nomore_label = '<?php echo esc_js( titan_zero_front_desk_translate_legacy_value( $this->get_option_not_empty('vs_text_nomore', __( 'No more slots available.', 'titan-zero-trades-front-desk' )) ) ); ?>';
         var tz_fd_hour_avoid_overlapping = 0;
         var tz_fd_hboverbooking_handler<?php echo esc_html($this->print_counter-1); ?> = false;
         function tz_fd_frontdesk_pform_doValidate<?php echo '_'.esc_html($this->print_counter); ?>(form)
         {   
            var counteridn = <?php echo esc_html($this->print_counter); ?>;         
            var counterid = "_"+counteridn;
            try { if (form.tz_fd_frontdesk_pform_status.value == '1') return false; } catch (e) {}
            $dexQuery = jQuery.noConflict();
            try { form.tz_fd_ref_page.value = document.location; } catch (e) {}
            $dexQuery = jQuery.noConflict();<?php if (!is_admin() && $this->get_option('cv_enable_captcha', TZFD_BOOK_DEFAULT_cv_enable_captcha) != 'false' && function_exists('imagecreatetruecolor')) { ?>
            var result = '';
            try {
            if (!tz_fd_hboverbooking_handler<?php echo esc_html($this->print_counter-1); ?>) {
            if (form.hdcaptcha_tz_fd_frontdesk_post.value == '') { setTimeout( "tz_fd_frontdesk_cerror"+counterid+"()", 100); return false; }
            var result = $dexQuery.ajax({ type: "GET", url: "<?php echo esc_js($this->get_site_url()); ?>?ps="+counterid+"&tz_fd_frontdesk_pform_process=2&tz_fd_frontdesk_id=<?php echo intval($this->item); ?>&inAdmin=1&ps="+counterid+"&hdcaptcha_tz_fd_frontdesk_post="+encodeURIComponent(form.hdcaptcha_tz_fd_frontdesk_post.value), async: false }).responseText;
            }
            } catch (e) {result='';}
            if (!tz_fd_hboverbooking_handler<?php echo esc_html($this->print_counter-1); ?> && result.indexOf("captchafailed") != -1) {
                $dexQuery("#captchaimg"+counterid).attr('src', $dexQuery("#captchaimg"+counterid).attr('src')+'&'+Math.floor((Math.random() * 99999) + 1));
                setTimeout( "tz_fd_frontdesk_cerror"+counterid+"()", 100);
                return false;
            } else <?php } ?>
            {
                var tz_fd_efb_error = 0; 
                $dexQuery("#"+form.id).find(".tz_fd_efb_error").each(function(index){
                if ($dexQuery(this).css("display")!="none")
                    tz_fd_efb_error++;
                });
                if (tz_fd_efb_error==0)
                {
                    if (!tz_fd_hboverbooking_handler<?php echo esc_html($this->print_counter-1); ?>)
                    {
                        try { form.tz_fd_frontdesk_pform_status.value = '1';	 } catch (e) {}
                        tzfdhbblink<?php echo esc_html('_'.$this->print_counter); ?>(".pbSubmit:visible", form);
                        $dexQuery("#"+form.id).find(".avoid_overlapping_before").not(".ignore,.ignorepb").removeClass("avoid_overlapping_before").removeClass("valid").addClass("avoid_overlapping");
                        tz_fd_hour_avoid_overlapping = 1;
                        try {
                            $dexQuery("#"+form.id).find(".avoid_overlapping").valid();
                        } catch (e) { tz_fd_hour_avoid_overlapping = 0; }
                        function check_tz_fd_hour_avoid_overlapping(){
		                    if (tz_fd_hour_avoid_overlapping>0)
		                        setTimeout(check_tz_fd_hour_avoid_overlapping,100);
		                    else
		                    {
                                var tz_fd_efb_error = 0;
                                $dexQuery("#"+form.id).find(".tz_fd_efb_error").each(function(index){
                                    if ($dexQuery(this).css("display")!="none")
                                        tz_fd_efb_error++;
                                    });
                                try { form.tz_fd_frontdesk_pform_status.value = '0';	 } catch (e) {}
                                if (tz_fd_efb_error==0)
                                {
                                    tz_fd_hboverbooking_handler<?php echo esc_html($this->print_counter-1); ?> = true;
                                    if (tz_fd_frontdesk_pform_doValidate<?php echo esc_html('_'.$this->print_counter); ?>(form))
                                    {
                                        $dexQuery( ".pbSubmit" ).unbind();
                                        if ($dexQuery( ".pbSubmit" ).hasClass("nofirst"))
                                            return false;
                                        $dexQuery( ".pbSubmit" ).addClass("nofirst");
                                        form.submit();
                                    }
                                }
		                    }
		                }
		                check_tz_fd_hour_avoid_overlapping();
                        return false;
                    }
                    <?php
                    /**
				     * Action called before insert the data into database.
				     * To the function are passed two parameters: the array with submitted data, and the number of form in the page.
				     */

				    do_action( 'tz_fd_script_after_validation', $this->print_counter, $this->item );

	                ?>
	        		$dexQuery("#"+form.id).find( '.ignore' ).closest( '.fields' ).remove();
	        	}
	        	document.getElementById("form_structure"+counterid).value = '';
	        	document.getElementById("refpage"+counterid).value = document.location;
                try {
                    if (tz_fd_efb_error==0)
                    {
                        form.tz_fd_frontdesk_pform_status.value = '1';
                        tzfdhbblink<?php echo esc_html('_'.$this->print_counter); ?>(".pbSubmit", form);
                    }
                } catch (e) {}
                return true;
            }
         }
         function tzfdhbblink<?php echo esc_html('_'.$this->print_counter); ?>(selector, form){
             try {
                 $dexQuery = jQuery.noConflict();
                 $dexQuery(selector).fadeOut(1000, function(){
                     $dexQuery(this).fadeIn(1000, function(){
                             try {
                                 if (form.tz_fd_frontdesk_pform_status.value != '0')
                                     tzfdhbblink<?php echo esc_html('_'.$this->print_counter); ?>(this, form);
                             } catch (e) { }
                     });
                 });
             } catch (e) {}
         }
         function tz_fd_frontdesk_cerror<?php echo esc_html('_'.$this->print_counter); ?>(){$dexQuery = jQuery.noConflict();$dexQuery("#hdcaptcha_error<?php echo esc_html('_'.$this->print_counter); ?>").css("display","block");$dexQuery.tzfdform.markCaptchaError($dexQuery("#hdcaptcha_tz_fd_frontdesk_post<?php echo esc_html('_'.$this->print_counter); ?>")[0],$dexQuery("#hdcaptcha_error<?php echo esc_html('_'.$this->print_counter); ?>")[0]);}
        <?php
        
        $buffered_contents = ob_get_contents();
        ob_end_clean();
        
        return $buffered_contents;
    }

    /* Code for the admin area */

    public function plugin_page_links( $links ) {
        $settings_link = '<a href="admin.php?page='.TZFD_Admin_Menu::SETTINGS.'">'.__('Settings','titan-zero-trades-front-desk').'</a>';
    	array_unshift($links, $settings_link);
    	return $links;
    }


    public function admin_menu() {
        // No top-level menu and no Settings > Options entry here: Titan Zero Trades: Front Desk owns the one menu (R04).
        // The rest of the Workspace menu (Dashboard, Calendar, Bookings, Services, Addons, Settings)
        // and the Settings tabs are added by TZFD_Admin_Menu. See includes/class-tzfd-admin-menu.php.

    }



    function insert_button() {
        // Form data is provided by the company-scoped selector below. Do not
        // query the entire forms table here merely to construct unused HTML.
        if ( ! is_user_logged_in() ) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor routing.
        wp_enqueue_style( 'wp-jquery-ui-dialog' );
        wp_enqueue_script( 'jquery-ui-dialog' );
        wp_enqueue_script( 'tz_fd_hour_classic_editor', plugins_url( '/js/insertpanel.js', __FILE__ ), array(), '1.0.0', true );

        wp_localize_script( 'tz_fd_hour_classic_editor', 'tz_fd_hour_formsclassic', array(
            'forms' => $this->getThisUserForms(),
            'siteUrl' => get_site_url(),
        ) );
        wp_add_inline_script( 'tz_fd_hour_classic_editor', 'document.addEventListener("click",function(e){var a=e.target.closest?e.target.closest("a.tzfdhbinsertb"):null;if(a){e.preventDefault();if(typeof tz_fd_appointments_fpanel!=="undefined"){tz_fd_appointments_fpanel.open();}}});' );

        print '<a href="#" class="tzfdhbinsertb" title="' . esc_attr__( 'Insert Titan Zero Trades: Front Desk booking form', 'titan-zero-trades-front-desk' ) . '"><img hspace="5" src="' . esc_url( plugins_url( '/images/tz_fd_form.gif', __FILE__ ) ) . '" alt="' . esc_attr__( 'Insert Titan Zero Trades: Front Desk booking form', 'titan-zero-trades-front-desk' ) . '" /></a>';
    }

    function getThisUserForms() {
        require_once __DIR__ . '/includes/class-tzfd-scoped-store.php';
        require_once __DIR__ . '/includes/class-tzfd-access-list.php';
        $forms = array( array(
            'value' => 0,
            'label' => __( '- Please select a booking form -', 'titan-zero-trades-front-desk' ),
        ) );
        $company = TZFD_Scoped_Store::company_id();
        if ( $company < 1 || ! is_user_logged_in() ) { return $forms; }
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id,form_name,tz_fd_user_access FROM %i WHERE company_id = %d ORDER BY form_name',
            $wpdb->prefix . TZFD_Tables::FORMS, $company
        ) );
        if ( ! is_array( $rows ) ) { return $forms; }
        $can_manage = current_user_can( 'tz_fd_access' );
        $user_id = get_current_user_id();
        foreach ( $rows as $row ) {
            if ( ! $can_manage && ! in_array( $user_id, TZFD_Access_List::decode( $row->tz_fd_user_access ), true ) ) {
                continue;
            }
            $forms[] = array(
                'value' => absint( $row->id ),
                'label' => sanitize_text_field( $row->form_name ),
            );
        }
        return $forms;
    }


    /**
     * Returns the full path of one of the plugin's own PHP parts. A missing part stops
     * with a clear message, instead of a blank page. The caller includes the file itself,
     * so the part runs in the caller's variable scope.
     */
    private function part_path( $relative_path ) {
        $path = dirname( __FILE__ ) . '/' . $relative_path;
        if ( is_readable( $path ) ) {
            return $path;
        }
        wp_die( esc_html( sprintf(
            /* translators: %s: file name inside the plugin folder. */
            __( 'Titan Zero Front Desk is incomplete: the file %s is missing. Reinstall the plugin.', 'titan-zero-trades-front-desk' ),
            $relative_path
        ) ) );
    }

    public function settings_page() {
        global $wpdb;
        
        if ($this->get_param("page") === TZFD_Admin_Menu::HELP)
        {
            include_once $this->part_path( 'tzfd-help.inc.php' );
        }
        else if ($this->get_param("page") === TZFD_Admin_Menu::SETTINGS)
        {
            include_once $this->part_path( 'tzfd-settings.inc.php' );
        }
        else if (in_array( $this->get_param("page"), array( TZFD_Admin_Menu::ADDONS, 'tzfd_addons' ), true ))
        {
            include_once $this->part_path( 'tzfd-addons.inc.php' );
        } 
        else if ($this->get_param("cal") || $this->get_param("cal") == '0' || $this->get_param("pwizard") == '1')
        {
            $this->item = intval($this->get_param("cal"));
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
            if (isset($_GET["edit"]) && tzfd_request_text( 'get', "edit" ) == '1')
                include_once $this->part_path( 'tzfd-admin-int.inc.php' );
            else if ($this->get_param("schedule") == '1')
                include_once $this->part_path( 'tzfd-admin-int-schedule.inc.php' );
            else if ($this->get_param("list") == '1')
                include_once $this->part_path( 'tzfd-admin-int-message-list.inc.php' );
            else if ($this->get_param("report") == '1')
                include_once $this->part_path( 'tzfd-admin-int-report.inc.php' );
            else if ($this->get_param("addbk") == '1')
                include_once $this->part_path( 'tzfd-admin-int-add-booking.inc.php' );
            else if ($this->get_param("blocktimes") == '1')
                include_once $this->part_path( 'tzfd-admin-int-block-times.inc.php' );
            else if ($this->get_param("pwizard") == '1')
            {
                if ($this->get_param("cal"))
                    $this->item = intval($this->get_param("cal"));
                include_once $this->part_path( 'tzfd-publish-wizzard.inc.php' );
            }
            else
                include_once $this->part_path( 'tzfd-admin-int.inc.php' );
        }
        else
            include_once $this->part_path( 'tzfd-admin-int-list.inc.php' );
    }


    function gutenberg_block() {
        global $wpdb;
        
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
        if (!isset($_GET["page"]) || substr(tzfd_request_text( 'get', "page" ),0,3) != 'tz_fd_') {

            wp_enqueue_script('tz_fd_hour_gutenberg_editor', plugins_url('/js/block.js', __FILE__), array(), '1.0.0', true);

            wp_enqueue_style('tzfdapp-calendarstyle', plugins_url('css/cupertino/calendar.css', __FILE__), array(), '1.0.0');
            wp_enqueue_style('tzfdapp-publicstyle', plugins_url('css/stylepublic.css', __FILE__), array(), '1.0.0');
            if (defined("TZFD_DYNAMIC_LOADING"))
            {
                wp_enqueue_script( $this->prefix.'_builder_script',
                       $this->fixurl($this->get_site_url( false ),'tz_fd_resources=public'),array("jquery","jquery-ui-core","jquery-ui-datepicker","jquery-ui-widget","jquery-ui-position","jquery-ui-tooltip"), '1.0.0', true );
            }
            else
            {
                wp_enqueue_script( $this->prefix.'_builder_script',
                      plugins_url('js/tzfdform-public.js', __FILE__),array("jquery","jquery-ui-core","jquery-ui-datepicker","jquery-ui-widget","jquery-ui-position","jquery-ui-tooltip"), '1.0.0', true );
            }    

            $forms = $this->getThisUserForms();

            wp_localize_script( 'tz_fd_hour_gutenberg_editor', 'tz_fd_hour_forms', array(
                                'forms' => $forms,
                                'siteUrl' => get_site_url()
                              ) );
        }
                          
    }


public function render_form_admin( $atts ) {
    global $wpdb;

    // 1. Check if formId is set. If not, bail early.
    if ( empty( $atts['formId'] ) ) {
        return esc_html__( 'Please select a booking form.', 'titan-zero-trades-front-desk' );
    }

    // The block attribute can come from the public REST renderer: reject type coercion
    // and verify the form belongs to the current business before rendering it.
    $tzfd_raw_id = $atts['formId'];
    if ( ! is_string( $tzfd_raw_id ) && ! is_int( $tzfd_raw_id ) ) {
        return esc_html__( 'Please select a booking form.', 'titan-zero-trades-front-desk' );
    }
    if ( ! preg_match( '/^[1-9][0-9]{0,17}$/D', (string) $tzfd_raw_id ) ) {
        return esc_html__( 'Please select a booking form.', 'titan-zero-trades-front-desk' );
    }
    $form_id = absint( $tzfd_raw_id );
    $tzfd_company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
    if ( $tzfd_company < 1 || (int) TZFD_Scoped_Store::company_for_form( $form_id ) !== $tzfd_company ) {
        return esc_html__( 'Please select a booking form.', 'titan-zero-trades-front-desk' );
    }

    // The ownership constraint is repeated in SQL to avoid a cross-business race.
    $tz_table_name = $wpdb->prefix . TZFD_Tables::FORMS;
    $tz_myrows = $wpdb->get_results(
        $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND company_id = %d', $tz_table_name, $form_id, $tzfd_company )
    );

    // If the form doesn't exist in the DB, bail early.
    if ( empty( $tz_myrows ) ) {
        return esc_html__( 'Please select a booking form.', 'titan-zero-trades-front-desk' );
    }

    // 3. Determine if we are inside the Gutenberg Editor via the REST API
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
    $is_gutenberg_editor = defined( 'REST_REQUEST' ) && REST_REQUEST && ! empty( $_REQUEST['context'] ) && 'edit' === tzfd_request_text( 'request', 'context' );

    // 4. Handle Frontend Output
    if ( ! $is_gutenberg_editor ) {
        return $this->filter_content( array( 'id' => $form_id ) );
    }

    // 5. Handle Editor Output
    $this->setId( $form_id );

    // Prevent PHP warning if instanceId is missing
    $instance_id = isset( $atts['instanceId'] ) && is_scalar( $atts['instanceId'] )
        ? substr( sanitize_text_field( (string) $atts['instanceId'] ), 0, 80 )
        : (string) wp_rand( 10000, 99999 );
    $escaped_id  = esc_attr( $instance_id );

    // Clean up the form structure string
    $form_structure = esc_attr( $this->get_option( 'form_structure' ) );
    $form_structure = str_replace( array( "\r", "\n" ), '', $form_structure );

    // Use sprintf for a clean, readable HTML template
    return sprintf(
        '<div style="padding: 30px 20px; border: 2px dashed #4a5366; background-color: #232834; color: #a4acba; border-radius: 8px; text-align: center; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen-Sans, Ubuntu, Cantarell, \'Helvetica Neue\', sans-serif;">
            <span style="font-size: 32px; display: block; margin-bottom: 12px;">✅</span>
            <strong style="font-size: 16px; color: #1d2327; display: block; margin-bottom: 8px;">%1$s</strong>
            <span style="font-size: 14px; color: #50575e;">%2$s</span>
        </div>',
        esc_html__( 'OK, booking form selected. Great!', 'titan-zero-trades-front-desk' ),
        esc_html__( 'The appointment booking form will appear here when viewed in the public website.', 'titan-zero-trades-front-desk' )
    );
    /**
    return sprintf(
        '<input type="hidden" name="form_structure%1$s" id="form_structure%1$s" value="%2$s" />
        <fieldset class="tz_fd_gutenberg_editor" disabled>
            <div id="tzfdform">
                <div id="tz_fd_form_%1$s">
                    <div id="formheader_%1$s"></div>
                    <div id="fieldlist_%1$s"></div>
                </div>
            </div>
        </fieldset>',
        $escaped_id,
        $form_structure
    );
    */
}


    function insert_adminScripts( $hook ) {
        
        // Use the admin hook suffix rather than reading request parameters to identify the settings screen.
        if ( is_string( $hook ) && false !== strpos( $hook, 'page_tz_fd_settings' ) ) {
            wp_enqueue_style( 'tzfd-settings-screen', plugins_url( 'css/tzfd-settings.css', __FILE__ ), array(), (string) filemtime( __DIR__ . '/css/tzfd-settings.css' ) );
        }
        
        if ($this->get_param("page") == $this->menu_parameter && $this->get_param("blocktimes") != '1' && $this->get_param("addbk") != '1')
        {


            if (defined("TZFD_DYNAMIC_LOADING"))
            {
                wp_enqueue_script($this->prefix.'_builder_script', $this->get_site_url( true ).'?tz_fd_resources=admin', array("jquery","jquery-ui-core","jquery-ui-sortable","jquery-ui-tabs","jquery-ui-droppable","jquery-ui-button","jquery-ui-datepicker"), '1.0.0', true);
            }
            else
            {
                // Papa Parse 5.7.0 (MIT) provides Papa.parse for CSV import; the builder bundle no longer embeds it.
                wp_enqueue_script($this->prefix.'_papaparse', plugins_url('js/tzfdform-admin-fields/00_jquery.parse.min.js', __FILE__), array(), '5.7.0', true);
                wp_enqueue_script($this->prefix.'_builder_script', plugins_url('js/tzfdform-admin.js', __FILE__), array("jquery","jquery-ui-core","jquery-ui-sortable","jquery-ui-tabs","jquery-ui-droppable","jquery-ui-button","jquery-ui-datepicker", $this->prefix.'_papaparse'), '1.0.0', true);
            }
                
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
            if (isset($_GET["report"]) && tzfd_request_text( 'get', "report" ) == '1') 
            {
                wp_enqueue_script('tzfdstatschart', plugins_url('js/chart.js', __FILE__), array('jquery'), '1.0.0', true);
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
            if (isset($_GET["calendarview"]) && tzfd_request_text( 'get', "calendarview" ) == '1')
            {
                wp_enqueue_script( 'jquery-ui-dialog' );
                
                wp_enqueue_style('tzfd-jquery-schedcalstyle', plugins_url('/tzfd-calendar/css/cupertino/calendar.css', __FILE__), array(), '1.0.0');
                wp_enqueue_style('tzfd-jquery-schedcalstylemv', plugins_url('/tzfd-calendar/css/main.css', __FILE__), array(), '1.0.0');
                
                wp_enqueue_script('underscore');
                wp_enqueue_script('tzfd-schedcal-rrule', plugins_url('/tzfd-calendar/js/rrule.js', __FILE__), array(), '1.0.0', true);  
                wp_enqueue_script('tzfd-schedcal-common', plugins_url('/tzfd-calendar/js/Common.js', __FILE__), array('jquery'), '1.0.0', true);

                if (file_exists(dirname( __FILE__ ).'/tzfd-calendar/language/tz_fd_calendar_lang_'.$this->mv_autodetect_language().'.js'))
                    $langscript = plugins_url('/tzfd-calendar/language/tz_fd_calendar_lang_'.$this->mv_autodetect_language().'.js', __FILE__);
                else
                    $langscript = plugins_url('/tzfd-calendar/language/tz_fd_calendar_lang_en_GB.js', __FILE__);

                wp_enqueue_script('tzfdtslots-schedcal-lang', $langscript, array(), '1.0.0', true);
                // Declare dependencies so the scripts load in the order they need: the calendar reads the
                // language strings and Common.js when it loads; tzfdcalendar.js calls both.
                wp_enqueue_script('tzfdtslots-schedcal-calendar', plugins_url('/tzfd-calendar/js/jquery.calendar.js', __FILE__), array('jquery', 'tzfd-schedcal-common', 'tzfdtslots-schedcal-lang'), '1.0.0', true);
                wp_enqueue_script('tzfdtslots-schedcal-alert', plugins_url('/tzfd-calendar/js/jquery.alert.js', __FILE__), array('jquery'), '1.0.0', true);
                wp_enqueue_script('tzfdtslots-schedcal-tzfdcalendar', plugins_url('/tzfd-calendar/js/tzfdcalendar.js', __FILE__), array('jquery', 'jquery-ui-dialog', 'tzfd-schedcal-rrule', 'tzfd-schedcal-common', 'tzfdtslots-schedcal-lang', 'tzfdtslots-schedcal-calendar', 'tzfdtslots-schedcal-alert'), '1.0.0', true);
            }

            wp_enqueue_style('tzfd-jquery-ui-style', plugins_url('/css/cupertino/jquery-ui-1.8.20.custom.css', __FILE__), array(), '1.0.0');            
            wp_enqueue_style('tzfdapp-style', plugins_url('/css/style.css', __FILE__), array(), '1.0.0');
            wp_enqueue_style('tzfdapp-newadminstyle', plugins_url('/css/newadminlayout.css', __FILE__), array(), (string) filemtime( __DIR__ . '/css/newadminlayout.css' ));
            wp_enqueue_script('tzfd-admin-accessibility', plugins_url('/js/tzfd-admin-accessibility.js', __FILE__), array(), '1.0.0', true);
            $calendar_language = $this->get_option('calendar_language','');
            if ($calendar_language == '') $calendar_language = $this->autodetect_language();
            if ($calendar_language != '')
                wp_enqueue_script($this->prefix.'_language_file', plugins_url('js/languages/jquery.ui.datepicker-'.$calendar_language.'.js', __FILE__), array("jquery","jquery-ui-core","jquery-ui-sortable","jquery-ui-tabs","jquery-ui-droppable","jquery-ui-button","jquery-ui-datepicker"), '1.0.0', true);
        }  else if ($this->get_param("blocktimes") == '1') {                        
            foreach ( array( 'jquery-ui-core','jquery-ui-sortable','jquery-ui-tabs','jquery-ui-droppable','jquery-ui-button','jquery-ui-datepicker' ) as $tz_ui_dep ) { wp_enqueue_script( $tz_ui_dep ); }
            
            
            wp_enqueue_style('tzfdapp-newadminstyle', plugins_url('/css/newadminlayout.css', __FILE__), array(), (string) filemtime( __DIR__ . '/css/newadminlayout.css' ));
        }
        if( 'post.php' != $hook  && 'post-new.php' != $hook )
            return;
        // space to include some script in the post or page areas if needed
    }


    function mv_autodetect_language()
    {
            $basename = '/tzfd-calendar/language/tz_fd_calendar_lang_';
    
            $binfo = str_replace('-','_',get_bloginfo('language'));
    
            $tz_options = array ($binfo,
                              strtolower($binfo),
                              substr(strtolower($binfo),0,2)."_".substr(strtoupper($binfo),strlen(strtoupper($binfo))-2,2),
                              substr(strtolower($binfo),0,2)."_".substr(strtoupper($binfo),0,2),
                              substr(strtolower($binfo),0,2),
                              substr(strtolower($binfo),strlen(strtolower($binfo))-2,2)
                              );
            foreach ($tz_options as $tz_option)
            {
                if (file_exists(dirname( __FILE__ ).$basename.$tz_option.'.js'))
                    return $tz_option;
                $tz_option = str_replace ("-","_", $tz_option);
                if (file_exists(dirname( __FILE__ ).$basename.$tz_option.'.js'))
                    return $tz_option;
            }
            return '';
    }

    function autodetect_language() {
        $basename = '/js/languages/jquery.ui.datepicker-';

        $tz_options = array (get_bloginfo('language'),
                          strtolower(get_bloginfo('language')),
                          substr(strtolower(get_bloginfo('language')),0,2)."-".substr(strtoupper(get_bloginfo('language')),strlen(strtoupper(get_bloginfo('language')))-2,2),
                          substr(strtolower(get_bloginfo('language')),0,2),
                          substr(strtolower(get_bloginfo('language')),strlen(strtolower(get_bloginfo('language')))-2,2)
                          );
        foreach ($tz_options as $tz_option)
        {
            if (file_exists(dirname( __FILE__ ).$basename.$tz_option.'.js'))
                return $tz_option;
            $tz_option = str_replace ("-","_", $tz_option);
            if (file_exists(dirname( __FILE__ ).$basename.$tz_option.'.js'))
                return $tz_option;
        }
        return '';
    }


   public function data_management_pluginsloaded() {
       
       tz_fd_loading_add_ons();

       // Scheduled report sending runs from WP-Cron (see run_scheduled_reports) instead of on every front-end request.
       add_action( 'tz_fd_reports_cron', array( $this, 'run_scheduled_reports' ) );
       if ( ! wp_next_scheduled( 'tz_fd_reports_cron' ) )
           wp_schedule_event( time() + 300, 'hourly', 'tz_fd_reports_cron' );

       // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
       if (isset($_GET["tz_fd_resources"]))
       {
           add_filter( 'trp_allow_tp_to_run', 'tz_fd_hour_tp_disable_filter' );
       }
   }


   function data_management_loaded() {
        global $wpdb;

        $action = $this->get_param('tz_fd_do_action_loaded');
    	if (!$action) return; // go out if the call isn't for this one

        if ( 'wizard' === $action ) {
            // Publishing modifies WordPress posts and must never use an unverified calendar.
            if ( 'POST' !== ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) || ! current_user_can( 'tz_fd_access' ) ) {
                wp_die( esc_html__( 'Not authorized to publish a booking form.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
            }
            $tz_wizard_nonce = tzfd_request_text( 'post', 'anonce' );
            if ( ! is_string( $tz_wizard_nonce ) || ! wp_verify_nonce( $tz_wizard_nonce, 'tz_fd_actions_pwizard' ) ) {
                $this->nonce_failed();
            }
            require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';
            $tz_wizard_id = TZFD_Admin_Request_Ids::positive( tzfd_request_text( 'post', 'tz_fd_hour_id' ) );
            if ( null === $tz_wizard_id || ! $this->check_current_user_access( $tz_wizard_id ) ) {
                wp_die( esc_html__( 'Calendar access denied.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
            }
            $this->item = $tz_wizard_id;
            $tz_publish_to = tzfd_request_text( 'post', 'whereto' );
            if ( ! is_string( $tz_publish_to ) || ! in_array( $tz_publish_to, array( '0', '1', '2', '3' ), true ) ) {
                wp_die( esc_html__( 'Invalid publishing destination.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
            }
            if ( ( '0' === $tz_publish_to || '2' === $tz_publish_to ) && ! current_user_can( 'edit_pages' ) ) {
                wp_die( esc_html__( 'Page editing permission required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
            }
            if ( ( '1' === $tz_publish_to || '3' === $tz_publish_to ) && ! current_user_can( 'edit_posts' ) ) {
                wp_die( esc_html__( 'Post editing permission required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
            }
            $shortcode = '[TZFD_FRONT_DESK  id="'.$this->item .'"]';
            $this->postURL = $this->publish_on( $tz_publish_to, $this->sanitize( (isset($_POST["publishpage"]) ? tzfd_request_text( 'post', "publishpage" ) : '') ), $this->sanitize( (isset($_POST["publishpost"]) ? tzfd_request_text( 'post', "publishpost" ) : '') ), $shortcode, $this->sanitize( (isset($_POST["posttitle"]) ? tzfd_request_text( 'post', "posttitle" ) : '' ) )); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }

        // ...
        echo esc_html__( 'Some unexpected error happened. If you see this error contact the support service at ', 'titan-zero-trades-front-desk' );

        exit();
    }


    private function publish_on( $whereto, $publishpage = '', $publishpost = '', $content = '', $posttitle = 'Booking Form' ) {
        global $wpdb;
        $id = '';
        if ( ! in_array( (string) $whereto, array( '0', '1', '2', '3' ), true ) ) { return ''; }
        if ($whereto == '0' || $whereto =='1') // new page
        {
            $my_post = array(
              'post_title'    => $posttitle,
              'post_type' => ($whereto == '0'?'page':'post'),
              'post_content'  => __( 'This is a <b>preview</b> page, remember to publish it if needed. You can edit the full calendar and form settings into the admin settings page.<br /><br /> ', 'titan-zero-trades-front-desk' ).$content,
              'post_status'   => 'draft'
            );

            // Insert the post into the database
            $id = wp_insert_post( $my_post );
        }
        else
        {
            $id = ($whereto == '2'?$publishpage:$publishpost);
            $post = get_post( $id );
            // Prevent a valid post ID being substituted for a page destination (or vice versa).
            $expected_post_type = '2' === (string) $whereto ? 'page' : 'post';
            if ( ! $post || $post->post_type !== $expected_post_type || ! current_user_can( 'edit_post', $post->ID ) ) { return ''; }
            $pos = strpos($post->post_content,$content);
            if ($pos === false)
            {
                $my_post = array(
                      'ID'           => $id,
                      'post_content' => $content.$post->post_content,
                  );
                // Update the post into the database
                wp_update_post( $my_post );
            }
        }
        return get_permalink( $id );
    }


    function print_tz_fd_calendar_format( $tz_data, $formid = 0, $dataquery = '', $startdate = '', $enddate = '' ) {
        // $tz_data[$tz_k]["d"] - date
        // $tz_data[$tz_k]["h1"] - hour
        // $tz_data[$tz_k]["m1"] - minute
        // $tz_data[$tz_k]["h2"] - hour end
        // $tz_data[$tz_k]["m2"] - minute end
        // $tz_data[$tz_k]["info"] - description

        function tz_fd_js_to_php_time( $jsdate ) {
          $ret = null;
          if(preg_match('@(\d+)/(\d+)/(\d+)\s+(\d+):(\d+)((am|pm)*)@', $jsdate, $matches)==1){
            if ($matches[6]=="pm")
                if ($matches[4]<12)
                    $matches[4] += 12;
            $ret = mktime($matches[4], $matches[5], 0, $matches[1], $matches[2], $matches[3]);
          }else if(preg_match('@(\d+)/(\d+)/(\d+)@', $jsdate, $matches)==1){
            $ret = mktime(0, 0, 0, $matches[1], $matches[2], $matches[3]);
          }
          return $ret;
        }


        function tz_fd_php_to_mysql_time( $phpDate ) {
            return gmdate("Y-m-d H:i:s", $phpDate);
        }


        function tz_fd_php_to_js_time( $phpDate ) {
            return @gmdate("m/d/Y H:i", $phpDate);
        }


        function tz_fd_mysql_to_php_time( $sqlDate ) {
            $a1 = explode (" ",$sqlDate);
            $a2 = explode ("-",$a1[0]);
            $a3 = explode (":",$a1[1]);
            $a3[0] = isset($a3[0]) ? intval($a3[0]) : 0;
            $a3[1] = isset($a3[1]) ? intval($a3[1]) : 0;
            $a3[2] = isset($a3[2]) ? intval($a3[2]) : 0;
            $a2[0] = isset($a2[0]) ? intval($a2[0]) : 0;
            $a2[1] = isset($a2[1]) ? intval($a2[1]) : 0;
            $a2[2] = isset($a2[2]) ? intval($a2[2]) : 0;
            $t = mktime( $a3[0], $a3[1], $a3[2], $a2[1], $a2[2], $a2[0] );
            return $t;
        }

        usort($tz_data, array($this, 'wptsbk_custom_sort') );

        // Only bookings inside the requested range are sent to the calendar.
        $tz_data = array_values( array_filter( $tz_data, function ( $tz_item ) use ( $startdate, $enddate ) {
            return $this->date_in_range( $tz_item['d'] ?? '', $startdate, $enddate );
        } ) );

        $ret = array();
        $ret['events'] = array();
        $ret["issort"] = true;
        $ret['error'] = null;
        $d1 = tz_fd_js_to_php_time( $startdate );
        $d2 = tz_fd_js_to_php_time( $enddate );
        $d1 = mktime(0, 0, 0,  gmdate("m", $d1), gmdate("d", $d1), gmdate("Y", $d1));
        $d2 = mktime(0, 0, 0, gmdate("m", $d2), gmdate("d", $d2), gmdate("Y", $d2))+24*60*60-1;
        $ret["start"] = tz_fd_php_to_js_time($d1);
        $ret["end"] = tz_fd_php_to_js_time($d2);

        $aid = 1;
        foreach ($tz_data as $tz_item)
        {
            $datetime = $tz_item["d"]." ".$tz_item["h1"].":".($tz_item["m1"]<10?"0":"").$tz_item["m1"];
            $datetime2 = $tz_item["d"]." ".$tz_item["h2"].":".($tz_item["m2"]<10?"0":"").$tz_item["m2"];
            $ev = array(
                $aid++,//mt_rand(1,9999999), //$row["id"],
                $tz_item["e"],
                tz_fd_php_to_js_time(tz_fd_mysql_to_php_time($datetime)),
                tz_fd_php_to_js_time(tz_fd_mysql_to_php_time($datetime2)),
                0, // is  all day event?
                0, // more than one day event
                '',//Recurring event rule,
                '#3CF',
                0,//editable
                '',
                '',//$attends
                $tz_item["info"],
                '',
                1
            );
            $ret['events'][] = $ev;
        }
        $dataoutput = wp_json_encode($ret);
        do_action( 'tz_fd_cache_store', $formid, $dataquery, $dataoutput );
        echo wp_json_encode( $ret );
        exit;
    }


    /**
     * True when a booking date falls inside the requested range. Both ends are inclusive and compared by day.
     * The dates are read by strtotime() and formatted by gmdate() in the same zone (WordPress keeps the PHP default
     * zone at UTC), so the day does not shift. An empty or unreadable range keeps every date.
     */
    private function date_in_range( $date, $startdate, $enddate ) {
        $start = strtotime( (string) $startdate );
        $end   = strtotime( (string) $enddate );
        $day   = strtotime( (string) $date );
        // A damaged date must not bypass a requested range. An empty range is
        // permitted for legacy clients, but does not legitimize invalid records.
        if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $date ) ||
             ! checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) ) ||
             false === $day ) {
            return false;
        }
        if ( '' === $startdate && '' === $enddate ) {
            return true;
        }
        if ( false === $start || false === $end ) {
            return false;
        }
        $day_str = gmdate( 'Y-m-d', $day );
        return $day_str >= gmdate( 'Y-m-d', $start ) && $day_str <= gmdate( 'Y-m-d', $end );
    }

    public function wptsbk_custom_sort( $a, $b ) {
          return ((($a['d']>$b['d']) ||
                   ($a['d']==$b['d'] && $a['h1']>$b['h1']) ||
                   ($a['d']==$b['d'] && $a['h1']==$b['h1'] && $a['m1']>$b['m1'])) ? 1 : -1);
    }


    function check_current_user_access( $calid = '' )
    {
        // A WordPress capability alone does not authorize crossing companies.
        // Verify the calendar's owner before reading its stored access list.
        $calid = absint( $calid );
        if ( $calid < 1 || ! is_user_logged_in() || ! class_exists( 'TZFD_Scoped_Store' ) ) {
            return false;
        }
        $company = TZFD_Scoped_Store::company_id();
        if ( $company < 1 || TZFD_Scoped_Store::company_for_form( $calid ) !== $company ) {
            return false;
        }
        $user_id = get_current_user_id();
        if ( $user_id < 1 ) { return false; }
        $saved_id = $this->item;
        $this->setID( $calid );
        $access_ids = TZFD_Access_List::decode( $this->get_option( 'tz_fd_user_access', '' ) );
        $allowed = current_user_can( 'tz_fd_access' ) || in_array( $user_id, $access_ids, true );
        $this->setID( $saved_id );
        return $allowed;
    }


    function data_management() {
        global $wpdb;
            
        // Invalid request types must never reach sanitize_text_field()/absint() as arrays.
        $tzfd_requested_action = tzfd_request_text( 'post', 'tz_fd_app_action' );
        if ( '' === $tzfd_requested_action ) { $tzfd_requested_action = tzfd_request_text( 'get', 'tz_fd_app_action' ); }
        // A POST endpoint must not have a second, contradictory action in the query string.
        // Disallow request-method confusion before any calendar or resource processing.
        if ( 'POST' === ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) &&
            isset( $_GET['tz_fd_app_action'] ) &&
            tzfd_request_text( 'get', 'tz_fd_app_action' ) !== $tzfd_requested_action ) {
            status_header( 400 );
            exit;
        }
        if ( ! is_string( $tzfd_requested_action ) || strlen( $tzfd_requested_action ) > 32 ) {
            status_header( 400 );
            exit;
        }
        $tzfd_requested_action = sanitize_key( wp_unslash( $tzfd_requested_action ) );
        if ( in_array( $tzfd_requested_action, array( 'get_slots', 'mv' ), true ) && 'POST' !== ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
            status_header( 405 );
            header( 'Allow: POST' );
            exit;
        }
        if ( in_array( $tzfd_requested_action, array( 'get_slots', 'mv' ), true ) && 'POST' === ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) )
        {
            $tzfd_raw_form = tzfd_request_text( 'post', 'formid' );
            $tzfd_raw_field = tzfd_request_text( 'post', 'formfield' );
            if ( ! is_scalar( $tzfd_raw_form ) || ! ctype_digit( (string) $tzfd_raw_form ) ||
                 ! is_string( $tzfd_raw_field ) || strlen( $tzfd_raw_field ) > 128 ) {
                status_header( 400 );
                header( 'Content-Type: application/json; charset=UTF-8' );
                echo '[]';
                exit;
            }
            $formid = absint( $tzfd_raw_form );
            $field = sanitize_key( $tzfd_raw_field );

            // Public availability is intentionally anonymous, but a form ID must never be
            // trusted as a tenant boundary. Resolve ownership from the canonical forms table
            // and bind the booking query to the same owner. Unknown/legacy unowned forms fail closed.
            require_once __DIR__ . '/includes/class-tzfd-public-slot-scope.php';
            $tzfd_slot_company = TZFD_Public_Slot_Scope::owner_for_form( $formid );
            if ( $tzfd_slot_company < 1 ) {
                status_header( 404 );
                header( 'Content-Type: application/json; charset=UTF-8' );
                echo '[]';
                exit;
            }
            // Staff multi-view must also match the Workspace company's active context.
            if ( 'mv' === $tzfd_requested_action &&
                 ! TZFD_Public_Slot_Scope::staff_can_view( $tzfd_slot_company ) ) {
                status_header( 403 );
                echo 'Forbidden';
                exit;
            }

            // The multi-view calendar feed (POST, tz_fd_app_action=mv) shows booking details to staff only.
            if ('mv' === $tzfd_requested_action)
            {
                if ( ! wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
                if ( ! is_user_logged_in() || ! $this->check_current_user_access( $formid ) ) { echo esc_html__( 'Authentication required', 'titan-zero-trades-front-desk' ); exit; }
            }

            if ('mv' === $tzfd_requested_action)
            {
                $t_content_admin = get_option('tz_fd_schcalcontent_admin',TZFD_SCHCALCONTENT_ADMIN);
                $t_title_admin = get_option('tz_fd_schcaltitle_admin',TZFD_SCHCALTITLE_ADMIN);
                $t_content_public = get_option('tz_fd_schcalcontent_public',TZFD_SCHCALCONTENT_PUBLIC);
                $t_title_public = get_option('tz_fd_schcaltitle_public',TZFD_SCHCALTITLE_PUBLIC);
                $t_content_admin = apply_filters( 'tz_fd_mv_content_admin_filter', $t_content_admin,  intval($formid) );
                $t_title_admin = apply_filters( 'tz_fd_mv_title_admin_filter', $t_title_admin,  intval($formid) );
                $t_content_public = apply_filters( 'tz_fd_mv_content_public_filter', $t_content_public,  intval($formid) );
                $t_title_public = apply_filters( 'tz_fd_mv_title_public_filter', $t_title_public,  intval($formid) );
            }

            // Reject malformed/oversized date ranges before querying and decoding bookings.
            // Reject array/object parameters before calling WordPress string sanitizers.
            $tzfd_raw_start = tzfd_request_text( 'post', 'startdate' );
            $tzfd_raw_end   = tzfd_request_text( 'post', 'enddate' );
            if ( ! is_string( $tzfd_raw_start ) || ! is_string( $tzfd_raw_end ) ) {
                status_header( 400 );
                header( 'Content-Type: application/json; charset=UTF-8' );
                echo '[]';
                exit;
            }
            $tzfd_range_start = $tzfd_raw_start;
            $tzfd_range_end   = $tzfd_raw_end;
            if ( ! TZFD_Public_Slot_Scope::valid_date_range( $tzfd_range_start, $tzfd_range_end ) ) {
                status_header( 400 );
                header( 'Content-Type: application/json; charset=UTF-8' );
                echo '[]';
                exit;
            }

            // Blocked slots are left out of the calendar feed unless the setting shows them.
            $tz_include_blocked = ( tzfd_request_text( 'request', 'tz_fd_app_action' ) != 'mv' || get_option('tz_fd_sch_admin_blockedt','') == 'Yes' );
            if ( $tz_include_blocked )
                $dataquery = $wpdb->prepare( "SELECT data,notifyto,posted_data FROM %i WHERE formid=%d AND company_id=%d", $wpdb->prefix.TZFD_Tables::BOOKINGS, $formid, $tzfd_slot_company );
            else
                $dataquery = $wpdb->prepare( "SELECT data,notifyto,posted_data FROM %i WHERE formid=%d AND company_id=%d AND notifyto<>%s", $wpdb->prefix.TZFD_Tables::BOOKINGS, $formid, $tzfd_slot_company, $this->blocked_by_admin_indicator );

            // The cached feed depends on the date range, so the range is part of the cache key.
            $dataquery .= '|' . $tzfd_range_start . '|' . $tzfd_range_end;
            do_action( 'tz_fd_cache_check', $formid, $dataquery );

            // Clear the previous query's error state so the availability response
            // reflects only this database read. A current failure is fail-closed below.
            if ( method_exists( $wpdb, 'flush' ) ) { $wpdb->flush(); }
            // The same two queries as $dataquery, written out so each prepare() call gets a literal string.
            if ( $tz_include_blocked )
                $tz_myrows = $wpdb->get_results( $wpdb->prepare( "SELECT data,notifyto,posted_data FROM %i WHERE formid=%d AND company_id=%d", $wpdb->prefix.TZFD_Tables::BOOKINGS, $formid, $tzfd_slot_company ) );
            else
                $tz_myrows = $wpdb->get_results( $wpdb->prepare( "SELECT data,notifyto,posted_data FROM %i WHERE formid=%d AND company_id=%d AND notifyto<>%s", $wpdb->prefix.TZFD_Tables::BOOKINGS, $formid, $tzfd_slot_company, $this->blocked_by_admin_indicator ) );
            
            // A failed query must not be interpreted as an empty calendar. Otherwise a
            // public client could offer times already occupied by existing bookings.
            require_once __DIR__ . '/includes/class-tzfd-public-slot-records.php';
            if ( ! TZFD_Public_Slot_Records::valid_query_result( $tz_myrows, $wpdb->last_error ?? '' ) ) {
                status_header( 503 );
                header( 'Content-Type: application/json; charset=UTF-8' );
                header( 'Cache-Control: no-store, private' );
                header( 'X-Content-Type-Options: nosniff' );
                echo '[]';
                exit;
            }
            $tmp2 = array();
            $tz_range_start = $tzfd_range_start;
            $tz_range_end   = $tzfd_range_end;

            $excluded_tzfdcalendar = ',,'.get_option('tz_fd_schcalcontent_exclude','').','; // fields excluded form multi view calendar

            for ($tz_i=0; $tz_i < count($tz_myrows); $tz_i++)
            {
                $tz_data = TZFD_Stored_Data::decode_booking($tz_myrows[$tz_i]->posted_data);
                // Never silently ignore invalid booking payloads: that would make occupied
                // times appear free, potentially allowing a conflicting booking.
                if ( ! TZFD_Public_Slot_Records::valid_booking_payload( $tz_data ) ) {
                    status_header( 503 );
                    header( 'Content-Type: application/json; charset=UTF-8' );
                    header( 'Cache-Control: no-store, private' );
                    header( 'X-Content-Type-Options: nosniff' );
                    echo '[]';
                    exit;
                }
                if ( is_array( $tz_data['apps'] ) )
                {
                    for($tz_k=0; $tz_k<count($tz_data['apps']); $tz_k++) {
                        $tzfd_entry = $tz_data['apps'][$tz_k];
                        $tzfd_field = isset( $tzfd_entry['field'] ) ? $tzfd_entry['field'] : '';
                        if ( TZFD_Scheduling_Rules::is_occupied_status(
                                $tzfd_entry['cancelled'] ?? '', isset( $tz_data['lock'] )
                            ) &&
                            ( '' === $tzfd_field || '' === $field || $tzfd_field === $field ) &&
                            ( 'mv' !== $tzfd_requested_action || false === strpos( $excluded_tzfdcalendar, ',' . $tzfd_field . ',' ) ) )
                        {
                            if ( ! $this->date_in_range( $tz_data["apps"][$tz_k]["date"] ?? '', $tz_range_start, $tz_range_end ) )
                                continue;

                            $slot = $tz_data["apps"][$tz_k]["slot"] ?? null;
                            // Stored data may predate the current schema. Never turn
                            // malformed appointment times into zero-hour public slots.
                            if ( ! is_string( $slot ) ||
                                 ! preg_match( '/^([01][0-9]|2[0-3]):[0-5][0-9][\/-]([01][0-9]|2[0-3]):[0-5][0-9]$/D', $slot ) ) {
                                continue;
                            }
                            $quantity = TZFD_Scheduling_Rules::normalize_quantity( $tzfd_entry['quant'] ?? 1 );

                            $availitem = array("d"=>$tzfd_entry['date'] ,"h1"=>intval(substr($slot,0,2)),"m1"=>intval(substr($slot,3,2)),"h2"=>intval(substr($slot,6,2)),"m2"=>intval(substr($slot,9,2)),"serviceindex"=>intval($tzfd_entry['serviceindex'] ?? 0),"quantity"=>$quantity);

                            if ( ! empty( $tzfd_entry['sid'] ) )
                                $availitem["sid"] = intval( $tzfd_entry['sid'] );

                            $tmp2[] = $availitem;

                            if (tzfd_request_text( 'request', 'tz_fd_app_action' ) == 'mv' && $this->check_current_user_access($formid))
                            {
                                $tz_data["INFO"] = $this->sanitize($tz_myrows[$tz_i]->data);
                                $tmp2[count($tmp2)-1]["info"] = wp_kses_post( $this->replace_tags( $this->sanitize($t_content_admin), $tz_data, false, $tz_k, true ) );  //   $tz_myrows[$tz_i]->data;
                                $tmp2[count($tmp2)-1]["e"] = esc_html( $this->replace_tags( $this->sanitize($t_title_admin), $tz_data, false, $tz_k, true ) );  // sanitize_email($tz_myrows[$tz_i]->notifyto);
                            }
                        }
                    }
                }
            }
            if (tzfd_request_text( 'request', 'tz_fd_app_action' ) == 'mv')
            {
                if (is_admin() && $this->check_current_user_access(intval($formid)))
                    $this->print_tz_fd_calendar_format( $tmp2, $formid, $dataquery, $tzfd_range_start, $tzfd_range_end );
                else 
                    echo esc_html__( 'Authentication required', 'titan-zero-trades-front-desk' );
            }
            else {
                $dataoutput = wp_json_encode($tmp2);
                header( 'Content-Type: application/json; charset=UTF-8' );
                header( 'X-Content-Type-Options: nosniff' );
                header( 'Cache-Control: no-store, private' );
                do_action( 'tz_fd_cache_store', $formid, $dataquery, $dataoutput );
                echo $dataoutput; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON response, never HTML.
                //{type:"all",d:"",h1:8,m1:0,h2:17,m2:0}                
            }
		    exit;
        }

    	if ( isset( $_GET['tz_fd_resources'] ) )
    	{
            if ( 'GET' !== ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
                status_header( 405 );
                header( 'Allow: GET' );
                exit;
            }
            $tzfd_raw_resource = tzfd_request_text( 'get', 'tz_fd_resources' );
            if ( ! is_string( $tzfd_raw_resource ) || strlen( $tzfd_raw_resource ) > 40 ) {
                status_header( 400 );
                exit;
            }
            $tzfd_resource = sanitize_key( $tzfd_raw_resource );
    		if ( 'admin' === $tzfd_resource )
    		{
    			// Admin builder resources must never be served to unauthenticated visitors.
                if ( ! is_user_logged_in() || ! current_user_can( 'tz_fd_access' ) ) {
                    status_header( 403 );
                    header( 'Content-Type: text/plain; charset=UTF-8' );
                    header( 'X-Content-Type-Options: nosniff' );
                    echo 'Forbidden';
                    exit;
                }
                header( 'X-Content-Type-Options: nosniff' );
                require_once dirname( __FILE__ ).'/js/tzfdform-loader-admin.php';
    		}
            else if ( 'validatejs' === $tzfd_resource )
    		{
                // This public script belongs to a real form; reject malformed IDs and
                // arrays before loading per-form validation configuration.
                $tzfd_script_form = tzfd_request_text( 'get', 'cal' );
                $tzfd_script_counter = tzfd_request_text( 'get', 'counter' );
                if ( ! is_scalar( $tzfd_script_form ) || ! ctype_digit( (string) $tzfd_script_form ) ||
                     ! is_scalar( $tzfd_script_counter ) || ! ctype_digit( (string) $tzfd_script_counter ) ||
                     strlen( (string) $tzfd_script_counter ) > 7 ) {
                    status_header( 400 );
                    exit;
                }
                require_once __DIR__ . '/includes/class-tzfd-public-slot-scope.php';
                $tzfd_form_id = absint( $tzfd_script_form );
                if ( $tzfd_form_id < 1 || TZFD_Public_Slot_Scope::owner_for_form( $tzfd_form_id ) < 1 ) {
                    status_header( 404 );
                    exit;
                }
                header( 'Content-Type: application/javascript; charset=UTF-8' );
                header( 'X-Content-Type-Options: nosniff' );
                $this->print_counter = absint( $tzfd_script_counter );
                $this->item = $tzfd_form_id;                
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is a JavaScript response (Content-type application/javascript), not an HTML script tag. The body is built per form: it defines the per-form function names that tzfdform-public.js and the form markup call, and it includes the output of the tz_fd_script_after_validation action. Every dynamic value is escaped in getValidateScript() (esc_js, esc_html, intval). Moving it to a static enqueued file would need the per-form values passed another way, so it stays an endpoint for now.
                echo $this->clean_sanitize($this->getValidateScript());
    		}            
    		else if ( 'public' === $tzfd_resource )
    		{
    			header( 'X-Content-Type-Options: nosniff' );
    			require_once dirname( __FILE__ ).'/js/tzfdform-loader-public.php';
    		}
    		else { status_header( 404 ); exit; }
    		exit;
    	}

        // Scheduled reports are sent only by the tz_fd_reports_cron event (run_scheduled_reports). No page load, admin or
        // front end, runs check_reports(), because it writes options and CSV files and can send mail.

        if ($this->get_param($this->prefix.'_captcha') == 'captcha' )
        {
            include_once $this->part_path( 'captcha/captcha.php' );
            exit;
        }
		
        if ( isset( $_REQUEST[ $this->prefix . '_blockmultiple' ] ) &&
             '1' === tzfd_request_text( 'request', $this->prefix . '_blockmultiple' ) && is_admin() )
        {
            // Reject GET and mixed-method CSRF requests before evaluating submitted fields.
            if ( 'POST' !== ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ||
                 ! isset( $_POST[ $this->prefix . '_blockmultiple' ] ) ||
                 '1' !== tzfd_request_text( 'post', $this->prefix . '_blockmultiple' ) ||
                 ! wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_admin' ) ) {
                $this->nonce_failed();
            }
            $this->block_multiple();
            return;
        }  		

        if ( 'GET' === ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) && isset( $_GET[ $this->prefix . '_csv1' ] ) && is_admin() && $this->check_current_user_access( intval( tzfd_request_text( 'get', 'cal' ) ) ) )
        {
            // The message list export sends the list nonce in anonce (GET form).
            if ( ! wp_verify_nonce( tzfd_request_text( 'get', 'anonce' ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
            $this->export_csv();
            return;
        }

        if ( 'GET' === ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) && isset( $_GET[ $this->prefix . '_csv2' ] ) && is_admin() && $this->check_current_user_access( intval( tzfd_request_text( 'get', 'cal' ) ) ) )
        {
            if ( ! wp_verify_nonce( tzfd_request_text( 'get', 'anonce' ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
            $this->export_csv_schedule(array());
            return;
        }

        if ( $this->get_param($this->prefix.'_post_options') && is_admin() && $this->check_current_user_access(intval($this->get_param($this->prefix.'_id'))))
        {
            if ( 'POST' !== ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) || ! wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_admin' ) ) { $this->nonce_failed(); }
            $this->save_options();
            return;
        }

        if (!isset($_SERVER['REQUEST_METHOD']))
            return;

        if ( isset( $_REQUEST['TZFD_post_edition'] ) && 'POST' !== ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
            wp_die( esc_html__( 'Settings changes require POST.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( 'POST' == sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['TZFD_post_edition'] ) && current_user_can('tz_fd_access') && is_admin() )
        {
            $this->save_edition();
            return;
        }

    	if ( 'POST' != sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || ! isset( $_POST[$this->prefix.'_pform_process'] ) )
    	    if ( 'GET' != sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || !isset( $_GET['hdcaptcha_'.$this->prefix.'_post'] ) )
    		    return;

        // The booking form nonce is verified before any posted value is read (item id, captcha, fields).
        // A page cache can serve a form whose nonce has expired; the visitor is asked to reload instead of failing silently.
        if ( 'POST' === tzfd_request_text( 'server', 'REQUEST_METHOD' ) && ( $this->booking_form_nonce || is_admin() ) )
        {
            $tz_anonce = tzfd_request_text( 'post', 'anonce' );
            if ( ! wp_verify_nonce( $tz_anonce, 'tz_fd_actions_bookingform' ) )
            {
                echo esc_html__( 'This booking form has expired. Please reload the page and submit it again.', 'titan-zero-trades-front-desk' );
                exit;
            }
        }

        if ($this->get_param($this->prefix.'_id')) $this->item = intval($this->get_param($this->prefix.'_id'));

        // wp-admin/admin-ajax.php is also reachable by anonymous callers. URL context is not authority.
        $titan_zero_front_desk_admin_submission = is_admin() && get_current_user_id() > 0
            && $this->check_current_user_access( (int) $this->item );

        @session_start();
        $sequence = '';
        if (isset($_GET["ps"])) $sequence = tzfd_request_text( 'get', "ps" ); else if (isset($_POST["tz_fd_pform_psequence"])) $sequence = tzfd_request_text( 'post', "tz_fd_pform_psequence" );
        // Booking CAPTCHA: the answer must match the challenge in the browser's cookie, within the wrong-answer limits.
        require_once __DIR__ . '/includes/class-tzfd-captcha.php';
        $captcha_required = (!$titan_zero_front_desk_admin_submission && $this->get_option('cv_enable_captcha', TZFD_BOOK_DEFAULT_cv_enable_captcha) != 'false' && function_exists('imagecreatetruecolor'));
        $captcha_id = !empty($_COOKIE['rand_code'.$sequence]) ? sanitize_key(wp_unslash($_COOKIE['rand_code'.$sequence])) : '';
        $captcha_answer = (string) $this->get_param('hdcaptcha_'.$this->prefix.'_post');
        if (
               !apply_filters( 'tz_fd_valid_submission', true) ||
               ( $captcha_required && !TZFD_Captcha::check_answer($captcha_id, $captcha_answer, TZFD_Captcha::client_key()) )
           )
        {
            echo 'captchafailed';
            exit;
        }

    	// if this isn't the real post (it was the captcha verification) then echo ok and exit
        if ( 'POST' != sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || ! isset( $_POST[$this->prefix.'_pform_process'] ) )
    	{
    	    echo 'ok';
            exit;
    	}

        $posted_items = array();
        foreach ($_POST as $tz_item => $tz_value)
            $posted_items[sanitize_key($tz_item)] = $this->sanitize( wp_unslash( $tz_value ) );

        if (isset($_GET["blocktimes"]) && $titan_zero_front_desk_admin_submission && current_user_can( 'tz_fd_access' ))
            define('TZFD_HOUR_BLOCK_TIMES_PROCESS', true);

        // get form info
        //---------------------------
        //require_once(ABSPATH . "wp-admin" . '/includes/file.php');
        $tz_form_data = json_decode($this->cleanJSON($this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure)));
        // A damaged or partially saved form configuration must not reach the
        // appointment parser or produce an apparently successful booking.
        if ( ! is_array( $tz_form_data ) || ! isset( $tz_form_data[0] ) || ! is_array( $tz_form_data[0] ) ) {
            echo esc_html__( 'This booking form is not configured correctly. Please ask an administrator to check its fields.', 'titan-zero-trades-front-desk' );
            exit;
        }
        $fields = array();

        $apps = $this->extract_appointments($tz_form_data[0], $posted_items, $sequence);
        // A form with an appointment picker must not save a customer-only row
        // when the picker did not yield a valid selection. Keep non-booking
        // contact forms working (they intentionally contain no fapp field).
        require_once __DIR__ . '/includes/class-tzfd-booking-submission-guard.php';
        if ( ! TZFD_Booking_Submission_Guard::selection_allowed( $tz_form_data[0], $apps ) ) {
            echo esc_html__( 'Please choose a valid service, date and appointment time before saving.', 'titan-zero-trades-front-desk' );
            exit;
        }
        if ($this instanceof TZFD_Scheduling_Plugin && !$this->calendar_selection_is_valid($apps)) {
            $this->endSubmission();
            return;
        }
        // Only public intake uses request mode; admin and block-time paths retain donor behavior.
        $titan_zero_front_desk_intake_mode = ( ! $titan_zero_front_desk_admin_submission && ! defined('TZFD_HOUR_BLOCK_TIMES_PROCESS') )
            ? TZFD_Intake_Mode::get_for_form( (int) $this->item )
            : TZFD_Intake_Mode::MODE_INSTANT;
        $apps = TZFD_Intake_Mode::apply_to_appointments($apps, $titan_zero_front_desk_intake_mode);

        $honeypotcheck = sanitize_key(get_option('tz_fd_honeypot',''));
        if ($honeypotcheck != '' && (isset($_POST[$honeypotcheck]) && tzfd_request_text( 'post', $honeypotcheck ) != ''))
    	{
    	    echo esc_html__( 'Blocked by anti-spam rule. Please contact our support service if you this this is an error.', 'titan-zero-trades-front-desk' );
            exit;
    	}

        // A public booking whose form has no resolvable company would be stored where no confirmation, reminder or staff
        // view can reach it. Refuse it before the capacity lock and the insert, and say so.
        if ( ! $titan_zero_front_desk_admin_submission && ! defined('TZFD_HOUR_BLOCK_TIMES_PROCESS') && TZFD_Scoped_Store::company_id() < 1 ) {
            echo esc_html__( 'This booking could not be saved because the form is not linked to an active business. Please contact us to make the booking.', 'titan-zero-trades-front-desk' );
            exit;
        }

        $tz_price = $this->extract_total_price ($apps);
        $apptext = $this->get_appointments_text ($apps);
        $excluded_items = array();

        foreach ($tz_form_data[0] as $tz_item)
            if ($tz_item->ftype != 'fapp' && !defined('TZFD_HOUR_BLOCK_TIMES_PROCESS'))
            {
                if ($tz_item->ftype == 'femail' && isset($posted_items[$tz_item->name.$sequence]))
                    $posted_items[$tz_item->name.$sequence] = sanitize_email($posted_items[$tz_item->name.$sequence]);
                $fields[$tz_item->name] = $tz_item->title;
                if ($tz_item->ftype == 'fPhone') // join fields for phone fields
                {
                    $posted_items[$tz_item->name.$sequence] = '';
                    for($tz_i=0; $tz_i<=substr_count($tz_item->dformat," "); $tz_i++)
                    {
                        $posted_items[$tz_item->name.$sequence] .= ($posted_items[$tz_item->name.$sequence."_".$tz_i]!=''?($tz_i==0?'':'-').$posted_items[$tz_item->name.$sequence."_".$tz_i]:'');
                        unset($posted_items[$tz_item->name.$sequence."_".$tz_i]);
                    }
                }
            }
            else
            {
                $fields[$tz_item->name] = $tz_item->title;
                $posted_items[$tz_item->name.$sequence] = $apptext;
                $excluded_items[] = $tz_item->name;
            }



        // Server-side customer field checks for public bookings. The browser validator is not enough on its own.
        if ( ! $titan_zero_front_desk_admin_submission && ! defined('TZFD_HOUR_BLOCK_TIMES_PROCESS') ) {
            require_once __DIR__ . '/includes/class-tzfd-customer-field-check.php';
            $tz_check_fields = array();
            foreach ($tz_form_data[0] as $tz_item) {
                if ($tz_item->ftype == 'fapp') continue;
                $tz_check_key = $tz_item->name.$sequence;
                $tz_check_fields[] = array(
                    'ftype'    => $tz_item->ftype,
                    'name'     => (string) $tz_item->name,
                    'title'    => (string) $tz_item->title,
                    'required' => TZFD_Customer_Field_Check::is_required($tz_item),
                    // Email is read before sanitize_email() so an invalid address is refused, not blanked.
                    'value'    => $tz_item->ftype == 'femail'
                        ? tzfd_request_text( 'post', $tz_check_key )
                        : (string) ( $posted_items[$tz_check_key] ?? '' ),
                );
            }
            $tz_check_error = TZFD_Customer_Field_Check::first_error($tz_check_fields);
            if ('' !== $tz_check_error) {
                echo esc_html($tz_check_error);
                exit;
            }
        }

        // grab posted data
        //---------------------------
        $buffer = __('Appointments','titan-zero-trades-front-desk').":\n".$apptext."\n";

        $founddata = false;
        $tz_params = array();
        // Discount hook: Quotes and Invoicing apply their discounts through this filter. Front Desk has no coupons.
        $tz_price = number_format( (float) apply_filters( 'tz_fd_invoice_amount', (float) $tz_price, array( 'formid' => (int) $this->item, 'itemnumber' => 0 ) ), 2, '.', '' );
        $tz_params["final_price"] = $tz_price;
        $tz_params["final_price_short"] = number_format($tz_price,0);
        $tz_params["request_timestamp"] = $this->format_date(gmdate("Y-m-d", current_time('timestamp'))). " ". (gmdate("H:i:s", current_time('timestamp')));
        $tz_params["titan_zero_front_desk_intake_mode"] = $titan_zero_front_desk_intake_mode;
        $tz_params["apps"] = $apps;
        foreach ($apps as $appitem)
        {
           $is_military_t = (!isset($appitem["military"]) || $appitem["military"] == 0 || $appitem["military"] == '');
           $tz_params["app_service_".$appitem["id"]] = $appitem["service"];
           $tz_params["app_status_".$appitem["id"]] = $appitem["cancelled"];
           $tz_params["app_duration_".$appitem["id"]] = $appitem["duration"];
           $tz_params["app_price_".$appitem["id"]] = $appitem["price"];
           $tz_params["app_date_".$appitem["id"]] = $this->format_date($appitem["date"]);
           $tz_params["app_slot_".$appitem["id"]] = $appitem["slot"];
           $slotpieces = explode("/",$appitem["slot"]);
           $tz_params["app_starttime_".$appitem["id"]] = $this->format12hours(trim(@$slotpieces[0]), $is_military_t);
           $tz_params["app_endtime_".$appitem["id"]] = $this->format12hours(trim(@$slotpieces[1]), $is_military_t);
           $tz_params["app_quantity_".$appitem["id"]] = $appitem["quant"];
           $founddata = true;
        }

        $tz_params["formid"] = $this->item;
        $tz_params["formname"] = $this->get_option('form_name');
        $tz_params["referrer"] = $posted_items["refpage".$sequence] ?? '';
        foreach ($posted_items as $tz_item => $tz_value)
            if (isset($fields[str_replace($sequence,'',$tz_item)]))
            {
                if (is_array($tz_value))
                {
                    for ($iv=0; $iv<count($tz_value); $iv++)
                        $tz_value[$iv] = str_replace(TZFD_BOOK_REP_ARR, "@", $tz_value[$iv]);
                }
                else
                    $tz_value = str_replace(TZFD_BOOK_REP_ARR, "@", $tz_value);
                if (!in_array(str_replace($sequence,'',$tz_item), $excluded_items))
                    $buffer .= ($fields[str_replace($sequence,'',$tz_item)]?$fields[str_replace($sequence,'',$tz_item)] . ": ":""). (is_array($tz_value)?($this->recursive_implode(", ",$tz_value)):($tz_value)) . "\n\n";
                $tz_params[str_replace($sequence,'',$tz_item)] = $tz_value;
                $founddata = true;
            }

        $buffer_A = (defined('TZFD_HOUR_BLOCK_TIMES_PROCESS')?__('BLOCKED BY ADMIN','titan-zero-trades-front-desk')."\n\n":'') . $buffer;

        $_SESSION['rand_code'.$sequence] = '';
        if (!empty($_COOKIE['rand_code'.$sequence])) set_transient( "tzfd-captcha-".sanitize_key($_COOKIE['rand_code'.$sequence]) , '' , 1800 );
        
	    /**
	     * Action called before insert the data into database.
	     * To the function is passed an array with submitted data.
	     */
	    do_action( 'tz_fd_process_data_before_insert', $tz_params );

        if (!$founddata)
        {
            do_action( 'tz_fd_process_data_insert_failed', $tz_params );
            echo esc_html__( 'Empty post! No data received.', 'titan-zero-trades-front-desk' );
            exit;
        }

        // Persist the selected customer relationship only for an authenticated
        // administrator with access to this calendar. Public submissions cannot
        // supply a customer ID or impersonate an existing CRM record.
        if ( $titan_zero_front_desk_admin_submission && ! defined( 'TZFD_HOUR_BLOCK_TIMES_PROCESS' ) ) {
            // Reject malformed customer identifiers rather than absint() coercing
            // arrays, negative numbers or partial text into another customer ID.
            require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';
            $tz_customer_raw = $posted_items['tz_fd_customer_id'] ?? '';
            $tz_crm_id = '' === $tz_customer_raw ? 0 : TZFD_Admin_Request_Ids::positive( $tz_customer_raw );
            if ( null === $tz_crm_id ) {
                do_action( 'tz_fd_process_data_insert_failed', $tz_params );
                echo esc_html__( 'Invalid customer selection. Refresh the booking form and choose a customer.', 'titan-zero-trades-front-desk' );
                exit;
            }
            // The Add Booking selector is backed by Front Desk's own customer table.
            // Never resolve its numeric IDs against Workspace: the two stores can
            // independently contain a customer with the same integer ID.
            require_once __DIR__ . '/includes/class-tzfd-local-customers.php';
            require_once __DIR__ . '/includes/class-tzfd-scoped-store.php';
            if ( $tz_crm_id ) {
                $tz_local_customer = TZFD_Local_Customers::find( $tz_crm_id );
                $tz_active_company = (int) TZFD_Scoped_Store::company_id();
                if ( is_array( $tz_local_customer ) && $tz_active_company > 0 &&
                     (int) ( $tz_local_customer['company_id'] ?? 0 ) === $tz_active_company &&
                     (int) ( $tz_local_customer['id'] ?? 0 ) === $tz_crm_id ) {
                    $tz_params['customer_id'] = $tz_crm_id;
                    $tz_params['customer_source'] = 'frontdesk_local';
                } else {
                    // An invalid/foreign customer selection cannot be silently bound.
                    do_action( 'tz_fd_process_data_insert_failed', $tz_params );
                    echo esc_html__( 'The selected customer no longer exists. Refresh the booking form and select a valid customer.', 'titan-zero-trades-front-desk' );
                    exit;
                }
            }
        }

        // insert into database
        //---------------------------
        $current_user = wp_get_current_user();
        $tz_params["username"] = $current_user->user_login;
        $this->add_field_verify($wpdb->prefix.TZFD_Tables::BOOKINGS, "whoadded");

        $to = $this->get_option('cu_user_email_field', TZFD_BOOK_DEFAULT_cu_user_email_field);
        $sanitized_email = sanitize_email( ( !empty($posted_items[$to.$sequence]) ? $posted_items[$to.$sequence] : '' ) );
        if (defined('TZFD_HOUR_BLOCK_TIMES_PROCESS'))
            $sanitized_email = $this->blocked_by_admin_indicator;

        if (!$this->performAdvancedDoubleBookingVerification($tz_params, $sequence))
        {
            do_action( 'tz_fd_process_data_insert_failed', $tz_params );
            $this->endSubmission();
            return; // in the case it shouldn't break the whole process
        }

        // Keep initial insert and final booking payload atomic. A failed final
        // write must not leave an incomplete booking visible to future readers.
        require_once __DIR__ . '/includes/class-tzfd-final-booking-write.php';
        require_once __DIR__ . '/includes/class-tzfd-transaction-storage.php';
        require_once __DIR__ . '/includes/class-tzfd-booking-create-transaction.php';
        $tzfd_create_result = TZFD_Booking_Create_Transaction::create(
            $wpdb,
            (int) TZFD_Scoped_Store::company_id(),
            array(
                'formid' => $this->item,
                'time' => current_time('mysql'),
                'ipaddr' => ( get_option('tz_fd_storeip', TZFD_BOOK_DEFAULT_track_IP) ? tzfd_request_text( 'server', 'REMOTE_ADDR' ) : '' ),
                'notifyto' => $sanitized_email,
                'posted_data' => serialize($tz_params),
                'data' => $buffer_A,
                'whoadded' => (string) $current_user->ID,
            ),
            $tz_params,
            $titan_zero_front_desk_intake_mode
        );
        if ( 'success' !== $tzfd_create_result['status'] ) {
            do_action( 'tz_fd_process_data_insert_failed', $tz_params );
            if ( 'unknown' === $tzfd_create_result['status'] ) {
                echo esc_html__( 'The booking outcome could not be confirmed. Contact the administrator before trying again to avoid a duplicate.', 'titan-zero-trades-front-desk' );
            } else {
                echo esc_html__( 'The booking could not be saved. Please contact the administrator if this continues.', 'titan-zero-trades-front-desk' );
            }
            return;
        }
        $item_number = (int) $tzfd_create_result['id'];
        $tz_params = $tzfd_create_result['params'];

        // Optional external links and booking-created events are post-commit effects.
        // Do not emit them for incomplete or mismatched stored bookings.



        // Post-persistence hooks can send notifications or queue remote work;
        // they MUST execute only after the authoritative final write is verified.
        // Call action for data processing
	    //---------------------------------

	    /**
	     * Action called after inserted the data into database.
	     * To the function is passed an array with submitted data.
	     */
        // A legacy callback may temporarily change status before the normalized event at priority 20.
        // Scope the invariant to this booking so a nested submission keeps its own mode.
        $titan_zero_front_desk_submission_form = (int) $this->item;
        $titan_zero_front_desk_request_event = function( $payload ) use ( $item_number, $titan_zero_front_desk_submission_form ) {
            if ( is_array( $payload ) && isset( $payload['booking_id'], $payload['form_id'] ) &&
                 (int) $payload['booking_id'] === (int) $item_number && (int) $payload['form_id'] === $titan_zero_front_desk_submission_form ) {
                return TZFD_Intake_Mode::request_event_payload( $payload );
            }
            return $payload;
        };
        if ( TZFD_Intake_Mode::MODE_REQUEST === $titan_zero_front_desk_intake_mode ) {
            add_filter( 'titan_zero_front_desk_booking_created_payload', $titan_zero_front_desk_request_event, PHP_INT_MAX );
        }
        // Notifications and optional integrations are secondary to a committed
        // booking. Do not throw a post-commit callback error back to the visitor:
        // the appointment already exists, and a retry could create a duplicate.
        try {
            TZFD_Post_Commit_Dispatch::run_booking_created(
                'tz_fd_process_data', array( &$tz_params ), (int) $item_number
            );
        } finally {
            remove_filter( 'titan_zero_front_desk_booking_created_payload', $titan_zero_front_desk_request_event, PHP_INT_MAX );
        }



        $this->ready_to_go_reservation( $item_number, "" );
        $_SESSION[ 'tz_fd_cff_form_data' ] = $item_number;

        if ($titan_zero_front_desk_admin_submission)
            return;

        /**
		 * Filters applied to decide if the website should be redirected to the thank you page after submit the form,
		 * pass a boolean as parameter and returns a boolean
		 */
		$redirect = true;
        $redirect = apply_filters( 'tz_fd_redirect', $redirect );

        if ( TZFD_Intake_Mode::MODE_REQUEST === $titan_zero_front_desk_intake_mode ) {
            // The instant-confirm return page may promise approval. Requests stay on a neutral acknowledgment.
            $acknowledgment = TZFD_Intake_Mode::acknowledgment();
            if ( $redirect ) {
                wp_die( esc_html( $acknowledgment ), esc_html__( 'Booking request received', 'titan-zero-trades-front-desk' ), array( 'response' => 200 ) );
            }
            echo '<p class="tzfd-request-received">' . esc_html( $acknowledgment ) . '</p>';
            return;
        }

        if( $redirect )
        {
            // Never emit a raw Location header from an administrator-configurable URL.
            // wp_safe_redirect validates the destination against allowed hosts.
            $tzfd_destination = $this->replace_tags(
                $this->translate_permalink( $this->get_option( 'fp_return_page', TZFD_BOOK_DEFAULT_fp_return_page ) ),
                $tz_params,
                true
            );
            wp_safe_redirect( $tzfd_destination, 303, 'Titan Zero Front Desk' );
            exit();
        }
    }


    public function endSubmission() {
        // Link back to the referring page instead of a javascript: URL.
        $tz_back = wp_get_referer();
        if ( ! $tz_back ) {
            $tz_back = home_url( '/' );
        }
        $tz_back = wp_validate_redirect( $tz_back, home_url( '/' ) );
        echo esc_html__( 'Selected time is no longer available', 'titan-zero-trades-front-desk' ) . ' <a href="' . esc_url( $tz_back ) . '">' . esc_html__( 'please go back and select another time', 'titan-zero-trades-front-desk' ) . '</a>.';
        exit;
    }


    public function performAdvancedDoubleBookingVerification( $tz_params, $sequence = "_1", $is_double_check = false ) {
        global $wpdb;

        if ( ! isset( $tz_params["apps"] ) || ! is_array( $tz_params["apps"] ) || empty( $tz_params["apps"] ) ) {
            return false;
        }

        $tz_form_data = json_decode( $this->cleanJSON( $this->get_option( 'form_structure', TZFD_BOOK_DEFAULT_form_structure ) ) );
        $requested = array();
        foreach ( $tz_params["apps"] as $current_app ) {
            if ( ! isset( $current_app["date"], $current_app["slot"], $current_app["service"] ) ) {
                continue;
            }
            $current_app["quant"] = TZFD_Scheduling_Rules::normalize_quantity( $current_app["quant"] ?? 1 );
            $requested[] = TZFD_Scheduling_Rules::with_service_configuration( $current_app, $tz_form_data );
        }
        if ( empty( $requested ) || count( $requested ) !== count( $tz_params["apps"] ) ) {
            return false;
        }

        $capacity_owner = class_exists( 'TZFD_Scoped_Store' )
            ? (int) TZFD_Scoped_Store::company_for_form( (int) $this->item ) : 0;
        if ( $capacity_owner < 1 ) { return false; }
        // Read the complete occupancy history. A bounded last-2000 query can
        // falsely advertise a fully occupied older slot as available; failed
        // database reads must also fail closed rather than mean zero bookings.
        require_once __DIR__ . '/includes/class-tzfd-booking-capacity-reader.php';
        $latestitems = TZFD_Booking_Capacity_Reader::all_for_calendar( (int) $this->item, $capacity_owner );
        if ( null === $latestitems ) { return false; }

        $stored = array();
        foreach ( $latestitems as $tz_item ) {
            if ( $is_double_check && $tz_item->id == $is_double_check ) {
                continue;
            }
            $latestdata = TZFD_Stored_Data::decode_booking($tz_item->posted_data);
            if ( ! isset( $latestdata["apps"] ) || ! is_array( $latestdata["apps"] ) ) {
                continue;
            }
            $has_lock = isset( $latestdata["lock"] );
            foreach ( $latestdata["apps"] as $tz_app ) {
                $status = isset( $tz_app["cancelled"] ) ? $tz_app["cancelled"] : '';
                if ( ! TZFD_Scheduling_Rules::is_occupied_status( $status, $has_lock ) ||
                     ! isset( $tz_app["date"], $tz_app["slot"], $tz_app["service"] ) ) {
                    continue;
                }
                $tz_app["quant"] = TZFD_Scheduling_Rules::normalize_quantity( $tz_app["quant"] ?? 1 );
                $stored[] = TZFD_Scheduling_Rules::with_service_configuration( $tz_app, $tz_form_data );
            }
        }

        foreach ( $requested as $index => $candidate ) {
            $other_requested = $requested;
            unset( $other_requested[$index] );
            if ( TZFD_Scheduling_Rules::capacity_exceeded( $candidate, $stored, array_values( $other_requested ) ) ) {
                if ( $is_double_check ) {
                    $wpdb->query( $wpdb->prepare(
                        'DELETE FROM %i WHERE id=%d AND formid=%d AND company_id=%d',
                        $wpdb->prefix . TZFD_Tables::BOOKINGS, (int) $is_double_check, (int) $this->item, $capacity_owner
                    ) );
                }
                return false;
            }
        }

        return true;
    }

public function replace_tags ( $tz_message, $tz_params, $urlencode = false, $slotindex = '', $strongs = false ) {
        if ($slotindex !== '' && isset($tz_params["apps"][$slotindex]))
        {
            $slotindex++;
            $tz_params["app_service"] = $tz_params["app_service_".$slotindex];
            $tz_params["app_status"] = $tz_params["app_status_".$slotindex];
            $tz_params["app_duration"] = $tz_params["app_duration_".$slotindex];
            $tz_params["app_date"] = $tz_params["app_date_".$slotindex];
            $tz_params["app_slot"] = $tz_params["app_slot_".$slotindex];
            $tz_params["app_starttime"] = $tz_params["app_starttime_".$slotindex];
            $tz_params["app_endtime"] = $tz_params["app_endtime_".$slotindex];
            $tz_params["app_quantity"] = $tz_params["app_quantity_".$slotindex];
        }

        foreach ($tz_params as $tz_item => $tz_value)
        {
            if ($urlencode)
                $tz_value = rawurlencode( (is_array($tz_value)?'':$tz_value) );
            $tz_value = (is_array($tz_value)?($this->recursive_implode(", ",$tz_value)):($tz_value));
            if ($strongs) $tz_value = esc_html($tz_value);
            $tz_message = @str_replace('<'.'%'.$tz_item.'%'.'>', $tz_value, $tz_message);
            $tz_message = @str_replace('%'.$tz_item.'%', $tz_value, $tz_message);
        }

        for ($tz_i=0;$tz_i<500;$tz_i++)
        {
            $tz_message = str_replace('<'.'%fieldname'.$tz_i.'%'.'>',"",$tz_message);
            $tz_message = str_replace('%fieldname'.$tz_i.'%',"",$tz_message);
            $tz_message = str_replace('<'.'%fieldname'.$tz_i.'_block%'.'>',"",$tz_message);
            $tz_message = str_replace('%fieldname'.$tz_i.'_endblock%',"",$tz_message);
            $tz_message = str_replace('%app_date_'.$tz_i.'%',"",$tz_message);
            $tz_message = str_replace('%app_slot_'.$tz_i.'%',"",$tz_message);
            $tz_message = str_replace('%app_starttime_'.$tz_i.'%',"",$tz_message);
            $tz_message = str_replace('%app_endtime_'.$tz_i.'%',"",$tz_message);
            $tz_message = str_replace('%app_service_'.$tz_i.'%',"",$tz_message);
            $tz_message = str_replace('%app_status_'.$tz_i.'%',"",$tz_message);
            $tz_message = str_replace('%app_duration_'.$tz_i.'%',"",$tz_message);
            $tz_message = str_replace('%app_price_'.$tz_i.'%',"",$tz_message);
            $tz_message = str_replace('%app_quantity_'.$tz_i.'%',"",$tz_message);
        }

        // Remove empty blocks
	    while( preg_match( "/%\s*(.+)_block\s*%/", $tz_message, $matches ) )
	    {
	    	if( empty( $tz_params[ ''.$matches[ 1 ] ] ) )
	    	{
	    		$from = strpos( $tz_message, $matches[ 0 ] );
	    		if( preg_match( "/%\s*(".$matches[ 1 ].")_endblock\s*%/", $tz_message, $matches_end ) )
	    		{
	    			$lenght = strpos( $tz_message, $matches_end[ 0 ] ) + strlen( $matches_end[ 0 ] ) - $from;
	    		}
	    		else
	    		{
	    			$lenght = strlen( $matches[ 0 ] );
	    		}
	    		$tz_message = substr_replace( $tz_message, '', $from, $lenght );
	    	}
	    	else
	    	{
	    		$tz_message = preg_replace( array( "/%\s*".$matches[ 1 ]."_block\s*%/", "/%\s*".$matches[ 1 ]."_endblock\s*%/"), "", $tz_message );
	    	}
	    }

        $tz_message = str_replace('<'.'%email'.'%'.'>',"",$tz_message);
        $tz_message = str_replace('%email'.'%',"",$tz_message);
        $tz_message = str_replace('%username'.'%',"",$tz_message);
        return $tz_message;
    }


    public function extract_appointments( $tz_form, $tz_data, $sequence ) {
        $apps = array();
        $subid = 0;
        if ((is_admin() && get_current_user_id() > 0 && $this->check_current_user_access( (int) $this->item )) || defined('TZFD_HOUR_BLOCK_TIMES_PROCESS'))
		{
            // The admin status choice is only read when the booking form nonce is valid.
            $tz_anonce    = tzfd_request_text( 'post', "anonce" );
            $tz_anonce_ok = wp_verify_nonce( $tz_anonce, 'tz_fd_actions_bookingform' ) || wp_verify_nonce( $tz_anonce, 'tz_fd_actions_admin' );
            if ($tz_anonce_ok && is_admin() && isset($_POST["statusbox"]))
                $status = tzfd_request_text( 'post', "statusbox" );
            else
			    $status = $this->get_option('defaultpaidstatus', '');
		}
        else
            $status = $this->get_option('defaultstatus', '');
        if (defined('TZFD_HOUR_BLOCK_TIMES_PROCESS')) $status = '';
        foreach($tz_form as $field)
            if ($field->ftype == 'fapp' && @$tz_data[$field->name.$sequence] != '')
            {
                $apps_text = explode(';',$tz_data[$field->name.$sequence]);
                $fieldtotalcost = 0;
                $fieldpostedcost = floatval(@$tz_data["tcost".$field->name.$sequence]);
                foreach($apps_text as $app_item_text)
                {
                    $item_split = explode(' ',$app_item_text);  
                    if (!isset($item_split[2])) {
                            continue; 
                    }                    
                    $item_split[2] = intval($item_split[2]);
                    if (!isset($field->services[ $item_split[2] ])) {
                        continue;
                    }
                    $quant = TZFD_Scheduling_Rules::normalize_quantity(
                        $item_split[3] ?? 1,
                        intval($field->services[ $item_split[2] ]->capacity)
                    );
                    $subid++;
                    $fieldtotalcost += (floatval($field->services[ $item_split[2] ]->price) * $quant);
                    $sdate = strtotime($item_split[0]);
                    if ($sdate > 0)
                        $apps[] = array (
                                         'id' => $subid,
                                         'cancelled' => $status,
                                         'serviceindex' => $item_split[2],
                                         'service' => $field->services[ $item_split[2] ]->name,
                                         'duration' => $field->services[ $item_split[2] ]->duration,
                                         'price' => floatval($field->services[ $item_split[2] ]->price) * $quant,
                                         'date' =>  gmdate("Y-m-d", $sdate),
                                         'slot' => sanitize_text_field($item_split[1]),
                                         'military' => (!empty($field->militaryTime) ? $field->militaryTime : 0),
                                         'field' => $field->name,
                                         'quant' => $quant,
                                         'sid' => (isset($field->services[ $item_split[2] ]->idx)?$field->services[ $item_split[2] ]->idx:'')  // service ID
                                         );
                }
                //if ($fieldtotalcost < $fieldpostedcost)      // this is to support javascript price calculations
                //    $apps[count($apps)-1]["price"] = $fieldpostedcost;
            }
        return $apps;
    }


    function extract_total_price( $apps ) {
        $tz_price = 0;
        foreach( $apps as $tz_app )
            $tz_price += floatval( $tz_app["price"] );
        return number_format( $tz_price, 2, '.', '' );
    }

    function get_appointments_text( $apps ) {
        $tz_option = $this->get_option('display_emails_endtime', '');
        $text = '';
        foreach($apps as $tz_app)
        {
            $slot = str_replace("/","-",$tz_app["slot"]);
            if (!isset($tz_app["military"]) || $tz_app["military"] == 0 || $tz_app["military"] == '')
            {
                $times = explode("-",$slot);
                $times[0] = explode(":",$times[0]);
                $times[1] = explode(":",$times[1]);
                if ($tz_option != '')
                    $slot = ($times[0][0]>12?$times[0][0]-12:$times[0][0]).":".$times[0][1].' '.($times[0][0]>=12?'PM':'AM');
                else
                    $slot = ($times[0][0]>12?$times[0][0]-12:$times[0][0]).":".$times[0][1].' '.($times[0][0]>=12?'PM':'AM') ." - ".
                            ($times[1][0]>12?$times[1][0]-12:$times[1][0]).":".$times[1][1].' '.($times[1][0]>=12?'PM':'AM');

            }
            $text .= " - ".$this->format_date($tz_app["date"])." ".$slot.(isset($tz_app["quant"]) && $tz_app["quant"]>1?' ('.$tz_app["quant"].')':'')." (".$tz_app["service"].")\n";
        }
        return $text;
    }


    function format12hours( $time, $is_non_military ) {
        if ($is_non_military)
        {
            $times = explode(":",$time);
            $time = ($times[0]>12?$times[0]-12:$times[0]).":".$times[1].' '.($times[0]>=12?'PM':'AM');
        }
        return $time;
    }


    function format_date( $tz_date ) {
        $format = $this->get_option( 'date_format', 'mm/dd/yy' );
        if (!$format) $format = 'mm/dd/yy';
        $format = str_replace( 'mm', 'm', $format );
        $format = str_replace( 'dd', 'd', $format );
        $format = str_replace( 'yy', 'Y', $format );
        $format = str_replace( 'DD', 'K', $format );
        $format = str_replace( 'MM', 'Q', $format );

        $dconv = gmdate( $format, strtotime($tz_date) );

        $dconv = str_replace( 'K', ucfirst ( titan_zero_front_desk_translate_legacy_value( gmdate( 'l', strtotime( $tz_date ) ) ) ), $dconv );
        $dconv = str_replace( 'Q', ucfirst ( titan_zero_front_desk_translate_legacy_value( gmdate( 'F', strtotime( $tz_date ) ) ) ), $dconv );

        return $dconv;
    }

    function ready_to_go_reservation( $itemnumber, $payer_email = "", $resendonly = false ) {

        global $wpdb;

        // A resend is an admin action. The public booking path also calls this without $resendonly.
        if ( $resendonly && ! current_user_can( 'tz_fd_access' ) ) { return false; }

        // Never load customer details for a booking outside the active business.
        // Resend requests are privileged, but must also remain tenant-scoped.
        require_once __DIR__ . '/includes/class-tzfd-confirmation-lookup.php';
        $tz_confirm_row = TZFD_Confirmation_Lookup::booking(
            $wpdb, (int) $itemnumber, (int) TZFD_Scoped_Store::company_id()
        );
        if ( null === $tz_confirm_row ) { return false; }
        $tz_myrows = array( $tz_confirm_row );
        $tz_params = TZFD_Stored_Data::decode_booking($tz_myrows[0]->posted_data);
        if (null === $tz_params || !isset($tz_myrows[0]->formid)) { return false; }
        if (!isset($tz_params['apps'])) { $tz_params['apps']=array(); }
        $titan_zero_front_desk_pending_request = TZFD_Intake_Mode::is_pending_request( $tz_params );
        $titan_zero_front_desk_settled_request = TZFD_Intake_Mode::settled_request_summary( $tz_params );
        // The form must belong to the booking's company. Company 0 or a mismatch returns no form.
        $tz_booking_company = isset( $tz_myrows[0]->company_id ) ? (int) $tz_myrows[0]->company_id : 0;
        $tz_form_row        = TZFD_Form_Access::row_for_company( $tz_myrows[0]->formid, $tz_booking_company );
        $mycalendarrows     = $tz_form_row ? array( $tz_form_row ) : array(); 
        if (!is_array($mycalendarrows) || !isset($mycalendarrows[0]) || !empty($wpdb->last_error)) { return false; }

        $this->item = intval($tz_myrows[0]->formid);

        $buffer_A = $tz_myrows[0]->data;
        $buffer = $buffer_A;

        if ('true' == $this->get_option('fp_inc_additional_info', TZFD_BOOK_DEFAULT_fp_inc_additional_info))
        {
            $buffer .="ADDITIONAL INFORMATION\n"
                  ."*********************************\n";

            $basic_data = "IP: ".$tz_myrows[0]->ipaddr."\n"
              ."Server Time:  ".gmdate("Y-m-d H:i:s", current_time('timestamp'))."\n";

		    /**
		     *	Includes additional information to the email's message,
		     *  are passed two parameters: the basic information, and the IP address
		     */
		    $basic_data = apply_filters( 'tz_fd_additional_information',  $basic_data, $tz_myrows[0]->ipaddr );
		    $tz_params["additional"] = $basic_data;
            $tz_params["server_time"] = gmdate("Y-m-d H:i:s", current_time('timestamp'));
		    $buffer .= $basic_data;
        }

        // 1- Send email
        //---------------------------
        $attachments = array();

        $tz_message = str_replace('<'.'%', '%', titan_zero_front_desk_translate_legacy_value($this->get_option('fp_message', TZFD_BOOK_DEFAULT_fp_message)));
        $tz_message = str_replace('%'.'>', '%', $tz_message);
        $subject = str_replace('<'.'%', '%', titan_zero_front_desk_translate_legacy_value($this->get_option('fp_subject', TZFD_BOOK_DEFAULT_fp_subject)));
        $subject = str_replace('%'.'>', '%', $subject);

        if ('html' == $this->get_option('fp_emailformat', TZFD_BOOK_DEFAULT_email_format))
            $tz_message = str_replace('%INFO%',str_replace("\n","<br />",esc_html($buffer)),$tz_message);
        else
            $tz_message = str_replace('%INFO%',$buffer,$tz_message);

        /**
		 *	Attach or modify attached files,
		 *  Example for adding ical or PDF attachments
		 */
		$attachments = apply_filters( 'tz_fd_email_attachments',  $attachments, $tz_params, $this->item);

        $tz_params["apps"] = $this->get_appointments_text($tz_params["apps"]);
        foreach ($tz_params as $tz_item => $tz_value)
        {
            $tz_message = str_replace('%'.$tz_item.'%',(is_array($tz_value)?($this->recursive_implode(", ",$tz_value)):($tz_value)),$tz_message);
            $subject = str_replace('%'.$tz_item.'%',(is_array($tz_value)?($this->recursive_implode(", ",$tz_value)):($tz_value)),$subject);
            // Visitor-posted "_link" values are never used as attachment paths. Only server-generated files
            // supplied through the tz_fd_email_attachments filter (for example the iCal invitations) are attached.
        }

        $tz_message = str_replace('%itemnumber%',$itemnumber,$tz_message);
        $subject = str_replace('%itemnumber%',$itemnumber,$subject);

        $from = $this->get_option('fp_from_email', (defined('TZFD_BOOK_DEFAULT_fp_from_email')?TZFD_BOOK_DEFAULT_fp_from_email:''));
        $from_name = $this->get_option('fp_from_name', '');
        $to = explode(",",$this->get_option('fp_destination_emails', (defined('TZFD_BOOK_DEFAULT_fp_destination_emails')?TZFD_BOOK_DEFAULT_fp_destination_emails:'')));
        
        
        $is_html_admin = ('html' == $this->get_option('fp_emailformat', (defined('TZFD_BOOK_DEFAULT_email_format')?TZFD_BOOK_DEFAULT_email_format:'text')));
        $html_filter = function() { return 'text/html'; };
        if ( $is_html_admin ) { add_filter( 'wp_mail_content_type', $html_filter ); }

        $replyto = sanitize_email($tz_myrows[0]->notifyto);

        if ($this->get_option('fp_emailtomethod', "fixed") == 'customer')
        {
            $text_addr = $tz_params[$this->get_option('fp_destination_emails_field', "fixed")];
            if (is_array($text_addr))
                $text_addr = implode(", ",$text_addr);
            $pattern = '/[a-zA-Z0-9_\.\+-]+@[A-Za-z0-9_-]+\.([A-Za-z0-9_-][A-Za-z0-9_]+)/'; //regex for pattern of e-mail address
            preg_match_all($pattern, $text_addr, $matches);
            // Visitor-typed addresses: keep only valid ones, capped at 5. They are added to the admin list, never substituted for it.
            $tz_visitor_to = array_slice( array_values( array_filter( $matches[0], 'is_email' ) ), 0, 5 );
            if (count($tz_visitor_to) > 0)
                $to = array_merge( $to, $tz_visitor_to );
        }
        $to = array_unique ( array_map( 'trim', $to ) );

        $tz_message = $this->replace_tags($tz_message, $tz_params, false, '', $is_html_admin);
        $subject = $this->replace_tags($subject, $tz_params);


        // if is_admin and not required emails end function here
        // The admin "send notification e-mails" choice only counts when the booking form nonce is valid.
        $tz_anonce    = tzfd_request_text( 'post', "anonce" );
        $tz_anonce_ok = wp_verify_nonce( $tz_anonce, 'tz_fd_actions_bookingform' ) || wp_verify_nonce( $tz_anonce, 'tz_fd_actions_admin' );
        $tz_send_now  = $tz_anonce_ok && isset($_POST["sendemails_admin"]);
        if (is_admin() && get_current_user_id() > 0 && $this->check_current_user_access( (int) $this->item ) && !$tz_send_now && !$resendonly)
            return;

        foreach ($to as $tz_item)
            if (trim($tz_item) != '')
            {
                $headers = array();
                $headers[] = "From: " . $from;
                if ($replyto!='')
                    $headers[] = "Reply-To: " . $replyto;
                $headers[] = "X-Mailer: PHP/" . phpversion();

                //wp_mail(trim($tz_item), $subject, $tz_message,
                //    "From: ".$from_1."\r\n".
                //    ($replyto!=''?"Reply-To: ".$replyto."\r\n":'').
                //    $content_type.
                //    "X-Mailer: PHP/" . phpversion(), $attachments);                   

                wp_mail(trim($tz_item), $subject, $tz_message, $headers, $attachments);
            }

        if ( $is_html_admin ) { remove_filter( 'wp_mail_content_type', $html_filter ); }
        
        // A front-end booking does not call check_reports(). The tz_fd_reports_cron event sends these reports on their schedule.

        // 2- Send copy to user
        //---------------------------
        $destination_to = sanitize_email($tz_myrows[0]->notifyto);
        if (($destination_to != '' || $payer_email != '') && 'true' == $this->get_option('cu_enable_copy_to_user', TZFD_BOOK_DEFAULT_cu_enable_copy_to_user))
        {
            $tz_message = str_replace('<'.'%', '%', titan_zero_front_desk_translate_legacy_value($this->get_option('cu_message', TZFD_BOOK_DEFAULT_cu_message)));
            $tz_message = str_replace('%'.'>', '%', $tz_message);

            $subject = str_replace('<'.'%', '%', titan_zero_front_desk_translate_legacy_value($this->get_option('cu_subject', TZFD_BOOK_DEFAULT_cu_subject)));
            $subject = str_replace('%'.'>', '%', $subject);
            if ('html' == $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format))
                $tz_message = str_replace('%INFO%',str_replace("\n","<br />",esc_html($buffer_A)).'</pre>',$tz_message);
            else
                $tz_message = str_replace('%INFO%',$buffer_A,$tz_message);

            $tz_message = str_replace('%itemnumber%',$itemnumber,$tz_message);
            $subject = str_replace('%itemnumber%',$itemnumber,$subject);

            $tz_message = $this->replace_tags($tz_message, $tz_params, false, '', 'html' === $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format));
            $subject = $this->replace_tags($subject, $tz_params);

            // Do not reuse instant-confirm copy or calendar invitations for a pending request.
            $customer_attachments = $attachments;
            if ( $titan_zero_front_desk_pending_request ) {
                $subject = __( 'Booking request received', 'titan-zero-trades-front-desk' );
                $tz_message = TZFD_Intake_Mode::acknowledgment() . "\n\n"
                    . __( 'Request reference:', 'titan-zero-trades-front-desk' ) . ' ' . (int) $itemnumber . "\n\n" . $buffer_A;
                if ( 'html' === $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format) ) {
                    $tz_message = nl2br( esc_html( $tz_message ) );
                }
                $customer_attachments = array();
            } elseif ( null !== $titan_zero_front_desk_settled_request ) {
                $subject = __( 'Booking request status update', 'titan-zero-trades-front-desk' );
                $tz_message = __( 'Current appointment statuses:', 'titan-zero-trades-front-desk' ) . "\n"
                    . $titan_zero_front_desk_settled_request . "\n\n"
                    . __( 'Request reference:', 'titan-zero-trades-front-desk' ) . ' ' . (int) $itemnumber;
                if ( 'html' === $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format) ) {
                    $tz_message = nl2br( esc_html( $tz_message ) );
                }
                $customer_attachments = array();
            }

            if (!strpos($from,">"))
                $from = '"'.($from_name!=''?$from_name:$from).'" <'.$from.'>';            
                        
            $is_html_cu = ('html' == $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format));
            if ( $is_html_cu ) { add_filter( 'wp_mail_content_type', $html_filter ); }
            
            $headers_cu = array();
            $headers_cu[] = "From: " . $from;
            $headers_cu[] = "X-Mailer: PHP/" . phpversion();

            if ($destination_to != '') {
                // Customer-only boundary: management links must never reach admin or payer copies.
                $customer_message = apply_filters('titan_zero_front_desk_customer_copy_message', $tz_message, $tz_myrows[0], $headers_cu, $this);
                wp_mail($destination_to, $subject, $customer_message, $headers_cu, $customer_attachments);
            }
            if ($destination_to != $payer_email && $payer_email != '')
                wp_mail(trim($payer_email), $subject, $tz_message, $headers_cu, $customer_attachments);
                
            if ( $is_html_cu ) { remove_filter( 'wp_mail_content_type', $html_filter ); }            
        }
        
        // Remove generated .ics invitations, but only those inside this plugin's upload folder.
        $tz_upload_dir = wp_upload_dir();
        $tz_ics_base   = realpath( trailingslashit( $tz_upload_dir['basedir'] ) . 'titan-zero-trades-front-desk' );
        foreach ($attachments as $tz_item)
        {
            if ( false === $tz_ics_base || ! is_string( $tz_item ) || 'ics' !== strtolower( pathinfo( $tz_item, PATHINFO_EXTENSION ) ) )
                continue;
            $tz_real_item = realpath( $tz_item );
            if ( false !== $tz_real_item && 0 === strpos( $tz_real_item, trailingslashit( $tz_ics_base ) ) )
                wp_delete_file( $tz_real_item );
        }

    }


    function recursive_implode( $glue, array $array, $include_keys = false, $trim_all = true ) {
    	$glued_string = '';

    	// Recursively iterates array and adds key/value to glued string
    	array_walk_recursive($array, function($tz_value, $tz_key) use ($glue, $include_keys, &$glued_string)
    	{
    		$include_keys and $glued_string .= $tz_key.$glue;
    		$glued_string .= $tz_value.$glue;
    	});

    	// Removes last $glue from string
    	strlen($glue) > 0 and $glued_string = substr($glued_string, 0, -strlen($glue));

    	// Trim ALL whitespace
    	// $trim_all and $glued_string = preg_replace("/(\s)/ixsm", '', $glued_string);

    	return (string) $glued_string;
    }


    function available_templates() {
        if ( ! empty( $this->TZFD_CFPP_global_templates ) ) {
            return $this->TZFD_CFPP_global_templates;
        }

        // Distribution-safe JSON descriptors replace the legacy INI files.
        // Keep the template prefixes and filenames unchanged for saved bookings.
        $this->TZFD_CFPP_global_templates = array();
        $templates_path = plugin_dir_path( __FILE__ ) . 'templates';
        if ( ! is_dir( $templates_path ) ) {
            return $this->TZFD_CFPP_global_templates;
        }

        foreach ( glob( $templates_path . '/*/config.json' ) ?: array() as $descriptor ) {
            $folder = basename( dirname( $descriptor ) );
            if ( ! preg_match( '/^[0-9]{2}$/', $folder ) ) {
                continue;
            }
            $data = json_decode( file_get_contents( $descriptor ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Static bundled JSON descriptor, not a remote resource.
            if ( ! is_array( $data ) ) {
                continue;
            }
            $required = array( 'prefix', 'file', 'title', 'thumbnail', 'description' );
            if ( array_diff( $required, array_keys( $data ) ) ) {
                continue;
            }
            if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', (string) $data['prefix'] ) ) {
                continue;
            }
            foreach ( array( 'file', 'thumbnail' ) as $asset ) {
                $filename = (string) $data[ $asset ];
                if ( '' === $filename || basename( $filename ) !== $filename || ! file_exists( dirname( $descriptor ) . '/' . $filename ) ) {
                    continue 2;
                }
                $data[ $asset ] = plugins_url( 'templates/' . $folder . '/' . $filename, __FILE__ );
            }
            $this->TZFD_CFPP_global_templates[ $data['prefix'] ] = $data;
        }
        return $this->TZFD_CFPP_global_templates;
    }

    function block_multiple()
    {
        global $wpdb;
        // The write method is also used directly: never rely exclusively on the dispatcher.
        if ( 'POST' !== ( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ||
             ! isset( $_POST[ $this->prefix . '_blockmultiple' ] ) ||
             '1' !== tzfd_request_text( 'post', $this->prefix . '_blockmultiple' ) ||
             ! wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_admin' ) ) {
            $this->nonce_failed();
        }
        require_once __DIR__ . '/includes/class-tzfd-admin-write-guard.php';
        TZFD_Admin_Write_Guard::enforce( 200, 262144 );
        // Same capability as the calendar and bookings screens (edit_pages). Checked before any row is written.
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        // Reject malformed selections before any booking rows are written. Never treat 0 as "all calendars".
        // Non-string bdate input is kept as an array so the is_string() check below rejects it, as before.
        if ( isset( $_POST['bdate'] ) && is_string( $_POST['bdate'] ) ) {
            $raw_dates = sanitize_text_field( wp_unslash( $_POST['bdate'] ) );
        } else {
            $raw_dates = isset( $_POST['bdate'] ) ? array() : '';
        }
        if ( isset( $_POST['selectedcalendar'] ) && is_array( $_POST['selectedcalendar'] ) ) {
            $raw_calendars = array_map( 'sanitize_text_field', wp_unslash( $_POST['selectedcalendar'] ) );
        } elseif ( isset( $_POST['selectedcalendar'] ) && is_string( $_POST['selectedcalendar'] ) ) {
            $raw_calendars = sanitize_text_field( wp_unslash( $_POST['selectedcalendar'] ) );
        } else {
            $raw_calendars = array();
        }
        if ( ! is_string( $raw_dates ) || strlen( $raw_dates ) > 4096 || ! is_array( $raw_calendars ) || count( $raw_calendars ) > 100 || empty( $raw_calendars ) ) {
            wp_die( esc_html__( 'Invalid blocked-time selection.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        $rawfromfield = array_map( 'trim', explode( ',', sanitize_text_field( $raw_dates ) ) );
        if ( count( $rawfromfield ) > 100 || in_array( '', $rawfromfield, true ) ) {
            wp_die( esc_html__( 'Invalid blocked-time dates.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        // Validate each date exactly once. strtotime() silently normalizes invalid days.
        foreach ( $rawfromfield as $raw_date ) {
            $tz_valid_date = false;
            if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/D', $raw_date, $tz_parts ) ) {
                $tz_valid_date = checkdate( (int) $tz_parts[2], (int) $tz_parts[3], (int) $tz_parts[1] );
            } elseif ( preg_match( '/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/D', $raw_date, $tz_parts ) ) {
                // Match the configured legacy date order, rather than guessing.
                $tz_date_order = $this->get_option( 'date_format', 'mm/dd/yy' );
                $tz_month = 'dd/mm/yy' === $tz_date_order ? (int) $tz_parts[2] : (int) $tz_parts[1];
                $tz_day   = 'dd/mm/yy' === $tz_date_order ? (int) $tz_parts[1] : (int) $tz_parts[2];
                $tz_valid_date = checkdate( $tz_month, $tz_day, (int) $tz_parts[3] );
            }
            if ( ! $tz_valid_date ) {
                wp_die( esc_html__( 'Invalid blocked-time date.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
            }
        }
        $calendars = array();
        foreach ( $raw_calendars as $raw_calendar ) {
            if ( ! is_scalar( $raw_calendar ) || ! ctype_digit( (string) $raw_calendar ) || absint( $raw_calendar ) < 1 ) {
                wp_die( esc_html__( 'Invalid calendar.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
            }
            $calendar = absint( $raw_calendar );
            if ( ! $this->check_current_user_access( $calendar ) ) {
                wp_die( esc_html__( 'Calendar access denied.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
            }
            $calendars[] = $calendar;
        }
        $calendars = array_unique( $calendars );
        $company_id = class_exists( 'TZFD_Scoped_Store' ) ? absint( TZFD_Scoped_Store::company_id() ) : 0;
        if ( $company_id < 1 ) {
            wp_die( esc_html__( 'Company context required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        $tzfd_block_clock = array();
        foreach ( array( 'h1' => 23, 'h2' => 23, 'm1' => 59, 'm2' => 59 ) as $part => $max ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- strict digit-only check on the next line; sanitize_text_field() would trim whitespace and change which values pass.
            $value = isset( $_POST[ $part ] ) ? wp_unslash( $_POST[ $part ] ) : null;
            if ( ! is_scalar( $value ) || ! ctype_digit( (string) $value ) || (int) $value > $max ) {
                wp_die( esc_html__( 'Invalid blocked-time hours.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
            }
            $tzfd_block_clock[ $part ] = (int) $value;
        }
        $current_user = wp_get_current_user();
        
        foreach ($rawfromfield as $tz_rawfrom)
        {
            $tz_rawfrom = trim($tz_rawfrom);
            
            foreach ($calendars as $calendar)
            {
                $this->setId( $calendar );
                // Reject invalid and reversed intervals before writing a blocked slot.
                $start_ts = strtotime( $tz_rawfrom . ' ' . $tzfd_block_clock['h1'] . ':' . $tzfd_block_clock['m1'] );
                $end_ts   = strtotime( $tz_rawfrom . ' ' . $tzfd_block_clock['h2'] . ':' . $tzfd_block_clock['m2'] );
                if ( false === $start_ts || false === $end_ts || $end_ts <= $start_ts || $end_ts - $start_ts > DAY_IN_SECONDS ) {
                    wp_die( esc_html__( 'Invalid blocked-time interval.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
                }

                // Skip any calendar the current user cannot manage. The check uses the same ID that the row is written
                // with ($calendar), so calendar 0 is checked as formid 0 and not against the first form. Every non-zero
                // calendar is checked exactly as before. The first form is still loaded above for the date format.
                if ( $this->check_current_user_access( $calendar ) )
                {

                    if ($this->get_option('date_format', 'mm/dd/yy') == 'dd/mm/yy')
                        $tz_rawfrom = str_replace('/','.',$tz_rawfrom);
                    $from = gmdate("Y-m-d H:i", strtotime($tz_rawfrom. " ".tzfd_request_text( 'post', "h1" ). ":".tzfd_request_text( 'post', "m1" )));
                    $to = gmdate("Y-m-d H:i", strtotime($tz_rawfrom. " ".tzfd_request_text( 'post', "h2" ). ":".tzfd_request_text( 'post', "m2" )));
                    $duration = ceil((strtotime($tz_rawfrom. " ".tzfd_request_text( 'post', "h2" ). ":".tzfd_request_text( 'post', "m2" )) -  strtotime($tz_rawfrom. " ".tzfd_request_text( 'post', "h1" ). ":".tzfd_request_text( 'post', "m1" )))  / 60);
                            
                    
                    $apps = array();
                    $apps[] = array ( 'id' => 1,
                                      'cancelled' => '',
                                      'serviceindex' => -1,
                                      'service' => __('[all services]','titan-zero-trades-front-desk'),
                                      'duration' => $duration,
                                      'price' => 0, //$field->services[ $item_split[2] ]->price,
                                      'date' => gmdate("Y-m-d", strtotime($tz_rawfrom. " ".tzfd_request_text( 'post', "h1" ). ":".tzfd_request_text( 'post', "m1" ))),
                                      'slot' => gmdate("H:i", strtotime($tz_rawfrom. " ".tzfd_request_text( 'post', "h1" ). ":".tzfd_request_text( 'post', "m1" )))."/". gmdate("H:i", strtotime($tz_rawfrom. " ".tzfd_request_text( 'post', "h2" ). ":".tzfd_request_text( 'post', "m2" ))),
                                      'military' => 0,
                                      'field' => '',
                                      'quant' => 0
                                    );    
                                    
                     $apptext = __('BLOCKED BY ADMIN','titan-zero-trades-front-desk').  "\n\n" . $this->get_appointments_text ($apps);                        
                     
                      $tz_params = array();
                     $tz_params["apps"] = $apps;
                     $current_user = wp_get_current_user();
                     $rows_affected = $wpdb->insert( $wpdb->prefix.TZFD_Tables::BOOKINGS, array( 'company_id' => $company_id, 'formid' => $calendar,
                                                                                                 'time' => current_time('mysql'),
                                                                                                 'ipaddr' => (get_option('tz_fd_storeip', TZFD_BOOK_DEFAULT_track_IP)? tzfd_request_text( 'server', 'REMOTE_ADDR' ) : ''),
                                                                                                 'notifyto' => $this->blocked_by_admin_indicator,
                                                                                                 'posted_data' => serialize($tz_params),
                                                                                                 'data' => $apptext,
                                                                                                 'whoadded' => "".$current_user->ID
                                                                                                ) );
                }
            }
        }                                                                           
    }
	

    function save_edition() {
        require_once __DIR__ . '/includes/class-tzfd-admin-write-guard.php';
        TZFD_Admin_Write_Guard::enforce();
        global $wpdb;

        if ( ! wp_verify_nonce( tzfd_request_text( 'post', "nonce" ), 'tz_fd_actions_admin' ) ) { $this->nonce_failed(); }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }

        $posted_items = array();
        foreach ($_POST as $tz_item => $tz_value)
            $posted_items[sanitize_key($tz_item)] = $this->sanitize( wp_unslash( $tz_value ) );
        if (isset($_POST["gotab"]) && tzfd_request_text( 'post', "gotab" ) == '')
        {
            update_option( 'tz_fd_rep_enable', sanitize_text_field($posted_items["tz_fd_rep_enable"]));
            update_option( 'tz_fd_rep_days', sanitize_text_field($posted_items["tz_fd_rep_days"]));
            update_option( 'tz_fd_rep_hour', sanitize_text_field($posted_items["tz_fd_rep_hour"]));
            update_option( 'tz_fd_rep_emails', sanitize_text_field($posted_items["tz_fd_rep_emails"]));
            update_option( 'tz_fd_fp_from_email', sanitize_text_field($posted_items["tz_fd_fp_from_email"]));
            // Report subject and body are sent as plain text without placeholder replacement, so they are sanitized directly.
            update_option( 'tz_fd_rep_subject', tzfd_request_text( 'post', "tz_fd_rep_subject" ));
            update_option( 'tz_fd_rep_emailformat', sanitize_text_field($posted_items["tz_fd_rep_emailformat"]));
            if ( 'html' === sanitize_text_field( $posted_items["tz_fd_rep_emailformat"] ) ) {
                update_option( 'tz_fd_rep_message', wp_kses_post( tzfd_request_text( 'post', 'tz_fd_rep_message' ) ));
            } else {
                update_option( 'tz_fd_rep_message', sanitize_textarea_field( tzfd_request_text( 'post', "tz_fd_rep_message" ) ));
            }
        }
        else if (isset($_POST["gotab"]) && tzfd_request_text( 'post', "gotab" ) == 'csvarea')
        {
            update_option( 'tz_fd_bocsvexclude', sanitize_text_field($posted_items["bocsvexclude"]));
            update_option( 'tz_fd_schcsvexclude', sanitize_text_field($posted_items["schcsvexclude"]));
        }
        else if (isset($_POST["gotab"]) && tzfd_request_text( 'post', "gotab" ) == 'miscsettings')
        {
            $honeypot = sanitize_key($posted_items["tz_fd_honeypot"]);
            // sanitize_key() lowercases the posted value, so compare against the lowercase sentinel.
            if ($honeypot == 'yes')
                $honeypot = 'tzfdhb'.substr(sanitize_key(md5(wp_generate_uuid4())),0,5);
            update_option( 'tz_fd_honeypot', $honeypot);
            update_option( 'tz_fd_storeip', sanitize_text_field($posted_items["tz_fd_storeip"]));
            // Only save the language when the field was posted, so a save without it never blanks the stored value.
            if ( isset( $_POST["tz_fd_admin_language"] ) ) {
                update_option( 'tz_fd_admin_language', tzfd_request_text( 'post', "tz_fd_admin_language" ) );
            }
            if ( current_user_can( 'tz_fd_access' ) ) {
                TZFD_Intake_Mode::set_default( TZFD_Intake_Mode::normalize( sanitize_key( tzfd_request_text( 'post', 'intake_default' ) ) ) );
                $tz_intake_posted = ( isset( $_POST['intake_form'] ) && is_array( $_POST['intake_form'] ) ) ? map_deep( wp_unslash( $_POST['intake_form'] ), 'sanitize_text_field' ) : array();
                foreach ( $tz_intake_posted as $tz_intake_fid => $tz_intake_mode ) {
                    TZFD_Intake_Mode::set_for_form( (int) $tz_intake_fid, TZFD_Intake_Mode::normalize( sanitize_key( (string) $tz_intake_mode ) ) );
                }
            }
        }
        else if (current_user_can('tz_fd_access') && isset($_POST["gotab"]) && tzfd_request_text( 'post', "gotab" ) == 'schedulecalarea')
        {
            update_option( 'tz_fd_schcaltitle_admin', $this->clean_sanitize($posted_items["schcaltitle_admin"]));
            update_option( 'tz_fd_schcalcontent_admin', $this->clean_sanitize($posted_items["schcalcontent_admin"]));
            update_option( 'tz_fd_schcaltitle_public', $this->clean_sanitize($posted_items["schcaltitle_public"]));
            update_option( 'tz_fd_schcalcontent_public', $this->clean_sanitize($posted_items["schcalcontent_public"]));
            update_option( 'tz_fd_sch_admin_blockedt', sanitize_text_field($posted_items["tz_fd_sch_admin_blockedt"]));
            update_option( 'tz_fd_schcalcontent_exclude', trim(str_replace(' ','',str_replace('>','',str_replace('<','',str_replace('%','',tzfd_request_text( 'post', "tz_fd_schcalcontent_exclude" )))))));
            update_option( 'tz_fd_schcalcontent_otherparams', $this->clean_sanitize($posted_items["tz_fd_schcalcontent_otherparams"]));

        }
        else if (current_user_can('tz_fd_access') && isset($_POST["gotab"]) && tzfd_request_text( 'post', "gotab" ) == 'fixarea')
        {
            update_option( 'TZFD_LOAD_SCRIPTS', ($posted_items["ccscriptload"]=="1"?"0":"1") );
            update_option( 'TZFD_CSV_CHARFIX', ($posted_items["csvcharautofix"]==""?"":"1") );
            update_option( 'TZFD_CSV_SEPARATOR', ($posted_items["csvseparator"]==";"?";":",") );
            if ($posted_items["cccharsets"] != '')
            {
                $target_charset = str_replace('`','``',sanitize_text_field($posted_items["cccharsets"]));
                $tables = array( $wpdb->prefix.TZFD_Tables::BOOKINGS, $wpdb->prefix.TZFD_Tables::FORMS );
                foreach ($tables as $tab)
                {
                    $tz_tab_name = $this->sanitizeTableName($tab);
                    $tz_myrows   = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $tz_tab_name ) );
                    $tz_collate  = $this->sanitizeTableName($target_charset);
                    foreach ($tz_myrows as $tz_item)
	                {
	                    $tz_col_name = $this->sanitizeTableName($tz_item->Field);
	        	        $type = $tz_item->Type;
	        	        // Column types are matched against a fixed list. Identifiers use %i and the length uses %d.
	        	        if (preg_match("/^varchar\((\d+)\)$/i", $type, $mat))
	        	            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i VARCHAR(%d) COLLATE %i', $tz_tab_name, $tz_col_name, $tz_col_name, (int) $mat[1], $tz_collate ) );
	        	        elseif (!strcasecmp($type, "CHAR"))
	        	            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i CHAR COLLATE %i', $tz_tab_name, $tz_col_name, $tz_col_name, $tz_collate ) );
	        	        elseif (!strcasecmp($type, "TEXT"))
	        	            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i TEXT COLLATE %i', $tz_tab_name, $tz_col_name, $tz_col_name, $tz_collate ) );
	        	        elseif (!strcasecmp($type, "MEDIUMTEXT"))
	        	            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i MEDIUMTEXT COLLATE %i', $tz_tab_name, $tz_col_name, $tz_col_name, $tz_collate ) );
	                }
                }
            }
        }
        // The custom CSS editor (editionarea) was removed. Stored TZFD_CSS data is left untouched and is not saved or output.
    }


    function save_options() {
        require_once __DIR__ . '/includes/class-tzfd-admin-write-guard.php';
        TZFD_Admin_Write_Guard::enforce();
        global $wpdb;
        require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';
        $tz_save_calendar_id = TZFD_Admin_Request_Ids::positive( isset( $_POST[ $this->prefix . '_id' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $this->prefix . '_id' ] ) ) : null );
        if ( null === $tz_save_calendar_id ) {
            wp_die( esc_html__( 'Invalid booking calendar.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        $this->item = $tz_save_calendar_id;

        if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_admin' ) ) { $this->nonce_failed(); }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        // The route checked the calendar named in the query string (get_param() reads GET first). This function writes to the
        // calendar in the POST body, so check that calendar too. Without this, the two could differ.
        if ( ! $this->check_current_user_access( $this->item ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }

        if (false == get_option('TZFD_ONE_TIME_3UPDATE',false))
        {
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'fp_from_name');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'vs_text_nmore');
			$this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'vs_text_nomore');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'vs_text_cost');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'vs_text_cancel');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'vs_text_quantity');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'calendar_language');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'date_format');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'vs_text_maxapp');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'defaultstatus');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'defaultpaidstatus');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'tz_fd_user_access_settings');
            $this->add_field_verify($wpdb->prefix.TZFD_Tables::FORMS, 'display_emails_endtime');
            update_option('TZFD_ONE_TIME_3UPDATE',true);
        }
        $_pdata = wp_unslash( $_POST );
        if ( ! isset( $_pdata['form_structure'] ) || ! is_string( $_pdata['form_structure'] ) ||
            strlen( $_pdata['form_structure'] ) > 1048576 || '' === trim( $_pdata['form_structure'] ) ) {
            wp_die( esc_html__( 'Invalid form configuration.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        while ((substr_count($_pdata['form_structure'],"\\") > 30) || substr_count($_pdata['form_structure'],"\\\"title\\\":"))
            foreach ($_pdata as $tz_item => $tz_value)
                if (!is_array($tz_value))
                    $_pdata[$tz_item] = stripcslashes($tz_value);

        $tz_fp_is_html  = ( 'html' === sanitize_text_field( $_pdata['fp_emailformat'] ?? '' ) ) ? 'html' : 'text';
        $tz_cu_is_html  = ( 'html' === sanitize_text_field( $_pdata['cu_emailformat'] ?? '' ) ) ? 'html' : 'text';

        $tzfd_verified_structure = $this->admin_process_json( $_pdata['form_structure'] );
        if ( '' === $tzfd_verified_structure ) {
            wp_die( esc_html__( 'Invalid booking form structure.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        $tz_data = array(
                      'form_structure' => $this->clean_sanitize( $tzfd_verified_structure ),

                      'vs_text_maxapp' => sanitize_text_field($_pdata['vs_text_maxapp']),
                      'calendar_language' => sanitize_text_field($_pdata['calendar_language']),
                      'date_format' => sanitize_text_field($_pdata['date_format']),
                      'product_name' => sanitize_text_field($_pdata['product_name']),
                      'pay_later_label' => sanitize_text_field($_pdata['pay_later_label']),
                      'fp_from_email' => sanitize_text_field($_pdata['fp_from_email']),
                     // 'fp_from_name' => sanitize_text_field($_pdata['fp_from_name']),
                      'fp_destination_emails' => sanitize_text_field(@$_pdata['fp_destination_emails']),
                      'fp_subject' => $this->tz_fd_sanitize_template_value( $_pdata['fp_subject'] ?? '', 'line' ),
                      'fp_inc_additional_info' => sanitize_text_field($_pdata['fp_inc_additional_info']),
                      'fp_return_page' => sanitize_text_field($_pdata['fp_return_page']),
                      'fp_message' => $this->tz_fd_sanitize_template_value( $_pdata['fp_message'] ?? '', $tz_fp_is_html ),
                      'fp_emailformat' => sanitize_text_field($_pdata['fp_emailformat']),

                      'defaultstatus' => sanitize_text_field($_pdata['defaultstatus']),
                      'defaultpaidstatus' => sanitize_text_field($_pdata['defaultpaidstatus']),

                      'fp_emailtomethod' => sanitize_text_field($_pdata['fp_emailtomethod']),
                      'fp_destination_emails_field' => sanitize_text_field(@$_pdata['fp_destination_emails_field']),

                      'cu_enable_copy_to_user' => sanitize_text_field($_pdata['cu_enable_copy_to_user']),
                      'cu_user_email_field' => sanitize_text_field(@$_pdata['cu_user_email_field']),
                      'cu_subject' => $this->tz_fd_sanitize_template_value( $_pdata['cu_subject'] ?? '', 'line' ),
                      'cu_message' => $this->tz_fd_sanitize_template_value( $_pdata['cu_message'] ?? '', $tz_cu_is_html ),
                      'cu_emailformat' => sanitize_text_field($_pdata['cu_emailformat']),
                      'fp_emailfrommethod' => sanitize_text_field($_pdata['fp_emailfrommethod']),

                      'vs_text_is_required' => sanitize_text_field($_pdata['vs_text_is_required']),
                      'vs_text_is_email' => sanitize_text_field($_pdata['vs_text_is_email']),
                      'vs_text_datemmddyyyy' => sanitize_text_field($_pdata['vs_text_datemmddyyyy']),
                      'vs_text_dateddmmyyyy' => sanitize_text_field($_pdata['vs_text_dateddmmyyyy']),
                      'vs_text_number' => sanitize_text_field($_pdata['vs_text_number']),
                      'vs_text_digits' => sanitize_text_field($_pdata['vs_text_digits']),
                      'vs_text_max' => sanitize_text_field($_pdata['vs_text_max']),
                      'vs_text_min' => sanitize_text_field($_pdata['vs_text_min']),
                      'vs_text_pageof' => sanitize_text_field($_pdata['vs_text_pageof']),
                      'vs_text_submitbtn' => sanitize_text_field($_pdata['vs_text_submitbtn']),
                      'vs_text_previousbtn' => sanitize_text_field($_pdata['vs_text_previousbtn']),
                      'vs_text_nextbtn' => sanitize_text_field($_pdata['vs_text_nextbtn']),

                      'vs_text_quantity' => sanitize_text_field($_pdata['vs_text_quantity']),
                      'vs_text_cancel' => sanitize_text_field($_pdata['vs_text_cancel']),
                      'vs_text_cost' => sanitize_text_field($_pdata['vs_text_cost']),
					  'vs_text_nomore' => sanitize_text_field($_pdata['vs_text_nomore']),   
                      'vs_text_nmore' => sanitize_text_field($_pdata['vs_text_nmore']),

                      'tz_fd_user_access' => serialize($this->sanitize( ( isset($_pdata["tz_fd_user_access"]) ? $_pdata["tz_fd_user_access"] : array() ) )),
                      'tz_fd_user_access_settings' => sanitize_text_field($_pdata['tz_fd_user_access_settings']),
                      'display_emails_endtime' => sanitize_text_field($_pdata['display_emails_endtime']),
                      'rep_enable' => sanitize_text_field($_pdata['rep_enable']),
                      'rep_days' => sanitize_text_field($_pdata['rep_days']),
                      'rep_hour' => sanitize_text_field($_pdata['rep_hour']),
                      'rep_emails' => sanitize_text_field($_pdata['rep_emails']),
                      'rep_subject' => $this->tz_fd_sanitize_template_value( $_pdata['rep_subject'] ?? '', 'line' ),
                      'rep_emailformat' => sanitize_text_field($_pdata['rep_emailformat']),
                      'rep_message' => $this->tz_fd_sanitize_template_value( $_pdata['rep_message'] ?? '', ( 'html' === sanitize_text_field( $_pdata['rep_emailformat'] ?? '' ) ? 'html' : 'text' ) ),

                      'cv_enable_captcha' => sanitize_text_field($_pdata['cv_enable_captcha']),
                      'cv_width' => sanitize_text_field($_pdata['cv_width']),
                      'cv_height' => sanitize_text_field($_pdata['cv_height']),
                      'cv_chars' => sanitize_text_field($_pdata['cv_chars']),
                      'cv_font' => sanitize_text_field($_pdata['cv_font']),
                      'cv_min_font_size' => sanitize_text_field($_pdata['cv_min_font_size']),
                      'cv_max_font_size' => sanitize_text_field($_pdata['cv_max_font_size']),
                      'cv_noise' => sanitize_text_field($_pdata['cv_noise']),
                      'cv_noise_length' => sanitize_text_field($_pdata['cv_noise_length']),
                      'cv_background' => sanitize_text_field(str_replace('#','',$_pdata['cv_background'])),
                      'cv_border' => sanitize_text_field(str_replace('#','',$_pdata['cv_border'])),
                      'cv_text_enter_valid_captcha' => sanitize_text_field($_pdata['cv_text_enter_valid_captcha'])
    	);

        // Access lists decide who may manage bookings, so only administrators may change them.
        if ( ! current_user_can( 'tz_fd_access' ) ) {
            unset( $tz_data['tz_fd_user_access'], $tz_data['tz_fd_user_access_settings'] );
        }

        $wpdb->update( $wpdb->prefix.TZFD_Tables::FORMS, $tz_data, array( 'id' => $this->item, 'company_id' => TZFD_Scoped_Store::company_id() ));

        $tz_data = array( 'fp_from_name' => sanitize_text_field($_pdata['fp_from_name']) );
        $wpdb->update( $wpdb->prefix.TZFD_Tables::FORMS, $tz_data, array( 'id' => $this->item, 'company_id' => TZFD_Scoped_Store::company_id() ));

        // save_options() runs from the init hook, before any output, so a normal redirect is possible.
        if (isset($_pdata["savepublish"]))
        {
            wp_safe_redirect( admin_url( 'admin.php?page=tzfd&pwizard=1&cal=' . intval($this->item) ) );
            exit;
        } else if (isset($_pdata["savereturn"]))
        {
            wp_safe_redirect( admin_url( 'admin.php?page=tzfd&confirm=1' ) );
            exit;
        }
    }


    function get_form_field_label( $fieldid, $tz_form ) {
            foreach($tz_form as $tz_item)
                if ($tz_item->name == $fieldid)
                {
                    if (isset($tz_item->shortlabel) && $tz_item->shortlabel != '')
                        return $tz_item->shortlabel;
                    else
                        return $tz_item->title;
                }
        return $fieldid;
    }


    function generateSafeFileName( $filename ) {
        $filename = strtolower( wp_strip_all_tags( $filename ) );
        $filename = str_replace( ';', '_', $filename );
        $filename = str_replace( '#', '_', $filename );
        $filename = str_replace( ' ', '_', $filename );
        $filename = str_replace( "'", '', $filename );
        $filename = str_replace( '"', '', $filename );
        $filename = str_replace( '__' ,'_', $filename );
        $filename = str_replace( '&', 'and', $filename );
        $filename = str_replace( '/', '_', $filename );
        $filename = str_replace( '\\' ,'_', $filename );
        $filename = str_replace( '?', '', $filename );
        return sanitize_file_name( $filename );
    }

    function clean_csv_value( $tz_value ) {
        // A quoted CSV field can still execute as a spreadsheet formula. Block formula
        // prefixes even when hidden behind whitespace, newlines or a UTF-8 BOM.
        if ( ! is_scalar( $tz_value ) && null !== $tz_value ) { return ''; }
        $value = (string) $tz_value;
        $value = str_replace( array( "\r\n", "\r" ), "\n", $value );
        // Preserve ordinary spaces inside values, but remove spreadsheet control characters.
        $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
        $probe = preg_replace( '/^(?:\xEF\xBB\xBF|[\s\x{00A0}\x{2000}-\x{200B}\x{FEFF}])+/u', '', $value );
        if ( null === $probe ) {
            // Latin-1 values (already converted) are not valid UTF-8, so strip byte-level whitespace and BOM instead.
            $probe = preg_replace( '/^(?:\xEF\xBB\xBF|[\s\xA0])+/', '', $value );
        }
        $first = ( '' !== $value ) ? $value[0] : '';
        if ( in_array( $first, array( "\t", "\r" ), true ) || ( is_string( $probe ) && '' !== $probe && in_array( $probe[0], array( '=', '+', '-', '@' ), true ) ) ) {
            return "'" . $value;
        }
        return $value;
    }

    /** CSV delimiter must be a single supported character, never an option-controlled string. */
    private function csv_separator() {
        return ';' === get_option( 'TZFD_CSV_SEPARATOR', ',' ) ? ';' : ',';
    }


    function export_csv() {
        require_once __DIR__ . '/includes/class-tzfd-admin-export-request.php';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only CSV export; enforce() checks capability, GET method and the tz_fd_actions_booking nonce before anything else runs.
        TZFD_Admin_Export_Request::enforce( $_REQUEST );
        if ( !is_admin() )
            return;
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        global $wpdb;

        $this->item = intval( $this->get_param("cal") );
        require_once __DIR__ . '/includes/class-tzfd-admin-report-scope.php';
        $tzfd_export_company = TZFD_Admin_Report_Scope::enforce( $this->item );

        if ( $this->item )
        {
            $tz_form = json_decode( $this->cleanJSON( $this->get_option( 'form_structure', TZFD_BOOK_DEFAULT_form_structure ) ) );
            $tz_form = ( is_array( $tz_form ) && isset( $tz_form[0] ) ) ? $tz_form[0] : array();
        }
        else
            $tz_form = array();

        // Every condition is a placeholder in one prepared query. A condition that is not in use is switched off by its flag.
        $tz_has_search = (bool) $this->get_param( 'search' );
        $tz_like       = $tz_has_search ? '%' . $wpdb->esc_like( $this->get_param( 'search' ) ) . '%' : '';

        // Export filters are read only after TZFD_Admin_Export_Request::enforce() validates capability and nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only export filter; nonce verified by TZFD_Admin_Export_Request::enforce() above.
        $tz_rawfrom = isset( $_GET['dfrom'] ) ? tzfd_request_text( 'get', 'dfrom' ) : '';
        // Export filters are read only after TZFD_Admin_Export_Request::enforce() validates capability and nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only export filter; nonce verified by TZFD_Admin_Export_Request::enforce() above.
        $tz_rawto   = isset( $_GET['dto'] )   ? tzfd_request_text( 'get', 'dto' ) : '';

        if ( $this->get_option( 'date_format', 'mm/dd/yy' ) === 'dd/mm/yy' ) {
            $tz_rawfrom = str_replace( '/', '.', $tz_rawfrom );
            $tz_rawto   = str_replace( '/', '.', $tz_rawto );
        }

        require_once __DIR__ . '/includes/class-tzfd-export-dates.php';
        $tz_has_from = '' !== $tz_rawfrom;
        $tz_has_to   = '' !== $tz_rawto;
        $tzfd_dates  = TZFD_Export_Dates::range( $tz_has_from ? $tz_rawfrom : null, $tz_has_to ? $tz_rawto : null );
        if ( null === $tzfd_dates ) {
            wp_die( esc_html__( 'Invalid export date range.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        $tz_date_start = $tz_has_from ? $tzfd_dates[0] : '';
        $tz_date_end   = $tz_has_to ? $tzfd_dates[1] . ' 23:59:59' : '';

        $tz_has_form = ( $this->item != 0 );

        $tz_table_name = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        $tz_events_query = $wpdb->prepare(
            'SELECT * FROM %i WHERE company_id=%d AND (%d = 1 OR (data LIKE %s OR posted_data LIKE %s)) AND (%d = 1 OR `time` >= %s) AND (%d = 1 OR `time` <= %s) AND (%d = 1 OR formid = %d) ORDER BY `time` DESC',
            $tz_table_name, $tzfd_export_company,
            $tz_has_search ? 0 : 1, $tz_like, $tz_like,
            $tz_has_from ? 0 : 1, $tz_date_start,
            $tz_has_to ? 0 : 1, $tz_date_end,
            $tz_has_form ? 0 : 1, (int) $this->item
        );

        /**
         * Allows modify the query of messages, passing the query as parameter
         * returns the new query
         */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Developer-only filter, kept with its existing contract. The query passed in is built by $wpdb->prepare() above, with every request value (search, dfrom, dto, form id) as a placeholder. No request input is concatenated into it. Filter callbacks are registered by code, never from request data. The sniff cannot see through apply_filters(), so the annotation stays.
        $tz_events = $wpdb->get_results( apply_filters( 'tz_fd_csv_query', $tz_events_query ) );
        require_once __DIR__ . '/includes/class-tzfd-csv-booking-fields.php';
        if ( ! TZFD_CSV_Booking_Fields::valid_query_result( $tz_events ) ) {
            wp_die( esc_html__( 'Booking export could not be completed. Please retry.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) );
        }

        if ($this->include_user_data_csv)
            $fields = array("ID", "Form", "Time", "IP Address", "email");
        else
            $fields = array("ID", "Form", "Time", "email");

        $fields_exclude = explode(",",trim(get_option('tz_fd_bocsvexclude',"")));
        $fields_exclude_org = array();
        for($tz_j=0; $tz_j<count($fields_exclude); $tz_j++)
        {
           $fields_exclude_org[] = (trim($fields_exclude[$tz_j]));
           $fields_exclude[$tz_j] = strtolower(trim($fields_exclude[$tz_j]));
        }

        $fields = array_values(array_diff($fields, $fields_exclude_org));

        $newfields = array();
        for($tz_j=0; $tz_j<count($fields); $tz_j++)
           if (!in_array($fields[$tz_j],$fields_exclude))
               $newfields[] = $fields[$tz_j];
        $fields = $newfields;

        $saved_formid = $this->item;

        $values = array();
        foreach ($tz_events as $tz_item)
        {
            $tz_value = array();
            if (in_array('ID',$fields)) $tz_value[] = $tz_item->id;
            $this->item = $tz_item->formid;
            if (in_array('Form',$fields)) $tz_value[] = $this->get_option('form_name','');
            $this->item = $saved_formid;
            if (in_array('Time',$fields)) $tz_value[] = $tz_item->time;
            if ($this->include_user_data_csv)
                if (in_array('IP Address',$fields)) $tz_value[] = $tz_item->ipaddr;
            if (in_array('email',$fields)) $tz_value[] = sanitize_email($tz_item->notifyto);

            $tz_data = !empty($tz_item->posted_data) ? TZFD_Stored_Data::decode_booking($tz_item->posted_data) : array();
            if (!is_array($tz_data)) { $tz_data=array(); }

            $end = count($fields);
            for ($tz_i=0; $tz_i<$end; $tz_i++)
                {  //  if (isset($tz_data[$fields[$tz_i]]) )
                    if (isset($tz_data[$fields[$tz_i]]))
                        $tz_d = $tz_data[$fields[$tz_i]];
                    else if (!isset($tz_value[$tz_i]))
                        $tz_d = "";
                    else
                        $tz_d = $tz_value[$tz_i];
                    if (substr($fields[$tz_i],0,strlen('app_status_')) == 'app_status_')
                    {
                       $tz_d = TZFD_CSV_Booking_Fields::appointment_status( $tz_data, (string) $fields[$tz_i] );
                       if ($tz_d == '')
                           $tz_d = __('Approved','titan-zero-trades-front-desk');
                    }
                    $tz_value[$tz_i] = $tz_d;
                    unset($tz_data[$fields[$tz_i]]);
                }

            if (is_array($tz_data)) foreach ($tz_data as $tz_k => $tz_d)
                if ($tz_k != 'apps' && $tz_k != 'itemnumber' && !in_array(strtolower($tz_k),$fields_exclude))
                {
                   $fields[] = $tz_k;
                   if (substr($tz_k,0,strlen('app_status_')) == 'app_status_')
                   {
                       $tz_d = TZFD_CSV_Booking_Fields::appointment_status( $tz_data, (string) $tz_k );
                       if ($tz_d == '')
                           $tz_d = __('Approved','titan-zero-trades-front-desk');
                   }
                   $tz_value[] = $tz_d;
                }
            $values[] = $tz_value;
        }

        $separator = $this->csv_separator();

        $filename = $this->generateSafeFileName(strtolower($this->get_option('form_name','export'))).'_'.gmdate("m_d_y");

        header("Content-type: application/octet-stream");
        header("Content-Disposition: attachment; filename=".$filename.".csv");
        // CSV is a file download, not HTML, so rows are written to the response stream unchanged.
        $tz_csv = '';
        $end = count($fields);
        for ($tz_i=0; $tz_i<$end; $tz_i++)
        {
            $hlabel = $this->iconv("utf-8", "ISO-8859-1//TRANSLIT//IGNORE", $this->get_form_field_label($fields[$tz_i],$tz_form));
            $tz_csv .= '"'.str_replace('"','""', $this->clean_csv_value($hlabel)).'"'.$separator;
        }

        $tz_csv .= "\n";
        foreach ( $values as $tz_item )
        {
            for ( $tz_i=0; $tz_i<$end; $tz_i++ )
            {
                if ( !isset( $tz_item[$tz_i] ) )
                    $tz_item[$tz_i] = '';
                if (is_array($tz_item[$tz_i]))
                    $tz_item[$tz_i] = implode(',',$tz_item[$tz_i]);
                $tz_item[$tz_i] = $this->iconv("utf-8", "ISO-8859-1//TRANSLIT//IGNORE", $tz_item[$tz_i]);
                $tz_csv .= '"'.str_replace('"','""', $this->clean_csv_value($tz_item[$tz_i])).'"'.$separator;
            }
            $tz_csv .= "\n";
        }

        echo $tz_csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file download, not HTML; the cells are CSV-quoted above.
        exit;
    }


    function export_csv_schedule( $atts ) {
        require_once __DIR__ . '/includes/class-tzfd-admin-export-request.php';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only CSV export; enforce() checks capability, GET method and the tz_fd_actions_booking nonce before anything else runs.
        TZFD_Admin_Export_Request::enforce( $_REQUEST );
        if ( !is_admin() )
            return;
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        global $wpdb;
        extract( shortcode_atts( array(
	    	'calendar' => '',
	    	'fields' => 'DATE,TIME,SERVICE,final_price,paid,email,data,cancelled',
	    	'from' => "today",
	    	'to' => "today +30 days",
            'paidonly' => "",
            'status' => "-1"
	    ), $atts ) );

        // Export filters are read only after TZFD_Admin_Export_Request::enforce() validates capability and nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only export filter; nonce verified by TZFD_Admin_Export_Request::enforce() above.
        if (isset($_GET["dfrom"])) $from = tzfd_request_text( 'get', "dfrom" );
        // Export filters are read only after TZFD_Admin_Export_Request::enforce() validates capability and nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only export filter; nonce verified by TZFD_Admin_Export_Request::enforce() above.
        if (isset($_GET["dto"])) $to = tzfd_request_text( 'get', "dto" );
        // Export filters are read only after TZFD_Admin_Export_Request::enforce() validates capability and nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only export filter; nonce verified by TZFD_Admin_Export_Request::enforce() above.
        if (isset($_GET["status"])) $status = tzfd_request_text( 'get', "status" );
        // Export filters are read only after TZFD_Admin_Export_Request::enforce() validates capability and nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only export filter; nonce verified by TZFD_Admin_Export_Request::enforce() above.
        if (isset($_GET["paid"])) $paidonly = tzfd_request_text( 'get', "paid" );
        require_once __DIR__ . '/includes/class-tzfd-export-dates.php';
        // Shortcode attributes and request values are untrusted; never coerce malformed IDs to zero (all calendars).
        $tzfd_requested_calendar = $this->get_param( 'cal' );
        $tzfd_calendar_input = $tzfd_requested_calendar ? $tzfd_requested_calendar : $calendar;
        $calendar = TZFD_Export_Dates::calendar_id( $tzfd_calendar_input );
        if ( null === $calendar ) {
            wp_die( esc_html__( 'Invalid export calendar.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        require_once __DIR__ . '/includes/class-tzfd-admin-report-scope.php';
        $tzfd_export_company = TZFD_Admin_Report_Scope::enforce( $calendar );

        ob_start();

        // calculate dates
        // Use the already validated, tenant-scoped calendar throughout the export.
        // Re-reading a raw GET parameter here could select the wrong form settings.
        $this->item = (int) $calendar;
        if ($this->get_option('date_format', 'mm/dd/yy') == 'dd/mm/yy' || $this->get_option('date_format', 'mm/dd/yy') == 'd M, y')
        {
            $from = str_replace('/','.',$from);
            $to = str_replace('/','.',$to);
        }

        $from = str_replace(',',' ',$from);
        $to = str_replace(',',' ',$to);
        
        $tzfd_dates = TZFD_Export_Dates::range( $from, $to );
        if ( null === $tzfd_dates ) {
            ob_end_clean();
            wp_die( esc_html__( 'Invalid export date range.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        list( $from, $to ) = $tzfd_dates;

        // pre-select time-slots
        $tz_selection = array();

        require_once __DIR__ . '/includes/class-tzfd-booking-export-reader.php';
        require_once __DIR__ . '/includes/class-tzfd-csv-booking-fields.php';
        $tz_rows = TZFD_Booking_Export_Reader::read( (int) $tzfd_export_company, (int) $calendar, $to );
        if ( null === $tz_rows ) {
            ob_end_clean();
            wp_die( esc_html__( 'Booking export could not be completed. Please retry.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) );
        }

        require_once __DIR__ . '/includes/class-tzfd-export-appointment-selection.php';
        foreach ( $tz_rows as $tz_item ) {
            foreach ( TZFD_Export_Appointment_Selection::from_row( $tz_item, $from, $to, $paidonly, $status ) as $tz_event ) {
                $tz_selection[] = $tz_event;
            }
        }

        // order time-slots
        if (!function_exists('tzfdbkfastsortfn'))
        {
            function tzfdbkfastsortfn( $a, $b ) { return ( $a[0] > $b[0] ? 1 : -1 ); }
        }
        usort($tz_selection, "tzfdbkfastsortfn" );

        // clean fields IDs
        $fields = explode(",",trim($fields));
        for($tz_j=0; $tz_j<count($fields); $tz_j++)
           $fields[$tz_j] = strtolower(trim($fields[$tz_j]));

        $fields_exclude = explode(",",trim(get_option('tz_fd_schcsvexclude',"")));
        for($tz_j=0; $tz_j<count($fields_exclude); $tz_j++)
           $fields_exclude[$tz_j] = strtolower(trim($fields_exclude[$tz_j]));

        $fields = array_values(array_diff ($fields, $fields_exclude));

        $separator = $this->csv_separator();

		
        if ($this->item)
        {
            $tz_forms = json_decode( $this->cleanJSON( $this->get_option( 'form_structure', TZFD_BOOK_DEFAULT_form_structure ) ) );
            $tz_form = is_array( $tz_forms ) && isset( $tz_forms[0] ) ? $tz_forms[0] : array();
        }
        else
            $tz_form = array();

        $filename = $this->generateSafeFileName(strtolower($this->get_option('form_name','export'))).'_'.gmdate("m_d_y");

        header("Content-type: application/octet-stream");
        header("Content-Disposition: attachment; filename=".$filename.".csv");
        // CSV is a file download, not HTML, so rows are written to the response stream unchanged.
        $tz_csv = '';
		
        $end = count($fields);
        for ($tz_i=0; $tz_i<$end; $tz_i++)
        {
            $hlabel = $this->iconv("utf-8", "ISO-8859-1//TRANSLIT//IGNORE", $this->get_form_field_label($fields[$tz_i],$tz_form));
			if ($hlabel == 'cancelled') $hlabel = 'status';
            $tz_csv .= '"'.str_replace('"','""', $this->clean_csv_value($hlabel)).'"'.$separator;
        }
        $tz_csv .= "\n";		
		
        // print table
        for($tz_i=0; $tz_i<count($tz_selection); $tz_i++)
        {
            for($tz_j=0; $tz_j<count($fields); $tz_j++)
            {
                if ($tz_j>0) $tz_csv .= $separator;
                $tz_csv .= '"';
                switch ($fields[$tz_j]) {
                    case 'date':
                        $tz_value = $tz_selection[$tz_i][1];
                        break;
                    case 'time':
                        $tz_value = $tz_selection[$tz_i][2];
                        break;
                    case 'email':
                        $tz_value = $tz_selection[$tz_i][4];
                        break;
                    case 'service':
                        $tz_value = $tz_selection[$tz_i][7];
                        break;
                    case 'quantity':
                        $tz_value = $tz_selection[$tz_i][8];
                        break;
                    case 'cancelled':
                        if ($tz_selection[$tz_i][6] == '')
                            $tz_value = __('Approved','titan-zero-trades-front-desk');
                        else
                            $tz_value = $tz_selection[$tz_i][6];
                        break;
                    case 'data':
                        $tz_details = (string) $tz_selection[$tz_i][5];
                        $tz_separator_position = strpos( $tz_details, "\n\n" );
                        $tz_value = false === $tz_separator_position ? $tz_details : substr( $tz_details, $tz_separator_position + 2 );
                        break;
                    case 'paid':
                        $tz_value = (@$tz_selection[$tz_i][3]['paid']?__('Yes','titan-zero-trades-front-desk'):'');
                        break;
                    default:
                        $tz_value = $tz_selection[$tz_i][3][$fields[$tz_j]] ?? '';
                }
                if ( is_array( $tz_value ) ) { $tz_value = implode( ',', $tz_value ); }
                $tz_csv .= str_replace( '"', '""', $this->clean_csv_value( $tz_value ) );
                $tz_csv .= '"';
            }
            $tz_csv .= "\n";
        }

        echo $tz_csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file download, not HTML; the cells are CSV-quoted above.
        exit;
    }


    public function fixurl( $base, $tz_param ) {
        if (strpos($base,'?'))
            return $base."&".$tz_param;
        else
		{
			$base = rtrim($base,"/")."/";
            return $base."?".$tz_param;
		}
    }


    public function setId( $id ) {
        $this->item = intval( $id );
    }

    public function getId() {
        return intval( $this->item );
    }    

    public function translate_permalink( $url )
    {
        $postid = url_to_postid( $url );
        if ($postid)
        {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- 'wpml_object_id' is WPML's own public filter name; it cannot be renamed.
            $newpostid = apply_filters( 'wpml_object_id', $postid, 'post', TRUE );
            if ( $newpostid != $postid )
                $url = get_permalink( $newpostid );
        }
        return $url;
    }


    public function filter_allowed_tags( $content ) {
        return  wp_kses( $content, $this->tags_allowed );
    }


    public function admin_process_json( $str ) {
        if ( ! is_string( $str ) || strlen( $str ) > 1048576 ) { return ''; }
        $data = json_decode( $this->cleanJSON( $str ) );
        if ( ! is_array( $data ) || ! isset( $data[0], $data[1][0] ) ||
             ! is_array( $data[0] ) || count( $data[0] ) > 200 || ! is_object( $data[1][0] ) ) { return ''; }
        foreach ( array( 'title', 'description' ) as $key ) {
            $value = $data[1][0]->$key ?? '';
            if ( ! is_string( $value ) ) { return ''; }
            $data[1][0]->$key = $this->filter_allowed_tags( $value );
        }
        foreach ( $data[0] as $field ) {
            if ( ! is_object( $field ) || ! isset( $field->ftype ) || ! is_string( $field->ftype ) ) { return ''; }
            foreach ( array( 'title', 'userhelpTooltip', 'userhelp' ) as $key ) {
                $value = $field->$key ?? '';
                if ( ! is_string( $value ) ) { return ''; }
                $field->$key = $this->filter_allowed_tags( $value );
            }
            if ( in_array( $field->ftype, array( 'fradio', 'fcheck', 'fdropdown' ), true ) ) {
                if ( ! isset( $field->choices ) || ! is_array( $field->choices ) || count( $field->choices ) > 200 ) { return ''; }
                foreach ( $field->choices as $index => $choice ) {
                    if ( ! is_string( $choice ) ) { return ''; }
                    $field->choices[$index] = $this->filter_allowed_tags( $choice );
                }
            } elseif ( 'fapp' === $field->ftype ) {
                if ( ! isset( $field->services ) || ! is_array( $field->services ) || count( $field->services ) > 200 ) { return ''; }
                foreach ( $field->services as $service ) {
                    if ( ! is_object( $service ) || ! isset( $service->name ) || ! is_string( $service->name ) ) { return ''; }
                    $service->name = $this->filter_allowed_tags( $service->name );
                }
                if ( isset( $field->emptySelect ) ) {
                    if ( ! is_string( $field->emptySelect ) ) { return ''; }
                    $field->emptySelect = $this->filter_allowed_tags( $field->emptySelect );
                }
            }
        }
        return wp_json_encode( $data ) ?: '';
    }


    public function translate_json( $str ) {
        $tz_form_data = json_decode( $this->cleanJSON( $str ) );
        if (is_array($tz_form_data))
        {
            $tz_form_data[1][0]->title = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tz_form_data[1][0]->title));
            $tz_form_data[1][0]->description = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tz_form_data[1][0]->description));

            for ($tz_i=0; $tz_i < count($tz_form_data[0]); $tz_i++)
            {
                if (property_exists($tz_form_data[0][$tz_i], 'title'))
                    $tz_form_data[0][$tz_i]->title = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tz_form_data[0][$tz_i]->title));
                $tz_form_data[0][$tz_i]->userhelpTooltip = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tz_form_data[0][$tz_i]->userhelpTooltip));
                $tz_form_data[0][$tz_i]->userhelp = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tz_form_data[0][$tz_i]->userhelp));

                $tz_form_data[0][$tz_i]->csslayout = esc_attr(str_replace('"', ' ', sanitize_text_field(titan_zero_front_desk_translate_legacy_value($tz_form_data[0][$tz_i]->csslayout))));

                if (property_exists($tz_form_data[0][$tz_i], 'predefined') && $tz_form_data[0][$tz_i]->predefined != '' && $tz_form_data[0][$tz_i]->ftype != 'fapp')
                    $tz_form_data[0][$tz_i]->predefined = titan_zero_front_desk_translate_legacy_value($tz_form_data[0][$tz_i]->predefined);

                if ($tz_form_data[0][$tz_i]->ftype == 'fCommentArea')
                    $tz_form_data[0][$tz_i]->userhelp = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tz_form_data[0][$tz_i]->userhelp));
                else if ($tz_form_data[0][$tz_i]->ftype == 'fradio' || $tz_form_data[0][$tz_i]->ftype == 'fcheck' || $tz_form_data[0][$tz_i]->ftype == 'fdropdown')
                {
                        for ($tz_j=0; $tz_j < count($tz_form_data[0][$tz_i]->choices); $tz_j++)
                            $tz_form_data[0][$tz_i]->choices[$tz_j] = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tz_form_data[0][$tz_i]->choices[$tz_j]));
                }
                else if ($tz_form_data[0][$tz_i]->ftype == 'fapp')
                {
                    for ($tz_j=0; $tz_j < count($tz_form_data[0][$tz_i]->services); $tz_j++)
                        $tz_form_data[0][$tz_i]->services[$tz_j]->name = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tz_form_data[0][$tz_i]->services[$tz_j]->name));
                    if (isset($tz_form_data[0][$tz_i]->emptySelect))
                        $tz_form_data[0][$tz_i]->emptySelect= $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tz_form_data[0][$tz_i]->emptySelect));
                }
            }
            $str = wp_json_encode($tz_form_data);
        }
        return $str;
    }


    private function get_records_csv( $formid, $form_name = "", &$tz_new_last_id = 0 ) {
        global $wpdb;

        require_once __DIR__ . '/includes/class-tzfd-scoped-store.php';
        $formid = absint( $formid );
        $company = TZFD_Scoped_Store::company_for_form( $formid );
        // Cron has no logged-in company context: derive ownership from the saved form,
        // never from the current request. Unknown forms must never export records.
        if ( $formid < 1 || $company < 1 ) { return ''; }
        $saved_item = $this->item;
        $this->item = $formid;

        $last_sent_id = get_option('tz_fd_last_sent_id_'.$formid, '0');
        $tz_events = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i WHERE formid=%d AND company_id=%d AND id>%d ORDER BY id ASC',
            $wpdb->prefix . TZFD_Tables::BOOKINGS, $formid, $company, absint( $last_sent_id )
        ) );

        if ($wpdb->num_rows <= 0) // if no rows, return empty
        {
            $this->item = $saved_item;
            return '';
        }

        if ($this->item)
        {
            $tz_form = json_decode($this->cleanJSON($this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure)));
            $tz_form = $tz_form[0];
        }
        else
            $tz_form = array();

        $buffer = '';
        if ($this->include_user_data_csv)
            $fields = array("Submission ID", "Form", "Time", "IP Address", "email");
        else
            $fields = array("Submission ID", "Form", "Time", "email");

        $fields_exclude = explode(",",trim(get_option('tz_fd_bocsvexclude',"")));
        $fields_exclude_org = array();
        for($tz_j=0; $tz_j<count($fields_exclude); $tz_j++)
        {
           $fields_exclude_org[] = (trim($fields_exclude[$tz_j]));
           $fields_exclude[$tz_j] = strtolower(trim($fields_exclude[$tz_j]));
        }

        $fields = array_values(array_diff($fields, $fields_exclude_org));

        $newfields = array();
        for($tz_j=0; $tz_j<count($fields); $tz_j++)
           if (!in_array($fields[$tz_j],$fields_exclude))
               $newfields[] = $fields[$tz_j];
        $fields = $newfields;


        $values = array();
        foreach ($tz_events as $tz_item)
        {
            $tz_value = array();
            if (in_array('Submission ID',$fields)) $tz_value[] = $tz_item->id;
            if (in_array('Form',$fields)) $tz_value[] = $this->get_option('form_name','');
            if (in_array('Time',$fields)) $tz_value[] = $tz_item->time;
            if ($this->include_user_data_csv)
                if (in_array('IP Address',$fields)) $tz_value[] = $tz_item->ipaddr;
            if (in_array('email',$fields)) $tz_value[] = sanitize_email($tz_item->notifyto);

            $last_sent_id = $tz_item->id;
            $tz_data = !empty($tz_item->posted_data) ? TZFD_Stored_Data::decode_booking($tz_item->posted_data) : array();
            if (!is_array($tz_data)) { $tz_data=array(); }

            $end = count($fields);
            for ($tz_i=0; $tz_i<$end; $tz_i++)
            { //if (isset($tz_data[$fields[$tz_i]]) ){
                if (isset($tz_data[$fields[$tz_i]]))
                    $tz_d = $tz_data[$fields[$tz_i]];
                else if (!isset($tz_value[$tz_i]))
                    $tz_d = "";
                else
                    $tz_d = $tz_value[$tz_i];
                if (substr($fields[$tz_i],0,strlen('app_status_')) == 'app_status_')
                {
                   $tz_d = TZFD_CSV_Booking_Fields::appointment_status( $tz_data, (string) $fields[$tz_i] );
                   if ($tz_d == '')
                       $tz_d = __('Approved','titan-zero-trades-front-desk');
                }
                $tz_value[$tz_i] = $tz_d;
                unset($tz_data[$fields[$tz_i]]);
            }

            if (is_array($tz_data)) foreach ($tz_data as $tz_k => $tz_d)
                if ($tz_k != 'apps' && $tz_k != 'itemnumber' && !in_array(strtolower($tz_k),$fields_exclude))
                {
                   $fields[] = $tz_k;
                   if (substr($tz_k,0,strlen('app_status_')) == 'app_status_')
                   {
                       $tz_d = TZFD_CSV_Booking_Fields::appointment_status( $tz_data, (string) $tz_k );
                       if ($tz_d == '')
                           $tz_d = __('Approved','titan-zero-trades-front-desk');
                   }
                   $tz_value[] = $tz_d;
                }
            $values[] = $tz_value;
        }
        // The cursor is returned to the caller and stored only after the report file is written and mailed.
        $tz_new_last_id = $last_sent_id;


        $separator = $this->csv_separator();

        $end = count($fields);
        for ($tz_i=0; $tz_i<$end; $tz_i++)
        {
            $hlabel = $this->iconv("utf-8", "ISO-8859-1//TRANSLIT//IGNORE", $this->get_form_field_label($fields[$tz_i],$tz_form));
            $buffer .= '"'.str_replace('"','""', $this->clean_csv_value( $hlabel )).'"'.$separator;
        }
        $buffer .= "\n";
        foreach ($values as $tz_item)
        {
            for ($tz_i=0; $tz_i<$end; $tz_i++)
            {
                if (!isset($tz_item[$tz_i]))
                    $tz_item[$tz_i] = '';
                if (is_array($tz_item[$tz_i]))
                    $tz_item[$tz_i] = implode(',',$tz_item[$tz_i]);
                $tz_item[$tz_i] = $this->iconv("utf-8", "ISO-8859-1//TRANSLIT//IGNORE", $tz_item[$tz_i]);
                $buffer .= '"'.str_replace('"','""', $this->clean_csv_value( $tz_item[$tz_i] )).'"'.$separator;
            }
            $buffer .= "\n";
        }

        $this->item = $saved_item;

        return $buffer;

    }

    /**
     * WP-Cron callback for the tz_fd_reports_cron event. check_reports() is private, so this public method calls it.
     */
    public function run_scheduled_reports() {
        $this->check_reports();
    }

    private function check_reports( $skip_verification = false ) {
        global $wpdb;
        // Scheduled reports execute without a logged-in company session. Restrict both
        // global and per-calendar exports to the configured standalone owner.
        require_once __DIR__ . '/includes/class-tzfd-scoped-store.php';
        $tzfd_report_owner = (int) TZFD_Scoped_Store::legacy_owner();
        if ( $tzfd_report_owner < 1 ) {
            return;
        }

        $last_verified = get_option( 'tz_fd_last_verified', '' );
        if ( $skip_verification || $last_verified == '' || $last_verified < gmdate("Y-m-d H:i:s", strtotime("-1 minutes")) )  // verification to don't check too fast to avoid overloading the site
        {
            update_option('tz_fd_last_verified',gmdate("Y-m-d H:i:s"));
            
            // Customer exports must never be written beneath public uploads.
            // Fail closed on hosts with no verified private temporary directory.
            $tmp_dir = realpath( get_temp_dir() );
            $site_root = realpath( ABSPATH );
            $uploads = wp_upload_dir( null, false );
            $uploads_root = realpath( $uploads['basedir'] ?? '' );
            $private_dir_ok = $tmp_dir && is_dir( $tmp_dir ) && wp_is_writable( $tmp_dir );
            foreach ( array( $site_root, $uploads_root ) as $public_root ) {
                if ( $public_root && $tmp_dir && ( $tmp_dir === $public_root || 0 === strpos( $tmp_dir, trailingslashit( $public_root ) ) ) ) {
                    $private_dir_ok = false;
                }
            }
            if ( ! $private_dir_ok ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic log for a failed background job.
                error_log( 'Titan Front Desk: scheduled CSV reports skipped; no private temporary directory.' );
                return;
            }

            // global reports for all forms
            if (get_option('tz_fd_rep_enable', 'no') == 'yes' && get_option('tz_fd_rep_days', '') != '' && get_option('tz_fd_rep_emails', '') != '' )
            {
                $formid = 0;
                $verify_after = gmdate("Y-m-d H:i:s", strtotime("-".get_option('tz_fd_rep_days', '')." days"));
                $last_sent = get_option('tz_fd_last_sent'.$formid, '');
                if ($last_sent == '' || $last_sent < $verify_after)  // check if this form needs to check for a new report
                {
                    // The gate is written only after the report mail is accepted (or when there is nothing to send).
                    $tz_gate_value = gmdate("Y-m-d ".(get_option('tz_fd_rep_hour', '')<'10'?'0':'').get_option('tz_fd_rep_hour', '').":00:00");
                    $text = '';
                    require_once __DIR__ . '/includes/class-tzfd-scoped-store.php';
                    // Legacy global report preferences belong only to the legacy owner.
                    $report_owner = TZFD_Scoped_Store::legacy_owner();
                    $forms = $report_owner > 0 ? $wpdb->get_results( $wpdb->prepare(
                        'SELECT id,fp_from_email,form_name,rep_days,rep_hour,rep_emails,rep_subject,rep_emailformat,rep_message,rep_enable FROM %i WHERE company_id=%d',
                        $wpdb->prefix . TZFD_Tables::FORMS, $report_owner
                    ) ) : array();
                    if ( ! is_array( $forms ) || ! empty( $wpdb->last_error ) ) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic log for a failed background job.
                        error_log( 'Titan Front Desk: global scheduled report skipped after database read failure.' );
                        return;
                    }
                    // " WHERE rep_emails<>'' AND rep_enable='yes'"
                    $attachments = array();
                    $tz_pending_cursors = array();
                    foreach ($forms as $tz_form)  // for each form with the reports enabled
                    {
                        $tz_csv_cursor = 0;
                        $csv = $this->get_records_csv($tz_form->id, $tz_form->form_name, $tz_csv_cursor);
                        if ($csv != '')
                        {
                            $tz_pending_cursors[ (int) $tz_form->id ] = $tz_csv_cursor;
                            $text ="- ".substr_count($csv,",\n\"").' submissions from '.$tz_form->form_name."\n";
                            $filename = tempnam( $tmp_dir, 'tzfd-' );
                            if ( false === $filename ) { continue; }
                            if ( false === file_put_contents( $filename, $csv, LOCK_EX ) ) { wp_delete_file( $filename ); continue; }
                            require_once __DIR__ . '/includes/class-tzfd-local-filesystem.php';
                            if ( ! TZFD_Local_Filesystem::make_private( $filename ) ) { wp_delete_file( $filename ); continue; }
                            $attachments[] = $filename;
                        }
                    }
                                    
                    $is_html_rep = ('html' == get_option('tz_fd_rep_emailformat',''));
                    $html_filter = function() { return 'text/html'; };
                    if ( $is_html_rep ) { add_filter( 'wp_mail_content_type', $html_filter ); }
                    
                    $headers_rep = array();
                    $headers_rep[] = "From: \"" . get_option('tz_fd_fp_from_email','') . "\" <" . get_option('tz_fd_fp_from_email','') . ">";
                    $headers_rep[] = "X-Mailer: PHP/" . phpversion();

                    $tz_gate_ok = true; // nothing to send, so nothing to retry
                    if (count($attachments))
                    {
                        $tz_rep_sent = wp_mail( str_replace(" ","",str_replace(";",",",get_option('tz_fd_rep_emails',''))), get_option('tz_fd_rep_subject',''), get_option('tz_fd_rep_message','')."\n".$text, $headers_rep, @$attachments);
                        $tz_gate_ok = (bool) $tz_rep_sent;
                        // Advance each form's cursor only when the mail was accepted, so unsent rows are retried next run.
                        if ( $tz_rep_sent ) {
                            foreach ( $tz_pending_cursors as $tz_cursor_form => $tz_cursor_id )
                                update_option('tz_fd_last_sent_id_'.$tz_cursor_form, $tz_cursor_id);
                        }
                    }
                    // On failure the gate stays unchanged, so the next run sees the same rows and retries.
                    if ( $tz_gate_ok )
                        update_option('tz_fd_last_sent'.$formid, $tz_gate_value);
                        
                    if ( $is_html_rep ) { remove_filter( 'wp_mail_content_type', $html_filter ); }                                    
                                    
                                    
                    foreach ($attachments as $tz_file_path)
                        wp_delete_file($tz_file_path);                                      
                }
            }

            // reports for specific forms
            $forms = $wpdb->get_results( $wpdb->prepare(
                "SELECT id,form_name,fp_from_email,rep_days,rep_hour,rep_emails,rep_subject,rep_emailformat,rep_message,rep_enable FROM %i WHERE company_id=%d AND rep_emails<>'' AND rep_enable='yes'",
                $wpdb->prefix . TZFD_Tables::FORMS,
                $tzfd_report_owner
            ) );
            if ( ! is_array( $forms ) || ! empty( $wpdb->last_error ) ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostic log for a failed background job.
                error_log( 'Titan Front Desk: per-form scheduled reports skipped after database read failure.' );
                return;
            }
            foreach ($forms as $tz_form)  // for each form with the reports enabled
            {
                $formid = absint( $tz_form->id );
                if ( $formid < 1 || (int) TZFD_Scoped_Store::company_for_form( $formid ) !== $tzfd_report_owner ) {
                    continue;
                }
                $verify_after = gmdate("Y-m-d H:i:s", strtotime("-".$tz_form->rep_days." days"));
                $last_sent = get_option('tz_fd_last_sent'.$formid, '');
                if ($skip_verification || $last_sent == '' || $last_sent < $verify_after)  // check if this form needs to check for a new report
                {
                    // The gate is written only after the report mail is accepted (or when there is nothing to send).
                    $tz_gate_value = gmdate("Y-m-d ".($tz_form->rep_hour<'10'?'0':'').$tz_form->rep_hour.":00:00");
                    $tz_csv_cursor = 0;
                    $csv = $this->get_records_csv($formid, $tz_form->form_name, $tz_csv_cursor);
                    $tz_gate_ok = true; // nothing to send, so nothing to retry
                    if ($csv != '')
                    {
                        $filename = tempnam( $tmp_dir, 'tzfd-' );
                        if ( false === $filename ) { continue; }
                        if ( false === file_put_contents( $filename, $csv, LOCK_EX ) ) { wp_delete_file( $filename ); continue; }
                        require_once __DIR__ . '/includes/class-tzfd-local-filesystem.php';
                            if ( ! TZFD_Local_Filesystem::make_private( $filename ) ) { wp_delete_file( $filename ); continue; }
                        $attachments = array( $filename );
                        
                        $is_html_frep = ('html' == $tz_form->rep_emailformat);
                        $html_filter = function() { return 'text/html'; };
                        if ( $is_html_frep ) { add_filter( 'wp_mail_content_type', $html_filter ); }
                        
                        $headers_frep = array();
                        $headers_frep[] = "From: \"" . $tz_form->fp_from_email . "\" <" . $tz_form->fp_from_email . ">";
                        $headers_frep[] = "X-Mailer: PHP/" . phpversion();

                        $tz_frep_sent = wp_mail( str_replace(" ","",str_replace(";",",",$tz_form->rep_emails)), $tz_form->rep_subject, $tz_form->rep_message, $headers_frep, $attachments);
                        $tz_gate_ok = (bool) $tz_frep_sent;
                        // On failure the cursor and the gate both stay unchanged, so the next run retries the same rows.
                        if ( $tz_frep_sent )
                            update_option('tz_fd_last_sent_id_'.$formid, $tz_csv_cursor);
                        if ( $tz_gate_ok )
                            update_option('tz_fd_last_sent'.$formid, $tz_gate_value);
                                
                        if ( $is_html_frep ) { remove_filter( 'wp_mail_content_type', $html_filter ); }
                        
                        foreach ( $attachments as $tz_file_path )
                            wp_delete_file( $tz_file_path );                                  
                    }
                }
            } // end foreach
        } // end if
    }  // end check_reports function


    protected function iconv( $from, $to, $text ) {
        $text = trim( $text );
        if ( strlen( $text ) > 1 && ( in_array( substr( $text, 0, 1 ), array( '=', '@', '+', '-' ), true ) ) )
        {
                $text = chr( 9 ).$text;
        }
        if ( get_option( 'TZFD_CSV_CHARFIX', "" ) == "" && function_exists( 'iconv' ) )
            return iconv( $from, $to, $text );
        else
            return $text;
    }


    /**
     * Sanitizes an admin-written message or subject template.
     * Placeholders such as <%INFO%> are protected while the rest is cleaned.
     * $mode: 'line' (subject), 'text' (plain text body), 'html' (HTML body).
     * $value must already be unslashed.
     */
    private function tz_fd_sanitize_template_value( $value, $mode = 'text' ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $value        = (string) $value;
        $placeholders = array();
        $value        = preg_replace_callback(
            '/<%.*?%>/s',
            function ( $tz_match ) use ( &$placeholders ) {
                $tz_token                = '{tzfdph' . count( $placeholders ) . '}';
                $placeholders[ $tz_token ] = $tz_match[0];
                return $tz_token;
            },
            $value
        );
        if ( 'html' === $mode ) {
            $value = wp_kses_post( $value );
        } elseif ( 'line' === $mode ) {
            $value = sanitize_text_field( $value );
        } else {
            $value = sanitize_textarea_field( $value );
        }
        return strtr( $value, $placeholders );
    }


} // end class
