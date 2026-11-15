<?php
/**
 * Front Desk local infrastructure. Loads the plugin's own standalone classes; no other plugin is required.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Standalone_Runtime {
    private static $booted = false;
    public static function boot() {
        if ( self::$booted ) { return; }
        self::$booted = true;
        require_once __DIR__ . '/class-tzfd-local-business.php';
        require_once __DIR__ . '/class-tzfd-scoped-store.php';
        require_once __DIR__ . '/class-tzfd-local-customers.php';
        require_once __DIR__ . '/class-tzfd-local-audit.php';
        add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ), 2 );
        require_once __DIR__ . '/class-tzfd-local-services.php';
        require_once __DIR__ . '/class-tzfd-local-money.php';
        require_once __DIR__ . '/class-tzfd-legacy-compat.php';
        require_once __DIR__ . '/class-tzfd-local-brand.php';
    }
    /** Upgrade existing standalone sites without requiring plugin reactivation. */
    public static function maybe_upgrade() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        if ( version_compare( (string) get_option( 'tzfd_local_schema_version', '0' ), '3', '>=' ) ) { return; }
        self::install();
    }
    public static function install() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        TZFD_Local_Customers::install();
        TZFD_Local_Services::install();
        if ( ! TZFD_Local_Audit::install() ) { return; }
        update_option( 'tzfd_local_schema_version', '3', false );
    }
}
