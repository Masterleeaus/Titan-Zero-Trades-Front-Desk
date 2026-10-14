<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';
require_once __DIR__ . '/includes/class-tzfd-stored-data.php';

class TZFD_FrontDeskPlugin extends TZFD_BOOK_BaseClass {

    private $menu_parameter = 'tzfd';
    private $prefix = 'tzfd_frontdesk';
    private $plugin_name = 'Titan Zero Trades: Front Desk';
    private $plugin_URL = '';
    public $table_items = "tzfd_forms";
    public $table_messages = "tzfd_messages";
    public $print_counter = 1;
    private $include_user_data_csv = false;
    // true: every booking submission must carry a valid 'tzfd_actions_bookingform' nonce (the hidden anonce field) before any posted value is read.
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

        // New installs start with AI Setup, Quotes and the dashboard widget on, so they show in the menu and on the Dashboard.
        // An existing site keeps whatever it has chosen on the Add-ons page.
        if ( false === get_option( 'tzfd_addons_active_list', false ) ) {
            update_option( 'tzfd_addons_active_list', array( 'tzfd-Quotes', 'tzfd-Onboarding', 'tzfd-DashboardWidget' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $table_messages = $wpdb->prefix . 'tzfd_messages';
        $sql_messages   = "CREATE TABLE {$table_messages} (
            id int(10) NOT NULL AUTO_INCREMENT,
            formid INT NOT NULL,
            time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            ipaddr VARCHAR(250) DEFAULT '' NOT NULL,
            notifyto VARCHAR(250) DEFAULT '' NOT NULL,
            data mediumtext,
            posted_data mediumtext,
            whoadded VARCHAR(250) DEFAULT '' NOT NULL,
            UNIQUE KEY id (id)
        ) {$charset_collate};";

        dbDelta( $sql_messages );

        $table_forms = $wpdb->prefix . 'tzfd_forms';
        $sql_forms   = "CREATE TABLE {$table_forms} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
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
            tzfd_user_access text,
            tzfd_user_access_settings VARCHAR(10) DEFAULT '' NOT NULL,
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
            UNIQUE KEY id (id)
        ) {$charset_collate};";

        dbDelta( $sql_forms );


        // insert initial data
        $tzfd_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM %i", $wpdb->prefix.'tzfd_forms' ) ); 
        if ( !$tzfd_count )
        {
            define('TZFD_BOOK_DEFAULT_fp_from_email', get_the_author_meta('user_email', get_current_user_id()) );
            define('TZFD_BOOK_DEFAULT_fp_destination_emails', TZFD_BOOK_DEFAULT_fp_from_email);
            $wpdb->insert( $wpdb->prefix."tzfd_forms", array( 'id' => 1,
                                      'form_name' => 'Form 1',

                                      'form_structure' => $this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure),

                                      'calendar_language' => $this->get_option('calendar_language', ''),
                                      'date_format' => $this->get_option('date_format', 'mm/dd/yy'),
                                      'product_name' => $this->get_option('fp_from_email', 'Booking'),
                                      'pay_later_label' => $this->get_option('fp_from_email', 'Pay later'),

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

                                      'vs_text_quantity' => $this->get_option_not_empty('vs_text_quantity', 'Quantity'),
                                      'vs_text_cancel' => $this->get_option_not_empty('vs_text_cancel', 'Cancel'),
                                      'vs_text_cost' => $this->get_option_not_empty('vs_text_cost', 'Cost'),
									  'vs_text_nomore' => $this->get_option_not_empty('vs_text_nomore', 'No more slots available.'),
                                      'vs_text_nmore' => $this->get_option_not_empty('vs_text_nmore', 'Selected time is no longer available. Please select a different time.'),

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
        foreach ($this->paid_statuses as $tzfd_item)
            $statuses[] = $tzfd_item;
        return $statuses;
    }


    public function render_status_box( $name, $tzfd_selected, $displayall = false ) {
        echo '<select name="'.esc_attr($name).'" id="'.esc_attr($name).'">';
        if ($displayall)
            echo '<option value="all"'.($tzfd_selected == 'all'?' selected':'').'>'.esc_html(__('[All]','titan-zero-trades-front-desk')).'</option>';
        echo '<option value=""'.($tzfd_selected == ''?' selected':'').'>'.esc_html(__('Approved','titan-zero-trades-front-desk')).'</option>';
        foreach ($this->paid_statuses as $tzfd_item)
            echo '<option value="'.esc_attr($tzfd_item).'"'.($tzfd_selected == $tzfd_item?' selected':'').'>'.esc_html(titan_zero_front_desk_translate_legacy_value($tzfd_item)).'</option>';
        echo '</select>';
    }


    /**
     * Tags and attributes of the booking form HTML: the markup in tzfd-public-int.inc.php and what the add-ons add
     * through the tzfd_the_form filter (coupon and password fields, captcha widget and nonce, SMS and WhatsApp
     * buttons, admin status select). Used for deferred script loading, where that HTML has no script or style tags.
     * Anything not listed is dropped. Add-ons that need another tag must enqueue their script and style instead.
     */
    public function booking_form_markup_allowed() {
        $tzfd_common = array(
            'class' => true, 'id' => true, 'style' => true, 'role' => true, 'data-*' => true,
            'aria-live' => true, 'aria-atomic' => true,
        );
        $tzfd_field = array_merge( $tzfd_common, array(
            'type' => true, 'name' => true, 'value' => true, 'size' => true, 'autocomplete' => true,
            'checked' => true, 'disabled' => true, 'required' => true, 'vt' => true,
        ) );
        return array(
            'form'     => array_merge( $tzfd_common, array( 'name' => true, 'action' => true, 'method' => true, 'enctype' => true ) ),
            'input'    => $tzfd_field,
            'select'   => array_merge( $tzfd_common, array( 'name' => true ) ),
            'option'   => array_merge( $tzfd_common, array( 'value' => true, 'selected' => true ) ),
            'label'    => array_merge( $tzfd_common, array( 'for' => true ) ),
            'img'      => array_merge( $tzfd_common, array( 'src' => true, 'alt' => true, 'border' => true ) ),
            'a'        => array_merge( $tzfd_common, array( 'href' => true, 'target' => true, 'rel' => true ) ),
            'div'      => array_merge( $tzfd_common, array( 'generated' => true ) ),
            'p'        => $tzfd_common,
            'fieldset' => $tzfd_common,
            'legend'   => $tzfd_common,
            'br'       => array(),
        );
    }

    /**
     * Tags and attributes used by the admin booking lists and the add-on settings forms.
     * Admin markup that comes from add-on code or from filter_list() is passed through
     * wp_kses() with this list before it is echoed. Anything not listed is dropped.
     */
    public function admin_markup_allowed() {
        $tzfd_global = array(
            'class' => true, 'id' => true, 'style' => true, 'title' => true, 'role' => true,
            'lang' => true, 'tabindex' => true, 'hidden' => true, 'data-*' => true,
            'aria-hidden' => true, 'aria-label' => true, 'aria-labelledby' => true,
            'aria-describedby' => true, 'aria-live' => true,
        );
        $tzfd_field = array_merge( $tzfd_global, array(
            'name' => true, 'value' => true, 'type' => true, 'size' => true, 'placeholder' => true,
            'checked' => true, 'selected' => true, 'disabled' => true, 'readonly' => true,
            'required' => true, 'multiple' => true, 'min' => true, 'max' => true, 'step' => true,
            'maxlength' => true, 'autocomplete' => true, 'rows' => true, 'cols' => true,
            'for' => true, 'accept' => true,
        ) );
        $tzfd_cell = array_merge( $tzfd_global, array(
            'colspan' => true, 'rowspan' => true, 'align' => true, 'valign' => true,
            'scope' => true, 'width' => true, 'height' => true,
        ) );
        $tzfd_allowed = array(
            'a'        => array_merge( $tzfd_global, array( 'href' => true, 'target' => true, 'rel' => true, 'name' => true ) ),
            'img'      => array_merge( $tzfd_global, array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true, 'hspace' => true, 'vspace' => true, 'align' => true, 'border' => true ) ),
            'form'     => array_merge( $tzfd_global, array( 'action' => true, 'method' => true, 'enctype' => true, 'name' => true, 'target' => true, 'autocomplete' => true ) ),
            'input'    => $tzfd_field,
            'select'   => $tzfd_field,
            'textarea' => $tzfd_field,
            'option'   => array_merge( $tzfd_global, array( 'value' => true, 'selected' => true, 'disabled' => true, 'label' => true ) ),
            'optgroup' => array_merge( $tzfd_global, array( 'label' => true, 'disabled' => true ) ),
            'button'   => array_merge( $tzfd_global, array( 'type' => true, 'name' => true, 'value' => true, 'disabled' => true ) ),
            'label'    => array_merge( $tzfd_global, array( 'for' => true ) ),
            'fieldset' => array_merge( $tzfd_global, array( 'name' => true, 'disabled' => true ) ),
            'td'       => $tzfd_cell,
            'th'       => $tzfd_cell,
            'table'    => array_merge( $tzfd_global, array( 'width' => true, 'align' => true, 'border' => true, 'cellspacing' => true, 'cellpadding' => true ) ),
            'col'      => array_merge( $tzfd_global, array( 'span' => true, 'width' => true ) ),
            'tr'       => array_merge( $tzfd_global, array( 'align' => true, 'valign' => true ) ),
            'ol'       => array_merge( $tzfd_global, array( 'type' => true, 'start' => true ) ),
            'ul'       => array_merge( $tzfd_global, array( 'type' => true ) ),
            'li'       => array_merge( $tzfd_global, array( 'value' => true ) ),
            'details'  => array_merge( $tzfd_global, array( 'open' => true ) ),
        );
        // Text containers and layout tags take only the global attributes.
        foreach ( array( 'div', 'span', 'p', 'em', 'strong', 'b', 'i', 'small', 'code', 'pre', 'nobr', 'br', 'hr',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'summary', 'legend', 'dl', 'dt', 'dd', 'thead', 'tbody', 'tfoot',
            'caption', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav', 'sup', 'sub', 'kbd' ) as $tzfd_tag ) {
            if ( ! isset( $tzfd_allowed[ $tzfd_tag ] ) ) {
                $tzfd_allowed[ $tzfd_tag ] = $tzfd_global;
            }
        }
        return $tzfd_allowed;
    }


    public function update_status($id, $status, $indexonly = '-1')
    {
        global $wpdb;
        if ( (!is_int($id) && !is_string($id)) ||
             !filter_var($id, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1))) ||
             !is_string($status) || strlen($status)>250 ||
             1!==preg_match('//u',$status) || preg_match('/[\x00-\x1F\x7F]/',$status) ) { return false; }
        if ( (!is_int($indexonly) && !is_string($indexonly)) ||
             false===filter_var($indexonly,FILTER_VALIDATE_INT,array('options'=>array('min_range'=>-1))) ) { return false; }
        $tzfd_events = $wpdb->get_results( $wpdb->prepare('SELECT * FROM `'.$wpdb->prefix.'tzfd_messages` WHERE id=%d', $id) );
        if (!is_array($tzfd_events) || !isset($tzfd_events[0]->posted_data) || !empty($wpdb->last_error)) { return false; }
        $tzfd_posted_data = TZFD_Stored_Data::decode_booking($tzfd_events[0]->posted_data);
        if (!is_array($tzfd_posted_data) || !isset($tzfd_posted_data['apps']) || !is_array($tzfd_posted_data['apps'])) { return false; }
        $countapps = count($tzfd_posted_data['apps']);
        if (!$countapps || array_keys($tzfd_posted_data['apps'])!==range(0,$countapps-1) || $indexonly>=$countapps) { return false; }
        $something_changed = false;
        $status_changed = false;
        // Optional cancel reason (rs) from the admin list action. It is read only when the list nonce is valid
        // (the same tzfd_actions_booking nonce that the list branches check before they call this method).
        // Unslash once, then sanitise before the value is used.
        $tzfd_reason_nonce_ok = isset($_REQUEST['rs']) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['anonce'] ?? '' ) ), 'tzfd_actions_booking' );
        $tzfd_reason_is_string = $tzfd_reason_nonce_ok && !is_array($_REQUEST['rs']);
        $tzfd_cancel_reason = $tzfd_reason_is_string ? sanitize_text_field( wp_unslash( $_REQUEST['rs'] ) ) : '';
        $tzfd_has_reason = $tzfd_reason_nonce_ok && !empty($_REQUEST['rs']) && $tzfd_cancel_reason != '';
        for($tzfd_k=0; $tzfd_k<$countapps; $tzfd_k++)
            if (($indexonly == '-1' || $indexonly == $tzfd_k) && (!isset($tzfd_posted_data["apps"][$tzfd_k]["cancelled"]) || $tzfd_posted_data["apps"][$tzfd_k]["cancelled"] != $status || $tzfd_has_reason))
            {
                 if (!isset($tzfd_posted_data["apps"][$tzfd_k]["cancelled"]) || $tzfd_posted_data["apps"][$tzfd_k]["cancelled"] != $status)
                     $status_changed = true;
                 $tzfd_posted_data["apps"][$tzfd_k]["cancelled"] = $status;
                 $tzfd_posted_data["app_status_".($tzfd_k+1)] = $status;
                 if ($tzfd_reason_is_string)
                 {
                     $tzfd_posted_data["apps"][$tzfd_k]["cancelreason"] = $tzfd_cancel_reason;
                     $tzfd_posted_data["app_cancelreason_".($tzfd_k+1)] = $tzfd_posted_data["apps"][$tzfd_k]["cancelreason"];
                 }
                 $something_changed = true;
            }
        if ($something_changed)
        {
            $tzfd_posted_data = serialize($tzfd_posted_data);
            $update_result = $wpdb->update ( $wpdb->prefix."tzfd_messages", array( 'posted_data' => $tzfd_posted_data ), array( 'id' => $id ));
            do_action( 'tzfd_update_status', $id, $status );
            if ( $status_changed && is_int( $update_result ) && $update_result > 0 ) {
                do_action( 'titan_zero_front_desk_booking_status_persisted', $id, $status, $indexonly );
            } 
        }
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
        if ( ! current_user_can( 'manage_options' ) ) { return ''; }
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




        $tzfd_table_name = $wpdb->prefix . 'tzfd_messages';

        // Each optional filter is a pair of placeholders. A flag of 0 switches the filter off, so its clause is always true.
        // The query is one literal string, which $wpdb->prepare() can check.
        $tzfd_only_current = 0;
        $tzfd_current_id   = 0;
        if ( strtolower( $onlycurrentuser ) === 'yes' || strtolower( $onlycurrentuser ) === 'true' ) {
            $current_user      = wp_get_current_user();
            $tzfd_only_current = 1;
            // No logged-in user: -1 matches no booking, the same result as the old "1=0" clause.
            $tzfd_current_id   = $current_user->ID ? (int) $current_user->ID : -1;
        }

        $tzfd_email_filter = ( $email !== '' ) ? 1 : 0;

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
        $tzfd_calendar_list = implode( ',', $cal_ids );

        $tzfd_selection = array();

        $tzfd_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT notifyto, posted_data, data, formid, id, time FROM %i WHERE notifyto <> %s AND (%d = 0 OR whoadded = %d) AND (%d = 0 OR notifyto = %s) AND (%s = '' OR FIND_IN_SET(formid, %s)) AND time <= %s ORDER BY time DESC LIMIT 0, %d",
                $tzfd_table_name,
                $this->blocked_by_admin_indicator,
                $tzfd_only_current,
                $tzfd_current_id,
                $tzfd_email_filter,
                (string) $email,
                $tzfd_calendar_list,
                $tzfd_calendar_list,
                $to_query,
                intval( $limit )
            )
        );

        foreach($tzfd_rows as $tzfd_item)
        {
            $tzfd_data = TZFD_Stored_Data::decode_booking($tzfd_item->posted_data);
            if (null === $tzfd_data || !isset($tzfd_data["apps"])) { continue; }
            if (!$paidonly || (!empty($tzfd_data['paid']) && $tzfd_data['paid']))
            {
                $appindex = 0;
                if (is_array($tzfd_data["apps"]))
                    foreach($tzfd_data["apps"] as $tzfd_app)
                    {
                        $appindex++;
                        if ($tzfd_app["date"] >= $from && $tzfd_app["date"] <= $to &&
                           ($status == 'notcancelled' || $status == 'all' || $status == $tzfd_app["cancelled"]) &&
                           ($status != 'notcancelled' || ('Cancelled by customer' != $tzfd_app["cancelled"] && 'Cancelled' != $tzfd_app["cancelled"]) )
                           )
                        {
                            if ($service == '' || $service == $tzfd_app["service"])
                                $tzfd_selection[] = array(
                                                     $tzfd_app["date"]." ".$tzfd_app["slot"],
                                                     $tzfd_app["date"],
                                                     str_replace("/","-",$tzfd_app["slot"]),
                                                     $tzfd_data,
                                                     sanitize_email($tzfd_item->notifyto),
                                                     $tzfd_item->data,
                                                     $tzfd_app["cancelled"],
                                                     $tzfd_app["service"],
                                                     $appindex,                       // 8
                                                     $tzfd_item->formid,                   // 9
                                                     $tzfd_app["quant"],              // 10
                                                     $tzfd_item->time,                   // 11
                                                     $tzfd_item->id                   // 12                                                     
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
        usort( $tzfd_selection, "tzfdbkfastsortfn" );

        // clean fields IDs
        $fields = explode(",",trim($fields));
        for($tzfd_j=0; $tzfd_j<count($fields); $tzfd_j++)
            $fields[$tzfd_j] = strtolower(trim($fields[$tzfd_j]));

        // print table
        if (!count($tzfd_selection))
            echo '<div class="tzfd_noapps">'.esc_html(__('No appointments found with the selected filters','titan-zero-trades-front-desk')).'</div>';
        else for($tzfd_i=0; $tzfd_i<count($tzfd_selection) && ($maxitems == '' || $tzfd_i < $maxitems); $tzfd_i++)
        {
            echo '<div class="tzfdapp_no_wrap">';
            for($tzfd_j=0; $tzfd_j<count($fields); $tzfd_j++)
            {
                echo '<div class="tzfd_field_'.intval($tzfd_j).($tzfd_selection[$tzfd_i][6]!=''?' tzfd_cancelled':'').'">';
                switch ($fields[$tzfd_j]) {
                    case 'rownumber':
                        echo intval($tzfd_i)+1;
                        break;
                    case 'weekday':
                        echo esc_html(ucfirst(titan_zero_front_desk_translate_legacy_value(gmdate('l',strtotime($tzfd_selection[$tzfd_i][1])))));
                        break;
                    case 'date':
                        echo esc_html($this->format_date($tzfd_selection[$tzfd_i][1]));
                        break;
                    case 'time':
                        echo esc_html($tzfd_selection[$tzfd_i][2]);
                        break;
                    case 'email':
                        echo "<a href=\"mailto:".esc_attr($tzfd_selection[$tzfd_i][4])."\">".esc_html($tzfd_selection[$tzfd_i][4])."</a>&nbsp;";
                        break;
                    case 'service':
                        echo esc_html($tzfd_selection[$tzfd_i][7])."&nbsp;";
                        break;
                    case 'quantity':
                        echo esc_html($tzfd_selection[$tzfd_i][10])."&nbsp;";
                        break;
                    case 'cancelled':
                        if ($tzfd_selection[$tzfd_i][6] == '')
                            echo esc_html(__('Approved','titan-zero-trades-front-desk'));
                        else
                            echo esc_html(titan_zero_front_desk_translate_legacy_value($tzfd_selection[$tzfd_i][6]));
                        echo '&nbsp;';
                        break;
                    case 'data':
                        echo esc_html(substr($tzfd_selection[$tzfd_i][5],strpos($tzfd_selection[$tzfd_i][5],"\n\n")+2));
                        break;
                    case 'paid':
                        echo esc_html(( (!empty($tzfd_selection[$tzfd_i][3]['paid']) && $tzfd_selection[$tzfd_i][3]['paid'])?__('Yes','titan-zero-trades-front-desk'):'&nbsp;'));
                        break;
                    case 'options':
                        if ( is_admin() && $this->check_current_user_access( intval( $tzfd_selection[$tzfd_i][9] ) ) )
                        {
                            echo '<div style="width:100px; zoom:75%; overflow:none">';                            
                            ?><div style="clear:both;"><nobr><?php $this->render_status_box('sb'.intval($tzfd_selection[$tzfd_i][12]).'_'.intval($tzfd_selection[$tzfd_i][8]), $tzfd_selection[$tzfd_i][6]); ?><br><input style="float:left" class="button tzfd-ups-item" type="button" name="calups_<?php echo intval($tzfd_selection[$tzfd_i][12]); ?>" data-tzfd-id="<?php echo intval($tzfd_selection[$tzfd_i][12]); ?>" data-tzfd-item="<?php echo intval($tzfd_selection[$tzfd_i][8]); ?>" value="<?php esc_html_e('Update Status','titan-zero-trades-front-desk'); ?>" /></nobr></div><?php
                            echo '</div>';
                        }
                        else
                            echo "&nbsp;";
                        break;                         
                    default:
                        if (is_array($tzfd_selection[$tzfd_i][3][$fields[$tzfd_j]]))
                            echo esc_html(implode(",",$tzfd_selection[$tzfd_i][3][$fields[$tzfd_j]]));
                        else
                            echo esc_html(($tzfd_selection[$tzfd_i][3][$fields[$tzfd_j]]==''?'&nbsp;':$tzfd_selection[$tzfd_i][3][$fields[$tzfd_j]])."&nbsp;");
                }
                echo '</div>';
            }
            echo '</div>';
            echo '<div class="tzfdapp_break"></div>';
        }
        $tzfd_nonce = wp_create_nonce( 'tzfd_actions_booking' );
        if ( is_admin() && current_user_can('edit_pages') ) {
            // The Update Status buttons (data-tzfd-id, data-tzfd-item) are wired by js/inline/m1-upsitem.js.
            wp_enqueue_script( 'tzfd-upsitem', plugins_url( 'js/inline/m1-upsitem.js', __FILE__ ), array(), '1.0.0', true );
            wp_localize_script( 'tzfd-upsitem', 'tzfdUpsItem', array(
                'page'   => $this->menu_parameter,
                'anonce' => $tzfd_nonce,
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only value, cast to int below.
                'cal'    => intval( sanitize_text_field( wp_unslash( $_GET["cal"] ?? '' ) ) ),
            ) );
        }
        $buffered_contents = ob_get_contents();
        ob_end_clean();
        // Shortcode output (TZFD_FRONT_DESK_LIST): admin list markup only, so it goes through the admin allow-list.
        return wp_kses( $buffered_contents, $this->admin_markup_allowed() );
    }
    


    /* output of the booking form */
    public function output_filter_content( $atts ) {
        $tzfd_form_html = $this->build_booking_form( $atts );
        if ( ! TZFD_BOOK_DEFER_SCRIPTS_LOADING ) {
            // Classic loading only: build_booking_form() captures the <script> and <link> tags that WordPress core prints
            // (wp_print_scripts, wp_print_styles). wp_kses would strip them and break the script text, so the markup is echoed
            // as built. Every dynamic value in tzfd-public-int.inc.php is escaped there (esc_attr, esc_html, esc_js, intval).
            echo $tzfd_form_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Classic loading: the output holds the core <script> and <link> tags that wp_kses would strip, and every dynamic value is escaped where it is built (tzfd-public-int.inc.php).
            return;
        }
        echo wp_kses( $tzfd_form_html, $this->booking_form_markup_allowed() );
    }


    /**
     * Shortcode and block callback for the booking form. Deferred loading: the form HTML is escaped with
     * the booking form allow-list. Classic loading: returned as built, for the reason given in output_filter_content().
     */
    public function filter_content( $atts ) {
        $tzfd_form_html = $this->build_booking_form( $atts );
        if ( ! TZFD_BOOK_DEFER_SCRIPTS_LOADING ) {
            return $tzfd_form_html;
        }
        return wp_kses( $tzfd_form_html, $this->booking_form_markup_allowed() );
    }


    /* Builds the booking form HTML. Returns unescaped markup; callers escape it (filter_content, output_filter_content). */
    private function build_booking_form( $atts ) {
        global $wpdb;
        extract( shortcode_atts( array(
    		                           'id' => '',
    	                        ), $atts ) );
        if ($id != '')
            $this->item = intval($id);

    	/**
    	 * Filters applied before generate the form,
    	 * is passed as parameter an array with the forms attributes, and return the list of attributes
    	 */
        $atts = apply_filters( 'tzfd_pre_form',  $atts );

        ob_start();
        $this->insert_public_item();
        $buffered_contents = ob_get_contents();
        ob_end_clean();

	    /**
	     * Filters applied after generate the form,
	     * is passed as parameter the HTML code of the form with the corresponding <LINK> and <SCRIPT> tags,
	     * and returns the HTML code to includes in the webpage
	     */
	    $buffered_contents = apply_filters( 'tzfd_the_form', $buffered_contents,  $this->item );

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

        if (TZFD_BOOK_DEFER_SCRIPTS_LOADING)
        {
            wp_enqueue_style('tzfdapp-calendarstyle', plugins_url('css/cupertino/calendar.css', __FILE__), array(), '1.0.0');
            wp_enqueue_style('tzfdapp-publicstyle', plugins_url('css/stylepublic.css', __FILE__), array(), '1.0.0');
            if ( !defined('ELEMENTOR_PRO_VERSION'))
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
            
            wp_enqueue_script($this->prefix.'_validatejs'.$this->print_counter, $this->fixurl($this->get_site_url( false ),'tzfd_resources=validatejs&cal='.$this->item.'&counter='.$this->print_counter), array('jquery'), '1.0.0', true);
            $js_dependencies[] = $this->prefix.'_validatejs'.$this->print_counter;
            
            
            if (defined("TZFD_DYNAMIC_LOADING"))
            {
                wp_enqueue_script( $this->prefix.'_builder_script',
                     $this->fixurl($this->get_site_url( false ), 'tzfd_resources=public'), $js_dependencies, '1.0.0', true );
            }
            else                
            {
                wp_enqueue_script( $this->prefix.'_builder_script',
                     plugins_url('js/tzfdform-public.js', __FILE__), $js_dependencies, (string) filemtime(__DIR__.'/js/tzfdform-public.js'), true );
            }
            
            


            wp_localize_script($this->prefix.'_builder_script', $this->prefix.'_tzfdform_config'.('_'.$this->print_counter), array('obj' =>
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
        }
        else
        {
            wp_enqueue_script( "jquery" );
            wp_enqueue_script( "jquery-ui-core" );
            wp_enqueue_script( "jquery-ui-datepicker" );
            
            // Validation JS is printed as inline data on a registered handle (no file, no output tag written here).
            $tzfd_validate_handle = $this->prefix.'_validate_inline_'.$this->print_counter;
            wp_register_script( $tzfd_validate_handle, false, array(), '1.0.0', true );
            wp_add_inline_script( $tzfd_validate_handle, $this->getValidateScript() );
            wp_print_scripts( array( $tzfd_validate_handle ) );
        }

        $button_label = $this->get_option('vs_text_submitbtn', 'Submit');
        $button_label = ($button_label==''?'Submit':$button_label);


        // START:: code to load form settings
        $tzfd_raw_form_str = str_replace("\r"," ",str_replace("\n"," ",$this->cleanJSON($this->translate_json($this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure)))));

        $tzfd_form_data = json_decode( $tzfd_raw_form_str );
        if( is_null( $tzfd_form_data ) ){
        	$tzfd_json = new JSON;
        	$tzfd_form_data = $tzfd_json->unserialize( $tzfd_raw_form_str );
        }

        $tzfd_template_style = '';
        if( !is_null( $tzfd_form_data ) )
        {
        	if( !empty( $tzfd_form_data[ 0 ] ) )
        	{
        		foreach( $tzfd_form_data[ 0 ] as $tzfd_key => $object )
        		{
        			if ($object->ftype == 'fcheck' || $object->ftype == 'fradio' || $object->ftype == 'fdropdown')
        			{
        			    for($ki=0; $ki<count($object->choicesVal); $ki++)
        			        $object->choicesVal[$ki] = str_replace('@', TZFD_BOOK_REP_ARR, $object->choicesVal[$ki]);
        			    $tzfd_form_data[ 0 ][ $tzfd_key ] = $object;
        				$tzfd_raw_form_str = wp_json_encode( $tzfd_form_data );
        			}
                    else if ($object->ftype == 'fapp')
                    {
                        $object->csslayout = apply_filters ('tzfd_csslayout', $object->csslayout, $this->item);
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
                        $tzfd_form_data[ 0 ][ $tzfd_key ] = $object;
                        //$tzfd_raw_form_str = wp_json_encode( $tzfd_form_data );
                    }
                    $tzfd_raw_form_str = wp_json_encode( $tzfd_form_data );
        		}
        	}
            $tzfd_form_template = '';
        	if( isset( $tzfd_form_data[ 1 ] ) && isset( $tzfd_form_data[ 1 ][ 0 ] ) && isset( $tzfd_form_data[ 1 ][ 0 ]->formtemplate ) )
        	{
        		$tzfd_templatelist = $this->available_templates();
                $tzfd_form_template = substr(md5($tzfd_form_data[ 1 ][ 0 ]->formtemplate),0,10);
        		if(  isset( $tzfd_templatelist[ $tzfd_form_data[ 1 ][ 0 ]->formtemplate ] ) )
                {
                    $tzfd_template_style = 'tzfd-template-'.$tzfd_form_template;
                    wp_enqueue_style( $tzfd_template_style, $tzfd_templatelist[ $tzfd_form_data[ 1 ][ 0 ]->formtemplate ][ 'file' ], array(), '1.0.0' );
                }
        	}
            
        }

        $tzfd_raw_form_str = str_replace('"','&quot;',esc_attr($tzfd_raw_form_str));
        // END:: code to load form settings

        if (!defined('TZFD_AUTH_INCLUDE')) define('TZFD_AUTH_INCLUDE',true);
        include_once $this->part_path( 'tzfd-public-int.inc.php' );
        if (!TZFD_BOOK_DEFER_SCRIPTS_LOADING)
        {
            // Legacy setting for themes without wp_head() or wp_footer(): the scripts and styles are enqueued and then printed here, in order.
            wp_enqueue_style('tzfd-legacy-publicstyle', plugins_url('css/stylepublic.css', __FILE__), array(), '1.0.0');
            wp_enqueue_style('tzfd-legacy-calendarstyle', plugins_url('css/cupertino/calendar.css', __FILE__), array(), '1.0.0');
            $tzfd_legacy_styles = array('tzfd-legacy-publicstyle', 'tzfd-legacy-calendarstyle');
            if ($tzfd_template_style != '')
                array_unshift($tzfd_legacy_styles, $tzfd_template_style);
            wp_print_styles($tzfd_legacy_styles);
            wp_enqueue_script('jquery');
            wp_enqueue_script('jquery-ui-core');
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_script('jquery-ui-widget');
            wp_enqueue_script('jquery-ui-position');
            wp_enqueue_script('jquery-ui-tooltip');
            $tzfd_legacy_ui = array('jquery', 'jquery-ui-core', 'jquery-ui-datepicker', 'jquery-ui-widget', 'jquery-ui-position', 'jquery-ui-tooltip');
            if ($calendar_language != '' && file_exists(dirname( __FILE__ ).'/js/languages/jquery.ui.datepicker-'.$calendar_language.'.js')) {
                wp_enqueue_script('tzfd-legacy-datepicker-language', plugins_url('js/languages/jquery.ui.datepicker-'.$calendar_language.'.js', __FILE__), array('jquery-ui-datepicker'), '1.0.0', true);
                $tzfd_legacy_ui[] = 'tzfd-legacy-datepicker-language';
            }
            wp_print_scripts($tzfd_legacy_ui);
            $tzfd_legacy_config = array(
                'pub' => true,
                'identifier' => '_'.$this->print_counter,
                'messages' => array(
                    'required' => $this->get_option('vs_text_is_required', TZFD_BOOK_DEFAULT_vs_text_is_required),
                    'maxapp' => $this->get_option('vs_text_maxapp', TZFD_BOOK_DEFAULT_vs_text_maxapp),
                    'language' => $calendar_language,
                    'date_format' => $this->get_option('date_format', 'mm/dd/yy'),
                    'email' => $this->get_option('vs_text_is_email', TZFD_BOOK_DEFAULT_vs_text_is_email),
                    'datemmddyyyy' => $this->get_option('vs_text_datemmddyyyy', TZFD_BOOK_DEFAULT_vs_text_datemmddyyyy),
                    'dateddmmyyyy' => $this->get_option('vs_text_dateddmmyyyy', TZFD_BOOK_DEFAULT_vs_text_dateddmmyyyy),
                    'number' => $this->get_option('vs_text_number', TZFD_BOOK_DEFAULT_vs_text_number),
                    'digits' => $this->get_option('vs_text_digits', TZFD_BOOK_DEFAULT_vs_text_digits),
                    'max' => $this->get_option('vs_text_max', TZFD_BOOK_DEFAULT_vs_text_max),
                    'min' => $this->get_option('vs_text_min', TZFD_BOOK_DEFAULT_vs_text_min),
                    'previous' => $previous_label,
                    'next' => $next_label,
                ),
            );
            // Dynamic form settings: a registered handle with no file, printed right here before the form scripts.
            $tzfd_legacy_config_handle = $this->prefix.'_legacy_config_'.$this->print_counter;
            wp_register_script( $tzfd_legacy_config_handle, false, array(), '1.0.0', true );
            wp_add_inline_script( $tzfd_legacy_config_handle, 'var '.$this->prefix.'_tzfdform_config_'.$this->print_counter.' = '.wp_json_encode( array( 'obj' => wp_json_encode( $tzfd_legacy_config ) ) ).';' );
            wp_print_scripts( array( $tzfd_legacy_config_handle ) );
            wp_enqueue_script('tzfd-legacy-public', $this->fixurl($this->get_site_url( false ), 'tzfd_resources=public'), array('jquery'), '1.0.0', true);
            wp_print_scripts(array('tzfd-legacy-public'));
        }
        // $this->print_counter++;
    }


    public function getValidateScript() {
        
        ob_start();        
        
        ?>
         var tzfd_hour_cancel_label = '<?php echo esc_js( titan_zero_front_desk_translate_legacy_value( $this->get_option_not_empty('vs_text_cancel', 'Cancel') ) ); ?>';
         var tzfd_hour_close_label = '<?php echo esc_js( __( 'Close', 'titan-zero-trades-front-desk' ) ); ?>';
         var tzfd_hour_quantity_label = '<?php echo esc_js( titan_zero_front_desk_translate_legacy_value( $this->get_option_not_empty('vs_text_quantity', 'Quantity') ) ); ?>';
         var tzfd_hour_cost_label = '<?php echo esc_js( titan_zero_front_desk_translate_legacy_value( $this->get_option_not_empty('vs_text_cost', 'Cost') ) ); ?>';
         var tzfd_hour_overlapping_label = '<?php echo esc_js( titan_zero_front_desk_translate_legacy_value( $this->get_option_not_empty('vs_text_nmore', 'Selected time is no longer available. Please select a different time.') ) ); ?>';
		 var tzfd_hour_nomore_label = '<?php echo esc_js( titan_zero_front_desk_translate_legacy_value( $this->get_option_not_empty('vs_text_nomore', 'No more slots available.') ) ); ?>';
         var tzfd_hour_avoid_overlapping = 0;
         var tzfdhboverbooking_handler<?php echo esc_html($this->print_counter-1); ?> = false;
         function tzfd_frontdesk_pform_doValidate<?php echo '_'.esc_html($this->print_counter); ?>(form)
         {   
            var counteridn = <?php echo esc_html($this->print_counter); ?>;         
            var counterid = "_"+counteridn;
            try { if (form.tzfd_frontdesk_pform_status.value == '1') return false; } catch (e) {}
            $dexQuery = jQuery.noConflict();
            try { form.tzfd_ref_page.value = document.location; } catch (e) {}
            $dexQuery = jQuery.noConflict();<?php if (!is_admin() && $this->get_option('cv_enable_captcha', TZFD_BOOK_DEFAULT_cv_enable_captcha) != 'false' && function_exists('imagecreatetruecolor')) { ?>
            var result = '';
            try {
            if (!tzfdhboverbooking_handler<?php echo esc_html($this->print_counter-1); ?>) {
            if (form.hdcaptcha_tzfd_frontdesk_post.value == '') { setTimeout( "tzfd_frontdesk_cerror"+counterid+"()", 100); return false; }
            var result = $dexQuery.ajax({ type: "GET", url: "<?php echo esc_js($this->get_site_url()); ?>?ps="+counterid+"&tzfd_frontdesk_pform_process=2&tzfd_frontdesk_id=<?php echo intval($this->item); ?>&inAdmin=1&ps="+counterid+"&hdcaptcha_tzfd_frontdesk_post="+form.hdcaptcha_tzfd_frontdesk_post.value, async: false }).responseText;
            }
            } catch (e) {result='';}
            if (!tzfdhboverbooking_handler<?php echo esc_html($this->print_counter-1); ?> && result.indexOf("captchafailed") != -1) {
                $dexQuery("#captchaimg"+counterid).attr('src', $dexQuery("#captchaimg"+counterid).attr('src')+'&'+Math.floor((Math.random() * 99999) + 1));
                setTimeout( "tzfd_frontdesk_cerror"+counterid+"()", 100);
                return false;
            } else <?php } ?>
            {
                var tzfdefb_error = 0; 
                $dexQuery("#"+form.id).find(".tzfdefb_error").each(function(index){
                if ($dexQuery(this).css("display")!="none")
                    tzfdefb_error++;
                });
                if (tzfdefb_error==0)
                {
                    if (!tzfdhboverbooking_handler<?php echo esc_html($this->print_counter-1); ?>)
                    {
                        try { form.tzfd_frontdesk_pform_status.value = '1';	 } catch (e) {}
                        tzfdhbblink<?php echo esc_html('_'.$this->print_counter); ?>(".pbSubmit:visible", form);
                        $dexQuery("#"+form.id).find(".avoid_overlapping_before").not(".ignore,.ignorepb").removeClass("avoid_overlapping_before").removeClass("valid").addClass("avoid_overlapping");
                        tzfd_hour_avoid_overlapping = 1;
                        try {
                            $dexQuery("#"+form.id).find(".avoid_overlapping").valid();
                        } catch (e) { tzfd_hour_avoid_overlapping = 0; }
                        function check_tzfd_hour_avoid_overlapping(){
		                    if (tzfd_hour_avoid_overlapping>0)
		                        setTimeout(check_tzfd_hour_avoid_overlapping,100);
		                    else
		                    {
                                var tzfdefb_error = 0;
                                $dexQuery("#"+form.id).find(".tzfdefb_error").each(function(index){
                                    if ($dexQuery(this).css("display")!="none")
                                        tzfdefb_error++;
                                    });
                                try { form.tzfd_frontdesk_pform_status.value = '0';	 } catch (e) {}
                                if (tzfdefb_error==0)
                                {
                                    tzfdhboverbooking_handler<?php echo esc_html($this->print_counter-1); ?> = true;
                                    if (tzfd_frontdesk_pform_doValidate<?php echo esc_html('_'.$this->print_counter); ?>(form))
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
		                check_tzfd_hour_avoid_overlapping();
                        return false;
                    }
                    <?php
                    /**
				     * Action called before insert the data into database.
				     * To the function are passed two parameters: the array with submitted data, and the number of form in the page.
				     */

				    do_action( 'tzfd_script_after_validation', $this->print_counter, $this->item );

	                ?>
	        		$dexQuery("#"+form.id).find( '.ignore' ).closest( '.fields' ).remove();
	        	}
	        	document.getElementById("form_structure"+counterid).value = '';
	        	document.getElementById("refpage"+counterid).value = document.location;
                try {
                    if (tzfdefb_error==0)
                    {
                        form.tzfd_frontdesk_pform_status.value = '1';
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
                                 if (form.tzfd_frontdesk_pform_status.value != '0')
                                     tzfdhbblink<?php echo esc_html('_'.$this->print_counter); ?>(this, form);
                             } catch (e) { }
                     });
                 });
             } catch (e) {}
         }
         function tzfd_frontdesk_cerror<?php echo esc_html('_'.$this->print_counter); ?>(){$dexQuery = jQuery.noConflict();$dexQuery("#hdcaptcha_error<?php echo esc_html('_'.$this->print_counter); ?>").css("display","block");$dexQuery.tzfdform.markCaptchaError($dexQuery("#hdcaptcha_tzfd_frontdesk_post<?php echo esc_html('_'.$this->print_counter); ?>")[0],$dexQuery("#hdcaptcha_error<?php echo esc_html('_'.$this->print_counter); ?>")[0]);}
        <?php
        
        $buffered_contents = ob_get_contents();
        ob_end_clean();
        
        return $buffered_contents;
    }

    /* Code for the admin area */

    public function plugin_page_links( $links ) {
        $settings_link = '<a href="admin.php?page='.$this->menu_parameter.'_settings">'.__('Settings','titan-zero-trades-front-desk').'</a>';
    	array_unshift($links, $settings_link);
    	return $links;
    }


    public function admin_menu() {
        add_options_page($this->plugin_name.' '.__( 'Options' ,'titan-zero-trades-front-desk'), $this->plugin_name, 'manage_options', $this->menu_parameter, array($this, 'settings_page') );
        add_menu_page( $this->plugin_name.' '.__( 'Options' ,'titan-zero-trades-front-desk'), 'Front Desk', 'read', $this->menu_parameter, array( 'TZFD_Admin_Menu', 'route_front_desk' ), TZFD_Brand::menu_icon() );
        // The rest of the Front Desk menu (Dashboard, Calendar, Bookings, Services, Addons, Settings)
        // and the Settings tabs are added by TZFD_Admin_Menu. See includes/class-tzfd-admin-menu.php.

    }



    function insert_button() {
        global $wpdb;
        $tzfd_options = '';
        $calendars = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i", $wpdb->prefix.'tzfd_forms' ) ); 
        foreach($calendars as $tzfd_item)
            $tzfd_options .= '<option value="'.intval($tzfd_item->id).'">'.esc_html($tzfd_item->form_name).'</option>';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
        if ( (!defined('ELEMENTOR_MENUS_VERSION') && !defined('ELEMENTOR_PRO_VERSION')) || @sanitize_text_field( wp_unslash( $_GET["action"] ?? '' ) ) != 'elementor')
            wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_script('tzfdhour_classic_editor', plugins_url('/js/insertpanel.js', __FILE__), array(), '1.0.0', true);

        $forms = $this->getThisUserForms();

        wp_localize_script( 'tzfdhour_classic_editor', 'tzfdhour_formsclassic', array(
                            'forms' => $forms,
                            'siteUrl' => get_site_url()
                          ) );
        wp_add_inline_script( 'tzfdhour_classic_editor', 'document.addEventListener("click",function(e){var a=e.target.closest?e.target.closest("a.tzfdhbinsertb"):null;if(a){e.preventDefault();if(typeof tzfd_appointments_fpanel!=="undefined"){tzfd_appointments_fpanel.open();}}});' );

        print '<a href="#" class="tzfdhbinsertb" title="'.esc_attr(__('Insert Titan Zero Trades: Front Desk','titan-zero-trades-front-desk')).'"><img hspace="5" src="'.esc_attr(plugins_url('/images/tzfd_form.gif', __FILE__)).'" alt="'.esc_attr(__('Insert Titan Zero Trades: Front Desk','titan-zero-trades-front-desk')).'" /></a>';
    }


    function getThisUserForms() {
        global $wpdb;
        $current_user = wp_get_current_user();
        $tzfd_current_user_access = current_user_can('manage_options');
        $tzfd_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,form_name,tzfd_user_access FROM %i ORDER BY form_name", $wpdb->prefix.'tzfd_forms' ) ); 
        $forms = array();

        $forms[] = array (
                                'value' => 0,
                                'label' =>__('- Please select a booking form -','titan-zero-trades-front-desk'),
                                );
        $counter = 0;
        if (!$tzfd_current_user_access)  // if isn't admin and has some form assigned then return only assigned forms
        {
            foreach ($tzfd_rows as $tzfd_item)
            {
               $useraccess = TZFD_Access_List::decode($tzfd_item->tzfd_user_access);
               
               if (in_array($current_user->ID, $useraccess, true))
               {
                   $counter++;
                   $forms[] = array (
                                    'value' => $tzfd_item->id,
                                    'label' => $tzfd_item->form_name,
                                    );
               }
            }
        }


        if ($counter == 0)
            foreach ($tzfd_rows as $tzfd_item)
               $forms[] = array (
                                'value' => $tzfd_item->id,
                                'label' => $tzfd_item->form_name,
                                );

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
            __( 'Titan Zero Trades: Front Desk is incomplete: the file %s is missing. Reinstall the plugin.', 'titan-zero-trades-front-desk' ),
            $relative_path
        ) ) );
    }

    public function settings_page() {
        global $wpdb;
        
        if ($this->get_param("page") == $this->menu_parameter.'_support')
        {
            include_once $this->part_path( 'tzfd-help.inc.php' );
        }
        else if ($this->get_param("page") == $this->menu_parameter.'_settings')
        {
            include_once $this->part_path( 'tzfd-settings.inc.php' );
        }
        else if ($this->get_param("page") == $this->menu_parameter.'_addons')
        {
            include_once $this->part_path( 'tzfd-addons.inc.php' );
        } 
        else if ($this->get_param("cal") || $this->get_param("cal") == '0' || $this->get_param("pwizard") == '1')
        {
            $this->item = intval($this->get_param("cal"));
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
            if (isset($_GET["edit"]) && sanitize_text_field( wp_unslash( $_GET["edit"] ?? '' ) ) == '1')
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
        if (!isset($_GET["page"]) || substr(sanitize_text_field( wp_unslash( $_GET["page"] ?? '' ) ),0,3) != 'tzfd_') {

            wp_enqueue_script('tzfdhour_gutenberg_editor', plugins_url('/js/block.js', __FILE__), array(), '1.0.0', true);

            wp_enqueue_style('tzfdapp-calendarstyle', plugins_url('css/cupertino/calendar.css', __FILE__), array(), '1.0.0');
            wp_enqueue_style('tzfdapp-publicstyle', plugins_url('css/stylepublic.css', __FILE__), array(), '1.0.0');
            if (defined("TZFD_DYNAMIC_LOADING"))
            {
                wp_enqueue_script( $this->prefix.'_builder_script',
                       $this->fixurl($this->get_site_url( false ),'tzfd_resources=public'),array("jquery","jquery-ui-core","jquery-ui-datepicker","jquery-ui-widget","jquery-ui-position","jquery-ui-tooltip"), '1.0.0', true );
            }
            else
            {
                wp_enqueue_script( $this->prefix.'_builder_script',
                      plugins_url('js/tzfdform-public.js', __FILE__),array("jquery","jquery-ui-core","jquery-ui-datepicker","jquery-ui-widget","jquery-ui-position","jquery-ui-tooltip"), '1.0.0', true );
            }    

            $forms = $this->getThisUserForms();

            wp_localize_script( 'tzfdhour_gutenberg_editor', 'tzfdhour_forms', array(
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

    $form_id = intval( $atts['formId'] );

    // 2. Run the query ONCE, and only select the ID to save memory.    
    $tzfd_table_name = $wpdb->prefix . 'tzfd_forms';
    $tzfd_myrows = $wpdb->get_results( 
        $wpdb->prepare("SELECT id FROM %i WHERE id = %d", $tzfd_table_name, $form_id) 
    );

    // If the form doesn't exist in the DB, bail early.
    if ( empty( $tzfd_myrows ) ) {
        return esc_html__( 'Please select a booking form.', 'titan-zero-trades-front-desk' );
    }

    // 3. Determine if we are inside the Gutenberg Editor via the REST API
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
    $is_gutenberg_editor = defined( 'REST_REQUEST' ) && REST_REQUEST && ! empty( $_REQUEST['context'] ) && 'edit' === sanitize_text_field( wp_unslash( $_REQUEST['context'] ?? '' ) );

    // 4. Handle Frontend Output
    if ( ! $is_gutenberg_editor ) {
        return $this->filter_content( array( 'id' => $form_id ) );
    }

    // 5. Handle Editor Output
    $this->setId( $form_id );

    // Prevent PHP warning if instanceId is missing
    $instance_id = ! empty( $atts['instanceId'] ) ? $atts['instanceId'] : wp_rand( 10000, 99999 );
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
        <fieldset class="tzfdgutenberg_editor" disabled>
            <div id="tzfdform">
                <div id="tzfdform_%1$s">
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
        
        wp_enqueue_style('tzfdapp-genstyle', plugins_url('/css/genstyle.css', __FILE__), array(), '1.0.0');
        
        if ($this->get_param("page") == $this->menu_parameter && $this->get_param("blocktimes") != '1' && $this->get_param("addbk") != '1')
        {


            if (defined("TZFD_DYNAMIC_LOADING"))
            {
                wp_enqueue_script($this->prefix.'_builder_script', $this->get_site_url( true ).'?tzfd_resources=admin', array("jquery","jquery-ui-core","jquery-ui-sortable","jquery-ui-tabs","jquery-ui-droppable","jquery-ui-button","jquery-ui-datepicker"), '1.0.0', true);
            }
            else
            {
                // Papa Parse 5.7.0 (MIT) provides Papa.parse for CSV import; the builder bundle no longer embeds it.
                wp_enqueue_script($this->prefix.'_papaparse', plugins_url('js/tzfdform-admin-fields/00_jquery.parse.min.js', __FILE__), array(), '5.7.0', true);
                wp_enqueue_script($this->prefix.'_builder_script', plugins_url('js/tzfdform-admin.js', __FILE__), array("jquery","jquery-ui-core","jquery-ui-sortable","jquery-ui-tabs","jquery-ui-droppable","jquery-ui-button","jquery-ui-datepicker", $this->prefix.'_papaparse'), '1.0.0', true);
            }
                
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
            if (isset($_GET["report"]) && sanitize_text_field( wp_unslash( $_GET["report"] ?? '' ) ) == '1') 
            {
                wp_enqueue_script('tzfdstatschart', plugins_url('js/chart.js', __FILE__), array('jquery'), '1.0.0', true);
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
            if (isset($_GET["calendarview"]) && sanitize_text_field( wp_unslash( $_GET["calendarview"] ?? '' ) ) == '1')
            {
                wp_enqueue_script( 'jquery-ui-dialog' );
                
                wp_enqueue_style('tzfd-jquery-schedcalstyle', plugins_url('/tzfd-calendar/css/cupertino/calendar.css', __FILE__), array(), '1.0.0');
                wp_enqueue_style('tzfd-jquery-schedcalstylemv', plugins_url('/tzfd-calendar/css/main.css', __FILE__), array(), '1.0.0');
                
                wp_enqueue_script('underscore', includes_url('/js/underscore.min.js'), array('jquery'), '1.0.0', true);
                wp_enqueue_script('tzfd-schedcal-rrule', plugins_url('/tzfd-calendar/js/rrule.js', __FILE__), array(), '1.0.0', true);  
                wp_enqueue_script('tzfd-schedcal-common', plugins_url('/tzfd-calendar/js/Common.js', __FILE__), array('jquery'), '1.0.0', true);

                if (file_exists(dirname( __FILE__ ).'/tzfd-calendar/language/tzfdcalendar_lang_'.$this->mv_autodetect_language().'.js'))
                    $langscript = plugins_url('/tzfd-calendar/language/tzfdcalendar_lang_'.$this->mv_autodetect_language().'.js', __FILE__);
                else
                    $langscript = plugins_url('/tzfd-calendar/language/tzfdcalendar_lang_en_GB.js', __FILE__);

                wp_enqueue_script('tzfdtslots-schedcal-lang', $langscript, array(), '1.0.0', true);
                // Declare dependencies so the scripts load in the order they need: the calendar reads the
                // language strings and Common.js when it loads; tzfdcalendar.js calls both.
                wp_enqueue_script('tzfdtslots-schedcal-calendar', plugins_url('/tzfd-calendar/js/jquery.calendar.js', __FILE__), array('jquery', 'tzfd-schedcal-common', 'tzfdtslots-schedcal-lang'), '1.0.0', true);
                wp_enqueue_script('tzfdtslots-schedcal-alert', plugins_url('/tzfd-calendar/js/jquery.alert.js', __FILE__), array('jquery'), '1.0.0', true);
                wp_enqueue_script('tzfdtslots-schedcal-tzfdcalendar', plugins_url('/tzfd-calendar/js/tzfdcalendar.js', __FILE__), array('jquery', 'jquery-ui-dialog', 'tzfd-schedcal-rrule', 'tzfd-schedcal-common', 'tzfdtslots-schedcal-lang', 'tzfdtslots-schedcal-calendar', 'tzfdtslots-schedcal-alert'), '1.0.0', true);
            }

            wp_enqueue_style('tzfd-jquery-ui-style', plugins_url('/css/cupertino/jquery-ui-1.8.20.custom.css', __FILE__), array(), '1.0.0');            
            wp_enqueue_style('tzfdapp-style', plugins_url('/css/style.css', __FILE__), array(), '1.0.0');
            wp_enqueue_style('tzfdapp-newadminstyle', plugins_url('/css/newadminlayout.css', __FILE__), array(), '1.3');
            wp_enqueue_script('tzfd-admin-accessibility', plugins_url('/js/tzfd-admin-accessibility.js', __FILE__), array(), '1.0.0', true);
            $calendar_language = $this->get_option('calendar_language','');
            if ($calendar_language == '') $calendar_language = $this->autodetect_language();
            if ($calendar_language != '')
                wp_enqueue_script($this->prefix.'_language_file', plugins_url('js/languages/jquery.ui.datepicker-'.$calendar_language.'.js', __FILE__), array("jquery","jquery-ui-core","jquery-ui-sortable","jquery-ui-tabs","jquery-ui-droppable","jquery-ui-button","jquery-ui-datepicker"), '1.0.0', true);
        }  else if ($this->get_param("blocktimes") == '1') {                        
            foreach ( array( 'jquery-ui-core','jquery-ui-sortable','jquery-ui-tabs','jquery-ui-droppable','jquery-ui-button','jquery-ui-datepicker' ) as $tzfd_ui_dep ) { wp_enqueue_script( $tzfd_ui_dep ); }
            
            
            wp_enqueue_style('tzfdapp-newadminstyle', plugins_url('/css/newadminlayout.css', __FILE__), array(), '1.0.0'); 
        }
        if( 'post.php' != $hook  && 'post-new.php' != $hook )
            return;
        // space to include some script in the post or page areas if needed
    }


    function mv_autodetect_language()
    {
            $basename = '/tzfd-calendar/language/tzfdcalendar_lang_';
    
            $binfo = str_replace('-','_',get_bloginfo('language'));
    
            $tzfd_options = array ($binfo,
                              strtolower($binfo),
                              substr(strtolower($binfo),0,2)."_".substr(strtoupper($binfo),strlen(strtoupper($binfo))-2,2),
                              substr(strtolower($binfo),0,2)."_".substr(strtoupper($binfo),0,2),
                              substr(strtolower($binfo),0,2),
                              substr(strtolower($binfo),strlen(strtolower($binfo))-2,2)
                              );
            foreach ($tzfd_options as $tzfd_option)
            {
                if (file_exists(dirname( __FILE__ ).$basename.$tzfd_option.'.js'))
                    return $tzfd_option;
                $tzfd_option = str_replace ("-","_", $tzfd_option);
                if (file_exists(dirname( __FILE__ ).$basename.$tzfd_option.'.js'))
                    return $tzfd_option;
            }
            return '';
    }

    function autodetect_language() {
        $basename = '/js/languages/jquery.ui.datepicker-';

        $tzfd_options = array (get_bloginfo('language'),
                          strtolower(get_bloginfo('language')),
                          substr(strtolower(get_bloginfo('language')),0,2)."-".substr(strtoupper(get_bloginfo('language')),strlen(strtoupper(get_bloginfo('language')))-2,2),
                          substr(strtolower(get_bloginfo('language')),0,2),
                          substr(strtolower(get_bloginfo('language')),strlen(strtolower(get_bloginfo('language')))-2,2)
                          );
        foreach ($tzfd_options as $tzfd_option)
        {
            if (file_exists(dirname( __FILE__ ).$basename.$tzfd_option.'.js'))
                return $tzfd_option;
            $tzfd_option = str_replace ("-","_", $tzfd_option);
            if (file_exists(dirname( __FILE__ ).$basename.$tzfd_option.'.js'))
                return $tzfd_option;
        }
        return '';
    }


   public function data_management_pluginsloaded() {
       
       tzfd_loading_add_ons();

       // Scheduled report sending runs from WP-Cron (see run_scheduled_reports) instead of on every front-end request.
       add_action( 'tzfd_reports_cron', array( $this, 'run_scheduled_reports' ) );
       if ( ! wp_next_scheduled( 'tzfd_reports_cron' ) )
           wp_schedule_event( time() + 300, 'hourly', 'tzfd_reports_cron' );

       // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
       if (isset($_GET["tzfd_resources"]))
       {
           add_filter( 'trp_allow_tp_to_run', 'tzfdhour_tp_disable_filter' );
       }
   }


   function data_management_loaded() {
        global $wpdb;

        $action = $this->get_param('tzfd_do_action_loaded');
    	if (!$action) return; // go out if the call isn't for this one

        if ($this->get_param('tzfdhour_id')) $this->item = intval($this->get_param('tzfdhour_id'));

        if ($action == "wizard" && current_user_can('manage_options'))
        {
            if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_POST["anonce"] ?? '' )), 'tzfd_actions_pwizard' ) ) { $this->nonce_failed(); }
            $shortcode = '[TZFD_FRONT_DESK  id="'.$this->item .'"]';
            $this->postURL = $this->publish_on($this->sanitize( (isset($_POST["whereto"]) ? sanitize_text_field( wp_unslash( $_POST["whereto"] ?? '' ) ) : '') ), $this->sanitize( (isset($_POST["publishpage"]) ? sanitize_text_field( wp_unslash( $_POST["publishpage"] ?? '' ) ) : '') ), $this->sanitize( (isset($_POST["publishpost"]) ? sanitize_text_field( wp_unslash( $_POST["publishpost"] ?? '' ) ) : '') ), $shortcode, $this->sanitize( (isset($_POST["posttitle"]) ? sanitize_text_field( wp_unslash( $_POST["posttitle"] ?? '' ) ) : '' ) )); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }

        // ...
        echo 'Some unexpected error happened. If you see this error contact the support service at ';

        exit();
    }


    private function publish_on( $whereto, $publishpage = '', $publishpost = '', $content = '', $posttitle = 'Booking Form' ) {
        global $wpdb;
        $id = '';
        if ($whereto == '0' || $whereto =='1') // new page
        {
            $my_post = array(
              'post_title'    => $posttitle,
              'post_type' => ($whereto == '0'?'page':'post'),
              'post_content'  => 'This is a <b>preview</b> page, remember to publish it if needed. You can edit the full calendar and form settings into the admin settings page.<br /><br /> '.$content,
              'post_status'   => 'draft'
            );

            // Insert the post into the database
            $id = wp_insert_post( $my_post );
        }
        else
        {
            $id = ($whereto == '2'?$publishpage:$publishpost);
            $post = get_post( $id );
            if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) { return ''; }
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


    function print_tzfdcalendar_format( $tzfd_data, $formid = 0, $dataquery = '', $startdate = '', $enddate = '' ) {
        // $tzfd_data[$tzfd_k]["d"] - date
        // $tzfd_data[$tzfd_k]["h1"] - hour
        // $tzfd_data[$tzfd_k]["m1"] - minute
        // $tzfd_data[$tzfd_k]["h2"] - hour end
        // $tzfd_data[$tzfd_k]["m2"] - minute end
        // $tzfd_data[$tzfd_k]["info"] - description

        function tzfd_js_to_php_time( $jsdate ) {
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


        function tzfd_php_to_mysql_time( $phpDate ) {
            return gmdate("Y-m-d H:i:s", $phpDate);
        }


        function tzfd_php_to_js_time( $phpDate ) {
            return @gmdate("m/d/Y H:i", $phpDate);
        }


        function tzfd_mysql_to_php_time( $sqlDate ) {
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

        usort($tzfd_data, array($this, 'wptsbk_custom_sort') );

        // Only bookings inside the requested range are sent to the calendar.
        $tzfd_data = array_values( array_filter( $tzfd_data, function ( $tzfd_item ) use ( $startdate, $enddate ) {
            return $this->date_in_range( $tzfd_item['d'] ?? '', $startdate, $enddate );
        } ) );

        $ret = array();
        $ret['events'] = array();
        $ret["issort"] = true;
        $ret['error'] = null;
        $d1 = tzfd_js_to_php_time( $startdate );
        $d2 = tzfd_js_to_php_time( $enddate );
        $d1 = mktime(0, 0, 0,  gmdate("m", $d1), gmdate("d", $d1), gmdate("Y", $d1));
        $d2 = mktime(0, 0, 0, gmdate("m", $d2), gmdate("d", $d2), gmdate("Y", $d2))+24*60*60-1;
        $ret["start"] = tzfd_php_to_js_time($d1);
        $ret["end"] = tzfd_php_to_js_time($d2);

        $aid = 1;
        foreach ($tzfd_data as $tzfd_item)
        {
            $datetime = $tzfd_item["d"]." ".$tzfd_item["h1"].":".($tzfd_item["m1"]<10?"0":"").$tzfd_item["m1"];
            $datetime2 = $tzfd_item["d"]." ".$tzfd_item["h2"].":".($tzfd_item["m2"]<10?"0":"").$tzfd_item["m2"];
            $ev = array(
                $aid++,//mt_rand(1,9999999), //$row["id"],
                $tzfd_item["e"],
                tzfd_php_to_js_time(tzfd_mysql_to_php_time($datetime)),
                tzfd_php_to_js_time(tzfd_mysql_to_php_time($datetime2)),
                0, // is  all day event?
                0, // more than one day event
                '',//Recurring event rule,
                '#3CF',
                0,//editable
                '',
                '',//$attends
                $tzfd_item["info"],
                '',
                1
            );
            $ret['events'][] = $ev;
        }
        $dataoutput = wp_json_encode($ret);
        do_action( 'tzfd_cache_store', $formid, $dataquery, $dataoutput );
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
        if ( false === $start || false === $end || false === $day ) {
            return true;
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
        $current_user = wp_get_current_user();
        $tzfd_current_user_access = current_user_can('manage_options');
        $tzfd_saved_id = $this->item;
        $this->setID($calid);
        $access_ids = TZFD_Access_List::decode( $this->get_option( "tzfd_user_access", "" ) );
        $result = ( $tzfd_current_user_access || in_array( $current_user->ID, $access_ids, true ) );
        $this->setID($tzfd_saved_id);
        return $result;
    }


    function data_management() {
        global $wpdb;
            
        if(!empty($_REQUEST['tzfd_app_action']) && (sanitize_text_field( wp_unslash( $_REQUEST['tzfd_app_action'] ?? '' ) ) == 'get_slots' || sanitize_text_field( wp_unslash( $_REQUEST['tzfd_app_action'] ?? '' ) ) == 'mv') && isset($_SERVER['REQUEST_METHOD']) && 'POST' == $_SERVER['REQUEST_METHOD'])
        {
            $formid = intval(sanitize_text_field( wp_unslash( $_REQUEST['formid'] ?? '' ) ));
            $field = (isset($_REQUEST['formfield']) ? sanitize_key(wp_unslash( $_REQUEST['formfield'] ?? '' )) : '');

            // The multi-view calendar feed (POST, tzfd_app_action=mv) shows booking details to staff only.
            if ('mv' == sanitize_text_field( wp_unslash( $_REQUEST['tzfd_app_action'] ?? '' ) ))
            {
                if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['anonce'] ?? '' ) ), 'tzfd_actions_booking' ) ) { $this->nonce_failed(); }
                if ( ! is_user_logged_in() || ! $this->check_current_user_access( $formid ) ) { echo 'Authentication required'; exit; }
            }

            if ('mv' == sanitize_text_field( wp_unslash( $_REQUEST['tzfd_app_action'] ?? '' ) ))
            {
                $t_content_admin = get_option('tzfd_schcalcontent_admin',TZFD_SCHCALCONTENT_ADMIN);
                $t_title_admin = get_option('tzfd_schcaltitle_admin',TZFD_SCHCALTITLE_ADMIN);
                $t_content_public = get_option('tzfd_schcalcontent_public',TZFD_SCHCALCONTENT_PUBLIC);
                $t_title_public = get_option('tzfd_schcaltitle_public',TZFD_SCHCALTITLE_PUBLIC);
                $t_content_admin = apply_filters( 'tzfd_mv_content_admin_filter', $t_content_admin,  intval($formid) );
                $t_title_admin = apply_filters( 'tzfd_mv_title_admin_filter', $t_title_admin,  intval($formid) );
                $t_content_public = apply_filters( 'tzfd_mv_content_public_filter', $t_content_public,  intval($formid) );
                $t_title_public = apply_filters( 'tzfd_mv_title_public_filter', $t_title_public,  intval($formid) );
            }

            // Blocked slots are left out of the calendar feed unless the setting shows them.
            $tzfd_include_blocked = ( sanitize_text_field( wp_unslash( $_REQUEST['tzfd_app_action'] ?? '' ) ) != 'mv' || get_option('tzfd_sch_admin_blockedt','') == 'Yes' );
            if ( $tzfd_include_blocked )
                $dataquery = $wpdb->prepare( "SELECT data,notifyto,posted_data FROM %i where formid=%d", $wpdb->prefix.'tzfd_messages', $formid );
            else
                $dataquery = $wpdb->prepare( "SELECT data,notifyto,posted_data FROM %i where formid=%d AND notifyto<>%s", $wpdb->prefix.'tzfd_messages', $formid, $this->blocked_by_admin_indicator );

            // The cached feed depends on the date range, so the range is part of the cache key.
            $dataquery .= '|' . sanitize_text_field( wp_unslash( $_POST["startdate"] ?? '' ) ) . '|' . sanitize_text_field( wp_unslash( $_POST["enddate"] ?? '' ) );
            do_action( 'tzfd_cache_check', $formid, $dataquery );

            // The same two queries as $dataquery, written out so each prepare() call gets a literal string.
            if ( $tzfd_include_blocked )
                $tzfd_myrows = $wpdb->get_results( $wpdb->prepare( "SELECT data,notifyto,posted_data FROM %i where formid=%d", $wpdb->prefix.'tzfd_messages', $formid ) );
            else
                $tzfd_myrows = $wpdb->get_results( $wpdb->prepare( "SELECT data,notifyto,posted_data FROM %i where formid=%d AND notifyto<>%s", $wpdb->prefix.'tzfd_messages', $formid, $this->blocked_by_admin_indicator ) );
            
            $tmp2 = array();
            $tzfd_range_start = sanitize_text_field( wp_unslash( $_POST["startdate"] ?? '' ) );
            $tzfd_range_end   = sanitize_text_field( wp_unslash( $_POST["enddate"] ?? '' ) );

            $excluded_tzfdcalendar = ',,'.get_option('tzfd_schcalcontent_exclude','').','; // fields excluded form multi view calendar

            for ($tzfd_i=0; $tzfd_i < count($tzfd_myrows); $tzfd_i++)
            {
                $tzfd_data = TZFD_Stored_Data::decode_booking($tzfd_myrows[$tzfd_i]->posted_data);
                if (is_array($tzfd_data) && isset($tzfd_data["apps"]) && is_array($tzfd_data["apps"]))
                {
                    for($tzfd_k=0; $tzfd_k<count($tzfd_data["apps"]); $tzfd_k++)
                        if ( TZFD_Scheduling_Rules::is_occupied_status(
                                isset($tzfd_data["apps"][$tzfd_k]["cancelled"]) ? $tzfd_data["apps"][$tzfd_k]["cancelled"] : '',
                                isset($tzfd_data["lock"])
                            ) &&
                            ( !isset($tzfd_data["apps"][$tzfd_k]["field"]) || @$tzfd_data["apps"][$tzfd_k]["field"] == $field || $field == '' || $tzfd_data["apps"][$tzfd_k]["field"] == '') &&
                            ((sanitize_text_field( wp_unslash( $_REQUEST['tzfd_app_action'] ?? '' ) ) != 'mv') || !strpos($excluded_tzfdcalendar, ','.$tzfd_data["apps"][$tzfd_k]["field"].','))
                           )
                        {
                            if ( ! $this->date_in_range( $tzfd_data["apps"][$tzfd_k]["date"] ?? '', $tzfd_range_start, $tzfd_range_end ) )
                                continue;

                            $slot = $tzfd_data["apps"][$tzfd_k]["slot"];
                            $quantity = TZFD_Scheduling_Rules::normalize_quantity( @$tzfd_data["apps"][$tzfd_k]["quant"] );

                            $availitem = array("d"=>$tzfd_data["apps"][$tzfd_k]["date"] ,"h1"=>intval(substr($slot,0,2)),"m1"=>intval(substr($slot,3,2)),"h2"=>intval(substr($slot,6,2)),"m2"=>intval(substr($slot,9,2)),"serviceindex"=>intval(@$tzfd_data["apps"][$tzfd_k]["serviceindex"]),"quantity"=>$quantity);

                            if (isset($tzfd_data["apps"][$tzfd_k]["sid"]) && $tzfd_data["apps"][$tzfd_k]["sid"] != '')
                                $availitem["sid"] = intval(@$tzfd_data["apps"][$tzfd_k]["sid"]);

                            $tmp2[] = $availitem;

                            if (sanitize_text_field( wp_unslash( $_REQUEST['tzfd_app_action'] ?? '' ) ) == 'mv' && $this->check_current_user_access($formid))
                            {
                                $tzfd_data["INFO"] = $this->sanitize($tzfd_myrows[$tzfd_i]->data);
                                $tmp2[count($tmp2)-1]["info"] = wp_kses_post( $this->replace_tags( $this->sanitize($t_content_admin), $tzfd_data, false, $tzfd_k, true ) );  //   $tzfd_myrows[$tzfd_i]->data;
                                $tmp2[count($tmp2)-1]["e"] = esc_html( $this->replace_tags( $this->sanitize($t_title_admin), $tzfd_data, false, $tzfd_k, true ) );  // sanitize_email($tzfd_myrows[$tzfd_i]->notifyto);
                            }
                        }
                }
            }
            if (sanitize_text_field( wp_unslash( $_REQUEST['tzfd_app_action'] ?? '' ) ) == 'mv')
            {
                if (is_admin() && $this->check_current_user_access(intval($formid)))
                    $this->print_tzfdcalendar_format( $tmp2, $formid, $dataquery, sanitize_text_field( wp_unslash( $_POST["startdate"] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST["enddate"] ?? '' ) ) );
                else 
                    echo 'Authentication required';
            }
            else {
                $dataoutput = wp_json_encode($tmp2);
                do_action( 'tzfd_cache_store', $formid, $dataquery, $dataoutput );
                echo wp_json_encode( $tmp2 );
                //{type:"all",d:"",h1:8,m1:0,h2:17,m2:0}                
            }
		    exit;
        }

    	if( isset( $_REQUEST[ 'tzfd_resources' ] ) )
    	{
    		if( sanitize_text_field( wp_unslash( $_REQUEST[ 'tzfd_resources' ] ?? '' ) ) == 'admin' )
    		{
    			require_once dirname( __FILE__ ).'/js/tzfdform-loader-admin.php';
    		}
            else if( sanitize_text_field( wp_unslash( $_REQUEST[ 'tzfd_resources' ] ?? '' ) ) == 'validatejs' )
    		{
                header("Content-type: application/javascript");  
                $this->print_counter = intval(sanitize_text_field( wp_unslash( $_GET["counter"] ?? '' ) ));
                $this->item = intval(sanitize_text_field( wp_unslash( $_GET["cal"] ?? '' ) ));                
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is a JavaScript response (Content-type application/javascript), not an HTML script tag. The body is built per form: it defines the per-form function names that tzfdform-public.js and the form markup call, and it includes the output of the tzfd_script_after_validation action. Every dynamic value is escaped in getValidateScript() (esc_js, esc_html, intval). Moving it to a static enqueued file would need the per-form values passed another way, so it stays an endpoint for now.
                echo $this->clean_sanitize($this->getValidateScript());
    		}            
    		else
    		{
    			require_once dirname( __FILE__ ).'/js/tzfdform-loader-public.php';
    		}
    		exit;
    	}

        // Scheduled reports are sent only by the tzfd_reports_cron event (run_scheduled_reports). No page load, admin or
        // front end, runs check_reports(), because it writes options and CSV files and can send mail.

        if ($this->get_param($this->prefix.'_captcha') == 'captcha' )
        {
            include_once $this->part_path( 'captcha/captcha.php' );
            exit;
        }
		
        if ($this->get_param($this->prefix.'_blockmultiple') == '1' && is_admin())
        {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST["anonce"] ?? '' ) ), 'tzfd_actions_admin' ) ) { $this->nonce_failed(); }
            $this->block_multiple();
            return;
        }  		

        if ($this->get_param($this->prefix.'_csv1') && is_admin() && $this->check_current_user_access(intval($this->get_param("cal"))))
        {
            // The message list export sends the list nonce in anonce (GET form).
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['anonce'] ?? '' ) ), 'tzfd_actions_booking' ) ) { $this->nonce_failed(); }
            $this->export_csv();
            return;
        }

        if ($this->get_param($this->prefix.'_csv2') && is_admin() && $this->check_current_user_access(intval($this->get_param("cal"))))
        {
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['anonce'] ?? '' ) ), 'tzfd_actions_booking' ) ) { $this->nonce_failed(); }
            $this->export_csv_schedule(array());
            return;
        }

        if ( $this->get_param($this->prefix.'_post_options') && is_admin() && $this->check_current_user_access(intval($this->get_param($this->prefix.'_id'))))
        {
            $this->save_options();
            return;
        }

        if (!isset($_SERVER['REQUEST_METHOD']))
            return;

        if ( 'POST' == $_SERVER['REQUEST_METHOD'] && isset( $_POST['TZFD_post_edition'] ) && current_user_can('edit_pages') && is_admin() )
        {
            $this->save_edition();
            return;
        }

    	if ( 'POST' != $_SERVER['REQUEST_METHOD'] || ! isset( $_POST[$this->prefix.'_pform_process'] ) )
    	    if ( 'GET' != $_SERVER['REQUEST_METHOD'] || !isset( $_GET['hdcaptcha_'.$this->prefix.'_post'] ) )
    		    return;

        // The booking form nonce is verified before any posted value is read (item id, captcha, coupon code, fields).
        // A page cache can serve a form whose nonce has expired; the visitor is asked to reload instead of failing silently.
        if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && ( $this->booking_form_nonce || is_admin() ) )
        {
            $tzfd_anonce = sanitize_text_field( wp_unslash( $_POST['anonce'] ?? '' ) );
            if ( ! wp_verify_nonce( $tzfd_anonce, 'tzfd_actions_bookingform' ) )
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
        if (isset($_GET["ps"])) $sequence = sanitize_text_field(wp_unslash( $_GET["ps"] ?? '' )); else if (isset($_POST["tzfd_pform_psequence"])) $sequence = sanitize_text_field(wp_unslash( $_POST["tzfd_pform_psequence"] ?? '' ));
        $captcha_tr = '';
        if (!empty($_COOKIE['rand_code'.$sequence])) $captcha_tr = get_transient( "tzfd-captcha-".sanitize_key($_COOKIE['rand_code'.$sequence]));
        if (
               !apply_filters( 'tzfd_valid_submission', true) ||
               (
                   (!$titan_zero_front_desk_admin_submission && $this->get_option('cv_enable_captcha', TZFD_BOOK_DEFAULT_cv_enable_captcha) != 'false' && function_exists('imagecreatetruecolor')) &&
                   ( (!isset($_SESSION['rand_code'.$sequence]) ||
                      strtolower($this->get_param('hdcaptcha_'.$this->prefix.'_post')) != strtolower(sanitize_text_field($_SESSION['rand_code'.$sequence])) ||
                     ($_SESSION['rand_code'.$sequence] == ''))
                   )
                   &&
                   ( (strtolower($this->get_param('hdcaptcha_'.$this->prefix.'_post')) != $captcha_tr) ||
                     ($captcha_tr == '')
                   )
               )
           )
        {
            echo 'captchafailed';
            exit;
        }

    	// if this isn't the real post (it was the captcha verification) then echo ok and exit
        if ( 'POST' != $_SERVER['REQUEST_METHOD'] || ! isset( $_POST[$this->prefix.'_pform_process'] ) )
    	{
    	    echo 'ok';
            exit;
    	}

        $posted_items = array();
        foreach ($_POST as $tzfd_item => $tzfd_value)
            $posted_items[sanitize_key($tzfd_item)] = $this->sanitize( wp_unslash( $tzfd_value ) );

        if (isset($_GET["blocktimes"]) && $titan_zero_front_desk_admin_submission && current_user_can('edit_posts'))
            define('TZFD_HOUR_BLOCK_TIMES_PROCESS', true);

        // get form info
        //---------------------------
        //require_once(ABSPATH . "wp-admin" . '/includes/file.php');
        $tzfd_form_data = json_decode($this->cleanJSON($this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure)));
        $fields = array();

        $apps = $this->extract_appointments($tzfd_form_data[0], $posted_items, $sequence);
        if ($this instanceof TZFD_Scheduling_Plugin && !$this->calendar_selection_is_valid($apps)) {
            $this->endSubmission();
            return;
        }
        // Only public intake uses request mode; admin and block-time paths retain donor behavior.
        $titan_zero_front_desk_intake_mode = ( ! $titan_zero_front_desk_admin_submission && ! defined('TZFD_HOUR_BLOCK_TIMES_PROCESS') )
            ? TZFD_Intake_Mode::get_for_form( (int) $this->item )
            : TZFD_Intake_Mode::MODE_INSTANT;
        $apps = TZFD_Intake_Mode::apply_to_appointments($apps, $titan_zero_front_desk_intake_mode);

        $honeypotcheck = sanitize_key(get_option('tzfd_honeypot',''));
        if ($honeypotcheck != '' && (isset($_POST[$honeypotcheck]) && sanitize_text_field( wp_unslash( $_POST[$honeypotcheck] ?? '' ) ) != ''))
    	{
    	    echo 'Blocked by anti-spam rule. Please contact our support service if you this this is an error.';
            exit;
    	}

        $tzfd_price = $this->extract_total_price ($apps);
        $apptext = $this->get_appointments_text ($apps);
        $excluded_items = array();

        foreach ($tzfd_form_data[0] as $tzfd_item)
            if ($tzfd_item->ftype != 'fapp' && !defined('TZFD_HOUR_BLOCK_TIMES_PROCESS'))
            {
                if ($tzfd_item->ftype == 'femail' && isset($posted_items[$tzfd_item->name.$sequence]))
                    $posted_items[$tzfd_item->name.$sequence] = sanitize_email($posted_items[$tzfd_item->name.$sequence]);
                $fields[$tzfd_item->name] = $tzfd_item->title;
                if ($tzfd_item->ftype == 'fPhone') // join fields for phone fields
                {
                    $posted_items[$tzfd_item->name.$sequence] = '';
                    for($tzfd_i=0; $tzfd_i<=substr_count($tzfd_item->dformat," "); $tzfd_i++)
                    {
                        $posted_items[$tzfd_item->name.$sequence] .= ($posted_items[$tzfd_item->name.$sequence."_".$tzfd_i]!=''?($tzfd_i==0?'':'-').$posted_items[$tzfd_item->name.$sequence."_".$tzfd_i]:'');
                        unset($posted_items[$tzfd_item->name.$sequence."_".$tzfd_i]);
                    }
                }
            }
            else
            {
                $fields[$tzfd_item->name] = $tzfd_item->title;
                $posted_items[$tzfd_item->name.$sequence] = $apptext;
                $excluded_items[] = $tzfd_item->name;
            }



        // grab posted data
        //---------------------------
        $buffer = __('Appointments','titan-zero-trades-front-desk').":\n".$apptext."\n";

        $founddata = false;
        $tzfd_params = array();
        // Coupon discounts (if any) are applied here, before the total is shown in emails.
        $tzfd_price = number_format( (float) apply_filters( 'tzfd_invoice_amount', (float) $tzfd_price, array( 'formid' => (int) $this->item, 'itemnumber' => 0 ) ), 2, '.', '' );
        $tzfd_params["final_price"] = $tzfd_price;
        $tzfd_params["final_price_short"] = number_format($tzfd_price,0);
        $tzfd_params["request_timestamp"] = $this->format_date(gmdate("Y-m-d", current_time('timestamp'))). " ". (gmdate("H:i:s", current_time('timestamp')));
        $tzfd_params["titan_zero_front_desk_intake_mode"] = $titan_zero_front_desk_intake_mode;
        $tzfd_params["apps"] = $apps;
        foreach ($apps as $appitem)
        {
           $is_military_t = (!isset($appitem["military"]) || $appitem["military"] == 0 || $appitem["military"] == '');
           $tzfd_params["app_service_".$appitem["id"]] = $appitem["service"];
           $tzfd_params["app_status_".$appitem["id"]] = $appitem["cancelled"];
           $tzfd_params["app_duration_".$appitem["id"]] = $appitem["duration"];
           $tzfd_params["app_price_".$appitem["id"]] = $appitem["price"];
           $tzfd_params["app_date_".$appitem["id"]] = $this->format_date($appitem["date"]);
           $tzfd_params["app_slot_".$appitem["id"]] = $appitem["slot"];
           $slotpieces = explode("/",$appitem["slot"]);
           $tzfd_params["app_starttime_".$appitem["id"]] = $this->format12hours(trim(@$slotpieces[0]), $is_military_t);
           $tzfd_params["app_endtime_".$appitem["id"]] = $this->format12hours(trim(@$slotpieces[1]), $is_military_t);
           $tzfd_params["app_quantity_".$appitem["id"]] = $appitem["quant"];
           $founddata = true;
        }

        $tzfd_params["formid"] = $this->item;
        $tzfd_params["formname"] = $this->get_option('form_name');
        $tzfd_params["referrer"] = $posted_items["refpage".$sequence] ?? '';
        foreach ($posted_items as $tzfd_item => $tzfd_value)
            if (isset($fields[str_replace($sequence,'',$tzfd_item)]))
            {
                if (is_array($tzfd_value))
                {
                    for ($iv=0; $iv<count($tzfd_value); $iv++)
                        $tzfd_value[$iv] = str_replace(TZFD_BOOK_REP_ARR, "@", $tzfd_value[$iv]);
                }
                else
                    $tzfd_value = str_replace(TZFD_BOOK_REP_ARR, "@", $tzfd_value);
                if (!in_array(str_replace($sequence,'',$tzfd_item), $excluded_items))
                    $buffer .= ($fields[str_replace($sequence,'',$tzfd_item)]?$fields[str_replace($sequence,'',$tzfd_item)] . ": ":""). (is_array($tzfd_value)?($this->recursive_implode(", ",$tzfd_value)):($tzfd_value)) . "\n\n";
                $tzfd_params[str_replace($sequence,'',$tzfd_item)] = $tzfd_value;
                $founddata = true;
            }

        $buffer_A = (defined('TZFD_HOUR_BLOCK_TIMES_PROCESS')?__('BLOCKED BY ADMIN','titan-zero-trades-front-desk')."\n\n":'') . $buffer;

        $_SESSION['rand_code'.$sequence] = '';
        if (!empty($_COOKIE['rand_code'.$sequence])) set_transient( "tzfd-captcha-".sanitize_key($_COOKIE['rand_code'.$sequence]) , '' , 1800 );
        
	    /**
	     * Action called before insert the data into database.
	     * To the function is passed an array with submitted data.
	     */
	    do_action( 'tzfd_process_data_before_insert', $tzfd_params );

        if (!$founddata)
        {
            echo 'Empty post! No data received.';
            exit;
        }

        // insert into database
        //---------------------------
        $current_user = wp_get_current_user();
        $tzfd_params["username"] = $current_user->user_login;
        $this->add_field_verify($wpdb->prefix."tzfd_messages", "whoadded");

        $to = $this->get_option('cu_user_email_field', TZFD_BOOK_DEFAULT_cu_user_email_field);
        $sanitized_email = sanitize_email( ( !empty($posted_items[$to.$sequence]) ? $posted_items[$to.$sequence] : '' ) );
        if (defined('TZFD_HOUR_BLOCK_TIMES_PROCESS'))
            $sanitized_email = $this->blocked_by_admin_indicator;

        if (!$this->performAdvancedDoubleBookingVerification($tzfd_params, $sequence))
        {
            $this->endSubmission();
            return; // in the case it shouldn't break the whole process
        }

        $rows_affected = $wpdb->insert( $wpdb->prefix."tzfd_messages", array( 'formid' => $this->item,
                                                                                    'time' => current_time('mysql'),
                                                                                    'ipaddr' => ( get_option('tzfd_storeip', TZFD_BOOK_DEFAULT_track_IP)? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) : '' ),
                                                                                    'notifyto' => $sanitized_email,
                                                                                    'posted_data' => serialize($tzfd_params),
                                                                                    'data' =>$buffer_A,
                                                                                    'whoadded' => "".$current_user->ID
                                                                                   ) );
        if ( !$rows_affected )
        {
            echo 'Error saving data! Please try again.';
            exit;
        }

        // $tzfd_myrows = $wpdb->get_results( "SELECT MAX(id) as max_id FROM ".$wpdb->prefix."tzfd_messages" );
        $item_number = $wpdb->insert_id; // $tzfd_myrows[0]->max_id;

	    // Call action for data processing
	    //---------------------------------
	    $tzfd_params[ 'itemnumber' ] = $item_number;

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
        try {
            do_action_ref_array( 'tzfd_process_data', array(&$tzfd_params) );
        } finally {
            remove_filter( 'titan_zero_front_desk_booking_created_payload', $titan_zero_front_desk_request_event, PHP_INT_MAX );
        }

        // Legacy processing callbacks must not silently approve a public request.
        if ( TZFD_Intake_Mode::MODE_REQUEST === $titan_zero_front_desk_intake_mode ) {
            $tzfd_params = TZFD_Intake_Mode::request_payload( $tzfd_params );
        }

        $wpdb->update( $wpdb->prefix."tzfd_messages",
                       array( 'posted_data' => serialize($tzfd_params) ),
                       array ( 'id' => $item_number),
                       array( '%s' ),
	                   array( '%d' )
                       );

        $this->ready_to_go_reservation( $item_number, "" );
        $_SESSION[ 'tzfd_cff_form_data' ] = $item_number;

        if ($titan_zero_front_desk_admin_submission)
            return;

        /**
		 * Filters applied to decide if the website should be redirected to the thank you page after submit the form,
		 * pass a boolean as parameter and returns a boolean
		 */
		$redirect = true;
        $redirect = apply_filters( 'tzfd_redirect', $redirect );

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
            header( 'Location: ' . esc_url_raw( $this->replace_tags($this->translate_permalink($this->get_option('fp_return_page', TZFD_BOOK_DEFAULT_fp_return_page)), $tzfd_params, true) ) );
            exit();
        }
    }


    public function endSubmission() {
        // Link back to the referring page instead of a javascript: URL.
        $tzfd_back = wp_get_referer();
        if ( ! $tzfd_back ) {
            $tzfd_back = home_url( '/' );
        }
        $tzfd_back = wp_validate_redirect( $tzfd_back, home_url( '/' ) );
        echo esc_html__( 'Selected time is no longer available', 'titan-zero-trades-front-desk' ) . ' <a href="' . esc_url( $tzfd_back ) . '">' . esc_html__( 'please go back and select another time', 'titan-zero-trades-front-desk' ) . '</a>.';
        exit;
    }


    public function performAdvancedDoubleBookingVerification( $tzfd_params, $sequence = "_1", $is_double_check = false ) {
        global $wpdb;

        if ( ! isset( $tzfd_params["apps"] ) || ! is_array( $tzfd_params["apps"] ) || empty( $tzfd_params["apps"] ) ) {
            return true;
        }

        $tzfd_form_data = json_decode( $this->cleanJSON( $this->get_option( 'form_structure', TZFD_BOOK_DEFAULT_form_structure ) ) );
        $requested = array();
        foreach ( $tzfd_params["apps"] as $current_app ) {
            if ( ! isset( $current_app["date"], $current_app["slot"], $current_app["service"] ) ) {
                continue;
            }
            $current_app["quant"] = TZFD_Scheduling_Rules::normalize_quantity( $current_app["quant"] ?? 1 );
            $requested[] = TZFD_Scheduling_Rules::with_service_configuration( $current_app, $tzfd_form_data );
        }
        if ( empty( $requested ) ) {
            return true;
        }

        $latestitems = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, posted_data FROM ".$wpdb->prefix."tzfd_messages WHERE formid=%d ORDER BY ID DESC LIMIT 2000",
                $this->item
            )
        );

        $stored = array();
        foreach ( $latestitems as $tzfd_item ) {
            if ( $is_double_check && $tzfd_item->id == $is_double_check ) {
                continue;
            }
            $latestdata = TZFD_Stored_Data::decode_booking($tzfd_item->posted_data);
            if ( ! isset( $latestdata["apps"] ) || ! is_array( $latestdata["apps"] ) ) {
                continue;
            }
            $has_lock = isset( $latestdata["lock"] );
            foreach ( $latestdata["apps"] as $tzfd_app ) {
                $status = isset( $tzfd_app["cancelled"] ) ? $tzfd_app["cancelled"] : '';
                if ( ! TZFD_Scheduling_Rules::is_occupied_status( $status, $has_lock ) ||
                     ! isset( $tzfd_app["date"], $tzfd_app["slot"], $tzfd_app["service"] ) ) {
                    continue;
                }
                $tzfd_app["quant"] = TZFD_Scheduling_Rules::normalize_quantity( $tzfd_app["quant"] ?? 1 );
                $stored[] = TZFD_Scheduling_Rules::with_service_configuration( $tzfd_app, $tzfd_form_data );
            }
        }

        foreach ( $requested as $index => $candidate ) {
            $other_requested = $requested;
            unset( $other_requested[$index] );
            if ( TZFD_Scheduling_Rules::capacity_exceeded( $candidate, $stored, array_values( $other_requested ) ) ) {
                if ( $is_double_check ) {
                    $wpdb->query( $wpdb->prepare( "DELETE FROM ".$wpdb->prefix."tzfd_messages WHERE id=%d", $is_double_check ) );
                }
                return false;
            }
        }

        return true;
    }

