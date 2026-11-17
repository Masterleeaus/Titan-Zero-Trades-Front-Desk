<?php
/** Explicit audited Front Desk add-on manifest (Pass 3).
 * Compatibility entries MUST remain until migration and dependent consumers are verified.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Addon_Loader {
    /** @return array<string,array<string,string>> */
    public static function manifest() {
        return array(
            'bookings/applimit.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'bookings/auto-cancel-pending-status.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'bookings/calendarless-interface.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'bookings/dashboard-box.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'bookings/icalexport.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'core/base.addon.php' => array( 'domain' => 'infrastructure', 'policy' => 'active' ),
            'core/cache.addon.php' => array( 'domain' => 'infrastructure', 'policy' => 'active' ),
            'core/logs.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'core/onboarding.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'core/password.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'core/vertical-packs.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'messaging/email-blacklist.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'messaging/followup.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'messaging/missed-call.addon.php' => array( 'domain' => 'calls', 'policy' => 'active' ),
            'messaging/reminder.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'messaging/sms-button.addon.php' => array( 'domain' => 'calls', 'policy' => 'active' ),
            'messaging/webhook.addon.php' => array( 'domain' => 'bookings', 'policy' => 'active' ),
            'messaging/whatsapp-button.addon.php' => array( 'domain' => 'calls', 'policy' => 'active' ),
        );
    }

    /** Deterministic, explicitly listed PHP files; unlisted files can never load. */
    public static function files( $addons_dir ) {
        $base = rtrim( (string) $addons_dir, '/\\' );
        $files = array();
        foreach ( self::manifest() as $relative => $entry ) {
            if ( ! in_array( $entry['policy'], array( 'active', 'compatibility' ), true ) ) {
                continue;
            }
            $path = $base . '/' . $relative;
            if ( is_file( $path ) ) {
                $files[] = $path;
            }
        }
        return $files;
    }

    /** Booking/call catalog excludes modules awaiting owner migration. */
    public static function catalog_files( $addons_dir ) {
        $base = rtrim( (string) $addons_dir, '/\\' );
        $files = array();
        foreach ( self::manifest() as $relative => $entry ) {
            if ( 'active' === $entry['policy'] && in_array( $entry['domain'], array( 'bookings', 'calls' ), true ) && is_file( $base . '/' . $relative ) ) {
                $files[] = $base . '/' . $relative;
            }
        }
        return $files;
    }
    /** Published add-on IDs; compatibility entries remain loadable but not configurable here. */
    public static function domain_ids() {
        return array(
            'tzfd-appLimit' => 'bookings',
            'tzfd-AutoCancelStatus' => 'bookings',
            'tzfd-SingleDaysSelection' => 'bookings',
            'tzfd-DashboardWidget' => 'bookings',
            'tzfd-iCalExport' => 'bookings',
            'tzfd-Password' => 'bookings',
            'tzfd-EmailBlacklist' => 'bookings',
            'tzfd-FollowUp' => 'bookings',
            'tzfd-Reminder' => 'bookings',
            'tzfd-MissedCall' => 'calls',
            'tzfd-Cache' => 'infrastructure',
            'tzfd-ActivityLogsWidget' => 'bookings',
            'tzfd-Onboarding' => 'bookings',
            'tzfd-VerticalPacks' => 'bookings',
            'tzfd-SMSButton' => 'calls',
            'tzfd-WebHook' => 'bookings',
            'tzfd-WhatsappButton' => 'calls',
        );
    }

    public static function compatibility_ids() {
        // Every former compatibility module is now switchable; none remain.
        return array();
    }

    /** Existing transitional selections must survive a catalog-only save. */
    public static function preserve_compatibility_selections( $existing, $selected ) {
        $existing = is_array( $existing ) ? $existing : array();
        $selected = is_array( $selected ) ? $selected : array();
        $allowed = array_keys( self::domain_ids() );
        $compatibility = self::compatibility_ids();
        $visible = array_values( array_intersect( $allowed, $selected ) );
        $retained = array_values( array_intersect( $compatibility, $existing ) );
        return array_values( array_unique( array_merge( $visible, $retained ) ) );
    }

}
