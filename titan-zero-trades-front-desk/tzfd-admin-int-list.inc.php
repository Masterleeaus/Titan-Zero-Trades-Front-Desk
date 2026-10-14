<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( !is_admin() )
{
    echo 'Direct access not allowed.';
    exit;
}


require_once dirname( __FILE__ ) . '/includes/class-tzfd-access-list.php';

$tzfd_current_user_access = current_user_can('manage_options');

global $wpdb, $tzfd_addons_active_list, $tzfd_addons_objs_list;

$tzfd_message = "";
$tzfd_list_redirect = '';


if (isset($_GET['a']) && sanitize_text_field( wp_unslash( $_GET['a'] ?? '' ) ) == '1' && $tzfd_current_user_access)
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_list' ) ) { $this->nonce_failed(); }
    define('TZFD_BOOK_DEFAULT_fp_from_email', get_the_author_meta('user_email', get_current_user_id()) );
    define('TZFD_BOOK_DEFAULT_fp_destination_emails', TZFD_BOOK_DEFAULT_fp_from_email);

    $wpdb->insert( $wpdb->prefix."tzfd_forms", array(
                                      'form_name' => stripcslashes(sanitize_text_field(wp_unslash( $_GET["name"] ?? '' ))),

                                      'form_structure' => TZFD_BOOK_DEFAULT_form_structure,

                                      'fp_from_email' => $this->get_option('fp_from_email', TZFD_BOOK_DEFAULT_fp_from_email),
                                      'fp_destination_emails' => $this->get_option('fp_destination_emails', TZFD_BOOK_DEFAULT_fp_destination_emails),
                                      'fp_subject' => $this->get_option('fp_subject', TZFD_BOOK_DEFAULT_fp_subject),
                                      'fp_inc_additional_info' => $this->get_option('fp_inc_additional_info', TZFD_BOOK_DEFAULT_fp_inc_additional_info),
                                      'fp_return_page' => $this->get_option('fp_return_page', TZFD_BOOK_DEFAULT_fp_return_page),
                                      'fp_message' => $this->get_option('fp_message', TZFD_BOOK_DEFAULT_fp_message),
                                      'fp_emailformat' => $this->get_option('fp_emailformat', TZFD_BOOK_DEFAULT_email_format),

                                      'cu_enable_copy_to_user' => $this->get_option('cu_enable_copy_to_user', TZFD_BOOK_DEFAULT_cu_enable_copy_to_user),
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

    $tzfd_list_redirect = '?page=tzfd&cal='.intval($wpdb->insert_id);
}
else if (isset($_GET['u']) && sanitize_text_field( wp_unslash( $_GET['u'] ?? '' ) ) != '' && $tzfd_current_user_access)
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_list' ) ) { $this->nonce_failed(); }
    $wpdb->query( $wpdb->prepare('UPDATE %i SET form_name=%s WHERE id=%d', $wpdb->prefix.'tzfd_forms', sanitize_text_field(wp_unslash( $_GET["name"] ?? '' )), sanitize_text_field(wp_unslash( $_GET['u'] ?? '' ))) ); 
    $tzfd_message = __( 'Item updated', 'titan-zero-trades-front-desk' );
}
else if (isset($_GET['d']) && sanitize_text_field( wp_unslash( $_GET['d'] ?? '' ) ) != '' && $tzfd_current_user_access)
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_list' ) ) { $this->nonce_failed(); }
    $wpdb->query( $wpdb->prepare('DELETE FROM %i WHERE id=%d', $wpdb->prefix.'tzfd_forms', sanitize_text_field(wp_unslash( $_GET['d'] ?? '' ))) ); 
    $tzfd_message = __( 'Item deleted', 'titan-zero-trades-front-desk' );
} else if (isset($_GET['c']) && sanitize_text_field( wp_unslash( $_GET['c'] ?? '' ) ) != '' && $tzfd_current_user_access)
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_list' ) ) { $this->nonce_failed(); }
    $tzfd_myrows = $wpdb->get_row( $wpdb->prepare("SELECT * FROM %i WHERE id=%d", $wpdb->prefix.'tzfd_forms', sanitize_text_field(wp_unslash( $_GET['c'] ?? '' )) ) , ARRAY_A); 
    unset($tzfd_myrows["id"]);
    $tzfd_myrows["form_name"] = 'Cloned: '.$tzfd_myrows["form_name"];
    $wpdb->insert( $wpdb->prefix.'tzfd_forms', $tzfd_myrows);
    $tzfd_message = __( 'Item duplicated/cloned', 'titan-zero-trades-front-desk' );
}

