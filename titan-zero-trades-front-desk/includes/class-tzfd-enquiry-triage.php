<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-request-value.php';
require_once __DIR__ . '/class-tzfd-local-audit.php';
require_once __DIR__ . '/class-tzfd-transaction-storage.php';

/**
 * Deterministic enquiry triage (issue #28).
 *
 * Local rules only: classify() is a pure function of the enquiry text. It makes no hosted or AI call, no network
 * request and no database write. Rules add an intent, a queue (from an allowlisted business-rule map) and tags to
 * tz_enquiries. Triage never changes an enquiry's status, never deletes an enquiry and never declines one. Spam is
 * routed to a review queue, not discarded. An administrator can override the result; an override is locked against
 * automatic reclassification and is written to the Front Desk audit log.
 */
final class TZFD_Enquiry_Triage {

    const RULES_VERSION = 'v1';
    const ENABLED_OPTION = 'tz_fd_enquiry_triage_enabled';
    const QUEUE_MAP_OPTION = 'tz_fd_enquiry_triage_queue_map';
    const DB_OPTION = 'tz_fd_enquiry_triage_db';
    const DB_VERSION = '1';
    const ACTION = 'tz_fd_enquiry_triage';
    const NONCE = 'tz_fd_enquiry_triage';
    const NONCE_FIELD = 'tz_fd_enquiry_triage_nonce';
    const MAX_CHARS = 5000;

    /** Intents, in the order they are reported. */
    const INTENTS = array( 'booking', 'cancel', 'quote', 'complaint', 'service', 'spam', 'general' );

    /** Intents that describe a customer need. Spam and general are fallbacks, never primary when a need matches. */
    const PRIORITY = array( 'complaint', 'cancel', 'booking', 'quote', 'service' );

    /** Flags a row may carry besides an intent. Intent names are also allowed as tags for secondary intents. */
    const FLAG_TAGS = array( 'urgent', 'multi_intent', 'needs_review', 'possible_spam', 'admin_override' );

    /** Allowlisted queues on the existing Enquiries review screen. Business rules may only pick from this list. */
    const QUEUES = array( 'bookings', 'cancellations', 'quotes', 'complaints', 'service_requests', 'spam_review', 'general_enquiries' );

    /** Default business rules: intent to queue. Overridable by option, validated against the allowlist above. */
    const DEFAULT_QUEUE_MAP = array(
        'booking' => 'bookings', 'cancel' => 'cancellations', 'quote' => 'quotes', 'complaint' => 'complaints',
        'service' => 'service_requests', 'spam' => 'spam_review', 'general' => 'general_enquiries',
    );

    /** Keywords, English plus Spanish, French and German. Matched on whole words, case-insensitive. */
    const KEYWORDS = array(
        'booking' => array( 'book', 'books', 'booking', 'bookings', 'booked', 'appointment', 'appointments', 'appt', 'schedule',
            'scheduled', 'reschedule', 'rescheduled', 'rebook', 'reserve', 'reservation', 'availability', 'available',
            'slot', 'come out', 'reservar', 'reserva', 'cita', 'agendar', 'réserver', 'rendez-vous', 'buchen', 'termin', 'buchung' ),
        'cancel' => array( 'cancel', 'cancels', 'cancelled', 'cancelling', 'canceled', 'canceling', 'cancellation', 'call off',
            'no longer need', 'annuler', 'annulation', 'cancelar', 'cancelación', 'stornieren', 'stornierung', 'absagen' ),
        'quote' => array( 'quote', 'quotes', 'quoted', 'quotation', 'estimate', 'estimates', 'how much', 'price', 'prices',
            'pricing', 'cost', 'costs', 'rate', 'rates', 'tarif', 'combien', 'devis', 'presupuesto', 'precio', 'cuánto', 'angebot',
            'kosten', 'preis' ),
        'complaint' => array( 'complaint', 'complaints', 'complain', 'complaining', 'unhappy', 'disappointed', 'disappointing',
            'terrible', 'awful', 'rude', 'furious', 'refund', 'damaged', 'ruined', 'never again', 'worst', 'queja', 'reclamo',
            'plainte', 'réclamation', 'mécontent', 'beschwerde', 'unzufrieden' ),
        'service' => array( 'repair', 'repairs', 'fix', 'broken', 'burst pipe', 'leak', 'leaking', 'blocked', 'not working', "doesn't work",
            'install', 'installation', 'replace', 'replacement', 'maintenance', 'kaputt', 'reparieren', 'reparatur', 'reparar',
            'reparación', 'réparation', 'réparer', 'fuite', 'fuga', 'undicht', 'instalar', 'installer' ),
        'spam' => array( 'crypto', 'bitcoin', 'casino', 'forex', 'lottery', 'viagra', 'backlink', 'backlinks', 'seo services',
            'guaranteed traffic', 'click here', 'free money', 'make money fast', 'you have won', 'loan approval', 'earn money online' ),
    );

