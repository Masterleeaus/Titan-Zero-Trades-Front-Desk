<?php
/**
 * WordPress personal-data exporter and eraser for booking assistant chats saved by earlier Front Desk versions
 * (table tz_ai_conversations).
 *
 * Nothing here runs AI, reads or changes settings, or deletes anything on its own. Rows are read or deleted only when
 * WordPress's privacy tools ask for one exact email address. Erasure keeps no backup copy of the rows it deletes.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Legacy_Privacy {
    const CHAT_TABLE   = 'tz_ai_conversations';
    const GROUP        = 'tzfd-legacy-ai-chats';
    const PRIVACY_PAGE = 50;

    public static function register() {
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
    }

    public static function chat_table() {
        global $wpdb;
        return $wpdb->prefix . self::CHAT_TABLE;
    }

    public static function chat_exists() {
        global $wpdb;
        $table = self::chat_table();
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    public static function chat_columns() {
        global $wpdb;
        if ( ! self::chat_exists() ) { return array(); }
        $cols = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', self::chat_table() ) );
        $cols = is_array( $cols ) ? array_map( 'strval', $cols ) : array();
        return array_values( array_filter( $cols, function ( $c ) { return 1 === preg_match( '/^[A-Za-z0-9_]{1,64}$/', $c ); } ) );
    }

    public static function register_exporter( $exporters ) {
        $exporters[ self::GROUP ] = array(
            'exporter_friendly_name' => __( 'Booking assistant chats from earlier Front Desk versions', 'titan-zero-trades-front-desk' ),
            'callback'               => array( __CLASS__, 'privacy_export' ),
        );
        return $exporters;
    }

    public static function register_eraser( $erasers ) {
        $erasers[ self::GROUP ] = array(
            'eraser_friendly_name' => __( 'Booking assistant chats from earlier Front Desk versions', 'titan-zero-trades-front-desk' ),
            'callback'             => array( __CLASS__, 'privacy_erase' ),
        );
        return $erasers;
    }

    private static function chats_for_email( $email, $offset, $limit ) {
        global $wpdb;
        $table = self::chat_table();
        $rows  = in_array( 'id', self::chat_columns(), true )
            ? $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM %i WHERE LOWER(%i) = %s ORDER BY %i LIMIT %d OFFSET %d',
                $table, 'user_email', $email, 'id', $limit, $offset
            ), ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare(
                'SELECT * FROM %i WHERE LOWER(%i) = %s LIMIT %d OFFSET %d',
                $table, 'user_email', $email, $limit, $offset
            ), ARRAY_A );
        return is_array( $rows ) ? $rows : array();
    }

    private static function email_ready( $email ) {
        return is_string( $email ) && is_email( $email ) && self::chat_exists() && in_array( 'user_email', self::chat_columns(), true );
    }

    public static function privacy_export( $email_address, $page = 1 ) {
        $response = array( 'data' => array(), 'done' => true );
        $email = is_string( $email_address ) ? strtolower( trim( $email_address ) ) : '';
        if ( ! self::email_ready( $email ) || ! is_int( $page ) || $page < 1 ) { return $response; }
        $rows = self::chats_for_email( $email, ( $page - 1 ) * self::PRIVACY_PAGE, self::PRIVACY_PAGE + 1 );
        foreach ( array_slice( $rows, 0, self::PRIVACY_PAGE ) as $row ) {
            $data = array();
            foreach ( $row as $column => $value ) {
                $data[] = array( 'name' => (string) $column, 'value' => is_scalar( $value ) || null === $value ? (string) $value : wp_json_encode( $value ) );
            }
            $response['data'][] = array(
                'group_id'    => self::GROUP,
                'group_label' => __( 'Booking assistant chats (earlier versions)', 'titan-zero-trades-front-desk' ),
                'item_id'     => 'tzfd-legacy-ai-chat-' . ( isset( $row['id'] ) ? (string) $row['id'] : md5( wp_json_encode( $row ) ) ),
                'data'        => $data,
            );
        }
        $response['done'] = count( $rows ) <= self::PRIVACY_PAGE;
        return $response;
    }

    /**
     * Erase the chats for an exact email address (case-insensitive). The matching rows are deleted directly and no
     * backup copy is kept, because a backup would keep the same person's chats.
     */
    public static function privacy_erase( $email_address, $page = 1 ) {
        global $wpdb;
        $response = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
        $email = is_string( $email_address ) ? strtolower( trim( $email_address ) ) : '';
        if ( ! self::email_ready( $email ) ) { return $response; }
        $wpdb->last_error = '';
        $deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE LOWER(%i) = %s', self::chat_table(), 'user_email', $email ) );
        if ( false === $deleted || ! empty( $wpdb->last_error ) ) {
            $response['items_retained'] = true;
            $response['messages'][]     = __( 'Booking assistant chats from an earlier version could not be erased. Please retry.', 'titan-zero-trades-front-desk' );
            return $response;
        }
        $deleted = (int) $deleted;
        if ( $deleted > 0 ) {
            $response['items_removed'] = true;
            /* translators: %d: number of deleted chat records. */
            $response['messages'][] = sprintf( _n( '%d booking assistant chat from an earlier version deleted.', '%d booking assistant chats from an earlier version deleted.', $deleted, 'titan-zero-trades-front-desk' ), $deleted );
        }
        return $response;
    }
}