if (isset($_GET["confirm"]))
    $tzfd_message = __( 'Settings updated', 'titan-zero-trades-front-desk' );

if ($tzfd_message) echo "<div id='setting-error-settings_updated' class='updated'><h2>".esc_html($tzfd_message)."</h2></div>";

$tzfd_nonce = wp_create_nonce( 'tzfd_actions_list' );

// Page assets (review R2). Enqueued here because this template renders after admin_enqueue_scripts.
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
		<input type="text" name="tzfd_itemname" id="tzfd_itemname" placeholder=" - Calendar Name - " class="tzfd-new-calendar" />
		<input type="button" class="button-primary" value="Add New" data-tzfd-action="tzfd_addItem" />
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
  $tzfd_myrows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i", $wpdb->prefix . 'tzfd_forms' ) ); 
  foreach ($tzfd_myrows as $tzfd_item)
  {
   $tzfd_options = TZFD_Access_List::decode($tzfd_item->tzfd_user_access);

   if ($tzfd_current_user_access || in_array($current_user->ID, $tzfd_options, true))
   {
?>
   <tr>
    <td nowrap class="firstcol"><?php echo intval($tzfd_item->id); ?></td>
    <td nowrap><input type="text" name="calname_<?php echo intval($tzfd_item->id); ?>" id="calname_<?php echo intval($tzfd_item->id); ?>" value="<?php echo esc_attr($tzfd_item->form_name); ?>" /></td>

    <td>
<?php if ($tzfd_current_user_access) { ?>
                             <input style="margin-bottom:5px;" class="button" type="button" name="calupdate_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Rename','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tzfd_updateItem" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
<?php } ?>
<?php if ($tzfd_current_user_access || (isset($tzfd_item->tzfd_user_access_settings) && $tzfd_item->tzfd_user_access_settings == 'true')) { ?>
                             <input style="margin-bottom:5px;" class="button-primary button" type="button" name="calmanage_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Edit','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tzfd_manageSettings" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
<?php } ?>
<?php if ($tzfd_current_user_access) { ?>
                             <input style="margin-bottom:5px;" class="button-primary button" type="button" name="calpublish_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Publish','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tzfd_publish" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
<?php } ?>
                             <input style="margin-bottom:5px;" class="button-primary2 button" type="button" name="calmessages_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Booking Orders','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tzfd_viewMessages" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
                             <input style="margin-bottom:5px;" class="button-primary2 button" type="button" name="calschedule_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Schedule','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tzfd_viewSchedule" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
                             <input style="margin-bottom:5px;" class="button-primary3 button" type="button" name="caladdbk_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Add Booking','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tzfd_addbk" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
                             <input style="margin-bottom:5px;" class="button-primary3 button" type="button" name="calblocktimes_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Block Times','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tzfd_blocktimes" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
                             <input style="margin-bottom:5px;" class="button" type="button" name="calreport_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Stats','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tzfd_viewReport" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
<?php if ($tzfd_current_user_access) { ?>
                             <input style="margin-bottom:5px;" class="button" type="button" name="calclone_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Clone','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tzfd_cloneItem" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
                             <input style="margin-bottom:5px;" class="button" type="button" name="caldelete_<?php echo intval($tzfd_item->id); ?>" value="<?php esc_html_e('Delete','titan-zero-trades-front-desk'); ?>" data-tzfd-action="tzfd_deleteItem" data-tzfd-id="<?php echo intval($tzfd_item->id); ?>" />
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


[<a href="<?php echo esc_url('mailto:support@titanzero.io'); ?>"><?php esc_html_e('Free Support','titan-zero-trades-front-desk'); ?></a>] | [<a href="<?php echo esc_url('https://titanzero.io/support/'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Help','titan-zero-trades-front-desk'); ?></a>]
</form>
</div>