private function countBookingsFor( $tzfd_date, $slot, $service ) {
        global $wpdb;
        $tzfd_count = 0;
        // verification for the latest 1000 submissions
        $like_date = '%' . $wpdb->esc_like( $tzfd_date ) . '%';

        $latestitems = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM %i WHERE formid = %d AND posted_data LIKE %s ORDER BY ID DESC LIMIT 0, 1000", $wpdb->prefix . 'tzfd_messages', $this->item, $like_date)
        );                         
                         
        foreach ($latestitems as $tzfd_item)
        {
            $latestdata = TZFD_Stored_Data::decode_booking($tzfd_item->posted_data);
            if (!isset($latestdata["apps"]) || !is_array($latestdata["apps"])) continue;
            
            foreach ($latestdata["apps"] as $tzfd_app) {
                $status = isset($tzfd_app["cancelled"]) ? $tzfd_app["cancelled"] : '';
                $is_occupied = TZFD_Scheduling_Rules::is_occupied_status( $status, isset($latestdata["lock"]) );
                if ($is_occupied &&
                    isset($tzfd_app["date"], $tzfd_app["slot"], $tzfd_app["service"]) &&
                    $tzfd_app["date"] == $tzfd_date &&
                    $tzfd_app["slot"] == $slot &&
                    $tzfd_app["service"] == $service
                   ) {
                   $quant = TZFD_Scheduling_Rules::normalize_quantity( isset($tzfd_app["quant"]) ? $tzfd_app["quant"] : 1 );
                   $tzfd_count += $quant;
                }
            }
        }
        return $tzfd_count;
    }


    public function replace_tags ( $tzfd_message, $tzfd_params, $urlencode = false, $slotindex = '', $strongs = false ) {
        if ($slotindex !== '' && isset($tzfd_params["apps"][$slotindex]))
        {
            $slotindex++;
            $tzfd_params["app_service"] = $tzfd_params["app_service_".$slotindex];
            $tzfd_params["app_status"] = $tzfd_params["app_status_".$slotindex];
            $tzfd_params["app_duration"] = $tzfd_params["app_duration_".$slotindex];
            $tzfd_params["app_date"] = $tzfd_params["app_date_".$slotindex];
            $tzfd_params["app_slot"] = $tzfd_params["app_slot_".$slotindex];
            $tzfd_params["app_starttime"] = $tzfd_params["app_starttime_".$slotindex];
            $tzfd_params["app_endtime"] = $tzfd_params["app_endtime_".$slotindex];
            $tzfd_params["app_quantity"] = $tzfd_params["app_quantity_".$slotindex];
        }

        foreach ($tzfd_params as $tzfd_item => $tzfd_value)
        {
            if ($urlencode)
                $tzfd_value = urlencode( (is_array($tzfd_value)?'':$tzfd_value) );
            $tzfd_value = (is_array($tzfd_value)?($this->recursive_implode(", ",$tzfd_value)):($tzfd_value));
            if ($strongs) $tzfd_value = esc_html($tzfd_value);
            $tzfd_message = @str_replace('<'.'%'.$tzfd_item.'%'.'>', $tzfd_value, $tzfd_message);
            $tzfd_message = @str_replace('%'.$tzfd_item.'%', $tzfd_value, $tzfd_message);
        }

        for ($tzfd_i=0;$tzfd_i<500;$tzfd_i++)
        {
            $tzfd_message = str_replace('<'.'%fieldname'.$tzfd_i.'%'.'>',"",$tzfd_message);
            $tzfd_message = str_replace('%fieldname'.$tzfd_i.'%',"",$tzfd_message);
            $tzfd_message = str_replace('<'.'%fieldname'.$tzfd_i.'_block%'.'>',"",$tzfd_message);
            $tzfd_message = str_replace('%fieldname'.$tzfd_i.'_endblock%',"",$tzfd_message);
            $tzfd_message = str_replace('%app_date_'.$tzfd_i.'%',"",$tzfd_message);
            $tzfd_message = str_replace('%app_slot_'.$tzfd_i.'%',"",$tzfd_message);
            $tzfd_message = str_replace('%app_starttime_'.$tzfd_i.'%',"",$tzfd_message);
            $tzfd_message = str_replace('%app_endtime_'.$tzfd_i.'%',"",$tzfd_message);
            $tzfd_message = str_replace('%app_service_'.$tzfd_i.'%',"",$tzfd_message);
            $tzfd_message = str_replace('%app_status_'.$tzfd_i.'%',"",$tzfd_message);
            $tzfd_message = str_replace('%app_duration_'.$tzfd_i.'%',"",$tzfd_message);
            $tzfd_message = str_replace('%app_price_'.$tzfd_i.'%',"",$tzfd_message);
            $tzfd_message = str_replace('%app_quantity_'.$tzfd_i.'%',"",$tzfd_message);
        }

        // Remove empty blocks
	    while( preg_match( "/%\s*(.+)_block\s*%/", $tzfd_message, $matches ) )
	    {
	    	if( empty( $tzfd_params[ ''.$matches[ 1 ] ] ) )
	    	{
	    		$from = strpos( $tzfd_message, $matches[ 0 ] );
	    		if( preg_match( "/%\s*(".$matches[ 1 ].")_endblock\s*%/", $tzfd_message, $matches_end ) )
	    		{
	    			$lenght = strpos( $tzfd_message, $matches_end[ 0 ] ) + strlen( $matches_end[ 0 ] ) - $from;
	    		}
	    		else
	    		{
	    			$lenght = strlen( $matches[ 0 ] );
	    		}
	    		$tzfd_message = substr_replace( $tzfd_message, '', $from, $lenght );
	    	}
	    	else
	    	{
	    		$tzfd_message = preg_replace( array( "/%\s*".$matches[ 1 ]."_block\s*%/", "/%\s*".$matches[ 1 ]."_endblock\s*%/"), "", $tzfd_message );
	    	}
	    }

        $tzfd_message = str_replace('<'.'%email'.'%'.'>',"",$tzfd_message);
        $tzfd_message = str_replace('%email'.'%',"",$tzfd_message);
        $tzfd_message = str_replace('%username'.'%',"",$tzfd_message);
        return $tzfd_message;
    }


    public function extract_appointments( $tzfd_form, $tzfd_data, $sequence ) {
        $apps = array();
        $subid = 0;
        if ((is_admin() && get_current_user_id() > 0 && $this->check_current_user_access( (int) $this->item )) || defined('TZFD_HOUR_BLOCK_TIMES_PROCESS'))
		{
            // The admin status choice is only read when the booking form nonce is valid.
            $tzfd_anonce    = sanitize_text_field( wp_unslash( $_POST["anonce"] ?? '' ) );
            $tzfd_anonce_ok = wp_verify_nonce( $tzfd_anonce, 'tzfd_actions_bookingform' ) || wp_verify_nonce( $tzfd_anonce, 'tzfd_actions_admin' );
            if ($tzfd_anonce_ok && is_admin() && isset($_POST["statusbox"]))
                $status = sanitize_text_field(wp_unslash( $_POST["statusbox"] ?? '' ));
            else
			    $status = $this->get_option('defaultpaidstatus', '');
		}
        else
            $status = $this->get_option('defaultstatus', '');
        if (defined('TZFD_HOUR_BLOCK_TIMES_PROCESS')) $status = '';
        foreach($tzfd_form as $field)
            if ($field->ftype == 'fapp' && @$tzfd_data[$field->name.$sequence] != '')
            {
                $apps_text = explode(';',$tzfd_data[$field->name.$sequence]);
                $fieldtotalcost = 0;
                $fieldpostedcost = floatval(@$tzfd_data["tcost".$field->name.$sequence]);
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
        $tzfd_price = 0;
        foreach( $apps as $tzfd_app )
            $tzfd_price += floatval( $tzfd_app["price"] );
        return number_format( $tzfd_price, 2, '.', '' );
    }

    function get_appointments_text( $apps ) {
        $tzfd_option = $this->get_option('display_emails_endtime', '');
        $text = '';
        foreach($apps as $tzfd_app)
        {
            $slot = $tzfd_app["slot"];
            if ($tzfd_option != '')
                $slot = substr ($slot, 0, strpos($slot,"/"));
            $slot = str_replace("/","-",$tzfd_app["slot"]);
            if (!isset($tzfd_app["military"]) || $tzfd_app["military"] == 0 || $tzfd_app["military"] == '')
            {
                $times = explode("-",$slot);
                $times[0] = explode(":",$times[0]);
                $times[1] = explode(":",$times[1]);
                if ($tzfd_option != '')
                    $slot = ($times[0][0]>12?$times[0][0]-12:$times[0][0]).":".$times[0][1].' '.($times[0][0]>=12?'PM':'AM');
                else
                    $slot = ($times[0][0]>12?$times[0][0]-12:$times[0][0]).":".$times[0][1].' '.($times[0][0]>=12?'PM':'AM') ." - ".
                            ($times[1][0]>12?$times[1][0]-12:$times[1][0]).":".$times[1][1].' '.($times[1][0]>=12?'PM':'AM');

            }
            $text .= " - ".$this->format_date($tzfd_app["date"])." ".$slot.(isset($tzfd_app["quant"]) && $tzfd_app["quant"]>1?' ('.$tzfd_app["quant"].')':'')." (".$tzfd_app["service"].")\n";
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


    function format_date( $tzfd_date ) {
        $format = $this->get_option( 'date_format', 'mm/dd/yy' );
        if (!$format) $format = 'mm/dd/yy';
        $format = str_replace( 'mm', 'm', $format );
        $format = str_replace( 'dd', 'd', $format );
        $format = str_replace( 'yy', 'Y', $format );
        $format = str_replace( 'DD', 'K', $format );
        $format = str_replace( 'MM', 'Q', $format );

        $dconv = gmdate( $format, strtotime($tzfd_date) );

        $dconv = str_replace( 'K', ucfirst ( titan_zero_front_desk_translate_legacy_value( gmdate( 'l', strtotime( $tzfd_date ) ) ) ), $dconv );
        $dconv = str_replace( 'Q', ucfirst ( titan_zero_front_desk_translate_legacy_value( gmdate( 'F', strtotime( $tzfd_date ) ) ) ), $dconv );

        return $dconv;
    }

    function ready_to_go_reservation( $itemnumber, $payer_email = "", $resendonly = false ) {

        global $wpdb;

        $tzfd_myrows = $wpdb->get_results( $wpdb->prepare("SELECT * FROM ".$wpdb->prefix."tzfd_messages WHERE id=%d", $itemnumber) ); 
        if (!is_array($tzfd_myrows) || !isset($tzfd_myrows[0]->posted_data) || !empty($wpdb->last_error)) { return false; }
        $tzfd_params = TZFD_Stored_Data::decode_booking($tzfd_myrows[0]->posted_data);
        if (null === $tzfd_params || !isset($tzfd_myrows[0]->formid)) { return false; }
        if (!isset($tzfd_params['apps'])) { $tzfd_params['apps']=array(); }
        $titan_zero_front_desk_pending_request = TZFD_Intake_Mode::is_pending_request( $tzfd_params );
        $titan_zero_front_desk_settled_request = TZFD_Intake_Mode::settled_request_summary( $tzfd_params );
        $mycalendarrows = $wpdb->get_results( $wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'tzfd_forms WHERE `id`=%d', $tzfd_myrows[0]->formid) ); 
        if (!is_array($mycalendarrows) || !isset($mycalendarrows[0]) || !empty($wpdb->last_error)) { return false; }

        $this->item = intval($tzfd_myrows[0]->formid);

        $buffer_A = $tzfd_myrows[0]->data;
        $buffer = $buffer_A;

        if ('true' == $this->get_option('fp_inc_additional_info', TZFD_BOOK_DEFAULT_fp_inc_additional_info))
        {
            $buffer .="ADDITIONAL INFORMATION\n"
                  ."*********************************\n";

            $basic_data = "IP: ".$tzfd_myrows[0]->ipaddr."\n"
              ."Server Time:  ".gmdate("Y-m-d H:i:s", current_time('timestamp'))."\n";

		    /**
		     *	Includes additional information to the email's message,
		     *  are passed two parameters: the basic information, and the IP address
		     */
		    $basic_data = apply_filters( 'tzfd_additional_information',  $basic_data, $tzfd_myrows[0]->ipaddr );
		    $tzfd_params["additional"] = $basic_data;
            $tzfd_params["server_time"] = gmdate("Y-m-d H:i:s", current_time('timestamp'));
		    $buffer .= $basic_data;
        }

        // 1- Send email
        //---------------------------
        $attachments = array();

        $tzfd_message = str_replace('<'.'%', '%', titan_zero_front_desk_translate_legacy_value($this->get_option('fp_message', TZFD_BOOK_DEFAULT_fp_message)));
        $tzfd_message = str_replace('%'.'>', '%', $tzfd_message);
        $subject = str_replace('<'.'%', '%', titan_zero_front_desk_translate_legacy_value($this->get_option('fp_subject', TZFD_BOOK_DEFAULT_fp_subject)));
        $subject = str_replace('%'.'>', '%', $subject);

        if ('html' == $this->get_option('fp_emailformat', TZFD_BOOK_DEFAULT_email_format))
            $tzfd_message = str_replace('%INFO%',str_replace("\n","<br />",str_replace('<','&lt;',$buffer)),$tzfd_message);
        else
            $tzfd_message = str_replace('%INFO%',$buffer,$tzfd_message);
        $subject = $this->get_option('fp_subject', TZFD_BOOK_DEFAULT_fp_subject);

        /**
		 *	Attach or modify attached files,
		 *  Example for adding ical or PDF attachments
		 */
		$attachments = apply_filters( 'tzfd_email_attachments',  $attachments, $tzfd_params, $this->item);

        $tzfd_params["apps"] = $this->get_appointments_text($tzfd_params["apps"]);
        foreach ($tzfd_params as $tzfd_item => $tzfd_value)
        {
            $tzfd_message = str_replace('%'.$tzfd_item.'%',(is_array($tzfd_value)?($this->recursive_implode(", ",$tzfd_value)):($tzfd_value)),$tzfd_message);
            $subject = str_replace('%'.$tzfd_item.'%',(is_array($tzfd_value)?($this->recursive_implode(", ",$tzfd_value)):($tzfd_value)),$subject);
            if (strpos($tzfd_item,"_link"))
            {
                foreach ($tzfd_value as $filevalue)
                    $attachments[] = $filevalue;
            }
        }

        $tzfd_message = str_replace('%itemnumber%',$itemnumber,$tzfd_message);
        $subject = str_replace('%itemnumber%',$itemnumber,$subject);

        $from = $this->get_option('fp_from_email', (defined('TZFD_BOOK_DEFAULT_fp_from_email')?TZFD_BOOK_DEFAULT_fp_from_email:''));
        $from_name = $this->get_option('fp_from_name', '');
        $to = explode(",",$this->get_option('fp_destination_emails', (defined('TZFD_BOOK_DEFAULT_fp_destination_emails')?TZFD_BOOK_DEFAULT_fp_destination_emails:'')));
        
        
        $is_html_admin = ('html' == $this->get_option('fp_emailformat', (defined('TZFD_BOOK_DEFAULT_email_format')?TZFD_BOOK_DEFAULT_email_format:'text')));
        $html_filter = function() { return 'text/html'; };
        if ( $is_html_admin ) { add_filter( 'wp_mail_content_type', $html_filter ); }

        $replyto = sanitize_email($tzfd_myrows[0]->notifyto);
        if ($this->get_option('fp_emailfrommethod', "fixed") == "customer")
            $from_1 = $replyto;
        else
            $from_1 = $from;

        if ($this->get_option('fp_emailtomethod', "fixed") == 'customer')
        {
            $text_addr = $tzfd_params[$this->get_option('fp_destination_emails_field', "fixed")];
            if (is_array($text_addr))
                $text_addr = implode(", ",$text_addr);
            $pattern = '/[a-zA-Z0-9_\.\+-]+@[A-Za-z0-9_-]+\.([A-Za-z0-9_-][A-Za-z0-9_]+)/'; //regex for pattern of e-mail address
            preg_match_all($pattern, $text_addr, $matches);
            if (count($matches[0]) > 0)
                $to = $matches[0];
        }
        $to = array_unique ($to);

        $tzfd_message = $this->replace_tags($tzfd_message, $tzfd_params);
        $subject = $this->replace_tags($subject, $tzfd_params);


        // if is_admin and not required emails end function here
        // The admin "send notification e-mails" choice only counts when the booking form nonce is valid.
        $tzfd_anonce    = sanitize_text_field( wp_unslash( $_POST["anonce"] ?? '' ) );
        $tzfd_anonce_ok = wp_verify_nonce( $tzfd_anonce, 'tzfd_actions_bookingform' ) || wp_verify_nonce( $tzfd_anonce, 'tzfd_actions_admin' );
        $tzfd_send_now  = $tzfd_anonce_ok && isset($_POST["sendemails_admin"]);
        if (is_admin() && get_current_user_id() > 0 && $this->check_current_user_access( (int) $this->item ) && !$tzfd_send_now && !$resendonly)
            return;

        foreach ($to as $tzfd_item)
            if (trim($tzfd_item) != '')
            {
                if (!strpos($from_1,">"))
                    $from_1 = '"'.($from_name!=''?$from_name:$from_1).'" <'.$from_1.'>';
                
                $headers = array();
                $headers[] = "From: " . $from;
                if ($replyto!='')
                    $headers[] = "Reply-To: " . $replyto;
                $headers[] = "X-Mailer: PHP/" . phpversion();
            
                //wp_mail(trim($tzfd_item), $subject, $tzfd_message,
                //    "From: ".$from_1."\r\n".
                //    ($replyto!=''?"Reply-To: ".$replyto."\r\n":'').
                //    $content_type.
                //    "X-Mailer: PHP/" . phpversion(), $attachments);                   

                wp_mail(trim($tzfd_item), $subject, $tzfd_message, $headers, $attachments);
            }

        if ( $is_html_admin ) { remove_filter( 'wp_mail_content_type', $html_filter ); }
        
        // A front-end booking does not call check_reports(). The tzfd_reports_cron event sends these reports on their schedule.

        // 2- Send copy to user
        //---------------------------
        $to = $this->get_option('cu_user_email_field', TZFD_BOOK_DEFAULT_cu_user_email_field);
        $destination_to = sanitize_email($tzfd_myrows[0]->notifyto);
        if (($destination_to != '' || $payer_email != '') && 'true' == $this->get_option('cu_enable_copy_to_user', TZFD_BOOK_DEFAULT_cu_enable_copy_to_user))
        {
            $tzfd_message = str_replace('<'.'%', '%', titan_zero_front_desk_translate_legacy_value($this->get_option('cu_message', TZFD_BOOK_DEFAULT_cu_message)));
            $tzfd_message = str_replace('%'.'>', '%', $tzfd_message);

            $subject = str_replace('<'.'%', '%', titan_zero_front_desk_translate_legacy_value($this->get_option('cu_subject', TZFD_BOOK_DEFAULT_cu_subject)));
            $subject = str_replace('%'.'>', '%', $subject);
            if ('html' == $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format))
                $tzfd_message = str_replace('%INFO%',str_replace("\n","<br />",str_replace('<','&lt;',$buffer_A)).'</pre>',$tzfd_message);
            else
                $tzfd_message = str_replace('%INFO%',$buffer_A,$tzfd_message);

            $tzfd_message = str_replace('%itemnumber%',$itemnumber,$tzfd_message);
            $subject = str_replace('%itemnumber%',$itemnumber,$subject);

            $tzfd_message = $this->replace_tags($tzfd_message, $tzfd_params);
            $subject = $this->replace_tags($subject, $tzfd_params);

            // Do not reuse instant-confirm copy or calendar invitations for a pending request.
            $customer_attachments = $attachments;
            if ( $titan_zero_front_desk_pending_request ) {
                $subject = __( 'Booking request received', 'titan-zero-trades-front-desk' );
                $tzfd_message = TZFD_Intake_Mode::acknowledgment() . "\n\n"
                    . __( 'Request reference:', 'titan-zero-trades-front-desk' ) . ' ' . (int) $itemnumber . "\n\n" . $buffer_A;
                if ( 'html' === $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format) ) {
                    $tzfd_message = nl2br( esc_html( $tzfd_message ) );
                }
                $customer_attachments = array();
            } elseif ( null !== $titan_zero_front_desk_settled_request ) {
                $subject = __( 'Booking request status update', 'titan-zero-trades-front-desk' );
                $tzfd_message = __( 'Current appointment statuses:', 'titan-zero-trades-front-desk' ) . "\n"
                    . $titan_zero_front_desk_settled_request . "\n\n"
                    . __( 'Request reference:', 'titan-zero-trades-front-desk' ) . ' ' . (int) $itemnumber;
                if ( 'html' === $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format) ) {
                    $tzfd_message = nl2br( esc_html( $tzfd_message ) );
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
                $customer_message = apply_filters('titan_zero_front_desk_customer_copy_message', $tzfd_message, $tzfd_myrows[0], $headers_cu, $this);
                wp_mail($destination_to, $subject, $customer_message, $headers_cu, $customer_attachments);
            }
            if ($destination_to != $payer_email && $payer_email != '')
                wp_mail(trim($payer_email), $subject, $tzfd_message, $headers_cu, $customer_attachments);
                
            if ( $is_html_cu ) { remove_filter( 'wp_mail_content_type', $html_filter ); }            
        }
        
        // Remove generated .ics invitations, but only those inside this plugin's upload folder.
        $tzfd_upload_dir = wp_upload_dir();
        $tzfd_ics_base   = realpath( trailingslashit( $tzfd_upload_dir['basedir'] ) . 'titan-zero-trades-front-desk' );
        foreach ($attachments as $tzfd_item)
        {
            if ( false === $tzfd_ics_base || ! is_string( $tzfd_item ) || 'ics' !== strtolower( pathinfo( $tzfd_item, PATHINFO_EXTENSION ) ) )
                continue;
            $tzfd_real_item = realpath( $tzfd_item );
            if ( false !== $tzfd_real_item && 0 === strpos( $tzfd_real_item, trailingslashit( $tzfd_ics_base ) ) )
                wp_delete_file( $tzfd_real_item );
        }

    }


    function recursive_implode( $glue, array $array, $include_keys = false, $trim_all = true ) {
    	$glued_string = '';

    	// Recursively iterates array and adds key/value to glued string
    	array_walk_recursive($array, function($tzfd_value, $tzfd_key) use ($glue, $include_keys, &$glued_string)
    	{
    		$include_keys and $glued_string .= $tzfd_key.$glue;
    		$glued_string .= $tzfd_value.$glue;
    	});

    	// Removes last $glue from string
    	strlen($glue) > 0 and $glued_string = substr($glued_string, 0, -strlen($glue));

    	// Trim ALL whitespace
    	// $trim_all and $glued_string = preg_replace("/(\s)/ixsm", '', $glued_string);

    	return (string) $glued_string;
    }


    function available_templates() {

    	if( empty( $this->TZFD_CFPP_global_templates ) )
    	{
    		// Get available designs
    		$this->TZFD_CFPP_global_templates = array();
    		$tzfd_tpls_path = plugin_dir_path( __FILE__ ).'templates';
    		$tzfd_tpls_dir  = is_dir( $tzfd_tpls_path ) ? dir( $tzfd_tpls_path ) : false;
    		while( $tzfd_tpls_dir && false !== ( $tzfd_entry = $tzfd_tpls_dir->read() ) )
    		{
    			if ( $tzfd_entry != '.' && $tzfd_entry != '..' && is_dir( $tzfd_tpls_dir->path.'/'.$tzfd_entry ) && file_exists( $tzfd_tpls_dir->path.'/'.$tzfd_entry.'/config.ini' ) && function_exists('parse_ini_file'))
    			{
    				if( function_exists('parse_ini_file') && ( $ini_array = parse_ini_file( $tzfd_tpls_dir->path.'/'.$tzfd_entry.'/config.ini' ) ) !== false )
    				{
    					if( !empty( $ini_array[ 'file' ] ) ) $ini_array[ 'file' ] = plugins_url( 'templates/'.$tzfd_entry.'/'.$ini_array[ 'file' ], __FILE__ );
    					if( !empty( $ini_array[ 'thumbnail' ] ) ) $ini_array[ 'thumbnail' ] = plugins_url( 'templates/'.$tzfd_entry.'/'.$ini_array[ 'thumbnail' ], __FILE__ );
    					$this->TZFD_CFPP_global_templates[ $ini_array[ 'prefix' ] ] = $ini_array;
    				}
    			}
    		}
    	}

    	return $this->TZFD_CFPP_global_templates;
    }


    function block_multiple()
    {
        global $wpdb;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST["anonce"] ?? '' ) ), 'tzfd_actions_admin' ) ) { $this->nonce_failed(); }
        // Same capability as the calendar and bookings screens (edit_pages). Checked before any row is written.
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        $rawfromfield = sanitize_text_field(wp_unslash( $_POST["bdate"] ?? '' ));
        if ($rawfromfield == '')
            return;
        $rawfromfield = explode(",",$rawfromfield);
       
	    $calendars = array();
	   	$tzfd_selected_calendars = map_deep( wp_unslash( $_POST["selectedcalendar"] ?? array() ), 'intval' );
	   	if (is_array($tzfd_selected_calendars))
		    foreach ($tzfd_selected_calendars as $tzfd_item)
                $calendars[] = intval($tzfd_item);
        
        $current_user = wp_get_current_user();
        
        foreach ($rawfromfield as $tzfd_rawfrom)
        {
            $tzfd_rawfrom = trim($tzfd_rawfrom);
            
            foreach ($calendars as $calendar)
            {
                if ($calendar)
                    $this->setId($calendar);
                else
                {
                    $tzfd_myrows = $wpdb->get_results( "SELECT * FROM ".$wpdb->prefix."tzfd_forms" );
                    $this->setId( isset( $tzfd_myrows[0] ) ? $tzfd_myrows[0]->id : 0 );
                }

                // Skip any calendar the current user cannot manage. The check uses the same ID that the row is written
                // with ($calendar), so calendar 0 is checked as formid 0 and not against the first form. Every non-zero
                // calendar is checked exactly as before. The first form is still loaded above for the date format.
                if ( $this->check_current_user_access( $calendar ) )
                {

                    if ($this->get_option('date_format', 'mm/dd/yy') == 'dd/mm/yy')
                        $tzfd_rawfrom = str_replace('/','.',$tzfd_rawfrom);
                    $from = gmdate("Y-m-d H:i", strtotime($tzfd_rawfrom. " ".sanitize_text_field(wp_unslash( $_POST["h1"] ?? '' )). ":".sanitize_text_field(wp_unslash( $_POST["m1"] ?? '' ))));
                    $to = gmdate("Y-m-d H:i", strtotime($tzfd_rawfrom. " ".sanitize_text_field(wp_unslash( $_POST["h2"] ?? '' )). ":".sanitize_text_field(wp_unslash( $_POST["m2"] ?? '' ))));
                    $duration = ceil((strtotime($tzfd_rawfrom. " ".sanitize_text_field(wp_unslash( $_POST["h2"] ?? '' )). ":".sanitize_text_field(wp_unslash( $_POST["m2"] ?? '' ))) -  strtotime($tzfd_rawfrom. " ".sanitize_text_field(wp_unslash( $_POST["h1"] ?? '' )). ":".sanitize_text_field(wp_unslash( $_POST["m1"] ?? '' ))))  / 60);
                            
                    
                    $apps = array();
                    $apps[] = array ( 'id' => 1,
                                      'cancelled' => '',
                                      'serviceindex' => -1,
                                      'service' => __('[all services]','titan-zero-trades-front-desk'),
                                      'duration' => $duration,
                                      'price' => 0, //$field->services[ $item_split[2] ]->price,
                                      'date' => gmdate("Y-m-d", strtotime($tzfd_rawfrom. " ".sanitize_text_field(wp_unslash( $_POST["h1"] ?? '' )). ":".sanitize_text_field(wp_unslash( $_POST["m1"] ?? '' )))),
                                      'slot' => gmdate("H:i", strtotime($tzfd_rawfrom. " ".sanitize_text_field(wp_unslash( $_POST["h1"] ?? '' )). ":".sanitize_text_field(wp_unslash( $_POST["m1"] ?? '' ))))."/". gmdate("H:i", strtotime($tzfd_rawfrom. " ".sanitize_text_field(wp_unslash( $_POST["h2"] ?? '' )). ":".sanitize_text_field(wp_unslash( $_POST["m2"] ?? '' )))),
                                      'military' => 0,
                                      'field' => '',
                                      'quant' => 0
                                    );    
                                    
                     $apptext = __('BLOCKED BY ADMIN','titan-zero-trades-front-desk').  "\n\n" . $this->get_appointments_text ($apps);                        
                     
                      $tzfd_params = array();
                     $tzfd_params["apps"] = $apps;
                     $current_user = wp_get_current_user();
                     $rows_affected = $wpdb->insert( $wpdb->prefix."tzfd_messages", array( 'formid' => $calendar,
                                                                                                 'time' => current_time('mysql'),
                                                                                                 'ipaddr' => (get_option('tzfd_storeip', TZFD_BOOK_DEFAULT_track_IP)? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) : ''),
                                                                                                 'notifyto' => $this->blocked_by_admin_indicator,
                                                                                                 'posted_data' => serialize($tzfd_params),
                                                                                                 'data' => $apptext,
                                                                                                 'whoadded' => "".$current_user->ID
                                                                                                ) );
                }
            }
        }                                                                           
    }
	

    function save_edition() {
        global $wpdb;

        if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_POST["nonce"] ?? '' )), 'tzfd_actions_admin' ) ) { $this->nonce_failed(); }
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }

        $posted_items = array();
        foreach ($_POST as $tzfd_item => $tzfd_value)
            $posted_items[sanitize_key($tzfd_item)] = $this->sanitize( wp_unslash( $tzfd_value ) );
        if (isset($_POST["gotab"]) && sanitize_text_field( wp_unslash( $_POST["gotab"] ?? '' ) ) == '')
        {
            update_option( 'tzfd_rep_enable', sanitize_text_field($posted_items["tzfd_rep_enable"]));
            update_option( 'tzfd_rep_days', sanitize_text_field($posted_items["tzfd_rep_days"]));
            update_option( 'tzfd_rep_hour', sanitize_text_field($posted_items["tzfd_rep_hour"]));
            update_option( 'tzfd_rep_emails', sanitize_text_field($posted_items["tzfd_rep_emails"]));
            update_option( 'tzfd_fp_from_email', sanitize_text_field($posted_items["tzfd_fp_from_email"]));
            // Report subject and body are sent as plain text without placeholder replacement, so they are sanitized directly.
            update_option( 'tzfd_rep_subject', sanitize_text_field( wp_unslash( $_POST["tzfd_rep_subject"] ?? '' ) ));
            update_option( 'tzfd_rep_emailformat', sanitize_text_field($posted_items["tzfd_rep_emailformat"]));
            if ( 'html' === sanitize_text_field( $posted_items["tzfd_rep_emailformat"] ) ) {
                update_option( 'tzfd_rep_message', wp_kses_post( wp_unslash( $_POST["tzfd_rep_message"] ?? '' ) ));
            } else {
                update_option( 'tzfd_rep_message', sanitize_textarea_field( wp_unslash( $_POST["tzfd_rep_message"] ?? '' ) ));
            }
        }
        else if (isset($_POST["gotab"]) && sanitize_text_field( wp_unslash( $_POST["gotab"] ?? '' ) ) == 'csvarea')
        {
            update_option( 'tzfd_bocsvexclude', sanitize_text_field($posted_items["bocsvexclude"]));
            update_option( 'tzfd_schcsvexclude', sanitize_text_field($posted_items["schcsvexclude"]));
        }
        else if (isset($_POST["gotab"]) && sanitize_text_field( wp_unslash( $_POST["gotab"] ?? '' ) ) == 'miscsettings')
        {
            $honeypot = sanitize_text_field($posted_items["tzfd_honeypot"]);
            if ($honeypot == 'Yes')
                $honeypot = 'tzfdhb'.substr(sanitize_key(md5(wp_generate_uuid4())),0,5);
            update_option( 'tzfd_honeypot', $honeypot);
            update_option( 'tzfd_storeip', sanitize_text_field($posted_items["tzfd_storeip"]));
            // Only save the language when the field was posted, so a save without it never blanks the stored value.
            if ( isset( $_POST["tzfd_admin_language"] ) ) {
                update_option( 'tzfd_admin_language', sanitize_text_field( wp_unslash( $_POST["tzfd_admin_language"] ) ) );
            }
            if ( current_user_can( 'manage_options' ) ) {
                TZFD_Intake_Mode::set_default( TZFD_Intake_Mode::normalize( sanitize_key( wp_unslash( $_POST['intake_default'] ?? '' ) ) ) );
                $tzfd_intake_posted = ( isset( $_POST['intake_form'] ) && is_array( $_POST['intake_form'] ) ) ? map_deep( wp_unslash( $_POST['intake_form'] ), 'sanitize_text_field' ) : array();
                foreach ( $tzfd_intake_posted as $tzfd_intake_fid => $tzfd_intake_mode ) {
                    TZFD_Intake_Mode::set_for_form( (int) $tzfd_intake_fid, TZFD_Intake_Mode::normalize( sanitize_key( (string) $tzfd_intake_mode ) ) );
                }
            }
        }
        else if (current_user_can('manage_options') && isset($_POST["gotab"]) && sanitize_text_field( wp_unslash( $_POST["gotab"] ?? '' ) ) == 'schedulecalarea')
        {
            update_option( 'tzfd_schcaltitle_admin', $this->clean_sanitize($posted_items["schcaltitle_admin"]));
            update_option( 'tzfd_schcalcontent_admin', $this->clean_sanitize($posted_items["schcalcontent_admin"]));
            update_option( 'tzfd_schcaltitle_public', $this->clean_sanitize($posted_items["schcaltitle_public"]));
            update_option( 'tzfd_schcalcontent_public', $this->clean_sanitize($posted_items["schcalcontent_public"]));
            update_option( 'tzfd_sch_admin_blockedt', sanitize_text_field($posted_items["tzfd_sch_admin_blockedt"]));
            update_option( 'tzfd_schcalcontent_exclude', trim(str_replace(' ','',str_replace('>','',str_replace('<','',str_replace('%','',sanitize_text_field(wp_unslash( $_POST["tzfd_schcalcontent_exclude"] ?? '' ))))))));
            update_option( 'tzfd_schcalcontent_otherparams', $this->clean_sanitize($posted_items["tzfd_schcalcontent_otherparams"]));

        }
        else if (current_user_can('manage_options') && isset($_POST["gotab"]) && sanitize_text_field( wp_unslash( $_POST["gotab"] ?? '' ) ) == 'fixarea')
        {
            update_option( 'TZFD_LOAD_SCRIPTS', ($posted_items["ccscriptload"]=="1"?"0":"1") );
            update_option( 'TZFD_CSV_CHARFIX', ($posted_items["csvcharautofix"]==""?"":"1") );
            update_option( 'TZFD_CSV_SEPARATOR', ($posted_items["csvseparator"]==";"?";":",") );
            if ($posted_items["cccharsets"] != '')
            {
                $target_charset = str_replace('`','``',sanitize_text_field($posted_items["cccharsets"]));
                $tables = array( $wpdb->prefix."tzfd_messages", $wpdb->prefix."tzfd_forms" );
                foreach ($tables as $tab)
                {
                    $tzfd_tab_name = $this->sanitizeTableName($tab);
                    $tzfd_myrows   = $wpdb->get_results( $wpdb->prepare( 'DESCRIBE %i', $tzfd_tab_name ) );
                    $tzfd_collate  = $this->sanitizeTableName($target_charset);
                    foreach ($tzfd_myrows as $tzfd_item)
	                {
	                    $tzfd_col_name = $this->sanitizeTableName($tzfd_item->Field);
	        	        $type = $tzfd_item->Type;
	        	        // Column types are matched against a fixed list. Identifiers use %i and the length uses %d.
	        	        if (preg_match("/^varchar\((\d+)\)$/i", $type, $mat))
	        	            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i VARCHAR(%d) COLLATE %i', $tzfd_tab_name, $tzfd_col_name, $tzfd_col_name, (int) $mat[1], $tzfd_collate ) );
	        	        elseif (!strcasecmp($type, "CHAR"))
	        	            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i CHAR COLLATE %i', $tzfd_tab_name, $tzfd_col_name, $tzfd_col_name, $tzfd_collate ) );
	        	        elseif (!strcasecmp($type, "TEXT"))
	        	            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i TEXT COLLATE %i', $tzfd_tab_name, $tzfd_col_name, $tzfd_col_name, $tzfd_collate ) );
	        	        elseif (!strcasecmp($type, "MEDIUMTEXT"))
	        	            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i CHANGE %i %i MEDIUMTEXT COLLATE %i', $tzfd_tab_name, $tzfd_col_name, $tzfd_col_name, $tzfd_collate ) );
	                }
                }
            }
        }
        // The custom CSS editor (editionarea) was removed. Stored TZFD_CSS data is left untouched and is not saved or output.
    }


    function save_options() {
        global $wpdb;
        $this->item = intval( wp_unslash( $_POST[$this->prefix."_id"] ?? 0 ) );

        if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_POST["anonce"] ?? '' )), 'tzfd_actions_admin' ) ) { $this->nonce_failed(); }
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        // The route checked the calendar named in the query string (get_param() reads GET first). This function writes to the
        // calendar in the POST body, so check that calendar too. Without this, the two could differ.
        if ( ! $this->check_current_user_access( $this->item ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }

        if (false == get_option('TZFD_ONE_TIME_3UPDATE',false))
        {
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'fp_from_name');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'vs_text_nmore');
			$this->add_field_verify($wpdb->prefix."tzfd_forms", 'vs_text_nomore');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'vs_text_cost');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'vs_text_cancel');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'vs_text_quantity');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'calendar_language');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'date_format');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'vs_text_maxapp');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'defaultstatus');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'defaultpaidstatus');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'tzfd_user_access_settings');
            $this->add_field_verify($wpdb->prefix."tzfd_forms", 'display_emails_endtime');
            update_option('TZFD_ONE_TIME_3UPDATE',true);
        }
        $_pdata = wp_unslash( $_POST );
        while ((substr_count($_pdata['form_structure'],"\\") > 30) || substr_count($_pdata['form_structure'],"\\\"title\\\":"))
            foreach ($_pdata as $tzfd_item => $tzfd_value)
                if (!is_array($tzfd_value))
                    $_pdata[$tzfd_item] = stripcslashes($tzfd_value);

        $tzfd_fp_is_html  = ( 'html' === sanitize_text_field( $_pdata['fp_emailformat'] ?? '' ) ) ? 'html' : 'text';
        $tzfd_cu_is_html  = ( 'html' === sanitize_text_field( $_pdata['cu_emailformat'] ?? '' ) ) ? 'html' : 'text';

        $tzfd_data = array(
                      'form_structure' => $this->clean_sanitize($this->admin_process_json($_pdata['form_structure'])),

                      'vs_text_maxapp' => sanitize_text_field($_pdata['vs_text_maxapp']),
                      'calendar_language' => sanitize_text_field($_pdata['calendar_language']),
                      'date_format' => sanitize_text_field($_pdata['date_format']),
                      'product_name' => sanitize_text_field($_pdata['product_name']),
                      'pay_later_label' => sanitize_text_field($_pdata['pay_later_label']),
                      'fp_from_email' => sanitize_text_field($_pdata['fp_from_email']),
                     // 'fp_from_name' => sanitize_text_field($_pdata['fp_from_name']),
                      'fp_destination_emails' => sanitize_text_field(@$_pdata['fp_destination_emails']),
                      'fp_subject' => $this->tzfd_sanitize_template_value( $_pdata['fp_subject'] ?? '', 'line' ),
                      'fp_inc_additional_info' => sanitize_text_field($_pdata['fp_inc_additional_info']),
                      'fp_return_page' => sanitize_text_field($_pdata['fp_return_page']),
                      'fp_message' => $this->tzfd_sanitize_template_value( $_pdata['fp_message'] ?? '', $tzfd_fp_is_html ),
                      'fp_emailformat' => sanitize_text_field($_pdata['fp_emailformat']),

                      'defaultstatus' => sanitize_text_field($_pdata['defaultstatus']),
                      'defaultpaidstatus' => sanitize_text_field($_pdata['defaultpaidstatus']),

                      'fp_emailtomethod' => sanitize_text_field($_pdata['fp_emailtomethod']),
                      'fp_destination_emails_field' => sanitize_text_field(@$_pdata['fp_destination_emails_field']),

                      'cu_enable_copy_to_user' => sanitize_text_field($_pdata['cu_enable_copy_to_user']),
                      'cu_user_email_field' => sanitize_text_field(@$_pdata['cu_user_email_field']),
                      'cu_subject' => $this->tzfd_sanitize_template_value( $_pdata['cu_subject'] ?? '', 'line' ),
                      'cu_message' => $this->tzfd_sanitize_template_value( $_pdata['cu_message'] ?? '', $tzfd_cu_is_html ),
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

                      'tzfd_user_access' => serialize($this->sanitize( ( isset($_pdata["tzfd_user_access"]) ? $_pdata["tzfd_user_access"] : array() ) )),
                      'tzfd_user_access_settings' => sanitize_text_field($_pdata['tzfd_user_access_settings']),
                      'display_emails_endtime' => sanitize_text_field($_pdata['display_emails_endtime']),
                      'rep_enable' => sanitize_text_field($_pdata['rep_enable']),
                      'rep_days' => sanitize_text_field($_pdata['rep_days']),
                      'rep_hour' => sanitize_text_field($_pdata['rep_hour']),
                      'rep_emails' => sanitize_text_field($_pdata['rep_emails']),
                      'rep_subject' => $this->tzfd_sanitize_template_value( $_pdata['rep_subject'] ?? '', 'line' ),
                      'rep_emailformat' => sanitize_text_field($_pdata['rep_emailformat']),
                      'rep_message' => $this->tzfd_sanitize_template_value( $_pdata['rep_message'] ?? '', ( 'html' === sanitize_text_field( $_pdata['rep_emailformat'] ?? '' ) ? 'html' : 'text' ) ),

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
        if ( ! current_user_can( 'manage_options' ) ) {
            unset( $tzfd_data['tzfd_user_access'], $tzfd_data['tzfd_user_access_settings'] );
        }

        $wpdb->update( $wpdb->prefix."tzfd_forms", $tzfd_data, array( 'id' => $this->item ));

        $tzfd_data = array( 'fp_from_name' => sanitize_text_field($_pdata['fp_from_name']) );
        $wpdb->update( $wpdb->prefix."tzfd_forms", $tzfd_data, array( 'id' => $this->item ));

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


    function get_form_field_label( $fieldid, $tzfd_form ) {
            foreach($tzfd_form as $tzfd_item)
                if ($tzfd_item->name == $fieldid)
                {
                    if (isset($tzfd_item->shortlabel) && $tzfd_item->shortlabel != '')
                        return $tzfd_item->shortlabel;
                    else
                        return $tzfd_item->title;
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

    function clean_csv_value( $tzfd_value ) {
        $tzfd_value = trim($tzfd_value);
        while (strlen($tzfd_value) > 1 && in_array($tzfd_value[0],array('=','@')))
            $tzfd_value = trim(substr($tzfd_value, 1));
        return $tzfd_value;
    }


    function export_csv() {
        if ( !is_admin() )
            return;
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        global $wpdb;

        $this->item = intval( $this->get_param("cal") );

        if ( $this->item )
        {
            $tzfd_form = json_decode( $this->cleanJSON( $this->get_option( 'form_structure', TZFD_BOOK_DEFAULT_form_structure ) ) );
            $tzfd_form = $tzfd_form[0];
        }
        else
            $tzfd_form = array();

        // Every condition is a placeholder in one prepared query. A condition that is not in use is switched off by its flag.
        $tzfd_has_search = (bool) $this->get_param( 'search' );
        $tzfd_like       = $tzfd_has_search ? '%' . $wpdb->esc_like( $this->get_param( 'search' ) ) . '%' : '';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only export parameter; the export is capability-gated and changes no state.
        $tzfd_rawfrom = isset( $_GET['dfrom'] ) ? sanitize_text_field( wp_unslash( $_GET['dfrom'] ?? '' ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only export parameter; the export is capability-gated and changes no state.
        $tzfd_rawto   = isset( $_GET['dto'] )   ? sanitize_text_field( wp_unslash( $_GET['dto'] ?? '' ) ) : '';

        if ( $this->get_option( 'date_format', 'mm/dd/yy' ) === 'dd/mm/yy' ) {
            $tzfd_rawfrom = str_replace( '/', '.', $tzfd_rawfrom );
            $tzfd_rawto   = str_replace( '/', '.', $tzfd_rawto );
        }

        $tzfd_has_from   = (bool) $this->get_param( 'dfrom' );
        $tzfd_date_start = $tzfd_has_from ? gmdate( 'Y-m-d', strtotime( $tzfd_rawfrom ) ) : '';

        $tzfd_has_to   = (bool) $this->get_param( 'dto' );
        $tzfd_date_end = $tzfd_has_to ? gmdate( 'Y-m-d', strtotime( $tzfd_rawto ) ) . ' 23:59:59' : '';

        $tzfd_has_form = ( $this->item != 0 );

        $tzfd_table_name = $wpdb->prefix . 'tzfd_messages';
        $tzfd_events_query = $wpdb->prepare(
            'SELECT * FROM %i WHERE (%d = 1 OR (data LIKE %s OR posted_data LIKE %s)) AND (%d = 1 OR `time` >= %s) AND (%d = 1 OR `time` <= %s) AND (%d = 1 OR formid = %d) ORDER BY `time` DESC',
            $tzfd_table_name,
            $tzfd_has_search ? 0 : 1, $tzfd_like, $tzfd_like,
            $tzfd_has_from ? 0 : 1, $tzfd_date_start,
            $tzfd_has_to ? 0 : 1, $tzfd_date_end,
            $tzfd_has_form ? 0 : 1, (int) $this->item
        );

        /**
         * Allows modify the query of messages, passing the query as parameter
         * returns the new query
         */
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Developer-only filter, kept with its existing contract. The query passed in is built by $wpdb->prepare() above, with every request value (search, dfrom, dto, form id) as a placeholder. No request input is concatenated into it. Filter callbacks are registered by code, never from request data. The sniff cannot see through apply_filters(), so the annotation stays.
        $tzfd_events = $wpdb->get_results( apply_filters( 'tzfd_csv_query', $tzfd_events_query ) );

        if ($this->include_user_data_csv)
            $fields = array("ID", "Form", "Time", "IP Address", "email");
        else
            $fields = array("ID", "Form", "Time", "email");

        $fields_exclude = explode(",",trim(get_option('tzfd_bocsvexclude',"")));
        $fields_exclude_org = array();
        for($tzfd_j=0; $tzfd_j<count($fields_exclude); $tzfd_j++)
        {
           $fields_exclude_org[] = (trim($fields_exclude[$tzfd_j]));
           $fields_exclude[$tzfd_j] = strtolower(trim($fields_exclude[$tzfd_j]));
        }

        $fields = array_values(array_diff($fields, $fields_exclude_org));

        $newfields = array();
        for($tzfd_j=0; $tzfd_j<count($fields); $tzfd_j++)
           if (!in_array($fields[$tzfd_j],$fields_exclude))
               $newfields[] = $fields[$tzfd_j];
        $fields = $newfields;

        $saved_formid = $this->item;

        $values = array();
        foreach ($tzfd_events as $tzfd_item)
        {
            $tzfd_value = array();
            if (in_array('ID',$fields)) $tzfd_value[] = $tzfd_item->id;
            $this->item = $tzfd_item->formid;
            if (in_array('Form',$fields)) $tzfd_value[] = $this->get_option('form_name','');
            $this->item = $saved_formid;
            if (in_array('Time',$fields)) $tzfd_value[] = $tzfd_item->time;
            if ($this->include_user_data_csv)
                if (in_array('IP Address',$fields)) $tzfd_value[] = $tzfd_item->ipaddr;
            if (in_array('email',$fields)) $tzfd_value[] = sanitize_email($tzfd_item->notifyto);

            $tzfd_data = !empty($tzfd_item->posted_data) ? TZFD_Stored_Data::decode_booking($tzfd_item->posted_data) : array();
            if (!is_array($tzfd_data)) { $tzfd_data=array(); }

            $end = count($fields);
            for ($tzfd_i=0; $tzfd_i<$end; $tzfd_i++)
                {  //  if (isset($tzfd_data[$fields[$tzfd_i]]) )
                    if (isset($tzfd_data[$fields[$tzfd_i]]))
                        $tzfd_d = $tzfd_data[$fields[$tzfd_i]];
                    else if (!isset($tzfd_value[$tzfd_i]))
                        $tzfd_d = "";
                    else
                        $tzfd_d = $tzfd_value[$tzfd_i];
                    if (substr($fields[$tzfd_i],0,strlen('app_status_')) == 'app_status_')
                    {
                       $tzfd_d = $tzfd_data["apps"][ intval( substr($fields[$tzfd_i],strlen('app_status_'))-1) ]["cancelled"];
                       if ($tzfd_d == '')
                           $tzfd_d = __('Approved','titan-zero-trades-front-desk');
                    }
                    $tzfd_value[$tzfd_i] = $tzfd_d;
                    unset($tzfd_data[$fields[$tzfd_i]]);
                }

            if (is_array($tzfd_data)) foreach ($tzfd_data as $tzfd_k => $tzfd_d)
                if ($tzfd_k != 'apps' && $tzfd_k != 'itemnumber' && !in_array(strtolower($tzfd_k),$fields_exclude))
                {
                   $fields[] = $tzfd_k;
                   if (substr($tzfd_k,0,strlen('app_status_')) == 'app_status_')
                   {
                       $tzfd_d = $tzfd_data["apps"][ intval( substr($tzfd_k,strlen('app_status_'))-1) ]["cancelled"];
                       if ($tzfd_d == '')
                           $tzfd_d = __('Approved','titan-zero-trades-front-desk');
                   }
                   $tzfd_value[] = $tzfd_d;
                }
            $values[] = $tzfd_value;
        }

        $separator = get_option('TZFD_CSV_SEPARATOR',",");
        if ($separator == '') $separator = ',';

        $filename = $this->generateSafeFileName(strtolower($this->get_option('form_name','export'))).'_'.gmdate("m_d_y");

        header("Content-type: application/octet-stream");
        header("Content-Disposition: attachment; filename=".$filename.".csv");
        // CSV is a file download, not HTML, so rows are written to the response stream unchanged.
        $tzfd_csv_out = fopen( 'php://output', 'w' );
        $end = count($fields);
        for ($tzfd_i=0; $tzfd_i<$end; $tzfd_i++)
        {
            $hlabel = $this->iconv("utf-8", "ISO-8859-1//TRANSLIT//IGNORE", $this->get_form_field_label($fields[$tzfd_i],$tzfd_form));
            fwrite( $tzfd_csv_out, '"'.str_replace('"','""', $this->clean_csv_value($hlabel)).'"'.$separator );
        }

        echo "\n";
        foreach ( $values as $tzfd_item )
        {
            for ( $tzfd_i=0; $tzfd_i<$end; $tzfd_i++ )
            {
                if ( !isset( $tzfd_item[$tzfd_i] ) )
                    $tzfd_item[$tzfd_i] = '';
                if (is_array($tzfd_item[$tzfd_i]))
                    $tzfd_item[$tzfd_i] = implode(',',$tzfd_item[$tzfd_i]);
                $tzfd_item[$tzfd_i] = $this->iconv("utf-8", "ISO-8859-1//TRANSLIT//IGNORE", $tzfd_item[$tzfd_i]);
                fwrite( $tzfd_csv_out, '"'.str_replace('"','""', $this->clean_csv_value($tzfd_item[$tzfd_i])).'"'.$separator );
            }
            echo "\n";
        }

        exit;
    }


    function export_csv_schedule( $atts ) {
        if ( !is_admin() )
            return;
        if ( ! current_user_can( 'edit_pages' ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        global $wpdb;
        extract( shortcode_atts( array(
	    	'calendar' => '',
	    	'fields' => 'DATE,TIME,SERVICE,final_price,paid,email,data,cancelled',
	    	'from' => "today",
	    	'to' => "today +30 days",
            'paidonly' => "",
            'status' => "-1"
	    ), $atts ) );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only export parameter; the export is capability-gated and changes no state.
        if (isset($_REQUEST["dfrom"])) $from = sanitize_text_field(wp_unslash( $_REQUEST["dfrom"] ?? '' ));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only export parameter; the export is capability-gated and changes no state.
        if (isset($_REQUEST["dto"])) $to = sanitize_text_field(wp_unslash( $_REQUEST["dto"] ?? '' ));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only export parameter; the export is capability-gated and changes no state.
        if (isset($_REQUEST["status"])) $status = sanitize_text_field(wp_unslash( $_REQUEST["status"] ?? '' ));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only export parameter; the export is capability-gated and changes no state.
        if (isset($_REQUEST["paid"])) $paidonly = sanitize_text_field(wp_unslash( $_REQUEST["paid"] ?? '' ));
        if ($this->get_param("cal")) $calendar = intval($this->get_param("cal"));

        ob_start();

        // calculate dates
        $this->item = intval($this->get_param("cal"));
        if ($this->get_option('date_format', 'mm/dd/yy') == 'dd/mm/yy' || $this->get_option('date_format', 'mm/dd/yy') == 'd M, y')
        {
            $from = str_replace('/','.',$from);
            $to = str_replace('/','.',$to);
        }

        $from = str_replace(',',' ',$from);
        $to = str_replace(',',' ',$to);
        
        $from = gmdate("Y-m-d",strtotime($from));
        $to = gmdate("Y-m-d",strtotime($to));

        // pre-select time-slots
        $tzfd_selection = array();

        $tzfd_table_name = $wpdb->prefix . 'tzfd_messages';
        if ( $calendar ) {
            $tzfd_rows = $wpdb->get_results( 
                $wpdb->prepare("SELECT notifyto, posted_data, data FROM %i WHERE formid = %d AND time <= %s ORDER BY time DESC LIMIT 0, 10000", $tzfd_table_name, $calendar, $to) 
            );
        } else {
            $tzfd_rows = $wpdb->get_results( 
                $wpdb->prepare("SELECT notifyto, posted_data, data FROM %i WHERE time <= %s ORDER BY time DESC LIMIT 0, 10000", $tzfd_table_name, $to) 
            );
        }
 
        foreach($tzfd_rows as $tzfd_item)
        {
            $tzfd_data = TZFD_Stored_Data::decode_booking($tzfd_item->posted_data);
            if (null === $tzfd_data || !isset($tzfd_data["apps"])) { continue; }
            if (!$paidonly || $tzfd_data['paid'])
            {
                foreach($tzfd_data["apps"] as $tzfd_app)
                    if ($tzfd_app["date"] >= $from && $tzfd_app["date"] <= $to && ($status == 'all' || $status == '-1' || $status == $tzfd_app["cancelled"]) )
                    {
                        $tzfd_selection[] = array($tzfd_app["date"]." ".$tzfd_app["slot"], $tzfd_app["date"], $tzfd_app["slot"], $tzfd_data, sanitize_email($tzfd_item->notifyto), $tzfd_item->data, $tzfd_app["cancelled"], $tzfd_app["service"], $tzfd_app["quant"]);
                    }
            }
        }

        // order time-slots
        if (!function_exists('tzfdbkfastsortfn'))
        {
            function tzfdbkfastsortfn( $a, $b ) { return ( $a[0] > $b[0] ? 1 : -1 ); }
        }
        usort($tzfd_selection, "tzfdbkfastsortfn" );

        // clean fields IDs
        $fields = explode(",",trim($fields));
        for($tzfd_j=0; $tzfd_j<count($fields); $tzfd_j++)
           $fields[$tzfd_j] = strtolower(trim($fields[$tzfd_j]));

        $fields_exclude = explode(",",trim(get_option('tzfd_schcsvexclude',"")));
        for($tzfd_j=0; $tzfd_j<count($fields_exclude); $tzfd_j++)
           $fields_exclude[$tzfd_j] = strtolower(trim($fields_exclude[$tzfd_j]));

        $fields = array_values(array_diff ($fields, $fields_exclude));

        $separator = get_option('TZFD_CSV_SEPARATOR',",");
        if ($separator == '') $separator = ',';

		
        if ($this->item)
        {
            $tzfd_form = json_decode($this->cleanJSON($this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure)));
            $tzfd_form = $tzfd_form[0];
        }
        else
            $tzfd_form = array();

        $filename = $this->generateSafeFileName(strtolower($this->get_option('form_name','export'))).'_'.gmdate("m_d_y");

        header("Content-type: application/octet-stream");
        header("Content-Disposition: attachment; filename=".$filename.".csv");
        // CSV is a file download, not HTML, so rows are written to the response stream unchanged.
        $tzfd_csv_out = fopen( 'php://output', 'w' );
		
        $end = count($fields);
        for ($tzfd_i=0; $tzfd_i<$end; $tzfd_i++)
        {
            $hlabel = $this->iconv("utf-8", "ISO-8859-1//TRANSLIT//IGNORE", $this->get_form_field_label($fields[$tzfd_i],$tzfd_form));
			if ($hlabel == 'cancelled') $hlabel = 'status';
            fwrite( $tzfd_csv_out, '"'.str_replace('"','""', $this->clean_csv_value($hlabel)).'"'.$separator );
        }
        echo "\n";		
		
        // print table
        for($tzfd_i=0; $tzfd_i<count($tzfd_selection); $tzfd_i++)
        {
            for($tzfd_j=0; $tzfd_j<count($fields); $tzfd_j++)
            {
                if ($tzfd_j>0) echo esc_html($separator);
                echo '"';
                switch ($fields[$tzfd_j]) {
                    case 'date':
                        $tzfd_value = $tzfd_selection[$tzfd_i][1];
                        break;
                    case 'time':
                        $tzfd_value = $tzfd_selection[$tzfd_i][2];
                        break;
                    case 'email':
                        $tzfd_value = $tzfd_selection[$tzfd_i][4];
                        break;
                    case 'service':
                        $tzfd_value = $tzfd_selection[$tzfd_i][7];
                        break;
                    case 'quantity':
                        $tzfd_value = $tzfd_selection[$tzfd_i][8];
                        break;
                    case 'cancelled':
                        if ($tzfd_selection[$tzfd_i][6] == '')
                            $tzfd_value = __('Approved','titan-zero-trades-front-desk');
                        else
                            $tzfd_value = $tzfd_selection[$tzfd_i][6];
                        break;
                    case 'data':
                        $tzfd_value = substr($tzfd_selection[$tzfd_i][5],strpos($tzfd_selection[$tzfd_i][5],"\n\n")+2);
                        break;
                    case 'paid':
                        $tzfd_value = (@$tzfd_selection[$tzfd_i][3]['paid']?__('Yes','titan-zero-trades-front-desk'):'');
                        break;
                    default:
                        $tzfd_value = ($tzfd_selection[$tzfd_i][3][$fields[$tzfd_j]]==''?'':$tzfd_selection[$tzfd_i][3][$fields[$tzfd_j]])."";
                }
                $tzfd_value = str_replace('"','""', $tzfd_value);
                fwrite( $tzfd_csv_out, $this->clean_csv_value($tzfd_value) );
                echo '"';
            }
            echo "\n";
        }

        exit;
    }


    public function fixurl( $base, $tzfd_param ) {
        if (strpos($base,'?'))
            return $base."&".$tzfd_param;
        else
		{
			$base = rtrim($base,"/")."/";
            return $base."?".$tzfd_param;
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
        $tzfd_form_data = json_decode($this->cleanJSON($str));
        if (is_array($tzfd_form_data))
        {
            $tzfd_form_data[1][0]->title = $this->filter_allowed_tags( $tzfd_form_data[1][0]->title );
            $tzfd_form_data[1][0]->description = $this->filter_allowed_tags( $tzfd_form_data[1][0]->description );
            
            for ( $tzfd_i=0; $tzfd_i < count($tzfd_form_data[0]); $tzfd_i++ )
            {
                if ( isset( $tzfd_form_data[0][$tzfd_i]->title ) )
                    $tzfd_form_data[0][$tzfd_i]->title = $this->filter_allowed_tags(($tzfd_form_data[0][$tzfd_i]->title));
                $tzfd_form_data[0][$tzfd_i]->userhelpTooltip = $this->filter_allowed_tags(($tzfd_form_data[0][$tzfd_i]->userhelpTooltip));
                $tzfd_form_data[0][$tzfd_i]->userhelp = $this->filter_allowed_tags(($tzfd_form_data[0][$tzfd_i]->userhelp));
            
                if ($tzfd_form_data[0][$tzfd_i]->ftype == 'fCommentArea')
                    $tzfd_form_data[0][$tzfd_i]->userhelp = $this->filter_allowed_tags(($tzfd_form_data[0][$tzfd_i]->userhelp));
                else if ($tzfd_form_data[0][$tzfd_i]->ftype == 'fradio' || $tzfd_form_data[0][$tzfd_i]->ftype == 'fcheck' || $tzfd_form_data[0][$tzfd_i]->ftype == 'fdropdown')
                {
                        for ($tzfd_j=0; $tzfd_j < count($tzfd_form_data[0][$tzfd_i]->choices); $tzfd_j++)
                            $tzfd_form_data[0][$tzfd_i]->choices[$tzfd_j] = $this->filter_allowed_tags(($tzfd_form_data[0][$tzfd_i]->choices[$tzfd_j]));
                }
                else if ($tzfd_form_data[0][$tzfd_i]->ftype == 'fapp')
                {
                    for ($tzfd_j=0; $tzfd_j < count($tzfd_form_data[0][$tzfd_i]->services); $tzfd_j++)
                        $tzfd_form_data[0][$tzfd_i]->services[$tzfd_j]->name = $this->filter_allowed_tags(($tzfd_form_data[0][$tzfd_i]->services[$tzfd_j]->name));
                    if (isset($tzfd_form_data[0][$tzfd_i]->emptySelect))
                        $tzfd_form_data[0][$tzfd_i]->emptySelect= $this->filter_allowed_tags(($tzfd_form_data[0][$tzfd_i]->emptySelect));
                }
            }
            $str = wp_json_encode( $tzfd_form_data );
        }
        return $str;
    }


    public function translate_json( $str ) {
        $tzfd_form_data = json_decode( $this->cleanJSON( $str ) );
        if (is_array($tzfd_form_data))
        {
            $tzfd_form_data[1][0]->title = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tzfd_form_data[1][0]->title));
            $tzfd_form_data[1][0]->description = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tzfd_form_data[1][0]->description));

            for ($tzfd_i=0; $tzfd_i < count($tzfd_form_data[0]); $tzfd_i++)
            {
                if (property_exists($tzfd_form_data[0][$tzfd_i], 'title'))
                    $tzfd_form_data[0][$tzfd_i]->title = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tzfd_form_data[0][$tzfd_i]->title));
                $tzfd_form_data[0][$tzfd_i]->userhelpTooltip = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tzfd_form_data[0][$tzfd_i]->userhelpTooltip));
                $tzfd_form_data[0][$tzfd_i]->userhelp = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tzfd_form_data[0][$tzfd_i]->userhelp));

                $tzfd_form_data[0][$tzfd_i]->csslayout = esc_attr(str_replace('"', ' ', sanitize_text_field(titan_zero_front_desk_translate_legacy_value($tzfd_form_data[0][$tzfd_i]->csslayout))));

                if (property_exists($tzfd_form_data[0][$tzfd_i], 'predefined') && $tzfd_form_data[0][$tzfd_i]->predefined != '' && $tzfd_form_data[0][$tzfd_i]->ftype != 'fapp')
                    $tzfd_form_data[0][$tzfd_i]->predefined = titan_zero_front_desk_translate_legacy_value($tzfd_form_data[0][$tzfd_i]->predefined);

                if ($tzfd_form_data[0][$tzfd_i]->ftype == 'fCommentArea')
                    $tzfd_form_data[0][$tzfd_i]->userhelp = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tzfd_form_data[0][$tzfd_i]->userhelp));
                else if ($tzfd_form_data[0][$tzfd_i]->ftype == 'fradio' || $tzfd_form_data[0][$tzfd_i]->ftype == 'fcheck' || $tzfd_form_data[0][$tzfd_i]->ftype == 'fdropdown')
                {
                        for ($tzfd_j=0; $tzfd_j < count($tzfd_form_data[0][$tzfd_i]->choices); $tzfd_j++)
                            $tzfd_form_data[0][$tzfd_i]->choices[$tzfd_j] = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tzfd_form_data[0][$tzfd_i]->choices[$tzfd_j]));
                }
                else if ($tzfd_form_data[0][$tzfd_i]->ftype == 'fapp')
                {
                    for ($tzfd_j=0; $tzfd_j < count($tzfd_form_data[0][$tzfd_i]->services); $tzfd_j++)
                        $tzfd_form_data[0][$tzfd_i]->services[$tzfd_j]->name = $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tzfd_form_data[0][$tzfd_i]->services[$tzfd_j]->name));
                    if (isset($tzfd_form_data[0][$tzfd_i]->emptySelect))
                        $tzfd_form_data[0][$tzfd_i]->emptySelect= $this->filter_allowed_tags(titan_zero_front_desk_translate_legacy_value($tzfd_form_data[0][$tzfd_i]->emptySelect));
                }
            }
            $str = wp_json_encode($tzfd_form_data);
        }
        return $str;
    }


    private function get_records_csv( $formid, $form_name = "" ) {
        global $wpdb;

        $saved_item = $this->item;
        $this->item = $formid;

        $last_sent_id = get_option('tzfd_last_sent_id_'.$formid, '0');
        $tzfd_events = $wpdb->get_results(
                             $wpdb->prepare("SELECT * FROM ".$wpdb->prefix."tzfd_messages WHERE formid=%d AND id>%d ORDER BY id ASC",$formid,$last_sent_id) 
                                     );

        if ($wpdb->num_rows <= 0) // if no rows, return empty
        {
            $this->item = $saved_item;
            return '';
        }

        if ($this->item)
        {
            $tzfd_form = json_decode($this->cleanJSON($this->get_option('form_structure', TZFD_BOOK_DEFAULT_form_structure)));
            $tzfd_form = $tzfd_form[0];
        }
        else
            $tzfd_form = array();

        $buffer = '';
        if ($this->include_user_data_csv)
            $fields = array("Submission ID", "Form", "Time", "IP Address", "email");
        else
            $fields = array("Submission ID", "Form", "Time", "email");

        $fields_exclude = explode(",",trim(get_option('tzfd_bocsvexclude',"")));
        $fields_exclude_org = array();
        for($tzfd_j=0; $tzfd_j<count($fields_exclude); $tzfd_j++)
        {
           $fields_exclude_org[] = (trim($fields_exclude[$tzfd_j]));
           $fields_exclude[$tzfd_j] = strtolower(trim($fields_exclude[$tzfd_j]));
        }

        $fields = array_values(array_diff($fields, $fields_exclude_org));

        $newfields = array();
        for($tzfd_j=0; $tzfd_j<count($fields); $tzfd_j++)
           if (!in_array($fields[$tzfd_j],$fields_exclude))
               $newfields[] = $fields[$tzfd_j];
        $fields = $newfields;


        $values = array();
        foreach ($tzfd_events as $tzfd_item)
        {
            $tzfd_value = array();
            if (in_array('Submission ID',$fields)) $tzfd_value[] = $tzfd_item->id;
            if (in_array('Form',$fields)) $tzfd_value[] = $this->get_option('form_name','');
            if (in_array('Time',$fields)) $tzfd_value[] = $tzfd_item->time;
            if ($this->include_user_data_csv)
                if (in_array('IP Address',$fields)) $tzfd_value[] = $tzfd_item->ipaddr;
            if (in_array('email',$fields)) $tzfd_value[] = sanitize_email($tzfd_item->notifyto);

            $last_sent_id = $tzfd_item->id;
            $tzfd_data = !empty($tzfd_item->posted_data) ? TZFD_Stored_Data::decode_booking($tzfd_item->posted_data) : array();
            if (!is_array($tzfd_data)) { $tzfd_data=array(); }

            $end = count($fields);
            for ($tzfd_i=0; $tzfd_i<$end; $tzfd_i++)
            { //if (isset($tzfd_data[$fields[$tzfd_i]]) ){
                if (isset($tzfd_data[$fields[$tzfd_i]]))
                    $tzfd_d = $tzfd_data[$fields[$tzfd_i]];
                else if (!isset($tzfd_value[$tzfd_i]))
                    $tzfd_d = "";
                else
                    $tzfd_d = $tzfd_value[$tzfd_i];
                if (substr($fields[$tzfd_i],0,strlen('app_status_')) == 'app_status_')
                {
                   $tzfd_d = $tzfd_data["apps"][ intval( substr($fields[$tzfd_i],strlen('app_status_'))-1) ]["cancelled"];
                   if ($tzfd_d == '')
                       $tzfd_d = __('Approved','titan-zero-trades-front-desk');
                }
                $tzfd_value[$tzfd_i] = $tzfd_d;
                unset($tzfd_data[$fields[$tzfd_i]]);
            }

            if (is_array($tzfd_data)) foreach ($tzfd_data as $tzfd_k => $tzfd_d)
                if ($tzfd_k != 'apps' && $tzfd_k != 'itemnumber' && !in_array(strtolower($tzfd_k),$fields_exclude))
                {
                   $fields[] = $tzfd_k;
                   if (substr($tzfd_k,0,strlen('app_status_')) == 'app_status_')
                   {
                       $tzfd_d = $tzfd_data["apps"][ intval( substr($tzfd_k,strlen('app_status_'))-1) ]["cancelled"];
                       if ($tzfd_d == '')
                           $tzfd_d = __('Approved','titan-zero-trades-front-desk');
                   }
                   $tzfd_value[] = $tzfd_d;
                }
            $values[] = $tzfd_value;
        }
        update_option('tzfd_last_sent_id_'.$formid, $last_sent_id);


        $separator = get_option('TZFD_CSV_SEPARATOR',",");
        if ($separator == '') $separator = ',';

        $end = count($fields);
        for ($tzfd_i=0; $tzfd_i<$end; $tzfd_i++)
        {
            $hlabel = $this->iconv("utf-8", "ISO-8859-1//TRANSLIT//IGNORE", $this->get_form_field_label($fields[$tzfd_i],$tzfd_form));
            $buffer .= '"'.str_replace('"','""', $hlabel).'"'.$separator;
        }
        $buffer .= "\n";
        foreach ($values as $tzfd_item)
        {
            for ($tzfd_i=0; $tzfd_i<$end; $tzfd_i++)
            {
                if (!isset($tzfd_item[$tzfd_i]))
                    $tzfd_item[$tzfd_i] = '';
                if (is_array($tzfd_item[$tzfd_i]))
                    $tzfd_item[$tzfd_i] = implode(',',$tzfd_item[$tzfd_i]);
                $tzfd_item[$tzfd_i] = $this->iconv("utf-8", "ISO-8859-1//TRANSLIT//IGNORE", $tzfd_item[$tzfd_i]);
                $buffer .= '"'.str_replace('"','""', $tzfd_item[$tzfd_i]).'"'.$separator;
            }
            $buffer .= "\n";
        }

        $this->item = $saved_item;

        return $buffer;

    }

    /**
     * WP-Cron callback for the tzfd_reports_cron event. check_reports() is private, so this public method calls it.
     */
    public function run_scheduled_reports() {
        $this->check_reports();
    }

    private function check_reports( $skip_verification = false ) {
        global $wpdb;

        $last_verified = get_option( 'tzfd_last_verified', '' );
        if ( $skip_verification || $last_verified == '' || $last_verified < gmdate("Y-m-d H:i:s", strtotime("-1 minutes")) )  // verification to don't check too fast to avoid overloading the site
        {
            update_option('tzfd_last_verified',gmdate("Y-m-d H:i:s"));
            
            // Report CSV files are written to this plugin's upload folder and deleted after sending.
            $tzfd_upload_dir = wp_upload_dir();
            $tmp_dir         = untrailingslashit( $tzfd_upload_dir['basedir'] ) . '/titan-zero-trades-front-desk';
            if ( ! is_dir( $tmp_dir ) ) {
                wp_mkdir_p( $tmp_dir );
            }

            // global reports for all forms
            if (get_option('tzfd_rep_enable', 'no') == 'yes' && get_option('tzfd_rep_days', '') != '' && get_option('tzfd_rep_emails', '') != '' )
            {
                $formid = 0;
                $verify_after = gmdate("Y-m-d H:i:s", strtotime("-".get_option('tzfd_rep_days', '')." days"));
                $last_sent = get_option('tzfd_last_sent'.$formid, '');
                if ($last_sent == '' || $last_sent < $verify_after)  // check if this form needs to check for a new report
                {
                    update_option('tzfd_last_sent'.$formid, gmdate("Y-m-d ".(get_option('tzfd_rep_hour', '')<'10'?'0':'').get_option('tzfd_rep_hour', '').":00:00"));
                    $text = '';
                    $forms = $wpdb->get_results("SELECT id,fp_from_email,form_name,rep_days,rep_hour,rep_emails,rep_subject,rep_emailformat,rep_message,rep_enable FROM ".$wpdb->prefix."tzfd_forms");  
                    // " WHERE rep_emails<>'' AND rep_enable='yes'"
                    $attachments = array();
                    foreach ($forms as $tzfd_form)  // for each form with the reports enabled
                    {
                        $csv = $this->get_records_csv($tzfd_form->id, $tzfd_form->form_name);
                        if ($csv != '')
                        {
                            $text = "- ".substr_count($csv,",\n\"").' submissions from '.$tzfd_form->form_name."\n";
                            $filename = $this->generateSafeFileName(strtolower($tzfd_form->form_name)).'_'.gmdate("m_d_y");
                            $filename = $tmp_dir . '/' . $filename . '.csv';
                            require_once ABSPATH . 'wp-admin/includes/file.php';
                            WP_Filesystem();
                            global $wp_filesystem;
                            $wp_filesystem->put_contents( $filename, $csv, FS_CHMOD_FILE );
                            $attachments[] = $filename;
                        }
                    }
                                    
                    $is_html_rep = ('html' == get_option('tzfd_rep_emailformat',''));
                    $html_filter = function() { return 'text/html'; };
                    if ( $is_html_rep ) { add_filter( 'wp_mail_content_type', $html_filter ); }
                    
                    $headers_rep = array();
                    $headers_rep[] = "From: \"" . get_option('tzfd_fp_from_email','') . "\" <" . get_option('tzfd_fp_from_email','') . ">";
                    $headers_rep[] = "X-Mailer: PHP/" . phpversion();

                    if (count($attachments))
                        wp_mail( str_replace(" ","",str_replace(";",",",get_option('tzfd_rep_emails',''))), get_option('tzfd_rep_subject',''), get_option('tzfd_rep_message','')."\n".$text, $headers_rep, @$attachments);
                        
                    if ( $is_html_rep ) { remove_filter( 'wp_mail_content_type', $html_filter ); }                                    
                                    
                                    
                    foreach ($attachments as $tzfd_file_path)
                        wp_delete_file($tzfd_file_path);                                      
                }
            }

            // reports for specific forms
            $forms = $wpdb->get_results("SELECT id,form_name,fp_from_email,rep_days,rep_hour,rep_emails,rep_subject,rep_emailformat,rep_message,rep_enable FROM ".$wpdb->prefix."tzfd_forms WHERE rep_emails<>'' AND rep_enable='yes'"); 
            foreach ($forms as $tzfd_form)  // for each form with the reports enabled
            {
                $formid = $tzfd_form->id;
                $verify_after = gmdate("Y-m-d H:i:s", strtotime("-".$tzfd_form->rep_days." days"));
                $last_sent = get_option('tzfd_last_sent'.$formid, '');
                if ($skip_verification || $last_sent == '' || $last_sent < $verify_after)  // check if this form needs to check for a new report
                {
                    update_option('tzfd_last_sent'.$formid, gmdate("Y-m-d ".($tzfd_form->rep_hour<'10'?'0':'').$tzfd_form->rep_hour.":00:00"));
                    $csv = $this->get_records_csv($formid, $tzfd_form->form_name);
                    if ($csv != '')
                    {
                        $filename = $this->generateSafeFileName(strtolower($tzfd_form->form_name)).'_'.gmdate("m_d_y");
                        $filename = $tmp_dir . '/'.$filename .'.csv';
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        WP_Filesystem();
                        global $wp_filesystem;
                        $wp_filesystem->put_contents( $filename, $csv, FS_CHMOD_FILE );
                        $attachments = array( $filename );
                        
                        $is_html_frep = ('html' == $tzfd_form->rep_emailformat);
                        $html_filter = function() { return 'text/html'; };
                        if ( $is_html_frep ) { add_filter( 'wp_mail_content_type', $html_filter ); }
                        
                        $headers_frep = array();
                        $headers_frep[] = "From: \"" . $tzfd_form->fp_from_email . "\" <" . $tzfd_form->fp_from_email . ">";
                        $headers_frep[] = "X-Mailer: PHP/" . phpversion();

                        wp_mail( str_replace(" ","",str_replace(";",",",$tzfd_form->rep_emails)), $tzfd_form->rep_subject, $tzfd_form->rep_message, $headers_frep, $attachments);
                                
                        if ( $is_html_frep ) { remove_filter( 'wp_mail_content_type', $html_filter ); }
                        
                        foreach ( $attachments as $tzfd_file_path )
                            wp_delete_file( $tzfd_file_path );                                  
                    }
                }
            } // end foreach
        } // end if
    }  // end check_reports function


    protected function iconv( $from, $to, $text ) {
        $text = trim( $text );
        if ( strlen( $text ) > 1 && ( in_array( substr( $text, 0, 1 ), array( '=', '@', '+' )) ) )
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
    private function tzfd_sanitize_template_value( $value, $mode = 'text' ) {
        if ( ! is_scalar( $value ) ) {
            return '';
        }
        $value        = (string) $value;
        $placeholders = array();
        $value        = preg_replace_callback(
            '/<%.*?%>/s',
            function ( $tzfd_match ) use ( &$placeholders ) {
                $tzfd_token                = '{tzfdph' . count( $placeholders ) . '}';
                $placeholders[ $tzfd_token ] = $tzfd_match[0];
                return $tzfd_token;
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
