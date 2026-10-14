<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( !is_admin() )
{
    echo 'Direct access not allowed.';
    exit;
}

global $wpdb, $tzfd_addons_active_list, $tzfd_addons_objs_list;

$tzfd_message = "";

if( isset( $_GET[ 'b' ] ) && sanitize_text_field( wp_unslash( $_GET[ 'b' ] ?? '' ) ) == 1 && current_user_can( 'manage_options' ) )
{
    if ( ! wp_verify_nonce( sanitize_text_field(wp_unslash( $_GET["anonce"] ?? '' )), 'tzfd_actions_list' ) ) { $this->nonce_failed(); }
	// Save the option for active addons
	delete_option( 'tzfd_addons_active_list' );
	// Each add-on ID is unslashed and sanitised as a text field before it is saved; anything that is not a list is ignored.
	$tzfd_selected = ( isset( $_GET['tzfd_addons_active_list'] ) && is_array( $_GET['tzfd_addons_active_list'] ) ) ? array_map( 'sanitize_text_field', wp_unslash( $_GET['tzfd_addons_active_list'] ) ) : array();
	if( !empty( $tzfd_selected ) )
	{
		update_option( 'tzfd_addons_active_list', $tzfd_selected );
	}

	// Get the list of active addons
	$tzfd_addons_active_list = get_option( 'tzfd_addons_active_list', array() );
	// Menus and hooks are set up when a page loads, so reload once to pick up the saved list.
	$tzfd_saved_redirect = admin_url( 'admin.php?page=' . $this->menu_parameter . '_addons&saved=1' );
	$tzfd_message = "Add-on settings updated";
}
elseif( isset( $_GET['saved'] ) && sanitize_text_field( wp_unslash( $_GET['saved'] ) ) == 1 )
{
	$tzfd_message = "Add-on settings updated";
}

$tzfd_nonce = wp_create_nonce( 'tzfd_actions_list' );

// Add-on page script: the save button, and the one reload after a save.
wp_enqueue_script( 'tzfd-adminb-addons', plugins_url( 'js/inline/adminb-addons.js', __FILE__ ), array(), '2.0.1', true );
wp_localize_script( 'tzfd-adminb-addons', 'tzfdAdminBAddons', array(
	'page'     => $this->menu_parameter,
	'nonce'    => $tzfd_nonce,
	'redirect' => isset( $tzfd_saved_redirect ) ? $tzfd_saved_redirect : '',
) );

// Sort every add-on into one of the page's sections.
// Free build: categories without a leading space are Pro-only and are shown greyed out.
$tzfd_is_pro_build = false;
$tzfd_included = array();  // switches listed under "Included features"
$tzfd_cards    = array();  // optional add-ons shown as cards
$tzfd_pro      = array();  // Pro-only, not switchable in this build
foreach ( (array) $tzfd_addons_objs_list as $tzfd_id => $tzfd_obj )
{
	$tzfd_cat = (string) $tzfd_obj->category;
	if ( ! $tzfd_is_pro_build && ( '' === $tzfd_cat || ' ' !== $tzfd_cat[0] ) )
		$tzfd_pro[ $tzfd_id ] = $tzfd_obj;
	elseif ( 'Add-ons included in this plugin version' === trim( $tzfd_cat ) )
		$tzfd_included[ $tzfd_id ] = $tzfd_obj;
	else
		$tzfd_cards[ $tzfd_id ] = $tzfd_obj;
}
$tzfd_by_name = function ( $a, $b ) { return strcasecmp( $a->get_addon_name(), $b->get_addon_name() ); };
uasort( $tzfd_included, $tzfd_by_name );
uasort( $tzfd_cards, $tzfd_by_name );
uasort( $tzfd_pro, $tzfd_by_name );

$tzfd_available = 0;
foreach ( $tzfd_cards as $tzfd_id => $tzfd_obj )
	if ( ! $tzfd_obj->addon_is_active() ) $tzfd_available++;

$tzfd_wizard_active = isset( $tzfd_addons_objs_list['tzfdad-Onboarding'] ) && $tzfd_addons_objs_list['tzfdad-Onboarding']->addon_is_active();

?>




