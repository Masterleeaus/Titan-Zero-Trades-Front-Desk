<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( !is_admin() )
{
    echo 'Direct access not allowed.';
    exit;
}

$tzfd_is_admin = current_user_can('manage_options');

$tzfd_tzfdid = 'TZFD_Titan Zero Trades: Front Desk';

$tzfd_gotab = '';
if ( !empty($_POST["nonce"]) && current_user_can('edit_pages') && wp_verify_nonce( sanitize_text_field(wp_unslash($_POST["nonce"])), 'tzfd_actions_admin') )
{
    if (isset($_POST["gotab"]))
    {
        $tzfd_gotab = sanitize_text_field(wp_unslash($_POST["gotab"]));
        if ($tzfd_gotab == '')
            $tzfd_message = 'Email report settings updated.';
        else if ($tzfd_gotab == 'fixarea')
            $tzfd_message = 'Troubleshoot settings updated.';
        else if ($tzfd_gotab == 'csvarea')
            $tzfd_message = 'CSV settings updated.';
        else if ($tzfd_gotab == 'schedulecalarea')
            $tzfd_message = 'Schedule Calendar View settings updated.';
        else if ($tzfd_gotab == 'miscsettings')
            $tzfd_message = 'Misc settings updated.';
        else if ($tzfd_gotab == 'ai' && current_user_can('manage_options'))
        {
            $tzfd_choice  = sanitize_key( wp_unslash( $_POST['ai_choice'] ?? '' ) );
            // Model and address typed for the previous choice must not carry over to a new one.
            $tzfd_same    = ($tzfd_choice === TZFD_AI_Options::current_choice());
            $tzfd_ai      = TZFD_AI_Options::resolve( $tzfd_choice, array(
                'model' => $tzfd_same ? sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) ) : '',
                'base'  => esc_url_raw( wp_unslash( $_POST['api_base'] ?? '' ) ),
            ) );
            if ( $tzfd_ai['id'] === 'custom' && $tzfd_ai['base'] === '' ) {
                $tzfd_message = 'Enter the server address for Other (OpenAI-compatible server). Nothing was changed.';
            } else {
                if ( ! empty($_POST['api_key_clear']) ) delete_option('tzfd_ai_api_key');
                $tzfd_typed_key = isset($_POST['api_key']) ? sanitize_text_field( wp_unslash($_POST['api_key']) ) : '';
                if ( $tzfd_typed_key !== '' ) update_option('tzfd_ai_api_key', TZFD_Addon_Support::secret_encrypt( $tzfd_typed_key ));
                // The placeholder kept for a local server must not be sent to a hosted provider.
                if ( ! $tzfd_ai['key_optional'] && TZFD_AI_Options::key_is_placeholder() ) delete_option('tzfd_ai_api_key');
                // A local server needs no key. Store a placeholder so the AI features stay on.
                if ( $tzfd_ai['key_optional'] && (string) get_option('tzfd_ai_api_key', '') === '' ) update_option('tzfd_ai_api_key', TZFD_Addon_Support::secret_encrypt('local'));
                update_option('tzfd_ai_provider', $tzfd_ai['provider']);
                update_option('tzfd_ai_api_base', $tzfd_ai['base']);
                update_option('tzfd_ai_model', $tzfd_ai['model']);
                $tzfd_message = 'AI settings saved.';
            }
        }
    }
    else
        if (isset($_GET["gotab"]))
            $tzfd_gotab = sanitize_text_field(wp_unslash( $_GET["gotab"] ?? '' ));
}

$tzfd_nonce = wp_create_nonce( 'tzfd_actions_admin' );

