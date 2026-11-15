<?php
/**
 * Titan Zero UI pack: shared admin shell. Identical in every app pack.
 *
 * Read-only. The pack wraps an existing screen callback with a header, navigation, an optional dashboard and an
 * optional record summary, then calls the existing callback unchanged. Forms, nonces, capability checks and REST
 * routes stay in the plugin. Nothing here writes data.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( defined( 'ABSPATH' ) ) { require_once __DIR__ . '/../../includes/class-tzfd-request-scalar.php'; }

if ( class_exists( 'TZ_UI_Core', false ) ) { return; }

abstract class TZ_UI_Core {
	const VERSION = '1.0.0';

	/** App slug used in handles and file names, e.g. 'front-desk'. */
	abstract public static function slug();

	/** Visible app name. */
	abstract public static function label();

	/** Text domain of the owning plugin, used for translated strings in the pack. */
	abstract public static function domain();

	/** Logo file prefix from assets/brand, e.g. 'front_desk'. */
	abstract public static function logo();

	/** Capability (or array of alternatives) a user needs before the pack prints any data. */
	abstract public static function view_cap();

	/**
	 * Navigation: array of array('page' => slug, 'label' => text, 'cap' => capability or array of alternatives).
	 * Items are shown only when the user holds the capability and WordPress has registered the page.
	 */
	abstract public static function nav_items();

	/** Read-only dashboard: array('stats' => array(...), 'tables' => array(...)), or null when nothing can be read. */
	abstract public static function dashboard_data();

	/** Read-only summary for one record: array('title', 'rows' => array(array(label, value)), 'back'), or null. */
	abstract public static function detail_data( $id );

	/** Hooks the stylesheet. Call once from the plugin, on plugins_loaded. */
	public static function boot() {
		add_action( 'admin_enqueue_scripts', array( static::class, 'enqueue' ) );
	}

	public static function enqueue() {
		if ( ! static::is_app_screen() ) {
			return;
		}
		wp_enqueue_style( static::handle(), static::pack_url() . '/ui/tz-app.css', array(), self::VERSION );
	}

	public static function handle() {
		return 'tz-app-ui-' . static::slug();
	}

	/** True when the current ?page= value is one of this app's navigation pages. */
	public static function is_app_screen() {
		$page = static::current_page();
		if ( '' === $page ) {
			return false;
		}
		foreach ( static::nav_items() as $item ) {
			if ( isset( $item['page'] ) && $item['page'] === $page ) {
				return true;
			}
		}
		return false;
	}

	/** Current admin page slug from the routing query value (read-only). */
	public static function current_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing value.
		return isset( $_GET['page'] ) ? sanitize_key( tzfd_request_text( 'get', 'page' ) ) : '';
	}

	/**
	 * Returns a callable for add_submenu_page() or add_menu_page(). It prints the pack shell around $callback.
	 * Options: 'dashboard' (bool), 'detail' (query argument name holding a record id), 'title', 'lead'.
	 */
	public static function wrap( $callback, $page, $opts = null ) {
		$class = static::class;
		$opts  = null === $opts ? static::options_for( $page ) : (array) $opts;
		return function () use ( $class, $callback, $page, $opts ) {
			$class::render_screen( $page, $callback, $opts );
		};
	}

	/** Default options for a page when wrap() gets none. Apps override this with their page map. */
	public static function options_for( $page ) {
		return array();
	}

	/**
	 * Prints the shell, then the optional dashboard and record summary, then the existing callback, then the close.
	 * If the user cannot view the app, the callback runs alone so its own permission check still decides.
	 */
	public static function render_screen( $page, $callback = null, array $opts = array() ) {
		$page = sanitize_key( (string) $page );
		if ( ! self::can_any( static::view_cap() ) ) {
			self::call( $callback );
			return;
		}
		$app = static::app_info();
		static::part( 'page-open', array(
			'tz_app'     => $app,
			'tz_nav'     => static::nav(),
			'tz_current' => $page,
			'tz_title'   => isset( $opts['title'] ) ? (string) $opts['title'] : '',
			'tz_lead'    => isset( $opts['lead'] ) ? (string) $opts['lead'] : '',
		) );
		// A record id in the query string means a record view: the dashboard then stays hidden.
		$id = 0;
		if ( ! empty( $opts['detail'] ) ) {
			$param = sanitize_key( (string) $opts['detail'] );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only record id for display.
			$id = isset( $_GET[ $param ] ) ? absint( tzfd_request_text( 'get', $param ) ) : 0;
		}
		if ( ! empty( $opts['dashboard'] ) && 0 === $id ) {
			$dash = self::safe( array( static::class, 'dashboard_data' ) );
			// Unreadable figures render as an honest unavailable state, never as zero.
			if ( ! is_array( $dash ) ) {
				$dash = array( 'stats' => array(), 'tables' => array() );
			}
			static::part( 'dashboard', array( 'tz_app' => $app, 'tz_dash' => $dash ) );
		}
		if ( $id > 0 ) {
			$detail = self::safe( array( static::class, 'detail_data' ), array( $id ) );
			if ( is_array( $detail ) ) {
				static::part( 'detail-card', array( 'tz_app' => $app, 'tz_detail' => $detail ) );
			}
		}
		self::call( $callback );
		static::part( 'page-close' );
	}

	/** App details passed to every partial. Only plain strings. */
	public static function app_info() {
		return array(
			'slug'   => static::slug(),
			'label'  => static::label(),
			'domain' => static::domain(),
			'logo'   => static::logo(),
			'base'   => static::pack_url() . '/assets/brand/',
			'site'   => get_bloginfo( 'name' ),
		);
	}

	/** Navigation items the current user may open and WordPress has registered. */
	public static function nav() {
		$out = array();
		foreach ( static::nav_items() as $item ) {
			$page = isset( $item['page'] ) ? sanitize_key( (string) $item['page'] ) : '';
			if ( '' === $page || ! self::can_any( isset( $item['cap'] ) ? $item['cap'] : 'manage_options' ) ) {
				continue;
			}
			$url = function_exists( 'menu_page_url' ) ? menu_page_url( $page, false ) : '';
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$out[] = array(
				'page'  => $page,
				'label' => isset( $item['label'] ) ? (string) $item['label'] : $page,
				'url'   => $url,
			);
		}
		return $out;
	}

	/** True when the user holds any one of the given capabilities. */
	protected static function can_any( $caps ) {
		foreach ( (array) $caps as $cap ) {
			if ( is_string( $cap ) && '' !== $cap && current_user_can( $cap ) ) {
				return true;
			}
		}
		return false;
	}

	/** Base URL of the pack folder (the folder that holds ui/ and assets/). */
	protected static function pack_url() {
		// plugins_url('', __FILE__) is the ui/ folder with a trailing slash; its parent is the pack folder.
		return untrailingslashit( dirname( plugins_url( '', __FILE__ ) ) );
	}

	/** Includes one partial with its variables. Missing partials are skipped, never fatal. */
	protected static function part( $name, array $vars = array() ) {
		$file = dirname( __FILE__ ) . '/partials/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return;
		}
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- Local template variables only.
		include $file;
	}

	/** Runs a read-only function; any failure returns null so the screen degrades to unavailable. */
	protected static function safe( $fn, array $args = array() ) {
		try {
			return call_user_func_array( $fn, $args );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	private static function call( $callback ) {
		if ( is_callable( $callback ) ) {
			call_user_func( $callback );
		}
	}
}
