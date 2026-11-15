<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-request-scalar.php';
require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';
require_once __DIR__ . '/includes/class-tzfd-stored-data.php';
require_once __DIR__ . '/includes/class-tzfd-admin-filter-input.php';

require_once __DIR__ . '/includes/class-tzfd-lifecycle-rules.php';
require_once __DIR__ . '/includes/class-tzfd-admin-booking-access.php';
require_once __DIR__ . '/includes/class-tzfd-admin-mutation-request.php';
require_once __DIR__ . '/includes/class-tzfd-admin-action-result.php';
require_once __DIR__ . '/includes/class-tzfd-booking-delete-service.php';

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; no state change.
$this->item = TZFD_Admin_Request_Ids::calendar( $_GET );
if ( null === $this->item ) {
    wp_die( esc_html__( 'Invalid calendar identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}

require_once __DIR__ . '/includes/class-tzfd-admin-report-scope.php';
$tzfd_report_company = TZFD_Admin_Report_Scope::enforce( $this->item );

$current_user = wp_get_current_user();
$tz_current_user_access = current_user_can('tz_fd_access');

$tz_calendar_access_ids = TZFD_Access_List::decode(
    $this->get_option( 'tz_fd_user_access', serialize( array() ) )
);
if ( ! is_admin() || ( ! $tz_current_user_access && ! in_array( (int) $current_user->ID, $tz_calendar_access_ids, true ) ) )
{
    echo esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' );
    exit;
}

// Never execute a booking mutation from a GET URL, even when it contains a nonce.
foreach ( array( 'statusmark', 'resend', 'delmark', 'del', 'ld', 'ud', 'lu' ) as $tzfd_mutation_key ) {
    if ( array_key_exists( $tzfd_mutation_key, $_GET ) ) {
        wp_die( esc_html__( 'Booking changes require a POST submission.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
    }
}
$tzfd_request_method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) );
// Booking actions are POST-only: verify the booking nonce before any POST field is read.
if ( 'POST' === $tzfd_request_method && ! wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_booking' ) ) {
    wp_die( esc_html__( 'Invalid booking action or security token.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}
$tzfd_mutation_request = 'POST' === $tzfd_request_method ? $_POST : array();
if ( ! TZFD_Admin_Mutation_Request::validate( $tzfd_mutation_request ) ) {
    wp_die( esc_html__( 'Invalid booking action or security token.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}

// Authorize actual target rows before any bulk write, deletion or resend.
// The branch-specific CSRF nonce verification below remains required.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
if ( ! TZFD_Admin_Booking_Access::authorize_request( $tzfd_mutation_request, $this->item ) ) {
    wp_die( esc_html__( 'You are not allowed to change these bookings.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination parameter; no state change.
$tz_current_page = TZFD_Admin_Request_Ids::page( $_GET );
if ( null === $tz_current_page ) {
    wp_die( esc_html__( 'Invalid page number.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}
$tz_records_per_page = 50;

$tz_message = "";

if (isset($_POST['statusmark']) && tzfd_request_text( 'post', 'statusmark' ) != '')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tz_raw_status = isset($_POST['sbmi']) ? tzfd_request_text( 'post', 'sbmi' ) : null;
    $status = is_string($tz_raw_status) ? $tz_raw_status : null;

    if ( ! TZFD_Lifecycle_Rules::is_allowed_admin_status( $status, $this->get_status_list() ) ) {
        $tz_message = __('Invalid booking status; no bookings were changed.','titan-zero-trades-front-desk');
    } else {
        $tzfd_selected = 0;
        $tzfd_updated = 0;
        $tzfd_failed = 0;
        for ( $tz_i = 0; $tz_i <= $tz_records_per_page; $tz_i++ ) {
            $tz_c_value = tzfd_request_text( 'post', 'c' . $tz_i );
            if ( '' === $tz_c_value ) { continue; }
            $tzfd_selected++;
            if ( true === $this->update_status( absint( $tz_c_value ), $status ) ) {
                $tzfd_updated++;
            } else {
                $tzfd_failed++;
            }
        }
        $tz_message = TZFD_Admin_Action_Result::bulk_status_message( $tzfd_selected, $tzfd_updated, $tzfd_failed );
    }
}
else if (isset($_POST['resend']) && tzfd_request_text( 'post', 'resend' ) != '')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tzfd_resend_result = $this->ready_to_go_reservation( absint( tzfd_request_text( 'post', 'resend' ) ), '', true );
    $tz_message = false === $tzfd_resend_result
        ? __( 'Unable to process notification resend for this booking.', 'titan-zero-trades-front-desk' )
        : __( 'Notification resend request processed; delivery is not guaranteed.', 'titan-zero-trades-front-desk' );
}
else if (isset($_POST['delmark']) && tzfd_request_text( 'post', 'delmark' ) != '')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tz_selected_ids = array();
    for ( $tz_i = 0; $tz_i <= $tz_records_per_page; $tz_i++ ) {
        $tz_c_value = tzfd_request_text( 'post', 'c' . $tz_i );
        if ( '' !== $tz_c_value ) {
            $tz_selected_ids[] = $tz_c_value; // Authorised and validated above.
        }
    }
    $tz_delete_result = TZFD_Booking_Delete_Service::delete_selected( $tz_selected_ids, $this->item, (int) TZFD_Scoped_Store::company_id(), $wpdb );
    $tz_message = TZFD_Admin_Action_Result::bulk_delete_message( $tz_delete_result );
    if ( $tz_delete_result['deleted'] > 0 ) {
        do_action( 'tz_fd_cache_clean', $this->item );
    }
}
else if (isset($_POST['del']) && tzfd_request_text( 'post', 'del' ) == 'all')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tz_company = (int) TZFD_Scoped_Store::company_id(); // Company-scoped: "delete all" removes only this company's bookings.
    if ( $tz_company > 0 ) {
        $tz_table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        if ( $this->item == '' || $this->item == '0' ) {
            $tz_result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE company_id=%d', $tz_table, $tz_company ) );
        } else {
            $tz_result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE formid=%d AND company_id=%d', $tz_table, absint( $this->item ), $tz_company ) );
        }
        if ( false === $tz_result || ! empty( $wpdb->last_error ) ) {
            $tz_message = __( 'Unable to delete bookings; the database did not confirm the operation.', 'titan-zero-trades-front-desk' );
        } else {
            $tz_message = __( 'All selected bookings deleted', 'titan-zero-trades-front-desk' );
            do_action( 'tz_fd_cache_clean', $this->item );
        }
    } else {
        $tz_message = __( 'The business context is unavailable; no bookings were deleted.', 'titan-zero-trades-front-desk' );
    }
}
// Payment status is owned by Titan Zero Invoicing (19 Oct split). Front Desk no longer writes a paid flag.
else if (isset($_POST['ld']) && tzfd_request_text( 'post', 'ld' ) != '')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tz_delete_result = TZFD_Booking_Delete_Service::delete_selected(
        array( tzfd_request_text( 'post', 'ld' ) ), $this->item, (int) TZFD_Scoped_Store::company_id(), $wpdb
    );
    $tz_message = TZFD_Admin_Action_Result::single_delete_message( $tz_delete_result );
    if ( $tz_delete_result['deleted'] > 0 ) {
        do_action( 'tz_fd_cache_clean', $this->item );
    }
}
else if (isset($_POST['ud']) && tzfd_request_text( 'post', 'ud' ) != '')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tz_raw_status = isset($_POST['status']) ? tzfd_request_text( 'post', 'status' ) : null;
    $status = is_string($tz_raw_status) ? $tz_raw_status : null;

    if ( ! TZFD_Lifecycle_Rules::is_allowed_admin_status( $status, $this->get_status_list() ) ) {
        $tz_message = __('Invalid booking status; no change was made.','titan-zero-trades-front-desk');
    } else {
        $tzfd_status_updated = isset( $_POST['udidx'] )
            ? $this->update_status( absint( tzfd_request_text( 'post', 'ud' ) ), $status, absint( tzfd_request_text( 'post', 'udidx' ) ) )
            : $this->update_status( absint( tzfd_request_text( 'post', 'ud' ) ), $status );
        $tz_message = true === $tzfd_status_updated
            ? __( 'Status updated', 'titan-zero-trades-front-desk' )
            : __( 'Status was not changed; verify the booking and its current status.', 'titan-zero-trades-front-desk' );

        if ( true === $tzfd_status_updated && ! empty( $_POST['or'] ) && tzfd_request_text( 'post', 'or' ) === 'shlist' )
        {
          $tz_go = 'admin.php?page=' . $this->menu_parameter . '&schedule=1&cal=' . intval(tzfd_request_text( 'get', "cal" )) . '#sb' . intval(tzfd_request_text( 'post', "ud" )) . '_' . intval(tzfd_request_text( 'post', "udidx" ));
          if ( ! headers_sent() ) {
              wp_safe_redirect( admin_url( $tz_go ) );
          } else {
              printf( '<meta http-equiv="refresh" content="0;url=%s" />', esc_url( admin_url( $tz_go ) ) );
          }
          exit;
        }
    }
}

if ($this->item != 0)
    $tz_myform = $wpdb->get_results( $wpdb->prepare('SELECT * FROM %i WHERE id=%d AND company_id=%d', $wpdb->prefix . TZFD_Tables::FORMS, $this->item, TZFD_Scoped_Store::company_id()) ); 

list( $tz_valid_from, $tz_valid_to ) = TZFD_Admin_Filter_Input::enforce( $_GET, $this->get_option( 'date_format', 'mm/dd/yy' ) );
$tz_rawfrom = TZFD_Admin_Filter_Input::text( $_GET, 'dfrom', 80 );
$tz_rawto = TZFD_Admin_Filter_Input::text( $_GET, 'dto', 80 );
$tz_raw_search = TZFD_Admin_Filter_Input::text( $_GET, 'search', 200 );
if ( null === $tz_raw_search ) {
    wp_die( esc_html__( 'Invalid search filter.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}
$tz_search_on = ( '' !== $tz_raw_search ) ? 1 : 0;
$tz_search    = '';
if ( $tz_search_on ) {
    $search      = $tz_raw_search;
    $tz_search = '%' . $wpdb->esc_like( $search ) . '%';
}


$tz_from_on = null !== $tz_valid_from ? 1 : 0;
$tz_from = $tz_valid_from ?? '';
$tz_to_on = null !== $tz_valid_to ? 1 : 0;
$tz_to = ( $tz_valid_to ?? '' ) . ' 23:59:59';

$tz_form_on = ( $this->item != 0 ) ? 1 : 0;
$tz_form_id = (int) $this->item;

$tz_table_name = $wpdb->prefix . TZFD_Tables::BOOKINGS;
// First Query: Pagination Count
$tz_eventscount = $wpdb->get_results( $wpdb->prepare(
    "SELECT count(id) as ck FROM %i WHERE company_id=%d
    AND ( %d = 0 OR (data LIKE %s OR posted_data LIKE %s) )
    AND ( %d = 0 OR (`time` >= %s) )
    AND ( %d = 0 OR (`time` <= %s) )
    AND ( %d = 0 OR formid = %d )",
    $tz_table_name, $tzfd_report_company, $tz_search_on, $tz_search, $tz_search, $tz_from_on, $tz_from, $tz_to_on, $tz_to, $tz_form_on, $tz_form_id
) );

if ( ! is_array( $tz_eventscount ) || ! isset( $tz_eventscount[0]->ck ) || ! empty( $wpdb->last_error ) ) {
    wp_die( esc_html__( 'Unable to read booking totals.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) );
}
$tz_total_pages = ceil( max( 0, (int) $tz_eventscount[0]->ck ) / $tz_records_per_page );

$tz_offset = ( $tz_current_page - 1 ) * $tz_records_per_page;

// Second Query: Items
$tz_events = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM %i WHERE company_id=%d
    AND ( %d = 0 OR (data LIKE %s OR posted_data LIKE %s) )
    AND ( %d = 0 OR (`time` >= %s) )
    AND ( %d = 0 OR (`time` <= %s) )
    AND ( %d = 0 OR formid = %d )
    ORDER BY `time` DESC LIMIT %d, %d",
    $tz_table_name, $tzfd_report_company, $tz_search_on, $tz_search, $tz_search, $tz_from_on, $tz_from, $tz_to_on, $tz_to, $tz_form_on, $tz_form_id, $tz_offset, $tz_records_per_page
) );
if ( ! is_array( $tz_events ) || ! empty( $wpdb->last_error ) ) {
    wp_die( esc_html__( 'Unable to read bookings.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) );
}
$tz_nonce = wp_create_nonce( 'tz_fd_actions_booking' );

?>
<div class="wrap tzfd-admin-page tzfd-page tzfd-page-bookings">
    <h1 class="wp-heading-inline"><?php esc_html_e('Booking Orders','titan-zero-trades-front-desk'); ?> - <?php if ($this->item != 0) echo esc_html( ! empty( $tz_myform ) ? $tz_myform[0]->form_name : __( 'Calendar not found', 'titan-zero-trades-front-desk' ) ); else echo esc_html__( 'All forms', 'titan-zero-trades-front-desk' ); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="page-title-action">&larr; <?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
    <hr class="wp-header-end">
    <?php if ( $this->item != 0 && empty( $tz_myform ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'This calendar was not found for the current company.', 'titan-zero-trades-front-desk' ) . '</p></div>'; } ?>

    <?php if ($tz_message && !isset($_POST['ud'])) echo "<div id='setting-error-settings_updated' class='updated notice is-dismissible'><p><strong>".esc_html($tz_message)."</strong></p></div>"; ?>

<?php
    // Bookings list behaviour lives in js/inline/. Page values reach it through wp_localize_script().
    wp_enqueue_script( 'tzfd-adminb-message-paging', plugins_url( 'js/inline/adminb-message-paging.js', __FILE__ ), array(), '2.0.1', true );
    wp_enqueue_script( 'tzfd-adminb-message-list', plugins_url( 'js/inline/adminb-message-list.js', __FILE__ ), array( 'jquery' ), '2.0.1', true );
    wp_localize_script( 'tzfd-adminb-message-list', 'tzfdAdminBList', array(
        'page'    => $this->menu_parameter,
        'nonce'   => $tz_nonce,
        'cal'     => intval(tzfd_request_text( 'get', "cal" )),
        'strings' => array(
            'confirmResend' => __( 'Are you sure that you want to resend the notification emails for this item?', 'titan-zero-trades-front-desk' ),
            'confirmDelete' => __( 'Are you sure that you want to delete this item?', 'titan-zero-trades-front-desk' ),
            'sending'       => __( 'Sending...', 'titan-zero-trades-front-desk' ),
            'sent'          => __( 'Sent!', 'titan-zero-trades-front-desk' ),
        ),
    ) );
    ?>

    <div class="tablenav top">
        <form action="admin.php" method="get" style="display:inline-block; margin-bottom: 10px;">
            <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_parameter); ?>" />
            <input type="hidden" name="cal" value="<?php echo esc_attr($this->item); ?>" />
            <input type="hidden" name="list" value="1" />
            <input type="hidden" name="anonce" value="<?php echo esc_attr( wp_create_nonce( 'tz_fd_actions_booking' ) ); ?>" />
            
            <div class="alignleft actions tzfdno-wrap-div">
                <select id="cal" name="cal" class="selectmh">
                    <?php if ($tz_current_user_access) { ?> <option value="0">[<?php esc_html_e('All Items','titan-zero-trades-front-desk'); ?>]</option><?php } ?>
                    <?php
                    $tz_myrows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id=%d', $wpdb->prefix . TZFD_Tables::FORMS, $tzfd_report_company ) );
                    $tz_saved_id = $this->item;
                    foreach ($tz_myrows as $tz_item) {
                        $this->setId($tz_item->id);
                        $tz_calendar_access_ids = TZFD_Access_List::decode(
                            $this->get_option( 'tz_fd_user_access', serialize( array() ) )
                        );
                        if ( $tz_current_user_access || in_array( (int) $current_user->ID, $tz_calendar_access_ids, true ) )
                            echo '<option value="'.esc_attr($tz_item->id).'"'.(intval($tz_item->id)==intval($tz_saved_id)?" selected":"").'>'.esc_html($tz_item->form_name).'</option>';
                    }
                    $this->setId($tz_saved_id);
                    ?>
                </select>
                
                <input  type="search" name="search" placeholder="<?php esc_attr_e('Search...','titan-zero-trades-front-desk'); ?>" value="<?php echo esc_attr($tz_raw_search); ?>">
                <input autocomplete="off" type="date" id="dfrom" name="dfrom" placeholder="<?php esc_attr_e('From','titan-zero-trades-front-desk'); ?>" value="<?php echo esc_attr($tz_rawfrom); ?>" size="10">
                <input autocomplete="off" type="date" id="dto" name="dto" placeholder="<?php esc_attr_e('To','titan-zero-trades-front-desk'); ?>" value="<?php echo esc_attr($tz_rawto); ?>" size="10">
                
                <input type="submit" name="ds" value="<?php esc_html_e('Filter','titan-zero-trades-front-desk'); ?>" class="button buttonnf">
                <input type="submit" name="<?php echo esc_attr($this->prefix); ?>_csv1" value="<?php esc_html_e('Export CSV','titan-zero-trades-front-desk'); ?>" class="button buttonnf">
            </div>
        </form>


        <div class="tablenav-pages tzfd-wp-pagination<?php if($tz_total_pages <= 1) echo ' one-page'; ?>">
            <?php if ( $tz_total_pages > 0 ) : ?>
                <span class="displaying-num"><?php
	/* translators: %s: Number of items. */
	echo esc_html( sprintf( _n( '%s item', '%s items', $tz_eventscount[0]->ck, 'titan-zero-trades-front-desk' ), number_format_i18n( $tz_eventscount[0]->ck ) ) );
	?></span>
                
                <?php 
                // Build the base URL for the pagination links
                $tz_base_url = 'admin.php?page='.sanitize_key($this->menu_parameter).'&cal='.intval($this->item).'&list=1&dfrom='.rawurlencode(sanitize_text_field($tz_rawfrom)).'&dto='.rawurlencode(sanitize_text_field($tz_rawto)).'&search='.rawurlencode($this->clean_sanitize($tz_raw_search));
                ?>
                <span class="pagination-links">
                    <?php if ( $tz_current_page == 1 ) : ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
                    <?php else : ?>
                        <a class="first-page button" href="<?php echo esc_url($tz_base_url . '&p=1'); ?>"><span class="screen-reader-text"><?php esc_html_e( 'First page', 'titan-zero-trades-front-desk' ); ?></span><span aria-hidden="true">&laquo;</span></a>
                        <a class="prev-page button" href="<?php echo esc_url($tz_base_url . '&p=' . ($tz_current_page - 1)); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'titan-zero-trades-front-desk' ); ?></span><span aria-hidden="true">&lsaquo;</span></a>
                    <?php endif; ?>

                    <span class="paging-input">
                        <label for="current-page-selector" class="screen-reader-text"><?php esc_html_e( 'Current Page', 'titan-zero-trades-front-desk' ); ?></label>
                        <input class="current-page" id="current-page-selector" type="text" name="p" value="<?php echo esc_attr( $tz_current_page ); ?>" size="<?php echo intval(strlen( (string) $tz_total_pages )); ?>" aria-describedby="table-paging" data-base-url="<?php echo esc_url( $tz_base_url ); ?>">
                        <span class="tablenav-paging-text"> <?php esc_html_e( 'of', 'titan-zero-trades-front-desk' ); ?> <span class="total-pages"><?php echo esc_html( number_format_i18n( $tz_total_pages ) ); ?></span></span>
                    </span>

                    <?php if ( $tz_current_page == $tz_total_pages ) : ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
                    <?php else : ?>
                        <a class="next-page button" href="<?php echo esc_url($tz_base_url . '&p=' . ($tz_current_page + 1)); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Next page', 'titan-zero-trades-front-desk' ); ?></span><span aria-hidden="true">&rsaquo;</span></a>
                        <a class="last-page button" href="<?php echo esc_url($tz_base_url . '&p=' . $tz_total_pages); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Last page', 'titan-zero-trades-front-desk' ); ?></span><span aria-hidden="true">&raquo;</span></a>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="clear"></div>
    <div id="dex_printable_contents">
        <form name="dex_table_form" id="dex_table_form" action="<?php echo esc_url( admin_url( 'admin.php?page=' . rawurlencode( $this->menu_parameter ) . '&cal=' . (int) $this->item . '&list=1' ) ); ?>" method="post">
            <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_parameter); ?>" />
            <input type="hidden" name="cal" value="<?php echo intval(tzfd_request_text( 'get', "cal" )); ?>" />
            <input type="hidden" name="list" value="1" />
            <input type="hidden" name="anonce" value="<?php echo esc_attr( wp_create_nonce( 'tz_fd_actions_booking' ) ); ?>" />
            <input type="hidden" name="delmark" value="1" />
            <input type="hidden" name="statusmark" value="" />
            <input type="hidden" name="sbmi" value="" /> 
            <input type="hidden" name="anonce" value="<?php echo esc_attr($tz_nonce); ?>" />
            <div class="tzfd-orderssection-container">
            <table class="wp-list-table widefat fixed striped tzfd-orders-list">
                <thead>
                <tr>
                    <td id="cb" class="manage-column column-cb check-column"><input type="checkbox" name="tzfdcontrolck" id="tzfdcontrolck" value="" data-tzfd-action="mark-all"></td>
                    <th scope="col" class="manage-column column-id" ><?php esc_html_e('ID','titan-zero-trades-front-desk'); ?></th>
                    <th scope="col" class="manage-column column-date" ><?php esc_html_e('Submission Date','titan-zero-trades-front-desk'); ?></th>
                    <th scope="col" class="manage-column column-email" ><?php esc_html_e('Email','titan-zero-trades-front-desk'); ?></th>
                    <th scope="col" class="manage-column column-message" ><?php esc_html_e('Message','titan-zero-trades-front-desk'); ?></th>
                    <th scope="col" class="manage-column column-options tzfdnopr"><?php esc_html_e('Actions','titan-zero-trades-front-desk'); ?></th>
                </tr>
                </thead>
                <tbody id="the-list">
                 <?php for ($tz_i=0; $tz_i<$tz_records_per_page; $tz_i++) if (isset($tz_events[$tz_i])) {
                          $tz_posted_data = TZFD_Stored_Data::decode_booking($tz_events[$tz_i]->posted_data);
                          $tz_cancelled = 0;
                          $status = '';
                          if (!is_array($tz_posted_data)) $tz_posted_data = array();
                          if (!isset($tz_posted_data["apps"]) || !is_array($tz_posted_data["apps"])) $tz_posted_data["apps"] = array();
                          for($tz_k=0; $tz_k<count($tz_posted_data["apps"]); $tz_k++)
                              if (isset($tz_posted_data["apps"][$tz_k]["cancelled"]) && $tz_posted_data["apps"][$tz_k]["cancelled"] != '') {
                                  $tz_cancelled++;
                                  $status = $tz_posted_data["apps"][$tz_k]["cancelled"];
                              }
                          if ($tz_cancelled && $tz_cancelled != count($tz_posted_data["apps"])) 
                             $status = '';                    
                 ?>
                  <tr class="<?php if ( $tz_cancelled && $tz_cancelled == count( $tz_posted_data["apps"] ) && $status != 'Attended') { echo 'tz_fd_cancelled'; } ?>" valign="top">
                    <th scope="row" class="check-column"><input type="checkbox" name="c<?php echo intval($tz_i); ?>" value="<?php echo intval($tz_events[$tz_i]->id); ?>" /></th>
                    <td><?php echo intval($tz_events[$tz_i]->id); ?></td>
                    <td><?php echo esc_html($this->format_date(substr($tz_events[$tz_i]->time,0,16)).gmdate(" H:i",strtotime($tz_events[$tz_i]->time))); ?></td>
                    <td><a href="<?php echo esc_url( 'mailto:' . sanitize_email($tz_events[$tz_i]->notifyto) ); ?>"><?php echo esc_html(sanitize_email($tz_events[$tz_i]->notifyto)); ?></a></td>
                    <td class="tzfdmessage">
                      <div class="tzfdmessagemw">
                        <?php
                        if ( $tz_cancelled && $tz_cancelled != count( $tz_posted_data["apps"] ) ) echo '<div class="notice notice-warning inline"><p><strong>* '.esc_html(__('Contains','titan-zero-trades-front-desk')).' '.esc_html($tz_cancelled).' '.esc_html(__('non-approved or cancelled dates','titan-zero-trades-front-desk')).'. <a href="'.esc_url( add_query_arg( array( 'page' => sanitize_key( $this->menu_parameter ), 'cal' => absint( $this->item ), 'schedule' => 1 ), admin_url( 'admin.php' ) ) ).'">'.esc_html(__('See details in schedule','titan-zero-trades-front-desk')).'</a>.</strong></p></div>';
                        
                        $tz_raw_message = isset( $tz_events[$tz_i]->data ) && is_scalar( $tz_events[$tz_i]->data )
                            ? (string) $tz_events[$tz_i]->data : '';
                        $tz_data = nl2br( esc_html( $tz_raw_message ) );
                        // Legacy attachment entries use a matching <field> and <field>_url pair.
                        // Reject malformed stored values; never derive an HTML attribute from arrays.
                        foreach ( $tz_posted_data as $tz_item => $tz_value ) {
                            if ( ! is_string( $tz_item ) || substr( $tz_item, -4 ) !== '_url' ) { continue; }
                            $tz_base_key = substr( $tz_item, 0, -4 );
                            if ( '' === $tz_base_key || ! isset( $tz_posted_data[ $tz_base_key ] ) ||
                                ! is_scalar( $tz_posted_data[ $tz_base_key ] ) ) { continue; }
                            $tz_label = (string) $tz_posted_data[ $tz_base_key ];
                            $tz_url = is_array( $tz_value ) && isset( $tz_value[0] ) && is_string( $tz_value[0] )
                                ? $tz_value[0] : ( is_string( $tz_value ) ? $tz_value : '' );
                            $tz_safe_url = esc_url( $tz_url, array( 'http', 'https' ) );
                            if ( '' === $tz_label || '' === $tz_safe_url ) { continue; }
                            $tz_data = str_replace(
                                esc_html( $tz_label ),
                                '<a href="' . esc_url( $tz_safe_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $tz_label ) . '</a><br />',
                                $tz_data
                            );
                        }
                        // Never reverse HTML escaping for stored messages. Any genuine image markup must be independently validated by wp_kses().
                        echo '<div class="tzfd-appointment-header">'; // style="display:none"
                        $tz_data = str_replace('<br /><br />', '</div><div>',$this->filter_allowed_tags(apply_filters( 'tz_fd_booking_orders_item', $tz_data, $tz_posted_data ))); 

                        $tz_appts = "";

                        foreach ( $tz_posted_data['apps'] as $tz_app ) {
                            if ( ! is_array( $tz_app ) ) { continue; }
                            // Booking data may predate the current schema or have been corrupted.
                            // Never pass arrays/objects to WordPress escaping or date functions.
                            $tz_date = isset( $tz_app['date'] ) && is_scalar( $tz_app['date'] ) ? (string) $tz_app['date'] : '';
                            $tz_slot = isset( $tz_app['slot'] ) && is_scalar( $tz_app['slot'] ) ? (string) $tz_app['slot'] : '';
                            $tz_service = isset( $tz_app['service'] ) && is_scalar( $tz_app['service'] ) ? (string) $tz_app['service'] : '';
                            $tz_quant = isset( $tz_app['quant'] ) && is_numeric( $tz_app['quant'] ) ? (int) $tz_app['quant'] : 0;
                            if ( '' === $tz_date || '' === $tz_slot ) { continue; }
                            $tz_appts .= '<div class="tzfd-appointment-badge">' .
                                '<span class="dashicons dashicons-clock"></span>' .
                                '<span class="tzfd-time">' . esc_html( $this->format_date( $tz_date ) ) . ' ' . esc_html( $tz_slot ) .
                                ( $tz_quant > 1 ? ' (' . esc_html( (string) $tz_quant ) . ')' : '' ) . '</span>' .
                                '<span class="tzfd-service">' . esc_html( $tz_service ) . '</span></div>';
                        }

                        
                        echo wp_kses( $tz_appts, array( 'div' => array( 'class' => true ), 'span' => array( 'class' => true ) ) ) . '</div><div style="display:none">' . wp_kses( $tz_data, $this->tags_allowed );
                        echo '</div>';
                        
                        
                        ?>
                       </div>
                    </td>
                    <td class="tzfdnopr">
                      <div style="margin-bottom: 5px;">
                          <?php $this->render_status_box('sb'.intval($tz_events[$tz_i]->id), $status); ?>
                      </div>
                    <div class="row-actions visible">
                          <span class="inline"><a href="#" data-tzfd-action="resend" data-id="<?php echo intval($tz_events[$tz_i]->id); ?>"><?php esc_html_e('Resend Email','titan-zero-trades-front-desk'); ?></a> | </span>
                          <span class="trash"><a href="#" data-tzfd-action="delete-row" data-id="<?php echo intval($tz_events[$tz_i]->id); ?>" class="submitdelete"><?php esc_html_e('Delete','titan-zero-trades-front-desk'); ?></a></span>
                      </div>                      
                    </td>
                  </tr>
                 <?php } ?>
                </tbody>
            </table>
            </div>
            
            <div class="tzfdnoprint tablenav bottom" style="margin-top: 15px;">
                <div class="alignleft actions">
                    <?php $this->render_status_box('statusbox_markeditems', ''); ?>
                    <input class="button" type="button" name="pbutton" value="<?php esc_html_e('Apply Status','titan-zero-trades-front-desk'); ?>" data-tzfd-action="apply-status" />
                    <input class="button" type="button" name="pbutton" value="<?php esc_html_e('Delete Selected','titan-zero-trades-front-desk'); ?>" data-tzfd-action="delete-marked" style="margin-left: 10px;" /> 
                </div>
                <div class="alignright actions">
                    <input class="button" type="button" value="<?php esc_html_e('Print List','titan-zero-trades-front-desk'); ?>" data-tzfd-action="print" style="margin-right:20px;" />
                    <input class="button button-link-delete" type="button" name="pbutton" value="<?php esc_html_e('Delete ALL Bookings','titan-zero-trades-front-desk'); ?>" data-tzfd-action="delete-all" />
                </div>
                <div class="clear"></div>
            </div>
        </form>
    </div>
</div>

