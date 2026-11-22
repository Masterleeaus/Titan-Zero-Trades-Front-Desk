<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-request-scalar.php';

if ( ! is_admin() || ! is_user_logged_in() ) {
    wp_die( esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}


require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';
require_once __DIR__ . '/includes/class-tzfd-admin-request-ids.php';
require_once __DIR__ . '/includes/class-tzfd-form-list-input.php';
require_once __DIR__ . '/includes/class-tzfd-calendar-write-result.php';

$tzfd_current_user_access = current_user_can('tz_fd_access');
// The plugin capability does not grant access to another company's calendars.
$tzfd_list_company = class_exists( 'TZFD_Scoped_Store' ) ? (int) TZFD_Scoped_Store::company_id() : 0;
if ( $tzfd_list_company <= 0 ) {
    wp_die( esc_html__( 'An active company is required to manage booking calendars.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}
// Calendar mutations must be submitted by POST. Reject legacy GET action URLs even
// when a valid nonce is present: GET requests are safe/read-only by contract.
foreach ( array( 'a', 'u', 'd', 'c' ) as $tzfd_list_mutation_key ) {
    if ( array_key_exists( $tzfd_list_mutation_key, $_GET ) ) {
        wp_die( esc_html__( 'Use the calendar action buttons to make changes.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
    }
}
$tzfd_list_method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) );
// Calendar actions are POST-only: verify the list nonce before any POST field is read.
if ( 'POST' === $tzfd_list_method && ! wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_list' ) ) {
    wp_die( esc_html__( 'You cannot modify these calendars.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}
$tzfd_list_payload = 'POST' === $tzfd_list_method ? $_POST : array();
// All mutations must be bound to this company and use validated identifiers.
$tzfd_list_action = TZFD_Form_List_Input::validate( $tzfd_list_payload );
if ( is_wp_error( $tzfd_list_action ) ) {
    wp_die( esc_html__( 'Invalid calendar action parameters.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
}
if ( '' !== $tzfd_list_action['operation'] && ( ! $tzfd_current_user_access || ! wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_list' ) ) ) {
    wp_die( esc_html__( 'You cannot modify these calendars.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}

global $wpdb, $tzfd_addons_active_list, $tzfd_addons_objs_list;

$tzfd_message = "";
$tzfd_list_redirect = '';


if ( 'a' === $tzfd_list_action['operation'] )
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_list' ) ) { $this->nonce_failed(); }
    define('TZFD_BOOK_DEFAULT_fp_from_email', get_the_author_meta('user_email', get_current_user_id()) );
    define('TZFD_BOOK_DEFAULT_fp_destination_emails', TZFD_Settings_Extras::default_notify_email( TZFD_BOOK_DEFAULT_fp_from_email ));

    $tzfd_list_inserted = $wpdb->insert( $wpdb->prefix.TZFD_Tables::FORMS, array(
                                      'company_id' => $tzfd_list_company,
                                      'form_name' => $tzfd_list_action['name'],

                                      'form_structure' => TZFD_BOOK_DEFAULT_form_structure,

                                      'fp_from_email' => $this->get_option('fp_from_email', TZFD_BOOK_DEFAULT_fp_from_email),
                                      'fp_destination_emails' => TZFD_BOOK_DEFAULT_fp_destination_emails, // New calendars take the default alert recipients, not the current calendar's.
                                      'fp_subject' => $this->get_option('fp_subject', TZFD_BOOK_DEFAULT_fp_subject),
                                      'fp_inc_additional_info' => $this->get_option('fp_inc_additional_info', TZFD_BOOK_DEFAULT_fp_inc_additional_info),
                                      'fp_return_page' => TZFD_Settings_Extras::default_return_page( TZFD_BOOK_DEFAULT_fp_return_page ),
                                      'fp_message' => $this->get_option('fp_message', TZFD_BOOK_DEFAULT_fp_message),
                                      'fp_emailformat' => $this->get_option('fp_emailformat', TZFD_BOOK_DEFAULT_email_format),

                                      'cu_enable_copy_to_user' => TZFD_Settings_Extras::default_customer_confirm( TZFD_BOOK_DEFAULT_cu_enable_copy_to_user ),
                                      'cu_user_email_field' => $this->get_option('cu_user_email_field', TZFD_BOOK_DEFAULT_cu_user_email_field),
                                      'cu_subject' => $this->get_option('cu_subject', TZFD_BOOK_DEFAULT_cu_subject),
                                      'cu_message' => $this->get_option('cu_message', TZFD_BOOK_DEFAULT_cu_message),
                                      'cu_emailformat' => $this->get_option('cu_emailformat', TZFD_BOOK_DEFAULT_email_format),

                                      'vs_text_is_required' => $this->get_option('vs_text_is_required', TZFD_BOOK_DEFAULT_vs_text_is_required),
                                      'vs_text_maxapp' => $this->get_option('vs_text_maxapp', TZFD_BOOK_DEFAULT_vs_text_maxapp),
                                      'vs_text_is_email' => $this->get_option('vs_text_is_email', TZFD_BOOK_DEFAULT_vs_text_is_email),
                                      'vs_text_datemmddyyyy' => $this->get_option('vs_text_datemmddyyyy', TZFD_BOOK_DEFAULT_vs_text_datemmddyyyy),
                                      'vs_text_dateddmmyyyy' => $this->get_option('vs_text_dateddmmyyyy', TZFD_BOOK_DEFAULT_vs_text_dateddmmyyyy),
                                      'vs_text_number' => $this->get_option('vs_text_number', TZFD_BOOK_DEFAULT_vs_text_number),
                                      'vs_text_digits' => $this->get_option('vs_text_digits', TZFD_BOOK_DEFAULT_vs_text_digits),
                                      'vs_text_max' => $this->get_option('vs_text_max', TZFD_BOOK_DEFAULT_vs_text_max),
                                      'vs_text_min' => $this->get_option('vs_text_min', TZFD_BOOK_DEFAULT_vs_text_min),

                                      'cv_enable_captcha' => $this->get_option('cv_enable_captcha', TZFD_BOOK_DEFAULT_cv_enable_captcha),
                                      'cv_width' => $this->get_option('cv_width', TZFD_BOOK_DEFAULT_cv_width),
                                      'cv_height' => $this->get_option('cv_height', TZFD_BOOK_DEFAULT_cv_height),
                                      'cv_chars' => $this->get_option('cv_chars', TZFD_BOOK_DEFAULT_cv_chars),
                                      'cv_font' => $this->get_option('cv_font', TZFD_BOOK_DEFAULT_cv_font),
                                      'cv_min_font_size' => $this->get_option('cv_min_font_size', TZFD_BOOK_DEFAULT_cv_min_font_size),
                                      'cv_max_font_size' => $this->get_option('cv_max_font_size', TZFD_BOOK_DEFAULT_cv_max_font_size),
                                      'cv_noise' => $this->get_option('cv_noise', TZFD_BOOK_DEFAULT_cv_noise),
                                      'cv_noise_length' => $this->get_option('cv_noise_length', TZFD_BOOK_DEFAULT_cv_noise_length),
                                      'cv_background' => $this->get_option('cv_background', TZFD_BOOK_DEFAULT_cv_background),
                                      'cv_border' => $this->get_option('cv_border', TZFD_BOOK_DEFAULT_cv_border),
                                      'cv_text_enter_valid_captcha' => $this->get_option('cv_text_enter_valid_captcha', TZFD_BOOK_DEFAULT_cv_text_enter_valid_captcha)
                                     )
                      );

    if ( ! TZFD_Calendar_Write_Result::inserted( $tzfd_list_inserted, $wpdb->insert_id, $wpdb->last_error ) ) {
        wp_die( esc_html__( 'Calendar creation failed. No calendar was confirmed.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 500 ) );
    }
    $tzfd_list_redirect = '?page=tzfd&cal=' . absint( $wpdb->insert_id );
}
else if ( 'u' === $tzfd_list_action['operation'] )
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_list' ) ) { $this->nonce_failed(); }
    $tzfd_list_updated = $wpdb->query( $wpdb->prepare('UPDATE %i SET form_name=%s WHERE id=%d AND company_id=%d', $wpdb->prefix.TZFD_Tables::FORMS, $tzfd_list_action['name'], $tzfd_list_action['id'], $tzfd_list_company ) );
    $tzfd_list_verified = $wpdb->get_var( $wpdb->prepare( 'SELECT form_name FROM %i WHERE id=%d AND company_id=%d', $wpdb->prefix . TZFD_Tables::FORMS, $tzfd_list_action['id'], $tzfd_list_company ) );
    if ( ! TZFD_Calendar_Write_Result::updated( $tzfd_list_updated, $tzfd_list_verified, $tzfd_list_action['name'], $wpdb->last_error ) ) {
        wp_die( esc_html__( 'Calendar rename was not verified.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 500 ) );
    }
    $tzfd_message = __( 'Item updated', 'titan-zero-trades-front-desk' );
}
else if ( 'd' === $tzfd_list_action['operation'] )
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_list' ) ) { $this->nonce_failed(); }
    $tzfd_list_deleted = $wpdb->query( $wpdb->prepare('DELETE FROM %i WHERE id=%d AND company_id=%d', $wpdb->prefix.TZFD_Tables::FORMS, $tzfd_list_action['id'], $tzfd_list_company ) );
    if ( ! TZFD_Calendar_Write_Result::deleted( $tzfd_list_deleted, $wpdb->last_error ) ) {
        wp_die( esc_html__( 'Calendar deletion failed or the calendar no longer exists.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 500 ) );
    }
    $tzfd_message = __( 'Item deleted', 'titan-zero-trades-front-desk' );
} else if ( 'c' === $tzfd_list_action['operation'] )
{
    if ( ! wp_verify_nonce( tzfd_request_text( 'post', "anonce" ), 'tz_fd_actions_list' ) ) { $this->nonce_failed(); }
    $tzfd_myrows = $wpdb->get_row( $wpdb->prepare("SELECT * FROM %i WHERE id=%d AND company_id=%d", $wpdb->prefix.TZFD_Tables::FORMS, $tzfd_list_action['id'], $tzfd_list_company ) , ARRAY_A); 
    // The clone belongs to the current company, never to the company of the row it copies.
    if ( is_array( $tzfd_myrows ) && $tzfd_list_company > 0 ) {
        unset($tzfd_myrows["id"]);
        $tzfd_myrows["company_id"] = $tzfd_list_company;
        $tzfd_myrows["form_name"] = 'Cloned: '.$tzfd_myrows["form_name"];
        $tzfd_list_cloned = $wpdb->insert( $wpdb->prefix . TZFD_Tables::FORMS, $tzfd_myrows );
        if ( ! TZFD_Calendar_Write_Result::inserted( $tzfd_list_cloned, $wpdb->insert_id, $wpdb->last_error ) ) {
            wp_die( esc_html__( 'Calendar duplication failed.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 500 ) );
        }
    } else {
        wp_die( esc_html__( 'The selected calendar could not be found in this business.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 404 ) );
    }
    $tzfd_message = __( 'Item duplicated/cloned', 'titan-zero-trades-front-desk' );
}

// Post/Redirect/Get prevents browser refreshes from repeating a mutation.
if ( '' !== $tzfd_list_action['operation'] ) {
    $tzfd_list_destination = 'admin.php?page=' . rawurlencode( $this->menu_parameter );
    if ( 'a' === $tzfd_list_action['operation'] && (int) $wpdb->insert_id > 0 ) {
        $tzfd_list_destination .= '&cal=' . (int) $wpdb->insert_id;
    }
    wp_safe_redirect( admin_url( $tzfd_list_destination ) );
    exit;
}

// Success notices are never inferred from an untrusted query parameter.

if ($tzfd_message) echo "<div id='setting-error-settings_updated' class='updated'><h2>".esc_html($tzfd_message)."</h2></div>";

$tzfd_nonce = wp_create_nonce( 'tz_fd_actions_list' );

// Page assets (review R2). Enqueued here because this template renders after admin_enqueue_scripts.
wp_register_style( 'tzfd-admin-list-inline', false, array(), '2.0.1' );
wp_enqueue_style( 'tzfd-admin-list-inline' );
wp_add_inline_style( 'tzfd-admin-list-inline', 'input.button.tzfd-cal-row-btn{margin-bottom:5px;}' );
wp_enqueue_script( 'tzfd-admin-list', plugins_url( 'js/inline/admina-list.js', __FILE__ ), array(), '2.0.1', true );
wp_localize_script( 'tzfd-admin-list', 'tzfdListData', array(
    'page'     => $this->menu_parameter,
    'nonce'    => $tzfd_nonce,
    'redirect' => $tzfd_list_redirect,
) );

?>
<div class="wrap tzfd-admin-page tzfd-page tzfd-page-calendars">
<h1><?php echo esc_html($this->plugin_name); ?></h1>


<?php if ($tzfd_current_user_access) { ?>
 <div class="tzfd-section-container">
	<div class="tzfd-section">
		<label><?php esc_html_e('New Calendar','titan-zero-trades-front-desk'); ?></label>&nbsp;&nbsp;&nbsp;
		<input type="text" name="tz_fd_itemname" id="tz_fd_itemname" placeholder=" - Calendar Name - " class="tzfd-new-calendar" />
		<input type="button" class="button-primary" value="<?php echo esc_attr__( 'Add New', 'titan-zero-trades-front-desk' ); ?>" data-tzfd-action="tz_fd_addItem" />
	</div>
</div>
<?php } ?>

<h2><?php esc_html_e('Calendars List','titan-zero-trades-front-desk'); ?></h2>

<div class="tzfd-section-container">
	<div class="tzfd-section">

  <table cellspacing="10" cellpadding="6" class="tzfd-calendars-list">
   <tr>
    <th align="left"><?php esc_html_e('ID','titan-zero-trades-front-desk'); ?></th><th align="left"><?php esc_html_e('Form Name','titan-zero-trades-front-desk'); ?></th><th align="left">&nbsp; &nbsp; <?php esc_html_e('Options','titan-zero-trades-front-desk'); ?></th><th align="left"><?php esc_html_e('Shortcode for Pages and Posts','titan-zero-trades-front-desk'); ?></th>
   </tr>
<?php

  $current_user = wp_get_current_user();
  $tzfd_myrows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id = %d', $wpdb->prefix . TZFD_Tables::FORMS, $tzfd_list_company ) ); 
  if ( ! is_array( $tzfd_myrows ) || ! empty( $wpdb->last_error ) ) {
      wp_die( esc_html__( 'Unable to load calendars.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 500 ) );
  }
  foreach ( $tzfd_myrows as $tzfd_item )
  {
   $tzfd_options = TZFD_Access_List::decode($tzfd_item->tz_fd_user_access);

   if ( (int) $tzfd_item->company_id === $tzfd_list_company && ( $tzfd_current_user_access || in_array( (int) $current_user->ID, $tzfd_options, true ) ) )
   {
?>
   <tr>
    <td nowrap class="firstcol"><?php echo intval($tzfd_item->id); ?></td>
    <td nowrap><input type="text" name="calname_<?php echo intval($tzfd_item->id); ?>" id="calname_<?php echo intval($tzfd_item->id); ?>" value="<?php echo esc_attr($tzfd_item->form_name); ?>" /></td>

    <td>
<?php if ($tzfd_current_user_access) { ?>
                             <input class="tzfd-cal-row-btn button" type="button" name="calupdate_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Rename','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tz_fd_updateItem" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
<?php } ?>
<?php if ($tzfd_current_user_access || (isset($tzfd_item->tz_fd_user_access_settings) && $tzfd_item->tz_fd_user_access_settings == 'true')) { ?>
                             <input class="tzfd-cal-row-btn button-primary button" type="button" name="calmanage_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Edit','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tz_fd_manageSettings" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
<?php } ?>
<?php if ($tzfd_current_user_access) { ?>
                             <input class="tzfd-cal-row-btn button-primary button" type="button" name="calpublish_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Publish','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tz_fd_publish" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
<?php } ?>
                             <input class="tzfd-cal-row-btn button-primary2 button" type="button" name="calmessages_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Booking Orders','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tz_fd_viewMessages" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
                             <input class="tzfd-cal-row-btn button-primary2 button" type="button" name="calschedule_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Schedule','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tz_fd_viewSchedule" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
                             <input class="tzfd-cal-row-btn button-primary3 button" type="button" name="caladdbk_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Add Booking','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tz_fd_addbk" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
                             <input class="tzfd-cal-row-btn button-primary3 button" type="button" name="calblocktimes_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Block Times','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tz_fd_blocktimes" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
                             <input class="tzfd-cal-row-btn button" type="button" name="calreport_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Stats','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tz_fd_viewReport" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
<?php if ($tzfd_current_user_access) { ?>
                             <input class="tzfd-cal-row-btn button" type="button" name="calclone_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Clone','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tz_fd_cloneItem" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
                             <input class="tzfd-cal-row-btn button" type="button" name="caldelete_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Delete','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tz_fd_deleteItem" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
<?php } ?>
    </td>
    <td class="lastcol"><nobr>[<?php echo esc_html($this->shorttag); ?> id="<?php echo intval($tzfd_item->id); ?>"]<nobr></td>
   </tr>
<?php
    }
   }
?>

  </table>

     <div class="clearer"></div>

	</div>
</div>




<div id="normal-sortables" class="meta-box-sortables">

<?php if ($tzfd_current_user_access) { ?>


<?php
	if( count( $tzfd_addons_active_list ) )
	{
		foreach( $tzfd_addons_active_list as $tzfd_addon_id ) if( isset( $tzfd_addons_objs_list[ $tzfd_addon_id ] ) ) 
        {
            // Add-on settings are shown on the Add-ons page.
        }
	}
?>



<?php } ?>

</div>


[<a href="<?php echo esc_url('mailto:support@titanzero.io'); ?>"><?php esc_html_e('Free Support','titan-zero-trades-front-desk'); ?></a>] | [<a href="<?php echo esc_url('mailto:support@titanzero.io'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Help','titan-zero-trades-front-desk'); ?></a>]
</form>
</div>