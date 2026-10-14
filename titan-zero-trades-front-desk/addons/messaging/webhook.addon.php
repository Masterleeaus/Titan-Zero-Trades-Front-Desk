<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';

if( !class_exists( 'TZFD_WebHook' ) )
{
    /**
     * Sends signed booking events to one HTTPS endpoint. Delivery itself lives in
     * the core webhook module (includes/class-tzfd-webhooks.php); this
     * add-on supplies the destination and secret from the site settings.
     *
     * Events: booking.created and booking.status_update_attempted, schema version 1.
     * Each request is signed with HMAC-SHA256 in X-TZFD-Signature.
     */
    class TZFD_WebHook extends TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
		protected $addonID = "tzfd-WebHook";
		protected $name = "WebHook";
		protected $description;
        public $category = ' Add-ons included in this plugin version';
        public $help = '';

        const KEY = 'webhook';

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
			$this->description = $this->tr_apply("The add-on sends signed booking events to an HTTPS endpoint you control.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            add_filter( 'titan_zero_front_desk_webhook_config', array( $this, 'provide_config' ), 10, 1 );
        } // End __construct

        /************************ SETTINGS *****************************/

        public static function defaults()
        {
            return array(
                'url'    => '',
                'secret' => '',
            );
        }

        public function get_addon_settings()
        {
            $defaults = self::defaults();
            $notice   = '';
            $request  = TZFD_Addon_Support::global_request( self::KEY );
            if ( null !== $request ) {
                $current = TZFD_Addon_Support::global_settings( self::KEY, $defaults );
                $url     = isset( $request['url'] ) ? sanitize_text_field( (string) $request['url'] ) : '';
                $secret  = isset( $request['secret'] ) ? sanitize_text_field( (string) $request['secret'] ) : '';
                if ( '' === $secret ) { $secret = $current['secret']; } // Blank keeps the stored secret.

                if ( null === TZFD_Addon_Support::webhook_settings( $url, $secret ) ) {
                    $notice = '<div class="notice notice-error inline"><p>' . esc_html__( 'Enter an https:// address and a secret of at least 32 characters. Nothing was saved.', 'titan-zero-trades-front-desk' ) . '</p></div>';
                } else {
                    TZFD_Addon_Support::save_global_settings( self::KEY, $defaults, array( 'url' => esc_url_raw( $url ), 'secret' => $secret ) );
                    $notice = '<div class="notice notice-success inline"><p>' . esc_html__( 'Webhook settings saved.', 'titan-zero-trades-front-desk' ) . '</p></div>';
                }
            }

            $stored = TZFD_Addon_Support::global_settings( self::KEY, $defaults );
            $active = null !== TZFD_Addon_Support::webhook_settings( $stored['url'], $stored['secret'] );

            ob_start();
            echo '<div class="tzfd-addons-container"><h2>' . esc_html( $this->name ) . '</h2>';
            echo wp_kses_post( $notice );
            echo '<p>' . esc_html( $active ? __( 'Status: sending events.', 'titan-zero-trades-front-desk' ) : __( 'Status: not sending. Enter a destination and secret below.', 'titan-zero-trades-front-desk' ) ) . '</p>';
            echo '<form method="post">';
            wp_nonce_field( 'tzfd_global_' . self::KEY, '_tzfd_nonce' );
            echo '<input type="hidden" name="tzfd_save_' . esc_attr( self::KEY ) . '" value="1" />';
            TZFD_Addon_Support::render_table( 'tzfd_global_' . self::KEY . '_', array(
                'url'    => array( 'label' => 'Destination URL', 'type' => 'text',
                                   'help'  => 'Must be https:// with no username, password or fragment.' ),
                'secret' => array( 'label' => 'Signing secret', 'type' => 'password',
                                   'help'  => 'At least 32 characters. Stored in the site database; leave blank to keep the current secret. Verify the signature on your server with HMAC-SHA256 over the raw body.' ),
            ), array( 'url' => $stored['url'] ) );
            submit_button( __( 'Save settings', 'titan-zero-trades-front-desk' ), 'secondary', 'submit', false );
            echo '</form></div>';
            return ob_get_clean();
        }

        /************************ HOOKS *****************************/

        /** Supplies the destination to the core webhook sender. Empty when not configured. */
        public function provide_config( $settings )
        {
            $stored = TZFD_Addon_Support::global_settings( self::KEY, self::defaults() );
            $config = TZFD_Addon_Support::webhook_settings( $stored['url'], $stored['secret'] );
            return ( null === $config ) ? array() : $config;
        }

        /************************ ADDON OBJECT *****************************/

    } // End Class

    // Main add-on code
    $tzfd_webhook_obj = new TZFD_WebHook();

	// Add addon object to the objects list
	global $tzfd_addons_objs_list;
	$tzfd_addons_objs_list[ $tzfd_webhook_obj->get_addon_id() ] = $tzfd_webhook_obj;
}
