<?php

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

$tzfd_message = '';

// Notice only. Shown only after a booking whose anonce is valid (the tzfd_actions_bookingform check the booking handler makes).
$tzfd_booking_added = ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST[$this->prefix.'_pform_process'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['anonce'] ?? '' ) ), 'tzfd_actions_bookingform' ) );
if ( $tzfd_booking_added )
    echo '<div id="setting-error-settings_updated" class="updated settings-error"><p><strong>Booking added. It appears now in the <a href="?page='.esc_attr($this->menu_parameter).'&cal='.intval($this->item).'&list=1">bookings list</a>.</strong></p></div>';

$tzfd_nonce = wp_create_nonce( 'tzfd_actions_admin' );

// Page assets (review R2). Enqueued here because this template renders after admin_enqueue_scripts.
wp_register_style( 'tzfd-add-booking-inline', false, array(), '2.0.1' );
wp_enqueue_style( 'tzfd-add-booking-inline' );
wp_add_inline_style( 'tzfd-add-booking-inline', ".clear{clear:both;}\n.tzfd-first-button{margin-right:10px !important;}\n.tzfd-buttons-container{margin:1em 1em 1em 0;}\n.tzfd-return-link{float:right;}" );

// Flag read by the public form script (tzfdhour_in_admin). Printed in the footer before the form scripts.
wp_register_script( 'tzfd-add-booking-inline', false, array(), '2.0.1', true );
wp_enqueue_script( 'tzfd-add-booking-inline' );
wp_add_inline_script( 'tzfd-add-booking-inline', 'var tzfdhour_in_admin=true;', 'before' );

?>
<div class="wrap">

<h1><?php esc_html_e('Add Booking','titan-zero-trades-front-desk'); ?></h1>

<div class="tzfd-buttons-container">
	<a href="<?php print esc_attr(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
    <div class="clear"></div>
</div>

<p><?php esc_html_e('This page is for adding bookings from the administration area. The captcha and payment process are disabled in order to allow the website manager easily adding bookings.','titan-zero-trades-front-desk'); ?></p>


<?php $this->output_filter_content(array('id' => $this->item)); ?>

</div>


