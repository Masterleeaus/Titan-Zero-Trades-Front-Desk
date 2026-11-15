<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
require_once __DIR__ . '/class-tzfd-transaction-storage.php';
require_once __DIR__ . '/class-tzfd-local-audit.php';
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-workspace-customer-owner.php';

/**
 * TZ_FrontDesk: read-only API for the other Titan Zero Trades: Front Desk plugins.
 *
 * Every method returns plain arrays and never objects from Front Desk's own
 * classes. Methods return [] when the id is not valid, the customer is not
 * found, nothing matches, or the table is unavailable. Front Desk is then
 * treated as absent. Optional integrations should check class_exists( 'TZ_FrontDesk' ).
 *
 * Reads never write. The one write is set_booking_status(), which changes only
 * Front Desk's own booking rows, in the same transaction as its Front Desk audit record.
 * Customer records for native bookings are read through TZFD_Local_Customers.
 *
 * Public surface (the whole list): bookings_for_customer, booking_exists, booking_form (reads);
 * set_booking_status and default_paid_status (Invoicing). Invoices and payments are not here. They belong to Titan Zero Invoicing (19 Oct split),
 * which exposes its own read API. Front Desk does not read those tables.
 */
if ( ! class_exists( 'TZ_FrontDesk' ) ) {
final class TZ_FrontDesk {

    const LIMIT_BOOKINGS = 100;
    /** Maximum booking rows read per customer lookup (the CRM scan limit, now owned here). */
    const SCAN_LIMIT     = 10000;

    /**
     * Legacy bookings for a customer, newest date first. Matched by the
     * customer's stored booking link, or by contact email or phone.
     *
     * @param int $customer_id tz_customers.id
     * @return array<int, array{date:string, slot:string, service:string, cancelled:string}>
     */
    /** Current company from the standalone resolver (0 when none applies). */
    private static function company_id() {
        return class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
    }

    public static function bookings_for_customer( $customer_id ) {
        $customer_id = absint( $customer_id );
        if ( ! $customer_id ) { return array(); }
        require_once __DIR__ . '/class-tzfd-local-customers.php';
        $customer = TZFD_Local_Customers::find( $customer_id );
        $company = class_exists( 'TZFD_Scoped_Store', false ) ? (int) TZFD_Scoped_Store::company_id() : 0;
        if ( ! TZFD_Workspace_Customer_Owner::matches( $customer, $company ) ) { return array(); }

        $scan = self::scan_bookings( (object) $customer );
        if ( ! empty( $scan['failed'] ) || empty( $scan['rows'] ) ) { return array(); }

        $out = array();
        foreach ( array_slice( $scan['rows'], 0, self::LIMIT_BOOKINGS ) as $row ) {
            $out[] = array(
                'date'      => isset( $row[0] ) && is_scalar( $row[0] ) ? (string) $row[0] : '',
                'slot'      => isset( $row[1] ) && is_scalar( $row[1] ) ? (string) $row[1] : '',
                'service'   => isset( $row[2] ) && is_scalar( $row[2] ) ? (string) $row[2] : '',
                'cancelled' => isset( $row[3] ) && is_scalar( $row[3] ) ? (string) $row[3] : '',
            );
        }
        return $out;
    }

    /**
     * True when the booking exists in the current company. Read-only. False on a bad id, no company, not found, or a
     * database error (fail closed).
     *
     * @param int $booking_id tz_bookings.id
     * @return bool
     */
    public static function booking_exists( $booking_id ) {
        global $wpdb;
        $booking_id = absint( $booking_id );
        $company    = self::company_id();
        if ( ! $booking_id || ! $company ) { return false; }
        $found = $wpdb->get_var( $wpdb->prepare(
            'SELECT id FROM %i WHERE id = %d AND company_id = %d',
            $wpdb->prefix . TZFD_Tables::BOOKINGS, $booking_id, $company
        ) );
        return ! empty( $found );
    }

    /**
     * The form and appointment summary of one booking in the current company. Read-only. Returns [] when the
     * booking is not found in this company, the id or company is not valid, or the read fails.
     *
     * Shape: booking_id (int), formid (int), customer_id (int, 0 when unbound),
     * apps (list of array( date, slot, service, cancelled )). Contact fields are not returned.
     *
     * @param int $booking_id tz_bookings.id
     * @return array
     */
    public static function booking_form( $booking_id ) {
        global $wpdb;
        $booking_id = absint( $booking_id );
        $company    = self::company_id();
        if ( ! $booking_id || ! $company ) { return array(); }
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT id, formid, posted_data FROM %i WHERE id = %d AND company_id = %d',
            $wpdb->prefix . TZFD_Tables::BOOKINGS, $booking_id, $company
        ) );
        if ( ! $row || ! empty( $wpdb->last_error ) ) { return array(); }
        $data = TZFD_Stored_Data::decode_booking( $row->posted_data );
        $apps = array();
        if ( is_array( $data ) && ! empty( $data['apps'] ) && is_array( $data['apps'] ) ) {
            foreach ( $data['apps'] as $app ) {
                if ( ! is_array( $app ) ) { continue; }
                $apps[] = array(
                    'date'      => isset( $app['date'] ) && is_scalar( $app['date'] ) ? (string) $app['date'] : '',
                    'slot'      => isset( $app['slot'] ) && is_scalar( $app['slot'] ) ? (string) $app['slot'] : '',
                    'service'   => isset( $app['service'] ) && is_scalar( $app['service'] ) ? (string) $app['service'] : '',
                    'cancelled' => isset( $app['cancelled'] ) && is_scalar( $app['cancelled'] ) ? (string) $app['cancelled'] : '',
                );
            }
        }
        return array(
            'booking_id'  => (int) $row->id,
            'formid'      => (int) $row->formid,
            'customer_id' => is_array( $data ) ? absint( $data['customer_id'] ?? 0 ) : 0,
            'apps'        => $apps,
        );
    }

    /**
     * Authoritative, fail-closed booking/customer association for Workspace.
     * The booking_form() lookup is company-scoped and decodes the stored booking
     * payload. Never infer ownership from email, phone, contact or free text.
     * Bookings without a persisted customer_id remain deliberately unlinked.
     */
    public static function booking_belongs_to_customer( $booking_id, $company_id, $customer_id ) {
        $booking_id = absint( $booking_id );
        $company_id = absint( $company_id );
        $customer_id = absint( $customer_id );
        if ( ! $booking_id || ! $company_id || ! $customer_id ||
             ! class_exists( 'TZFD_Scoped_Store', false ) ||
             (int) TZFD_Scoped_Store::company_id() !== $company_id ) { return false; }
        $booking = self::booking_form( $booking_id );
        return is_array( $booking ) &&
            (int) ( $booking['booking_id'] ?? 0 ) === $booking_id &&
            (int) ( $booking['customer_id'] ?? 0 ) === $customer_id;
    }

    /**
     * Sets the status of one booking in the current company. Called by Titan Zero Invoicing
     * after a full payment (status 'Approved'). Owner write: the booking row and its native audit record
     * row commit or roll back together.
     *
     * Returns WP_Error with an HTTP-style code in data['status'] on refusal:
     * 400 bad id or status, 403 no company, 404 booking not found in this company,
     * 409 booking has no apps, 422 status not in the Front Desk status list (and 'paid', always),
     * 500 database or audit failure, 503 Front Desk or local audit not loaded.
     * Background callers (cron, webhook) need company context: Invoicing sets the tz_current_company_id filter.
     *
     * @param int    $booking_id tz_bookings.id
     * @param string $status     One of the Front Desk status values (see get_status_list()). 'Approved' or '' for the approved state.
     * @return array|WP_Error    array( 'booking_id', 'status', 'changed' ) on success.
     */
    public static function set_booking_status( $booking_id, $status ) {
        global $wpdb;

        $booking_id = absint( $booking_id );
        if ( ! $booking_id || ! is_string( $status ) || strlen( $status ) > 250
            || 1 !== preg_match( '//u', $status ) || preg_match( '/[\x00-\x1F\x7F]/', $status ) ) {
            return new WP_Error( 'tzfd_bad_input', __( 'A valid booking id and status are needed.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        $status = trim( $status );
        // 'paid' is payment status and belongs to Titan Zero Invoicing. Front Desk never stores it, so refuse it
        // here by name, before the status list is read, whatever that list contains.
        if ( 'paid' === strtolower( $status ) ) {
            return new WP_Error( 'tzfd_bad_status', __( 'That booking status is not allowed.', 'titan-zero-trades-front-desk' ), array( 'status' => 422 ) );
        }
        // The UI shows "Approved" but Front Desk stores that state as an empty string (TZFD_Lifecycle_Rules).
        // Both '' and 'Approved' mean that state. The label is what callers get back.
        $stored = in_array( $status, array( '', 'Approved' ), true ) ? '' : $status;
        $label  = ( '' === $stored ) ? 'Approved' : $stored;

        $company = self::company_id();
        if ( ! $company ) {
            return new WP_Error( 'tzfd_no_company', __( 'No company is selected.', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
        }

        $plugin = isset( $GLOBALS['tz_plugin'] ) ? $GLOBALS['tz_plugin'] : null;
        if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_status_list' )
            || ! class_exists( 'TZFD_Lifecycle_Rules' ) || ! class_exists( 'TZFD_Local_Audit' ) || ! method_exists( 'TZFD_Local_Audit', 'insert' ) ) {
            return new WP_Error( 'tzfd_unavailable', __( 'Front Desk or the audit log is not available.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        if ( ! TZFD_Lifecycle_Rules::is_allowed_admin_status( $stored, $plugin->get_status_list() ) ) {
            return new WP_Error( 'tzfd_bad_status', __( 'That booking status is not allowed.', 'titan-zero-trades-front-desk' ), array( 'status' => 422 ) );
        }

        $table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        $actor = (int) get_current_user_id();
        $level = $actor ? 'user' : 'system';

        // Front Desk owns bookings; Front Desk owns its local audit journal. A MyISAM table breaks atomic status/audit updates.
        if ( ! TZFD_Transaction_Storage::require_innodb( array( $table, TZFD_Local_Audit::table_name() ) ) ) {
            return new WP_Error( 'tzfd_transaction_storage_required', __( 'Booking and audit tables must use InnoDB.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        try {
            $started = $wpdb->query( 'START TRANSACTION' );
        } catch ( Throwable $error ) {
            $started = false;
        }
        if ( false === $started ) {
            return new WP_Error( 'tzfd_db', __( 'The booking could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }

        try {
            $row = $wpdb->get_row( $wpdb->prepare(
                'SELECT id, posted_data FROM %i WHERE id = %d AND company_id = %d FOR UPDATE',
                $table, $booking_id, $company
            ) );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_db', __( 'The booking could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }
        if ( ! empty( $wpdb->last_error ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_db', __( 'The booking could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }
        if ( ! $row ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_not_found', __( 'Booking not found.', 'titan-zero-trades-front-desk' ), array( 'status' => 404 ) );
        }

        $data = TZFD_Stored_Data::decode_booking( $row->posted_data );
        $apps_valid = is_array( $data ) && ! empty( $data['apps'] ) && is_array( $data['apps'] );
        if ( $apps_valid ) {
            foreach ( $data['apps'] as $app ) {
                if ( ! is_array( $app ) ) { $apps_valid = false; break; }
            }
        }
        if ( ! $apps_valid ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_no_apps', __( 'The booking has no appointments to update.', 'titan-zero-trades-front-desk' ), array( 'status' => 409 ) );
        }

        $changed = false;
        foreach ( $data['apps'] as $k => $app ) {
            if ( ! isset( $app['cancelled'] ) || $app['cancelled'] !== $stored ) {
                $changed = true;
            }
            $data['apps'][ $k ]['cancelled']         = $stored;
            $data['app_status_' . ( (int) $k + 1 ) ] = $stored;
        }
        if ( ! $changed ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'booking_id' => $booking_id, 'status' => $label, 'changed' => false );
        }

        try {
            $update = $wpdb->update(
                $table,
                array( 'posted_data' => serialize( $data ) ),
                array( 'id' => $booking_id, 'company_id' => $company ),
                array( '%s' ),
                array( '%d', '%d' )
            );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_db', __( 'The booking could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }
        if ( false === $update ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_db', __( 'The booking could not be updated.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }

        try {
            $audit = TZFD_Local_Audit::insert( $company, $actor, $level, array(
                'plugin'    => 'titan-zero-front-desk',
                'action'    => 'booking.status',
                'entity'    => 'booking',
                'entity_id' => $booking_id,
                'summary'   => __( 'Booking status set to ', 'titan-zero-trades-front-desk' ) . ( '' === $stored ? __( 'Approved', 'titan-zero-trades-front-desk' ) : $stored ),
            ) );
        } catch ( Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_audit', __( 'The booking change could not be audited and was rolled back.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }
        if ( true !== $audit ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'tzfd_audit', __( 'The change could not be recorded, so it was not saved.', 'titan-zero-trades-front-desk' ), array( 'status' => 500 ) );
        }

        // A failed COMMIT response does not prove the server rolled back: the connection may
        // have committed before its acknowledgement was lost. Never claim the write failed.
        try {
            $commit_result = $wpdb->query( 'COMMIT' );
        } catch ( Throwable $error ) {
            $commit_result = false;
        }
        if ( false === $commit_result ) {
            return new WP_Error( 'tzfd_commit_outcome_unknown', __( 'Booking update outcome is uncertain. Refresh the booking before attempting another change.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }

        do_action( 'tz_fd_update_status', $booking_id, $stored );
        do_action( 'titan_zero_front_desk_booking_status_persisted', $booking_id, $stored, '-1' );

        return array( 'booking_id' => $booking_id, 'status' => $label, 'changed' => true );
    }

    /**
     * The status Invoicing should set after a full payment: the Front Desk value chosen in
     * "Default status of paid bookings" (booking settings). Returns 'Approved' when unset or not a Front Desk
     * status, and null when Front Desk is not loaded.
     *
     * @return string|null
     */
    public static function default_paid_status() {
        $plugin = isset( $GLOBALS['tz_plugin'] ) ? $GLOBALS['tz_plugin'] : null;
        if ( ! is_object( $plugin ) || ! method_exists( $plugin, 'get_option' ) ) { return null; }
        $value = trim( (string) $plugin->get_option( 'defaultpaidstatus', '' ) );
        if ( '' === $value || 'Approved' === $value || 'paid' === strtolower( $value ) ) { return 'Approved'; }
        // Anything that is not a Front Desk status (for example a value saved from a crafted form) falls back to Approved.
        if ( ! method_exists( $plugin, 'get_status_list' ) || ! TZFD_Lifecycle_Rules::is_allowed_admin_status( $value, $plugin->get_status_list() ) ) {
            return 'Approved';
        }
        return $value;
    }

    /**
     * Bounded, read-only scan of booking rows for one customer. Ported from the CRM screen (19 Oct split),
     * which no longer exists. Private: bookings_for_customer() is the only customer read this class offers.
     *
     * @param object $c Customer with id, email and phone.
     * @return array{rows: array, scanned: int, failed: bool}
     */
    private static function scan_bookings( $c ) {
        global $wpdb;
        $email = isset( $c->email ) && is_scalar( $c->email ) ? (string) $c->email : '';
        $phone = isset( $c->phone ) && is_scalar( $c->phone ) ? (string) $c->phone : '';
        $needles = array_filter( array( strtolower( trim( $email ) ), preg_replace( '/\D+/', '', $phone ) ) );
        $found = array();
        $last_id = PHP_INT_MAX;
        $scanned = 0;
        $query_failed = false;
        if ( empty( $needles ) && empty( $c->id ) ) {
            return array( 'rows' => $found, 'scanned' => 0, 'failed' => false );
        }
        // Scan in bounded pages rather than silently excluding all but the
        // newest 500 booking records. Stop after 100 matching appointments.
        do {
            $messages = $wpdb->get_results( $wpdb->prepare(
                'SELECT id, posted_data FROM %i WHERE company_id = %d AND id < %d ORDER BY id DESC LIMIT 250',
                $wpdb->prefix . TZFD_Tables::BOOKINGS, self::company_id(), $last_id
            ) );
            if ( ! is_array( $messages ) || ! empty( $wpdb->last_error ) ) {
                $query_failed = true;
                break;
            }
            if ( ! $messages ) {
                break;
            }
            foreach ( $messages as $m ) {
                ++$scanned;
                $last_id = (int) $m->id;
                $data = TZFD_Stored_Data::decode_booking( $m->posted_data );
                if ( ! is_array( $data ) || empty( $data['apps'] ) || ! is_array( $data['apps'] ) ) {
                    continue;
                }
                $stored_customer_id = absint( $data['customer_id'] ?? 0 );
                if ( $stored_customer_id ) {
                    if ( $stored_customer_id !== (int) $c->id ) {
                        continue;
                    }
                } elseif ( empty( $needles ) || ! self::booking_contact_matches( $data, $needles ) ) {
                    continue;
                }
                foreach ( $data['apps'] as $app ) {
                    if ( ! is_array( $app ) ) { continue; }
                    $found[] = array(
                        isset( $app['date'] ) ? (string) $app['date'] : '',
                        isset( $app['slot'] ) ? (string) $app['slot'] : '',
                        isset( $app['service'] ) ? (string) $app['service'] : '',
                        isset( $app['cancelled'] ) ? (string) $app['cancelled'] : '',
                    );
                }
            }
        } while ( count( $messages ) === 250 && count( $found ) < 100 && $scanned < self::SCAN_LIMIT );
        return array( 'rows' => $found, 'scanned' => $scanned, 'failed' => $query_failed );
    }

    /** Match legacy bookings only against contact fields, not notes, services or arbitrary metadata. */
    private static function booking_contact_matches( $data, $needles ) {
        $contact_keys = array( 'email', 'phone', 'customer_email', 'customer_phone', 'telephone', 'tel' );
        foreach ( $contact_keys as $key ) {
            if ( isset( $data[ $key ] ) && self::contains_any( $data[ $key ], $needles ) ) {
                return true;
            }
        }
        foreach ( array( 'fields', 'customer', 'contact' ) as $container ) {
            if ( empty( $data[ $container ] ) || ! is_array( $data[ $container ] ) ) {
                continue;
            }
            foreach ( $contact_keys as $key ) {
                if ( isset( $data[ $container ][ $key ] ) && self::contains_any( $data[ $container ][ $key ], $needles ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    /** True when any string value in the decoded booking equals one of the needles (case-insensitive, phone digits only). */
    private static function contains_any( $value, $needles ) {
        if ( is_array( $value ) ) { return false; } // Contact fields must be scalar; never scan nested metadata.
        if ( ! is_scalar( $value ) ) {
            return false;
        }
        $text = strtolower( trim( (string) $value ) );
        $digits = preg_replace( '/\D+/', '', $text );
        foreach ( $needles as $needle ) {
            if ( $text === $needle || ( strlen( $needle ) >= 6 && $digits === $needle ) ) {
                return true;
            }
        }
        return false;
    }
}
}
