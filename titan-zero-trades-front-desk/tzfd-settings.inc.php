<?php


if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-request-scalar.php';

if ( !is_admin() )
{
    echo esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' );
    exit;
}

$tz_is_admin = current_user_can('tz_fd_access');
if ( ! $tz_is_admin || ! is_user_logged_in() ) {
    wp_die( esc_html__( 'You cannot access these settings.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}
// Administrative writes are bounded before any option is processed.
if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
    require_once __DIR__ . '/includes/class-tzfd-admin-write-guard.php';
    TZFD_Admin_Write_Guard::enforce( 500, 1048576 );
}
// An attempted administrative settings POST must always carry a valid nonce.
if ( 'POST' === tzfd_request_text( 'server', 'REQUEST_METHOD' )
    && ! wp_verify_nonce( tzfd_request_text( 'post', 'nonce' ), 'tz_fd_actions_admin' ) ) {
    wp_die( esc_html__( 'Invalid settings nonce.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}

$tz_tzfdid = 'TZFD_Titan Zero Front Desk';

$tz_gotab = '';
if ( !empty($_POST["nonce"]) && current_user_can('tz_fd_access') && wp_verify_nonce( tzfd_request_text( 'post', "nonce" ), 'tz_fd_actions_admin') )
{
    if (isset($_POST["gotab"]))
    {
        $tz_gotab = tzfd_request_text( 'post', "gotab" );
        if ($tz_gotab == '')
            $tz_message = 'Email report settings updated.';
        else if ($tz_gotab == 'fixarea')
            $tz_message = 'Troubleshoot settings updated.';
        else if ($tz_gotab == 'csvarea')
            $tz_message = 'CSV settings updated.';
        else if ($tz_gotab == 'schedulecalarea')
            $tz_message = 'Schedule Calendar View settings updated.';
        else if ($tz_gotab == 'miscsettings')
            $tz_message = 'Misc settings updated.';

    }
    else
        if (isset($_GET["gotab"]))
            $tz_gotab = tzfd_request_text( 'get', "gotab" );
}

$tz_nonce = wp_create_nonce( 'tz_fd_actions_admin' );

?>
<?php
// Settings sections. A form posted from another section keeps its place; a fresh visit opens General.
$tz_section_for_gotab = array(
	''                => 'notifications',
	'fixarea'         => 'advanced',
	'csvarea'         => 'advanced',
	'schedulecalarea' => 'advanced',
	'miscsettings'    => 'booking',
);
$tz_active = 'general';
if ( isset( $_POST['gotab'] ) || isset( $_GET['gotab'] ) ) {
	$tz_active = isset( $tz_section_for_gotab[ $tz_gotab ] ) ? $tz_section_for_gotab[ $tz_gotab ] : 'general';
}
// A GET query parameter must not impersonate a successful settings save.
$tz_can_manage = current_user_can( 'tz_fd_access' );
$tz_nav = array(
	'general'      => __( 'General', 'titan-zero-trades-front-desk' ),
	'booking'      => __( 'Booking rules', 'titan-zero-trades-front-desk' ),
	'hours'        => __( 'Business hours', 'titan-zero-trades-front-desk' ),
	'notifications'=> __( 'Notifications', 'titan-zero-trades-front-desk' ),
	'integrations' => __( 'Integrations', 'titan-zero-trades-front-desk' ),
	'advanced'     => __( 'Advanced', 'titan-zero-trades-front-desk' ),
);
// The stylesheet is enqueued from admin_enqueue_scripts; the section-tab script prints in the admin footer.
wp_enqueue_script( 'tzfd-settings-tabs', plugin_dir_url( __FILE__ ) . 'js/inline/settings-tabs.js', array( 'jquery' ), '2.0.2', true );
wp_localize_script( 'tzfd-settings-tabs', 'tzfdSettingsTabs', array( 'active' => $tz_active ) );
?>
<div class="tzfd-set-head">
	<div>
		<h1><?php echo wp_kses( TZFD_UI_Graphics::brand_html( 'settings' ), TZFD_UI_Graphics::allowed_html() ); ?><?php esc_html_e('Settings','titan-zero-trades-front-desk'); ?></h1>
		<p class="tzfd-set-sub"><?php esc_html_e('Set your business defaults and notification preferences.','titan-zero-trades-front-desk'); ?></p>
	</div>
	<button type="button" class="button button-primary" id="tzfd-settings-save"><?php esc_html_e('Save changes','titan-zero-trades-front-desk'); ?></button>
</div>
<?php
    if (isset($tz_message) && $tz_message != '') echo "<div id='setting-error-settings_updated' class='updated'><h2>".esc_html($tz_message)."</h2></div>";
?>
<div class="tzfd-set-layout">
	<nav class="tzfd-set-nav" aria-label="<?php esc_attr_e('Settings sections','titan-zero-trades-front-desk'); ?>">
<?php foreach ( $tz_nav as $tz_key => $tz_label ) : ?>
		<a href="<?php echo esc_url( '#' . sanitize_html_class( $tz_key ) ); ?>" data-tab="<?php echo esc_attr( $tz_key ); ?>"<?php echo $tz_active === $tz_key ? ' class="is-active"' : ''; ?>><?php echo wp_kses( TZFD_UI_Graphics::brand_html( $tz_key, 24 ), TZFD_UI_Graphics::allowed_html() ); ?><?php echo esc_html( $tz_label ); ?></a>
<?php endforeach; ?>
	</nav>
	<div class="tzfd-set-body">
<!-- General -->
<div class="tzfd-tab<?php echo $tz_active === 'general' ? ' tab-active' : ''; ?>" data-tab="general">
	<h2><?php esc_html_e('General','titan-zero-trades-front-desk'); ?></h2>
	<p class="tzfd-set-intro"><?php esc_html_e('Front Desk uses its own business settings and currency when operating independently.','titan-zero-trades-front-desk'); ?></p>
	<div class="tzfd-set-card">
		<h2><?php esc_html_e('Time zone','titan-zero-trades-front-desk'); ?></h2>
		<p><?php esc_html_e('Times use the WordPress site time zone:','titan-zero-trades-front-desk'); ?> <strong><?php echo esc_html( wp_timezone_string() ); ?></strong>. <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php esc_html_e('Change it in WordPress settings','titan-zero-trades-front-desk'); ?></a>.</p>
	</div>
<?php TZFD_Client_Portal::render_settings_card(); ?>
</div>

<!-- booking -->
<div class="tzfd-tab<?php echo $tz_active === 'booking' ? ' tab-active' : ''; ?>" data-tab="booking">
<div class="tzfd-subpane">
	<h2><?php esc_html_e('Booking rules and spam protection','titan-zero-trades-front-desk'); ?></h2>
	<p class="tzfd-set-intro"><?php esc_html_e('How requests are confirmed, how spam is blocked, and what is stored about each customer.','titan-zero-trades-front-desk'); ?></p>
    <form  method="post" action="" name="tzfdformconf7">
        <input name="nonce" type="hidden" value="<?php echo esc_attr($tz_nonce); ?>" />
        <input name="TZFD_post_edition" type="hidden" value="1" />
        <input name="gotab" type="hidden" value="miscsettings" />
	    <table class="form-table">
            <tbody>
                <tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Block spam bots','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<select id="tz_fd_honeypot" name="tz_fd_honeypot">
	    					<option value=""  <?php if (get_option('tz_fd_honeypot','') == "") echo 'selected'; ?> ><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
                            <option value="Yes"  <?php if (get_option('tz_fd_honeypot','') != '') echo 'selected'; ?>  ><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
	    				</select>
                        <br />
                       <?php esc_html_e('Adds a hidden field that stops most spam bots from submitting the form.','titan-zero-trades-front-desk'); ?>
	    			</td>
	    		</tr>
                <tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Store user\'s IP address?','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<select id="tz_fd_storeip" name="tz_fd_storeip">
	    					<option value=""  <?php if (get_option('tz_fd_storeip', TZFD_BOOK_DEFAULT_track_IP) == "") echo 'selected'; ?> ><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
                            <option value="Yes"  <?php if (get_option('tz_fd_storeip',TZFD_BOOK_DEFAULT_track_IP) != '') echo 'selected'; ?>  ><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
	    				</select>
                        <br />
	    			</td>
	    		</tr>
                <tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Admin language','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<select id="tz_fd_admin_language" name="tz_fd_admin_language">
	    					<option value=""  <?php if (get_option('tz_fd_admin_language', '') == '') echo 'selected'; ?> ><?php esc_html_e('Automatically detected from WordPress settings','titan-zero-trades-front-desk'); ?></option>
                            <option value="english"  <?php if (get_option('tz_fd_admin_language','') == 'english') echo 'selected'; ?>  >English</option>
	    				</select>     
                        <br />                         
	    			</td>
	    		</tr> 				
	    	</tbody>
	    </table>
        <?php if ( current_user_can('tz_fd_access') ) :
            $tz_intake_forms = array();
            foreach ( TZFD_Booking_View::calendar_names() as $tz_intake_id => $tz_intake_name ) {
                $tz_intake_forms[] = (object) array( 'id' => $tz_intake_id, 'form_name' => $tz_intake_name );
            }
            $tz_intake_default = TZFD_Intake_Mode::default_mode();
        ?>
        <h3 id="tzfd-intake"><?php esc_html_e('Booking intake','titan-zero-trades-front-desk'); ?></h3>
        <p class="description"><?php esc_html_e('Instant calendars confirm a booking straight away. Request calendars save each booking as Pending until you approve it; the time stays held while it waits. A calendar with no setting of its own follows the default.','titan-zero-trades-front-desk'); ?></p>
        <table class="form-table">
            <tbody>
                <tr valign="top">
                    <th scope="row"><label for="intake_default"><?php esc_html_e('Default for calendars','titan-zero-trades-front-desk'); ?></label></th>
                    <td>
                        <select id="intake_default" name="intake_default">
                            <option value="instant" <?php selected( $tz_intake_default, TZFD_Intake_Mode::MODE_INSTANT ); ?>><?php esc_html_e('Instant confirmation','titan-zero-trades-front-desk'); ?></option>
                            <option value="request" <?php selected( $tz_intake_default, TZFD_Intake_Mode::MODE_REQUEST ); ?>><?php esc_html_e('Request (approve first)','titan-zero-trades-front-desk'); ?></option>
                        </select>
                    </td>
                </tr>
                <?php foreach ( (array) $tz_intake_forms as $tz_intake_form ) :
                    $tz_intake_fid   = (int) $tz_intake_form->id;
                    $tz_intake_label = '' !== (string) $tz_intake_form->form_name ? $tz_intake_form->form_name : sprintf( /* translators: %d: calendar ID. */ __( 'Calendar %d', 'titan-zero-trades-front-desk' ), $tz_intake_fid );
                    $tz_intake_mode  = TZFD_Intake_Mode::get_for_form( $tz_intake_fid );
                ?>
                <tr valign="top">
                    <th scope="row"><label for="intake_form_<?php echo esc_attr( (string) $tz_intake_fid ); ?>"><?php echo esc_html( $tz_intake_label ); ?></label></th>
                    <td>
                        <select id="intake_form_<?php echo esc_attr( (string) $tz_intake_fid ); ?>" name="intake_form[<?php echo esc_attr( (string) $tz_intake_fid ); ?>]">
                            <option value="instant" <?php selected( $tz_intake_mode, TZFD_Intake_Mode::MODE_INSTANT ); ?>><?php esc_html_e('Instant confirmation','titan-zero-trades-front-desk'); ?></option>
                            <option value="request" <?php selected( $tz_intake_mode, TZFD_Intake_Mode::MODE_REQUEST ); ?>><?php esc_html_e('Request (approve first)','titan-zero-trades-front-desk'); ?></option>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

	    <input type="submit" value="<?php esc_attr_e('Save changes','titan-zero-trades-front-desk'); ?>" class="button button-primary" />
    </form>
</div>
</div>

<!-- Business hours -->
<div class="tzfd-tab<?php echo $tz_active === 'hours' ? ' tab-active' : ''; ?>" data-tab="hours">
	<h2><?php esc_html_e('Business hours','titan-zero-trades-front-desk'); ?></h2>
	<p class="tzfd-set-intro"><?php esc_html_e('Opening hours are set for each calendar and each worker, so a booking follows the hours of the calendar or worker it is for. There are no site-wide hours yet.','titan-zero-trades-front-desk'); ?></p>
	<div class="tzfd-set-card tzfd-set-links">
		<p><?php esc_html_e('Open a calendar to set its hours, or open Workers and locations to set the hours of people, equipment and rooms.','titan-zero-trades-front-desk'); ?></p>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::CALENDAR ) ); ?>"><?php esc_html_e('Calendars','titan-zero-trades-front-desk'); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::WORKERS ) ); ?>"><?php esc_html_e('Workers and locations','titan-zero-trades-front-desk'); ?></a>
	</div>
</div>

<!-- notifications -->
<div class="tzfd-tab<?php echo $tz_active === 'notifications' ? ' tab-active' : ''; ?>" data-tab="notifications">
<div class="tzfd-subpane">
	<h2><?php esc_html_e('Automatic email reports','titan-zero-trades-front-desk'); ?></h2>
	<p>
    <?php 
    echo wp_kses( 
        __( 'Automatic email reports for <b>ALL forms</b>: Send submissions in CSV format via email.', 'titan-zero-trades-front-desk' ),
        array(
            'b' => array(),
        )
    ); 
    ?>
    </p>
	<form name="updatereportsettings" action="" method="post">
     <input name="TZFD_post_edition" type="hidden" value="1" />
     <input name="gotab" type="hidden" value="" />
     <input name="nonce" type="hidden" value="<?php echo esc_attr($tz_nonce); ?>" />
     <table class="form-table">
        <tr valign="top">
        <td scope="row" colspan="2"><strong><?php esc_html_e('Send reports automatically?','titan-zero-trades-front-desk'); ?></strong>
          <?php $tz_option = get_option('tz_fd_rep_enable', 'no'); ?>
          <select name="tz_fd_rep_enable">
           <option value="no"<?php if ($tz_option == 'no' || $tz_option == '') echo ' selected'; ?>><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
           <option value="yes"<?php if ($tz_option == 'yes') echo ' selected'; ?>><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
          </select>
          &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;
          <strong><?php esc_html_e('Send a report every','titan-zero-trades-front-desk'); ?>:</strong> <input type="text" name="tz_fd_rep_days" size="1" value="<?php echo esc_attr(get_option('tz_fd_rep_days', '1')); ?>" /> <?php esc_html_e('days','titan-zero-trades-front-desk'); ?>
          &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;
          <strong><?php esc_html_e('Hour to send (server time)','titan-zero-trades-front-desk'); ?>:</strong>
          <select name="tz_fd_rep_hour">
           <?php
             $tz_hour = get_option('tz_fd_rep_hour', '0');
             for ($tz_k=0;$tz_k<24;$tz_k++)
                 echo '<option value="'.intval($tz_k).'"'.($tz_hour==$tz_k?' selected':'').'>'.($tz_k<10?'0':'').intval($tz_k).'</option>';
           ?>
          </select>
        </td>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Sender address','titan-zero-trades-front-desk'); ?></th>
        <td><input type="text" name="tz_fd_fp_from_email" size="70" value="<?php echo esc_attr(get_option('tz_fd_fp_from_email', get_the_author_meta('user_email', get_current_user_id()) )); ?>" /></td>
        </tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Recipient addresses','titan-zero-trades-front-desk'); ?></th>
        <td><input type="text" name="tz_fd_rep_emails" size="70" value="<?php echo esc_attr(get_option('tz_fd_rep_emails', '')); ?>" /></td>
        </tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Subject line','titan-zero-trades-front-desk'); ?></th>
        <td><input type="text" name="tz_fd_rep_subject" size="70" value="<?php echo esc_attr(get_option('tz_fd_rep_subject', __( 'Submissions report...', 'titan-zero-trades-front-desk' ))); ?>" /></td>
        </tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Message format','titan-zero-trades-front-desk'); ?></th>
        <td>
          <?php $tz_option = get_option('tz_fd_rep_emailformat', 'text'); ?>
          <select name="tz_fd_rep_emailformat">
           <option value="text"<?php if ($tz_option != 'html') echo ' selected'; ?>><?php esc_html_e('Plain text (default)','titan-zero-trades-front-desk'); ?></option>
           <option value="html"<?php if ($tz_option == 'html') echo ' selected'; ?>><?php esc_html_e('HTML (write HTML in the message box below)','titan-zero-trades-front-desk'); ?></option>
          </select>
        </td>
        </tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Message (the CSV file is attached automatically','titan-zero-trades-front-desk'); ?>)</th>
        <td><textarea type="text" name="tz_fd_rep_message" rows="3" cols="80"><?php echo esc_textarea(get_option('tz_fd_rep_message', __( 'Attached you will find the data from the form submissions.', 'titan-zero-trades-front-desk' ))); ?></textarea></td>
        </tr>
     </table>
     <input type="submit" value="<?php esc_attr_e('Save report settings','titan-zero-trades-front-desk'); ?>" class="button button-primary" />
     </form>
     <div class="clear"></div>
     <p><?php esc_html_e('Note: For setting up a report only for a specific form use the setting area available for that when editing each form settings','titan-zero-trades-front-desk'); ?>.</p>

</div>
<div class="tzfd-subpane">
	<h2><?php esc_html_e('Status update emails','titan-zero-trades-front-desk'); ?></h2>
	<p class="tzfd-set-intro"><?php esc_html_e('Sent to the customer when an administrator changes a booking\'s status. Each status has its own subject and message.','titan-zero-trades-front-desk'); ?></p>
	<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::EMAILS ) ); ?>"><?php esc_html_e('Edit status update emails','titan-zero-trades-front-desk'); ?></a></p>
</div>

</div>

<!-- integrations -->
<div class="tzfd-tab<?php echo $tz_active === 'integrations' ? ' tab-active' : ''; ?>" data-tab="integrations">
<div class="tzfd-subpane">

</div>
<div class="tzfd-subpane">
	<h2><?php esc_html_e('Add-on settings','titan-zero-trades-front-desk'); ?></h2>
	<p class="tzfd-set-intro"><?php esc_html_e('Each add-on keeps its own settings. They open from the add-on on the Add-ons page.','titan-zero-trades-front-desk'); ?></p>
	<?php
	global $tz_addons_objs_list;
	$tz_with_settings = array();
	foreach ( (array) $tz_addons_objs_list as $tz_obj ) {
		if ( is_object( $tz_obj ) && method_exists( $tz_obj, 'get_addon_settings' ) && method_exists( $tz_obj, 'addon_is_active' ) && $tz_obj->addon_is_active() ) {
			$tz_with_settings[] = $tz_obj;
		}
	}
	if ( empty( $tz_with_settings ) ) :
		echo '<p>' . esc_html__( 'No add-on with settings is switched on.', 'titan-zero-trades-front-desk' ) . '</p>';
	else : ?>
	<table class="widefat striped">
		<tbody>
		<?php foreach ( $tz_with_settings as $tz_obj ) : ?>
			<tr><td><?php echo esc_html( $tz_obj->get_addon_name() ); ?></td><td><?php esc_html_e( 'On', 'titan-zero-trades-front-desk' ); ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
	<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ADDONS ) ); ?>"><?php esc_html_e('Open Add-ons','titan-zero-trades-front-desk'); ?></a></p>
