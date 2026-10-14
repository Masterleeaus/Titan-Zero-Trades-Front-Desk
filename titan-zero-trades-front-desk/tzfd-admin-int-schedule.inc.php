<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.Security.NonceVerification.Recommended -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked. Read-only schedule parameters on a capability-gated admin screen; this screen changes no state.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';

$this->item = intval(sanitize_text_field( wp_unslash( $_GET["cal"] ?? '' ) ));

$current_user = wp_get_current_user();
$tzfd_current_user_access = current_user_can('manage_options');
$tzfd_calendar_access_ids = TZFD_Access_List::decode( $this->get_option( 'tzfd_user_access', '' ) );

if ( !is_admin() || (!$tzfd_current_user_access && !in_array($current_user->ID, $tzfd_calendar_access_ids, true)))
{
    echo 'Direct access not allowed.';
    exit;
}

$tzfd_firstday = 0;
if ($this->item != 0)
{
    $tzfd_myform = $wpdb->get_results( $wpdb->prepare('SELECT * FROM %i WHERE id=%d', $wpdb->prefix.'tzfd_forms', $this->item) ); 
    $tzfd_raw_form_str = $this->cleanJSON( $tzfd_myform[0]->form_structure );
    $tzfd_form_data = json_decode( $tzfd_raw_form_str );
    foreach($tzfd_form_data[0] as $tzfd_item)
    {
        if ($tzfd_item->ftype == 'fapp')
        {
            $tzfd_firstday = $tzfd_item->firstDay;
            break;
        }
    }
}

$tzfd_default_from = gmdate("Y-m-d",strtotime("today -10 days"));
$tzfd_default_to = gmdate("Y-m-d",strtotime("today +30 days"));

$tzfd_rawfrom = (isset($_GET["dfrom"]) ? sanitize_text_field(wp_unslash( $_GET["dfrom"] ?? '' )) : '');
$tzfd_rawto = (isset($_GET["dto"]) ? sanitize_text_field( wp_unslash( $_GET["dto"] ?? '' ) ) : '');

$tzfd_rawfrom = str_replace(',',' ',$tzfd_rawfrom);
$tzfd_rawto = str_replace(',',' ',$tzfd_rawto);

if ($this->get_option('date_format', 'mm/dd/yy') == 'dd/mm/yy' || $this->get_option('date_format', 'mm/dd/yy') == 'd M, y')
{
    $tzfd_rawfrom = str_replace('/','.',$tzfd_rawfrom);
    $tzfd_rawto = str_replace('/','.',$tzfd_rawto);
}

$tzfd_dfrom = ($tzfd_rawfrom != '' ? gmdate("Y-m-d", strtotime($tzfd_rawfrom)) : $tzfd_default_from);
$tzfd_dto = ($tzfd_rawto != '' ? gmdate("Y-m-d", strtotime($tzfd_rawto)) : $tzfd_default_to);

if ($this->get_option('date_format', 'mm/dd/yy') == 'd M, y')
{
	$tzfd_dfrom_formatted = gmdate("d/m/Y", strtotime($tzfd_dfrom));
    $tzfd_dto_formatted = gmdate("d/m/Y", strtotime($tzfd_dto));
}
else
{
    $tzfd_dfrom_formatted = $this->format_date($tzfd_dfrom);
    $tzfd_dto_formatted = $this->format_date($tzfd_dto);
}

// Page assets (review R2). Enqueued here because this template renders after admin_enqueue_scripts.
$tzfd_schedule_calendar_view = isset( $_GET["calendarview"] );
$tzfd_schedule_deps = array( 'jquery', 'jquery-ui-core', 'jquery-ui-datepicker' );
if ( $tzfd_schedule_calendar_view && wp_script_is( 'tzfdtslots-schedcal-tzfdcalendar', 'registered' ) ) {
    $tzfd_schedule_deps[] = 'tzfdtslots-schedcal-tzfdcalendar';
}
$tzfd_dformatc = $this->get_option( 'date_format', 'mm/dd/yy' );
if ( $tzfd_dformatc == 'd M, y' ) $tzfd_dformatc = 'dd/mm/yy';

