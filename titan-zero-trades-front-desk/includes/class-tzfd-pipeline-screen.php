<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-request-value.php';


require_once __DIR__ . '/class-tzfd-pipeline.php';

/**
 * Front Desk > Pipeline: the staff queue for bookings the checks have not approved, and 30-day telemetry.
 * Each action is a POST to admin-post.php with its own nonce, and needs the same capability as Bookings.
 */
final class TZFD_Pipeline_Screen {

    const PAGE   = TZFD_Tables::PIPELINE;
    const ACTION = 'tz_fd_pipeline_action';
    const CAP    = 'tz_fd_access';
    const PARENT = 'tzfd';
    const LABEL_ACTION = 'tzfd_pipeline_save_labels';
    const LABEL_OPTION = 'tzfd_pipeline_display_labels';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 112 );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
        add_action( 'admin_post_' . self::LABEL_ACTION, array( __CLASS__, 'save_labels' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            self::PARENT,
            __( 'Pipeline', 'titan-zero-trades-front-desk' ),
            __( 'Pipeline', 'titan-zero-trades-front-desk' ),
            self::CAP,
            self::PAGE,
            TZFD_Admin_Menu::ui_wrap( array( __CLASS__, 'render' ), self::PAGE )
        );
    }

    public static function render() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'titan-zero-trades-front-desk' ) );
        }
        global $wpdb;
        $table = $wpdb->prefix . TZFD_Pipeline::TABLE_STATE;

        // Company isolation: the queue shows only this company's pipeline rows.
        $company = TZFD_Scoped_Store::company_id();
        // No company means no rows (fail closed).
        $open = $company < 1 ? array() : $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_id, formid, state, reason, source, attempts, updated FROM %i WHERE company_id = %d AND state IN (%s, %s, %s) ORDER BY updated DESC LIMIT %d",
            $table, $company,
            TZFD_Pipeline_Rules::PROCESSING, TZFD_Pipeline_Rules::PROCESSED, TZFD_Pipeline_Rules::ESCALATED, 200
        ) );
        $open_available = is_array( $open ) && empty( $wpdb->last_error );
        if ( ! $open_available ) { $open = array(); }
        $done = $company < 1 ? array() : $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_id, formid, state, reason, source, updated FROM %i WHERE company_id = %d AND state IN (%s, %s) ORDER BY updated DESC LIMIT %d",
            $table, $company, TZFD_Pipeline_Rules::APPROVED, TZFD_Pipeline_Rules::REJECTED, 20
        ) );
        $done_available = is_array( $done ) && empty( $wpdb->last_error );
        if ( ! $done_available ) { $done = array(); }
        $stats = TZFD_Pipeline::stats( 30 );

        echo '<div class="wrap tzfd-pipeline-page"><h1>' . wp_kses( TZFD_UI_Graphics::brand_html( 'inbox' ), TZFD_UI_Graphics::allowed_html() ) . esc_html__( 'Pipeline', 'titan-zero-trades-front-desk' ) . '</h1>';
        self::notice();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view flag; the save it reports was nonce-checked in save_labels().
        if ( isset( $_GET['labels_saved'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['labels_saved'] ) ) ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Stage display names saved.', 'titan-zero-trades-front-desk' ) . '</p></div>'; }
        if ( ! $open_available || ! $done_available || empty( $stats['available'] ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Pipeline records could not be loaded. Check the database before relying on an empty queue or zero totals.', 'titan-zero-trades-front-desk' ) . '</p></div>';
        }
        echo '<p class="tzfd-pipeline-intro">' . esc_html__( 'Every booking from the website assistant or your channels is checked here before it is confirmed. Approve it, reject it, or ask for another check.', 'titan-zero-trades-front-desk' ) . '</p>';

        // Stage overview: one card per stage, with its count for the last 30 days.
        $avg = empty( $stats['available'] ) ? '—' : ( null === $stats['avg_minutes_to_approve'] ? '—' : (string) $stats['avg_minutes_to_approve'] );
        echo '<h2 class="tzfd-pipeline-h2">' . esc_html__( 'Where bookings are now', 'titan-zero-trades-front-desk' ) . ' <span class="tzfd-pipeline-sub">' . esc_html__( '(last 30 days)', 'titan-zero-trades-front-desk' ) . '</span></h2>';
        echo '<ol class="tzfd-pipeline-flow">';
        $stages = array(
            TZFD_Pipeline_Rules::PROCESSING => array( __( 'Being checked', 'titan-zero-trades-front-desk' ), __( 'Automatic checks are running.', 'titan-zero-trades-front-desk' ) ),
            TZFD_Pipeline_Rules::PROCESSED  => array( __( 'Waiting for approval', 'titan-zero-trades-front-desk' ), __( 'A team member approves, rejects or asks for a re-check.', 'titan-zero-trades-front-desk' ) ),
            TZFD_Pipeline_Rules::ESCALATED  => array( __( 'Needs staff', 'titan-zero-trades-front-desk' ), __( 'An exception that needs a person to look at it.', 'titan-zero-trades-front-desk' ) ),
            TZFD_Pipeline_Rules::APPROVED   => array( __( 'Approved', 'titan-zero-trades-front-desk' ), __( 'Confirmed. Kept in decision history.', 'titan-zero-trades-front-desk' ) ),
            TZFD_Pipeline_Rules::REJECTED   => array( __( 'Rejected', 'titan-zero-trades-front-desk' ), __( 'Declined. Kept in decision history.', 'titan-zero-trades-front-desk' ) ),
        );
        foreach ( $stages as $state => $meta ) {
            $n = isset( $stats['counts'][ $state ] ) ? (int) $stats['counts'][ $state ] : 0;
            $count = ! empty( $stats['available'] ) ? (string) $n : '—';
            echo '<li class="tzfd-pipeline-step tzfd-pipeline-step--' . esc_attr( sanitize_html_class( $state ) ) . '">';
            echo '<span class="tzfd-pipeline-count">' . esc_html( $count ) . '</span>';
            echo '<strong>' . esc_html( self::display_label( $state, $meta[0] ) ) . '</strong>';
            echo '<span class="tzfd-pipeline-hint">' . esc_html( $meta[1] ) . '</span></li>';
        }
        echo '</ol>';
        echo '<p class="tzfd-pipeline-avg">' . esc_html__( 'Average time from booking to approval:', 'titan-zero-trades-front-desk' ) . ' <strong>' . esc_html( $avg ) . ( '—' === $avg ? '' : ' ' . esc_html__( 'minutes', 'titan-zero-trades-front-desk' ) ) . '</strong></p>';

        // Queue: the only bookings that need a decision now.
        echo '<h2 class="tzfd-pipeline-h2">' . esc_html__( 'Needs your action now', 'titan-zero-trades-front-desk' ) . '</h2>';
        echo '<p class="tzfd-pipeline-sub">' . esc_html__( 'Check the reason, then approve, reject or ask for a re-check.', 'titan-zero-trades-front-desk' ) . '</p>';
        if ( empty( $open ) ) {
            echo '<p class="tzfd-pipeline-empty">' . esc_html( $open_available ? __( 'Nothing is waiting for you.', 'titan-zero-trades-front-desk' ) : __( 'The open queue is unavailable. Check the database and reload this page.', 'titan-zero-trades-front-desk' ) ) . '</p>';
        } else {
            self::table_start();
            foreach ( $open as $row ) { self::open_row( $row ); }
            echo '</tbody></table>';
        }

        // History: decisions already made.
        echo '<h2 class="tzfd-pipeline-h2">' . esc_html__( 'Recent decisions', 'titan-zero-trades-front-desk' ) . '</h2>';
        if ( empty( $done ) ) {
            echo '<p class="tzfd-pipeline-empty">' . esc_html( $done_available ? __( 'No decisions yet.', 'titan-zero-trades-front-desk' ) : __( 'Recent decisions are unavailable. Check the database and reload this page.', 'titan-zero-trades-front-desk' ) ) . '</p>';
        } else {
            self::table_start();
            foreach ( $done as $row ) {
                $facts = TZFD_Pipeline::details( (int) $row->booking_id );
                echo '<tr><td>#' . (int) $row->booking_id . '</td><td>' . esc_html( self::who( $facts ) ) . '</td>';
                echo '<td>' . esc_html( self::when( $facts ) ) . '</td>';
                echo '<td>' . wp_kses( self::state_tag( $row->state ), TZFD_Local_Brand::allowed_html() ) . '</td>';
                echo '<td>' . esc_html( (string) $row->reason ) . '</td><td></td></tr>';
            }
            echo '</tbody></table>';
        }

        // Settings are kept out of the way: one collapsed section.
        echo '<details class="tzfd-pipeline-settings"><summary>' . esc_html__( 'Stage names and settings', 'titan-zero-trades-front-desk' ) . '</summary>';
        echo '<p>' . esc_html__( 'This is the booking approval workflow. Its states drive validation, approvals and notifications, so they cannot be reordered here. You can change the names staff see.', 'titan-zero-trades-front-desk' ) . '</p>';
        self::label_form();
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=tzfd-settings' ) ) . '">' . esc_html__( 'Configure Front Desk', 'titan-zero-trades-front-desk' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=tz_fd_bookings' ) ) . '">' . esc_html__( 'Open booking management', 'titan-zero-trades-front-desk' ) . '</a></p>';
        echo '</details>';
        echo '</div>';
    }

    /** Display labels are presentation only; never mutate booking state identifiers. */
    private static function display_label( $state, $default ) {
        $labels = get_option( self::LABEL_OPTION . '_' . (int) TZFD_Scoped_Store::company_id(), array() );
        return is_array( $labels ) && isset( $labels[ $state ] ) && is_string( $labels[ $state ] ) && '' !== $labels[ $state ] ? $labels[ $state ] : $default;
    }

    private static function label_defaults() {
        return array(
            TZFD_Pipeline_Rules::PROCESSING => __( 'Being checked', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::PROCESSED => __( 'Waiting for approval', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::ESCALATED => __( 'Needs staff', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::APPROVED => __( 'Approved', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::REJECTED => __( 'Rejected', 'titan-zero-trades-front-desk' ),
        );
    }

    private static function label_form() {
        echo '<details class="tzfd-pipeline-guide"><summary>' . esc_html__( 'Customize stage names', 'titan-zero-trades-front-desk' ) . '</summary>';
        echo '<p>' . esc_html__( 'Change the names shown to staff. Booking statuses, order, approvals and automation remain unchanged.', 'titan-zero-trades-front-desk' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( self::LABEL_ACTION ) . '">';
        wp_nonce_field( self::LABEL_ACTION );
        foreach ( self::label_defaults() as $state => $default ) {
            echo '<p><label><strong>' . esc_html( $default ) . '</strong><br><input class="regular-text" type="text" maxlength="60" name="labels[' . esc_attr( $state ) . ']" value="' . esc_attr( self::display_label( $state, $default ) ) . '"></label></p>';
        }
        submit_button( __( 'Save display names', 'titan-zero-trades-front-desk' ) );
        echo '</form></details>';
    }

    public static function save_labels() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'Access denied.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        check_admin_referer( self::LABEL_ACTION );
        if ( (int) TZFD_Scoped_Store::company_id() < 1 ) { wp_die( esc_html__( 'Company context required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) ); }
        $raw = isset( $_POST['labels'] ) && is_array( $_POST['labels'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['labels'] ) ) : array();
        $clean = array();
        foreach ( self::label_defaults() as $state => $default ) {
            if ( isset( $raw[ $state ] ) && is_string( $raw[ $state ] ) ) {
                $label = trim( $raw[ $state ] );
                if ( '' !== $label ) { $clean[ $state ] = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 60 ) : substr( $label, 0, 60 ); }
            }
        }
        update_option( self::LABEL_OPTION . '_' . (int) TZFD_Scoped_Store::company_id(), $clean, false );
        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&labels_saved=1' ) );
        exit;
    }

    public static function handle() {
        if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            wp_die( esc_html__( 'POST request required.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
        }
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'titan-zero-trades-front-desk' ) );
        }
        $id = TZFD_Request_Value::id( sanitize_text_field( wp_unslash( $_POST['booking_id'] ?? '' ) ) );
        if ( $id < 1 ) { wp_die( esc_html__( 'Invalid booking ID.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) ); }
        require_once __DIR__ . '/class-tzfd-admin-write-guard.php';
        TZFD_Admin_Write_Guard::enforce( 20, 8192 );
        check_admin_referer( 'tz_fd_pipeline_' . $id, '_tz_pipeline' );

        $do    = is_string( $_POST['do'] ?? null ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
        $actor = 'staff:' . get_current_user_id();
        // Company isolation: staff can act only on a booking in their own company. Details are read in that scope.
        if ( $id < 1 || null === TZFD_Pipeline::details( $id ) ) {
            $result = array( 'ok' => false, 'error' => 'not_found' );
        } else {
            switch ( $do ) {
                case 'approve':
                    $result = TZFD_Pipeline::approve( $id, $actor, 'approved by staff' );
                    break;
                case 'reject':
                    $result = TZFD_Pipeline::reject( $id, $actor, 'rejected by staff' );
                    break;
                case 'recheck':
                    $result = TZFD_Pipeline::recheck( $id, $actor );
                    break;
                default:
                    $result = array( 'ok' => false, 'error' => 'unknown_action' );
            }
        }
        $code = ! empty( $result['ok'] ) ? 'done' : (string) ( $result['error'] ?? 'failed' );
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'tz_fd_pl_msg' => $code ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* ---------- Rendering helpers ---------- */

    private static function open_row( $row ) {
        $id    = (int) $row->booking_id;
        $facts = TZFD_Pipeline::details( $id );
        echo '<tr><td>#' . esc_html( (string) $id ) . '</td><td>' . esc_html( self::when( $facts ) ) . '</td>';
        echo '<td>' . esc_html( self::who( $facts ) ) . '</td>';
        echo '<td>' . esc_html( (string) ( $facts['service'] ?? '' ) ) . '</td>';
        echo '<td>' . esc_html( (string) $row->source ) . '</td>';
        echo '<td>' . wp_kses( self::state_tag( $row->state ), TZFD_Local_Brand::allowed_html() ) . '</td>';
        echo '<td>' . esc_html( (string) $row->reason ) . '</td><td>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:2px">';
        wp_nonce_field( 'tz_fd_pipeline_' . $id, '_tz_pipeline' );
        echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        echo '<input type="hidden" name="booking_id" value="' . esc_attr( (string) $id ) . '">';
        if ( TZFD_Pipeline_Rules::can_move( $row->state, TZFD_Pipeline_Rules::APPROVED ) ) {
            echo '<button class="button button-primary" name="do" value="approve">' . esc_html__( 'Approve', 'titan-zero-trades-front-desk' ) . '</button> ';
        }
        if ( TZFD_Pipeline_Rules::can_move( $row->state, TZFD_Pipeline_Rules::REJECTED ) ) {
            echo '<button class="button" name="do" value="reject">' . esc_html__( 'Reject', 'titan-zero-trades-front-desk' ) . '</button> ';
        }
        if ( TZFD_Pipeline_Rules::ESCALATED === $row->state || TZFD_Pipeline_Rules::PROCESSED === $row->state ) {
            echo '<button class="button" name="do" value="recheck">' . esc_html__( 'Check again', 'titan-zero-trades-front-desk' ) . '</button>';
        }
        echo '</form></td></tr>';
    }

    private static function table_start() {
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__( 'Booking', 'titan-zero-trades-front-desk' ) . '</th>';
        echo '<th>' . esc_html__( 'Slot', 'titan-zero-trades-front-desk' ) . '</th>';
        echo '<th>' . esc_html__( 'Customer', 'titan-zero-trades-front-desk' ) . '</th>';
        echo '<th>' . esc_html__( 'Service', 'titan-zero-trades-front-desk' ) . '</th>';
        echo '<th>' . esc_html__( 'Source', 'titan-zero-trades-front-desk' ) . '</th>';
        echo '<th>' . esc_html__( 'State', 'titan-zero-trades-front-desk' ) . '</th>';
        echo '<th>' . esc_html__( 'Reason', 'titan-zero-trades-front-desk' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'titan-zero-trades-front-desk' ) . '</th>';
        echo '</tr></thead><tbody>';
    }

    private static function notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display of a result code only; the action it reports was nonce-checked in handle().
        $code = is_string( $_GET['tz_fd_pl_msg'] ?? null ) ? sanitize_key( wp_unslash( $_GET['tz_fd_pl_msg'] ) ) : '';
        if ( '' === $code ) { return; }
        $messages = array(
            'done'          => array( 'success', __( 'Done.', 'titan-zero-trades-front-desk' ) ),
            'slot_taken'    => array( 'error', __( 'That time is already taken by another booking. Reject this one or ask the customer for another time.', 'titan-zero-trades-front-desk' ) ),
            'bad_transition'=> array( 'error', __( 'This booking cannot move to that state.', 'titan-zero-trades-front-desk' ) ),
            'busy'          => array( 'error', __( 'The calendar is busy. Try again in a moment.', 'titan-zero-trades-front-desk' ) ),
            'not_found'     => array( 'error', __( 'That booking was not found.', 'titan-zero-trades-front-desk' ) ),
            'unreadable'    => array( 'error', __( 'That booking could not be read.', 'titan-zero-trades-front-desk' ) ),
            'save_failed'   => array( 'error', __( 'The calendar could not be updated.', 'titan-zero-trades-front-desk' ) ),
        );
        $kind = isset( $messages[ $code ] ) ? $messages[ $code ] : array( 'error', __( 'That action did not work.', 'titan-zero-trades-front-desk' ) );
        echo '<div class="notice notice-' . esc_attr( $kind[0] ) . ' is-dismissible"><p>' . esc_html( $kind[1] ) . '</p></div>';
    }

    private static function who( $facts ) {
        if ( ! is_array( $facts ) ) { return '-'; }
        $parts = array_filter( array( (string) $facts['name'], (string) $facts['email'], (string) $facts['phone'] ), 'strlen' );
        return $parts ? implode( ' · ', $parts ) : '-';
    }

    private static function when( $facts ) {
        if ( ! is_array( $facts ) ) { return '-'; }
        return trim( $facts['date'] . ' ' . $facts['slot'] );
    }

    /** The state as a status tag: icon plus label. */
    private static function state_tag( $state ) {
        $icons = array(
            TZFD_Pipeline_Rules::PROCESSING => 'new',
            TZFD_Pipeline_Rules::PROCESSED  => 'waiting',
            TZFD_Pipeline_Rules::APPROVED   => 'confirmed',
            TZFD_Pipeline_Rules::ESCALATED  => 'needs-attention',
            TZFD_Pipeline_Rules::REJECTED   => 'cancelled',
        );
        $label = self::state_label( $state );
        return isset( $icons[ (string) $state ] ) ? TZFD_Local_Brand::status_tag( $icons[ (string) $state ], $label ) : esc_html( $label );
    }

    private static function state_label( $state ) {
        $labels = array(
            TZFD_Pipeline_Rules::PROCESSING => __( 'Being checked', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::PROCESSED  => __( 'Waiting for approval', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::APPROVED   => __( 'Approved', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::ESCALATED  => __( 'Needs staff', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::REJECTED   => __( 'Rejected', 'titan-zero-trades-front-desk' ),
        );
        return self::display_label( $state, isset( $labels[ (string) $state ] ) ? $labels[ (string) $state ] : (string) $state );
    }
}
