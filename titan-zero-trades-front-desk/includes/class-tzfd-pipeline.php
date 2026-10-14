<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-pipeline-rules.php';
require_once __DIR__ . '/class-tzfd-stored-data.php';
require_once __DIR__ . '/class-tzfd-capacity-lock.php';
require_once __DIR__ . '/agents/class-tzfd-orchestrator.php';

/**
 * Booking pipeline for bookings created by the assistant and by channel agents.
 *
 * This class holds the state and the calendar writes. The work is done by agents, run by the orchestrator:
 *   booking-intake (worker)     writes the booking as Pending and marks it processing
 *   booking-check (specialist)  runs the compliance checks; pass marks it processed
 *   slot-check (specialist)     confirms the slot is still free under the capacity lock
 *   approver (specialist)       approves, only when automatic approval is on
 * Staff approve or reject from the Pipeline screen. The pipeline manager holds the policy (automatic approval)
 * and can reject escalated bookings. The supervisor runs hourly to retry or escalate bookings left behind.
 *
 * Every move is written to tzfd_pipeline_events, so the event log doubles as telemetry.
 * Calendar status is only changed here through approve() and reject(), or by the supervisor's escalation,
 * which never changes it.
 *
 * Hooks:
 *   filter tzfd_ai_create_booking        creates a booking (AI assistant)
 *   filter tzfd_pipeline_source          label for where the booking came from, e.g. "pro:whatsapp"
 *   filter tzfd_pipeline_autonomous      true to approve automatically once the slot check passes
 *   filter tzfd_pipeline_slot_capacity   places per slot (default 1)
 *   filter tzfd_pipeline_duration        slot length in minutes (default 60)
 *   filter tzfd_pipeline_fulfilment_reasons  extra slot-check reasons (for workers and resources)
 *   action tzfd_pipeline_moved           after every state change: booking_id, from, to, stage, reason
 */
final class TZFD_Pipeline {

    const TABLE_STATE  = 'tzfd_pipeline';
    const TABLE_EVENTS = 'tzfd_pipeline_events';
    const MESSAGES     = 'tzfd_messages';
    const OPTION_DB    = 'tzfd_pipeline_db_version';
    const DB_VERSION   = '1';
    const CRON         = 'tzfd_pipeline_supervisor';
    const ACTION_MOVED = 'tzfd_pipeline_moved';
    const MAX_BATCH    = 50;
    const SCAN_LIMIT   = 5000;

    public static function register() {
        add_action( 'init', array( __CLASS__, 'maybe_install' ) );
        add_action( self::CRON, array( __CLASS__, 'supervise' ) );
        add_filter( 'tzfd_ai_create_booking', array( __CLASS__, 'ai_create_booking' ), 10, 2 );
    }

    /* ---------- Schema ---------- */