</div>
<div class="tzfd-subpane">
	<?php TZFD_Upgrades::render_cards(); ?>
</div>

</div>

<!-- Advanced -->
<div class="tzfd-tab<?php echo $tz_active === 'advanced' ? ' tab-active' : ''; ?>" data-tab="advanced">
<div class="tzfd-subpane">
	<h2><?php esc_html_e('Troubleshooting','titan-zero-trades-front-desk'); ?></h2>
	<p><b><?php esc_html_e('Important!','titan-zero-trades-front-desk'); ?>:</b> <?php esc_html_e('Use this area only if you are experiencing conflicts with third party plugins, with the theme scripts or with the character encoding.','titan-zero-trades-front-desk'); ?></p>
    <form  method="post" action="" name="tzfdformconf2">
        <input name="TZFD_post_edition" type="hidden" value="1" />
        <input name="nonce" type="hidden" value="<?php echo esc_attr($tz_nonce); ?>" />
        <input name="gotab" type="hidden" value="fixarea" />
	    <table class="form-table">
            <tbody>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('How scripts load','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<select id="ccscriptload" name="ccscriptload">
        <option value="0" <?php if (get_option('TZFD_LOAD_SCRIPTS',"1") == "1") echo 'selected'; ?>><?php esc_html_e('Classic (recommended)','titan-zero-trades-front-desk'); ?></option>
        <option value="1" <?php if (get_option('TZFD_LOAD_SCRIPTS',"1") != "1") echo 'selected'; ?>><?php esc_html_e('Direct','titan-zero-trades-front-desk'); ?></option>
       </select><br>
	    				<em><?php esc_html_e('Change the script load method if the form doesn\'t appear in the public website.','titan-zero-trades-front-desk'); ?></em>
	    			</td>
	    		</tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Text encoding','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<select id="cccharsets" name="cccharsets">
	    					<option value=""><?php esc_html_e('Keep current charset (Recommended)','titan-zero-trades-front-desk'); ?></option>
                            <option value="utf8_general_ci">UTF-8 (<?php esc_html_e('try this first','titan-zero-trades-front-desk'); ?>)</option>
                            <option value="latin1_swedish_ci">latin1_swedish_ci</option>
	    				</select><br>
	    				<em><?php esc_html_e('Update the charset if you are getting problems displaying special/non-latin characters. After updated you need to edit the special characters again.','titan-zero-trades-front-desk'); ?></em>
	    			</td>
	    		</tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Fix special characters in CSV files?','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<select id="csvcharautofix" name="csvcharautofix">
	    					<option <?php if (get_option('TZFD_CSV_CHARFIX',"") == "") echo 'selected'; ?> value=""><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
                            <option <?php if (get_option('TZFD_CSV_CHARFIX',"") == "1") echo 'selected'; ?> value="1"><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
	    				</select><br>
	    				<em><?php esc_html_e('Set to "No" if special characters appear with question marks "?" in the CSV file.','titan-zero-trades-front-desk'); ?></em>
	    			</td>
	    		</tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Column separator in CSV files','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<select id="csvseparator" name="csvseparator">
	    					<option <?php if (get_option('TZFD_CSV_SEPARATOR',",") == ",") echo 'selected'; ?> value=","><?php esc_html_e('comma: ","','titan-zero-trades-front-desk'); ?></option>
                            <option <?php if (get_option('TZFD_CSV_SEPARATOR',",") == ";") echo 'selected'; ?> value=";"><?php esc_html_e('semicolon: ";"','titan-zero-trades-front-desk'); ?></option>
	    				</select><br>
	    				<em><?php esc_html_e('Change it if you get the CSV columns mixed','titan-zero-trades-front-desk'); ?></em>
	    			</td>
	    		</tr>
	    	</tbody>
	    </table>
	    <input type="submit" value="<?php esc_attr_e('Save changes','titan-zero-trades-front-desk'); ?>" class="button button-primary" />
    </form>
</div>

<div class="tzfd-subpane">
	<h2><?php esc_html_e('CSV export','titan-zero-trades-front-desk'); ?></h2>
    <form  method="post" action="" name="tzfdformconf5">
        <input name="TZFD_post_edition" type="hidden" value="1" />
        <input name="nonce" type="hidden" value="<?php echo esc_attr($tz_nonce); ?>" />
        <input name="gotab" type="hidden" value="csvarea" />
	    <table class="form-table">
            <tbody>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Columns to leave out of the bookings CSV','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<input name="bocsvexclude" type="text" value="<?php echo esc_attr(get_option('tz_fd_bocsvexclude',"")); ?>" /><br />
                        <em><?php echo wp_kses( 
                                __( 'Enter field names comma separated, example: <b>final_price,referrer,fieldname1,fieldname2</b>', 'titan-zero-trades-front-desk' ), 
                                array(
                                    'b' => array(),
                                ) 
                            ); ?></em>
	    			</td>
	    		</tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php  esc_html_e('Columns to leave out of the schedule CSV','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<input name="schcsvexclude" type="text" value="<?php echo esc_attr(get_option('tz_fd_schcsvexclude',"")); ?>" /><br />
                        <em><?php echo wp_kses( 
                                                __( 'Enter field names comma separated, example: <b>paid,cancelled</b>', 'titan-zero-trades-front-desk' ), 
                                                array(
                                                    'b' => array(),
                                                ) 
                                            ); ?></em>
	    			</td>
	    		</tr>
	    	</tbody>
	    </table>
	    <input type="submit" value="<?php esc_attr_e('Save changes','titan-zero-trades-front-desk'); ?>" class="button button-primary" />
    </form>
</div>

<div class="tzfd-subpane">
	<h2><?php esc_html_e('Calendar display','titan-zero-trades-front-desk'); ?></h2>
    <p><?php esc_html_e( 'In this area you can customize the data displayed in the "', 'titan-zero-trades-front-desk' ); ?><strong><?php esc_html_e( 'Schedule Calendar View', 'titan-zero-trades-front-desk' ); ?></strong><?php esc_html_e( '" of the dashboard accessible through the "', 'titan-zero-trades-front-desk' ); ?><strong><?php esc_html_e( 'Schedule', 'titan-zero-trades-front-desk' ); ?></strong><?php esc_html_e( '" button in the', 'titan-zero-trades-front-desk' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page='.$this->menu_parameter ) ); ?>"><?php esc_html_e( 'calendars list', 'titan-zero-trades-front-desk' ); ?></a>.</p>
	<p><?php esc_html_e( 'For', 'titan-zero-trades-front-desk' ); ?> <strong><?php esc_html_e( 'help and more configuration options', 'titan-zero-trades-front-desk' ); ?></strong> <?php esc_html_e( 'check "Customizing the schedule calendar contents and colors".', 'titan-zero-trades-front-desk' ); ?>
	
    <form  method="post" action="" name="tzfdformconf6">
        <input name="nonce" type="hidden" value="<?php echo esc_attr($tz_nonce); ?>" />
        <input name="TZFD_post_edition" type="hidden" value="1" />
        <input name="gotab" type="hidden" value="schedulecalarea" />
	    <table class="form-table">
            <tbody>
                <tr valign="top">
	    	 		<th colspan="2" style="border-top: 1px dotted black">
	    				<label><?php esc_html_e('What the calendar shows in the admin area','titan-zero-trades-front-desk'); ?>:</label>
	    			</th>
                </tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Event title','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<input name="schcaltitle_admin" type="text" value="<?php echo esc_attr(get_option('tz_fd_schcaltitle_admin',TZFD_SCHCALTITLE_ADMIN)); ?>" /><br />
                        <em><?php esc_html_e( 'Check the list of tags available for the contents.', 'titan-zero-trades-front-desk' ); ?></em>
                        <em><br /><?php esc_html_e( 'The following additional tags are available for the specific time listed:', 'titan-zero-trades-front-desk' ); ?> <b><?php esc_html_e( '%app_service% - %app_date% - %app_slot% - %app_quantity% - %app_status%', 'titan-zero-trades-front-desk' ); ?></b> </em>
	    			</td>
	    		</tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Event details','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<textarea cols="80" rows="3"  name="schcalcontent_admin"><?php echo esc_textarea(get_option('tz_fd_schcalcontent_admin',TZFD_SCHCALCONTENT_ADMIN)); ?></textarea><br />
                        <em><?php esc_html_e( 'Check the list of tags available for the contents.', 'titan-zero-trades-front-desk' ); ?></em>
                        <em><br /><?php esc_html_e( 'The following additional tags are available for the specific time listed:', 'titan-zero-trades-front-desk' ); ?> <b><?php esc_html_e( '%app_service% - %app_date% - %app_slot% - %app_quantity% - %app_status%', 'titan-zero-trades-front-desk' ); ?></b> </em>
	    			</td>
	    		</tr>
                <tr valign="top">
	    	 		<th colspan="2" style="border-top: 1px dotted black">
	    				<label><?php esc_html_e('What the calendar shows on your website','titan-zero-trades-front-desk'); ?>:</label>
	    			</th>
                </tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Event title','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<input name="schcaltitle_public" type="text" value="<?php echo esc_attr(get_option('tz_fd_schcaltitle_public',TZFD_SCHCALTITLE_PUBLIC)); ?>" /><br />
                        <em><?php esc_html_e( 'Check the list of tags available for the contents.', 'titan-zero-trades-front-desk' ); ?></em>
                        <em><br /><?php esc_html_e( 'The following additional tags are available for the specific time listed:', 'titan-zero-trades-front-desk' ); ?> <b><?php esc_html_e( '%app_service% - %app_date% - %app_slot% - %app_quantity% - %app_status%', 'titan-zero-trades-front-desk' ); ?></b> </em>
	    			</td>
	    		</tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Event details','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<textarea cols="80" rows="3" name="schcalcontent_public"><?php echo esc_textarea(get_option('tz_fd_schcalcontent_public',TZFD_SCHCALCONTENT_PUBLIC)); ?></textarea><br />
                        <em><?php esc_html_e( 'Check the list of tags available for the contents.', 'titan-zero-trades-front-desk' ); ?></em>
                        <em><br /><?php esc_html_e( 'The following additional tags are available for the specific time listed:', 'titan-zero-trades-front-desk' ); ?> <b><?php esc_html_e( '%app_service% - %app_date% - %app_slot% - %app_quantity% - %app_status%', 'titan-zero-trades-front-desk' ); ?></b> </em>
	    			</td>
	    		</tr>
                <tr valign="top">
	    	 		<th colspan="2" style="border-top: 1px dotted black">
	    				<label><?php esc_html_e('Advanced','titan-zero-trades-front-desk'); ?>:</label>
	    			</th>
                </tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Show blocked times in the admin calendar?','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<select id="tz_fd_sch_admin_blockedt" name="tz_fd_sch_admin_blockedt">
	    					<option value=""  <?php if (get_option('tz_fd_sch_admin_blockedt','') != "Yes") echo 'selected'; ?> ><?php esc_html_e( 'No', 'titan-zero-trades-front-desk' ); ?></option>
                            <option value="Yes"  <?php if (get_option('tz_fd_sch_admin_blockedt','') == 'Yes') echo 'selected'; ?>  ><?php esc_html_e( 'Yes', 'titan-zero-trades-front-desk' ); ?></option>
	    				</select>
	    			</td>
	    		</tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Hide this calendar from the schedule','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<input size="80" name="tz_fd_schcalcontent_exclude" value="<?php echo esc_attr(get_option('tz_fd_schcalcontent_exclude','')); ?>" /><br />
                        <em><?php esc_html_e( 'Indicate the calendar ID to be excluded, ex:', 'titan-zero-trades-front-desk' ); ?> <strong><?php esc_html_e( 'fieldname1', 'titan-zero-trades-front-desk' ); ?></strong><?php esc_html_e( '. Comma separated for multiple IDs.', 'titan-zero-trades-front-desk' ); ?></em>
	    			</td>
	    		</tr>
	    	</tbody>
	    </table>
	    <input type="submit" value="<?php esc_attr_e('Save changes','titan-zero-trades-front-desk'); ?>" class="button button-primary" />
    </form>
</div>



</div>

</div>
</div>
