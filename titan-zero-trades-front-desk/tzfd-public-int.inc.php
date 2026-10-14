<?php
  if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
  if ( !defined('TZFD_AUTH_INCLUDE') ) { echo 'Direct access not allowed.'; exit; }
/**  
  if (!is_admin()) {
      $full_url = get_permalink();
  
      // Parse the URL to get the path
      $path = parse_url($full_url, PHP_URL_PATH);
      if ($path == "") $path = $full_url;
      
      // Add a random GET parameter to the path to prevent cache
      $cache_buster = rand(100000, 999999);
      $path .= (strpos($path, '?') === false ? '?' : '&') . 'tzfdnocache=' . $cache_buster;
  }
  else $path = "";  
*/  
  $path = ""; 
  // Form behaviour lives in js/inline/adminb-public-form.js; footer script, so it runs after the form is in the page.
  wp_enqueue_script( 'tzfd-adminb-public-form', plugins_url( 'js/inline/adminb-public-form.js', __FILE__ ), array( 'jquery' ), '2.0.1', true );
  
?>
<form class="tzfdp_form no-prefetch" name="<?php echo esc_attr($this->prefix); ?>_pform<?php echo '_'.esc_attr($this->print_counter); ?>" id="<?php echo esc_attr($this->prefix); ?>_pform<?php echo '_'.esc_attr($this->print_counter); ?>" action="<?php echo esc_attr($path); ?>" method="post" enctype="multipart/form-data" data-tzfd-validate="<?php echo esc_attr($this->prefix); ?>_pform_doValidate<?php echo '_'.esc_attr($this->print_counter); ?>"<?php if ( defined( 'APBCT_NAME' ) ) { echo ' data-tzfd-apbct="1"'; } ?>><input type="hidden" name="tzfd_pform_psequence" value="<?php echo '_'.esc_attr($this->print_counter); ?>" /><input type="hidden" name="<?php echo esc_attr($this->prefix); ?>_pform_process" value="1" /><input type="hidden" name="<?php echo esc_attr($this->prefix); ?>_id" value="<?php echo esc_attr($this->item); ?>" /><input type="hidden" name="tzfd_ref_page" value="<?php esc_attr($this->get_site_url()); ?>" /><input type="hidden" name="form_structure<?php echo '_'.esc_attr($this->print_counter); ?>" id="form_structure<?php echo '_'.esc_attr($this->print_counter); ?>" size="180" value="<?php echo wp_kses( $tzfd_raw_form_str, array() ); // The caller has already attribute-escaped this JSON; wp_kses with no tags leaves escaped text unchanged. 
?>" /><input type="hidden" name="refpage<?php echo '_'.esc_attr($this->print_counter); ?>" id="refpage<?php echo '_'.esc_attr($this->print_counter); ?>" value=""><input type="hidden" name="<?php echo esc_attr($this->prefix); ?>_pform_status" value="0" /><input name="anonce" type="hidden" value="<?php echo esc_attr(wp_create_nonce( 'tzfd_actions_bookingform' )); ?>"/>
<?php /* phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state. */ ?>
<?php if (is_admin() && !defined('TZFD_HOUR_ELEMENTOR_EDIT_MODE') && (empty($_GET["action"]) || sanitize_text_field( wp_unslash( $_GET["action"] ?? '' ) ) != 'edit') && !defined('TZFD_HOUR_BLOCK_TIMES')) {?>
  <fieldset class="tzfdadminoptions" style="border: 1px solid black; -webkit-border-radius: 8px; -moz-border-radius: 8px; border-radius: 8px; padding:15px;">
   <legend><?php esc_html_e('Administrator options','titan-zero-trades-front-desk'); ?></legend>
    
    <div style="float:left;margin-right:50px;">
     <input type="checkbox" name="sendemails_admin" value="1" vt="1" checked="checked" /> <?php esc_html_e('Send notification emails for this booking','titan-zero-trades-front-desk'); ?><br /><br />
     <div id="wptsremoveval"> <input type="checkbox" name="ignorewptsval" value="1" vt="1" data-tzfd-action="ignore-validation" /><?php esc_html_e('Ignore validation of required fields?','titan-zero-trades-front-desk'); ?></div>
     <div id="wptsremovedval" style="display:none"> <input type="checkbox" name="ignorewptsval" value="1" vt="1" checked disabled /> <?php echo esc_html__('Required field validation ignored!','titan-zero-trades-front-desk'); ?></div> 
    </div>
    <div style="float:left;">
       <?php 
         $status = apply_filters('tzfd_app_admin_default_status', $this->get_option('defaultpaidstatus', '') );        
         if (isset($preload_params) && is_array($preload_params) && count($preload_params) && isset($preload_params["apps"])) 
         {
            for($tzfd_k=0; $tzfd_k<count($preload_params["apps"]); $tzfd_k++)
                if (isset($preload_params["apps"][$tzfd_k]["cancelled"]))
                {
                    $status = $preload_params["apps"][$tzfd_k]["cancelled"];
                    break;
                }
         }
         esc_html_e('Status','titan-zero-trades-front-desk'); ?>:<br /> <?php $this->render_status_box('statusbox', $status); 
         
       ?>
    </div>
  </fieldset>
<?php } ?>
<div id="tzfdform">
    <div id="tzfdform<?php echo '_'.esc_attr($this->print_counter); ?>">
        <div id="formheader<?php echo '_'.esc_attr($this->print_counter); ?>"></div>
        <div id="fieldlist<?php echo '_'.esc_attr($this->print_counter); ?>"></div>
    </div>
