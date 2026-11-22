<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-request-scalar.php';
require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';

require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; no state change.
$this->item = TZFD_Admin_Request_Ids::calendar( $_GET );
if ( null === $this->item ) {
    wp_die( esc_html__( 'Invalid calendar identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}

require_once __DIR__ . '/includes/class-tzfd-admin-report-scope.php';
$tzfd_report_company = TZFD_Admin_Report_Scope::enforce( $this->item );

$current_user        = wp_get_current_user();
$tzfd_current_user_access = current_user_can( 'tz_fd_access' );

$tzfd_access_option = $this->get_option( 'tz_fd_user_access', '' );
$tzfd_allowed_users = TZFD_Access_List::decode( $tzfd_access_option );

$tzfd_has_custom_access = is_array( $tzfd_allowed_users ) && in_array( $current_user->ID, $tzfd_allowed_users, true );

if ( ! is_admin() || ( ! $tzfd_current_user_access && ! $tzfd_has_custom_access ) ) {
    wp_die( 
        esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' ), 
        '', 
        array( 'response' => 403 ) 
    );
}

define('TZFD_HOUR_BLOCK_TIMES', true);

$tzfd_message = '';
$tzfd_opensecond = false;



if ( 'POST' === tzfd_request_text( 'server', 'REQUEST_METHOD' ) && isset( $_POST[$this->prefix.'_pform_process'] ) ) 
{
    echo '<div id="setting-error-settings_updated" class="updated settings-error"><p><strong>';
    esc_html_e('Booking added. It appears now in the','titan-zero-trades-front-desk');
    echo ' <a href="'.esc_url( add_query_arg( array( 'page' => sanitize_key( $this->menu_parameter ), 'cal' => absint( $this->item ), 'list' => 1 ), admin_url( 'admin.php' ) ) ).'">';
    esc_html_e('bookings list','titan-zero-trades-front-desk');
    echo '</a>. </strong></p></div>';
} else if ($this->get_param($this->prefix.'_blockmultiple') == '1' && is_admin() )
{
    $tzfd_opensecond = true;
    echo '<div id="setting-error-settings_updated" class="updated settings-error"><p><strong>';
    esc_html_e('Blocked time added. It appears listed in the','titan-zero-trades-front-desk');
    echo ' <a href="'.esc_url( add_query_arg( array( 'page' => sanitize_key( $this->menu_parameter ), 'cal' => absint( $this->item ), 'list' => 1 ), admin_url( 'admin.php' ) ) ).'">';
    esc_html_e('bookings list','titan-zero-trades-front-desk');
    echo '</a>. ';
    esc_html_e('You can un-block it from the ','titan-zero-trades-front-desk');
    echo '<a href="'.esc_url( add_query_arg( array( 'page' => sanitize_key( $this->menu_parameter ), 'cal' => absint( $this->item ), 'list' => 1 ), admin_url( 'admin.php' ) ) ).'">';
    esc_html_e('bookings list','titan-zero-trades-front-desk');
    echo '</a>.</strong></p></div>';
}


$tzfd_local_selected = array();
// Selected calendars are sanitized before use. The POST is accepted only with a valid tz_fd_actions_admin anonce.
$tzfd_posted_calendars = array();
$tzfd_is_calendar_selection = 'POST' === tzfd_request_text( 'server', 'REQUEST_METHOD' ) && isset( $_POST['selectedcalendar'] );
if ( $tzfd_is_calendar_selection ) {
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_admin' ) ) {
        wp_die( esc_html__( 'Invalid blocked-time security token.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
    }
    $tzfd_posted_calendars_input = isset( $_POST['selectedcalendar'] ) && is_array( $_POST['selectedcalendar'] )
        ? array_map( 'sanitize_text_field', wp_unslash( $_POST['selectedcalendar'] ) )
        : null;
    if ( ! is_array( $tzfd_posted_calendars_input ) || count( $tzfd_posted_calendars_input ) < 1 || count( $tzfd_posted_calendars_input ) > 100 ) {
        wp_die( esc_html__( 'Invalid calendar selection.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
    }
    foreach ( $tzfd_posted_calendars_input as $tzfd_calendar_raw ) {
        $tzfd_local_calendar_id = TZFD_Admin_Request_Ids::positive( $tzfd_calendar_raw );
        if ( null === $tzfd_local_calendar_id || ! TZFD_Admin_Report_Scope::authorized( $tzfd_local_calendar_id ) ) {
            wp_die( esc_html__( 'Calendar access denied.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        $tzfd_posted_calendars[] = $tzfd_local_calendar_id;
    }
    $tzfd_local_selected = array_values( array_unique( $tzfd_posted_calendars ) );
} else {
    $tzfd_local_selected = array( $this->item );
}


$tzfd_nonce = wp_create_nonce( 'tz_fd_actions_admin' );

// Page assets (review R2). Enqueued here because this template renders after admin_enqueue_scripts.
wp_register_style( 'tzfd-block-times-inline', false, array(), '2.0.1' );
wp_enqueue_style( 'tzfd-block-times-inline' );
wp_add_inline_style( 'tzfd-block-times-inline', ".clear{clear:both;}\n.tzfd-first-button{margin-right:10px !important;}\n.tzfd-buttons-container{margin:1em 1em 1em 0;}\n.tzfd-return-link{float:right;}\n#tzfdform .donotdisplayfield { display:none !important;}\n#tzfdform .captcha { display:none !important;}" );

// Flag read by the public form script (tz_fd_hour_in_admin). Printed in the footer before the form scripts.
wp_register_script( 'tzfd-block-times-inline', false, array(), '2.0.1', true );
wp_enqueue_script( 'tzfd-block-times-inline' );
wp_add_inline_script( 'tzfd-block-times-inline', 'var tz_fd_hour_in_admin=true;', 'before' );

wp_enqueue_script( 'tzfd-block-times', plugins_url( 'js/inline/admina-block-times.js', __FILE__ ), array( 'jquery', 'jquery-ui-core', 'jquery-ui-sortable', 'jquery-ui-tabs', 'jquery-ui-droppable', 'jquery-ui-button', 'jquery-ui-datepicker' ), '2.0.1', true );
wp_localize_script( 'tzfd-block-times', 'tzfdBlockTimesData', array(
    'selectDateMessage' => __( 'Please select at least one date in the calendar', 'titan-zero-trades-front-desk' ),
) );


?>
<div class="wrap">

<h1><?php echo wp_kses( TZFD_UI_Graphics::brand_html( 'availability' ), TZFD_UI_Graphics::allowed_html() ); ?><?php esc_html_e('Block Times','titan-zero-trades-front-desk'); ?></h1>

<div class="tzfd-buttons-container">
	<a href="<?php print esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
    <div class="clear"></div>
</div>


<div class="tzfd-adintsection-container">
	<div class="tzfd-breadcrumb">
		<div class="tzfd-step<?php if (!$tzfd_opensecond) { ?> tzfd-step-active<?php } ?>" data-step="1">
			<i>A</i>
			<label><?php esc_html_e('Single - Block Existing Time-slot','titan-zero-trades-front-desk'); ?></label>
		</div>
		<div class="tzfd-step<?php if ($tzfd_opensecond) { ?> tzfd-step-active<?php } ?>" data-step="2">
			<i>B</i>
			<label><?php esc_html_e('Multiple - Block time range','titan-zero-trades-front-desk'); ?></label>
		</div>
	</div>

    <div class="tzfd-adintsection<?php if (!$tzfd_opensecond) { ?> tzfd-adintsection-active"<?php } ?> data-step="1">
       <div class="inside"> 
	   
            <p><?php echo wp_kses( 
                                __( 'This page is for <strong>blocking some of the available times</strong>. For services with multiple capacity be sure to select the "quantity" to be blocked.', 'titan-zero-trades-front-desk' ), 
                                array(
                                    'strong' => array(),
                                ) 
                            ); ?> 
               <?php esc_html_e('To un-block times, delete the "blocked" entry from the','titan-zero-trades-front-desk'); ?> <a href="<?php echo esc_url( add_query_arg( array( 'page' => sanitize_key( $this->menu_parameter ), 'cal' => absint( $this->item ), 'list' => 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e('booking orders list','titan-zero-trades-front-desk'); ?></a>.</p> </p>
            
            
            <p><?php echo wp_kses( 
                                    __( 'If you want to block complete dates please use instead the invalid dates feature.', 'titan-zero-trades-front-desk' ), 
                                    array(
                                        'a' => array(
                                            'href'   => array(),
                                            'target' => array(),
                                        ),
                                    ) 
                                ); ?></p>
            
            
            <?php $this->output_filter_content(array('id' => intval($this->item) ));  ?>
       </div>
     </div>
     

    <div class="tzfd-adintsection<?php if ($tzfd_opensecond) { ?> tzfd-adintsection-active"<?php } ?>" data-step="2">
      <div class="inside" id="tzfdform">   

        <p>
            <?php 
            echo wp_kses( 
                __( 'This page is for <strong>blocking a range of time</strong> for ALL services and optionally for all booking forms.', 'titan-zero-trades-front-desk' ),
                array(
                    'strong' => array(),
                )
            ); 
            ?>
        </p>

        <p>
            <?php 
            echo wp_kses( 
                __( 'If you want to block complete dates please use instead the invalid dates feature.', 'titan-zero-trades-front-desk' ),
                array(
                    'a' => array(
                        'href'   => array(),
                        'target' => array(),
                    ),
                )
            ); 
            ?>
        </p>
         
        <form class="tz_fd_p_form" name="tz_fd_frontdesk_pform_2" id="tz_fd_frontdesk_pform_2" action="" method="post" enctype="multipart/form-data">
        <input type="hidden" name="anonce" value="<?php echo esc_attr($tzfd_nonce); ?>" />
        <input type="hidden" name="<?php echo esc_attr($this->prefix); ?>_blockmultiple" value="1" />
        <strong><?php esc_html_e('Booking Form','titan-zero-trades-front-desk'); ?>:</strong><br />
        <select name="selectedcalendar[]" id="selectedcalendar" multiple required>         
              <?php
                    
                $tzfd_myrows     = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE company_id=%d", $wpdb->prefix . TZFD_Tables::FORMS, $tzfd_report_company ) );                                                                     
                foreach ( $tzfd_myrows as $tzfd_item ) {
                    $tzfd_allowed_users = TZFD_Access_List::decode( $tzfd_item->tz_fd_user_access );
                    $tzfd_has_user_access = is_array( $tzfd_allowed_users ) && in_array( $current_user->ID, $tzfd_allowed_users, true );

                    if ( $tzfd_current_user_access || $tzfd_has_user_access ) {
                       $tzfd_is_selected = ( ! empty( $tzfd_local_selected ) && is_array( $tzfd_local_selected ) && in_array( $tzfd_item->id, $tzfd_local_selected  ) ) ? ' selected' : '';
                        
                        echo '<option value="' . intval( $tzfd_item->id ) . '"' . esc_html($tzfd_is_selected) . '>';
                        echo esc_html( $tzfd_item->form_name );
                        echo '</option>';
                    }
                }                    
              ?>                          
        </select><br /><em><?php esc_html_e('CTRL+click to mark multiple','titan-zero-trades-front-desk'); ?></em><br /><br />
        
        <strong><?php esc_html_e('Date','titan-zero-trades-front-desk'); ?>:</strong><br />
        <div id="tz_fd_bt_datepicker"></div>
        <em><?php esc_html_e('You can select multiple dates','titan-zero-trades-front-desk'); ?></em>
        <input autocomplete="off" type="hidden" id="bdate" name="bdate" required value="" ><br /><br />
        
        <strong><?php esc_html_e('Start time','titan-zero-trades-front-desk'); ?>:</strong><br />
        <select name="h1"><?php
            for ($tzfd_i=0;$tzfd_i<24;$tzfd_i++)
            {
                echo '<option value="'.intval($tzfd_i).'" '.(($tzfd_i==8)?"selected":"").'>'.intval($tzfd_i).'</option>';
            }
            ?></select> : <select name="m1"><?php
            for ($tzfd_i=0;$tzfd_i<60;$tzfd_i=$tzfd_i+5)
            {
                echo '<option value="'.intval($tzfd_i).'" '.(($tzfd_i==0)?"selected":"").'>'.($tzfd_i<10?"0":"").intval($tzfd_i).'</option>';
            }
            ?></select>
        <!--<input type="text" name="starttime" value="08:00" required>--><br /><br />
        
        <strong><?php esc_html_e('End time','titan-zero-trades-front-desk'); ?>:</strong><br />
        <select name="h2"><?php
            for ($tzfd_i=0;$tzfd_i<24;$tzfd_i++)
            {
                echo '<option value="'.intval($tzfd_i).'" '.(($tzfd_i==17)?"selected":"").'>'.intval($tzfd_i).'</option>';
            }
            ?></select> : <select name="m2"><?php
            for ($tzfd_i=0;$tzfd_i<60;$tzfd_i=$tzfd_i+5)
            {
                echo '<option value="'.intval($tzfd_i).'" '.(($tzfd_i==0)?"selected":"").'>'.($tzfd_i<10?"0":"").intval($tzfd_i).'</option>';
            }
            ?></select><br /><br />
        
        <input type="submit" value="<?php esc_html_e('Block time','titan-zero-trades-front-desk'); ?>" />
        </form>
        
         
       </div>	
    </div>     


</div>	

</div>