wp_enqueue_script( 'tzfd-schedule', plugins_url( 'js/inline/admina-schedule.js', __FILE__ ), $tzfd_schedule_deps, '2.0.1', true );
wp_localize_script( 'tzfd-schedule', 'tzfdScheduleData', array(
    'calendarView' => $tzfd_schedule_calendar_view,
    'calendarId'   => intval( $this->item ),
    'firstDay'     => intval( $tzfd_firstday ),
    // The calendar feed (mv) checks this anonce on every request the calendar script makes.
    'pathCalendar' => $this->get_site_url( true ) . '?tzfd_app_action=mv&formid=' . intval( $this->item ) . '&anonce=' . rawurlencode( wp_create_nonce( 'tzfd_actions_booking' ) ),
    'dateFormat'   => $tzfd_dformatc,
) );

?>


<h1><?php esc_html_e('Schedule','titan-zero-trades-front-desk'); ?> - <?php if ($this->item != 0) echo esc_html($tzfd_myform[0]->form_name); else echo esc_html__( 'All forms', 'titan-zero-trades-front-desk' ); ?></h1>

<div class="tzfd-buttons-container">

    <?php if (!isset($_GET["calendarview"])) { ?>
     <input type="button" value="<?php esc_html_e('Change to Schedule Calendar View','titan-zero-trades-front-desk'); ?>" class="button button-primary" data-tzfd-url="<?php echo esc_attr('?page='.$this->menu_parameter.'&cal='.intval($this->item).'&schedule=1&calendarview=1'); ?>" />
    <?php } else { ?>
     <input type="button" value="<?php esc_html_e('Change to Schedule List View','titan-zero-trades-front-desk'); ?>" class="button button-primary" data-tzfd-url="<?php echo esc_attr('?page='.$this->menu_parameter.'&cal='.intval($this->item).'&schedule=1'); ?>" />
     <input type="button" value="<?php esc_html_e('Customize Contents of this Calendar','titan-zero-trades-front-desk'); ?>" class="button" style="margin-left:20px;" data-tzfd-url="<?php echo esc_attr('?page='.$this->menu_parameter.'_settings&gotab=schedulecalarea'); ?>" />
    <?php } ?>
	<a href="<?php print esc_attr(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
	<div class="clear"></div>
</div>

