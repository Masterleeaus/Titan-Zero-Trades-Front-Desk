<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

  if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

  if ( !is_admin() || !current_user_can('manage_options') ) {echo 'Direct access not allowed.';exit;}

  $tzfd_nonce = wp_create_nonce( 'tzfd_actions_pwizard' );
  wp_enqueue_script( 'tzfd-adminb-publish-wizard', plugins_url( 'js/inline/adminb-publish-wizard.js', __FILE__ ), array(), '2.0.1', true );

?>
<div class="tzfd-pwizard">

<h1><?php esc_html_e('Publish Titan Zero Trades: Front Desk Form','titan-zero-trades-front-desk'); ?></h1>



<div class="tzfd-buttons-container">
	<a href="<?php echo esc_url(admin_url('admin.php?page='.$this->menu_parameter));?>" class="tzfd-return-link">&larr;<?php esc_html_e('Return to the calendars list','titan-zero-trades-front-desk'); ?></a>
	<div class="clear"></div>
</div>

<form method="post" action="?page=tzfd&pwizard=1" name="regForm" id="regForm">
 <input name="tzfd_do_action_loaded" type="hidden" value="wizard" />
 <input name="anonce" type="hidden" value="<?php echo esc_attr($tzfd_nonce); ?>" />


<?php

if ($this->get_param('tzfd_do_action_loaded') == 'wizard') {
?>
<div class="tzfd-section-container">
	<div class="tzfd-section tzfd-section-active" data-step="1">
        <h1><?php esc_html_e('Great! Form successfully published','titan-zero-trades-front-desk'); ?></h1>
        <p class="tzfdmvcontainer"><?php esc_html_e('The booking form was placed into the page','titan-zero-trades-front-desk'); ?> <a href="<?php echo esc_url($this->postURL); ?>"><?php echo esc_html($this->postURL); ?></a>.</p>
        <p class="tzfdmvcontainer"><?php esc_html_e('Now you can:','titan-zero-trades-front-desk'); ?></p>
        <div style="clear:both"></div>
        <button class="button button-primary tzfdmvcontainer" type="button" id="nextBtn" data-tzfd-open="<?php echo esc_url($this->postURL); ?>"><?php esc_html_e('View the Published Booking Form','titan-zero-trades-front-desk'); ?></button>
        <div style="clear:both"></div>
        <p class="tzfdmvcontainer">* <?php esc_html_e('Note: If the calendar was published in a new page or post it will be a \'draft\', you have to publish the page/post in the future if needed.','titan-zero-trades-front-desk'); ?></p>
        <div style="clear:both"></div>
        <button class="button button-primary tzfdmvcontainer" type="button" id="nextBtn" data-tzfd-open="<?php echo esc_attr('?page=tzfd&cal=' . intval($this->get_param("tzfdhour_id"))); ?>"><?php esc_html_e('Edit the booking form settings and calendar availability','titan-zero-trades-front-desk'); ?></button>
        <div style="clear:both"></div>
    </div>
</div>
<div style="clear:both"></div>
<?php
} else {
?>

<div class="tzfd-section-container">
	<div class="tzfd-section tzfd-section-active" data-step="1">
		<table class="form-table">
            <tbody>
				<tr valign="top">
					<th><label><?php esc_html_e('Select calendar','titan-zero-trades-front-desk'); ?></label></th>
					<td>
                    <select id="tzfdhour_id" name="tzfdhour_id">
<?php
  $tzfd_myrows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $wpdb->prefix . 'tzfd_forms' ) );
  foreach ($tzfd_myrows as $tzfd_item)
      echo '<option value="'.esc_attr($tzfd_item->id).'"'.($tzfd_item->id==$this->item?' selected':'').'>'.esc_html($tzfd_item->form_name).'</option>';