?>
<?php
// Settings sections. A form posted from another section keeps its place; a fresh visit opens General.
$tzfd_section_for_gotab = array(
	''                => 'notifications',
	'fixarea'         => 'advanced',
	'csvarea'         => 'advanced',
	'schedulecalarea' => 'advanced',
	'miscsettings'    => 'booking',
	'ai'              => 'integrations',
);
$tzfd_active = 'general';
if ( isset( $_POST['gotab'] ) || isset( $_GET['gotab'] ) ) {
	$tzfd_active = isset( $tzfd_section_for_gotab[ $tzfd_gotab ] ) ? $tzfd_section_for_gotab[ $tzfd_gotab ] : 'general';
}
if ( '' === ( $tzfd_message ?? '' ) && ( isset( $_GET['saved'] ) || isset( $_GET['methods_saved'] ) || isset( $_GET['quote_saved'] ) ) ) {
	$tzfd_message = __( 'Settings saved.', 'titan-zero-trades-front-desk' );
}
$tzfd_can_manage = current_user_can( 'manage_options' );
$tzfd_nav = array(
	'general'      => __( 'General', 'titan-zero-trades-front-desk' ),
	'booking'      => __( 'Booking rules', 'titan-zero-trades-front-desk' ),
	'hours'        => __( 'Business hours', 'titan-zero-trades-front-desk' ),
	'notifications'=> __( 'Notifications', 'titan-zero-trades-front-desk' ),
	'payments'     => __( 'Payments', 'titan-zero-trades-front-desk' ),
	'integrations' => __( 'Integrations', 'titan-zero-trades-front-desk' ),
	'advanced'     => __( 'Advanced', 'titan-zero-trades-front-desk' ),
);
// Screen styles and the section-tab script are attached to registered handles, not printed as inline tags.
// The styles print here because the admin head has already been sent. The script prints in the admin footer.
wp_register_style( 'tzfd-settings-screen', false, array(), '2.0.1' );
wp_add_inline_style( 'tzfd-settings-screen', <<<'CSS'
	.tzfd-tab{display:none;}
	.tzfd-tab.tab-active{display:block;}
	.tzfd-tab label{font-weight:600;}
	.tzfd-set-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:16px 0 20px;}
	.tzfd-set-head h1{margin:0 0 4px;color:#f2f4f8;}
	.tzfd-set-sub{margin:0;color:#a4acba;}
	.tzfd-set-head .button-primary{background:#E31B23;border-color:#E31B23;padding:4px 18px;height:auto;line-height:2;}
	.tzfd-set-head .button-primary:hover,.tzfd-set-head .button-primary:focus{background:#c8161d;border-color:#c8161d;}
	.tzfd-set-layout{display:grid;grid-template-columns:220px minmax(0,1fr);gap:24px;align-items:start;}
	.tzfd-set-nav{background:#232834;border:1px solid #333a48;border-radius:12px;padding:8px;position:sticky;top:48px;}
	.tzfd-set-nav a{display:block;padding:9px 12px;border-radius:8px;color:#d6dbe5;text-decoration:none;font-weight:500;}
	.tzfd-set-nav a:hover{background:#2b3140;color:#f2f4f8;}
	.tzfd-set-nav a.is-active{background:rgba(227,27,35,.2);color:#fff;font-weight:600;}
	.tzfd-set-body{min-width:0;}
	.tzfd-subpane,.tzfd-set-card{background:#232834;color:#f2f4f8;border:1px solid #333a48;border-radius:12px;padding:20px 24px;margin-bottom:16px;}
	.tzfd-subpane h2,.tzfd-set-card h2{margin-top:0;color:#f2f4f8;}
	.tzfd-set-intro{color:#a4acba;margin-top:-4px;}
	.tzfd-set-pill{display:inline-block;padding:2px 10px;border-radius:999px;background:#2b3140;color:#d6dbe5;font-size:12px;font-weight:600;}
	.tzfd-set-links .button{margin:0 8px 8px 0;}
	@media (max-width: 782px){ .tzfd-set-layout{grid-template-columns:1fr;} .tzfd-set-nav{position:static;} }
CSS
);
wp_enqueue_style( 'tzfd-settings-screen' );
wp_print_styles( 'tzfd-settings-screen' );
wp_enqueue_script( 'tzfd-settings-tabs', plugin_dir_url( __FILE__ ) . 'js/inline/settings-tabs.js', array( 'jquery' ), '2.0.1', true );
wp_localize_script( 'tzfd-settings-tabs', 'tzfdSettingsTabs', array( 'active' => $tzfd_active ) );
?>
<div class="tzfd-set-head">
	<div>
		<h1><?php esc_html_e('Settings','titan-zero-trades-front-desk'); ?></h1>
		<p class="tzfd-set-sub"><?php esc_html_e('Set your business defaults and notification preferences.','titan-zero-trades-front-desk'); ?></p>
	</div>
	<button type="button" class="button button-primary" id="tzfd-settings-save"><?php esc_html_e('Save changes','titan-zero-trades-front-desk'); ?></button>
</div>
<?php
    if (isset($tzfd_message) && $tzfd_message != '') echo "<div id='setting-error-settings_updated' class='updated'><h2>".esc_html($tzfd_message)."</h2></div>";
?>
<div class="tzfd-set-layout">
	<nav class="tzfd-set-nav" aria-label="<?php esc_attr_e('Settings sections','titan-zero-trades-front-desk'); ?>">
<?php foreach ( $tzfd_nav as $tzfd_key => $tzfd_label ) : ?>
		<a href="#<?php echo esc_attr( $tzfd_key ); ?>" data-tab="<?php echo esc_attr( $tzfd_key ); ?>"<?php echo $tzfd_active === $tzfd_key ? ' class="is-active"' : ''; ?>><?php echo esc_html( $tzfd_label ); ?></a>
<?php endforeach; ?>
	</nav>
	<div class="tzfd-set-body">
<!-- General -->
<div class="tzfd-tab<?php echo $tzfd_active === 'general' ? ' tab-active' : ''; ?>" data-tab="general">
	<h2><?php esc_html_e('General','titan-zero-trades-front-desk'); ?></h2>
	<p class="tzfd-set-intro"><?php esc_html_e('Your business details and the currency every amount is shown in. Used on quotes, payment instructions and reports.','titan-zero-trades-front-desk'); ?></p>
	<?php if ( $tzfd_can_manage ) { TZFD_Money::render_form( true ); } else { echo '<p>' . esc_html__( 'Ask an administrator to change the business details and currency.', 'titan-zero-trades-front-desk' ) . '</p>'; } ?>
	<div class="tzfd-set-card">
		<h2><?php esc_html_e('Time zone','titan-zero-trades-front-desk'); ?></h2>
		<p><?php esc_html_e('Times use the WordPress site time zone:','titan-zero-trades-front-desk'); ?> <strong><?php echo esc_html( wp_timezone_string() ); ?></strong>. <a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>"><?php esc_html_e('Change it in WordPress settings','titan-zero-trades-front-desk'); ?></a>.</p>
	</div>
<?php TZFD_Client_Portal::render_settings_card(); ?>
</div>

<!-- booking -->
<div class="tzfd-tab<?php echo $tzfd_active === 'booking' ? ' tab-active' : ''; ?>" data-tab="booking">
<div class="tzfd-subpane">
	<h2><?php esc_html_e('Booking rules and spam protection','titan-zero-trades-front-desk'); ?></h2>
	<p class="tzfd-set-intro"><?php esc_html_e('How requests are confirmed, how spam is blocked, and what is stored about each customer.','titan-zero-trades-front-desk'); ?></p>
    <form  method="post" action="" name="tzfdformconf7">
        <input name="nonce" type="hidden" value="<?php echo esc_attr($tzfd_nonce); ?>" />
        <input name="TZFD_post_edition" type="hidden" value="1" />
        <input name="gotab" type="hidden" value="miscsettings" />
	    <table class="form-table">
            <tbody>
                <tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Block spam bots','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<select id="tzfd_honeypot" name="tzfd_honeypot">
	    					<option value=""  <?php if (get_option('tzfd_honeypot','') == "") echo 'selected'; ?> ><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
                            <option value="Yes"  <?php if (get_option('tzfd_honeypot','') != '') echo 'selected'; ?>  ><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
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
	    				<select id="tzfd_storeip" name="tzfd_storeip">
	    					<option value=""  <?php if (get_option('tzfd_storeip', TZFD_BOOK_DEFAULT_track_IP) == "") echo 'selected'; ?> ><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
                            <option value="Yes"  <?php if (get_option('tzfd_storeip',TZFD_BOOK_DEFAULT_track_IP) != '') echo 'selected'; ?>  ><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
	    				</select>
                        <br />
	    			</td>
	    		</tr>
                <tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Admin language','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<select id="tzfd_admin_language" name="tzfd_admin_language">
	    					<option value=""  <?php if (get_option('tzfd_admin_language', '') == '') echo 'selected'; ?> ><?php esc_html_e('Automatically detected from WordPress settings','titan-zero-trades-front-desk'); ?></option>
                            <option value="english"  <?php if (get_option('tzfd_admin_language','') == 'english') echo 'selected'; ?>  >English</option>
	    				</select>     
                        <br />                         
	    			</td>
	    		</tr> 				
	    	</tbody>
	    </table>
        <?php if ( current_user_can('manage_options') ) :
            $tzfd_intake_forms = array();
            foreach ( TZFD_Booking_View::calendar_names() as $tzfd_intake_id => $tzfd_intake_name ) {
                $tzfd_intake_forms[] = (object) array( 'id' => $tzfd_intake_id, 'form_name' => $tzfd_intake_name );
            }
            $tzfd_intake_default = TZFD_Intake_Mode::default_mode();
        ?>
        <h3 id="tzfd-intake"><?php esc_html_e('Booking intake','titan-zero-trades-front-desk'); ?></h3>
        <p class="description"><?php esc_html_e('Instant calendars confirm a booking straight away. Request calendars save each booking as Pending until you approve it; the time stays held while it waits. A calendar with no setting of its own follows the default.','titan-zero-trades-front-desk'); ?></p>
        <table class="form-table">
            <tbody>
                <tr valign="top">
                    <th scope="row"><label for="intake_default"><?php esc_html_e('Default for calendars','titan-zero-trades-front-desk'); ?></label></th>
                    <td>
                        <select id="intake_default" name="intake_default">
                            <option value="instant" <?php selected( $tzfd_intake_default, TZFD_Intake_Mode::MODE_INSTANT ); ?>><?php esc_html_e('Instant confirmation','titan-zero-trades-front-desk'); ?></option>
                            <option value="request" <?php selected( $tzfd_intake_default, TZFD_Intake_Mode::MODE_REQUEST ); ?>><?php esc_html_e('Request (approve first)','titan-zero-trades-front-desk'); ?></option>
                        </select>
                    </td>
                </tr>
                <?php foreach ( (array) $tzfd_intake_forms as $tzfd_intake_form ) :
                    $tzfd_intake_fid   = (int) $tzfd_intake_form->id;
                    $tzfd_intake_label = '' !== (string) $tzfd_intake_form->form_name ? $tzfd_intake_form->form_name : sprintf( /* translators: %d: calendar ID. */ __( 'Calendar %d', 'titan-zero-trades-front-desk' ), $tzfd_intake_fid );
                    $tzfd_intake_mode  = TZFD_Intake_Mode::get_for_form( $tzfd_intake_fid );
                ?>
                <tr valign="top">
                    <th scope="row"><label for="intake_form_<?php echo esc_attr( (string) $tzfd_intake_fid ); ?>"><?php echo esc_html( $tzfd_intake_label ); ?></label></th>
                    <td>
                        <select id="intake_form_<?php echo esc_attr( (string) $tzfd_intake_fid ); ?>" name="intake_form[<?php echo esc_attr( (string) $tzfd_intake_fid ); ?>]">
                            <option value="instant" <?php selected( $tzfd_intake_mode, TZFD_Intake_Mode::MODE_INSTANT ); ?>><?php esc_html_e('Instant confirmation','titan-zero-trades-front-desk'); ?></option>
                            <option value="request" <?php selected( $tzfd_intake_mode, TZFD_Intake_Mode::MODE_REQUEST ); ?>><?php esc_html_e('Request (approve first)','titan-zero-trades-front-desk'); ?></option>
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
<div class="tzfd-tab<?php echo $tzfd_active === 'hours' ? ' tab-active' : ''; ?>" data-tab="hours">
	<h2><?php esc_html_e('Business hours','titan-zero-trades-front-desk'); ?></h2>
	<p class="tzfd-set-intro"><?php esc_html_e('Opening hours are set for each calendar and each worker, so a booking follows the hours of the calendar or worker it is for. There are no site-wide hours yet.','titan-zero-trades-front-desk'); ?></p>
	<div class="tzfd-set-card tzfd-set-links">
		<p><?php esc_html_e('Open a calendar to set its hours, or open Workers and locations to set the hours of people, equipment and rooms.','titan-zero-trades-front-desk'); ?></p>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::CALENDAR ) ); ?>"><?php esc_html_e('Calendars','titan-zero-trades-front-desk'); ?></a>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::WORKERS ) ); ?>"><?php esc_html_e('Workers and locations','titan-zero-trades-front-desk'); ?></a>
	</div>
</div>

<!-- notifications -->
<div class="tzfd-tab<?php echo $tzfd_active === 'notifications' ? ' tab-active' : ''; ?>" data-tab="notifications">
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
     <input name="nonce" type="hidden" value="<?php echo esc_attr($tzfd_nonce); ?>" />
     <table class="form-table">
        <tr valign="top">
        <td scope="row" colspan="2"><strong><?php esc_html_e('Send reports automatically?','titan-zero-trades-front-desk'); ?></strong>
          <?php $tzfd_option = get_option('tzfd_rep_enable', 'no'); ?>
          <select name="tzfd_rep_enable">
           <option value="no"<?php if ($tzfd_option == 'no' || $tzfd_option == '') echo ' selected'; ?>><?php esc_html_e('No','titan-zero-trades-front-desk'); ?></option>
           <option value="yes"<?php if ($tzfd_option == 'yes') echo ' selected'; ?>><?php esc_html_e('Yes','titan-zero-trades-front-desk'); ?></option>
          </select>
          &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;
          <strong><?php esc_html_e('Send a report every','titan-zero-trades-front-desk'); ?>:</strong> <input type="text" name="tzfd_rep_days" size="1" value="<?php echo esc_attr(get_option('tzfd_rep_days', '1')); ?>" /> <?php esc_html_e('days','titan-zero-trades-front-desk'); ?>
          &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;
          <strong><?php esc_html_e('Hour to send (server time)','titan-zero-trades-front-desk'); ?>:</strong>
          <select name="tzfd_rep_hour">
           <?php
             $tzfd_hour = get_option('tzfd_rep_hour', '0');
             for ($tzfd_k=0;$tzfd_k<24;$tzfd_k++)
                 echo '<option value="'.intval($tzfd_k).'"'.($tzfd_hour==$tzfd_k?' selected':'').'>'.($tzfd_k<10?'0':'').intval($tzfd_k).'</option>';
           ?>
          </select>
        </td>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Sender address','titan-zero-trades-front-desk'); ?></th>
        <td><input type="text" name="tzfd_fp_from_email" size="70" value="<?php echo esc_attr(get_option('tzfd_fp_from_email', get_the_author_meta('user_email', get_current_user_id()) )); ?>" /></td>
        </tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Recipient addresses','titan-zero-trades-front-desk'); ?></th>
        <td><input type="text" name="tzfd_rep_emails" size="70" value="<?php echo esc_attr(get_option('tzfd_rep_emails', '')); ?>" /></td>
        </tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Subject line','titan-zero-trades-front-desk'); ?></th>
        <td><input type="text" name="tzfd_rep_subject" size="70" value="<?php echo esc_attr(get_option('tzfd_rep_subject', 'Submissions report...')); ?>" /></td>
        </tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Message format','titan-zero-trades-front-desk'); ?></th>
        <td>
          <?php $tzfd_option = get_option('tzfd_rep_emailformat', 'text'); ?>
          <select name="tzfd_rep_emailformat">
           <option value="text"<?php if ($tzfd_option != 'html') echo ' selected'; ?>><?php esc_html_e('Plain text (default)','titan-zero-trades-front-desk'); ?></option>
           <option value="html"<?php if ($tzfd_option == 'html') echo ' selected'; ?>><?php esc_html_e('HTML (write HTML in the message box below)','titan-zero-trades-front-desk'); ?></option>
          </select>
        </td>
        </tr>
        <tr valign="top">
        <th scope="row"><?php esc_html_e('Message (the CSV file is attached automatically','titan-zero-trades-front-desk'); ?>)</th>
        <td><textarea type="text" name="tzfd_rep_message" rows="3" cols="80"><?php echo esc_textarea(get_option('tzfd_rep_message', 'Attached you will find the data from the form submissions.')); ?></textarea></td>
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

<!-- Payments -->
<div class="tzfd-tab<?php echo $tzfd_active === 'payments' ? ' tab-active' : ''; ?>" data-tab="payments">
	<?php if ( $tzfd_can_manage ) : ?>
	<div class="tzfd-subpane">
		<h2><?php esc_html_e('Payment methods','titan-zero-trades-front-desk'); ?></h2>
		<p class="tzfd-set-intro"><?php esc_html_e('The ways customers can pay. Turn each on or off, add a note for customers and choose the default. Recording money received is on the Payments page.','titan-zero-trades-front-desk'); ?></p>
		<?php TZFD_Payments::methods_form( TZFD_Payments::methods(), true ); ?>
	</div>
	<div class="tzfd-subpane">
		<h2><?php esc_html_e('Quote defaults','titan-zero-trades-front-desk'); ?></h2>
		<?php if ( class_exists( 'TZFD_Quote_Screens' ) ) { TZFD_Quote_Screens::render_settings_form( true ); } else { echo '<p class="tzfd-set-intro">' . esc_html__( 'Turn on Quotes on the Add-ons page to set quote defaults here.', 'titan-zero-trades-front-desk' ) . '</p>'; } ?>
	</div>
	<div class="tzfd-subpane">
		<h2><?php esc_html_e('Payments received','titan-zero-trades-front-desk'); ?></h2>
		<p class="tzfd-set-intro"><?php esc_html_e('Record money received by PayPal, bank transfer or cash, and see what has come in.','titan-zero-trades-front-desk'); ?></p>
		<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::PAYMENTS ) ); ?>"><?php esc_html_e('Open Payments','titan-zero-trades-front-desk'); ?></a></p>
	</div>
	<?php else : ?>
	<div class="tzfd-subpane"><p><?php esc_html_e('Ask an administrator to change payment methods and quote defaults.','titan-zero-trades-front-desk'); ?></p></div>
	<?php endif; ?>
</div>

<!-- integrations -->
<div class="tzfd-tab<?php echo $tzfd_active === 'integrations' ? ' tab-active' : ''; ?>" data-tab="integrations">
<div class="tzfd-subpane">
<?php
$tzfd_has_key       = (string) get_option('tzfd_ai_api_key', '') !== '';
$tzfd_is_placeholder = $tzfd_has_key && TZFD_AI_Options::key_is_placeholder();
$tzfd_placeholder   = '';
if ( $tzfd_has_key && ! $tzfd_is_placeholder ) {
    $tzfd_tail = substr( TZFD_Addon_Support::secret_decrypt( get_option('tzfd_ai_api_key', '') ), -4 );
    /* translators: %s: the last four characters of the saved API key. */
    $tzfd_placeholder = sprintf( __( 'Saved, ends in %s. Leave blank to keep it.', 'titan-zero-trades-front-desk' ), $tzfd_tail );
}
$tzfd_presets   = TZFD_AI_Options::presets();
$tzfd_current   = TZFD_AI_Options::current_choice();
$tzfd_cur_p     = TZFD_AI_Options::preset( $tzfd_current );
$tzfd_on        = TZFD_Addon_Support::ai_configured();
$tzfd_model_now = (string) get_option('tzfd_ai_model', '');
$tzfd_base_now  = $tzfd_current === 'custom' ? (string) get_option('tzfd_ai_api_base', '') : '';
$tzfd_groups    = array(
    'free'  => __( 'Options', 'titan-zero-trades-front-desk' ),
    'own'   => __( 'Your own key', 'titan-zero-trades-front-desk' ),
);
?>
<h2><?php esc_html_e('AI','titan-zero-trades-front-desk'); ?></h2>
<p class="description"><?php esc_html_e('Create a key on the provider\'s website, paste it below and choose that provider. Chat messages are sent to the provider you choose, so check its terms before you use it with customer details.','titan-zero-trades-front-desk'); ?></p>
<p><?php
if ( $tzfd_on && $tzfd_cur_p ) {
    /* translators: 1: provider name, 2: model name or the word default. */
    printf( esc_html__( 'AI is on. Using %1$s (model: %2$s).', 'titan-zero-trades-front-desk' ), esc_html( $tzfd_cur_p['label'] ), esc_html( $tzfd_model_now !== '' ? $tzfd_model_now : (string) $tzfd_cur_p['model'] ) );
} else {
    esc_html_e('AI is off. Choose an option below and paste its key, or save your own key. The AI booking assistant, AI Setup and AI-drafted quotes stay hidden until then. Everything else works without AI.','titan-zero-trades-front-desk');
}
?></p>

<h3><?php esc_html_e('Options','titan-zero-trades-front-desk'); ?></h3>
<table class="widefat striped" style="max-width:820px;">
<?php foreach ( $tzfd_presets as $tzfd_p ) { if ( $tzfd_p['group'] !== 'free' ) continue; ?>
<tr><td style="width:200px;"><strong><?php echo esc_html( $tzfd_p['label'] ); ?></strong></td><td><?php echo esc_html( $tzfd_p['note'] ); ?></td><td style="width:140px;"><?php if ( $tzfd_p['key_url'] !== '' ) { ?><a href="<?php echo esc_url( $tzfd_p['key_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Get a key','titan-zero-trades-front-desk'); ?></a><?php } ?></td></tr>
<?php } ?>
</table>

<h3><?php esc_html_e('Set up your AI','titan-zero-trades-front-desk'); ?></h3>
<form method="post">
<input type="hidden" name="nonce" value="<?php echo esc_attr($tzfd_nonce); ?>" />
<input type="hidden" name="gotab" value="ai" />
<table class="form-table" role="presentation">
<tr><th scope="row"><label for="tzfd_ai_choice"><?php esc_html_e('Use','titan-zero-trades-front-desk'); ?></label></th>
<td><select id="tzfd_ai_choice" name="ai_choice">
<?php foreach ( $tzfd_groups as $tzfd_gkey => $tzfd_glabel ) {
    $tzfd_items = array_filter( $tzfd_presets, function ( $x ) use ( $tzfd_gkey ) { return $x['group'] === $tzfd_gkey; } );
    if ( empty( $tzfd_items ) ) continue; ?>
<optgroup label="<?php echo esc_attr( $tzfd_glabel ); ?>">
<?php foreach ( $tzfd_items as $tzfd_p ) { ?>
<option value="<?php echo esc_attr( $tzfd_p['id'] ); ?>" <?php selected( $tzfd_current, $tzfd_p['id'] ); ?>><?php echo esc_html( $tzfd_p['label'] ); ?></option>
<?php } ?>
</optgroup>
<?php } ?>
</select></td></tr>
<tr><th scope="row"><label for="tzfd_api_key"><?php esc_html_e('API key','titan-zero-trades-front-desk'); ?></label></th>
<td><input type="password" id="tzfd_api_key" name="api_key" size="50" autocomplete="new-password" class="regular-text"
 placeholder="<?php echo esc_attr( $tzfd_placeholder ); ?>" />
<?php if ( $tzfd_has_key && ! $tzfd_is_placeholder ) { ?><label style="margin-left:8px;"><input type="checkbox" name="api_key_clear" value="1" /> <?php esc_html_e('Remove the saved key','titan-zero-trades-front-desk'); ?></label><?php } ?>
<p class="description"><?php esc_html_e('Encrypted before it is saved. Only its last four characters are shown afterwards. Not needed for Ollama on your own server.','titan-zero-trades-front-desk'); ?></p></td></tr>
<tr><th scope="row"><label for="tzfd_ai_model"><?php esc_html_e('Model (optional)','titan-zero-trades-front-desk'); ?></label></th>
<td><input type="text" id="tzfd_ai_model" name="model" size="40" class="regular-text" value="<?php echo esc_attr( $tzfd_model_now ); ?>" placeholder="<?php echo esc_attr( $tzfd_cur_p ? (string) $tzfd_cur_p['model'] : '' ); ?>" />
<p class="description"><?php esc_html_e('Leave blank to use the suggested model for the option you choose. Type a model name only if your provider has given you one.','titan-zero-trades-front-desk'); ?></p></td></tr>
<tr><th scope="row"><label for="tzfd_api_base"><?php esc_html_e('Server address (Other only)','titan-zero-trades-front-desk'); ?></label></th>
<td><input type="text" id="tzfd_api_base" name="api_base" size="50" class="regular-text" value="<?php echo esc_attr( $tzfd_base_now ); ?>" placeholder="https://api.example.com/v1" />
<p class="description"><?php esc_html_e('Only for Other (OpenAI-compatible server). The other options fill this in for you.','titan-zero-trades-front-desk'); ?></p></td></tr>
</table>
<p><input type="submit" value="<?php esc_attr_e('Save AI settings','titan-zero-trades-front-desk'); ?>" class="button button-primary" /></p>
</form>

</div>
<div class="tzfd-subpane">
	<h2><?php esc_html_e('Add-on settings','titan-zero-trades-front-desk'); ?></h2>
	<p class="tzfd-set-intro"><?php esc_html_e('Each add-on keeps its own settings. They open from the add-on on the Add-ons page.','titan-zero-trades-front-desk'); ?></p>
	<?php
	global $tzfd_addons_objs_list;
	$tzfd_with_settings = array();
	foreach ( (array) $tzfd_addons_objs_list as $tzfd_obj ) {
		if ( is_object( $tzfd_obj ) && method_exists( $tzfd_obj, 'get_addon_settings' ) && method_exists( $tzfd_obj, 'addon_is_active' ) && $tzfd_obj->addon_is_active() ) {
			$tzfd_with_settings[] = $tzfd_obj;
		}
	}
	if ( empty( $tzfd_with_settings ) ) :
		echo '<p>' . esc_html__( 'No add-on with settings is switched on.', 'titan-zero-trades-front-desk' ) . '</p>';
	else : ?>
	<table class="widefat striped">
		<tbody>
		<?php foreach ( $tzfd_with_settings as $tzfd_obj ) : ?>
			<tr><td><?php echo esc_html( $tzfd_obj->get_addon_name() ); ?></td><td><?php esc_html_e( 'On', 'titan-zero-trades-front-desk' ); ?></td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
	<p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . TZFD_Admin_Menu::ADDONS ) ); ?>"><?php esc_html_e('Open Add-ons','titan-zero-trades-front-desk'); ?></a></p>
</div>

</div>

<!-- Advanced -->
<div class="tzfd-tab<?php echo $tzfd_active === 'advanced' ? ' tab-active' : ''; ?>" data-tab="advanced">
<div class="tzfd-subpane">
	<h2><?php esc_html_e('Troubleshooting','titan-zero-trades-front-desk'); ?></h2>
	<p><b><?php esc_html_e('Important!','titan-zero-trades-front-desk'); ?>:</b> <?php esc_html_e('Use this area only if you are experiencing conflicts with third party plugins, with the theme scripts or with the character encoding.','titan-zero-trades-front-desk'); ?></p>
    <form  method="post" action="" name="tzfdformconf2">
        <input name="TZFD_post_edition" type="hidden" value="1" />
        <input name="nonce" type="hidden" value="<?php echo esc_attr($tzfd_nonce); ?>" />
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
        <input name="nonce" type="hidden" value="<?php echo esc_attr($tzfd_nonce); ?>" />
        <input name="gotab" type="hidden" value="csvarea" />
	    <table class="form-table">
            <tbody>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Columns to leave out of the bookings CSV','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<input name="bocsvexclude" type="text" value="<?php echo esc_attr(get_option('tzfd_bocsvexclude',"")); ?>" /><br />
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
	    				<input name="schcsvexclude" type="text" value="<?php echo esc_attr(get_option('tzfd_schcsvexclude',"")); ?>" /><br />
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
    <p>In this area you can customize the data displayed in the "<strong>Schedule Calendar View</strong>" of the dashboard accessible through the "<strong>Schedule</strong>" button in the <a href="<?php echo esc_url( admin_url( 'admin.php?page='.$this->menu_parameter ) ); ?>">calendars list</a>.</p>
	<p>For <strong>help and more configuration options</strong> check "Customizing the schedule calendar contents and colors".
	
    <form  method="post" action="" name="tzfdformconf6">
        <input name="nonce" type="hidden" value="<?php echo esc_attr($tzfd_nonce); ?>" />
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
	    				<input name="schcaltitle_admin" type="text" value="<?php echo esc_attr(get_option('tzfd_schcaltitle_admin',TZFD_SCHCALTITLE_ADMIN)); ?>" /><br />
                        <em>Check the list of tags available for the contents.</em>
                        <em><br />The following additional tags are available for the specific time listed: <b>%app_service% - %app_date% - %app_slot% - %app_quantity% - %app_status%</b> </em>
	    			</td>
	    		</tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Event details','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<textarea cols="80" rows="3"  name="schcalcontent_admin"><?php echo esc_textarea(get_option('tzfd_schcalcontent_admin',TZFD_SCHCALCONTENT_ADMIN)); ?></textarea><br />
                        <em>Check the list of tags available for the contents.</em>
                        <em><br />The following additional tags are available for the specific time listed: <b>%app_service% - %app_date% - %app_slot% - %app_quantity% - %app_status%</b> </em>
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
	    				<input name="schcaltitle_public" type="text" value="<?php echo esc_attr(get_option('tzfd_schcaltitle_public',TZFD_SCHCALTITLE_PUBLIC)); ?>" /><br />
                        <em>Check the list of tags available for the contents.</em>
                        <em><br />The following additional tags are available for the specific time listed: <b>%app_service% - %app_date% - %app_slot% - %app_quantity% - %app_status%</b> </em>
	    			</td>
	    		</tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Event details','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<textarea cols="80" rows="3" name="schcalcontent_public"><?php echo esc_textarea(get_option('tzfd_schcalcontent_public',TZFD_SCHCALCONTENT_PUBLIC)); ?></textarea><br />
                        <em>Check the list of tags available for the contents.</em>
                        <em><br />The following additional tags are available for the specific time listed: <b>%app_service% - %app_date% - %app_slot% - %app_quantity% - %app_status%</b> </em>
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
	    				<select id="tzfd_sch_admin_blockedt" name="tzfd_sch_admin_blockedt">
	    					<option value=""  <?php if (get_option('tzfd_sch_admin_blockedt','') != "Yes") echo 'selected'; ?> >No</option>
                            <option value="Yes"  <?php if (get_option('tzfd_sch_admin_blockedt','') == 'Yes') echo 'selected'; ?>  >Yes</option>
	    				</select>
	    			</td>
	    		</tr>
	    		<tr valign="top">
	    			<th scope="row">
	    				<label><?php esc_html_e('Hide this calendar from the schedule','titan-zero-trades-front-desk'); ?></label>
	    			</th>
	    			<td>
	    				<input size="80" name="tzfd_schcalcontent_exclude" value="<?php echo esc_attr(get_option('tzfd_schcalcontent_exclude','')); ?>" /><br />
                        <em>Indicate the calendar ID to be excluded, ex: <strong>fieldname1</strong>. Comma separated for multiple IDs.</em>
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