</div>
<div style="display:none">
<div id="tzfdcaptchalayer<?php echo '_'.esc_attr($this->print_counter); ?>" class="tzfdcaptchalayer">
 <div class="fields" id="field-c0" style="display:none">
    <label></label>
    <div class="dfield"><!--addons-payment-options--></div>
    <div class="clearer"></div>
 </div>
<?php if (!is_admin() && $this->get_option('cv_enable_captcha', TZFD_BOOK_DEFAULT_cv_enable_captcha) != 'false' && function_exists('imagecreatetruecolor')) { ?>
  <?php esc_html_e("Security Code",'titan-zero-trades-front-desk'); ?>:<br />
  <img src="<?php echo esc_url($this->fixurl($this->get_site_url(), $this->prefix.'_captcha=captcha&ps=_'.$this->print_counter.'&inAdmin=1&width='.$this->get_option('cv_width', TZFD_BOOK_DEFAULT_cv_width).'&height='.$this->get_option('cv_height', TZFD_BOOK_DEFAULT_cv_height).'&letter_count='.$this->get_option('cv_chars', TZFD_BOOK_DEFAULT_cv_chars).'&min_size='.$this->get_option('cv_min_font_size', TZFD_BOOK_DEFAULT_cv_min_font_size).'&max_size='.$this->get_option('cv_max_font_size', TZFD_BOOK_DEFAULT_cv_max_font_size).'&noise='.$this->get_option('cv_noise', TZFD_BOOK_DEFAULT_cv_noise).'&noiselength='.$this->get_option('cv_noise_length', TZFD_BOOK_DEFAULT_cv_noise_length).'&bcolor='.$this->get_option('cv_background', TZFD_BOOK_DEFAULT_cv_background).'&border='.$this->get_option('cv_border', TZFD_BOOK_DEFAULT_cv_border).'&font='.$this->get_option('cv_font', TZFD_BOOK_DEFAULT_cv_font))); ?>"  id="captchaimg<?php echo '_'.esc_attr($this->print_counter); ?>" alt="security code" border="0" class="skip-lazy" />
  <br /><label for="hdcaptcha_<?php echo esc_attr($this->prefix); ?>_post<?php echo '_'.esc_attr($this->print_counter); ?>"><?php esc_html_e("Please enter the security code",'titan-zero-trades-front-desk'); ?>:</label><br />
  <div class="dfield"><input type="text" size="20" name="hdcaptcha_<?php echo esc_attr($this->prefix); ?>_post" id="hdcaptcha_<?php echo esc_attr($this->prefix); ?>_post<?php echo '_'.esc_attr($this->print_counter); ?>" value="" autocomplete="off" data-booking-captcha-error="hdcaptcha_error<?php echo '_'.esc_attr($this->print_counter); ?>" />
  <div class="tzfdefb_error message" id="hdcaptcha_error<?php echo '_'.esc_attr($this->print_counter); ?>" generated="true" role="status" aria-live="polite" aria-atomic="true" style="display:none;"><?php echo esc_html(titan_zero_front_desk_translate_legacy_value($this->get_option('cv_text_enter_valid_captcha', TZFD_BOOK_DEFAULT_cv_text_enter_valid_captcha))); ?></div>
  </div><br />
<?php } ?><!-- rcadon -->
</div>
</div>
<div id="tzfd_subbtn<?php echo '_'.esc_attr($this->print_counter); ?>" class="tzfd_subbtn"><?php if (defined('TZFD_HOUR_BLOCK_TIMES')) esc_html_e('Block selected dates','titan-zero-trades-front-desk'); else echo esc_html( titan_zero_front_desk_translate_legacy_value( $button_label ) ); ?></div>
<div style="clear:both;"></div>
<?php if (get_option('tzfd_honeypot','') != '') echo '<input type="hidden" name="'.esc_attr(get_option('tzfd_honeypot','')).'" value="">'; ?>
</form>
 
