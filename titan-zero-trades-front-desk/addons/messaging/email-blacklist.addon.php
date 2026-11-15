<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';

if( !class_exists( 'TZFD_EmailBlacklistaddon' ) )
{
    /**
     * Refuses public bookings made with listed email addresses or domains.
     * Entries are site-wide: one per line, a full address, a domain, or @domain.
     */
    class TZFD_EmailBlacklistaddon extends TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-EmailBlacklist";
		protected $name = "Email Blacklist";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const KEY = 'emailblacklist';

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
			$this->description = $this->tr_apply("The add-on refuses public bookings made with blacklisted email addresses or domains.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            add_action( 'tz_fd_process_data_before_insert', array( $this, 'check_submission' ), 5, 1 );
        } // End __construct

        /************************ SETTINGS *****************************/

        public static function defaults()
        {
            return array(
                'entries' => '',
                'message' => __( 'This email address cannot be used to make bookings. Please contact us if you believe this is a mistake.', 'titan-zero-trades-front-desk' ),
            );
        }

        /** Translates the built-in default when it is shown to a visitor. Stored values are never changed. */
        public static function visitor_message( $message )
        {
            if ( self::defaults()['message'] !== $message ) { return $message; }
            return __( 'This email address cannot be used to make bookings. Please contact us if you believe this is a mistake.', 'titan-zero-trades-front-desk' );
        }

        public function get_addon_settings()
        {
            $defaults = self::defaults();
            $request  = TZFD_Addon_Support::global_request( self::KEY );
            if ( null !== $request ) {
                TZFD_Addon_Support::save_global_settings( self::KEY, $defaults, array(
                    'entries' => sanitize_textarea_field( (string) ( $request['entries'] ?? '' ) ),
                    'message' => sanitize_text_field( (string) ( $request['message'] ?? '' ) ),
                ) );
            }
            $values = TZFD_Addon_Support::global_settings( self::KEY, $defaults );

            ob_start();
            echo '<div class="tzfd-addons-container"><h2>' . esc_html( $this->name ) . '</h2>';
            echo '<form method="post">';
            wp_nonce_field( 'tz_fd_global_' . self::KEY, '_tz_fd_nonce' );
            echo '<input type="hidden" name="tz_fd_save_' . esc_attr( self::KEY ) . '" value="1" />';
            TZFD_Addon_Support::render_table( 'tz_fd_global_' . self::KEY . '_', array(
                'entries' => array( 'label' => __( 'Blacklisted emails and domains', 'titan-zero-trades-front-desk' ), 'type' => 'textarea',
                                    'help'  => __( 'One per line: a full address (name@example.com), a domain (example.com, which also covers subdomains) or @example.com.', 'titan-zero-trades-front-desk' ) ),
                'message' => array( 'label' => __( 'Message shown to the customer', 'titan-zero-trades-front-desk' ), 'type' => 'text' ),
            ), $values );
            submit_button( __( 'Save settings', 'titan-zero-trades-front-desk' ), 'secondary', 'submit', false );
            echo '</form></div>';
            return ob_get_clean();
        }

        /************************ HOOKS *****************************/

        /** Runs before insert. Stops the submission when the customer's email is listed. */
        public function check_submission( $tz_params )
        {
            if ( defined( 'TZFD_HOUR_BLOCK_TIMES_PROCESS' ) ) { return; }
            if ( is_admin() && current_user_can( 'tz_fd_access' ) ) { return; } // Staff-entered bookings are trusted.

            global $tz_plugin;
            $field = is_object( $tz_plugin ) ? $tz_plugin->get_option( 'cu_user_email_field', 'email' ) : 'email';
            if ( ! is_array( $tz_params ) || ! isset( $tz_params[ $field ] ) || ! is_string( $tz_params[ $field ] ) ) { return; }

            $settings = TZFD_Addon_Support::global_settings( self::KEY, self::defaults() );
            $entries  = TZFD_Addon_Support::parse_entries( $settings['entries'] );
            if ( TZFD_Addon_Support::address_listed( $tz_params[ $field ], $entries ) ) {
                echo esc_html( self::visitor_message( $settings['message'] ) );
                exit;
            }
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $TZFD_EmailBlacklistaddon_obj = new TZFD_EmailBlacklistaddon();

	// Add addon object to the objects list
	global $tz_addons_objs_list;
	$tz_addons_objs_list[ $TZFD_EmailBlacklistaddon_obj->get_addon_id() ] = $TZFD_EmailBlacklistaddon_obj;
}
