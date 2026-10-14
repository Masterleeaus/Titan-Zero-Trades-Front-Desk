<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once dirname( __DIR__ ) . '/core/base.addon.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-support.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-addon-records.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-tzfd-extension-core.php';

if( !class_exists( 'TZFD_Coupons' ) )
{
    /**
     * Discount codes per calendar. Each line of the settings is CODE,percent|fixed,value,expires,max_uses
     * (expires as YYYY-MM-DD or blank; max_uses 0 means unlimited). The customer enters the code in the
     * tzfd_coupon field; a redemption is recorded for each booking, so a limit cannot be passed by retrying.
     * The discount is applied to the invoice amount through the tzfd_invoice_amount filter (priority 20).
     */
    class TZFD_Coupons extends TZFD_BaseAddon
    {
        /************* ADDON SYSTEM - ATTRIBUTES AND METHODS *************/
        protected $addonID = "tzfd-Coupons";
        protected $name = "Coupons";
        protected $description;
        public $category = ' Discounts and coupons (included in this plugin version)';
        public $help = '';

        const KEY        = 'coupons';
        const FIELD      = 'tzfd_coupon';
        const REDEEM     = 'redemption';
        const MAX_CODE_LENGTH = 64; // Longest discount code accepted, in bytes.

        /************************ CONSTRUCT *****************************/
        function __construct()
        {
            $this->description = $this->tr_apply("Discount codes for each calendar, with expiry dates and usage limits.", 'titan-zero-trades-front-desk' );
            if ( ! $this->addon_is_active() ) return;

            TZFD_Addon_Records::ensure_schema();
            add_filter( 'tzfd_the_form', array( $this, 'add_field' ), 97, 2 );
            add_action( 'tzfd_process_data_before_insert', array( $this, 'check_code' ), 8, 1 );
            add_action( 'tzfd_process_data', array( $this, 'redeem' ), 15, 1 );
            add_filter( 'tzfd_invoice_amount', array( $this, 'invoice_amount' ), 20, 2 );
        } // End __construct

        /************************ SETTINGS *****************************/

        public function get_addon_settings()
        {
            return TZFD_Addon_Support::global_settings_block( self::KEY, self::defaults(), array(
                'codes' => array( 'label' => __( 'Default discount codes', 'titan-zero-trades-front-desk' ), 'type' => 'textarea', 'help' => __( 'One per line: CODE,percent or fixed,value,expires (YYYY-MM-DD or blank),max uses (0 = unlimited). Example: SPRING10,percent,10,2030-12-31,0', 'titan-zero-trades-front-desk' ) ),
            ), __( 'Default for new calendars. Each calendar can change these in its own settings.', 'titan-zero-trades-front-desk' ) );
        }

        public static function defaults()
        {
            return array( 'codes' => '' );
        }

        public function get_addon_form_settings( $form_id )
        {
            TZFD_Extension_Core::form_settings_block( $this->name, self::KEY, $form_id, self::defaults(), array(
                'codes' => array( 'label' => 'Discount codes', 'type' => 'textarea',
                    'help'  => 'One per line: CODE,percent|fixed,value,expires (YYYY-MM-DD or blank),max uses (0 = unlimited). Example: SPRING10,percent,10,2030-12-31,0' ),
            ) );
            return '';
        }

        /** Parses the settings into code => rule. Invalid lines are skipped. */
        public static function parse_codes( $text )
        {
            $rules = array();
            foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
                $p = array_map( 'trim', explode( ',', $line ) );
                if ( count( $p ) < 3 || '' === $p[0] || ! in_array( $p[1], array( 'percent', 'fixed' ), true ) ) { continue; }
                $code = strtoupper( sanitize_text_field( $p[0] ) );
                if ( '' === $code || strlen( $code ) > self::MAX_CODE_LENGTH ) { continue; }
                $value = floatval( $p[2] );
                if ( $value <= 0 || ( 'percent' === $p[1] && $value > 100 ) ) { continue; }
                $expires = sanitize_text_field( $p[3] ?? '' );
                $rules[ $code ] = array(
                    'type'     => $p[1],
                    'value'    => $value,
                    'expires'  => self::is_iso_date( $expires ) ? $expires : '',
                    'max_uses' => absint( $p[4] ?? 0 ),
                );
            }
            return $rules;
        }

        /** True only for a real calendar date written YYYY-MM-DD. */
        private static function is_iso_date( $value )
        {
            if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $value, $m ) ) { return false; }
            return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
        }

        /**
         * Pure discount calculation. Returns valid, discount, final and a reason when refused.
         * $used is the number of earlier redemptions of the code.
         */
        public static function discount_for( array $rules, $code, $amount, $today, $used )
        {
            $code   = strtoupper( trim( (string) $code ) );
            $amount = max( 0, (float) $amount );
            if ( '' === $code || ! isset( $rules[ $code ] ) ) { return array( 'valid' => false, 'discount' => 0.0, 'final' => $amount, 'reason' => 'unknown' ); }
            $rule = $rules[ $code ];
            if ( '' !== $rule['expires'] && $today > $rule['expires'] ) { return array( 'valid' => false, 'discount' => 0.0, 'final' => $amount, 'reason' => 'expired' ); }
            if ( $rule['max_uses'] > 0 && $used >= $rule['max_uses'] ) { return array( 'valid' => false, 'discount' => 0.0, 'final' => $amount, 'reason' => 'used_up' ); }
            $discount = self::discount_amount( $rule, $amount );
            return array( 'valid' => true, 'discount' => $discount, 'final' => round( $amount - $discount, 2 ), 'reason' => '' );
        }

        /** The discount one rule takes off an amount. Never more than the amount, never negative. */
        public static function discount_amount( array $rule, $amount )
        {
            $amount   = max( 0, (float) $amount );
            $discount = ( 'percent' === $rule['type'] ) ? round( $amount * (float) $rule['value'] / 100, 2 ) : min( (float) $rule['value'], $amount );
            return round( min( $discount, $amount ), 2 );
        }

        /**
         * The coupon code posted with a booking, upper-case and length-capped. The booking form nonce (the same
         * tzfd_actions_bookingform nonce the form carries in anonce) is verified here, in the same call that reads
         * the field, so the code is never read from an unverified request. The main booking handler already refuses
         * a bad nonce before any hook runs; this is a second check. A failed nonce gives an empty code.
         */
        private static function posted_code()
        {
            $nonce = sanitize_text_field( wp_unslash( $_POST['anonce'] ?? '' ) );
            if ( ! wp_verify_nonce( $nonce, 'tzfd_actions_bookingform' ) ) { return ''; }
            // Capped at one byte over the longest valid code, so a longer entry is refused as unknown and never reaches a query.
            return substr( strtoupper( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ?? '' ) ) ), 0, self::MAX_CODE_LENGTH + 1 );
        }

        /************************ HOOKS *****************************/

        public function add_field( $html, $formid )
        {
            $rules = self::parse_codes( TZFD_Addon_Support::form_settings( self::KEY, (int) $formid, self::defaults() )['codes'] );
            if ( ! $rules ) { return $html; }
            $id    = self::FIELD . '_' . (int) $formid;
            $field = '<div class="tzfd-form-coupon" style="margin:8px 0;"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Discount code', 'titan-zero-trades-front-desk' ) . '</label><br />'
                   . '<input type="text" autocomplete="off" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::FIELD ) . '" /></div>';
            return TZFD_Extension_Core::insert_before_submit( $html, $field );
        }

        /** Refuses a booking whose code is not valid. An empty code is allowed. */
        public function check_code( $params )
        {
            if ( ! is_array( $params ) || ! isset( $params['formid'] ) ) return;
            $code = self::posted_code();
            if ( '' === $code ) return;

            $rules = self::parse_codes( TZFD_Addon_Support::form_settings( self::KEY, (int) $params['formid'], self::defaults() )['codes'] );
            $used  = TZFD_Addon_Records::count_prefix( self::KEY, self::REDEEM, strtoupper( $code ) . '#' );
            $check = self::discount_for( $rules, $code, 1, wp_date( 'Y-m-d' ), $used );
            if ( ! $check['valid'] ) {
                echo esc_html__( 'The discount code is not valid or has expired.', 'titan-zero-trades-front-desk' );
                exit;
            }
        }

        /**
         * Applies the redeemed code's discount to the invoice amount. Runs after the base amount
         * is set (priority 20). The code was already validated and counted at redemption, so it is
         * not checked again here.
         */
        public function invoice_amount( $amount, $params )
        {
            $amount = (float) $amount;
            if ( $amount <= 0 || ! is_array( $params ) ) return $amount;
            if ( empty( $params['itemnumber'] ) ) {
                // Before the booking exists: the code was posted with the form and already checked by check_code().
                $code = self::posted_code();
                if ( '' === $code ) return $amount;
                $rules = self::parse_codes( TZFD_Addon_Support::form_settings( self::KEY, (int) ( $params['formid'] ?? 0 ), self::defaults() )['codes'] );
                $used  = TZFD_Addon_Records::count_prefix( self::KEY, self::REDEEM, $code . '#' );
                $check = self::discount_for( $rules, $code, $amount, wp_date( 'Y-m-d' ), $used );
                return $check['valid'] ? (float) $check['final'] : $amount;
            }
            $found = TZFD_Addon_Records::for_booking( self::KEY, self::REDEEM, (int) $params['itemnumber'] );
            if ( ! $found ) return $amount;
            $payload = $found[0]['payload'];
            $rules   = self::parse_codes( TZFD_Addon_Support::form_settings( self::KEY, (int) ( $payload['formid'] ?? 0 ), self::defaults() )['codes'] );
            $code    = strtoupper( (string) ( $payload['code'] ?? '' ) );
            if ( ! isset( $rules[ $code ] ) ) return $amount;
            return round( $amount - self::discount_amount( $rules[ $code ], $amount ), 2 );
        }

        /** Records the redemption once the booking exists. */
        public function redeem( $params )
        {
            if ( ! is_array( $params ) || empty( $params['itemnumber'] ) ) return;
            // Writes a redemption only for the booking created in this same request, and only with a verified booking form nonce.
            $code = self::posted_code();
            if ( '' === $code ) return;
            TZFD_Addon_Records::insert( self::KEY, self::REDEEM, $code . '#' . (int) $params['itemnumber'], (int) $params['itemnumber'], array(
                'code' => $code, 'formid' => (int) ( $params['formid'] ?? 0 ), 'redeemed' => current_time( 'mysql' ),
            ) );
        }

        /************************ ADDON OBJECT *****************************/
    } // End Class

    // Main add-on code
    $TZFD_Coupons_obj = new TZFD_Coupons();

    global $tzfd_addons_objs_list;
    $tzfd_addons_objs_list[ $TZFD_Coupons_obj->get_addon_id() ] = $TZFD_Coupons_obj;
}
