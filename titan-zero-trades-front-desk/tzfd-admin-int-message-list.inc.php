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
$tzfd_current_user_access = current_user_can('tz_fd_access');

$tzfd_calendar_access_ids = TZFD_Access_List::decode(
    $this->get_option( 'tz_fd_user_access', serialize( array() ) )
);
if ( ! is_admin() || ( ! $tzfd_current_user_access && ! in_array( (int) $current_user->ID, $tzfd_calendar_access_ids, true ) ) )
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
$tzfd_current_page = TZFD_Admin_Request_Ids::page( $_GET );
if ( null === $tzfd_current_page ) {
    wp_die( esc_html__( 'Invalid page number.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}
$tzfd_records_per_page = 50;

$tzfd_message = "";

if (isset($_POST['statusmark']) && tzfd_request_text( 'post', 'statusmark' ) != '')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tzfd_raw_status = isset($_POST['sbmi']) ? tzfd_request_text( 'post', 'sbmi' ) : null;
    $status = is_string($tzfd_raw_status) ? $tzfd_raw_status : null;

    if ( ! TZFD_Lifecycle_Rules::is_allowed_admin_status( $status, $this->get_status_list() ) ) {
        $tzfd_message = __('Invalid booking status; no bookings were changed.','titan-zero-trades-front-desk');
    } else {
        $tzfd_selected = 0;
        $tzfd_updated = 0;
        $tzfd_failed = 0;
        for ( $tzfd_i = 0; $tzfd_i <= $tzfd_records_per_page; $tzfd_i++ ) {
            $tzfd_c_value = tzfd_request_text( 'post', 'c' . $tzfd_i );
            if ( '' === $tzfd_c_value ) { continue; }
            $tzfd_selected++;
            if ( true === $this->update_status( absint( $tzfd_c_value ), $status ) ) {
                $tzfd_updated++;
            } else {
                $tzfd_failed++;
            }
        }
        $tzfd_message = TZFD_Admin_Action_Result::bulk_status_message( $tzfd_selected, $tzfd_updated, $tzfd_failed );
    }
}
else if (isset($_POST['resend']) && tzfd_request_text( 'post', 'resend' ) != '')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tzfd_resend_result = $this->ready_to_go_reservation( absint( tzfd_request_text( 'post', 'resend' ) ), '', true );
    $tzfd_message = false === $tzfd_resend_result
        ? __( 'Unable to process notification resend for this booking.', 'titan-zero-trades-front-desk' )
        : __( 'Notification resend request processed; delivery is not guaranteed.', 'titan-zero-trades-front-desk' );
}
else if (isset($_POST['delmark']) && tzfd_request_text( 'post', 'delmark' ) != '')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tzfd_selected_ids = array();
    for ( $tzfd_i = 0; $tzfd_i <= $tzfd_records_per_page; $tzfd_i++ ) {
        $tzfd_c_value = tzfd_request_text( 'post', 'c' . $tzfd_i );
        if ( '' !== $tzfd_c_value ) {
            $tzfd_selected_ids[] = $tzfd_c_value; // Authorised and validated above.
        }
    }
    $tzfd_delete_result = TZFD_Booking_Delete_Service::delete_selected( $tzfd_selected_ids, $this->item, (int) TZFD_Scoped_Store::company_id(), $wpdb );
    $tzfd_message = TZFD_Admin_Action_Result::bulk_delete_message( $tzfd_delete_result );
    if ( $tzfd_delete_result['deleted'] > 0 ) {
        do_action( 'tz_fd_cache_clean', $this->item );
    }
}
else if (isset($_POST['del']) && tzfd_request_text( 'post', 'del' ) == 'all')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tzfd_local_company = (int) TZFD_Scoped_Store::company_id(); // Company-scoped: "delete all" removes only this company's bookings.
    if ( $tzfd_local_company > 0 ) {
        $tzfd_table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
        if ( $this->item == '' || $this->item == '0' ) {
            $tzfd_result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE company_id=%d', $tzfd_table, $tzfd_local_company ) );
        } else {
            $tzfd_result = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE formid=%d AND company_id=%d', $tzfd_table, absint( $this->item ), $tzfd_local_company ) );
        }
        if ( false === $tzfd_result || ! empty( $wpdb->last_error ) ) {
            $tzfd_message = __( 'Unable to delete bookings; the database did not confirm the operation.', 'titan-zero-trades-front-desk' );
        } else {
            $tzfd_message = __( 'All selected bookings deleted', 'titan-zero-trades-front-desk' );
            do_action( 'tz_fd_cache_clean', $this->item );
        }
    } else {
        $tzfd_message = __( 'The business context is unavailable; no bookings were deleted.', 'titan-zero-trades-front-desk' );
    }
}
// Payment status is owned by Titan Zero Invoicing (19 Oct split). Front Desk no longer writes a paid flag.
else if (isset($_POST['ld']) && tzfd_request_text( 'post', 'ld' ) != '')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tzfd_delete_result = TZFD_Booking_Delete_Service::delete_selected(
        array( tzfd_request_text( 'post', 'ld' ) ), $this->item, (int) TZFD_Scoped_Store::company_id(), $wpdb
    );
    $tzfd_message = TZFD_Admin_Action_Result::single_delete_message( $tzfd_delete_result );
    if ( $tzfd_delete_result['deleted'] > 0 ) {
        do_action( 'tz_fd_cache_clean', $this->item );
    }
}
else if (isset($_POST['ud']) && tzfd_request_text( 'post', 'ud' ) != '')
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_booking' ) ) { $this->nonce_failed(); }
    $tzfd_raw_status = isset($_POST['status']) ? tzfd_request_text( 'post', 'status' ) : null;
    $status = is_string($tzfd_raw_status) ? $tzfd_raw_status : null;

    if ( ! TZFD_Lifecycle_Rules::is_allowed_admin_status( $status, $this->get_status_list() ) ) {
        $tzfd_message = __('Invalid booking status; no change was made.','titan-zero-trades-front-desk');
    } else {
        $tzfd_status_updated = isset( $_POST['udidx'] )
            ? $this->update_status( absint( tzfd_request_text( 'post', 'ud' ) ), $status, absint( tzfd_request_text( 'post', 'udidx' ) ) )
            : $this->update_status( absint( tzfd_request_text( 'post', 'ud' ) ), $status );
        $tzfd_message = true === $tzfd_status_updated
            ? __( 'Status updated', 'titan-zero-trades-front-desk' )
            : __( 'Status was not changed; verify the booking and its current status.', 'titan-zero-trades-front-desk' );

        if ( true === $tzfd_status_updated && ! empty( $_POST['or'] ) && tzfd_request_text( 'post', 'or' ) === 'shlist' )
        {
          $tzfd_go = 'admin.php?page=' . $this->menu_parameter . '&schedule=1&cal=' . intval(tzfd_request_text( 'get', "cal" )) . '#sb' . intval(tzfd_request_text( 'post', "ud" )) . '_' . intval(tzfd_request_text( 'post', "udidx" ));
          if ( ! headers_sent() ) {
              wp_safe_redirect( admin_url( $tzfd_go ) );
          } else {
              printf( '<meta http-equiv="refresh" content="0;url=%s" />', esc_url( admin_url( $tzfd_go ) ) );
          }
          exit;
        }
    }
}

