<?php


if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
require_once __DIR__ . '/includes/class-tzfd-request-scalar.php';

if ( !is_admin() )
{
    echo esc_html__( 'Direct access not allowed.', 'titan-zero-trades-front-desk' );
    exit;
}

if ( ! is_user_logged_in() || ! current_user_can( 'tz_fd_access' ) ) {
    wp_die( esc_html__( 'You cannot manage Front Desk add-ons.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
}

global $wpdb, $tz_addons_active_list, $tz_addons_objs_list;

$tz_message = "";

// Add-on selections are state changes: never accept them from query strings.
if ( isset( $_GET['b'] ) || isset( $_GET['tz_fd_addons_active_list'] ) ) {
    wp_die( esc_html__( 'Add-on changes must be submitted using POST.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 405 ) );
}
// Only the add-on selection form is handled here. Add-on settings forms post to this page too and check their own nonces.
if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && 'save_addons' === tzfd_request_text( 'post', 'tzfd_action' ) ) {
    require_once __DIR__ . '/includes/class-tzfd-admin-write-guard.php';
    TZFD_Admin_Write_Guard::enforce( 100, 32768 );
    if ( ! current_user_can( 'tz_fd_access' ) || ! is_user_logged_in() ) {
        wp_die( esc_html__( 'Insufficient permission to manage add-ons.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
    }
    if ( 'save_addons' !== tzfd_request_text( 'post', 'tzfd_action' ) ||
         ! wp_verify_nonce( tzfd_request_text( 'post', 'anonce' ), 'tz_fd_actions_list' ) ) {
        wp_die( esc_html__( 'Invalid add-on change request.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
    }
    $tz_raw_selected = array();
    if ( isset( $_POST['tz_fd_addons_active_list'] ) ) {
        $tz_raw_selected = is_array( $_POST['tz_fd_addons_active_list'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['tz_fd_addons_active_list'] ) )
            : false;
    }
    if ( ! is_array( $tz_raw_selected ) || count( $tz_raw_selected ) > 80 ) {
        wp_die( esc_html__( 'Invalid add-on selection.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
    }
    $tz_allowed_addons = TZFD_Addon_Loader::domain_ids();
    $tz_selected = array();
    foreach ( $tz_raw_selected as $tz_raw_id ) {
        if ( ! is_string( $tz_raw_id ) || strlen( $tz_raw_id ) > 128 ) {
            wp_die( esc_html__( 'Invalid add-on identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        $tz_id = $tz_raw_id; // Already unslashed and sanitized when read above.
        if ( ! isset( $tz_allowed_addons[ $tz_id ], $tz_addons_objs_list[ $tz_id ] ) ||
             ! is_object( $tz_addons_objs_list[ $tz_id ] ) ) {
            wp_die( esc_html__( 'Unknown add-on identifier.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 400 ) );
        }
        $tz_cat = (string) $tz_addons_objs_list[ $tz_id ]->category;
        if ( '' === $tz_cat || ' ' !== $tz_cat[0] ) {
            wp_die( esc_html__( 'This add-on cannot be enabled in this version.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 403 ) );
        }
        $tz_selected[] = $tz_id;
    }
    $tz_existing_active = TZFD_Scoped_Store::get( 'tz_fd_addons_active_list', array() );
    $tz_selected = TZFD_Addon_Loader::preserve_compatibility_selections( $tz_existing_active, array_values( array_unique( $tz_selected ) ) );
    if ( false === TZFD_Scoped_Store::update( 'tz_fd_addons_active_list', $tz_selected ) ) {
        // A no-op update is allowed only if persisted selections match the intended value.
        $tz_saved = TZFD_Scoped_Store::get( 'tz_fd_addons_active_list', array() );
        if ( $tz_saved !== $tz_selected ) {
            wp_die( esc_html__( 'Unable to save add-on settings.', 'titan-zero-trades-front-desk' ), '', array( 'response' => 500 ) );
        }
    }
    $tz_addons_active_list = TZFD_Scoped_Store::get( 'tz_fd_addons_active_list', array() );
    $tz_message = __( 'Add-on settings updated.', 'titan-zero-trades-front-desk' );
}

$tz_nonce = wp_create_nonce( 'tz_fd_actions_list' );

// Add-on page script: the save button, and the one reload after a save.
wp_enqueue_script( 'tzfd-adminb-addons', plugins_url( 'js/inline/adminb-addons.js', __FILE__ ), array(), '2.0.1', true );
wp_localize_script( 'tzfd-adminb-addons', 'tzfdAdminBAddons', array(
	'page'     => $this->menu_parameter,
	'nonce'    => $tz_nonce,
) );

// Only bookable and telephone add-ons are configurable in Front Desk.
// Restore the full bundled inventory as a read-only compatibility catalogue.
// Only audited booking/call modules are switchable; never silently enable legacy modules.
$tz_compatibility_inventory = array();
foreach ( TZFD_Addon_Loader::manifest() as $tz_file => $tz_entry ) {
    if ( 'compatibility' === $tz_entry['policy'] ) {
        $tz_compatibility_inventory[] = array( 'name' => basename( $tz_file, '.addon.php' ), 'domain' => $tz_entry['domain'] );
    }
}
$tz_front_desk_domains = TZFD_Addon_Loader::domain_ids();
// Sort every add-on into one of the page's sections.
// Free build: categories without a leading space are Pro-only and are shown greyed out.
$tz_is_pro_build = false;
$tz_included = array();  // switches listed under "Included features"
$tz_cards    = array();  // optional add-ons shown as cards
$tz_pro      = array();  // Pro-only, not switchable in this build
foreach ( (array) $tz_addons_objs_list as $tz_id => $tz_obj )
{
	if ( ! isset( $tz_front_desk_domains[ $tz_id ] ) ) { continue; }
	$tz_cat = (string) $tz_obj->category;
	if ( ! $tz_is_pro_build && ( '' === $tz_cat || ' ' !== $tz_cat[0] ) )
		$tz_pro[ $tz_id ] = $tz_obj;
	elseif ( 'Add-ons included in this plugin version' === trim( $tz_cat ) )
		$tz_included[ $tz_id ] = $tz_obj;
	else
		$tz_cards[ $tz_id ] = $tz_obj;
}
$tz_by_name = function ( $a, $b ) { return strcasecmp( $a->get_addon_name(), $b->get_addon_name() ); };
uasort( $tz_included, $tz_by_name );
uasort( $tz_cards, $tz_by_name );
uasort( $tz_pro, $tz_by_name );

$tz_available = 0;
foreach ( $tz_cards as $tz_id => $tz_obj )
	if ( ! $tz_obj->addon_is_active() ) $tz_available++;

$tz_wizard_active = isset( $tz_addons_objs_list['tzfdad-Onboarding'] ) && $tz_addons_objs_list['tzfdad-Onboarding']->addon_is_active();

?>




<form id="tzfd-addons-save-form" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . 'tz_fd_addons' ) ); ?>" hidden>
    <input type="hidden" name="tzfd_action" value="save_addons" />
    <input type="hidden" name="anonce" value="<?php echo esc_attr( $tz_nonce ); ?>" />
</form>
<div class="tzfdad-ad-page" id="top">

	<div class="tzfdad-ad-head">
		<div>
				<h1><?php echo wp_kses( TZFD_UI_Graphics::brand_html( 'addons' ), TZFD_UI_Graphics::allowed_html() ); ?><?php esc_html_e( 'Add-ons', 'titan-zero-trades-front-desk' ); ?></h1>
			<p class="tzfdad-ad-sub"><?php esc_html_e( 'Manage Front Desk booking and communication features. Your existing selections are kept.', 'titan-zero-trades-front-desk' ); ?></p>
		</div>
		<div class="tzfdad-ad-actions">
			<a class="tzfdad-ad-back" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $this->menu_parameter ) ); ?>">&larr; <?php esc_html_e( 'Back to dashboard', 'titan-zero-trades-front-desk' ); ?></a>
			<button type="button" class="button button-primary tzfdad-btn-primary" data-tzfd-action="save-addons"><?php esc_html_e( 'Save add-on changes', 'titan-zero-trades-front-desk' ); ?></button>
		</div>
	</div>

	<?php if ( $tz_message ) : ?>
	<div id="setting-error-settings_updated" class="updated notice" style="margin:0 0 16px 0;"><p><?php echo esc_html( $tz_message ); ?></p></div>
	<?php endif; ?>

    <section class="tzfdad-ad-card" aria-label="<?php echo esc_attr__( 'Bundled module inventory', 'titan-zero-trades-front-desk' ); ?>">
        <h2><?php esc_html_e( 'Bundled module inventory', 'titan-zero-trades-front-desk' ); ?></h2>
        <?php /* translators: 1: number of bundled module files, 2: number of configurable features. */ ?>
        <p><?php echo esc_html( sprintf( __( '%1$d bundled module files; %2$d configurable features.', 'titan-zero-trades-front-desk' ), count( TZFD_Addon_Loader::manifest() ), count( $tz_front_desk_domains ) ) ); ?></p>
        <?php if ( ! empty( $tz_compatibility_inventory ) ) : ?>
        <details><summary><?php esc_html_e( 'View preserved compatibility modules', 'titan-zero-trades-front-desk' ); ?></summary>
            <ul><?php foreach ( $tz_compatibility_inventory as $tz_entry ) : ?><li><?php echo esc_html( ucwords( str_replace( '-', ' ', $tz_entry['name'] ) ) ); ?> — <?php echo esc_html( $tz_entry['domain'] ); ?></li><?php endforeach; ?></ul>
        </details>
        <?php endif; ?>
    </section>
	<!-- Optional add-ons -->
	<section class="tzfdad-ad-card" aria-labelledby="tzfdad-ad-optional">
		<div class="tzfdad-ad-sechead">
			<div>
				<h2 id="tzfdad-ad-optional"><?php esc_html_e( 'Booking and telephone add-ons', 'titan-zero-trades-front-desk' ); ?></h2>
				<p><?php esc_html_e( 'Each add-on expands what it does and what it needs before activation.', 'titan-zero-trades-front-desk' ); ?></p>
			</div>
			<span class="tzfdad-pill"><?php echo esc_html( $tz_available . ' ' . __( 'available', 'titan-zero-trades-front-desk' ) ); ?></span>
		</div>

		<?php if ( empty( $tz_cards ) ) : ?>
		<p class="tzfdad-ad-subnote"><?php esc_html_e( 'No optional add-ons are included in this version.', 'titan-zero-trades-front-desk' ); ?></p>
		<?php else : ?>
		<div class="tzfdad-ad-grid">
		<?php foreach ( $tz_cards as $tz_id => $tz_obj ) :
			$tz_on      = (bool) $tz_obj->addon_is_active();
			$tz_badges  = isset( $tz_obj->badges ) && is_array( $tz_obj->badges ) ? $tz_obj->badges : array();
			$tz_is_wiz  = ( 'tzfdad-Onboarding' === $tz_id );
			$tz_help    = (string) $tz_obj->help;
			$tz_anchor  = '#tzfdad-addon-' . sanitize_html_class( $tz_id );
				// Connection state: an add-on that is on but has no AI provider or no adapter needs attention; otherwise on is connected and off is disconnected.
				$tz_attention = $tz_on && ( ( $tz_obj instanceof TZFD_AIAssistant && ! TZFD_Addon_Support::ai_configured() ) || ( method_exists( $tz_obj, 'has_adapter' ) && ! $tz_obj->has_adapter() ) );
				$tz_state_src = plugins_url( 'images/ui-graphics/06-Integrations/Connection-States/' . ( $tz_attention ? 'attention' : ( $tz_on ? 'connected' : 'disconnected' ) ) . '.svg', __FILE__ );
		?>
			<div class="tzfdad-ad-tile<?php echo $tz_on ? '' : ' tzfdad-is-off'; ?>">
				<div class="tzfdad-ad-tile-top">
					<h3><?php echo esc_html( $tz_obj->get_addon_name() ); ?></h3>
					<label class="tzfdad-switch" title="<?php echo esc_attr__( 'Turn on or off', 'titan-zero-trades-front-desk' ); ?>">
						<input type="checkbox" id="<?php echo esc_attr( $tz_id ); ?>" name="tz_fd_addons" value="<?php echo esc_attr( $tz_id ); ?>" <?php checked( $tz_on ); ?>>
						<span class="tzfdad-slider"></span>
					</label>
				</div>
				<div class="tzfdad-ad-badges">
						<img class="tzfdad-state-icon" src="<?php echo esc_url( $tz_state_src ); ?>" alt="" width="18" height="18" loading="lazy" decoding="async" />
						<?php if ( $tz_attention ) : ?><span class="tzfdad-pill tzfdad-pill-off"><?php esc_html_e( 'Needs attention', 'titan-zero-trades-front-desk' ); ?></span><?php endif; ?>
					<?php foreach ( $tz_badges as $tz_badge ) : ?>
					<span class="tzfdad-ad-badge"><?php echo esc_html( $tz_badge ); ?></span>
					<?php endforeach; ?>
					<span class="tzfdad-pill <?php echo $tz_on ? 'tzfdad-pill-on' : 'tzfdad-pill-off'; ?>"><?php echo $tz_on ? esc_html__( 'On', 'titan-zero-trades-front-desk' ) : esc_html__( 'Off', 'titan-zero-trades-front-desk' ); ?></span>
				</div>
				<p><?php echo esc_html( $tz_obj->get_addon_description() ); ?></p>
				<div class="tzfdad-ad-tile-foot">
					<?php if ( $tz_is_wiz && $tz_on ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=tzfd-ai-setup' ) ); ?>"><?php esc_html_e( 'Open setup wizard', 'titan-zero-trades-front-desk' ); ?></a>
					<?php elseif ( $tz_on ) : ?>
						<a class="button" href="<?php echo esc_url( $tz_anchor ); ?>"><?php esc_html_e( 'Configure', 'titan-zero-trades-front-desk' ); ?></a>
					<?php elseif ( '' !== $tz_help ) : ?>
						<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $tz_help ); ?>"><?php esc_html_e( 'View details', 'titan-zero-trades-front-desk' ); ?></a>
					<?php else : ?>
						<a class="button" href="<?php echo esc_url( $tz_anchor ); ?>"><?php esc_html_e( 'View details', 'titan-zero-trades-front-desk' ); ?></a>
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
		<?php foreach ( $tz_included as $tz_id => $tz_obj ) :
			$tz_on = (bool) $tz_obj->addon_is_active();
		?>
		<div class="tzfdad-ad-row">
			<div>
				<h3><?php echo esc_html( $tz_obj->get_addon_name() ); ?></h3>
				<p><?php echo esc_html( $tz_obj->get_addon_description() ); ?></p>
			</div>
			<div class="tzfdad-ad-row-side">
				<span class="tzfdad-pill <?php echo $tz_on ? 'tzfdad-pill-on' : 'tzfdad-pill-off'; ?>"><?php echo $tz_on ? esc_html__( 'On', 'titan-zero-trades-front-desk' ) : esc_html__( 'Off', 'titan-zero-trades-front-desk' ); ?></span>
				<label class="tzfdad-switch" title="<?php echo esc_attr__( 'Turn on or off', 'titan-zero-trades-front-desk' ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $tz_id ); ?>" name="tz_fd_addons" value="<?php echo esc_attr( $tz_id ); ?>" <?php checked( $tz_on ); ?>>
					<span class="tzfdad-slider"></span>
				</label>
			</div>
		</div>
		<?php endforeach; ?>
	</section>

	<?php if ( ! empty( $tz_pro ) ) : ?>
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
		<?php foreach ( $tz_pro as $tz_id => $tz_obj ) : ?>
			<div class="tzfdad-ad-tile tzfdad-is-pro">
				<div class="tzfdad-ad-tile-top">
					<h3><?php echo esc_html( $tz_obj->get_addon_name() ); ?></h3>
					<label class="tzfdad-switch" title="<?php echo esc_attr__( 'Available in Titan Zero Pro', 'titan-zero-trades-front-desk' ); ?>">
						<input type="checkbox" disabled>
						<span class="tzfdad-slider"></span>
					</label>
				</div>
				<p><?php echo esc_html( $tz_obj->get_addon_description() ); ?></p>
				<?php if ( '' !== (string) $tz_obj->help ) : ?>
				<div class="tzfdad-ad-tile-foot">
					<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $tz_obj->help ); ?>"><?php esc_html_e( 'View details', 'titan-zero-trades-front-desk' ); ?></a>
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
		<?php foreach ( (array) $tz_addons_objs_list as $tz_id => $tz_settings_obj ) :
            if ( ! isset( $tz_front_desk_domains[ $tz_id ] ) ) { continue; } ?>
		<details class="tzfdad-addon-details" id="tzfdad-addon-<?php echo esc_attr( sanitize_html_class( $tz_id ) ); ?>">
			<summary><?php echo esc_html( $tz_settings_obj->get_addon_name() ); ?> <span style="color:#a4acba;font-weight:normal;">&mdash; <?php echo $tz_settings_obj->addon_is_active() ? esc_html__( 'on', 'titan-zero-trades-front-desk' ) : esc_html__( 'off', 'titan-zero-trades-front-desk' ); ?></span></summary>
			<div style="margin-top:10px;">
			<?php if ( $tz_settings_obj->addon_is_active() ) {
				$tz_settings_html = $tz_settings_obj->get_addon_settings();
				if ( is_string( $tz_settings_html ) ) { echo wp_kses( $tz_settings_html, $this->admin_markup_allowed() ); }
			} else { ?>
				<p><?php esc_html_e( 'Turn this add-on on to change its settings.', 'titan-zero-trades-front-desk' ); ?></p>
			<?php } ?>
			</div>
		</details>
		<?php endforeach; ?>
	</section>

	<section class="tzfdad-ad-card" aria-labelledby="tzfdad-ad-concept">
		<h2 id="tzfdad-ad-concept"><?php esc_html_e( 'Concept preview', 'titan-zero-trades-front-desk' ); ?></h2>
		<div class="tzfdad-concept-grid">
			<figure><img src="<?php echo esc_url( plugins_url( 'images/ui-graphics/08-Mobile-PWA/Concept-App-Icons/light-concept.svg', __FILE__ ) ); ?>" alt="<?php echo esc_attr__( 'Concept app icon, light version', 'titan-zero-trades-front-desk' ); ?>" width="160" height="160" loading="lazy" decoding="async" /></figure>
			<figure><img src="<?php echo esc_url( plugins_url( 'images/ui-graphics/08-Mobile-PWA/Concept-App-Icons/dark-concept.svg', __FILE__ ) ); ?>" alt="<?php echo esc_attr__( 'Concept app icon, dark version', 'titan-zero-trades-front-desk' ); ?>" width="160" height="160" loading="lazy" decoding="async" /></figure>
			<figure><img src="<?php echo esc_url( plugins_url( 'images/ui-graphics/08-Mobile-PWA/light-splash.png', __FILE__ ) ); ?>" alt="<?php echo esc_attr__( 'Concept splash screen, light version', 'titan-zero-trades-front-desk' ); ?>" width="117" height="253" loading="lazy" decoding="async" /></figure>
			<figure><img src="<?php echo esc_url( plugins_url( 'images/ui-graphics/08-Mobile-PWA/dark-splash.png', __FILE__ ) ); ?>" alt="<?php echo esc_attr__( 'Concept splash screen, dark version', 'titan-zero-trades-front-desk' ); ?>" width="117" height="253" loading="lazy" decoding="async" /></figure>
			<figure><img src="<?php echo esc_url( plugins_url( 'images/ui-graphics/08-Mobile-PWA/mobile-workspace-concept.png', __FILE__ ) ); ?>" alt="<?php echo esc_attr__( 'Concept mobile workspace with Search, Bookings, Customers, Messages and Services', 'titan-zero-trades-front-desk' ); ?>" width="240" height="450" loading="lazy" decoding="async" /></figure>
		</div>
	</section>

	<p class="tzfdad-ad-foot"><?php esc_html_e( 'Add-on availability depends on your plan and connected services.', 'titan-zero-trades-front-desk' ); ?></p>

	<div class="tzfdad-ad-actions">
		<button type="button" class="button button-primary tzfdad-btn-primary" data-tzfd-action="save-addons"><?php esc_html_e( 'Save add-on changes', 'titan-zero-trades-front-desk' ); ?></button>
		<a href="#top"><?php esc_html_e( 'Back to top', 'titan-zero-trades-front-desk' ); ?></a>
	</div>

</div>
