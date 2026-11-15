<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.NonceVerification.Recommended -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked. Read-only schedule parameters on a capability-gated admin screen; this screen changes no state.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-request-scalar.php';
require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';
require_once __DIR__ . '/includes/class-tzfd-admin-filter-input.php';

require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';

$this->item = TZFD_Admin_Request_Ids::calendar( $_GET );
if ( null === $this->item ) {
    wp_die( esc_html__( 'Invalid calendar identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}

require_once __DIR__ . '/includes/class-tzfd-admin-report-scope.php';
$tzfd_report_company = TZFD_Admin_Report_Scope::enforce( $this->item );

$current_user = wp_get_current_user();
$tz_current_user_access = current_user_can('tz_fd_access');
$tz_calendar_access_ids = TZFD_Access_List::decode( $this->get_option( 'tz_fd_user_access', '' ) );

if ( !is_admin() || (!$tz_current_user_access && !in_array($current_user->ID, $tz_calendar_access_ids, true)))
{
    echo esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' );
    exit;
}

$tz_firstday = 0;
if ($this->item != 0)
{
    $tz_myform = $wpdb->get_results( $wpdb->prepare('SELECT * FROM %i WHERE id=%d AND company_id=%d', $wpdb->prefix.TZFD_Tables::FORMS, $this->item, $tzfd_report_company) ); 
    if ( ! is_array( $tz_myform ) || ! isset( $tz_myform[0]->form_structure ) ) {
        wp_die( esc_html__( 'Calendar not found.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 404 ) );
    }
    $tz_raw_form_str = $this->cleanJSON( $tz_myform[0]->form_structure );
    $tz_form_data = json_decode( $tz_raw_form_str );
    if ( ! is_array( $tz_form_data ) || ! isset( $tz_form_data[0] ) || ! is_array( $tz_form_data[0] ) ) {
        wp_die( esc_html__( 'Invalid calendar configuration.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
    }
    foreach ( $tz_form_data[0] as $tz_item ) {
        if ( is_object( $tz_item ) && isset( $tz_item->ftype ) && 'fapp' === $tz_item->ftype ) {
            $tz_firstday = isset( $tz_item->firstDay ) ? absint( $tz_item->firstDay ) % 7 : 0;
            break;
        }
    }
}

$tz_default_from = gmdate("Y-m-d",strtotime("today -10 days"));
$tz_default_to = gmdate("Y-m-d",strtotime("today +30 days"));

// Use the same strict filter validation as booking lists and analytics reports.
// Invalid dates must not silently turn into a 1970 range.
list( $tz_selected_from, $tz_selected_to ) = TZFD_Admin_Filter_Input::enforce( $_GET, $this->get_option( 'date_format', 'mm/dd/yy' ) );
$tz_dfrom = null === $tz_selected_from ? $tz_default_from : $tz_selected_from;
$tz_dto   = null === $tz_selected_to ? $tz_default_to : $tz_selected_to;

if ($this->get_option('date_format', 'mm/dd/yy') == 'd M, y')
{
	$tz_dfrom_formatted = gmdate("d/m/Y", strtotime($tz_dfrom));
    $tz_dto_formatted = gmdate("d/m/Y", strtotime($tz_dto));
}
else
{
    $tz_dfrom_formatted = $this->format_date($tz_dfrom);
    $tz_dto_formatted = $this->format_date($tz_dto);
}

// Page assets (review R2). Enqueued here because this template renders after admin_enqueue_scripts.
$tz_schedule_calendar_view = isset( $_GET["calendarview"] );
$tz_schedule_deps = array( 'jquery', 'jquery-ui-core', 'jquery-ui-datepicker' );
if ( $tz_schedule_calendar_view && wp_script_is( 'tzfdtslots-schedcal-tzfdcalendar', 'registered' ) ) {
    $tz_schedule_deps[] = 'tzfdtslots-schedcal-tzfdcalendar';
}
$tz_dformatc = $this->get_option( 'date_format', 'mm/dd/yy' );
if ( $tz_dformatc == 'd M, y' ) $tz_dformatc = 'dd/mm/yy';

wp_enqueue_script( 'tzfd-schedule', plugins_url( 'js/inline/admina-schedule.js', __FILE__ ), $tz_schedule_deps, '2.0.1', true );
wp_localize_script( 'tzfd-schedule', 'tzfdScheduleData', array(
    'calendarView' => $tz_schedule_calendar_view,
    'calendarId'   => intval( $this->item ),
    'firstDay'     => intval( $tz_firstday ),
    // The calendar feed (mv) checks this anonce on every request the calendar script makes.
    'pathCalendar' => $this->get_site_url( true ) . '?tz_fd_app_action=mv&formid=' . intval( $this->item ) . '&anonce=' . rawurlencode( wp_create_nonce( 'tz_fd_actions_booking' ) ),
    'dateFormat'   => $tz_dformatc,
) );

?>


<h1><?php echo wp_kses( TZFD_UI_Graphics::brand_html( 'availability' ), TZFD_UI_Graphics::allowed_html() ); ?><?php esc_html_e('Schedule','titan-zero-trades-front-desk'); ?> -<?php if ($this->item != 0) echo esc_html($tz_myform[0]->form_name); else echo esc_html__( 'All forms', 'titan-zero-trades-front-desk' ); ?></h1>

<div class="tzfd-buttons-container">

    <?php if (!isset($_GET["calendarview"])) { ?>
     <input type="button" value="<?php esc_html_e('Change to Schedule Calendar View','titan-zero-trades-front-desk'); ?>" class="button button-primary" data-tzfd-url="<?php echo esc_attr('?page='.$this->menu_parameter.'&cal='.intval($this->item).'&schedule=1&calendarview=1'); ?>" />
    <?php } else { ?>
     <input type="button" value="<?php esc_html_e('Change to Schedule List View','titan-zero-trades-front-desk'); ?>" class="button button-primary" data-tzfd-url="<?php echo esc_attr('?page='.$this->menu_parameter.'&cal='.intval($this->item).'&schedule=1'); ?>" />
     <input type="button" value="<?php esc_html_e('Customize Contents of this Calendar','titan-zero-trades-front-desk'); ?>" class="button" style="margin-left:20px;" data-tzfd-url="<?php echo esc_attr('?page='.$this->menu_parameter.'_settings&gotab=schedulecalarea'); ?>" />
    <?php } ?>
	<a href="<?php print esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
	<div class="clear"></div>
</div>

<?php if (!isset($_GET["calendarview"])) { ?>

<div class="tzfd-section-container">
	<div class="tzfd-section">
      <form action="admin.php" method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_parameter); ?>" />
        <input type="hidden" name="cal" value="<?php echo intval($this->item); ?>" />
        <input type="hidden" name="schedule" value="1" />
        <input type="hidden" name="anonce" value="<?php echo esc_attr( wp_create_nonce( 'tz_fd_actions_booking' ) ); ?>" />
		<nobr><label for="dfrom"><?php esc_html_e('From','titan-zero-trades-front-desk'); ?>:</label> <input autocomplete="off" type="text" id="dfrom" name="dfrom" value="<?php echo esc_attr($tz_dfrom_formatted); ?>" >&nbsp;&nbsp;</nobr>
		<nobr><label for="dto"><?php esc_html_e('To','titan-zero-trades-front-desk'); ?>:</label> <input autocomplete="off" type="text" id="dto" name="dto" value="<?php echo esc_attr($tz_dto_formatted); ?>" >&nbsp;&nbsp;</nobr>
		<nobr><label for="paid"><?php esc_html_e('Paid Status','titan-zero-trades-front-desk'); ?>:</label> <select id="paid" name="paid">
         <option value=""><?php esc_html_e('All','titan-zero-trades-front-desk'); ?></option>
         <option value="1" <?php if (!empty($_GET["paid"])) echo ' selected'; ?>><?php esc_html_e('Paid only','titan-zero-trades-front-desk'); ?></option>
      </select></nobr>
        <nobr><label for="status"><?php esc_html_e('Booking Status','titan-zero-trades-front-desk'); ?>:</label> <?php $this->render_status_box('status', (!isset($_GET["status"])?'all':tzfd_request_text( 'get', "status" )), true); ?></nobr>
		<nobr><label for="cal"><?php esc_html_e('Item','titan-zero-trades-front-desk'); ?>:</label> <select id="cal" name="cal">
          <?php if ($tz_current_user_access) { ?> <option value="0">[<?php esc_html_e('All Items','titan-zero-trades-front-desk'); ?>]</option><?php } ?>
   <?php
    $tz_myrows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE company_id=%d", $wpdb->prefix . TZFD_Tables::FORMS, $tzfd_report_company ) );  
    $tz_saved_id = $this->item;
    foreach ($tz_myrows as $tz_item)
    {
        $this->setId($tz_item->id);
        if ($tz_current_user_access || in_array($current_user->ID, $tz_calendar_access_ids, true))
           echo '<option value="'.intval($tz_item->id).'"'.(intval($tz_item->id)==intval($tz_saved_id)?" selected":"").'>'.esc_html($tz_item->form_name).'</option>';
    }
    $this->setId($tz_saved_id);
   ?>
    </select></nobr>
       <div style="float:right;margin-top:3px;">
		<nobr>
            <input type="submit" name="ds" value="<?php esc_html_e('Filter','titan-zero-trades-front-desk'); ?>" class="button-primary button" style="">
			<input type="submit" name="<?php echo esc_attr($this->prefix); ?>_csv2" value="<?php esc_html_e('Export to CSV','titan-zero-trades-front-desk'); ?>" class="button" style="margin-left:10px;">
		</nobr>
       </div>
      </form>
      <div style="clear:both"></div>
	</div>
</div>


<br />

<div id="dex_printable_contents">

 <div class="tz_fd_app_no_wrap">
  <div class="tz_fd_field_0 tz_fd_field_header"><?php esc_html_e('Date','titan-zero-trades-front-desk'); ?></div>
  <div class="tz_fd_field_1 tz_fd_field_header"><?php esc_html_e('Slot','titan-zero-trades-front-desk'); ?></div>
  <div class="tz_fd_field_2 tz_fd_field_header"><?php esc_html_e('Service','titan-zero-trades-front-desk'); ?></div>
  <div class="tz_fd_field_3 tz_fd_field_header"><?php esc_html_e('Qty','titan-zero-trades-front-desk'); ?></div>
  <div class="tz_fd_field_4 tz_fd_field_header"><?php esc_html_e('Paid','titan-zero-trades-front-desk'); ?></div>
  <div class="tz_fd_field_5 tz_fd_field_header"><?php esc_html_e('Email','titan-zero-trades-front-desk'); ?></div>
  <div class="tz_fd_field_6 tz_fd_field_header"><?php esc_html_e('Data','titan-zero-trades-front-desk'); ?></div>
  <div class="tz_fd_field_7 tz_fd_field_header"><?php esc_html_e('Status','titan-zero-trades-front-desk'); ?></div>
  <div class="tz_fd_field_8 tz_fd_field_header"><?php esc_html_e('Options','titan-zero-trades-front-desk'); ?></div>
  <div class="tz_fd_app_break"></div>
 </div>
 <div class="tz_fd_app_break"></div>
<?php

echo wp_kses( $this->filter_list( array(
                               'calendar' => ($this->item != 0 ? intval($this->item) : ''),
                               'fields' => 'DATE,TIME,SERVICE,quantity,paid,email,data,cancelled,options',
	    	                   'from' => esc_html($tz_dfrom),
	    	                   'to' => esc_html($tz_dto),
                               'paidonly' => esc_html( (!empty($_GET["paid"])?tzfd_request_text( 'get', "paid" ):'')),
                               'status' => (!isset($_GET["status"])?'all':esc_html(tzfd_request_text( 'get', "status" )))
                               ) ), $this->admin_markup_allowed() );

?>
</div>


<div class="tzfd-buttons-container">
    <input type="button" value="<?php esc_html_e('Print','titan-zero-trades-front-desk'); ?>" class="button button-primary" id="tzfd-print-schedule" />
	<a href="<?php print esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
	<div class="clear"></div>
</div>


<?php } else { ?>

<div id="tz_fd_abc_printable_contents">

<p>
    <?php 
    echo wp_kses( 
        __( 'The purpose of this page is to <strong>display the bookings/schedule in a calendar view</strong>. You can add bookings from the public booking form or', 'titan-zero-trades-front-desk' ),
        array(
            'strong' => array(),
        )
    ); 
    ?> 
    <a href="<?php echo esc_url( add_query_arg( array( 'page' => sanitize_key( $this->menu_parameter ), 'cal' => absint( $this->item ), 'addbk' => 1 ), admin_url( 'admin.php' ) ) ); ?>">
        <?php esc_html_e( 'add bookings from the dashboard', 'titan-zero-trades-front-desk' ); ?>
    </a> 
    <?php 
    echo wp_kses( 
        __( 'and the bookings will appear in this calendar.<br />For CSV export, print and filter options switch to the "<strong>List View</strong>" with the button above this text.', 'titan-zero-trades-front-desk' ),
        array(
            'br'     => array(),
            'strong' => array(),
        )
    ); 
    ?> 
</p>
<div class="clearer"></div>


            <div id="multicalendar"><div id="cal<?php echo intval($this->item); ?>" class="multicalendar"></div></div>

             <div style="clear:both;height:20px" ></div>

</div>

<?php } ?>