    /** Emergency words. A tag only; it never changes the queue. */
    const URGENT_KEYWORDS = array( 'urgent', 'urgently', 'emergency', 'asap', 'flooding', 'flooded', 'burst pipe', 'gas leak',
        'sparking', 'right now', 'urgence', 'urgente', 'dringend', 'notfall', 'emergencia', 'urgencia', 'inondation', 'inundación' );

    /** Words that cancel a keyword when they appear in the three words before it, inside the same clause. */
    const NEGATORS = array( 'not', 'no', 'never', 'dont', "don't", 'didnt', "didn't", 'doesnt', "doesn't", 'isnt', "isn't",
        'cant', "can't", 'cannot', 'wont', "won't", 'without', 'nicht', 'kein', 'keine', 'nein', 'ohne', 'niemals', 'ne', 'pas',
        'jamais', 'sans', 'ni', 'sin', 'nunca' );

    /** Pure rule evaluation. Safe to call without WordPress loaded. */
    public static function classify( $text ) {
        $norm = self::normalise( $text );
        $hits = array();
        foreach ( self::KEYWORDS as $intent => $words ) { $hits[ $intent ] = array(); }
        $urgent = false;
        foreach ( self::clauses( $norm ) as $clause ) {
            foreach ( self::patterns() as $intent => $re ) {
                $found = self::hits_in( $clause, $re );
                if ( $found ) { $hits[ $intent ] = array_merge( $hits[ $intent ], $found ); }
            }
            if ( ! $urgent && self::hits_in( $clause, self::urgent_pattern() ) ) { $urgent = true; }
        }
        // Two or more links in an enquiry from a stranger is a spam signal of its own.
        $links = preg_match_all( '#https?://#i', $norm );
        if ( $links >= 2 ) { $hits['spam'][] = 'link x' . $links; }

        $strong   = array_values( array_filter( self::PRIORITY, static function ( $i ) use ( $hits ) { return ! empty( $hits[ $i ] ); } ) );
        $spam_hit = ! empty( $hits['spam'] );
        if ( $strong ) {
            $primary = $strong[0];
            $tags    = array_slice( $strong, 1 );
        } elseif ( $spam_hit ) {
            $primary = 'spam';
            $tags    = array();
        } else {
            $primary = 'general';
            $tags    = array();
        }
        if ( count( $strong ) > 1 ) { $tags[] = 'multi_intent'; }
        if ( $spam_hit && 'spam' !== $primary ) { $tags[] = 'possible_spam'; }
        if ( 'spam' === $primary ) { $tags[] = 'possible_spam'; }
        if ( 'general' === $primary ) { $tags[] = 'needs_review'; }
        if ( $urgent ) { $tags[] = 'urgent'; }
        $tags = array_values( array_unique( array_intersect( array_merge( self::INTENTS, self::FLAG_TAGS ), $tags ) ) );

        return array(
            'intent'        => $primary,
            'queue'         => self::queue_for( $primary ),
            'tags'          => $tags,
            'matched'       => array_filter( $hits ),
            'rules_version' => self::RULES_VERSION,
        );
    }

    /** Queue for an intent from the business-rule map. Unknown intents go to general enquiries. */
    public static function queue_for( $intent ) {
        $map = self::queue_map();
        return isset( $map[ $intent ] ) ? $map[ $intent ] : 'general_enquiries';
    }

