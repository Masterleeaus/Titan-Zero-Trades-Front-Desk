<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__.'/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';

if( !class_exists( 'TZFD_Passwordaddon' ) )
{
    /**
     * Requires a shared password before the public form accepts a booking.
     * Set per calendar. The password is stored as a one-way hash, so it cannot
     * be read back; entering a new one replaces it. Passwords saved before
     * hashing was added are upgraded the first time they are used.
     */
    class TZFD_Passwordaddon extends TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-Password";
		protected $name = "Password for making bookings";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const KEY        = 'password';
        const FIELD_NAME = 'tzfd_form_password';
        const NONCE_ACTION = 'tzfd_booking_password';
        const NONCE_FIELD  = 'tzfd_booking_password_nonce';

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
			$this->description = $this->tr_apply("The add-on requires customers to enter a password before a booking is accepted.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            add_filter( 'tzfd_the_form', array( $this, 'add_password_field' ), 98, 2 );
            add_action( 'tzfd_process_data_before_insert', array( $this, 'check_submission' ), 7, 1 );
        } // End __construct

        /************************ SETTINGS *****************************/

        public function get_addon_settings()
        {
            return TZFD_Addon_Support::global_settings_block( self::KEY, self::defaults(), array(
                'password' => array( 'label' => __( 'Booking password', 'titan-zero-trades-front-desk' ), 'type' => 'password', 'help' => __( 'Customers must enter this to make a booking. Leave blank to keep the current password.', 'titan-zero-trades-front-desk' ) ),
            ), __( 'Default for new calendars. Each calendar can change these in its own settings.', 'titan-zero-trades-front-desk' ) );
        }

        public static function defaults()
        {
            return array( 'password' => '' );
        }

        public function get_addon_form_settings( $form_id )
        {
            $defaults = self::defaults();
            $current  = TZFD_Addon_Support::form_settings( self::KEY, $form_id, $defaults );
            $request  = TZFD_Addon_Support::form_request( self::KEY, $form_id );
            if ( null !== $request ) {
                if ( ! empty( $request['clear'] ) ) {
                    $request['password'] = '';
                } elseif ( ! isset( $request['password'] ) || '' === trim( (string) $request['password'] ) ) {
                    $request['password'] = $current['password']; // Blank keeps the current password.
                }
                TZFD_Addon_Support::save_form_settings( self::KEY, $form_id, $defaults, $request );
                $current = TZFD_Addon_Support::form_settings( self::KEY, $form_id, $defaults );
            }

            echo '<h3>' . esc_html( $this->name ) . '</h3>';
            echo '<input type="hidden" name="TZFD_' . esc_attr( self::KEY ) . '_id" value="' . esc_attr( (string) (int) $form_id ) . '" />';
            TZFD_Addon_Support::render_table( 'tzfd_' . self::KEY . '_', array(
                'password' => array( 'label' => 'Booking password', 'type' => 'password',
                                     'help'  => ( '' !== $current['password'] ? 'A password is set. ' : 'No password is set. ' ) . 'Customers must enter it on the form.' ),
                'clear'    => array( 'label' => 'Remove the password', 'type' => 'bool' ),
            ), array() );
            return '';
        } // end get_addon_form_settings

        /************************ HOOKS *****************************/

        /** Adds the password input just before the submit button of this calendar's form. */
        public function add_password_field( $html, $formid )
        {
            $settings = TZFD_Addon_Support::form_settings( self::KEY, (int) $formid, self::defaults() );
            if ( '' === $settings['password'] || ! is_string( $html ) ) { return $html; }

            $field = '<div class="tzfd-form-password" style="margin:8px 0;">'
                   . '<label for="' . esc_attr( self::FIELD_NAME . '_' . (int) $formid ) . '">' . esc_html__( 'Booking password', 'titan-zero-trades-front-desk' ) . '</label><br />'
                   . '<input type="password" autocomplete="off" required="required" id="' . esc_attr( self::FIELD_NAME . '_' . (int) $formid ) . '" name="' . esc_attr( self::FIELD_NAME ) . '" />'
                   . wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false, false )
                   . '</div>';

            if ( 1 === preg_match( '/<div id="tzfd_subbtn/', $html, $m, PREG_OFFSET_CAPTURE ) ) {
                $pos = $m[0][1];
                return substr( $html, 0, $pos ) . $field . substr( $html, $pos );
            }
            $pos = strripos( $html, '</form>' );
            if ( false === $pos ) { return $html; }
            return substr( $html, 0, $pos ) . $field . substr( $html, $pos );
        }

        /** Runs before insert. Refuses the booking when the password is wrong or missing. */
        public function check_submission( $tzfd_params )
        {
            if ( defined( 'TZFD_HOUR_BLOCK_TIMES_PROCESS' ) ) { return; }
            if ( is_admin() && current_user_can( 'edit_posts' ) ) { return; }
            if ( ! is_array( $tzfd_params ) || ! isset( $tzfd_params['formid'] ) ) { return; }

            $settings = TZFD_Addon_Support::form_settings( self::KEY, (int) $tzfd_params['formid'], self::defaults() );
            if ( '' === $settings['password'] ) { return; }

            // The nonce is checked before the password is read. A stale or missing nonce refuses the booking.
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ?? '' ) ), self::NONCE_ACTION ) ) {
                echo esc_html__( 'Your booking form has expired or is out of date. Please reload the page and try again.', 'titan-zero-trades-front-desk' );
                exit;
            }
            $given = ( isset( $_POST[ self::FIELD_NAME ] ) && is_string( $_POST[ self::FIELD_NAME ] ) )
                   ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NAME ] ) )
                   : '';
            if ( ! TZFD_Addon_Support::password_matches( $given, $settings['password'] ) ) {
                echo esc_html__( 'The booking password is not correct. Please check it and try again.', 'titan-zero-trades-front-desk' );
                exit;
            }
            if ( ! TZFD_Addon_Support::is_password_hash( $settings['password'] ) ) {
                TZFD_Addon_Support::upgrade_plain_password( self::KEY, (int) $tzfd_params['formid'], $settings['password'] );
            }
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $TZFD_Passwordaddon_obj = new TZFD_Passwordaddon();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $TZFD_Passwordaddon_obj->get_addon_id() ] = $TZFD_Passwordaddon_obj;
}
