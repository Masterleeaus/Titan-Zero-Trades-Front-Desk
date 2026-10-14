<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-stored-data.php';

require_once __DIR__ . '/includes/class-tzfd-lifecycle-rules.php';
require_once __DIR__ . '/includes/class-tzfd-admin-booking-access.php';

$this->item = (isset($_GET["cal"]) ? intval(sanitize_text_field( wp_unslash( $_GET["cal"] ?? '' ) )) : 0);

$current_user = wp_get_current_user();
$tzfd_current_user_access = current_user_can('manage_options');

$tzfd_calendar_access_ids = TZFD_Access_List::decode(
    $this->get_option( 'tzfd_user_access', serialize( array() ) )
);
if ( ! is_admin() || ( ! $tzfd_current_user_access && ! in_array( (int) $current_user->ID, $tzfd_calendar_access_ids, true ) ) )
{
    echo 'Direct access not allowed.';
    exit;
}

// Authorize actual target rows before any bulk write, deletion or resend.
// The branch-specific CSRF nonce verification below remains required.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
if ( ! TZFD_Admin_Booking_Access::authorize_request( $_GET, $this->item ) ) {
    wp_die( esc_html__( 'You are not allowed to change these bookings.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}

$tzfd_current_page = intval( (empty($_GET["p"])?0:sanitize_text_field( wp_unslash( $_GET["p"] ?? '' ) )) );
if (!$tzfd_current_page) $tzfd_current_page = 1;
$tzfd_records_per_page = 50;

$tzfd_message = "";

if (isset($_GET['statusmark']) && sanitize_text_field( wp_unslash( $_GET['statusmark'] ?? '' ) ) != '')
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_booking' ) ) { $this->nonce_failed(); }
    $tzfd_raw_status = isset($_GET['sbmi']) ? sanitize_text_field( wp_unslash( $_GET['sbmi'] ?? '' ) ) : null;
    $status = is_string($tzfd_raw_status) ? sanitize_text_field(wp_unslash($tzfd_raw_status)) : null;

    if ( ! TZFD_Lifecycle_Rules::is_allowed_admin_status( $status, $this->get_status_list() ) ) {
        $tzfd_message = __('Invalid booking status; no bookings were changed.','titan-zero-trades-front-desk');
    } else {
        for ($tzfd_i=0; $tzfd_i<=$tzfd_records_per_page; $tzfd_i++)
        if (isset($_GET['c'.$tzfd_i]) && sanitize_text_field( wp_unslash( $_GET['c'.$tzfd_i] ) ) != '')
        {
            $this->update_status( intval( sanitize_text_field( wp_unslash( $_GET['c'.$tzfd_i] ) ) ), $status );
        }
        $tzfd_message = __('Marked items status updated','titan-zero-trades-front-desk');
    }
}
else if (isset($_GET['resend']) && sanitize_text_field( wp_unslash( $_GET['resend'] ?? '' ) ) != '')
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_booking' ) ) { $this->nonce_failed(); }
    $this->ready_to_go_reservation( intval(sanitize_text_field( wp_unslash( $_GET['resend'] ?? '' ) )), '', true);        
    $tzfd_message = __('Notification emails resent for the booking','titan-zero-trades-front-desk');
}
else if (isset($_GET['delmark']) && sanitize_text_field( wp_unslash( $_GET['delmark'] ?? '' ) ) != '')
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_booking' ) ) { $this->nonce_failed(); }
    for ($tzfd_i=0; $tzfd_i<=$tzfd_records_per_page; $tzfd_i++)
    if (isset($_GET['c'.$tzfd_i]) && sanitize_text_field( wp_unslash( $_GET['c'.$tzfd_i] ) ) != '') {
        do_action( 'tzfd_item_deleted', intval( sanitize_text_field( wp_unslash( $_GET['c'.$tzfd_i] ) ) ) );
        $wpdb->query( $wpdb->prepare('DELETE FROM `'.$wpdb->prefix.'tzfd_messages` WHERE id=%d', sanitize_text_field( wp_unslash( $_GET['c'.$tzfd_i] ?? '' ) )) ); 
    }
    do_action( 'tzfd_cache_clean', $this->item );
    $tzfd_message = __('Marked items deleted','titan-zero-trades-front-desk');
}
else if (isset($_GET['del']) && sanitize_text_field( wp_unslash( $_GET['del'] ?? '' ) ) == 'all')
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_booking' ) ) { $this->nonce_failed(); }
    if ($this->item == '' || $this->item == '0')
        $wpdb->query('DELETE FROM `'.$wpdb->prefix.'tzfd_messages`'); 
    else
        $wpdb->query($wpdb->prepare('DELETE FROM `'.$wpdb->prefix.'tzfd_messages` WHERE formid=%d', $this->item)); 
    $tzfd_message = __('All items deleted','titan-zero-trades-front-desk');
}
else if (isset($_GET['lu']) && sanitize_text_field( wp_unslash( $_GET['lu'] ?? '' ) ) != '')
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_booking' ) ) { $this->nonce_failed(); }
    $tzfd_myrows = $wpdb->get_results( $wpdb->prepare("SELECT * FROM ".$wpdb->prefix."tzfd_messages WHERE id=%d", sanitize_text_field(wp_unslash( $_GET['lu'] ?? '' ))) ); 
    $tzfd_params = (is_array($tzfd_myrows) && isset($tzfd_myrows[0]->posted_data) && empty($wpdb->last_error))
        ? TZFD_Stored_Data::decode_booking($tzfd_myrows[0]->posted_data) : null;
    // A list or other non-text value is refused, as before; text is unslashed and sanitised here.
    $tzfd_status_in = ( isset($_GET['status']) && is_string($_GET['status']) ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : null;
    if (null === $tzfd_params || null === $tzfd_status_in) {
        $tzfd_message = __('Booking data is unavailable; no change was made.','titan-zero-trades-front-desk');
    } else {
        $tzfd_params["paid"] = $tzfd_status_in;
        $tzfd_params["payment_type"] = __('Manually updated','titan-zero-trades-front-desk');
        $tzfd_updated = $wpdb->query( $wpdb->prepare('UPDATE `'.$wpdb->prefix.'tzfd_messages` SET posted_data=%s WHERE id=%d', serialize($tzfd_params), sanitize_text_field(wp_unslash( $_GET['lu'] ?? '' ))) );
        $tzfd_message = (false === $tzfd_updated) ? __('Booking update failed.','titan-zero-trades-front-desk') : __('Item updated','titan-zero-trades-front-desk');
    }
}
else if (isset($_GET['ld']) && sanitize_text_field( wp_unslash( $_GET['ld'] ?? '' ) ) != '')
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_booking' ) ) { $this->nonce_failed(); }
    do_action( 'tzfd_item_deleted', intval(sanitize_text_field( wp_unslash( $_GET['ld'] ?? '' ) )) );
    $wpdb->query( $wpdb->prepare('DELETE FROM `'.$wpdb->prefix.'tzfd_messages` WHERE id=%d', sanitize_text_field(wp_unslash( $_GET['ld'] ?? '' ))) ); 
    $tzfd_message = __('Item deleted','titan-zero-trades-front-desk');
}
else if (isset($_GET['ud']) && sanitize_text_field( wp_unslash( $_GET['ud'] ?? '' ) ) != '')
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_booking' ) ) { $this->nonce_failed(); }
    $tzfd_raw_status = isset($_GET['status']) ? sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ) : null;
    $status = is_string($tzfd_raw_status) ? sanitize_text_field(wp_unslash($tzfd_raw_status)) : null;

    if ( ! TZFD_Lifecycle_Rules::is_allowed_admin_status( $status, $this->get_status_list() ) ) {
        $tzfd_message = __('Invalid booking status; no change was made.','titan-zero-trades-front-desk');
    } else {
        if (isset($_GET["udidx"]))
            $this->update_status(sanitize_text_field(wp_unslash( $_GET['ud'] ?? '' )), $status, intval(sanitize_text_field( wp_unslash( $_GET["udidx"] ?? '' ) )));
        else
            $this->update_status(sanitize_text_field(wp_unslash( $_GET['ud'] ?? '' )), $status);

        $tzfd_message = __('Status updated','titan-zero-trades-front-desk');

        if ( !empty( $_GET["or"] ) && sanitize_text_field( wp_unslash( $_GET["or"] ?? '' ) ) == 'shlist')
        {
          $tzfd_go = 'admin.php?page=' . $this->menu_parameter . '&schedule=1&cal=' . intval(sanitize_text_field( wp_unslash( $_GET["cal"] ?? '' ) )) . '#sb' . intval(sanitize_text_field( wp_unslash( $_GET["ud"] ?? '' ) )) . '_' . intval(sanitize_text_field( wp_unslash( $_GET["udidx"] ?? '' ) ));
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
    $tzfd_myform = $wpdb->get_results( $wpdb->prepare('SELECT * FROM '.$wpdb->prefix.'tzfd_forms WHERE id=%d' ,$this->item) ); 

$tzfd_rawfrom = (isset($_GET["dfrom"]) ? sanitize_text_field(wp_unslash( $_GET["dfrom"] ?? '' )) : '');
$tzfd_rawto = (isset($_GET["dto"]) ? sanitize_text_field( wp_unslash( $_GET["dto"] ?? '' ) ) : '');
if ($this->get_option('date_format', 'mm/dd/yy') == 'dd/mm/yy')
{
    $tzfd_rawfrom = str_replace('/','.',$tzfd_rawfrom);
    $tzfd_rawto = str_replace('/','.',$tzfd_rawto);
}

// Each optional filter is a fixed clause with a switch: the clause is ignored when its switch is 0.
// Every value is passed to prepare() as a placeholder argument.
$tzfd_search_on = ( ! empty( $_GET['search'] ) ) ? 1 : 0;
$tzfd_search    = '';
if ( $tzfd_search_on ) {
    $search      = sanitize_text_field( wp_unslash( $_GET['search'] ?? '' ) );
    $tzfd_search = '%' . $wpdb->esc_like( $search ) . '%';
}

$tzfd_from_on = ( $tzfd_rawfrom != '' ) ? 1 : 0;
$tzfd_from    = '';
if ( $tzfd_from_on ) {
    $tzfd_from = gmdate( 'Y-m-d', strtotime( $tzfd_rawfrom ) );
}

$tzfd_to_on = ( $tzfd_rawto != '' ) ? 1 : 0;
$tzfd_to    = '';
if ( $tzfd_to_on ) {
    $tzfd_to = gmdate( 'Y-m-d', strtotime( $tzfd_rawto ) ) . ' 23:59:59';
}

$tzfd_form_on = ( $this->item != 0 ) ? 1 : 0;
$tzfd_form_id = (int) $this->item;

$tzfd_table_name = $wpdb->prefix . 'tzfd_messages';
// First Query: Pagination Count
$tzfd_eventscount = $wpdb->get_results( $wpdb->prepare(
    "SELECT count(id) as ck FROM %i WHERE 1=1
    AND ( %d = 0 OR (data LIKE %s OR posted_data LIKE %s) )
    AND ( %d = 0 OR (`time` >= %s) )
    AND ( %d = 0 OR (`time` <= %s) )
    AND ( %d = 0 OR formid = %d )
    ORDER BY `time` DESC",
    $tzfd_table_name, $tzfd_search_on, $tzfd_search, $tzfd_search, $tzfd_from_on, $tzfd_from, $tzfd_to_on, $tzfd_to, $tzfd_form_on, $tzfd_form_id
) );

$tzfd_total_pages = ceil( $tzfd_eventscount[0]->ck / $tzfd_records_per_page );

$tzfd_offset = ( $tzfd_current_page - 1 ) * $tzfd_records_per_page;

// Second Query: Items
$tzfd_events = $wpdb->get_results( $wpdb->prepare(
    "SELECT * FROM %i WHERE 1=1
    AND ( %d = 0 OR (data LIKE %s OR posted_data LIKE %s) )
    AND ( %d = 0 OR (`time` >= %s) )
    AND ( %d = 0 OR (`time` <= %s) )
    AND ( %d = 0 OR formid = %d )
    ORDER BY `time` DESC LIMIT %d, %d",
    $tzfd_table_name, $tzfd_search_on, $tzfd_search, $tzfd_search, $tzfd_from_on, $tzfd_from, $tzfd_to_on, $tzfd_to, $tzfd_form_on, $tzfd_form_id, $tzfd_offset, $tzfd_records_per_page
) );
$tzfd_nonce = wp_create_nonce( 'tzfd_actions_booking' );

?>
<div class="wrap tzfd-admin-page tzfd-page tzfd-page-bookings">
    <h1 class="wp-heading-inline"><?php esc_html_e('Booking Orders','titan-zero-trades-front-desk'); ?> - <?php if ($this->item != 0) echo esc_html($tzfd_myform[0]->form_name); else echo esc_html__( 'All forms', 'titan-zero-trades-front-desk' ); ?></h1>
    <a href="<?php echo esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="page-title-action">&larr; <?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
    <hr class="wp-header-end">

    <?php if ($tzfd_message && !isset($_GET['ud'])) echo "<div id='setting-error-settings_updated' class='updated notice is-dismissible'><p><strong>".esc_html($tzfd_message)."</strong></p></div>"; ?>

<?php
    // Bookings list behaviour lives in js/inline/. Page values reach it through wp_localize_script().
    wp_enqueue_script( 'tzfd-adminb-message-paging', plugins_url( 'js/inline/adminb-message-paging.js', __FILE__ ), array(), '2.0.1', true );
    wp_enqueue_script( 'tzfd-adminb-message-list', plugins_url( 'js/inline/adminb-message-list.js', __FILE__ ), array( 'jquery' ), '2.0.1', true );
    wp_localize_script( 'tzfd-adminb-message-list', 'tzfdAdminBList', array(
        'page'    => $this->menu_parameter,
        'nonce'   => $tzfd_nonce,
        'cal'     => intval(sanitize_text_field( wp_unslash( $_GET["cal"] ?? '' ) )),
        'strings' => array(
            'confirmResend' => __( 'Are you sure that you want to resend the notification emails for this item?', 'titan-zero-trades-front-desk' ),
            'confirmDelete' => __( 'Are you sure that you want to delete this item?', 'titan-zero-trades-front-desk' ),
            'manualUpdated' => __( 'Manually updated', 'titan-zero-trades-front-desk' ),
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
            <input type="hidden" name="anonce" value="<?php echo esc_attr($tzfd_nonce); ?>" />
            
            <div class="alignleft actions tzfdno-wrap-div">
                <select id="cal" name="cal" class="selectmh">
                    <?php if ($tzfd_current_user_access) { ?> <option value="0">[<?php esc_html_e('All Items','titan-zero-trades-front-desk'); ?>]</option><?php } ?>
                    <?php
                    $tzfd_myrows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $wpdb->prefix . 'tzfd_forms' ) );
                    $tzfd_saved_id = $this->item;
                    foreach ($tzfd_myrows as $tzfd_item) {
                        $this->setId($tzfd_item->id);
                        $tzfd_calendar_access_ids = TZFD_Access_List::decode(
                            $this->get_option( 'tzfd_user_access', serialize( array() ) )
                        );
                        if ( $tzfd_current_user_access || in_array( (int) $current_user->ID, $tzfd_calendar_access_ids, true ) )
                            echo '<option value="'.esc_attr($tzfd_item->id).'"'.(intval($tzfd_item->id)==intval($tzfd_saved_id)?" selected":"").'>'.esc_html($tzfd_item->form_name).'</option>';
                    }
                    $this->setId($tzfd_saved_id);
                    ?>
                </select>
                
                <input  type="search" name="search" placeholder="<?php esc_attr_e('Search...','titan-zero-trades-front-desk'); ?>" value="<?php echo esc_attr((!empty($_GET["search"])?sanitize_text_field(wp_unslash( $_GET["search"] ?? '' )):'')); ?>">
                <input autocomplete="off" type="date" id="dfrom" name="dfrom" placeholder="<?php esc_attr_e('From','titan-zero-trades-front-desk'); ?>" value="<?php echo esc_attr((!empty($_GET["dfrom"])?sanitize_text_field(wp_unslash( $_GET["dfrom"] ?? '' )):'')); ?>" size="10">
                <input autocomplete="off" type="date" id="dto" name="dto" placeholder="<?php esc_attr_e('To','titan-zero-trades-front-desk'); ?>" value="<?php echo esc_attr((!empty($_GET["dto"])?sanitize_text_field(wp_unslash( $_GET["dto"] ?? '' )):'')); ?>" size="10">
                
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
                $tzfd_base_url = 'admin.php?page='.esc_attr($this->menu_parameter).'&cal='.intval($this->item).'&list=1&dfrom='.urlencode(sanitize_text_field((!empty($_GET["dfrom"])?sanitize_text_field(wp_unslash( $_GET["dfrom"] ?? '' )):''))).'&dto='.urlencode(sanitize_text_field((!empty($_GET["dto"])?sanitize_text_field(wp_unslash( $_GET["dto"] ?? '' )):''))).'&search='.urlencode($this->clean_sanitize((!empty($_GET["search"])?sanitize_text_field(wp_unslash( $_GET["search"] ?? '' )):'')));
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
        <form name="dex_table_form" id="dex_table_form" action="admin.php" method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_parameter); ?>" />
            <input type="hidden" name="cal" value="<?php echo intval(sanitize_text_field( wp_unslash( $_GET["cal"] ?? '' ) )); ?>" />
            <input type="hidden" name="list" value="1" />
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
                    <th scope="col" class="manage-column column-status" ><?php esc_html_e('Paid Status','titan-zero-trades-front-desk'); ?></th>
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
                  <tr class="<?php if ( $tzfd_cancelled && $tzfd_cancelled == count( $tzfd_posted_data["apps"] ) && $status != 'Attended') { echo 'tzfd_cancelled'; } ?>" valign="top">
                    <th scope="row" class="check-column"><input type="checkbox" name="c<?php echo intval($tzfd_i); ?>" value="<?php echo intval($tzfd_events[$tzfd_i]->id); ?>" /></th>
                    <td><?php echo intval($tzfd_events[$tzfd_i]->id); ?></td>
                    <td><?php echo esc_html($this->format_date(substr($tzfd_events[$tzfd_i]->time,0,16)).gmdate(" H:i",strtotime($tzfd_events[$tzfd_i]->time))); ?></td>
                    <td><a href="<?php echo esc_url( 'mailto:' . sanitize_email($tzfd_events[$tzfd_i]->notifyto) ); ?>"><?php echo esc_html(sanitize_email($tzfd_events[$tzfd_i]->notifyto)); ?></a></td>
                    <td class="tzfdmessage">
                      <div class="tzfdmessagemw">
                        <?php
                        if ( $tzfd_cancelled && $tzfd_cancelled != count( $tzfd_posted_data["apps"] ) ) echo '<div class="notice notice-warning inline"><p><strong>* '.esc_html(__('Contains','titan-zero-trades-front-desk')).' '.esc_html($tzfd_cancelled).' '.esc_html(__('non-approved or cancelled dates','titan-zero-trades-front-desk')).'. <a href="?page='.esc_attr($this->menu_parameter).'&cal='.intval($this->item).'&schedule=1">'.esc_html(__('See details in schedule','titan-zero-trades-front-desk')).'</a>.</strong></p></div>';
                        
                        $tzfd_data = str_replace("\n","<br />",str_replace('<','&lt;',$tzfd_events[$tzfd_i]->data));
                        foreach ($tzfd_posted_data as $tzfd_item => $tzfd_value)
                            if (strpos($tzfd_item,"_url") && $tzfd_value != '') {
                                $tzfd_data = str_replace ($tzfd_posted_data[str_replace("_url","",$tzfd_item)],'<a href="'.esc_url($tzfd_value[0]).'" target="_blank">'.esc_html($tzfd_posted_data[str_replace("_url","",$tzfd_item)]).'</a><br />',$tzfd_data);
                            }
                        $tzfd_data = str_replace("&lt;img ","<img ", $tzfd_data);
                        echo '<div class="tzfd-appointment-header">'; // style="display:none"
                        $tzfd_data = str_replace('<br /><br />', '</div><div>',$this->filter_allowed_tags(apply_filters( 'tzfd_booking_orders_item', $tzfd_data, $tzfd_posted_data ))); 

                        $tzfd_appts = "";

                        for($tzfd_k=0; $tzfd_k<count($tzfd_posted_data["apps"]); $tzfd_k++) {
                            $tzfd_app = $tzfd_posted_data["apps"][$tzfd_k];
                            $tzfd_appts .=   '<div class="tzfd-appointment-badge">' .
                                           '<span class="dashicons dashicons-clock"></span>' .
                                           '<span class="tzfd-time">'.esc_html($this->format_date($tzfd_app["date"])).' '.esc_html($tzfd_app["slot"]).(isset($tzfd_app["quant"]) && $tzfd_app["quant"]>1?' ('.esc_html($tzfd_app["quant"]).')':'').'</span>' .
                                           '<span class="tzfd-service">'.esc_html($tzfd_app["service"]).'</span>' .
                                       '</div>';
                        }

                        
                        echo wp_kses( $tzfd_appts, array( 'div' => array( 'class' => true ), 'span' => array( 'class' => true ) ) ) . '</div><div style="display:none">' . wp_kses( $tzfd_data, $this->tags_allowed );
                        echo '</div>';
                        
                        
                        ?>
                       </div>
                    </td>
                    <td align="center">
                        <?php 
                        $tzfd_is_paid = (!empty($tzfd_posted_data["paid"]) && $tzfd_posted_data["paid"]=='1');
                        echo '<span class="dashicons dashicons-'.($tzfd_is_paid ? 'money-alt" style="color: green;" title="Paid"' : 'minus" style="color: #d63638;" title="Not Paid"').'></span>'; 
                        if ($tzfd_is_paid) {
                            echo '<br/><em style="font-size: 11px; color: #a4acba;">'.esc_html($tzfd_posted_data["payment_type"]).'</em>';
                        }
                        ?>
                    </td>
                    <td class="tzfdnopr">
                      <div style="margin-bottom: 5px;">
                          <?php $this->render_status_box('sb'.intval($tzfd_events[$tzfd_i]->id), $status); ?>
                      </div>
                    <div class="row-actions visible">
                          <span class="edit"><a href="#" data-tzfd-action="toggle-paid" data-id="<?php echo intval($tzfd_events[$tzfd_i]->id); ?>" data-status="<?php echo ($tzfd_is_paid ? '0' : '1'); ?>"><?php esc_html_e('Toggle Payment','titan-zero-trades-front-desk'); ?></a> | </span>
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

