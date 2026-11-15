<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Record store shared by the Titan Zero Trades: Front Desk add-ons.
 *
 * One table holds add-on records (payments, outbound messages, redemptions,
 * sync jobs). A record is identified by add-on, kind and reference, and that
 * triple is unique, so a repeated webhook or a retried job cannot create a
 * second record. Payloads are stored as JSON and never unserialized.
 */
final class TZFD_Addon_Records {
    const TABLE          = TZFD_Tables::ADDON_RECORDS;
    const SCHEMA_OPTION  = 'titan_zero_front_desk_addon_records_schema';
    const SCHEMA_VERSION = '2';
    const MAX_REF_LENGTH = 190;
    const MAX_PAYLOAD    = 65536;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** No implicit admin or cron fallback: the caller must have a verified company. */
    private static function company() {
        if ( ! class_exists( 'TZFD_Scoped_Store' ) || ! method_exists( 'TZFD_Scoped_Store', 'company_id' ) ) { return 0; }
        $company = TZFD_Scoped_Store::company_id();
        return is_int( $company ) && $company > 0 ? $company : 0;
    }

    /** A booking may only be associated with the company that owns its calendar. */
    private static function owns_booking( $company, $booking_id ) {
        if ( 0 === $booking_id ) { return true; }
        global $wpdb;
        $booking_table = TZFD_Tables::name( TZFD_Tables::BOOKINGS );
        if ( is_wp_error( $booking_table ) ) { return false; }
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE id = %d AND company_id = %d',
            $booking_table, $booking_id, $company
        ) ) === 1;
    }

    public static function ensure_schema() {
        global $wpdb;
        if ( get_option( self::SCHEMA_OPTION ) === self::SCHEMA_VERSION && self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table() ) ) ) { return; }
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE " . self::table() . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL DEFAULT 0,
            addon varchar(64) NOT NULL,
            kind varchar(64) NOT NULL,
            ref varchar(190) NOT NULL,
            booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payload longtext,
            created datetime DEFAULT CURRENT_TIMESTAMP,
            updated datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY company_addon_kind_ref (company_id, addon, kind, ref),
            KEY company_booking (company_id, booking_id),
            KEY booking (booking_id),
            KEY addon_kind (addon, kind)
        ) {$charset};" );
        // dbDelta does not remove the legacy site-wide unique key. Keeping it
        // would prevent two companies from using the same booking reference.
        $table = self::table();
        $indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
        $names = array();
        foreach ( (array) $indexes as $index ) {
            if ( is_array( $index ) && isset( $index['Key_name'] ) ) { $names[ $index['Key_name'] ] = true; }
        }
        if ( isset( $names['addon_kind_ref'] ) && isset( $names['company_addon_kind_ref'] ) ) {
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP INDEX addon_kind_ref', $table ) );
            $indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A );
            $names = array();
            foreach ( (array) $indexes as $index ) {
                if ( is_array( $index ) && isset( $index['Key_name'] ) ) { $names[ $index['Key_name'] ] = true; }
            }
        }
        // Legacy booking-linked records can be assigned using their authoritative
        // booking owner. Unlinked legacy opt-outs are ambiguous and remain at 0;
        // never silently expose them to an arbitrary tenant.
        $bookings = TZFD_Tables::name( TZFD_Tables::BOOKINGS );
        // On a fresh install the bookings table may not exist yet; skip the join then.
        if ( ! is_wp_error( $bookings ) && $bookings === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings ) ) ) {
            $wpdb->query( $wpdb->prepare(
                'UPDATE %i a INNER JOIN %i b ON a.booking_id = b.id SET a.company_id = b.company_id WHERE a.company_id = 0 AND a.booking_id > 0 AND b.company_id > 0',
                $table, $bookings
            ) );
        }
        $columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'company_id' ), ARRAY_A );
        if ( self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )
            && ! empty( $columns ) && isset( $names['company_addon_kind_ref'] ) && ! isset( $names['addon_kind_ref'] ) ) {
            update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
        }
    }

    /**
     * Consent guard for messages. Legacy opt-outs with company_id 0 cannot be
     * attributed safely after migration, so they still apply across tenants.
     * A missing schema, missing company context, or query error blocks sending.
     */
    public static function contact_is_blocked( $ref ) {
        global $wpdb;
        $company = self::company();
        if ( ! $company || ! is_string( $ref ) || '' === $ref || strlen( $ref ) > self::MAX_REF_LENGTH ) {
            return true;
        }
        $table = self::table();
        if ( ! is_string( $table ) || '' === $table ) { return true; }
        // Treat any query failure as blocked. In particular, don't allow sends
        // while the legacy table's company_id migration remains incomplete.
        $count = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE (company_id = %d OR company_id = 0) AND addon = %s AND kind = %s AND ref = %s',
            $table, $company, 'outbox', 'optout', $ref
        ) );
        return null === $count || ! empty( $wpdb->last_error ) || (int) $count > 0;
    }

    /**
     * Enumerate only tenant IDs persisted on queued outbound records. These are
     * routing identifiers, never a replacement for a user's Workspace session.
     */
    public static function queued_message_companies( $limit = 100 ) {
        global $wpdb;
        $limit = max( 1, min( 100, (int) $limit ) );
        $rows = $wpdb->get_col( $wpdb->prepare(
            'SELECT DISTINCT company_id FROM %i WHERE company_id > 0 AND addon = %s AND kind = %s ORDER BY company_id ASC LIMIT %d',
            self::table(), 'outbox', 'message', $limit
        ) );
        if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) { return array(); }
        return array_values( array_unique( array_filter( array_map( 'absint', $rows ) ) ) );
    }

    /** A strict, explicit company query for background delivery only. */
    public static function queued_messages_for_company( $company, $limit = 100 ) {
        global $wpdb;
        if ( ! is_int( $company ) || $company < 1 ) { return array(); }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i WHERE company_id = %d AND addon = %s AND kind = %s ORDER BY id ASC LIMIT %d',
            self::table(), $company, 'outbox', 'message', max( 1, min( 200, (int) $limit ) )
        ) );
        if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) { return array(); }
        return array_map( array( __CLASS__, 'hydrate' ), $rows );
    }

    /** Consent fail-closed for explicit background tenant, including legacy opt-outs. */
    public static function contact_is_blocked_for_company( $company, $ref ) {
        global $wpdb;
        if ( ! is_int( $company ) || $company < 1 || ! is_string( $ref ) || '' === $ref || strlen( $ref ) > self::MAX_REF_LENGTH ) { return true; }
        $wpdb->last_error = '';
        $count = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE (company_id = %d OR company_id = 0) AND addon = %s AND kind = %s AND ref = %s',
            self::table(), $company, 'outbox', 'optout', $ref
        ) );
        return null === $count || ! empty( $wpdb->last_error ) || (int) $count > 0;
    }

    /**
     * Resolve the actual booking owner independently of the queue's company_id.
     * An imported/corrupted queue row must never authorize a vendor side effect.
     */
    public static function booking_belongs_to_company( $company, $booking_id ) {
        global $wpdb;
        if ( ! is_int( $company ) || $company < 1 || ! is_int( $booking_id ) || $booking_id < 1 ) { return false; }
        $table = TZFD_Tables::name( TZFD_Tables::BOOKINGS );
        if ( is_wp_error( $table ) ) { return false; }
        $wpdb->last_error = '';
        $count = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE id = %d AND company_id = %d',
            $table, $booking_id, $company
        ) );
        return '' === (string) $wpdb->last_error && null !== $count && 1 === (int) $count;
    }

    /** Enumerate only persisted tenant IDs for a specific synchronization add-on. */
    public static function queued_sync_companies( $addon, $limit = 100 ) {
        global $wpdb;
        if ( ! is_string( $addon ) || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $addon ) || ! is_int( $limit ) || $limit < 1 ) { return array(); }
        $wpdb->last_error = '';
        $rows = $wpdb->get_col( $wpdb->prepare(
            'SELECT DISTINCT company_id FROM %i WHERE company_id > 0 AND addon = %s AND kind = %s ORDER BY company_id ASC LIMIT %d',
            self::table(), $addon, 'sync', min( 100, $limit )
        ) );
        if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) { return array(); }
        $companies = array();
        foreach ( $rows as $id ) {
            if ( ! is_string( $id ) && ! is_int( $id ) ) { continue; }
            $company = filter_var( $id, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
            if ( false !== $company ) { $companies[] = (int) $company; }
        }
        return array_values( array_unique( $companies ) );
    }

    /** Read queued sync jobs strictly for one persisted tenant and add-on. */
    public static function queued_sync_for_company( $company, $addon, $limit = 100 ) {
        global $wpdb;
        if ( ! is_int( $company ) || $company < 1 || ! is_string( $addon )
            || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $addon ) || ! is_int( $limit ) || $limit < 1 ) { return array(); }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i WHERE company_id = %d AND addon = %s AND kind = %s ORDER BY id ASC LIMIT %d',
            self::table(), $company, $addon, 'sync', min( 200, $limit )
        ) );
        if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) { return array(); }
        return array_map( array( __CLASS__, 'hydrate' ), $rows );
    }

    /** Atomic transitions for sync jobs; never overwrite a stale or foreign claim. */
    public static function transition_sync_for_company( $company, $addon, array $row, array $expected, array $next ) {
        global $wpdb;
        if ( ! is_int( $company ) || $company < 1 || ! is_string( $addon )
            || ! preg_match( '/^[a-z0-9_-]{1,64}$/', $addon )
            || ! is_int( $row['company_id'] ?? null ) || $row['company_id'] !== $company
            || ! is_int( $row['id'] ?? null ) || $row['id'] < 1
            || ! is_string( $row['ref'] ?? null ) || '' === $row['ref']
            || 'booking:' !== substr( $row['ref'], 0, 8 )
            || ! is_int( $row['booking_id'] ?? null ) || $row['booking_id'] < 1
            || ! in_array( array( $expected['status'] ?? '', $next['status'] ?? '' ), array(
                array( 'queued', 'processing' ), array( 'processing', 'done' ),
                array( 'processing', 'review' )
            ), true ) ) { return false; }
        $old = self::encode( $expected );
        $new = self::encode( $next );
        if ( null === $old || null === $new ) { return false; }
        $wpdb->last_error = '';
        $changed = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET payload = %s, updated = %s WHERE id = %d AND company_id = %d AND addon = %s AND kind = %s AND ref = %s AND booking_id = %d AND BINARY payload = BINARY %s',
            self::table(), $new, current_time( 'mysql' ), $row['id'], $company, $addon, 'sync', $row['ref'], $row['booking_id'], $old
        ) );
        return 1 === (int) $changed && '' === (string) $wpdb->last_error;
    }

    /** Compare-and-swap claim: only one worker can change a queued record. */
    public static function claim_message_for_company( $company, array $row, array $claimed ) {
        global $wpdb;
        if ( ! is_int( $company ) || $company < 1 || (int) ( $row['company_id'] ?? 0 ) !== $company
            || (int) ( $row['id'] ?? 0 ) < 1 || ! is_array( $row['payload'] ?? null )
            || 'queued' !== ( $row['payload']['status'] ?? '' ) || 'processing' !== ( $claimed['status'] ?? '' ) ) { return false; }
        $old = self::encode( $row['payload'] );
        $new = self::encode( $claimed );
        if ( null === $old || null === $new ) { return false; }
        $result = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET payload = %s, updated = %s WHERE id = %d AND company_id = %d AND addon = %s AND kind = %s AND ref = %s AND BINARY payload = BINARY %s',
            self::table(), $new, current_time( 'mysql' ), (int) $row['id'], $company, 'outbox', 'message', $row['ref'], $old
        ) );
        return 1 === (int) $result;
    }

    /**
     * Compare-and-swap after an attempted external delivery. A stale worker
     * must not overwrite a later manual review or reconciliation decision.
     * Expected payload is the exact previously claimed `processing` record.
     */
    public static function finish_claimed_message_for_company( $company, array $row, array $expected, array $final ) {
        global $wpdb;
        if ( ! is_int( $company ) || $company < 1 || (int) ( $row['company_id'] ?? 0 ) !== $company
            || (int) ( $row['id'] ?? 0 ) < 1 || ! is_string( $row['ref'] ?? null ) || '' === $row['ref']
            || 'processing' !== ( $expected['status'] ?? '' )
            || ! in_array( $final['status'] ?? null, array( 'sent', 'failed', 'review' ), true ) ) { return false; }
        $previous = self::encode( $expected );
        $next = self::encode( $final );
        if ( null === $previous || null === $next ) { return false; }
        $wpdb->last_error = '';
        $updated = $wpdb->query( $wpdb->prepare(
            'UPDATE %i SET payload = %s, updated = %s WHERE id = %d AND company_id = %d AND addon = %s AND kind = %s AND ref = %s AND BINARY payload = BINARY %s',
            self::table(), $next, current_time( 'mysql' ), (int) $row['id'], $company, 'outbox', 'message', $row['ref'], $previous
        ) );
        return 1 === (int) $updated && '' === (string) $wpdb->last_error;
    }

    /**
     * Read-only inventory for stalled claims. No automatic retry: transport
     * may have delivered the email before a worker crashed.
     */
    public static function stalled_message_claims( $company, $before, $limit = 100 ) {
        global $wpdb;
        if ( ! is_int( $company ) || $company < 1 || ! is_string( $before )
            || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $before ) ) { return array(); }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i WHERE company_id = %d AND addon = %s AND kind = %s AND updated IS NOT NULL AND updated < %s ORDER BY updated ASC LIMIT %d',
            self::table(), $company, 'outbox', 'message', $before, max( 1, min( 100, (int) $limit ) )
        ) );
        if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) { return array(); }
        return array_values( array_filter( array_map( array( __CLASS__, 'hydrate' ), $rows ), static function ( $row ) {
            return 'processing' === ( $row['payload']['status'] ?? '' );
        } ) );
    }

    /** Update a row only when its record ID, tenant, kind, and reference agree. */
    public static function update_message_for_company( $company, array $row, array $payload ) {
        global $wpdb;
        if ( ! is_int( $company ) || $company < 1 || (int) ( $row['company_id'] ?? 0 ) !== $company
            || (int) ( $row['id'] ?? 0 ) < 1 || ! is_string( $row['ref'] ?? null ) || '' === $row['ref'] ) { return false; }
        $encoded = self::encode( $payload );
        if ( null === $encoded ) { return false; }
        $updated = $wpdb->update(
            self::table(), array( 'payload' => $encoded, 'updated' => current_time( 'mysql' ) ),
            array( 'id' => $row['id'], 'company_id' => $company, 'addon' => 'outbox', 'kind' => 'message', 'ref' => $row['ref'] ),
            array( '%s', '%s' ), array( '%d', '%d', '%s', '%s', '%s' )
        );
        return 1 === (int) $updated;
    }

    /** Explicitly expose active ownership to background consumers. */
    public static function has_company_context() {
        return self::company() > 0;
    }

    public static function clean_ref( $ref ) {
        $ref = is_scalar( $ref ) ? sanitize_text_field( (string) $ref ) : '';
        return substr( $ref, 0, self::MAX_REF_LENGTH );
    }

    /** Stores a new record. Returns false if it exists already or cannot be stored. */
    public static function insert( $addon, $kind, $ref, $booking_id, array $payload ) {
        global $wpdb;
        $ref     = self::clean_ref( $ref );
        $encoded = self::encode( $payload );
        $company = self::company();
        if ( ! $company || '' === $ref || null === $encoded || ! is_int( $booking_id ) && ! ( is_string( $booking_id ) && ctype_digit( $booking_id ) ) ) { return false; }
        $booking_id = (int) $booking_id;
        if ( $booking_id < 0 || ! self::owns_booking( $company, $booking_id ) ) { return false; }

        $suppress = $wpdb->suppress_errors( true ); // a duplicate key is an expected outcome here
        $result   = $wpdb->insert(
            self::table(),
            array( 'company_id' => $company, 'addon' => sanitize_key( $addon ), 'kind' => sanitize_key( $kind ), 'ref' => $ref,
                   'booking_id' => max( 0, (int) $booking_id ), 'payload' => $encoded, 'created' => current_time( 'mysql' ) ),
            array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
        );
        $wpdb->suppress_errors( $suppress );
        return false !== $result && 1 === (int) $result;
    }

    public static function get( $addon, $kind, $ref ) {
        global $wpdb;
        $company = self::company();
        if ( ! $company ) { return null; }
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM %i WHERE company_id = %d AND addon = %s AND kind = %s AND ref = %s',
            self::table(), $company, sanitize_key( $addon ), sanitize_key( $kind ), self::clean_ref( $ref )
        ) );
        return $row ? self::hydrate( $row ) : null;
    }

    /** Replaces the payload of an existing record and can attach it to a booking. */
    public static function update( $addon, $kind, $ref, array $payload, $booking_id = null ) {
        global $wpdb;
        $encoded = self::encode( $payload );
        $company = self::company();
        if ( ! $company || null === $encoded ) { return false; }
        $data = array( 'payload' => $encoded, 'updated' => current_time( 'mysql' ) );
        $formats = array( '%s', '%s' );
        if ( null !== $booking_id ) {
            if ( ! is_int( $booking_id ) && ! ( is_string( $booking_id ) && ctype_digit( $booking_id ) ) ) { return false; }
            if ( ! self::owns_booking( $company, (int) $booking_id ) ) { return false; }
            $data['booking_id'] = (int) $booking_id;
            $formats[] = '%d';
        }
        $result = $wpdb->update(
            self::table(),
            $data,
            array( 'company_id' => $company, 'addon' => sanitize_key( $addon ), 'kind' => sanitize_key( $kind ), 'ref' => self::clean_ref( $ref ) ),
            $formats,
            array( '%d', '%s', '%s', '%s' )
        );
        return false !== $result && (int) $result > 0;
    }

    /** Removes a record by its unique key. */
    public static function delete( $addon, $kind, $ref ) {
        global $wpdb;
        $company = self::company();
        if ( ! $company ) { return false; }
        $deleted = $wpdb->delete(
            self::table(),
            array( 'company_id' => $company, 'addon' => sanitize_key( $addon ), 'kind' => sanitize_key( $kind ), 'ref' => self::clean_ref( $ref ) ),
            array( '%d', '%s', '%s', '%s' )
        );
        return 1 === (int) $deleted;
    }

    /** Records of one kind linked to a booking, oldest first. */
    public static function for_booking( $addon, $kind, $booking_id ) {
        global $wpdb;
        $company = self::company();
        if ( ! $company ) { return array(); }
        $tz_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE company_id = %d AND addon = %s AND kind = %s AND booking_id = %d ORDER BY id ASC LIMIT 500",
            self::table(), $company, sanitize_key( $addon ), sanitize_key( $kind ), (int) $booking_id
        ) );
        return array_map( array( __CLASS__, 'hydrate' ), (array) $tz_rows );
    }

    /** Records of one kind, oldest first, limited to $limit. Used by queues. */
    public static function list_kind( $addon, $kind, $limit = 50 ) {
        global $wpdb;
        $company = self::company();
        if ( ! $company ) { return array(); }
        $tz_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE company_id = %d AND addon = %s AND kind = %s ORDER BY id ASC LIMIT %d",
            self::table(), $company, sanitize_key( $addon ), sanitize_key( $kind ), max( 1, min( 500, (int) $limit ) )
        ) );
        return array_map( array( __CLASS__, 'hydrate' ), (array) $tz_rows );
    }

    /** Records of one kind whose reference starts with $prefix, oldest first. */
    public static function list_prefix( $addon, $kind, $prefix, $limit = 500 ) {
        global $wpdb;
        $company = self::company();
        if ( ! $company ) { return array(); }
        $tz_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE company_id = %d AND addon = %s AND kind = %s AND ref LIKE %s ORDER BY id ASC LIMIT %d",
            self::table(), $company, sanitize_key( $addon ), sanitize_key( $kind ), self::like( $prefix ) . '%', max( 1, min( 5000, (int) $limit ) )
        ) );
        return array_map( array( __CLASS__, 'hydrate' ), (array) $tz_rows );
    }

    /** Number of records of one kind whose reference starts with $prefix. */
    public static function count_prefix( $addon, $kind, $prefix ) {
        global $wpdb;
        $company = self::company();
        if ( ! $company ) { return false; }
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i WHERE company_id = %d AND addon = %s AND kind = %s AND ref LIKE %s",
            self::table(), $company, sanitize_key( $addon ), sanitize_key( $kind ), self::like( $prefix ) . '%'
        ) );
        if ( null === $count && ! empty( $wpdb->last_error ) ) { return false; }
        return (int) $count;
    }

    /** Count prefix-matched records only when a leading JSON string field is an exact value. */
    public static function count_prefix_payload_string( $addon, $kind, $prefix, $field, $value ) {
        global $wpdb;
        $company = self::company();
        if ( ! $company ) { return false; }
        $field   = sanitize_key( $field );
        $encoded = wp_json_encode( (string) $value );
        if ( '' === $field || ! is_string( $encoded ) || ! method_exists( $wpdb, 'esc_like' ) ) { return false; }

        // Add-on records are compact JSON. The comma after the encoded value prevents
        // a value such as SAVE from matching SAVE#VIP in an older redemption payload.
        $needle = '"' . $field . '":' . $encoded . ',';
        $count  = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE company_id = %d AND addon = %s AND kind = %s AND ref LIKE %s AND BINARY payload LIKE BINARY %s',
            self::table(), $company, sanitize_key( $addon ), sanitize_key( $kind ), self::like( $prefix ) . '%', '%' . $wpdb->esc_like( $needle ) . '%'
        ) );
        if ( null === $count && ! empty( $wpdb->last_error ) ) { return false; }
        return (int) $count;
    }

    /** Escapes LIKE wildcards so a prefix matches literally. */
    private static function like( $text ) {
        return addcslashes( self::clean_ref( $text ), '_%\\' );
    }

    public static function encode( array $payload ) {
        $tz_json = wp_json_encode( $payload );
        if ( ! is_string( $tz_json ) || strlen( $tz_json ) > self::MAX_PAYLOAD ) { return null; }
        return $tz_json;
    }

    public static function hydrate( $row ) {
        $row     = (object) $row;
        $raw     = $row->payload ?? '';
        $payload = is_string( $raw ) && strlen( $raw ) <= self::MAX_PAYLOAD ? json_decode( $raw, true, 16 ) : null;
        return array(
            'id'         => (int) ( $row->id ?? 0 ),
            'company_id' => (int) ( $row->company_id ?? 0 ),
            'addon'      => (string) ( $row->addon ?? '' ),
            'kind'       => (string) ( $row->kind ?? '' ),
            'ref'        => (string) ( $row->ref ?? '' ),
            'booking_id' => (int) ( $row->booking_id ?? 0 ),
            'payload'    => is_array( $payload ) ? $payload : array(),
            'created'    => (string) ( $row->created ?? '' ),
        );
    }
}