?>
            </select>
                    </td>
                </tr>
                <tr valign="top">
                    <th><label><?php esc_html_e('Where to publish it?','titan-zero-trades-front-desk'); ?></label></th>
					<td>
                        <select name="whereto">
                          <option value="0"><?php esc_html_e('Into a new page','titan-zero-trades-front-desk'); ?></option>
                          <option value="1"><?php esc_html_e('Into a new post','titan-zero-trades-front-desk'); ?></option>
                          <option value="2"><?php esc_html_e('Into an existent page','titan-zero-trades-front-desk'); ?></option>
                          <option value="3"><?php esc_html_e('Into an existent post','titan-zero-trades-front-desk'); ?></option>
                        </select>
                    </td>
                </tr>
                <tr valign="top" id="posttitle">
                    <th><label><?php esc_html_e('Page/Post Title','titan-zero-trades-front-desk'); ?></label></th>
					<td>
                        <input type="text" name="posttitle" value="Booking Form" />
                    </td>
                </tr>
                <tr valign="top"  id="ppage" style="display:none">
                    <th valign="top"></th>
					<td valign="top">

                       <h3 style="background:#cccccc; padding:5px;"><?php esc_html_e('Classic way? Just copy and paste the following shortcode into the page/post:','titan-zero-trades-front-desk'); ?></h3>

                       <div style="border: 1px dotted black; background-color: #FFFACD ;padding:15px; font-weight: bold; margin:10px;">
                         [<?php echo esc_html($this->shorttag); ?> id="<?php echo intval($this->item); ?>"]
                       </div>

                       <?php if (defined('ELEMENTOR_PATH')) { ?>
                       <br />
                       <h3 style="background:#cccccc; padding:5px;"><?php esc_html_e('Using Elementor?','titan-zero-trades-front-desk'); ?></h3>

                       <img src="<?php echo esc_url(plugins_url('/controllers/help/elementor.png', __FILE__)); ?>">
                       <?php } ?>

                       <br />
                       <h3 style="background:#cccccc; padding:5px;"><?php esc_html_e('Using New WordPress Editor (Gutenberg)?','titan-zero-trades-front-desk'); ?> </h3>

                       <img src="<?php echo esc_url(plugins_url('/controllers/help/gutemberg.png', __FILE__)); ?>">

                       <br />
                       <h3 style="background:#cccccc; padding:5px;"><?php esc_html_e('Using classic WordPress editor or other editors?','titan-zero-trades-front-desk'); ?></h3>

                        <?php esc_html_e('You can also publish the form in a post/page, use the dedicated icon','titan-zero-trades-front-desk'); ?> <?php echo '<img hspace="5" src="'.esc_url(plugins_url('/images/tzfd_form.gif', __FILE__)).'" alt="'.esc_attr(__('Insert Titan Zero Trades: Front Desk','titan-zero-trades-front-desk')).'" />';     ?>
                        <?php esc_html_e('which has been added to your Upload/Insert Menu, just below the title of your Post/Page', 'titan-zero-trades-front-desk'); ?>

                    </td>
                </tr>
            <tbody>
       </table>
       <hr size="1" />
       <div class="tzfd-buttons-container">
			<input type="submit" id="subbtnnow" value="<?php esc_html_e('Publish Calendar','titan-zero-trades-front-desk'); ?>" class="button button-primary" style="float:right;margin-right:10px"  />
			<div class="clear"></div>
		</div>
</form>
</div>
</div>
<?php } ?>




<div id="metabox_basic_settings" class="postbox" >
  <h3 class='hndle' style="padding:5px;"><span><?php esc_html_e('Note','titan-zero-trades-front-desk'); ?></span></h3>
  <div class="inside">
   <?php esc_html_e('You can also publish the form in a post/page, use the dedicated icon','titan-zero-trades-front-desk'); ?> <?php echo '<img hspace="5" src="'.esc_url(plugins_url('/images/tzfd_form.gif', __FILE__)).'" alt="'.esc_attr(__('Insert Titan Zero Trades: Front Desk','titan-zero-trades-front-desk')).'" />';     ?>
   <?php esc_html_e('which has been added to your Upload/Insert Menu, just below the title of your Post/Page or under the "+" icon if using the Gutenberg editor.','titan-zero-trades-front-desk'); ?>
   <br /><br />
  </div>
</div>
</div>
