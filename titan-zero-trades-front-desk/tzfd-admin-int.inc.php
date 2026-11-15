<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-request-scalar.php';

require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';

require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view parameter; no state change.
$this->item = TZFD_Admin_Request_Ids::calendar( $_GET );
if ( null === $this->item ) {
    wp_die( esc_html__( 'Invalid calendar identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}
require_once __DIR__ . '/includes/class-tzfd-admin-report-scope.php';
TZFD_Admin_Report_Scope::enforce( $this->item );
$current_user = wp_get_current_user();
$tz_current_user_access = current_user_can( 'tz_fd_access' );
$tz_calendar_access_ids = TZFD_Access_List::decode( $this->get_option( 'tz_fd_user_access', '' ) );
if ( ! is_admin() || ( ! $tz_current_user_access && ! in_array( $current_user->ID, $tz_calendar_access_ids, true ) ) ) {
    wp_die( esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}

$this->option_buffered_item = false;
$this->option_buffered_id = -1;

define('TZFD_BOOK_DEFAULT_fp_from_email', get_the_author_meta('user_email', get_current_user_id()) );
define('TZFD_BOOK_DEFAULT_fp_destination_emails', TZFD_BOOK_DEFAULT_fp_from_email);

// Notice only. Shown only after a save whose anonce is valid (the same tz_fd_actions_admin check the save handler makes).
$tz_saved_notice = ( 'POST' === tzfd_request_text( 'server', 'REQUEST_METHOD' ) && isset( $_POST[$this->prefix.'_post_options'] ) && wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_admin' ) );
if ( $tz_saved_notice )
    echo "<div id='setting-error-settings_updated' class='updated'> <h2>" . esc_html__( 'Settings saved.', 'titan-zero-trades-front-desk' ) . "</h2></div>";

$tz_nonce = wp_create_nonce( 'tz_fd_actions_admin' );
// Page assets (review R2). This template renders after admin_enqueue_scripts, so the handles are enqueued here.
// Both are printed by WordPress on the admin page (footer script, late style).
wp_register_style( 'tzfd-admin-int-inline', false, array(), '2.0.1' );
wp_enqueue_style( 'tzfd-admin-int-inline' );
wp_add_inline_style( 'tzfd-admin-int-inline', '.width50 { width:48% ! important;}' . 'h3.hndle.tzfd-hndle-compact{padding-top:5px;padding-bottom:5px;}' . 'input.button.tzfd-action-right{float:right;margin-right:10px;}' . '.tzfd-hint-small{font-size:10px;color:#a4acba;}' );

wp_enqueue_script( 'tzfd-admin-int', plugins_url( 'js/inline/admina-admin-int.js', __FILE__ ), array( 'jquery', 'jquery-ui-core', 'jquery-ui-sortable', 'jquery-ui-tabs', 'jquery-ui-draggable', 'jquery-ui-droppable', 'jquery-ui-button', 'jquery-ui-datepicker' ), '2.0.1', true );
wp_localize_script( 'tzfd-admin-int', 'tzfdAdminIntData', array(
    'builderUrl' => esc_url_raw( add_query_arg( 'tz_fd_resources', 'admin', home_url( '/' ) ) ),
    'captchaUrl' => esc_url_raw( add_query_arg( array( sanitize_key( $this->prefix . '_captcha' ) => 'captcha', 'inAdmin' => '1' ), home_url( '/' ) ) ),
) );

?>
<div class="wrap">
<h1><?php esc_html_e('Edit','titan-zero-trades-front-desk'); ?> - <?php echo esc_html($this->get_option('form_name','Calendar')); ?></h1>


<form method="post" action="" name="tzfdformconf">
<input name="anonce" type="hidden" value="<?php echo esc_attr($tz_nonce); ?>" />
<input name="<?php echo esc_attr($this->prefix); ?>_post_options" type="hidden" value="1" />
<input name="<?php echo esc_attr($this->prefix); ?>_id" type="hidden" value="<?php echo intval($this->item); ?>" />
<input type="hidden" name="templates" id="templates" value="<?php echo esc_attr( wp_json_encode( $this->available_templates() ) ); ?>" />

<div id="topadminsection"  class="tzfd-buttons-container">
	<input type="submit" class="button button-primary tzfd-save-btn" name="savereturn" value="<?php esc_html_e('Save Changes and Return','titan-zero-trades-front-desk'); ?>"  />
	<a href="<?php print esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
	<div class="clear"></div>
</div>

<div class="tzfd-adintsection-container">
	<div class="tzfd-breadcrumb">
		<div class="tzfd-step tzfd-step-active" data-step="1">
			<i>1</i>
			<label><?php esc_html_e('Editor','titan-zero-trades-front-desk'); ?></label>
		</div>
		<div class="tzfd-step" data-step="2">
			<i>2</i>
			<label><?php esc_html_e('General Settings','titan-zero-trades-front-desk'); ?></label>
		</div>
		<div class="tzfd-step" data-step="3">
			<i>3</i>
			<label><?php esc_html_e('Notification Emails','titan-zero-trades-front-desk'); ?></label>
		</div>
		<div class="tzfd-step" data-step="4">
			<i>4</i>
			<label><?php esc_html_e('Antispam','titan-zero-trades-front-desk'); ?></label>
		</div>
		<div class="tzfd-step" data-step="5">
			<i>5</i>
			<label><?php esc_html_e('Reports','titan-zero-trades-front-desk'); ?></label>
		</div>
        <div class="tzfd-step" data-step="6">
			<i>6</i>
			<label><?php esc_html_e('Add Ons','titan-zero-trades-front-desk'); ?></label>
		</div>
	</div>



    <div class="tzfd-adintsection tzfd-adintsection-active" data-step="1">
       <div class="inside">

         <input type="hidden" name="form_structure" id="form_structure" size="180" value="<?php echo esc_attr( str_replace( array( "\r", "\n" ), '', $this->cleanJSON( $this->admin_process_json( $this->get_option( 'form_structure', TZFD_BOOK_DEFAULT_form_structure ) ) ) ) ); ?>" />



         <div style="background:#1f232d;" class="form-builder">

             <div class="column width50 ctrlsColumn">
                 <div id="tabs">
         			<ul>
         				<li><a href="#tzfdtabs-1"><?php esc_html_e('Add a Field','titan-zero-trades-front-desk'); ?></a></li>
         				<li><a href="#tzfdtabs-2"><?php esc_html_e('Field Settings','titan-zero-trades-front-desk'); ?></a></li>
         				<li><a href="#tzfdtabs-3"><?php esc_html_e('Form Settings','titan-zero-trades-front-desk'); ?></a></li>
         			</ul>
         			<div id="tzfdtabs-1"></div>
         			<div id="tzfdtabs-2"></div>
         			<div id="tzfdtabs-3"></div>
         		</div>
             </div>
             <div class="columnr width50 padding10" id="tzfdform">
                 <div id="formheader"></div>
                 <div id="fieldlist"></div>
                 <!--<div class="button" id="saveForm">Save Form</div>-->
             </div>
             <div class="clearer"></div>

         </div>
      </div>
      <br  />
      <input type="submit" value="<?php esc_html_e('Save Changes and Continue Editing','titan-zero-trades-front-desk'); ?>" class="button-primary" /> 
<br />      
       <br />
        <div style="padding:10px;background-color:#ffffdd;border:1px dotted black;">
            <p><?php echo wp_kses( 
                        __( '<STRONG>In this version</STRONG> the form builder supports <STRONG>calendar, text, email and acceptance checkbox fields</STRONG>.', 'titan-zero-trades-front-desk' ), 
                        array(
                            'STRONG' => array(),
                        ) 
                    ); ?></p>
                          
         </div>  
         
      <br />
      
      <!-- TEXT DEFINITIONS -->
	  <h2><?php esc_html_e('Labels and Texts','titan-zero-trades-front-desk'); ?></h2>
	  <hr />

      <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Button Labels','titan-zero-trades-front-desk'); ?></span></h3>
      <div class="inside">
         <table class="form-table tzfdsmallpadding1">
            <tr valign="top">
            <td scope="row">
             <strong><?php esc_html_e('Submit button label (text)','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_submitbtn" size="40" value="<?php $tz_label = ($this->get_option('vs_text_submitbtn', 'Submit')); echo esc_attr($tz_label==''?'Submit':$tz_label); ?>" />
            </td>
            <td>
              <strong><?php esc_html_e('Page {0} of {0} (text','titan-zero-trades-front-desk'); ?>):</strong><br />
              <input type="text" name="vs_text_pageof" size="40" value="<?php $tz_label = ($this->get_option('vs_text_pageof', 'Page {0} of {0}')); echo esc_attr($tz_label==''?'Page {0} of {0}':$tz_label); ?>" />
            </td>
            </tr>
            <tr valign="top">
            <td scope="row">
             <strong><?php esc_html_e('Previous page button label (text)','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_previousbtn" size="40" value="<?php $tz_label = ($this->get_option('vs_text_previousbtn', 'Previous')); echo esc_attr($tz_label==''?'Previous':$tz_label); ?>" /></td>
            <td scope="row">
             <strong><?php esc_html_e('Next page button label (text)','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_nextbtn" size="40" value="<?php $tz_label = $this->get_option('vs_text_nextbtn', 'Next'); echo esc_attr($tz_label==''?'Next':$tz_label); ?>" /></td>
            </tr>
            <tr valign="top">
            <td colspan="2">The  <em>class="pbSubmit"</em> can be used to modify the button styles.  For further modifications the submit button is located at the end of the file <em>"tzfd-public-int.inc.php"</em>. For general CSS styles modifications to the form and samples check the FAQ.</td>
            </tr>
         </table>
      </div>
      
      <hr  size="1" />

      <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Error messages for validation rules','titan-zero-trades-front-desk'); ?></span></h3>
      <div class="inside">
         <table class="form-table tzfdsmallpadding1">
            <tr valign="top">
            <td scope="row">
             <strong><?php esc_html_e('"is required" text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_is_required" size="40" value="<?php echo esc_attr($this->get_option('vs_text_is_required', TZFD_BOOK_DEFAULT_vs_text_is_required)); ?>" />
            </td>
            <td scope="row">
             <strong><?php esc_html_e('"is email" text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_is_email" size="40" value="<?php echo esc_attr($this->get_option('vs_text_is_email', TZFD_BOOK_DEFAULT_vs_text_is_email)); ?>" />
            </td>
            </tr>
            <tr valign="top">
            <td scope="row">
             <strong><?php esc_html_e('"is valid captcha" text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="cv_text_enter_valid_captcha" size="40" value="<?php echo esc_attr($this->get_option('cv_text_enter_valid_captcha', TZFD_BOOK_DEFAULT_cv_text_enter_valid_captcha)); ?>" />
            </td>
            <td scope="row"><strong><?php esc_html_e('"is valid date (mm/dd/yyyy)" text','titan-zero-trades-front-desk'); ?>:</strong><br /><input type="text" name="vs_text_datemmddyyyy" size="40" value="<?php echo esc_attr($this->get_option('vs_text_datemmddyyyy', TZFD_BOOK_DEFAULT_vs_text_datemmddyyyy)); ?>" /></td>
            </tr>
            <tr valign="top">
            <td scope="row">
             <strong><?php esc_html_e('"is valid date (dd/mm/yyyy)" text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_dateddmmyyyy" size="40" value="<?php echo esc_attr($this->get_option('vs_text_dateddmmyyyy', TZFD_BOOK_DEFAULT_vs_text_dateddmmyyyy)); ?>" />
            </td>
            <td scope="row">
             <strong><?php esc_html_e('"is number" text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_number" size="40" value="<?php echo esc_attr($this->get_option('vs_text_number', TZFD_BOOK_DEFAULT_vs_text_number)); ?>" />
            </td>
            </tr>
            <tr valign="top">
            <td scope="row">
             <strong><?php esc_html_e('"only digits" text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_digits" size="40" value="<?php echo esc_attr($this->get_option('vs_text_digits', TZFD_BOOK_DEFAULT_vs_text_digits)); ?>" />
            </td>
            <td scope="row">
             <strong><?php esc_html_e('"under maximum" text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_max" size="40" value="<?php echo esc_attr($this->get_option('vs_text_max', TZFD_BOOK_DEFAULT_vs_text_max)); ?>" />
            </td>
            </tr>
            <tr valign="top">
            <td scope="row">
             <strong><?php esc_html_e('"over minimum" text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_min" size="40" value="<?php echo esc_attr($this->get_option('vs_text_min', TZFD_BOOK_DEFAULT_vs_text_min)); ?>" />
            </td>
            <td scope="row">
             <strong><?php esc_html_e('"Max appointments allowed message" text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_maxapp" size="40" value="<?php echo esc_attr($this->get_option('vs_text_maxapp', TZFD_BOOK_DEFAULT_vs_text_maxapp)); ?>" />
            </td>
            </tr>

         </table>
      </div>
      
      <hr  size="1" />

      <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Other Texts','titan-zero-trades-front-desk'); ?></span></h3>
      <div class="inside">
         <table class="form-table tzfdsmallpadding1">
            <tr valign="top">
            <td scope="row">
             <strong><?php esc_html_e('"Quantity" field label','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_quantity" size="40" value="<?php echo esc_attr($this->get_option_not_empty('vs_text_quantity', 'Quantity')); ?>" />
            </td>
            <td>
              <strong><?php esc_html_e('"Cancel" link label','titan-zero-trades-front-desk'); ?>):</strong><br />
              <input type="text" name="vs_text_cancel" size="40" value="<?php echo esc_attr($this->get_option_not_empty('vs_text_cancel', 'Cancel')); ?>" />
            </td>
            </tr>
            <tr valign="top">
            <td scope="row">
             <strong><?php esc_html_e('"Cost" label','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_cost" size="40" value="<?php echo esc_attr($this->get_option_not_empty('vs_text_cost', 'Cost')); ?>" />
            </td>
            <td scope="row">
             <strong><?php esc_html_e('"No more slots available." text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_nomore" size="40" value="<?php echo esc_attr($this->get_option_not_empty('vs_text_nomore', __( 'No more slots available.', 'titan-zero-trades-front-desk' ))); ?>" />
            </td>
            </tr>  
            <tr> 			
            <td scope="row">
             <strong><?php esc_html_e('"Selected time is no longer available..." text','titan-zero-trades-front-desk'); ?>:</strong><br />
             <input type="text" name="vs_text_nmore" size="40" value="<?php echo esc_attr($this->get_option_not_empty('vs_text_nmore', __( 'Selected time is no longer available. Please select a different time.', 'titan-zero-trades-front-desk' ))); ?>" /></td>
            </tr>              
         </table>
      </div>     
      
        <hr>
		<div class="tzfd-buttons-container">
			<input type="button" value="<?php esc_html_e('Next Step - General Settings >','titan-zero-trades-front-desk'); ?>" class="button tzfd-action-right" data-tzfd-goto="2" />
			<?php if ($tz_current_user_access) { ?><input type="submit" name="savepublish" value="<?php esc_html_e('Save and Publish','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" /><?php } ?>
			<input type="submit" name="savereturn" value="<?php esc_html_e('Save and Return','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" />
			<div class="clear"></div>
		</div>
     </div>
 

     <div class="tzfd-adintsection" data-step="2">
      
      <div class="inside">


         <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Confirmation / Thank you page','titan-zero-trades-front-desk'); ?></span></h3>
         <table class="form-table">
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Confirmation / Thank you page','titan-zero-trades-front-desk'); ?></th>
            <td><input type="text" name="fp_return_page" size="70" value="<?php echo esc_attr($this->get_option('fp_return_page', TZFD_BOOK_DEFAULT_fp_return_page)); ?>" />
            <br /><em><?php esc_html_e('Address / URL of the page where the user will be redirected after submiting the booking form','titan-zero-trades-front-desk'); ?></em></td>
            </tr>
          <table> 
         <hr />
         
         <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Booking Status','titan-zero-trades-front-desk'); ?></span></h3>
         <table class="form-table">
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Default status of new bookings','titan-zero-trades-front-desk'); ?></th>
            <td><?php $this->render_status_box('defaultstatus',$this->get_option('defaultstatus', ''));  ?>
            <br /><em><?php esc_html_e('Only "Approved" items are taken in account for the availability verification.','titan-zero-trades-front-desk'); ?></em></td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Default status of paid bookings','titan-zero-trades-front-desk'); ?></th>
            <td><?php $this->render_status_box('defaultpaidstatus',$this->get_option('defaultpaidstatus', ''));  ?>
            <br /><em><?php esc_html_e('This is the status of bookings you add in the admin area. Titan Zero Invoicing also sets this status when it records a full payment.','titan-zero-trades-front-desk'); ?></em></td>
            </tr>            
          <table> 
         <hr />         
         
         <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Global Calendar Settings','titan-zero-trades-front-desk'); ?></span></h3> 
         <table class="form-table">
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Date Format','titan-zero-trades-front-desk'); ?></th>
            <td><?php $tz_v = $this->get_option('date_format','mm/dd/yy'); ?>
         	   <select name="date_format" id="date_format">
    		   <option <?php if ($tz_v == '' || $tz_v == 'mm/dd/yy') echo 'selected'; ?> value="mm/dd/yy"><?php echo esc_html__( 'Default - mm/dd/yyyy', 'titan-zero-trades-front-desk' ); ?></option>
               <option <?php if ($tz_v == 'dd/mm/yy') echo 'selected'; ?> value="dd/mm/yy"><?php echo esc_html__( 'dd/mm/yyyy', 'titan-zero-trades-front-desk' ); ?></option>
               <option <?php if ($tz_v == 'mm.dd.yy') echo 'selected'; ?> value="mm.dd.yy"><?php echo esc_html__( 'mm.dd.yyyy', 'titan-zero-trades-front-desk' ); ?></option>
               <option <?php if ($tz_v == 'dd.mm.yy') echo 'selected'; ?> value="dd.mm.yy"><?php echo esc_html__( 'dd.mm.yyyy', 'titan-zero-trades-front-desk' ); ?></option>
    		   <option <?php if ($tz_v == 'yy-mm-dd') echo 'selected'; ?> value="yy-mm-dd"><?php echo esc_html__( 'ISO 8601 - yyyy-mm-dd', 'titan-zero-trades-front-desk' ); ?></option>
    		   <option <?php if ($tz_v == 'd M, y') echo 'selected'; ?> value="d M, y"><?php echo esc_html__( 'Short (UK) - d M, yy', 'titan-zero-trades-front-desk' ); ?></option>
               <option <?php if ($tz_v == 'M d, yy') echo 'selected'; ?> value="M d, yy"><?php echo esc_html__( 'Short - M d, yy', 'titan-zero-trades-front-desk' ); ?></option>
    		   <option <?php if ($tz_v == 'd MM, y') echo 'selected'; ?> value="d MM, y"><?php echo esc_html__( 'Medium (UK) - d MM, yy', 'titan-zero-trades-front-desk' ); ?></option>
               <option <?php if ($tz_v == 'MM d, yy') echo 'selected'; ?> value="MM d, yy"><?php echo esc_html__( 'Medium - MM d, yy', 'titan-zero-trades-front-desk' ); ?></option>
    		   <option <?php if ($tz_v == 'DD, d MM, yy') echo 'selected'; ?> value="DD, d MM, yy"><?php echo esc_html__( 'Full (UK) - DD, d MM, yyyy', 'titan-zero-trades-front-desk' ); ?></option>
               <option <?php if ($tz_v == 'DD, MM d, yy') echo 'selected'; ?> value="DD, MM d, yy"><?php echo esc_html__( 'Full - DD, MM d, yyyy', 'titan-zero-trades-front-desk' ); ?></option>
        	   </select>
            </td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Calendar Language','titan-zero-trades-front-desk'); ?></th>
            <td><?php $tz_v = $this->get_option('calendar_language',''); ?>
                 <select name="calendar_language" id="calendar_language">
    <option <?php if ($tz_v == '') echo 'selected'; ?> value=""><?php echo esc_html__( ' - auto-detect - ', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'af') echo 'selected'; ?> value="af"><?php echo esc_html__( 'Afrikaans', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'sq') echo 'selected'; ?> value="sq"><?php echo esc_html__( 'Albanian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ar') echo 'selected'; ?> value="ar"><?php echo esc_html__( 'Arabic', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ar_DZ') echo 'selected'; ?> value="ar_DZ"><?php echo esc_html__( 'Arabic (Algeria)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'hy_AM') echo 'selected'; ?> value="hy_AM"><?php echo esc_html__( 'Armenian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'az') echo 'selected'; ?> value="az"><?php echo esc_html__( 'Azerbaijani', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'eu') echo 'selected'; ?> value="eu"><?php echo esc_html__( 'Basque', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'bs_BA') echo 'selected'; ?> value="bs_BA"><?php echo esc_html__( 'Bosnian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'bg_BG') echo 'selected'; ?> value="bg_BG"><?php echo esc_html__( 'Bulgarian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'be_BY') echo 'selected'; ?> value="be_BY"><?php echo esc_html__( 'Byelorussian (Belarusian)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'km') echo 'selected'; ?> value="km"><?php echo esc_html__( 'Cambodian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ca') echo 'selected'; ?> value="ca"><?php echo esc_html__( 'Catalan', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'zh_HK') echo 'selected'; ?> value="zh_HK"><?php echo esc_html__( 'Chinese (Hong Kong SAR)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'zh_CN') echo 'selected'; ?> value="zh_CN"><?php echo esc_html__( 'Chinese (PRC)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'zh_TW') echo 'selected'; ?> value="zh_TW"><?php echo esc_html__( 'Chinese (Taiwan)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'hr') echo 'selected'; ?> value="hr"><?php echo esc_html__( 'Croatian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'cs_CZ') echo 'selected'; ?> value="cs_CZ"><?php echo esc_html__( 'Czech', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'da_DK') echo 'selected'; ?> value="da_DK"><?php echo esc_html__( 'Danish', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'nl_NL') echo 'selected'; ?> value="nl_NL"><?php echo esc_html__( 'Dutch', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'nl_BE') echo 'selected'; ?> value="nl_BE"><?php echo esc_html__( 'Dutch - Belgium', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'en_AU') echo 'selected'; ?> value="en_AU"><?php echo esc_html__( 'English (Australia)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'en_NZ') echo 'selected'; ?> value="en_NZ"><?php echo esc_html__( 'English (New Zealand)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'en_GB') echo 'selected'; ?> value="en_GB"><?php echo esc_html__( 'English (United Kingdom)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'eo_EO') echo 'selected'; ?> value="eo"><?php echo esc_html__( 'Esperanto', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'et') echo 'selected'; ?> value="et"><?php echo esc_html__( 'Estonian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'fo') echo 'selected'; ?> value="fo"><?php echo esc_html__( 'Faeroese', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'fa_IR') echo 'selected'; ?> value="fa_IR"><?php echo esc_html__( 'Farsi', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'fi') echo 'selected'; ?> value="fi"><?php echo esc_html__( 'Finnish', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'fr_FR') echo 'selected'; ?> value="fr_FR"><?php echo esc_html__( 'French', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'fr_CA') echo 'selected'; ?> value="fr_CA"><?php echo esc_html__( 'French (Canada)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'fr_CH') echo 'selected'; ?> value="fr_CH"><?php echo esc_html__( 'French (Switzerland)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'gl_ES') echo 'selected'; ?> value="gl_ES"><?php echo esc_html__( 'Galician', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ka_GE') echo 'selected'; ?> value="ka_GE"><?php echo esc_html__( 'Georgian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'de_DE') echo 'selected'; ?> value="de_DE"><?php echo esc_html__( 'German', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'el') echo 'selected'; ?> value="el"><?php echo esc_html__( 'Greek', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'he_IL') echo 'selected'; ?> value="he_IL"><?php echo esc_html__( 'Hebrew', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'hi_IN') echo 'selected'; ?> value="hi_IN"><?php echo esc_html__( 'Hindi', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'hu_HU') echo 'selected'; ?> value="hu_HU"><?php echo esc_html__( 'Hungarian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'is') echo 'selected'; ?> value="is"><?php echo esc_html__( 'Icelandic', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'id_ID') echo 'selected'; ?> value="id_ID"><?php echo esc_html__( 'Indonesian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'it_IT') echo 'selected'; ?> value="it_IT"><?php echo esc_html__( 'Italian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'it_CH') echo 'selected'; ?> value="it_CH"><?php echo esc_html__( 'Italian (Switzerland)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ja') echo 'selected'; ?> value="ja"><?php echo esc_html__( 'Japanese', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'kk') echo 'selected'; ?> value="kk"><?php echo esc_html__( 'Kazakh', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ky') echo 'selected'; ?> value="ky"><?php echo esc_html__( 'Kirghiz', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ko_KR') echo 'selected'; ?> value="ko_KR"><?php echo esc_html__( 'Korean', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'lv') echo 'selected'; ?> value="lv"><?php echo esc_html__( 'Latvian (Lettish)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'lt_LT') echo 'selected'; ?> value="lt_LT"><?php echo esc_html__( 'Lithuanian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'lb') echo 'selected'; ?> value="lb"><?php echo esc_html__( 'Luxembourgish', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'mk_MK') echo 'selected'; ?> value="mk_MK"><?php echo esc_html__( 'Macedonian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ms_MY') echo 'selected'; ?> value="ms_MY"><?php echo esc_html__( 'Malay', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ml_IN') echo 'selected'; ?> value="ml_IN"><?php echo esc_html__( 'Malayalam', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'no') echo 'selected'; ?> value="no"><?php echo esc_html__( 'Norwegian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'nb_NO') echo 'selected'; ?> value="nb_NO"><?php echo esc_html__( 'Norwegian (Bokm&aring;l)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'nn') echo 'selected'; ?> value="nn"><?php echo esc_html__( 'Norwegian Nynorsk', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'pl_PL') echo 'selected'; ?> value="pl_PL"><?php echo esc_html__( 'Polish', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'pt_PT') echo 'selected'; ?> value="pt_PT"><?php echo esc_html__( 'Portuguese', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'pt_BR') echo 'selected'; ?> value="pt_BR"><?php echo esc_html__( 'Portuguese (Brazil)', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'rm') echo 'selected'; ?> value="rm"><?php echo esc_html__( 'Rhaeto-Romance', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ro_RO') echo 'selected'; ?> value="ro_RO"><?php echo esc_html__( 'Romanian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ru_RU') echo 'selected'; ?> value="ru_RU"><?php echo esc_html__( 'Russian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'sr_SR') echo 'selected'; ?> value="sr_SR"><?php echo esc_html__( 'Serbian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'sk_SK') echo 'selected'; ?> value="sk_SK"><?php echo esc_html__( 'Slovak', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'sl_SI') echo 'selected'; ?> value="sl_SI"><?php echo esc_html__( 'Slovenian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'es_ES') echo 'selected'; ?> value="es_ES"><?php echo esc_html__( 'Spanish', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'sv_SE') echo 'selected'; ?> value="sv_SE"><?php echo esc_html__( 'Swedish', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'tj') echo 'selected'; ?> value="tj"><?php echo esc_html__( 'Tajikistan', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'ta') echo 'selected'; ?> value="ta"><?php echo esc_html__( 'Tamil', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'th') echo 'selected'; ?> value="th"><?php echo esc_html__( 'Thai', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'tr_TR') echo 'selected'; ?> value="tr_TR"><?php echo esc_html__( 'Turkish', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'uk') echo 'selected'; ?> value="uk"><?php echo esc_html__( 'Ukrainian', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'vi') echo 'selected'; ?> value="vi"><?php echo esc_html__( 'Vietnamese', 'titan-zero-trades-front-desk' ); ?></option>
    <option <?php if ($tz_v == 'cy_GB') echo 'selected'; ?> value="cy_GB"><?php echo esc_html__( 'Welsh/UK', 'titan-zero-trades-front-desk' ); ?></option>
    </select>
    </td>
            </tr>
           <tr valign="top">
            <th scope="row"><?php esc_html_e('Include appointment end-time in the confirmation emails?','titan-zero-trades-front-desk'); ?>:</th>
            <td>
              <?php $tz_option = $this->get_option('display_emails_endtime', ''); ?>
              <select name="display_emails_endtime">
               <option value=""<?php if ($tz_option == '') echo ' selected'; ?>><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
               <option value="false"<?php if ($tz_option == 'false') echo ' selected'; ?>><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
              </select>
            </td>
           </tr>             
         </table>
      </div>
      <hr />
      <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Users with access to the messages list','titan-zero-trades-front-desk'); ?></span></h3>
      <div class="inside">
         <table class="form-table">
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Select users with access (CTRL+click for multiple selection)','titan-zero-trades-front-desk'); ?>:</th>
            <td>
              <?php
                 $tz_users = $wpdb->get_results( $wpdb->prepare( "SELECT user_login,ID FROM %i ORDER BY user_login ASC LIMIT 0,500", $wpdb->users ) );
                 $tz_options = $tz_calendar_access_ids;
              ?>
              <select name="tz_fd_user_access[]" multiple="multiple" size="5">
                <?php foreach ($tz_users as $tz_user) { ?>
                 <option value="<?php echo esc_attr($tz_user->ID); ?>"<?php if ( in_array ($tz_user->ID, $tz_options, true) ) echo ' selected'; ?>><?php echo esc_html($tz_user->user_login); ?></option>
                <?php  } ?>
              </select>
            </td>
           </tr>
           <tr valign="top">
            <th scope="row"><?php esc_html_e('Allow selected users access also to the calendar settings?','titan-zero-trades-front-desk'); ?>:</th>
            <td>
              <?php $tz_option = $this->get_option('tz_fd_user_access_settings', ''); ?>
              <select name="tz_fd_user_access_settings">
               <option value="true"<?php if ($tz_option == 'true') echo ' selected'; ?>><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
               <option value=""<?php if ($tz_option == '') echo ' selected'; ?>><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
              </select>
            </td>
           </tr>            
         </table>
      </div>
      <hr>
		<div class="tzfd-buttons-container">
			<input type="button" value="<?php esc_html_e('Next Step - Notification Emails >','titan-zero-trades-front-desk'); ?>" class="button tzfd-action-right" data-tzfd-goto="3" />
			<?php if ($tz_current_user_access) { ?><input type="submit" name="savepublish" value="<?php esc_html_e('Save and Publish','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" /><?php } ?>
			<input type="submit" name="savereturn" value="<?php esc_html_e('Save and Return','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" />
			<div class="clear"></div>
		</div>
     </div>

     <div class="tzfd-adintsection" data-step="3">
      <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('From / To Email Addresses','titan-zero-trades-front-desk'); ?>:</span></h3>
      <div class="inside">
         <table class="form-table">

            <tr valign="top">
            <th scope="row"><?php esc_html_e('Send email "From"','titan-zero-trades-front-desk'); ?> </th>
            <td>
              <?php $tz_option = $this->get_option('fp_emailfrommethod', "fixed"); ?>
               <select name="fp_emailfrommethod">
                 <option value="fixed"<?php if ($tz_option == 'fixed') echo ' selected'; ?>><?php esc_html_e('From fixed email address indicated below - Recommended option','titan-zero-trades-front-desk'); ?></option>
                 <option value="customer"<?php if ($tz_option == 'customer') echo ' selected'; ?>><?php esc_html_e('From the email address indicated by the customer','titan-zero-trades-front-desk'); ?></option>
                </select><br />
                <span class="tzfd-hint-small">
                * <?php esc_html_e('If you select "from fixed..." the customer email address will appear in the "to" address when you hit "reply", this is the recommended setting to avoid mail server restrictions.','titan-zero-trades-front-desk'); ?>
                <br />
                * <?php esc_html_e('If you select "from customer email" then the customer email will appear also visually when you receive the email, but this isn\'t supported by all hosting services, so this
                option isn\'t recommended in most cases.','titan-zero-trades-front-desk'); ?>
                </span>
            </td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('"From" email (for fixed "from" addresses)','titan-zero-trades-front-desk'); ?></th>
            <td><input type="text" name="fp_from_email" id="fp_from_email" size="40" value="<?php echo esc_attr($this->get_option('fp_from_email', TZFD_BOOK_DEFAULT_fp_from_email)); ?>" /><div id="tz_fd_email_warning" style="margin-top:3px;"></div></td>
            </tr>
            
            <tr valign="top">
            <th scope="row"><?php esc_html_e('"From" name (ex: company name)','titan-zero-trades-front-desk'); ?></th>
            <td><input type="text" name="fp_from_name" id="fp_from_name"" size="40" value="<?php echo esc_attr($this->get_option('fp_from_name', '')); ?>" /></td>
            </tr>          

            <th scope="row"><?php esc_html_e('Send email "To"','titan-zero-trades-front-desk'); ?> </th>
            <td>
              <?php $tz_option = $this->get_option('fp_emailtomethod', "fixed"); ?>
               <select name="fp_emailtomethod">
                 <option value="fixed"<?php if ($tz_option == 'fixed') echo ' selected'; ?>><?php esc_html_e('To the fixed email(s) address(es) indicated below - Recommended option','titan-zero-trades-front-desk'); ?></option>
                 <option value="customer"<?php if ($tz_option == 'customer') echo ' selected'; ?>><?php esc_html_e('To the email address selected in a form field (ex: captcha image enabled is recommended in this case)','titan-zero-trades-front-desk'); ?></option>
                </select><br />
                <span class="tzfd-hint-small">
                * <?php esc_html_e('If you select "To fixed..." enter the destination emails in the next field. ','titan-zero-trades-front-desk'); ?>
                <br />
                * <?php esc_html_e('If you select "To email ...in form field" then add a field like a drop-down, radio-button or checkbox that contains the email address in the field value (not needed in the field text but in the internal value).','titan-zero-trades-front-desk'); ?>
                </span>
            </td>
            </tr>
            <tr valign="top" id="tz_fd_destemails" <?php if ($tz_option == 'customer') echo ' style="display:none;"'; ?>>
            <th scope="row"><?php esc_html_e('Destination emails (comma separated)','titan-zero-trades-front-desk'); ?></th>
            <td><input type="text" name="fp_destination_emails" size="40" value="<?php echo esc_attr($this->get_option('fp_destination_emails', TZFD_BOOK_DEFAULT_fp_destination_emails)); ?>" /></td>
            </tr>
            <tr valign="top" id="tz_fd_dropemails" <?php if ($tz_option != 'customer') echo ' style="display:none;"'; ?>>
            <th scope="row"><?php esc_html_e('Field that contains the destination email(s)','titan-zero-trades-front-desk'); ?></th>
            <td>
                <select id="fp_destination_emails_field" name="fp_destination_emails_field" def="<?php echo esc_attr($this->get_option('fp_destination_emails_field', '')); ?>"></select>
            </td>
            </tr>
            </table>
            
            <hr />
            <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Email Notification to Website Administrator','titan-zero-trades-front-desk'); ?>:</span></h3>
            
            <table class="form-table">
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Email subject','titan-zero-trades-front-desk'); ?></th>
            <td><input type="text" name="fp_subject" size="70" value="<?php echo esc_attr($this->get_option('fp_subject', TZFD_BOOK_DEFAULT_fp_subject)); ?>" /></td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Include additional information?','titan-zero-trades-front-desk'); ?></th>
            <td>
              <?php $tz_option = $this->get_option('fp_inc_additional_info', TZFD_BOOK_DEFAULT_fp_inc_additional_info); ?>
              <select name="fp_inc_additional_info">
               <option value="true"<?php if ($tz_option == 'true') echo ' selected'; ?>><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
               <option value="false"<?php if ($tz_option == 'false') echo ' selected'; ?>><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
              </select>
            </td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Email format?','titan-zero-trades-front-desk'); ?></th>
            <td>
              <?php $tz_option = $this->get_option('fp_emailformat', TZFD_BOOK_DEFAULT_email_format); ?>
              <select name="fp_emailformat">
               <option value="text"<?php if ($tz_option != 'html') echo ' selected'; ?>><?php esc_html_e('Plain Text (default)','titan-zero-trades-front-desk'); ?></option>
               <option value="html"<?php if ($tz_option == 'html') echo ' selected'; ?>><?php esc_html_e('HTML (use html in the textarea below)','titan-zero-trades-front-desk'); ?></option>
              </select>
            </td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Message','titan-zero-trades-front-desk'); ?></th>
            <td><textarea type="text" name="fp_message" rows="6" cols="80"><?php echo esc_textarea($this->get_option('fp_message', TZFD_BOOK_DEFAULT_fp_message)); ?></textarea></td>
            </tr>
         </table>
      </div>
      <hr />
      <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Email Copy to the User / Customer','titan-zero-trades-front-desk'); ?>:</span></h3>
      <div class="inside">
         <table class="form-table">
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Send confirmation/thank you message to user?','titan-zero-trades-front-desk'); ?></th>
            <td>
              <?php $tz_option = $this->get_option('cu_enable_copy_to_user', TZFD_BOOK_DEFAULT_cu_enable_copy_to_user); ?>
              <select name="cu_enable_copy_to_user">
               <option value="true"<?php if ($tz_option == 'true') echo ' selected'; ?>><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
               <option value="false"<?php if ($tz_option == 'false') echo ' selected'; ?>><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
              </select>
            </td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Email field on the form','titan-zero-trades-front-desk'); ?></th>
            <td><select id="cu_user_email_field" name="cu_user_email_field" def="<?php echo esc_attr($this->get_option('cu_user_email_field', TZFD_BOOK_DEFAULT_cu_user_email_field)); ?>"></select></td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Email subject','titan-zero-trades-front-desk'); ?></th>
            <td><input type="text" name="cu_subject" size="70" value="<?php echo esc_attr($this->get_option('cu_subject', TZFD_BOOK_DEFAULT_cu_subject)); ?>" /></td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Email format?','titan-zero-trades-front-desk'); ?></th>
            <td>
              <?php $tz_option = $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format); ?>
              <select name="cu_emailformat">
               <option value="text"<?php if ($tz_option != 'html') echo ' selected'; ?>><?php esc_html_e('Plain Text (default)','titan-zero-trades-front-desk'); ?></option>
               <option value="html"<?php if ($tz_option == 'html') echo ' selected'; ?>><?php esc_html_e('HTML (use html in the textarea below)','titan-zero-trades-front-desk'); ?></option>
              </select>
            </td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Message','titan-zero-trades-front-desk'); ?></th>
            <td><textarea type="text" name="cu_message" rows="6" cols="80"><?php echo esc_textarea($this->get_option('cu_message', TZFD_BOOK_DEFAULT_cu_message)); ?></textarea></td>
            </tr>
         </table>
      </div>
		<hr>
		<div class="tzfd-buttons-container">
			<input type="button" value="<?php esc_html_e('Next Step - Antispam >','titan-zero-trades-front-desk'); ?>" class="button tzfd-action-right" data-tzfd-goto="4" />
			<?php if ($tz_current_user_access) { ?><input type="submit" name="savepublish" value="<?php esc_html_e('Save and Publish','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" /><?php } ?>
			<input type="submit" name="savereturn" value="<?php esc_html_e('Save and Return','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" />
			<div class="clear"></div>
		</div>
     </div>


     <div class="tzfd-adintsection" data-step="4">
      <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Captcha Verification','titan-zero-trades-front-desk'); ?></span></h3>
      <div class="inside">
         <table class="form-table">
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Use Captcha Verification?','titan-zero-trades-front-desk'); ?></th>
            <td colspan="5">
              <?php $tz_option = $this->get_option('cv_enable_captcha', TZFD_BOOK_DEFAULT_cv_enable_captcha); ?>
              <select name="cv_enable_captcha">
               <option value="true"<?php if ($tz_option == 'true') echo ' selected'; ?>><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
               <option value="false"<?php if ($tz_option == 'false') echo ' selected'; ?>><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
              </select>
            </td>
            </tr>

            <tr valign="top">
             <th scope="row"><?php esc_html_e('Width','titan-zero-trades-front-desk'); ?>:</th>
             <td><input type="text" name="cv_width" size="10" value="<?php echo esc_attr($this->get_option('cv_width', TZFD_BOOK_DEFAULT_cv_width)); ?>"  class="tzfd-captcha-field"  /></td>
             <th scope="row"><?php esc_html_e('Height','titan-zero-trades-front-desk'); ?>:</th>
             <td><input type="text" name="cv_height" size="10" value="<?php echo esc_attr($this->get_option('cv_height', TZFD_BOOK_DEFAULT_cv_height)); ?>" class="tzfd-captcha-field"  /></td>
             <th scope="row"><?php esc_html_e('Chars','titan-zero-trades-front-desk'); ?>:</th>
             <td><input type="text" name="cv_chars" size="10" value="<?php echo esc_attr($this->get_option('cv_chars', TZFD_BOOK_DEFAULT_cv_chars)); ?>" class="tzfd-captcha-field"  /></td>
            </tr>

            <tr valign="top">
             <th scope="row" valign="top"><?php esc_html_e('Min font size','titan-zero-trades-front-desk'); ?>:</th>
             <td valign="top"><input type="text" name="cv_min_font_size" size="10" value="<?php echo esc_attr($this->get_option('cv_min_font_size', TZFD_BOOK_DEFAULT_cv_min_font_size)); ?>" class="tzfd-captcha-field"  /></td>
             <th scope="row" valign="top"><?php esc_html_e('Max font size','titan-zero-trades-front-desk'); ?>:</th>
             <td valign="top"><input type="text" name="cv_max_font_size" size="10" value="<?php echo esc_attr($this->get_option('cv_max_font_size', TZFD_BOOK_DEFAULT_cv_max_font_size)); ?>" class="tzfd-captcha-field"  /></td>
             <td colspan="2" rowspan="2">
               <?php esc_html_e('Preview','titan-zero-trades-front-desk'); ?>:<br />
                 <br />
                <?php if (!function_exists('imagecreatetruecolor')) { ?>
                 <div style="color: #009900;font-weight:normal; border: 1px dotted black; padding:10px;max-width: 390px; background-color:#ffffdd">
                  <strong><?php esc_html_e( 'PHP GD Image Library', 'titan-zero-trades-front-desk' ); ?></strong> <?php esc_html_e( 'not enabled in the server.', 'titan-zero-trades-front-desk' ); ?><br />
                  <?php esc_html_e( 'This library is required to be able to display the captcha image.', 'titan-zero-trades-front-desk' ); ?><br />
                  <?php esc_html_e( 'Please contact your hosting support to enable this feature.', 'titan-zero-trades-front-desk' ); ?>
                 </div>
                <?php } else { ?>
                <img src="<?php echo esc_url( add_query_arg( array( sanitize_key( $this->prefix . '_captcha' ) => 'captcha', 'inAdmin' => '1' ), home_url( '/' ) ) ); ?>"  id="captchaimg" alt="security code" border="0"  />
                <?php }  ?>
             </td>
            </tr>


            <tr valign="top">
             <th scope="row"><?php esc_html_e('Noise','titan-zero-trades-front-desk'); ?>:</th>
             <td><input type="text" name="cv_noise" size="10" value="<?php echo esc_attr($this->get_option('cv_noise', TZFD_BOOK_DEFAULT_cv_noise)); ?>" class="tzfd-captcha-field" /></td>
             <th scope="row"><?php esc_html_e('Noise Length','titan-zero-trades-front-desk'); ?>:</th>
             <td><input type="text" name="cv_noise_length" size="10" value="<?php echo esc_attr($this->get_option('cv_noise_length', TZFD_BOOK_DEFAULT_cv_noise_length)); ?>" class="tzfd-captcha-field" /></td>
            </tr>


            <tr valign="top">
             <th scope="row"><?php esc_html_e('Background','titan-zero-trades-front-desk'); ?>:</th>
             <td><input type="color" name="cv_background" size="10" value="#<?php echo esc_attr($this->get_option('cv_background', TZFD_BOOK_DEFAULT_cv_background)); ?>" class="tzfd-captcha-field" /></td>
             <th scope="row"><?php esc_html_e('Border','titan-zero-trades-front-desk'); ?>:</th>
             <td><input type="color" name="cv_border" size="10" value="#<?php echo esc_attr($this->get_option('cv_border', TZFD_BOOK_DEFAULT_cv_border)); ?>" class="tzfd-captcha-field" /></td>
             <th scope="row"><?php esc_html_e('Font','titan-zero-trades-front-desk'); ?>:</th>
             <td>
                <select name="cv_font" >
                  <option value="font1"<?php if ("font1" == $this->get_option('cv_font', TZFD_BOOK_DEFAULT_cv_font)) echo " selected"; ?>><?php echo esc_html__( 'Font 1', 'titan-zero-trades-front-desk' ); ?></option>
                  <option value="font2"<?php if ("font2" == $this->get_option('cv_font', TZFD_BOOK_DEFAULT_cv_font)) echo " selected"; ?>><?php echo esc_html__( 'Font 2', 'titan-zero-trades-front-desk' ); ?></option>
                  <option value="font3"<?php if ("font3" == $this->get_option('cv_font', TZFD_BOOK_DEFAULT_cv_font)) echo " selected"; ?>><?php echo esc_html__( 'Font 3', 'titan-zero-trades-front-desk' ); ?></option>
                  <option value="font4"<?php if ("font4" == $this->get_option('cv_font', TZFD_BOOK_DEFAULT_cv_font)) echo " selected"; ?>><?php echo esc_html__( 'Font 4', 'titan-zero-trades-front-desk' ); ?></option>
                </select>
             </td>
            </tr>
         </table>
      </div>
		<hr>
		<div class="tzfd-buttons-container">
			<input type="button" value="<?php esc_html_e('Next Step - Reports >','titan-zero-trades-front-desk'); ?>" class="button tzfd-action-right" data-tzfd-goto="5" />
			<?php if ($tz_current_user_access) { ?><input type="submit" name="savepublish" value="<?php esc_html_e('Save and Publish','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" /><?php } ?>
			<input type="submit" name="savereturn" value="<?php esc_html_e('Save and Return','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" />
			<div class="clear"></div>
		</div>
     </div>

     <div class="tzfd-adintsection" data-step="5">
      <h3 class='hndle tzfd-hndle-compact'><span><?php esc_html_e('Automatic Reports: Send submissions in CSV format via email','titan-zero-trades-front-desk'); ?></span></h3>
      <div class="inside">
         <table class="form-table">
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Enable Reports?','titan-zero-trades-front-desk'); ?></th>
            <td>
              <?php $tz_option = $this->get_option('rep_enable', 'no'); ?>
              <select name="rep_enable">
               <option value="no"<?php if ($tz_option == 'no' || $tz_option == '') echo ' selected'; ?>><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
               <option value="yes"<?php if ($tz_option == 'yes') echo ' selected'; ?>><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
              </select>
            </td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Send report every','titan-zero-trades-front-desk'); ?></th>
            <td><input type="text" name="rep_days" size="4" value="<?php echo esc_attr($this->get_option('rep_days', '1')); ?>" /> <?php esc_html_e('days (Put a 0 to send the report immediately after each submission)','titan-zero-trades-front-desk'); ?></td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Send report after this hour (server time)','titan-zero-trades-front-desk'); ?></th>
            <td>
              <select name="rep_hour">
               <?php
                 $tz_hour = $this->get_option('rep_hour', '0');
                 for ($tz_k=0;$tz_k<24;$tz_k++)
                     echo '<option value="'.intval($tz_k).'"'.($tz_hour==$tz_k?' selected':'').'>'.($tz_k<10?'0':'').intval($tz_k).'</option>';
               ?>
              </select>
            </td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Send the report to the following email addresses (comma separated)','titan-zero-trades-front-desk'); ?></th>
            <td><input type="text" name="rep_emails" size="70" value="<?php echo esc_attr($this->get_option('rep_emails', '')); ?>" /></td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Email subject','titan-zero-trades-front-desk'); ?></th>
            <td><input type="text" name="rep_subject" size="70" value="<?php echo esc_attr($this->get_option('rep_subject', __( 'Submissions report...', 'titan-zero-trades-front-desk' ))); ?>" /></td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Email format?','titan-zero-trades-front-desk'); ?></th>
            <td>
              <?php $tz_option = $this->get_option('rep_emailformat', 'text'); ?>
              <select name="rep_emailformat">
               <option value="text"<?php if ($tz_option != 'html') echo ' selected'; ?>><?php esc_html_e('Plain Text (default)','titan-zero-trades-front-desk'); ?></option>
               <option value="html"<?php if ($tz_option == 'html') echo ' selected'; ?>><?php esc_html_e('HTML (use html in the textarea below)','titan-zero-trades-front-desk'); ?></option>
              </select>
            </td>
            </tr>
            <tr valign="top">
            <th scope="row"><?php esc_html_e('Email Text (CSV file will be attached with the submissions)','titan-zero-trades-front-desk'); ?></th>
            <td><textarea type="text" name="rep_message" rows="3" cols="80"><?php echo esc_textarea($this->get_option('rep_message', __( 'Attached you will find the data from the form submissions.', 'titan-zero-trades-front-desk' ))); ?></textarea></td>
            </tr>
         </table>
      </div>
        <hr>
		<div class="tzfd-buttons-container">
			<input type="button" value="<?php esc_html_e('Next Step - Add Ons >','titan-zero-trades-front-desk'); ?>" class="button tzfd-action-right" data-tzfd-goto="6" />
			<?php if ($tz_current_user_access) { ?><input type="submit" name="savepublish" value="<?php esc_html_e('Save and Publish','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" /><?php } ?>
			<input type="submit" name="savereturn" value="<?php esc_html_e('Save and Return','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" />
			<div class="clear"></div>
		</div>
     </div>


    <div class="tzfd-adintsection" data-step="6">
     <?php
    	global $tz_addons_objs_list, $tz_addons_active_list;
        $tz_printed = false;
    	if( count( $tz_addons_active_list ) )
    	{
    		echo wp_kses( 
                            __( '<h2>Add-Ons Settings:</h2><hr />', 'titan-zero-trades-front-desk' ), 
                            array(
                                'h2' => array(),
                                'hr' => array(),
                            ) 
                        );
            ob_start();
    		foreach( $tz_addons_active_list as $tz_addon_id ) if( isset( $tz_addons_objs_list[ $tz_addon_id ] ) ) echo wp_kses( (string) $tz_addons_objs_list[ $tz_addon_id ]->get_addon_form_settings( $this->item ), $this->admin_markup_allowed() );
            $tz_printed = ob_get_contents() != '';
            ob_end_flush();
    	}
        
        if (!$tz_printed)
        {
            ?>
            <p><?php esc_html_e('You can optionally','titan-zero-trades-front-desk'); ?> <?php esc_html_e('activate add ons in the add ons section','titan-zero-trades-front-desk'); ?>.</p>
            <p><?php esc_html_e('The add ons can be enabled to add new features','titan-zero-trades-front-desk'); ?>.</p>
            <p><?php echo wp_kses( 
                                    __( 'If you don\'t want to enable add ons now then <strong>continue saving these settings and publishing the booking form</strong>.', 'titan-zero-trades-front-desk' ), 
                                    array(
                                        'strong' => array(),
                                    ) 
                                ); ?></p>
            <?php
        }
     ?>
		<hr>
		<div class="tzfd-buttons-container">
			<?php if ($tz_current_user_access) { ?><input type="submit" name="savepublish" value="<?php esc_html_e('Save and Publish','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" /><?php } ?>
			<input type="submit" name="savereturn" value="<?php esc_html_e('Save and Return','titan-zero-trades-front-desk'); ?>" class="button button-primary tzfd-action-right" />
			<div class="clear"></div>
		</div>
    </div>

 </div>

 <div class="tzfd-buttons-container">
	<a href="<?php print esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
 </div>

</div>



[<a href="<?php echo esc_url('mailto:support@titanzero.io'); ?>"><?php esc_html_e('Free Support','titan-zero-trades-front-desk'); ?></a>] | [<a href="<?php echo esc_url('mailto:support@titanzero.io'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Help','titan-zero-trades-front-desk'); ?></a>]
</form>