<div class="tzfdad-ad-page" id="top">

	<div class="tzfdad-ad-head">
		<div>
			<h1><?php esc_html_e( 'Add-ons', 'titan-zero-trades-front-desk' ); ?></h1>
			<p class="tzfdad-ad-sub"><?php esc_html_e( 'Choose optional tools and review included features in one place.', 'titan-zero-trades-front-desk' ); ?></p>
		</div>
		<div class="tzfdad-ad-actions">
			<a class="tzfdad-ad-back" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_parameter ) ); ?>">&larr; <?php esc_html_e( 'Back to calendars', 'titan-zero-trades-front-desk' ); ?></a>
			<button type="button" class="button button-primary tzfdad-btn-primary" data-tzfd-action="save-addons"><?php esc_html_e( 'Save add-on changes', 'titan-zero-trades-front-desk' ); ?></button>
		</div>
	</div>

	<?php if ( $tzfd_message ) : ?>
	<div id="setting-error-settings_updated" class="updated notice" style="margin:0 0 16px 0;"><p><?php echo esc_html( $tzfd_message ); ?></p></div>
	<?php endif; ?>

	<!-- Optional add-ons -->
	<section class="tzfdad-ad-card" aria-labelledby="tzfdad-ad-optional">
		<div class="tzfdad-ad-sechead">
			<div>
				<h2 id="tzfdad-ad-optional"><?php esc_html_e( 'Make Front Desk fit your workflow', 'titan-zero-trades-front-desk' ); ?></h2>
				<p><?php esc_html_e( 'Each add-on expands what it does and what it needs before activation.', 'titan-zero-trades-front-desk' ); ?></p>
			</div>
			<span class="tzfdad-pill"><?php echo esc_html( $tzfd_available . ' ' . __( 'available', 'titan-zero-trades-front-desk' ) ); ?></span>
		</div>

		<?php if ( empty( $tzfd_cards ) ) : ?>
		<p class="tzfdad-ad-subnote"><?php esc_html_e( 'No optional add-ons are included in this version.', 'titan-zero-trades-front-desk' ); ?></p>
		<?php else : ?>
		<div class="tzfdad-ad-grid">
		<?php foreach ( $tzfd_cards as $tzfd_id => $tzfd_obj ) :
			$tzfd_on      = (bool) $tzfd_obj->addon_is_active();
			$tzfd_badges  = isset( $tzfd_obj->badges ) && is_array( $tzfd_obj->badges ) ? $tzfd_obj->badges : array();
			$tzfd_is_wiz  = ( 'tzfdad-Onboarding' === $tzfd_id );
			$tzfd_help    = (string) $tzfd_obj->help;
			$tzfd_anchor  = '#tzfdad-addon-' . sanitize_html_class( $tzfd_id );
		?>
			<div class="tzfdad-ad-tile<?php echo $tzfd_on ? '' : ' tzfdad-is-off'; ?>">
				<div class="tzfdad-ad-tile-top">
					<h3><?php echo esc_html( $tzfd_obj->get_addon_name() ); ?></h3>
					<label class="tzfdad-switch" title="<?php echo esc_attr__( 'Turn on or off', 'titan-zero-trades-front-desk' ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $tzfd_id ); ?>" name="tzfd_addons" value="<?php echo esc_attr( $tzfd_id ); ?>" <?php checked( $tzfd_on ); ?>>
						<span class="tzfdad-slider"></span>
					</label>
				</div>
				<div class="tzfdad-ad-badges">
					<?php foreach ( $tzfd_badges as $tzfd_badge ) : ?>
					<span class="tzfdad-ad-badge"><?php echo esc_html( $tzfd_badge ); ?></span>
					<?php endforeach; ?>
					<span class="tzfdad-pill <?php echo $tzfd_on ? 'tzfdad-pill-on' : 'tzfdad-pill-off'; ?>"><?php echo $tzfd_on ? esc_html__( 'On', 'titan-zero-trades-front-desk' ) : esc_html__( 'Off', 'titan-zero-trades-front-desk' ); ?></span>
				</div>
				<p><?php echo esc_html( $tzfd_obj->get_addon_description() ); ?></p>
				<div class="tzfdad-ad-tile-foot">
					<?php if ( $tzfd_is_wiz && $tzfd_on ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=tzfd-ai-setup' ) ); ?>"><?php esc_html_e( 'Open setup wizard', 'titan-zero-trades-front-desk' ); ?></a>
					<?php elseif ( $tzfd_on ) : ?>
						<a class="button" href="<?php echo esc_url( $tzfd_anchor ); ?>"><?php esc_html_e( 'Configure', 'titan-zero-trades-front-desk' ); ?></a>
					<?php elseif ( '' !== $tzfd_help ) : ?>
						<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $tzfd_help ); ?>"><?php esc_html_e( 'View details', 'titan-zero-trades-front-desk' ); ?></a>
					<?php else : ?>
						<a class="button" href="<?php echo esc_url( $tzfd_anchor ); ?>"><?php esc_html_e( 'View details', 'titan-zero-trades-front-desk' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</section>

	<!-- Included features -->
	<section class="tzfdad-ad-card" aria-labelledby="tzfdad-ad-included">
		<div class="tzfdad-ad-sechead">
			<div>
				<h2 id="tzfdad-ad-included"><?php esc_html_e( 'Included features', 'titan-zero-trades-front-desk' ); ?></h2>
				<p><?php esc_html_e( 'Turn optional behaviour on or off. Read what each switch changes before you enable it.', 'titan-zero-trades-front-desk' ); ?></p>
			</div>
		</div>
		<?php foreach ( $tzfd_included as $tzfd_id => $tzfd_obj ) :
			$tzfd_on = (bool) $tzfd_obj->addon_is_active();
		?>
		<div class="tzfdad-ad-row">
			<div>
				<h3><?php echo esc_html( $tzfd_obj->get_addon_name() ); ?></h3>
				<p><?php echo esc_html( $tzfd_obj->get_addon_description() ); ?></p>
			</div>
			<div class="tzfdad-ad-row-side">
				<span class="tzfdad-pill <?php echo $tzfd_on ? 'tzfdad-pill-on' : 'tzfdad-pill-off'; ?>"><?php echo $tzfd_on ? esc_html__( 'On', 'titan-zero-trades-front-desk' ) : esc_html__( 'Off', 'titan-zero-trades-front-desk' ); ?></span>
				<label class="tzfdad-switch" title="<?php echo esc_attr__( 'Turn on or off', 'titan-zero-trades-front-desk' ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $tzfd_id ); ?>" name="tzfd_addons" value="<?php echo esc_attr( $tzfd_id ); ?>" <?php checked( $tzfd_on ); ?>>
					<span class="tzfdad-slider"></span>
				</label>
			</div>
		</div>
		<?php endforeach; ?>
	</section>

	<?php if ( ! empty( $tzfd_pro ) ) : ?>
	<!-- Pro-only add-ons: listed, not switchable in this version -->
	<section class="tzfdad-ad-card" aria-labelledby="tzfdad-ad-pro">
		<div class="tzfdad-ad-sechead">
			<div>
				<h2 id="tzfdad-ad-pro"><?php esc_html_e( 'Titan Zero Pro add-ons', 'titan-zero-trades-front-desk' ); ?></h2>
				<p><?php esc_html_e( 'These add-ons are not part of this version. Their help pages explain what they do.', 'titan-zero-trades-front-desk' ); ?></p>
			</div>
			<span class="tzfdad-pill tzfdad-pill-muted"><?php esc_html_e( 'Pro', 'titan-zero-trades-front-desk' ); ?></span>
		</div>
		<div class="tzfdad-ad-grid">
		<?php foreach ( $tzfd_pro as $tzfd_id => $tzfd_obj ) : ?>
			<div class="tzfdad-ad-tile tzfdad-is-pro">
				<div class="tzfdad-ad-tile-top">
					<h3><?php echo esc_html( $tzfd_obj->get_addon_name() ); ?></h3>
					<label class="tzfdad-switch" title="<?php echo esc_attr__( 'Available in Titan Zero Pro', 'titan-zero-trades-front-desk' ); ?>">
						<input type="checkbox" disabled>
						<span class="tzfdad-slider"></span>
					</label>
				</div>
				<p><?php echo esc_html( $tzfd_obj->get_addon_description() ); ?></p>
				<?php if ( '' !== (string) $tzfd_obj->help ) : ?>
				<div class="tzfdad-ad-tile-foot">
					<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $tzfd_obj->help ); ?>"><?php esc_html_e( 'View details', 'titan-zero-trades-front-desk' ); ?></a>
				</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<!-- Add-on settings -->
	<section class="tzfdad-ad-card" aria-labelledby="tzfdad-ad-settings">
		<h2 id="tzfdad-ad-settings" style="margin:0 0 6px;font-size:18px;"><?php esc_html_e( 'Add-on settings', 'titan-zero-trades-front-desk' ); ?></h2>
		<p class="tzfdad-ad-subnote"><?php esc_html_e( 'Open an add-on to change its settings. Turn an add-on on first to see its settings.', 'titan-zero-trades-front-desk' ); ?></p>
		<?php foreach ( (array) $tzfd_addons_objs_list as $tzfd_id => $tzfd_settings_obj ) : ?>
		<details class="tzfdad-addon-details" id="tzfdad-addon-<?php echo esc_attr( sanitize_html_class( $tzfd_id ) ); ?>">
			<summary><?php echo esc_html( $tzfd_settings_obj->get_addon_name() ); ?> <span style="color:#a4acba;font-weight:normal;">&mdash; <?php echo $tzfd_settings_obj->addon_is_active() ? esc_html__( 'on', 'titan-zero-trades-front-desk' ) : esc_html__( 'off', 'titan-zero-trades-front-desk' ); ?></span></summary>
			<div style="margin-top:10px;">
			<?php if ( $tzfd_settings_obj->addon_is_active() ) {
				$tzfd_settings_html = $tzfd_settings_obj->get_addon_settings();
				if ( is_string( $tzfd_settings_html ) ) { echo wp_kses( $tzfd_settings_html, $this->admin_markup_allowed() ); }
			} else { ?>
				<p><?php esc_html_e( 'Turn this add-on on to change its settings.', 'titan-zero-trades-front-desk' ); ?></p>
			<?php } ?>
			</div>
		</details>
		<?php endforeach; ?>
	</section>

	<p class="tzfdad-ad-foot"><?php esc_html_e( 'Add-on availability depends on your plan and connected services.', 'titan-zero-trades-front-desk' ); ?></p>

	<div class="tzfdad-ad-actions">
		<button type="button" class="button button-primary tzfdad-btn-primary" data-tzfd-action="save-addons"><?php esc_html_e( 'Save add-on changes', 'titan-zero-trades-front-desk' ); ?></button>
		<a href="#top"><?php esc_html_e( 'Back to top', 'titan-zero-trades-front-desk' ); ?></a>
	</div>

</div>