    public static function maybe_install() {
        if ( get_option( self::OPTION_DB ) !== self::DB_VERSION ) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $p  = $wpdb->prefix;
            $cc = $wpdb->get_charset_collate();
            dbDelta( "CREATE TABLE {$p}" . self::TABLE_STATE . " (
                booking_id BIGINT(20) UNSIGNED NOT NULL,
                formid BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                state VARCHAR(20) NOT NULL DEFAULT 'processing',
                reason VARCHAR(500) NOT NULL DEFAULT '',
                source VARCHAR(60) NOT NULL DEFAULT '',
                attempts SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
                created DATETIME NOT NULL,
                updated DATETIME NOT NULL,
                PRIMARY KEY  (booking_id),
                KEY state_updated (state, updated)
            ) $cc;" );
            dbDelta( "CREATE TABLE {$p}" . self::TABLE_EVENTS . " (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                booking_id BIGINT(20) UNSIGNED NOT NULL,
                stage VARCHAR(20) NOT NULL DEFAULT '',
                from_state VARCHAR(20) NOT NULL DEFAULT '',
                to_state VARCHAR(20) NOT NULL DEFAULT '',
                actor VARCHAR(60) NOT NULL DEFAULT '',
                reason VARCHAR(500) NOT NULL DEFAULT '',
                created DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY booking_id (booking_id),
                KEY created (created)
            ) $cc;" );
            update_option( self::OPTION_DB, self::DB_VERSION, false );
        }
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + 600, 'hourly', self::CRON );
        }
    }

    /* ---------- Entry points ---------- */

    /** Filter callback for the AI assistant's booking hook. Returns the array the assistant expects. */
    public static function ai_create_booking( $result, $args ) {
        if ( null !== $result ) { return $result; }
        if ( ! is_array( $args ) || empty( $args['formid'] ) || ! isset( $args['draft'] ) || ! is_array( $args['draft'] ) ) {
            return $result;
        }
        $source = substr( sanitize_text_field( (string) apply_filters( 'tzfd_pipeline_source', 'ai-assistant', $args ) ), 0, 60 );
        return self::submit( (int) $args['formid'], $args['draft'], $source );
    }

    /**
     * Entry point for a new booking. The orchestrator runs intake, then the checks.
     * Returns ok, booking_id, state and status ('confirmed' or 'pending_confirmation'), or ok false and an error code.
     * error codes: invalid_form, invalid_slot, invalid_duration, slot_taken, busy, save_failed.
     */
    public static function submit( $formid, array $draft, $source = 'ai-assistant' ) {
        return TZFD_Orchestrator::submit( $formid, $draft, $source );
    }

    /**
     * Booking agent's intake: writes the booking to the calendar as Pending and marks it processing.
     * Returns ok and booking_id, or ok false and an error code. Called by the booking-intake worker only.
     */
    public static function intake( $formid, array $draft, $source = 'ai-assistant' ) {
        global $wpdb;
        $formid = (int) $formid;
        if ( $formid < 1 ) { return self::fail( 'invalid_form' ); }

        $parts = self::slot_parts( $draft );
        if ( null === $parts ) { return self::fail( 'invalid_slot' ); }

        $duration = (int) apply_filters( 'tzfd_pipeline_duration', TZFD_Pipeline_Rules::DEFAULT_DURATION, $draft );
        $slot     = TZFD_Pipeline_Rules::slot_string( $parts['start'], $duration );
        if ( '' === $slot ) { return self::fail( 'invalid_duration' ); }
        $date  = $parts['date'];
        $now   = current_time( 'mysql' );
        $f     = self::customer_fields( $draft );
        $lock  = TZFD_Capacity_Lock::acquire( $wpdb, $formid );
        if ( false === $lock ) { return self::fail( 'busy' ); }

        $capacity = self::capacity( $formid, $date, $slot );
        $taken    = self::occupied_count( $formid, $date, $slot, 0 );
        if ( ! TZFD_Pipeline_Rules::fulfilment( array( 'occupied' => $taken, 'capacity' => $capacity ) )['ok'] ) {
            TZFD_Capacity_Lock::release( $wpdb, $lock );
            return self::fail( 'slot_taken' );
        }

        $data = array_merge( $f, array(
            'apps'         => array( array(
                'date'         => $date,
                'slot'         => $slot,
                'field'        => '',
                'service'      => $f['service_type'],
                'cancelled'    => 'Pending',
                'cancelreason' => '',
                'quantity'     => 1,
                'serviceindex' => 0,
            ) ),
            'source'       => $source,
            'app_status_1' => 'Pending',
        ) );

        $lines = array();
        foreach ( $f as $key => $value ) {
            if ( '' !== $value ) { $lines[] = ucwords( str_replace( '_', ' ', $key ) ) . ': ' . $value; }
        }
        $lines[] = 'Slot: ' . $date . ' ' . $slot;
        $lines[] = 'Source: ' . $source;

        $saved = $wpdb->insert( $wpdb->prefix . self::MESSAGES, array(
            'formid'      => $formid,
            'time'        => $now,
            'ipaddr'      => '',
            'notifyto'    => $f['customer_email'],
            'posted_data' => serialize( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The calendar stores bookings this way.
            'data'        => implode( "\n", $lines ),
            'whoadded'    => '0',
        ) );
        if ( ! $saved ) {
            TZFD_Capacity_Lock::release( $wpdb, $lock );
            return self::fail( 'save_failed' );
        }
        $id = (int) $wpdb->insert_id;

        $tracked = $wpdb->insert( $wpdb->prefix . self::TABLE_STATE, array(
            'booking_id' => $id,
            'formid'     => $formid,
            'state'      => TZFD_Pipeline_Rules::PROCESSING,
            'reason'     => '',
            'source'     => $source,
            'attempts'   => 0,
            'created'    => $now,
            'updated'    => $now,
        ) );
        TZFD_Capacity_Lock::release( $wpdb, $lock );
        if ( ! $tracked ) {
            $wpdb->delete( $wpdb->prefix . self::MESSAGES, array( 'id' => $id ) );
            return self::fail( 'save_failed' );
        }
        self::log( $id, 'intake', '', TZFD_Pipeline_Rules::PROCESSING, $source, 'booking received' );

        return array( 'ok' => true, 'booking_id' => $id, 'state' => TZFD_Pipeline_Rules::PROCESSING );
    }

    /* ---------- Staff and supervisor actions ---------- */

    /**
     * Staff approval. Checks the slot is still free, then confirms the booking in the calendar.
     * The automatic approver calls this too, with actor "specialist:approver".
     */
    public static function approve( $id, $actor = 'staff', $reason = 'approved by staff' ) {
        global $wpdb;
        $id  = (int) $id;
        $row = self::row( $id );
        if ( ! $row ) { return self::fail( 'not_found' ); }
        if ( ! TZFD_Pipeline_Rules::can_move( $row->state, TZFD_Pipeline_Rules::APPROVED ) ) { return self::fail( 'bad_transition' ); }
        $facts = self::details( $id );
        if ( null === $facts ) { return self::fail( 'unreadable' ); }

        $lock = TZFD_Capacity_Lock::acquire( $wpdb, $facts['formid'] );
        if ( false === $lock ) { return self::fail( 'busy' ); }
        $check = TZFD_Pipeline_Rules::fulfilment( array(
            'occupied' => self::occupied_count( $facts['formid'], $facts['date'], $facts['slot'], $id ),
            'capacity' => self::capacity( $facts['formid'], $facts['date'], $facts['slot'] ),
        ) );
        if ( ! $check['ok'] ) {
            TZFD_Capacity_Lock::release( $wpdb, $lock );
            return self::fail( 'slot_taken' );
        }
        $changed = self::set_calendar_status( $id, '', '' );
        $stage   = 0 === strpos( (string) $actor, 'specialist:' ) ? 'specialist' : 'staff';
        $moved   = $changed && self::move( $id, TZFD_Pipeline_Rules::APPROVED, $stage, $reason, $actor );
        if ( $changed && ! $moved ) {
            self::set_calendar_status( $id, 'Pending', '' );
        }
        TZFD_Capacity_Lock::release( $wpdb, $lock );
        return $moved ? array( 'ok' => true, 'state' => TZFD_Pipeline_Rules::APPROVED ) : self::fail( $changed ? 'bad_transition' : 'save_failed' );
    }

    /** Staff rejection. Marks the calendar entry Rejected. */
    public static function reject( $id, $actor = 'staff', $reason = 'rejected by staff' ) {
        global $wpdb;
        $id  = (int) $id;
        $row = self::row( $id );
        if ( ! $row ) { return self::fail( 'not_found' ); }
        if ( ! TZFD_Pipeline_Rules::can_move( $row->state, TZFD_Pipeline_Rules::REJECTED ) ) { return self::fail( 'bad_transition' ); }
        $lock = TZFD_Capacity_Lock::acquire( $wpdb, (int) $row->formid );
        if ( false === $lock ) { return self::fail( 'busy' ); }
        $changed = self::set_calendar_status( $id, 'Rejected', $reason );
        $moved   = $changed && self::move( $id, TZFD_Pipeline_Rules::REJECTED, 'staff', $reason, $actor );
        TZFD_Capacity_Lock::release( $wpdb, $lock );
        return $moved ? array( 'ok' => true, 'state' => TZFD_Pipeline_Rules::REJECTED ) : self::fail( $changed ? 'bad_transition' : 'save_failed' );
    }

    /**
     * Staff asks for another check. Escalated bookings go back to processing and run the checks again.
     * Processed bookings run the slot check again.
     */
    public static function recheck( $id, $actor = 'staff' ) {
        $id  = (int) $id;
        $row = self::row( $id );
        if ( ! $row ) { return self::fail( 'not_found' ); }
        if ( TZFD_Pipeline_Rules::ESCALATED === $row->state ) {
            if ( ! self::move( $id, TZFD_Pipeline_Rules::PROCESSING, 'staff', 'sent back for another check', $actor ) ) { return self::fail( 'bad_transition' ); }
            return array( 'ok' => true, 'state' => TZFD_Orchestrator::advance( $id ) );
        }
        if ( TZFD_Pipeline_Rules::PROCESSED === $row->state ) {
            return array( 'ok' => true, 'state' => TZFD_Orchestrator::advance( $id ) );
        }
        return self::fail( 'bad_transition' );
    }

    /**
     * Supervisor (hourly). Escalates bookings stuck in processing too long. Retries bookings left in
     * processing, and in processed when automatic approval is on. Never approves on its own unless automatic approval is on.
     */
    public static function supervise() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_STATE;

        $stuck = $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_id FROM %i WHERE state = %s AND updated <= %s ORDER BY updated ASC LIMIT %d",
            $table, TZFD_Pipeline_Rules::PROCESSING, self::ago( TZFD_Pipeline_Rules::STUCK_AFTER ), self::MAX_BATCH
        ) );
        foreach ( (array) $stuck as $row ) {
            self::move( (int) $row->booking_id, TZFD_Pipeline_Rules::ESCALATED, 'supervisor', 'timed out in processing' );
        }

        $retry = $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_id, state, source FROM %i WHERE state IN (%s, %s) AND updated <= %s ORDER BY updated ASC LIMIT %d",
            $table, TZFD_Pipeline_Rules::PROCESSING, TZFD_Pipeline_Rules::PROCESSED, self::ago( TZFD_Pipeline_Rules::RETRY_AFTER ), self::MAX_BATCH
        ) );
        foreach ( (array) $retry as $row ) {
            $id = (int) $row->booking_id;
            if ( TZFD_Pipeline_Rules::PROCESSING === $row->state || self::autonomous( $id, (string) $row->source ) ) {
                TZFD_Orchestrator::advance( $id );
            }
        }
    }

    /* ---------- Reading ---------- */

    /** Telemetry for the last N days: counts per state, and average minutes from booking to approval. */
    public static function stats( $days = 30 ) {
        global $wpdb;
        $days  = max( 1, min( 365, (int) $days ) );
        $table = $wpdb->prefix . self::TABLE_STATE;
        $since = self::ago( $days * DAY_IN_SECONDS );

        $counts = $wpdb->get_results( $wpdb->prepare( "SELECT state, COUNT(*) AS n FROM %i WHERE created >= %s GROUP BY state", $table, $since ) );
        $avg    = $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created, updated)) FROM %i WHERE state = %s AND created >= %s",
            $table, TZFD_Pipeline_Rules::APPROVED, $since
        ) );
        $out = array( 'days' => $days, 'counts' => array(), 'avg_minutes_to_approve' => null );
        foreach ( (array) $counts as $c ) { $out['counts'][ (string) $c->state ] = (int) $c->n; }
        if ( null !== $avg ) { $out['avg_minutes_to_approve'] = round( (float) $avg, 1 ); }
        return $out;
    }

    /** Booking facts for the checks and the staff screen, or null if the row is not a readable pipeline booking. */
    public static function details( $id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT formid, notifyto, posted_data FROM %i WHERE id = %d', $wpdb->prefix . self::MESSAGES, (int) $id ) );
        if ( ! $row ) { return null; }
        $data = TZFD_Stored_Data::decode_booking( (string) $row->posted_data );
        if ( ! is_array( $data ) || empty( $data['apps'][0] ) || ! is_array( $data['apps'][0] ) ) { return null; }
        $app   = $data['apps'][0];
        $slot  = (string) ( $app['slot'] ?? '' );
        $split = TZFD_Pipeline_Rules::split_slot( $slot );
        if ( null === $split ) { return null; }
        $duration = TZFD_Pipeline_Rules::duration_minutes( $split['start'], $split['end'] );
        return array(
            'formid'   => (int) $row->formid,
            'date'     => (string) ( $app['date'] ?? '' ),
            'slot'     => $slot,
            'start'    => $split['start'],
            'duration' => null === $duration ? 0 : $duration,
            'service'  => (string) ( $app['service'] ?? '' ),
            'name'     => (string) ( $data['customer_name'] ?? '' ),
            'email'    => (string) ( $data['customer_email'] ?? $row->notifyto ),
            'phone'    => (string) ( $data['customer_phone'] ?? '' ),
        );
    }

    public static function state_of( $id ) {
        $row = self::row( (int) $id );
        return $row ? (string) $row->state : '';
    }

    public static function row( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id = %d', $wpdb->prefix . self::TABLE_STATE, (int) $id ) );
    }

    /** Recent events for one booking, oldest first. */
    public static function events( $id, $limit = 50 ) {
        global $wpdb;
        return (array) $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM %i WHERE booking_id = %d ORDER BY id ASC LIMIT %d',
            $wpdb->prefix . self::TABLE_EVENTS, (int) $id, max( 1, min( 500, (int) $limit ) )
        ) );
    }

    /* ---------- Used by the agents ---------- */

    /**
     * Moves a booking to a new state. The update only applies if the state is still the one we read,
     * so two requests cannot both move the same booking.
     */
    public static function move( $id, $to, $stage, $reason = '', $actor = '' ) {
        global $wpdb;
        $id  = (int) $id;
        $row = self::row( $id );
        if ( ! $row ) { return false; }
        $from = (string) $row->state;
        if ( ! TZFD_Pipeline_Rules::can_move( $from, $to ) ) { return false; }

        $attempts = (int) $row->attempts;
        if ( TZFD_Pipeline_Rules::PROCESSING === $to && TZFD_Pipeline_Rules::ESCALATED === $from ) { $attempts++; }
        $done = $wpdb->update(
            $wpdb->prefix . self::TABLE_STATE,
            array(
                'state'    => $to,
                'reason'   => substr( sanitize_text_field( (string) $reason ), 0, 500 ),
                'attempts' => $attempts,
                'updated'  => current_time( 'mysql' ),
            ),
            array( 'booking_id' => $id, 'state' => $from )
        );
        if ( 1 !== (int) $done ) { return false; }

        self::log( $id, $stage, $from, $to, '' !== $actor ? $actor : $stage, $reason );
        do_action( 'tzfd_pipeline_moved', $id, $from, $to, $stage, (string) $reason );
        return true;
    }

    /** Writes a note to the event log without changing the state. */
    public static function note( $id, $stage, $text ) {
        $state = self::state_of( $id );
        self::log( (int) $id, $stage, $state, $state, $stage, $text );
    }

    /** Active bookings in the same slot, not counting $exclude_id. Pending and Attended count as occupied. */
    public static function occupied_count( $formid, $date, $slot, $exclude_id = 0 ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id, posted_data FROM %i WHERE formid = %d AND posted_data LIKE %s ORDER BY id DESC LIMIT %d',
            $wpdb->prefix . self::MESSAGES, (int) $formid, '%' . $wpdb->esc_like( $date ) . '%', self::SCAN_LIMIT
        ) );
        $count = 0;
        foreach ( (array) $rows as $row ) {
            if ( (int) $row->id === (int) $exclude_id ) { continue; }
            $data = TZFD_Stored_Data::decode_booking( (string) $row->posted_data );
            if ( ! is_array( $data ) || empty( $data['apps'] ) || ! is_array( $data['apps'] ) ) { continue; }
            foreach ( $data['apps'] as $app ) {
                if ( ! is_array( $app ) ) { continue; }
                if ( ( $app['date'] ?? '' ) !== $date || ( $app['slot'] ?? '' ) !== $slot ) { continue; }
                if ( TZFD_Scheduling_Rules::is_occupied_status( (string) ( $app['cancelled'] ?? '' ) ) ) { $count++; }
            }
        }
        return $count;
    }

    /** Places in a slot, between 1 and 1000. */
    public static function capacity( $formid, $date, $slot ) {
        $capacity = (int) apply_filters( 'tzfd_pipeline_slot_capacity', 1, (int) $formid, $date, $slot );
        return max( 1, min( 1000, $capacity ) );
    }

    /**
     * Whether a booking that has passed the checks is approved without staff. The pipeline manager's
     * policy sets the default. The tzfd_pipeline_autonomous filter can override it.
     */
    public static function autonomous( $id, $source ) {
        $default = class_exists( 'TZFD_Manager_Pipeline' ) ? (bool) TZFD_Manager_Pipeline::policy()['autonomous'] : false;
        return (bool) apply_filters( 'tzfd_pipeline_autonomous', $default, array( 'booking_id' => (int) $id, 'source' => (string) $source ) );
    }

    /* ---------- Internals ---------- */

    private static function log( $id, $stage, $from, $to, $actor, $reason ) {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . self::TABLE_EVENTS, array(
            'booking_id' => (int) $id,
            'stage'      => substr( (string) $stage, 0, 20 ),
            'from_state' => substr( (string) $from, 0, 20 ),
            'to_state'   => substr( (string) $to, 0, 20 ),
            'actor'      => substr( sanitize_text_field( (string) $actor ), 0, 60 ),
            'reason'     => substr( sanitize_text_field( (string) $reason ), 0, 500 ),
            'created'    => current_time( 'mysql' ),
        ) );
    }

    /** Writes the calendar status on the booking's only appointment and fires the same hooks as a staff status change. */
    private static function set_calendar_status( $id, $status, $cancelreason = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::MESSAGES;
        $raw   = $wpdb->get_var( $wpdb->prepare( 'SELECT posted_data FROM %i WHERE id = %d', $table, (int) $id ) );
        $data  = TZFD_Stored_Data::decode_booking( (string) $raw );
        if ( ! is_array( $data ) || empty( $data['apps'][0] ) || ! is_array( $data['apps'][0] ) ) { return false; }

        $data['apps'][0]['cancelled']  = $status;
        $data['app_status_1']          = $status;
        if ( '' !== $cancelreason ) {
            $cancelreason = sanitize_text_field( $cancelreason );
            $data['apps'][0]['cancelreason'] = $cancelreason;
            $data['app_cancelreason_1']      = $cancelreason;
        }
        $saved = $wpdb->update( $table, array( 'posted_data' => serialize( $data ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The calendar stores bookings this way.
        if ( false === $saved ) { return false; }
        do_action( 'tzfd_update_status', (int) $id, $status );
        do_action( 'titan_zero_front_desk_booking_status_persisted', (int) $id, $status, '-1' );
        return true;
    }

    /** Date and start time from the draft's slot ("Y-m-d H:i"), or null. */
    private static function slot_parts( array $draft ) {
        $slot = trim( (string) ( $draft['slot'] ?? '' ) );
        if ( 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})$/', $slot, $m ) ) { return null; }
        if ( ! TZFD_Pipeline_Rules::valid_date( $m[1] ) || ! TZFD_Pipeline_Rules::valid_time( $m[2] ) ) { return null; }
        return array( 'date' => $m[1], 'start' => $m[2] );
    }

    private static function customer_fields( array $draft ) {
        $urgency = sanitize_key( (string) ( $draft['urgency'] ?? '' ) );
        return array(
            'customer_name'   => sanitize_text_field( (string) ( $draft['name'] ?? '' ) ),
            'customer_email'  => sanitize_email( (string) ( $draft['email'] ?? '' ) ),
            'customer_phone'  => sanitize_text_field( (string) ( $draft['phone'] ?? '' ) ),
            'service_address' => sanitize_textarea_field( (string) ( $draft['address'] ?? '' ) ),
            'postcode'        => sanitize_text_field( (string) ( $draft['postcode'] ?? '' ) ),
            'service_type'    => sanitize_text_field( (string) ( $draft['service_type'] ?? '' ) ),
            'description'     => sanitize_textarea_field( (string) ( $draft['description'] ?? '' ) ),
            'urgency'         => '' !== $urgency ? $urgency : 'routine',
        );
    }

    /** The site-time datetime "N seconds ago", in the same format as the stored datetimes. */
    private static function ago( $seconds ) {
        return date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - (int) $seconds ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date -- Naive site-time arithmetic, matched to the stored datetimes.
    }

    private static function fail( $code ) {
        return array( 'ok' => false, 'error' => (string) $code );
    }
}