    /** Default map overlaid by the stored option. Entries outside the allowlist are dropped. */
    public static function queue_map() {
        $map = self::DEFAULT_QUEUE_MAP;
        $stored = function_exists( 'get_option' ) ? get_option( self::QUEUE_MAP_OPTION, array() ) : array();
        if ( is_array( $stored ) ) {
            foreach ( $stored as $intent => $queue ) {
                if ( in_array( $intent, self::INTENTS, true ) && in_array( $queue, self::QUEUES, true ) ) { $map[ $intent ] = $queue; }
            }
        }
        return $map;
    }

    /** Triage is on unless an administrator switches it off (rollback path). Enquiry capture never depends on it. */
    public static function enabled() {
        if ( ! function_exists( 'get_option' ) ) { return true; }
        return '0' !== (string) get_option( self::ENABLED_OPTION, '1' );
    }

    /** Lowercases, removes curly apostrophes and invalid UTF-8, collapses whitespace, caps length. */
    private static function normalise( $text ) {
        $text = is_string( $text ) ? $text : '';
        if ( ! preg_match( '//u', $text ) ) { $text = (string) @iconv( 'UTF-8', 'UTF-8//IGNORE', $text ); }
        $text = strtr( $text, array( "\xE2\x80\x99" => "'", "\xE2\x80\x98" => "'" ) );
        $text = substr( $text, 0, self::MAX_CHARS * 4 );
        $text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        $text = (string) preg_replace( '/\s+/u', ' ', $text );
        return trim( $text );
    }

