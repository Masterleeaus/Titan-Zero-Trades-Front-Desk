<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-pipeline.php';

/**
 * Front Desk > Pipeline: the staff queue for bookings the checks have not approved, and 30-day telemetry.
 * Each action is a POST to admin-post.php with its own nonce, and needs the same capability as Bookings.
 */
final class TZFD_Pipeline_Screen {

    const PAGE   = 'tzfd_pipeline';
    const ACTION = 'tzfd_pipeline_action';
    const CAP    = 'edit_pages';
    const PARENT = 'tzfd';

    public static function register() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 112 );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            self::PARENT,
            __( 'Pipeline', 'titan-zero-trades-front-desk' ),
            __( 'Pipeline', 'titan-zero-trades-front-desk' ),
            self::CAP,
            self::PAGE,
            array( __CLASS__, 'render' )
        );
    }

    public static function render() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'titan-zero-trades-front-desk' ) );
        }
        global $wpdb;
        $table = $wpdb->prefix . TZFD_Pipeline::TABLE_STATE;

        $open = $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_id, formid, state, reason, source, attempts, updated FROM %i WHERE state IN (%s, %s, %s) ORDER BY updated DESC LIMIT %d",
            $table,
            TZFD_Pipeline_Rules::PROCESSING, TZFD_Pipeline_Rules::PROCESSED, TZFD_Pipeline_Rules::ESCALATED, 200
        ) );
        $done = $wpdb->get_results( $wpdb->prepare(
            "SELECT booking_id, formid, state, reason, source, updated FROM %i WHERE state IN (%s, %s) ORDER BY updated DESC LIMIT %d",
            $table, TZFD_Pipeline_Rules::APPROVED, TZFD_Pipeline_Rules::REJECTED, 20
        ) );
        $stats = TZFD_Pipeline::stats( 30 );

        echo '<div class="wrap"><h1>' . esc_html__( 'Pipeline', 'titan-zero-trades-front-desk' ) . '</h1>';
        self::notice();

        echo '<p>' . esc_html__( 'Bookings from the assistant and channels are checked here before they are confirmed. Approve a booking to confirm it, reject it, or ask for another check.', 'titan-zero-trades-front-desk' ) . '</p>';

        echo '<h2>' . esc_html__( 'Last 30 days', 'titan-zero-trades-front-desk' ) . '</h2><ul>';
        $labels = array(
            TZFD_Pipeline_Rules::PROCESSING => __( 'Being checked', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::PROCESSED  => __( 'Waiting for approval', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::APPROVED   => __( 'Approved', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::ESCALATED  => __( 'Needs staff', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::REJECTED   => __( 'Rejected', 'titan-zero-trades-front-desk' ),
        );
        foreach ( $labels as $state => $label ) {
            $n = isset( $stats['counts'][ $state ] ) ? (int) $stats['counts'][ $state ] : 0;
            echo '<li>' . esc_html( $label ) . ': ' . (int) $n . '</li>';
        }
        $avg = null === $stats['avg_minutes_to_approve'] ? '-' : (string) $stats['avg_minutes_to_approve'];
        echo '<li>' . esc_html__( 'Average minutes from booking to approval', 'titan-zero-trades-front-desk' ) . ': ' . esc_html( $avg ) . '</li></ul>';

        echo '<h2>' . esc_html__( 'Open', 'titan-zero-trades-front-desk' ) . '</h2>';
        if ( empty( $open ) ) {
            echo '<p>' . esc_html__( 'Nothing is waiting.', 'titan-zero-trades-front-desk' ) . '</p>';
        } else {
            self::table_start();
            foreach ( $open as $row ) { self::open_row( $row ); }
            echo '</tbody></table>';
        }

        echo '<h2>' . esc_html__( 'Recently decided', 'titan-zero-trades-front-desk' ) . '</h2>';
        if ( empty( $done ) ) {
            echo '<p>' . esc_html__( 'None yet.', 'titan-zero-trades-front-desk' ) . '</p>';
        } else {
            self::table_start();
            foreach ( $done as $row ) {
                $facts = TZFD_Pipeline::details( (int) $row->booking_id );
                echo '<tr><td>#' . (int) $row->booking_id . '</td><td>' . esc_html( self::who( $facts ) ) . '</td>';
                echo '<td>' . esc_html( self::when( $facts ) ) . '</td>';
                echo '<td>' . wp_kses( self::state_tag( $row->state ), TZFD_Brand::allowed_html() ) . '</td>';
                echo '<td>' . esc_html( (string) $row->reason ) . '</td><td></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    public static function handle() {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( esc_html__( 'You do not have permission to do that.', 'titan-zero-trades-front-desk' ) );
        }
        $id = isset( $_POST['booking_id'] ) ? absint( wp_unslash( $_POST['booking_id'] ) ) : 0;
        check_admin_referer( 'tzfd_pipeline_' . $id, '_tzfd_pipeline' );

        $do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
        $actor = 'staff:' . get_current_user_id();
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
        $code = ! empty( $result['ok'] ) ? 'done' : (string) ( $result['error'] ?? 'failed' );
        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'tzfd_pl_msg' => $code ), admin_url( 'admin.php' ) ) );
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
        echo '<td>' . wp_kses( self::state_tag( $row->state ), TZFD_Brand::allowed_html() ) . '</td>';
        echo '<td>' . esc_html( (string) $row->reason ) . '</td><td>';

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:2px">';
        wp_nonce_field( 'tzfd_pipeline_' . $id, '_tzfd_pipeline' );
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
        $code = isset( $_GET['tzfd_pl_msg'] ) ? sanitize_key( wp_unslash( $_GET['tzfd_pl_msg'] ) ) : '';
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
        return isset( $icons[ (string) $state ] ) ? TZFD_Brand::status_tag( $icons[ (string) $state ], $label ) : esc_html( $label );
    }

    private static function state_label( $state ) {
        $labels = array(
            TZFD_Pipeline_Rules::PROCESSING => __( 'Being checked', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::PROCESSED  => __( 'Waiting for approval', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::APPROVED   => __( 'Approved', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::ESCALATED  => __( 'Needs staff', 'titan-zero-trades-front-desk' ),
            TZFD_Pipeline_Rules::REJECTED   => __( 'Rejected', 'titan-zero-trades-front-desk' ),
        );
        return isset( $labels[ (string) $state ] ) ? $labels[ (string) $state ] : (string) $state;
    }
}
