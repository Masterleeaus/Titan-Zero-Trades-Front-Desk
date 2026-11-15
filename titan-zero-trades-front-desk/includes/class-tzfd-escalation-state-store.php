<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Optional first-party escalation owner, explicitly enabled by a trusted site integration. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Escalation_State_Store {
    public static function table() { return TZFD_Tables::checked_name( TZFD_Tables::ESCALATION_STATE ); }
    public static function installed() {
        global $wpdb;
        $table = self::table();
        if ( is_wp_error( $table ) ) { return false; }
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }
    public static function install() {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) { return false; }
        if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
        $table = self::table(); $collate = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL,
            call_id bigint(20) unsigned NOT NULL,
            revision bigint(20) unsigned NOT NULL DEFAULT 1,
            state_hash char(64) NOT NULL,
            state_json longtext NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY company_call (company_id,call_id)
        ) $collate;" );
        if ( ! self::installed() ) { return false; }
        $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0  );
        foreach ( array( 'company_id','call_id','revision','state_hash','state_json' ) as $column ) {
            if ( ! is_array( $columns ) || ! in_array( $column, $columns, true ) ) { return false; }
        }
        // The compare-and-swap owner must have one row per company/call.
        $keys = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), ARRAY_A  );
        if ( ! is_array( $keys ) ) { return false; }
        $unique = array();
        foreach ( $keys as $key ) {
            if ( ! isset( $key['Key_name'], $key['Non_unique'], $key['Seq_in_index'], $key['Column_name'] ) ||
                (int) $key['Non_unique'] !== 0 ) { continue; }
            $unique[ $key['Key_name'] ][ (int) $key['Seq_in_index'] ] = $key['Column_name'];
        }
        foreach ( $unique as $columns ) {
            ksort( $columns );
            if ( array_values( $columns ) === array( 'company_id', 'call_id' ) ) { return true; }
        }
        return false;
    }
    /** Opt-in only. Caller is responsible for actor authentication; no public endpoint is registered. */
    public static function boot() {
        if ( true !== apply_filters( 'tzfd_enable_local_escalation_owner_v1', false ) ) { return; }
        add_filter( 'tzfd_escalation_load_state_v1', array( __CLASS__, 'load' ), 10, 3 );
        add_filter( 'tzfd_escalation_commit_ack_v1', array( __CLASS__, 'commit_ack' ), 10, 7 );
    }
    public static function create( $company, $call, $contacts, $now, $interval = 120 ) {
        global $wpdb;
        if ( ! self::installed() || true !== apply_filters( 'tzfd_escalation_verify_state_create_v1', false, $company, $call, $contacts ) ) { return false; }
        $state = TZFD_Call_Routing::create_escalation( $company, $call, $contacts, $now, $interval );
        if ( empty( $state['ok'] ) ) { return false; }
        $json = wp_json_encode( $state );
        if ( ! is_string( $json ) ) { return false; }
        $table = self::table();
        return 1 === (int) $wpdb->query( $wpdb->prepare("INSERT INTO %i (company_id,call_id,revision,state_hash,state_json,updated_at) VALUES (%d,%d,1,%s,%s,UTC_TIMESTAMP())", $table, $company, $call, hash('sha256',$json), $json) );
    }
    public static function load( $default, $company, $call ) {
        global $wpdb;
        if ( ! self::installed() || ! is_int( $company ) || $company < 1 || ! is_int( $call ) || $call < 1 ) { return null; }
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare("SELECT revision,state_hash,state_json FROM %i WHERE company_id=%d AND call_id=%d", $table, $company, $call), ARRAY_A );
        if ( ! is_array( $row ) || ! isset( $row['revision'], $row['state_hash'], $row['state_json'] ) ||
             ! is_scalar( $row['revision'] ) || ! ctype_digit( (string) $row['revision'] ) || (int) $row['revision'] < 1 ||
             ! is_string( $row['state_hash'] ) || ! is_string( $row['state_json'] ) || ! hash_equals( $row['state_hash'], hash('sha256',$row['state_json']) ) ) { return null; }
        $state = json_decode( $row['state_json'], true );
        if ( ! is_array( $state ) || ( $state['company_id'] ?? null ) !== $company || ( $state['call_id'] ?? null ) !== $call ) { return null; }
        $state['_revision'] = (int) $row['revision'];
        $state['_stored_hash'] = $row['state_hash'];
        return $state;
    }
    public static function commit_ack( $default, $company, $call, $contact, $request, $before, $after ) {
        global $wpdb;
        if ( ! self::installed() || ! is_array( $before ) || ! is_array( $after ) ||
             ! is_int( $before['_revision'] ?? null ) || $before['_revision'] < 1 ||
             ! is_string( $before['_stored_hash'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $before['_stored_hash'] ) ||
             ( $before['status'] ?? null ) !== 'awaiting_ack' || ( $after['status'] ?? null ) !== 'acknowledged' ||
             ( $after['acknowledged_by'] ?? null ) !== $contact || ( $before['contacts'][$before['index']] ?? null ) !== $contact ||
             ( $before['company_id'] ?? null ) !== $company || ( $before['call_id'] ?? null ) !== $call ||
             ! is_int( $before['due_at'] ?? null ) || $before['due_at'] < 1 ||
             ! is_int( $company ) || $company < 1 || ! is_int( $call ) || $call < 1 ||
             ! is_int( $contact ) || $contact < 1 ) { return null; }
        // Acknowledgement may ONLY change status and acknowledged_by. All routing,
        // deadline and ownership fields must match the loaded persisted state.
        $expected = $before;
        unset( $expected['_revision'], $expected['_stored_hash'] );
        $expected['status'] = 'acknowledged';
        $expected['acknowledged_by'] = $contact;
        unset( $after['_revision'], $after['_stored_hash'] );
        if ( $after !== $expected ) { return null; }
        $json = wp_json_encode( $after );
        if ( ! is_string( $json ) ) { return null; }
        $table = self::table();
        $updated = $wpdb->query( $wpdb->prepare("UPDATE %i SET state_json=%s,state_hash=%s,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE company_id=%d AND call_id=%d AND revision=%d AND state_hash=%s AND UTC_TIMESTAMP() <= %s", $table, $json, hash('sha256',$json), $company, $call, $before['_revision'], $before['_stored_hash'], gmdate( 'Y-m-d H:i:s', $before['due_at'] )) );
        if ( 1 !== (int) $updated ) { return null; }
        return array( 'committed'=>true, 'company_id'=>$company, 'call_id'=>$call, 'contact_id'=>$contact, 'request_id'=>$request );
    }
}