    /** Splits on punctuation so a negator in one clause never cancels a keyword in another. */
    private static function clauses( $norm ) {
        $parts = preg_split( '/[.!?;,:]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $parts ) ? array_map( 'trim', $parts ) : array();
    }

    /** One compiled pattern per intent, built once per request. */
    private static function patterns() {
        static $compiled = null;
        if ( null !== $compiled ) { return $compiled; }
        $compiled = array();
        foreach ( self::KEYWORDS as $intent => $words ) { $compiled[ $intent ] = self::word_pattern( $words ); }
        return $compiled;
    }

    private static function urgent_pattern() {
        static $re = null;
        if ( null === $re ) { $re = self::word_pattern( self::URGENT_KEYWORDS ); }
        return $re;
    }

    private static function word_pattern( array $words ) {
        $alts = array();
        foreach ( $words as $word ) { $alts[] = str_replace( ' ', '\s+', preg_quote( $word, '/' ) ); }
        return '/(?<![\p{L}\p{N}])(?:' . implode( '|', $alts ) . ')(?![\p{L}\p{N}])/u';
    }

    /** Matched keyword texts in one clause that are not negated. */
    private static function hits_in( $clause, $re ) {
        $found = array();
        if ( ! preg_match_all( $re, $clause, $matches, PREG_OFFSET_CAPTURE ) ) { return $found; }
        foreach ( $matches[0] as $match ) {
            if ( ! self::negated( $clause, (int) $match[1] ) ) { $found[] = $match[0]; }
        }
        return $found;
    }

    private static function negated( $clause, $offset ) {
        $before = trim( substr( $clause, 0, $offset ) );
        if ( '' === $before ) { return false; }
        $words = preg_split( '/\s+/u', $before );
        foreach ( array_slice( $words, -3 ) as $word ) {
            if ( in_array( $word, self::NEGATORS, true ) ) { return true; }
        }
        return false;
    }

    /**
     * Classifies one stored enquiry and writes the result. Skips rows that an administrator has locked, and never
     * touches the status column. Returns the intent written, or '' when nothing was written.
     */
    public static function apply( $enquiry_id, $company_id ) {
        global $wpdb;
        if ( ! self::enabled() ) { return ''; }
        $enquiry_id = TZFD_Request_Value::id( $enquiry_id );
        $company_id = TZFD_Request_Value::id( $company_id );
        if ( ! $enquiry_id || ! $company_id || ! self::ensure_columns() ) { return ''; }
        $table = $wpdb->prefix . TZFD_Tables::ENQUIRIES;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, enquiry, triage_locked FROM %i WHERE id = %d AND company_id = %d', $table, $enquiry_id, $company_id ) );
        if ( ! $row || (int) $row->triage_locked > 0 ) { return ''; }
        $result = self::classify( (string) $row->enquiry );
        $updated = $wpdb->update(
            $table,
            array( 'triage_intent' => $result['intent'], 'triage_queue' => $result['queue'], 'triage_tags' => implode( ',', $result['tags'] ),
                'triage_source' => 'rules', 'triage_rules' => self::RULES_VERSION ),
            array( 'id' => $enquiry_id, 'company_id' => $company_id, 'triage_locked' => 0 ),
            array( '%s', '%s', '%s', '%s', '%s' ),
            array( '%d', '%d', '%d' )
        );
        // MySQL reports 0 affected rows when the stored values are already identical, which is a success here.
        return false === $updated ? '' : $result['intent'];
    }

    /** Intake calls this after a successful insert. It never throws into the enquiry capture path. */
    public static function apply_after_capture( $enquiry_id, $company_id ) {
        try {
            return self::apply( $enquiry_id, $company_id );
        } catch ( Throwable $error ) {
            return '';
        }
    }

    /**
     * Administrator override: sets intent and tags, locks the row against automatic reclassification and writes an
     * audit record in the same transaction. Returns true, or WP_Error.
     */
    public static function override( $enquiry_id, $company_id, $intent, array $tags, $actor ) {
        global $wpdb;
        $enquiry_id = TZFD_Request_Value::id( $enquiry_id );
        $company_id = TZFD_Request_Value::id( $company_id );
        if ( ! $enquiry_id || ! in_array( $intent, self::INTENTS, true ) ) {
            return new WP_Error( 'tzfd_bad_input', __( 'Choose a valid enquiry and intent.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) );
        }
        $clean_tags = array();
        foreach ( $tags as $tag ) {
            if ( ! is_string( $tag ) ) { return new WP_Error( 'tzfd_bad_input', __( 'Choose valid tags.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) ); }
            if ( ! in_array( $tag, array_merge( self::INTENTS, self::FLAG_TAGS ), true ) ) { return new WP_Error( 'tzfd_bad_input', __( 'Choose valid tags.', 'titan-zero-trades-front-desk' ), array( 'status' => 400 ) ); }
            $clean_tags[] = $tag;
        }
        $clean_tags = array_values( array_unique( array_merge( $clean_tags, array( 'admin_override' ) ) ) );
        if ( ! $enquiry_id || ! $company_id ) {
            return new WP_Error( 'tzfd_no_company', __( 'No company is selected.', 'titan-zero-trades-front-desk' ), array( 'status' => 403 ) );
        }
        if ( ! self::ensure_columns() ) {
            return new WP_Error( 'tzfd_unavailable', __( 'Triage storage is not available.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        if ( ! class_exists( 'TZFD_Local_Audit' ) || ! method_exists( 'TZFD_Local_Audit', 'insert' ) ) {
            return new WP_Error( 'tzfd_unavailable', __( 'The audit log is not available.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        $table = $wpdb->prefix . TZFD_Tables::ENQUIRIES;
        if ( ! TZFD_Transaction_Storage::require_innodb( array( $table, TZFD_Local_Audit::table_name() ) ) ) {
            return new WP_Error( 'tzfd_transaction_storage_required', __( 'Enquiry and audit tables must use InnoDB.', 'titan-zero-trades-front-desk' ), array( 'status' => 503 ) );
        }
        $queue = self::queue_for( $intent );
        $fail  = static function ( $code, $message, $status ) {
            return new WP_Error( $code, $message, array( 'status' => $status ) );
        };
        if ( false === $wpdb->query( 'START TRANSACTION' ) ) { return $fail( 'tzfd_db', __( 'The enquiry could not be updated.', 'titan-zero-trades-front-desk' ), 500 ); }
        $exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND company_id = %d FOR UPDATE', $table, $enquiry_id, $company_id ) );
        if ( ! $exists ) { $wpdb->query( 'ROLLBACK' ); return $fail( 'tzfd_not_found', __( 'Enquiry not found.', 'titan-zero-trades-front-desk' ), 404 ); }
        $update = $wpdb->update(
            $table,
            array( 'triage_intent' => $intent, 'triage_queue' => $queue, 'triage_tags' => implode( ',', $clean_tags ),
                'triage_source' => 'admin', 'triage_rules' => self::RULES_VERSION, 'triage_locked' => 1 ),
            array( 'id' => $enquiry_id, 'company_id' => $company_id ),
            array( '%s', '%s', '%s', '%s', '%s', '%d' ),
            array( '%d', '%d' )
        );
        if ( false === $update ) { $wpdb->query( 'ROLLBACK' ); return $fail( 'tzfd_db', __( 'The enquiry could not be updated.', 'titan-zero-trades-front-desk' ), 500 ); }
        $audit_ok = false;
        try {
            $audit_ok = TZFD_Local_Audit::insert( $company_id, (int) $actor, $actor ? 'user' : 'system', array(
                'plugin' => 'titan-zero-front-desk', 'action' => 'enquiry.triage_override', 'entity' => 'enquiry', 'entity_id' => $enquiry_id,
                'summary' => 'Enquiry triage set to ' . $intent . ' (queue ' . $queue . ')',
            ) );
        } catch ( Throwable $error ) {
            $audit_ok = false;
        }
        if ( true !== $audit_ok ) { $wpdb->query( 'ROLLBACK' ); return $fail( 'tzfd_audit', __( 'The change could not be recorded, so it was not saved.', 'titan-zero-trades-front-desk' ), 500 ); }
        if ( false === $wpdb->query( 'COMMIT' ) ) { return $fail( 'tzfd_commit_outcome_unknown', __( 'Triage update outcome is uncertain. Refresh the enquiry before retrying.', 'titan-zero-trades-front-desk' ), 503 ); }
        return true;
    }

    /** Adds the triage columns to tz_enquiries when they are missing. Idempotent; the option short-circuits later calls. */
    public static function ensure_columns() {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) { return false; }
        if ( function_exists( 'get_option' ) && self::DB_VERSION === (string) get_option( self::DB_OPTION, '' ) ) { return true; }
        $table = $wpdb->prefix . TZFD_Tables::ENQUIRIES;
        $definitions = array(
            'triage_intent'  => "varchar(20) NOT NULL DEFAULT ''",
            'triage_queue'   => "varchar(40) NOT NULL DEFAULT ''",
            'triage_tags'    => "varchar(255) NOT NULL DEFAULT ''",
            'triage_source'  => "varchar(10) NOT NULL DEFAULT ''",
            'triage_rules'   => "varchar(10) NOT NULL DEFAULT ''",
            'triage_locked'  => 'tinyint(1) unsigned NOT NULL DEFAULT 0',
        );
        foreach ( $definitions as $column => $definition ) {
            $present = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
            if ( empty( $present ) ) {
                $added = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN ' . $column . ' ' . $definition, $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- column names and definitions are constants above.
                if ( false === $added ) { return false; }
            }
        }
        if ( function_exists( 'update_option' ) ) { update_option( self::DB_OPTION, self::DB_VERSION, false ); }
        return true;
    }

    /** Admin-post: administrator override of one enquiry's triage. Capability, nonce and company are checked. */
    public static function handle_override() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'tz_fd_access' ) ) { wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( self::NONCE, self::NONCE_FIELD );
        $id     = TZFD_Request_Value::id( sanitize_text_field( wp_unslash( $_POST['enquiry'] ?? '' ) ) );
        $intent = TZFD_Request_Value::key( sanitize_text_field( wp_unslash( $_POST['intent'] ?? '' ) ) );
        $tags   = isset( $_POST['tags'] ) && is_array( $_POST['tags'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['tags'] ) ) : array();
        $result = self::override( $id, (int) TZFD_Scoped_Store::company_id(), $intent, $tags, (int) get_current_user_id() );
        $error_data = is_wp_error( $result ) ? $result->get_error_data() : null;
        $error_status = is_array( $error_data ) && isset( $error_data['status'] ) ? absint( $error_data['status'] ) : 500;
        $args = is_wp_error( $result ) ? array( 'enquiry_error' => $error_status ) : array( 'enquiry_saved' => 1 );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ENQUIRIES ) ) );
        exit;
    }

    public static function register() {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_override' ) );
    }
}