if ($this->item != 0)
    $tzfd_myform = $wpdb->get_results( $wpdb->prepare('SELECT * FROM %i WHERE id=%d AND company_id=%d', $wpdb->prefix . TZFD_Tables::FORMS, $this->item, TZFD_Scoped_Store::company_id()) ); 

list( $tzfd_valid_from, $tzfd_valid_to ) = TZFD_Admin_Filter_Input::enforce( $_GET, $this->get_option( 'date_format', 'mm/dd/yy' ) );
$tzfd_rawfrom = TZFD_Admin_Filter_Input::text( $_GET, 'dfrom', 80 );
$tzfd_rawto = TZFD_Admin_Filter_Input::text( $_GET, 'dto', 80 );
$tzfd_raw_search = TZFD_Admin_Filter_Input::text( $_GET, 'search', 200 );
if ( null === $tzfd_raw_search ) {
    wp_die( esc_html__( 'Invalid search filter.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}
$tzfd_search_on = ( '' !== $tzfd_raw_search ) ? 1 : 0;
$tzfd_search    = '';
if ( $tzfd_search_on ) {
    $search      = $tzfd_raw_search;
    $tzfd_search = '%' . $wpdb->esc_like( $search ) . '%';
}


$tzfd_from_on = null !== $tzfd_valid_from ? 1 : 0;
$tzfd_from = $tzfd_valid_from ?? '';
$tzfd_to_on = null !== $tzfd_valid_to ? 1 : 0;
$tzfd_to = ( $tzfd_valid_to ?? '' ) . ' 23:59:59';

$tzfd_form_on = ( $this->item != 0 ) ? 1 : 0;
$tzfd_local_form_id = (int) $this->item;

$tzfd_table_name = $wpdb->prefix . TZFD_Tables::BOOKINGS;
// First Query: Pagination Count
$tzfd_eventscount = $wpdb->get_results( $wpdb->prepare(
    "SELECT count(id) as ck FROM %i WHERE company_id=%d
    AND ( %d = 0 OR (data LIKE %s OR posted_data LIKE %s) )
    AND ( %d = 0 OR (`time` >= %s) )
    AND ( %d = 0 OR (`time` <= %s) )
    AND ( %d = 0 OR formid = %d )",
    $tzfd_table_name, $tzfd_report_company, $tzfd_search_on, $tzfd_search, $tzfd_search, $tzfd_from_on, $tzfd_from, $tzfd_to_on, $tzfd_to, $tzfd_form_on, $tzfd_local_form_id
) );

if ( ! is_array( $tzfd_eventscount ) || ! isset( $tzfd_eventscount[0]->ck ) || ! empty( $wpdb->last_error ) ) {
    wp_die( esc_html__( 'Unable to read booking totals.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) );
}
$tzfd_total_pages = ceil( max( 0, (int) $tzfd_eventscount[0]->ck ) / $tzfd_records_per_page );

$tzfd_offset = ( $tzfd_current_page - 1 ) * $tzfd_records_per_page;

// Second Query: Items
$tzfd_events = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM %i WHERE company_id=%d
    AND ( %d = 0 OR (data LIKE %s OR posted_data LIKE %s) )
    AND ( %d = 0 OR (`time` >= %s) )
    AND ( %d = 0 OR (`time` <= %s) )
    AND ( %d = 0 OR formid = %d )
    ORDER BY `time` DESC LIMIT %d, %d",
    $tzfd_table_name, $tzfd_report_company, $tzfd_search_on, $tzfd_search, $tzfd_search, $tzfd_from_on, $tzfd_from, $tzfd_to_on, $tzfd_to, $tzfd_form_on, $tzfd_local_form_id, $tzfd_offset, $tzfd_records_per_page
) );
if ( ! is_array( $tzfd_events ) || ! empty( $wpdb->last_error ) ) {
    wp_die( esc_html__( 'Unable to read bookings.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 503 ) );
}
$tzfd_nonce = wp_create_nonce( 'tz_fd_actions_booking' );

?>
<div class="wrap tzfd-admin-page tzfd-page tzfd-page-bookings">
    <h1 class="wp-heading-inline"><?php esc_html_e('Booking Orders','titan-zero-trades-front-desk'); ?> - <?php if ($this->item != 0) echo esc_html( ! empty( $tzfd_myform ) ? $tzfd_myform[0]->form_name : __( 'Calendar not found', 'titan-zero-trades-front-desk' ) ); else echo esc_html__( 'All forms', 'titan-zero-trades-front-desk' ); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="page-title-action">&larr; <?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
    <hr class="wp-header-end">
    <?php if ( $this->item != 0 && empty( $tzfd_myform ) ) { echo '<div class="notice notice-error"><p>' . esc_html__( 'This calendar was not found for the current company.', 'titan-zero-trades-front-desk' ) . '</p></div>'; } ?>

    <?php if ($tzfd_message && !isset($_POST['ud'])) echo "<div id='setting-error-settings_updated' class='updated notice is-dismissible'><p><strong>".esc_html($tzfd_message)."</strong></p></div>"; ?>

<?php
    // Bookings list behaviour lives in js/inline/. Page values reach it through wp_localize_script().
    wp_enqueue_script( 'tzfd-adminb-message-paging', plugins_url( 'js/inline/adminb-message-paging.js', __FILE__ ), array(), '2.0.1', true );
    wp_enqueue_script( 'tzfd-adminb-message-list', plugins_url( 'js/inline/adminb-message-list.js', __FILE__ ), array( 'jquery' ), '2.0.1', true );
    wp_localize_script( 'tzfd-adminb-message-list', 'tzfdAdminBList', array(
        'page'    => $this->menu_parameter,
        'nonce'   => $tzfd_nonce,
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
                    <?php if ($tzfd_current_user_access) { ?> <option value="0">[<?php esc_html_e('All Items','titan-zero-trades-front-desk'); ?>]</option><?php } ?>
                    <?php
                    $tzfd_myrows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id=%d', $wpdb->prefix . TZFD_Tables::FORMS, $tzfd_report_company ) );
                    $tzfd_saved_id = $this->item;
                    foreach ($tzfd_myrows as $tzfd_item) {
                        $this->setId($tzfd_item->id);
                        $tzfd_calendar_access_ids = TZFD_Access_List::decode(
                            $this->get_option( 'tz_fd_user_access', serialize( array() ) )
                        );
                        if ( $tzfd_current_user_access || in_array( (int) $current_user->ID, $tzfd_calendar_access_ids, true ) )
                            echo '<option value="'.esc_attr($tzfd_item->id).'"'.(intval($tzfd_item->id)==intval($tzfd_saved_id)?" selected":"").'>'.esc_html($tzfd_item->form_name).'</option>';
                    }
                    $this->setId($tzfd_saved_id);
                    ?>
                </select>
                
                <input  type="search" name="search" placeholder="<?php esc_attr_e('Search...','titan-zero-trades-front-desk'); ?>" value="<?php echo esc_attr($tzfd_raw_search); ?>">
                <input autocomplete="off" type="date" id="dfrom" name="dfrom" placeholder="<?php esc_attr_e('From','titan-zero-trades-front-desk'); ?>" value="<?php echo esc_attr($tzfd_rawfrom); ?>" size="10">
                <input autocomplete="off" type="date" id="dto" name="dto" placeholder="<?php esc_attr_e('To','titan-zero-trades-front-desk'); ?>" value="<?php echo esc_attr($tzfd_rawto); ?>" size="10">
                
                <input type="submit" name="ds" value="<?php esc_html_e('Filter','titan-zero-trades-front-desk'); ?>" class="button buttonnf">
                <input type="submit" name="<?php echo esc_attr($this->prefix); ?>_csv1" value="<?php esc_html_e('Export CSV','titan-zero-trades-front-desk'); ?>" class="button buttonnf">
            </div>
        </form>


        <div class="tablenav-pages tzfd-wp-pagination<?php if($tzfd_total_pages <= 1) echo ' one-page'; ?>">
            <?php if ( $tzfd_total_pages > 0 ) : ?>
                <span class="displaying-num"><?php
	/* translators: %s: Number of items. */
	echo esc_html( sprintf( _n( '%s item', '%s items', $tzfd_eventscount[0]->ck, 'titan-zero-trades-front-desk' ), number_format_i18n( $tzfd_eventscount[0]->ck ) ) );
	?></span>
                
                <?php 
                // Build the base URL for the pagination links
                $tzfd_base_url = 'admin.php?page='.sanitize_key($this->menu_parameter).'&cal='.intval($this->item).'&list=1&dfrom='.rawurlencode(sanitize_text_field($tzfd_rawfrom)).'&dto='.rawurlencode(sanitize_text_field($tzfd_rawto)).'&search='.rawurlencode($this->clean_sanitize($tzfd_raw_search));
                ?>
                <span class="pagination-links">
                    <?php if ( $tzfd_current_page == 1 ) : ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
                    <?php else : ?>
                        <a class="first-page button" href="<?php echo esc_url($tzfd_base_url . '&p=1'); ?>"><span class="screen-reader-text"><?php esc_html_e( 'First page', 'titan-zero-trades-front-desk' ); ?></span><span aria-hidden="true">&laquo;</span></a>
                        <a class="prev-page button" href="<?php echo esc_url($tzfd_base_url . '&p=' . ($tzfd_current_page - 1)); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'titan-zero-trades-front-desk' ); ?></span><span aria-hidden="true">&lsaquo;</span></a>
                    <?php endif; ?>

                    <span class="paging-input">
                        <label for="current-page-selector" class="screen-reader-text"><?php esc_html_e( 'Current Page', 'titan-zero-trades-front-desk' ); ?></label>
                        <input class="current-page" id="current-page-selector" type="text" name="p" value="<?php echo esc_attr( $tzfd_current_page ); ?>" size="<?php echo intval(strlen( (string) $tzfd_total_pages )); ?>" aria-describedby="table-paging" data-base-url="<?php echo esc_url( $tzfd_base_url ); ?>">
                        <span class="tablenav-paging-text"> <?php esc_html_e( 'of', 'titan-zero-trades-front-desk' ); ?> <span class="total-pages"><?php echo esc_html( number_format_i18n( $tzfd_total_pages ) ); ?></span></span>
                    </span>

                    <?php if ( $tzfd_current_page == $tzfd_total_pages ) : ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
                    <?php else : ?>
                        <a class="next-page button" href="<?php echo esc_url($tzfd_base_url . '&p=' . ($tzfd_current_page + 1)); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Next page', 'titan-zero-trades-front-desk' ); ?></span><span aria-hidden="true">&rsaquo;</span></a>
                        <a class="last-page button" href="<?php echo esc_url($tzfd_base_url . '&p=' . $tzfd_total_pages); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Last page', 'titan-zero-trades-front-desk' ); ?></span><span aria-hidden="true">&raquo;</span></a>
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
            <input type="hidden" name="anonce" value="<?php echo esc_attr($tzfd_nonce); ?>" />
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
                 <?php for ($tzfd_i=0; $tzfd_i<$tzfd_records_per_page; $tzfd_i++) if (isset($tzfd_events[$tzfd_i])) {
                          $tzfd_posted_data = TZFD_Stored_Data::decode_booking($tzfd_events[$tzfd_i]->posted_data);
                          $tzfd_cancelled = 0;
                          $status = '';
                          if (!is_array($tzfd_posted_data)) $tzfd_posted_data = array();
                          if (!isset($tzfd_posted_data["apps"]) || !is_array($tzfd_posted_data["apps"])) $tzfd_posted_data["apps"] = array();
                          for($tzfd_k=0; $tzfd_k<count($tzfd_posted_data["apps"]); $tzfd_k++)
                              if (isset($tzfd_posted_data["apps"][$tzfd_k]["cancelled"]) && $tzfd_posted_data["apps"][$tzfd_k]["cancelled"] != '') {
                                  $tzfd_cancelled++;
                                  $status = $tzfd_posted_data["apps"][$tzfd_k]["cancelled"];
                              }
                          if ($tzfd_cancelled && $tzfd_cancelled != count($tzfd_posted_data["apps"])) 
                             $status = '';                    
                 ?>
                  <tr class="<?php if ( $tzfd_cancelled && $tzfd_cancelled == count( $tzfd_posted_data["apps"] ) && $status != 'Attended') { echo 'tz_fd_cancelled'; } ?>" valign="top">
                    <th scope="row" class="check-column"><input type="checkbox" name="c<?php echo intval($tzfd_i); ?>" value="<?php echo intval($tzfd_events[$tzfd_i]->id); ?>" /></th>
                    <td><?php echo intval($tzfd_events[$tzfd_i]->id); ?></td>
                    <td><?php echo esc_html($this->format_date(substr($tzfd_events[$tzfd_i]->time,0,16)).gmdate(" H:i",strtotime($tzfd_events[$tzfd_i]->time))); ?></td>
                    <td><a href="<?php echo esc_url( 'mailto:' . sanitize_email($tzfd_events[$tzfd_i]->notifyto) ); ?>"><?php echo esc_html(sanitize_email($tzfd_events[$tzfd_i]->notifyto)); ?></a></td>
                    <td class="tzfdmessage">
                      <div class="tzfdmessagemw">
                        <?php
                        if ( $tzfd_cancelled && $tzfd_cancelled != count( $tzfd_posted_data["apps"] ) ) echo '<div class="notice notice-warning inline"><p><strong>* '.esc_html(__('Contains','titan-zero-trades-front-desk')).' '.esc_html($tzfd_cancelled).' '.esc_html(__('non-approved or cancelled dates','titan-zero-trades-front-desk')).'. <a href="'.esc_url( add_query_arg( array( 'page' => sanitize_key( $this->menu_parameter ), 'cal' => absint( $this->item ), 'schedule' => 1 ), admin_url( 'admin.php' ) ) ).'">'.esc_html(__('See details in schedule','titan-zero-trades-front-desk')).'</a>.</strong></p></div>';
                        
                        $tzfd_raw_message = isset( $tzfd_events[$tzfd_i]->data ) && is_scalar( $tzfd_events[$tzfd_i]->data )
                            ? (string) $tzfd_events[$tzfd_i]->data : '';
                        $tzfd_data = nl2br( esc_html( $tzfd_raw_message ) );
                        // Legacy attachment entries use a matching <field> and <field>_url pair.
                        // Reject malformed stored values; never derive an HTML attribute from arrays.
                        foreach ( $tzfd_posted_data as $tzfd_item => $tzfd_value ) {
                            if ( ! is_string( $tzfd_item ) || substr( $tzfd_item, -4 ) !== '_url' ) { continue; }
                            $tzfd_base_key = substr( $tzfd_item, 0, -4 );
                            if ( '' === $tzfd_base_key || ! isset( $tzfd_posted_data[ $tzfd_base_key ] ) ||
                                ! is_scalar( $tzfd_posted_data[ $tzfd_base_key ] ) ) { continue; }
                            $tzfd_label = (string) $tzfd_posted_data[ $tzfd_base_key ];
                            $tzfd_url = is_array( $tzfd_value ) && isset( $tzfd_value[0] ) && is_string( $tzfd_value[0] )
                                ? $tzfd_value[0] : ( is_string( $tzfd_value ) ? $tzfd_value : '' );
                            $tzfd_safe_url = esc_url( $tzfd_url, array( 'http', 'https' ) );
                            if ( '' === $tzfd_label || '' === $tzfd_safe_url ) { continue; }
                            $tzfd_data = str_replace(
                                esc_html( $tzfd_label ),
                                '<a href="' . esc_url( $tzfd_safe_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $tzfd_label ) . '</a><br />',
                                $tzfd_data
                            );
                        }
                        // Never reverse HTML escaping for stored messages. Any genuine image markup must be independently validated by wp_kses().
                        echo '<div class="tzfd-appointment-header">'; // style="display:none"
                        $tzfd_data = str_replace('<br /><br />', '</div><div>',$this->filter_allowed_tags(apply_filters( 'tz_fd_booking_orders_item', $tzfd_data, $tzfd_posted_data ))); 

                        $tzfd_appts = "";

                        foreach ( $tzfd_posted_data['apps'] as $tzfd_app ) {
                            if ( ! is_array( $tzfd_app ) ) { continue; }
                            // Booking data may predate the current schema or have been corrupted.
                            // Never pass arrays/objects to WordPress escaping or date functions.
                            $tzfd_date = isset( $tzfd_app['date'] ) && is_scalar( $tzfd_app['date'] ) ? (string) $tzfd_app['date'] : '';
                            $tzfd_slot = isset( $tzfd_app['slot'] ) && is_scalar( $tzfd_app['slot'] ) ? (string) $tzfd_app['slot'] : '';
                            $tzfd_service = isset( $tzfd_app['service'] ) && is_scalar( $tzfd_app['service'] ) ? (string) $tzfd_app['service'] : '';
                            $tzfd_quant = isset( $tzfd_app['quant'] ) && is_numeric( $tzfd_app['quant'] ) ? (int) $tzfd_app['quant'] : 0;
                            if ( '' === $tzfd_date || '' === $tzfd_slot ) { continue; }
                            $tzfd_appts .= '<div class="tzfd-appointment-badge">' .
                                '<span class="dashicons dashicons-clock"></span>' .
                                '<span class="tzfd-time">' . esc_html( $this->format_date( $tzfd_date ) ) . ' ' . esc_html( $tzfd_slot ) .
                                ( $tzfd_quant > 1 ? ' (' . esc_html( (string) $tzfd_quant ) . ')' : '' ) . '</span>' .
                                '<span class="tzfd-service">' . esc_html( $tzfd_service ) . '</span></div>';
                        }

                        
                        echo wp_kses( $tzfd_appts, array( 'div' => array( 'class' => true ), 'span' => array( 'class' => true ) ) ) . '</div><div style="display:none">' . wp_kses( $tzfd_data, $this->tags_allowed );
                        echo '</div>';
                        
                        
                        ?>
                       </div>
                    </td>
                    <td class="tzfdnopr">
                      <div style="margin-bottom: 5px;">
                          <?php $this->render_status_box('sb'.intval($tzfd_events[$tzfd_i]->id), $status); ?>
                      </div>
                    <div class="row-actions visible">
                          <span class="inline"><a href="#" data-tzfd-action="resend" data-id="<?php echo intval($tzfd_events[$tzfd_i]->id); ?>"><?php esc_html_e('Resend Email','titan-zero-trades-front-desk'); ?></a> | </span>
                          <span class="trash"><a href="#" data-tzfd-action="delete-row" data-id="<?php echo intval($tzfd_events[$tzfd_i]->id); ?>" class="submitdelete"><?php esc_html_e('Delete','titan-zero-trades-front-desk'); ?></a></span>
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

