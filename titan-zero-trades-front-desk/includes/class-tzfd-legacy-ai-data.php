<?php
/**
 * Legacy AI data kept from earlier Front Desk versions: audit, export, backup-gated deletion and restore.
 *
 * Nothing here runs AI, and nothing is deleted when WordPress loads. Deletion happens only from the administrator
 * screen, after a backup export matches the current data and the typed phrase is correct. Restore adds missing
 * rows and settings and never overwrites a value that exists. Credentials are never written to an export.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Legacy_AI_Data {
    const SCHEMA       = 'tzfd-legacy-ai-export/1';
    const CHAT_TABLE   = 'tz_ai_conversations';
    const RECEIPT      = 'tzfd_legacy_ai_backup_receipt';
    const RESTORE_LOG  = 'tzfd_legacy_ai_last_restore';
    const PHRASE       = 'DELETE LEGACY AI DATA';
    const PAGE         = 'tzfd-legacy-ai-data';
    const CAP          = 'manage_options';
    const MAX_UPLOAD   = 5242880;
    const MAX_CHATS    = 100000;
    const PRIVACY_PAGE = 50;
    const ABSENT       = '__tzfd_legacy_absent__';
    const MARKER       = 'tz_fd_ai_connector_migration_20261015';

    /** Provider keys and tokens. Exported only as a redacted marker and never restored. */
    const CREDENTIAL_OPTIONS = array( 'tz_fd_ai_api_key', 'tz_fd_ai_local_key' );

    /** Provider routing and cache settings. Removed with the connection scope and never restored. */
    const PROVIDER_OPTIONS = array(
        'tz_fd_ai_api_base', 'tz_fd_ai_model', 'tz_fd_ai_provider', 'tz_fd_ai_local_base',
        'tz_fd_ai_local_model', 'tz_fd_ai_local_models_cache', 'tz_fd_ai_local_kind', 'tz_fd_ai_mode',
    );

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 130 );
        add_action( 'admin_post_tzfd_legacy_ai_export', array( __CLASS__, 'handle_export' ) );
        add_action( 'admin_post_tzfd_legacy_ai_delete', array( __CLASS__, 'handle_delete' ) );
        add_action( 'admin_post_tzfd_legacy_ai_restore', array( __CLASS__, 'handle_restore' ) );
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
    }

    /* ---------- Classification and state (no values leave this section) ---------- */

    public static function classify( $name ) {
        $name = (string) $name;
        if ( self::MARKER === $name ) { return 'marker'; }
        if ( in_array( $name, self::CREDENTIAL_OPTIONS, true ) || preg_match( '/(key|secret|token|password)/i', $name ) ) {
            return 'credential';
        }
        if ( in_array( $name, self::PROVIDER_OPTIONS, true ) ) { return 'provider'; }
        return 'configuration';
    }

    public static function is_legacy_name( $name ) {
        return is_string( $name ) && 1 === preg_match( '/^tz_fd_ai_[a-z0-9_]{1,80}$/', $name );
    }

    /** Known legacy option names plus any other tz_fd_ai_* option found in the database. */
    public static function option_names() {
        global $wpdb;
        $found = $wpdb->get_col( $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
            $wpdb->esc_like( 'tz_fd_ai_' ) . '%'
        ) );
        $names = array_merge( self::CREDENTIAL_OPTIONS, self::PROVIDER_OPTIONS, array( self::MARKER ), is_array( $found ) ? $found : array() );
        $names = array_values( array_unique( array_filter( array_map( 'strval', $names ), array( __CLASS__, 'is_legacy_name' ) ) ) );
        sort( $names );
        return $names;
    }

    public static function option_state( $name ) {
        $raw = get_option( $name, self::ABSENT );
        return array( 'raw' => $raw, 'present' => self::ABSENT !== $raw && null !== $raw && '' !== $raw );
    }

    public static function stored_format( $value ) {
        if ( ! is_string( $value ) ) { return 'non-text'; }
        if ( 0 === strpos( $value, 'gcm1:' ) ) { return 'encrypted (current format)'; }
        if ( 0 === strpos( $value, 'enc:' ) ) { return 'earlier format, not read: must be entered again'; }
        return 'plain text';
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
        $cols = $wpdb->get_col( 'SHOW COLUMNS FROM `' . self::chat_table() . '`' );
        $cols = is_array( $cols ) ? array_map( 'strval', $cols ) : array();
        return array_values( array_filter( $cols, function ( $c ) { return 1 === preg_match( '/^[A-Za-z0-9_]{1,64}$/', $c ); } ) );
    }

    public static function chat_stats() {
        global $wpdb;
        $stats = array( 'exists' => false, 'rows' => 0, 'max_id' => 0 );
        if ( ! self::chat_exists() ) { return $stats; }
        $table = '`' . self::chat_table() . '`';
        $stats['exists'] = true;
        $stats['rows']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( in_array( 'id', self::chat_columns(), true ) ) {
            $stats['max_id'] = (int) $wpdb->get_var( "SELECT COALESCE(MAX(`id`),0) FROM {$table}" );
        }
        return $stats;
    }

    /** Hash of the legacy state. Credentials contribute only presence and format, never their value. */
    public static function fingerprint() {
        $parts = array();
        foreach ( self::option_names() as $name ) {
            $state = self::option_state( $name );
            $class = self::classify( $name );
            $digest = 'credential' === $class
                ? ( $state['present'] ? self::stored_format( $state['raw'] ) : '' )
                : hash( 'sha256', serialize( $state['raw'] ) );
            $parts[] = array( $name, $class, $state['present'], $digest );
        }
        $chat = self::chat_stats();
        return hash( 'sha256', wp_json_encode( array( $parts, $chat['exists'], $chat['rows'], $chat['max_id'] ) ) );
    }

    public static function has_legacy_data() {
        foreach ( self::option_names() as $name ) {
            if ( self::option_state( $name )['present'] && self::MARKER !== $name ) { return true; }
        }
        return self::chat_stats()['rows'] > 0;
    }

    public static function audit() {
        $options = array();
        foreach ( self::option_names() as $name ) {
            $state = self::option_state( $name );
            $row   = array( 'name' => $name, 'class' => self::classify( $name ), 'present' => $state['present'] );
            if ( 'credential' === $row['class'] && $state['present'] ) { $row['format'] = self::stored_format( $state['raw'] ); }
            $options[] = $row;
        }
        return array(
            'options' => $options,
            'chats'   => self::chat_stats(),
            'receipt' => self::receipt(),
            'current' => self::backup_current(),
        );
    }

    /* ---------- Backup receipt and export ---------- */

    public static function receipt() {
        $receipt = get_option( self::RECEIPT, array() );
        return is_array( $receipt ) ? $receipt : array();
    }

    /** True only when the newest export matches the data as it is now. */
    public static function backup_current() {
        $receipt = self::receipt();
        return ! empty( $receipt['fingerprint'] ) && hash_equals( (string) $receipt['fingerprint'], self::fingerprint() );
    }

    public static function build_export() {
        global $wpdb;
        $options = array();
        foreach ( self::option_names() as $name ) {
            $state = self::option_state( $name );
            $class = self::classify( $name );
            $entry = array( 'name' => $name, 'class' => $class, 'present' => $state['present'], 'value' => null, 'redacted' => false );
            if ( $state['present'] ) {
                $risky = 'credential' === $class
                    || ( 'provider' === $class && is_string( $state['raw'] ) && preg_match( '/[?@&]/', $state['raw'] ) );
                if ( $risky ) {
                    $entry['redacted'] = true;
                    $entry['format']   = self::stored_format( $state['raw'] );
                } else {
                    $entry['value'] = $state['raw'];
                }
            }
            $options[] = $entry;
        }
        $chats = array();
        if ( self::chat_exists() ) {
            $order = in_array( 'id', self::chat_columns(), true ) ? ' ORDER BY `id`' : '';
            $rows  = $wpdb->get_results( 'SELECT * FROM `' . self::chat_table() . '`' . $order, ARRAY_A );
            $chats = is_array( $rows ) ? $rows : array();
        }
        return array(
            'schema'       => self::SCHEMA,
            'generated_at' => gmdate( 'c' ),
            'options'      => $options,
            'chats'        => $chats,
            'counts'       => array( 'options' => count( $options ), 'chat_rows' => count( $chats ) ),
        );
    }

    /** Returns the export JSON and records the receipt that gates deletion. */
    public static function export_json() {
        $json = wp_json_encode( self::build_export() );
        if ( ! is_string( $json ) ) { return ''; }
        update_option( self::RECEIPT, array(
            'at'            => time(),
            'export_sha256' => hash( 'sha256', $json ),
            'chat_rows'     => (int) self::chat_stats()['rows'],
            'fingerprint'   => self::fingerprint(),
        ), false );
        return $json;
    }

    /* ---------- Deletion (only from the admin screen, never automatic) ---------- */

    /**
     * @param string $scope 'connection' removes stored keys and provider settings; 'chats' removes saved chat rows.
     * @param string $phrase Must equal PHRASE exactly.
     * @return array{ok:bool,code:string,removed:int}
     */
    public static function delete_scope( $scope, $phrase ) {
        global $wpdb;
        $scope = (string) $scope;
        if ( ! in_array( $scope, array( 'connection', 'chats' ), true ) ) { return self::result( false, 'bad_scope' ); }
        if ( self::PHRASE !== (string) $phrase ) { return self::result( false, 'bad_phrase' ); }
        if ( ! self::backup_current() ) { return self::result( false, 'no_current_backup' ); }

        $removed = 0;
        if ( 'connection' === $scope ) {
            foreach ( self::option_names() as $name ) {
                if ( ! in_array( self::classify( $name ), array( 'credential', 'provider' ), true ) ) { continue; }
                if ( self::option_state( $name )['present'] && delete_option( $name ) ) { ++$removed; }
            }
        } elseif ( self::chat_exists() ) {
            $deleted = $wpdb->query( 'DELETE FROM `' . self::chat_table() . '`' );
            $removed = false === $deleted ? 0 : (int) $deleted;
        }
        return self::result( true, 'deleted', $removed );
    }

    /* ---------- Restore (adds missing data only) ---------- */

    /**
     * @param string $json Export file contents.
     * @return array
     */
    public static function restore_from_json( $json ) {
        global $wpdb;
        if ( ! is_string( $json ) || '' === $json || strlen( $json ) > self::MAX_UPLOAD ) { return self::result( false, 'bad_file' ); }
        $data = json_decode( $json, true, 64 );
        if ( ! is_array( $data ) || ! isset( $data['schema'] ) || self::SCHEMA !== $data['schema'] ) { return self::result( false, 'bad_schema' ); }

        $summary = array( 'chats_inserted' => 0, 'chats_skipped' => 0, 'options_restored' => 0, 'options_skipped' => 0, 'credentials_to_reenter' => 0 );

        foreach ( (array) ( $data['options'] ?? array() ) as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['name'] ) || ! self::is_legacy_name( $entry['name'] ) ) { continue; }
            $name = $entry['name'];
            // The class comes from the current name, not from the file, so a file cannot retarget an arbitrary option.
            if ( 'credential' === self::classify( $name ) ) {
                if ( ! empty( $entry['present'] ) ) { ++$summary['credentials_to_reenter']; }
                continue;
            }
            if ( 'configuration' !== self::classify( $name ) || empty( $entry['present'] ) || ! array_key_exists( 'value', $entry ) ) {
                ++$summary['options_skipped'];
                continue;
            }
            if ( self::ABSENT !== get_option( $name, self::ABSENT ) ) { ++$summary['options_skipped']; continue; }
            if ( add_option( $name, $entry['value'], '', 'no' ) ) { ++$summary['options_restored']; } else { ++$summary['options_skipped']; }
        }

        $chats = isset( $data['chats'] ) && is_array( $data['chats'] ) ? $data['chats'] : array();
        if ( count( $chats ) > self::MAX_CHATS ) { return self::result( false, 'too_many_chats' ); }
        if ( ! empty( $chats ) ) {
            if ( ! self::chat_exists() ) { return self::result( false, 'chat_table_missing' ); }
            $columns = self::chat_columns();
            $table   = self::chat_table();
            foreach ( $chats as $row ) {
                if ( ! is_array( $row ) || ! isset( $row['id'] ) || ! is_int( $row['id'] ) || $row['id'] < 1 ) { ++$summary['chats_skipped']; continue; }
                $exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $table . '` WHERE `id` = %d', $row['id'] ) );
                if ( $exists > 0 ) { ++$summary['chats_skipped']; continue; }
                $clean = array();
                foreach ( $row as $column => $value ) {
                    if ( in_array( (string) $column, $columns, true ) && null !== $value && is_scalar( $value ) ) {
                        $clean[ (string) $column ] = (string) $value;
                    }
                }
                if ( count( $clean ) < 2 ) { ++$summary['chats_skipped']; continue; }
                if ( false !== $wpdb->insert( $table, $clean ) ) { ++$summary['chats_inserted']; } else { ++$summary['chats_skipped']; }
            }
        }
        update_option( self::RESTORE_LOG, array_merge( $summary, array( 'at' => time() ) ), false );
        return array_merge( self::result( true, 'restored' ), $summary );
    }

    private static function result( $ok, $code, $removed = 0 ) {
        return array( 'ok' => (bool) $ok, 'code' => (string) $code, 'removed' => (int) $removed );
    }

    /* ---------- Privacy: export and retention for historic chats ---------- */

    public static function register_exporter( $exporters ) {
        $exporters['tzfd-legacy-ai-chats'] = array(
            'exporter_friendly_name' => __( 'Booking assistant chats from earlier Front Desk versions', 'titan-zero-trades-front-desk' ),
            'callback'               => array( __CLASS__, 'privacy_export' ),
        );
        return $exporters;
    }

    public static function register_eraser( $erasers ) {
        $erasers['tzfd-legacy-ai-chats'] = array(
            'eraser_friendly_name' => __( 'Booking assistant chats from earlier Front Desk versions', 'titan-zero-trades-front-desk' ),
            'callback'             => array( __CLASS__, 'privacy_erase' ),
        );
        return $erasers;
    }

    private static function chats_for_email( $email, $offset, $limit ) {
        global $wpdb;
        $order = in_array( 'id', self::chat_columns(), true ) ? ' ORDER BY `id`' : '';
        $rows  = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM `' . self::chat_table() . '` WHERE LOWER(`user_email`) = %s' . $order . ' LIMIT %d OFFSET %d',
            $email, $limit, $offset
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
                'group_id'    => 'tzfd-legacy-ai-chats',
                'group_label' => __( 'Booking assistant chats (earlier versions)', 'titan-zero-trades-front-desk' ),
                'item_id'     => 'tzfd-legacy-ai-chat-' . ( isset( $row['id'] ) ? (string) $row['id'] : md5( wp_json_encode( $row ) ) ),
                'data'        => $data,
            );
        }
        $response['done'] = count( $rows ) <= self::PRIVACY_PAGE;
        return $response;
    }

    /** Chats are kept until an administrator deletes them on the Legacy AI data screen. Erasure reports them as kept. */
    public static function privacy_erase( $email_address, $page = 1 ) {
        global $wpdb;
        $response = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
        $email = is_string( $email_address ) ? strtolower( trim( $email_address ) ) : '';
        if ( ! self::email_ready( $email ) ) { return $response; }
        $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . self::chat_table() . '` WHERE LOWER(`user_email`) = %s', $email ) );
        if ( $count > 0 ) {
            $response['items_retained'] = true;
            /* translators: %d: number of kept chat records. */
            $response['messages'][] = sprintf( _n( '%d booking assistant chat from an earlier version is kept until an administrator deletes it on the Legacy AI data screen.', '%d booking assistant chats from an earlier version are kept until an administrator deletes them on the Legacy AI data screen.', $count, 'titan-zero-trades-front-desk' ), $count );
        }
        return $response;
    }

    /* ---------- Admin screen and handlers ---------- */

    public static function admin_menu() {
        if ( ! current_user_can( self::CAP ) ) { return; }
        // Listed under Front Desk only while legacy data exists; otherwise the screen stays reachable by URL only.
        $parent = class_exists( 'TZFD_Admin_Menu' ) && self::has_legacy_data() ? TZFD_Admin_Menu::PARENT : '';
        add_submenu_page( $parent, __( 'Legacy AI data', 'titan-zero-trades-front-desk' ), __( 'Legacy AI data', 'titan-zero-trades-front-desk' ), self::CAP, self::PAGE, array( __CLASS__, 'render_page' ) );
    }

    private static function guard( $action ) {
        if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        check_admin_referer( $action, '_wpnonce' );
    }

    private static function back( $code ) {
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'tzfd_legacy_ai_notice' => sanitize_key( $code ) ), admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handle_export() {
        self::guard( 'tzfd_legacy_ai_export' );
        $json = self::export_json();
        if ( '' === $json ) { self::back( 'export_failed' ); }
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="tzfd-legacy-ai-export-' . gmdate( 'Ymd-His' ) . '.json"' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw JSON file body served as an attachment.
        echo $json;
        exit;
    }

    public static function handle_delete() {
        self::guard( 'tzfd_legacy_ai_delete' );
        $scope  = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
        $phrase = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
        $result = self::delete_scope( $scope, $phrase );
        self::back( $result['ok'] ? 'deleted_' . $scope : $result['code'] );
    }

    public static function handle_restore() {
        self::guard( 'tzfd_legacy_ai_restore' );
        $file = isset( $_FILES['tzfd_legacy_ai_file'] ) && is_array( $_FILES['tzfd_legacy_ai_file'] ) ? $_FILES['tzfd_legacy_ai_file'] : array();
        if ( empty( $file['tmp_name'] ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > self::MAX_UPLOAD ) {
            self::back( 'bad_file' );
        }
        $json   = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local uploaded temp file, size-checked above.
        $result = self::restore_from_json( false === $json ? '' : $json );
        self::back( $result['ok'] ? 'restored' : $result['code'] );
    }

    private static function notice_text( $code ) {
        $texts = array(
            'deleted_connection' => __( 'Stored keys and provider settings were deleted. Chats and configuration were not changed.', 'titan-zero-trades-front-desk' ),
            'deleted_chats'      => __( 'Saved legacy chats were deleted. Your bookings, customers and settings were not changed.', 'titan-zero-trades-front-desk' ),
            'restored'           => __( 'The export was restored. Existing values were not overwritten. Keys must be entered again.', 'titan-zero-trades-front-desk' ),
            'no_current_backup'  => __( 'Export a backup first. The backup must match the current data before anything is deleted.', 'titan-zero-trades-front-desk' ),
            'bad_phrase'         => __( 'The confirmation phrase did not match. Nothing was deleted.', 'titan-zero-trades-front-desk' ),
            'bad_file'           => __( 'The file could not be read. Upload the export file as it was downloaded (JSON, 5 MB at most).', 'titan-zero-trades-front-desk' ),
            'bad_schema'         => __( 'This file is not a Front Desk legacy AI export.', 'titan-zero-trades-front-desk' ),
            'export_failed'      => __( 'The export could not be created.', 'titan-zero-trades-front-desk' ),
            'chat_table_missing' => __( 'The chat table is missing, so chats cannot be restored.', 'titan-zero-trades-front-desk' ),
            'too_many_chats'     => __( 'The file has too many chat rows to restore in one step.', 'titan-zero-trades-front-desk' ),
        );
        return isset( $texts[ $code ] ) ? $texts[ $code ] : '';
    }

    public static function render_page() {
        if ( ! current_user_can( self::CAP ) ) { wp_die( esc_html__( 'You do not have permission to open this page.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code; no state change.
        $code = isset( $_GET['tzfd_legacy_ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_legacy_ai_notice'] ) ) : '';
        $audit   = self::audit();
        $receipt = $audit['receipt'];
        $restore = get_option( self::RESTORE_LOG, array() );
        $action  = admin_url( 'admin-post.php' );
        ?>
        <div class="wrap tzfd-legacy-ai">
            <h1><?php esc_html_e( 'Legacy AI data', 'titan-zero-trades-front-desk' ); ?></h1>
            <p><?php esc_html_e( 'Keys, provider settings and chats saved by earlier versions are listed here. Nothing on this screen runs AI, and nothing is deleted automatically. Values are never shown.', 'titan-zero-trades-front-desk' ); ?></p>
            <?php if ( '' !== $code && '' !== self::notice_text( $code ) ) : ?>
                <div class="notice notice-info"><p><?php echo esc_html( self::notice_text( $code ) ); ?></p></div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Audit', 'titan-zero-trades-front-desk' ); ?></h2>
            <table class="widefat striped">
                <thead><tr><th><?php esc_html_e( 'Setting', 'titan-zero-trades-front-desk' ); ?></th><th><?php esc_html_e( 'Kind', 'titan-zero-trades-front-desk' ); ?></th><th><?php esc_html_e( 'Status', 'titan-zero-trades-front-desk' ); ?></th></tr></thead>
                <tbody>
                <?php foreach ( $audit['options'] as $row ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $row['name'] ); ?></code></td>
                        <td><?php echo esc_html( $row['class'] ); ?></td>
                        <td><?php
                            if ( ! $row['present'] ) { esc_html_e( 'Not set', 'titan-zero-trades-front-desk' ); }
                            elseif ( isset( $row['format'] ) ) { echo esc_html( $row['format'] ); }
                            else { esc_html_e( 'Set', 'titan-zero-trades-front-desk' ); }
                        ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p><?php
                if ( $audit['chats']['exists'] ) {
                    /* translators: %d: number of saved chat rows. */
                    printf( esc_html__( 'Saved chats from earlier versions: %d.', 'titan-zero-trades-front-desk' ), (int) $audit['chats']['rows'] );
                } else {
                    esc_html_e( 'No chat table from earlier versions is present.', 'titan-zero-trades-front-desk' );
                }
            ?></p>

            <h2><?php esc_html_e( 'Backup', 'titan-zero-trades-front-desk' ); ?></h2>
            <p><?php
                if ( empty( $receipt['at'] ) ) {
                    esc_html_e( 'No export has been made yet.', 'titan-zero-trades-front-desk' );
                } else {
                    /* translators: 1: date and time of the last export, 2: yes or no. */
                    printf( esc_html__( 'Last export: %1$s. Matches current data: %2$s.', 'titan-zero-trades-front-desk' ), esc_html( wp_date( 'j M Y H:i', (int) $receipt['at'] ) ), $audit['current'] ? esc_html__( 'yes', 'titan-zero-trades-front-desk' ) : esc_html__( 'no', 'titan-zero-trades-front-desk' ) );
                }
            ?></p>
            <form method="post" action="<?php echo esc_url( $action ); ?>">
                <input type="hidden" name="action" value="tzfd_legacy_ai_export">
                <?php wp_nonce_field( 'tzfd_legacy_ai_export', '_wpnonce' ); ?>
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Download export', 'titan-zero-trades-front-desk' ); ?></button>
            </form>
            <p class="description"><?php esc_html_e( 'The export holds settings and chats, with keys and tokens replaced by a marker. Keep it somewhere safe.', 'titan-zero-trades-front-desk' ); ?></p>

            <h2><?php esc_html_e( 'Delete after backup', 'titan-zero-trades-front-desk' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Each delete needs a current export and the phrase exactly as shown. Keys are not in the export, so deleted keys must be entered again.', 'titan-zero-trades-front-desk' ); ?></p>
            <?php foreach ( array( 'connection' => __( 'Delete stored keys and provider settings', 'titan-zero-trades-front-desk' ), 'chats' => __( 'Delete saved chats', 'titan-zero-trades-front-desk' ) ) as $scope => $label ) : ?>
                <form method="post" action="<?php echo esc_url( $action ); ?>" style="margin-bottom:12px">
                    <input type="hidden" name="action" value="tzfd_legacy_ai_delete">
                    <input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
                    <?php wp_nonce_field( 'tzfd_legacy_ai_delete', '_wpnonce' ); ?>
                    <label>
                        <?php echo esc_html( $label ); ?>:
                        <input type="text" name="confirm" autocomplete="off" placeholder="<?php echo esc_attr( self::PHRASE ); ?>">
                    </label>
                    <button type="submit" class="button"><?php esc_html_e( 'Delete', 'titan-zero-trades-front-desk' ); ?></button>
                </form>
            <?php endforeach; ?>

            <h2><?php esc_html_e( 'Restore from an export', 'titan-zero-trades-front-desk' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Adds missing chats and settings. Existing values are never overwritten, and keys are not restored.', 'titan-zero-trades-front-desk' ); ?></p>
            <form method="post" action="<?php echo esc_url( $action ); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="tzfd_legacy_ai_restore">
                <?php wp_nonce_field( 'tzfd_legacy_ai_restore', '_wpnonce' ); ?>
                <input type="file" name="tzfd_legacy_ai_file" accept="application/json,.json">
                <button type="submit" class="button"><?php esc_html_e( 'Restore', 'titan-zero-trades-front-desk' ); ?></button>
            </form>
            <?php if ( ! empty( $restore['at'] ) ) : ?>
                <p class="description"><?php
                    /* translators: 1: chats added, 2: settings added, 3: settings skipped, 4: keys to re-enter. */
                    printf( esc_html__( 'Last restore: %1$d chats added, %2$d settings added, %3$d settings skipped, %4$d keys to enter again.', 'titan-zero-trades-front-desk' ), (int) $restore['chats_inserted'], (int) $restore['options_restored'], (int) $restore['options_skipped'], (int) $restore['credentials_to_reenter'] );
                ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