<?php if (!isset($_GET["calendarview"])) { ?>

<div class="tzfd-section-container">
	<div class="tzfd-section">
      <form action="admin.php" method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr($this->menu_parameter); ?>" />
        <input type="hidden" name="cal" value="<?php echo intval($this->item); ?>" />
        <input type="hidden" name="schedule" value="1" />
        <input type="hidden" name="anonce" value="<?php echo esc_attr( wp_create_nonce( 'tzfd_actions_booking' ) ); ?>" />
		<nobr><label for="dfrom"><?php esc_html_e('From','titan-zero-trades-front-desk'); ?>:</label> <input autocomplete="off" type="text" id="dfrom" name="dfrom" value="<?php echo esc_attr($tzfd_dfrom_formatted); ?>" >&nbsp;&nbsp;</nobr>
		<nobr><label for="dto"><?php esc_html_e('To','titan-zero-trades-front-desk'); ?>:</label> <input autocomplete="off" type="text" id="dto" name="dto" value="<?php echo esc_attr($tzfd_dto_formatted); ?>" >&nbsp;&nbsp;</nobr>
		<nobr><label for="paid"><?php esc_html_e('Paid Status','titan-zero-trades-front-desk'); ?>:</label> <select id="paid" name="paid">
         <option value=""><?php esc_html_e('All','titan-zero-trades-front-desk'); ?></option>
         <option value="1" <?php if (!empty($_GET["paid"])) echo ' selected'; ?>><?php esc_html_e('Paid only','titan-zero-trades-front-desk'); ?></option>
      </select></nobr>
        <nobr><label for="status"><?php esc_html_e('Booking Status','titan-zero-trades-front-desk'); ?>:</label> <?php $this->render_status_box('status', (!isset($_GET["status"])?'all':sanitize_text_field(wp_unslash( $_GET["status"] ?? '' ))), true); ?></nobr>
		<nobr><label for="cal"><?php esc_html_e('Item','titan-zero-trades-front-desk'); ?>:</label> <select id="cal" name="cal">
          <?php if ($tzfd_current_user_access) { ?> <option value="0">[<?php esc_html_e('All Items','titan-zero-trades-front-desk'); ?>]</option><?php } ?>
   <?php
    $tzfd_myrows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i", $wpdb->prefix . 'tzfd_forms' ) );  
    $tzfd_saved_id = $this->item;
    foreach ($tzfd_myrows as $tzfd_item)
    {
        $this->setId($tzfd_item->id);
        if ($tzfd_current_user_access || in_array($current_user->ID, $tzfd_calendar_access_ids, true))
           echo '<option value="'.intval($tzfd_item->id).'"'.(intval($tzfd_item->id)==intval($tzfd_saved_id)?" selected":"").'>'.esc_html($tzfd_item->form_name).'</option>';
    }
    $this->setId($tzfd_saved_id);
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

 <div class="tzfdapp_no_wrap">
  <div class="tzfd_field_0 tzfd_field_header"><?php esc_html_e('Date','titan-zero-trades-front-desk'); ?></div>
  <div class="tzfd_field_1 tzfd_field_header"><?php esc_html_e('Slot','titan-zero-trades-front-desk'); ?></div>
  <div class="tzfd_field_2 tzfd_field_header"><?php esc_html_e('Service','titan-zero-trades-front-desk'); ?></div>
  <div class="tzfd_field_3 tzfd_field_header"><?php esc_html_e('Qty','titan-zero-trades-front-desk'); ?></div>
  <div class="tzfd_field_4 tzfd_field_header"><?php esc_html_e('Paid','titan-zero-trades-front-desk'); ?></div>
  <div class="tzfd_field_5 tzfd_field_header"><?php esc_html_e('Email','titan-zero-trades-front-desk'); ?></div>
  <div class="tzfd_field_6 tzfd_field_header"><?php esc_html_e('Data','titan-zero-trades-front-desk'); ?></div>
  <div class="tzfd_field_7 tzfd_field_header"><?php esc_html_e('Status','titan-zero-trades-front-desk'); ?></div>
  <div class="tzfd_field_8 tzfd_field_header"><?php esc_html_e('Options','titan-zero-trades-front-desk'); ?></div>
  <div class="tzfdapp_break"></div>
 </div>
 <div class="tzfdapp_break"></div>
<?php

echo wp_kses( $this->filter_list( array(
                               'calendar' => ($this->item != 0 ? intval($this->item) : ''),
                               'fields' => 'DATE,TIME,SERVICE,quantity,paid,email,data,cancelled,options',
	    	                   'from' => esc_html($tzfd_dfrom),
	    	                   'to' => esc_html($tzfd_dto),
                               'paidonly' => esc_html( (!empty($_GET["paid"])?sanitize_text_field(wp_unslash( $_GET["paid"] ?? '' )):'')),
                               'status' => (!isset($_GET["status"])?'all':esc_html(sanitize_text_field(wp_unslash( $_GET["status"] ?? '' ))))
                               ) ), $this->admin_markup_allowed() );

?>
</div>


<div class="tzfd-buttons-container">
    <input type="button" value="<?php esc_html_e('Print','titan-zero-trades-front-desk'); ?>" class="button button-primary" id="tzfd-print-schedule" />
	<a href="<?php print esc_attr(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
	<div class="clear"></div>
</div>


<?php } else { ?>

<div id="tzfdabc_printable_contents">

<p>
    <?php 
    echo wp_kses( 
        __( 'The purpose of this page is to <strong>display the bookings/schedule in a calendar view</strong>. You can add bookings from the public booking form or', 'titan-zero-trades-front-desk' ),
        array(
            'strong' => array(),
        )
    ); 
    ?> 
    <a href="?page=<?php echo esc_attr( $this->menu_parameter ); ?>&cal=<?php echo intval( $this->item ); ?>&addbk=1">
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













