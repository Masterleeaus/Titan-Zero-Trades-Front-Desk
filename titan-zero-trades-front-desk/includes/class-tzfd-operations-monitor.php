<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Read-only operations health and explicitly authorised recovery of the Smart Tasks scheduler. */
final class TZFD_Operations_Monitor {
    const PAGE = 'tzfd-smart-task-health';
    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_tzfd_rearm_smart_tasks', array( __CLASS__, 'rearm' ) );
    }
    public static function menu() {
        add_management_page( __( 'Front Desk Smart Tasks Health', 'titan-zero-trades-front-desk' ), __( 'Front Desk Smart Tasks', 'titan-zero-trades-front-desk' ), 'manage_options', self::PAGE, array( __CLASS__, 'render' ) );
    }
    /**
     * Health figures belong to the selected Workspace company, not the whole
     * installation. The dispatcher itself must remain site-wide so cron can
     * drain every tenant's queue.
     */
    public static function company_id() {
        if ( ! class_exists( 'TZFD_Scoped_Store' ) || ! method_exists( 'TZFD_Scoped_Store', 'company_id' ) ) { return 0; }
        $value = TZFD_Scoped_Store::company_id();
        if ( ! is_int( $value ) && ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) ) { return 0; }
        $original = (string) $value;
        $value = (int) $value;
        return $value > 0 && (string) $value === $original ? $value : 0;
    }
    public static function snapshot() {
        global $wpdb;
        $result = array( 'installed' => false, 'next_run' => null,
            'pending' => 0, 'leased' => 0, 'accepted' => 0, 'oldest_due' => null );
        $company = self::company_id();
        if ( ! $company ) { return $result; }
        $result['installed'] = TZFD_Smart_Task_Outbox::installed();
        if ( ! $result['installed'] ) { return $result; }
        $next = wp_next_scheduled( TZFD_Smart_Task_Outbox::HOOK );
        $result['next_run'] = $next ? (int) $next : null;
        $table = TZFD_Smart_Task_Outbox::table();
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT state, COUNT(*) AS total FROM %i WHERE company_id = %d GROUP BY state', $table, $company
        ), ARRAY_A );
        if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) { return $result; }
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['state'], $row['total'] ) || ! is_string( $row['state'] ) ) { continue; }
            $state = $row['state'];
            if ( in_array( $state, array( 'pending', 'leased', 'accepted' ), true ) ) {
                $result[ $state ] = max( 0, (int) $row['total'] );
            }
        }
        $result['oldest_due'] = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(available_at) FROM %i WHERE company_id = %d AND state = 'pending' AND available_at <= UTC_TIMESTAMP()", $table, $company
        ) );
        return $result;
    }
    public static function needs_attention( $snapshot, $now ) {
        return ! empty( $snapshot['installed'] ) && ( (int) $snapshot['pending'] + (int) $snapshot['leased'] > 0 ) &&
            ( empty( $snapshot['next_run'] ) || (int) $snapshot['next_run'] < $now - 300 );
    }
    public static function rearm() {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Not authorised', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        require_once __DIR__ . '/class-tzfd-admin-write-guard.php';
        TZFD_Admin_Write_Guard::enforce( 10, 4096 );
        check_admin_referer( 'tzfd_rearm_smart_tasks' );
        if ( ! self::company_id() ) { wp_die( esc_html__( 'No active company selected.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        $snapshot = self::snapshot();
        if ( $snapshot['installed'] && $snapshot['pending'] + $snapshot['leased'] > 0 && ! wp_next_scheduled( TZFD_Smart_Task_Outbox::HOOK ) ) {
            wp_schedule_single_event( time() + 60, TZFD_Smart_Task_Outbox::HOOK );
        }
        wp_safe_redirect( admin_url( 'tools.php?page=' . self::PAGE . '&rearmed=1' ) );
        exit;
    }
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $company = self::company_id();
        if ( ! $company ) {
            echo '<div class="wrap"><h1>' . wp_kses( TZFD_UI_Graphics::brand_html( 'automation' ), TZFD_UI_Graphics::allowed_html() ) . esc_html__( 'Front Desk Smart Tasks Health', 'titan-zero-trades-front-desk' ) . '</h1><p>' . esc_html__( 'Select a company to view its delivery queue.', 'titan-zero-trades-front-desk' ) . '</p></div>';
            return;
        }
        $data = self::snapshot();
        echo '<div class="wrap"><h1>' . wp_kses( TZFD_UI_Graphics::brand_html( 'automation' ), TZFD_UI_Graphics::allowed_html() ) . esc_html__( 'Front Desk Smart Tasks — Delivery Health', 'titan-zero-trades-front-desk' ) . '</h1>';
        echo '<p>' . esc_html__( 'This is delivery-queue monitoring, not the Workspace Smart Tasks totals. No AI/engine task separation is shown.', 'titan-zero-trades-front-desk' ) . '</p>';
        echo '<table class="widefat striped"><tbody>';
        foreach ( array( 'installed'=>__( 'Outbox table installed', 'titan-zero-trades-front-desk' ), 'pending'=>__( 'Pending deliveries', 'titan-zero-trades-front-desk' ), 'leased'=>__( 'Leased deliveries', 'titan-zero-trades-front-desk' ), 'accepted'=>__( 'Workspace-acknowledged deliveries', 'titan-zero-trades-front-desk' ), 'oldest_due'=>__( 'Oldest due (UTC)', 'titan-zero-trades-front-desk' ), 'next_run'=>__( 'Next scheduled worker (Unix timestamp)', 'titan-zero-trades-front-desk' ) ) as $field => $label ) {
            $value = $data[$field];
            if ( is_bool( $value ) ) { $value = $value ? __( 'Yes', 'titan-zero-trades-front-desk' ) : __( 'No', 'titan-zero-trades-front-desk' ); }
            echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( null === $value ? __( 'Not available', 'titan-zero-trades-front-desk' ) : (string) $value ) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ( self::needs_attention( $data, time() ) ) { echo '<p><strong>' . esc_html__( 'Attention:', 'titan-zero-trades-front-desk' ) . '</strong> ' . esc_html__( 'Delivery work exists without a current scheduler.', 'titan-zero-trades-front-desk' ) . '</p>'; }
        echo '<p>' . esc_html__( 'WP-Cron requires traffic or an external cron driver. Queue acceptance requires a valid Workspace acknowledgement.', 'titan-zero-trades-front-desk' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="tzfd_rearm_smart_tasks">';
        wp_nonce_field( 'tzfd_rearm_smart_tasks' );
        submit_button( 'Re-arm missing schedule (no direct delivery)' );
        echo '</form></div>';
    }
}
