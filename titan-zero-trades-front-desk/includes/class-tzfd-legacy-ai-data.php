<?php
/**
 * Legacy AI data: administrator-visible audit, export and consented deletion (issue #17).
 *
 * Earlier builds stored AI settings in tz_fd_ai_* options (and per-company copies named
 * tzfd_c{company}_tz_fd_ai_*) and kept chat history in the tz_ai_conversations table. This
 * version no longer runs AI, but those records are kept so an upgrade or rollback never
 * loses them.
 *
 * Rules enforced here:
 *  - Nothing is deleted automatically. No admin_init, activation or upgrade hook touches this data.
 *  - Deletion removes only legacy AI option rows, and only for an administrator who types the
 *    confirmation phrase and ticks the backup confirmation. Chat rows, bookings, logs and the
 *    upgrade settings are never touched.
 *  - Values whose option names look like credentials are never exported or shown.
 *  - The privacy exporter reads historic chat rows for an exact email address only. It never
 *    re-enables AI and never writes.
 *
 * Multisite: options and tables are read and changed for the current site only. Run the tool
 * on each site separately.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Legacy_AI_Data {

	/** Chat history table (bare name, prefixed at runtime). Kept for export and retention. */
	const CHAT_TABLE = 'tz_ai_conversations';

	/** Exact phrase an administrator must type before legacy AI options are deleted. */
	const DELETE_PHRASE = 'DELETE LEGACY AI OPTIONS';

	const CAP            = 'manage_options';
	const PAGE           = 'tzfd-legacy-ai-data';
	const ACTION_EXPORT  = 'tzfd_legacy_ai_export';
	const ACTION_DELETE  = 'tzfd_legacy_ai_delete';
	const NOTICE_ARG     = 'tzfd_legacy_ai_notice';
	const PAGE_SIZE      = 50;

	/** Columns that may hold an address used to match a customer for the privacy exporter, in priority order. */
	const EMAIL_COLUMNS = array( 'email', 'customer_email', 'user_email', 'contact_email' );

	/** Option or column names that look like credentials. Their values are never exported or shown. */
	const SECRET_PATTERN = '/(key|secret|token|password|credential)/i';

	/** Register hooks. Deliberately adds no admin_init or upgrade callback that could delete data. */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( __CLASS__, 'handle_delete' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
	}

	/** Find every legacy AI option row on this site, including per-company copies. Returns sorted names only. */
	public static function option_names() {
		global $wpdb;
		$plain  = $wpdb->esc_like( 'tz_fd_ai_' ) . '%';
		$scoped = 'tzfd_c%' . $wpdb->esc_like( '_tz_fd_ai_' ) . '%';
		$names  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name ASC",
				$plain,
				$scoped
			)
		);
		$names = is_array( $names ) ? array_values( array_unique( array_map( 'strval', $names ) ) ) : array();
		sort( $names, SORT_STRING );
		return $names;
	}

	/** True when a name looks like it holds a credential. */
	public static function is_secret_name( $name ) {
		return 1 === preg_match( self::SECRET_PATTERN, (string) $name );
	}

	/** Chat table name for this site, or '' when it cannot be built. */
	public static function chat_table() {
		global $wpdb;
		return isset( $wpdb->prefix ) ? $wpdb->prefix . self::CHAT_TABLE : '';
	}

	/** Whether the chat table exists on this site. */
	public static function chat_table_exists() {
		global $wpdb;
		$table = self::chat_table();
		if ( '' === $table ) { return false; }
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return $found === $table;
	}

	/**
	 * Read-only audit. Reports names, sizes and row counts only; no values leave this method.
	 *
	 * @return array
	 */
	public static function audit() {
		global $wpdb;
		$options = array();
		foreach ( self::option_names() as $name ) {
			$value     = get_option( $name, null );
			$options[] = array(
				'name'   => $name,
				'secret' => self::is_secret_name( $name ),
				'bytes'  => strlen( maybe_serialize( $value ) ),
			);
		}
		$exists = self::chat_table_exists();
		$rows   = null;
		if ( $exists ) {
			$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::chat_table() ) );
		}
		return array(
			'mode'                  => 'read_only',
			'options'               => $options,
			'chat_table'            => array( 'name' => self::chat_table(), 'exists' => $exists, 'rows' => $rows ),
			'chat_records_retained' => true,
		);
	}

	/**
	 * Export of legacy AI option values for an administrator to keep as a backup. Credential-like
	 * options are listed by name only, with their values removed.
	 *
	 * @return array
	 */
	public static function export_payload() {
		$payload = array(
			'format'         => 'tzfd-legacy-ai-export',
			'format_version' => 1,
			'plugin_version' => defined( 'TZFD_VERSION' ) ? TZFD_VERSION : '',
			'generated_utc'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'options'        => array(),
			'withheld'       => array(),
		);
		foreach ( self::option_names() as $name ) {
			if ( self::is_secret_name( $name ) ) {
				$payload['withheld'][] = $name;
				continue;
			}
			$payload['options'][ $name ] = get_option( $name, null );
		}
		return $payload;
	}

	/**
	 * Delete legacy AI option rows once the confirmation is complete. Pure check first, so it can be tested.
	 *
	 * @return bool
	 */
	public static function confirmation_is_complete( $phrase, $backup_confirmed ) {
		return is_string( $phrase ) && self::DELETE_PHRASE === $phrase && true === $backup_confirmed;
	}

	/** Remove the legacy AI option rows listed by option_names(). Returns the number removed. */
	public static function delete_options() {
		$removed = 0;
		foreach ( self::option_names() as $name ) {
			if ( delete_option( $name ) ) { $removed++; }
		}
		return $removed;
	}

	/** Admin menu entry under Tools, so it does not change the Front Desk menu. */
	public static function add_page() {
		add_management_page(
			__( 'Front Desk legacy AI data', 'titan-zero-trades-front-desk' ),
			__( 'Front Desk legacy AI data', 'titan-zero-trades-front-desk' ),
			self::CAP,
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/** Administrator page: audit, export and the guarded delete form. */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'titan-zero-trades-front-desk' ), 403 );
		}
		$audit  = self::audit();
		$notice = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : '';
		$texts  = array(
			'deleted'  => __( 'Legacy AI option rows were deleted. Chat records and all other data were kept.', 'titan-zero-trades-front-desk' ),
			'refused'  => __( 'Nothing was deleted. Type the exact phrase and confirm that you have a backup.', 'titan-zero-trades-front-desk' ),
		);

		echo '<div class="wrap"><h1>' . esc_html__( 'Front Desk legacy AI data', 'titan-zero-trades-front-desk' ) . '</h1>';
		echo '<p>' . esc_html__( 'These records come from earlier AI features. Nothing here is deleted automatically. Chat history and bookings are kept in every case.', 'titan-zero-trades-front-desk' ) . '</p>';
		if ( isset( $texts[ $notice ] ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html( $texts[ $notice ] ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Audit', 'titan-zero-trades-front-desk' ) . '</h2>';
		if ( empty( $audit['options'] ) ) {
			echo '<p>' . esc_html__( 'No legacy AI option rows were found.', 'titan-zero-trades-front-desk' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Option', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Size (bytes)', 'titan-zero-trades-front-desk' ) . '</th><th>' . esc_html__( 'Credential-like', 'titan-zero-trades-front-desk' ) . '</th></tr></thead><tbody>';
			foreach ( $audit['options'] as $row ) {
				echo '<tr><td><code>' . esc_html( $row['name'] ) . '</code></td><td>' . esc_html( (string) $row['bytes'] ) . '</td><td>' . ( $row['secret'] ? esc_html__( 'Yes, value withheld', 'titan-zero-trades-front-desk' ) : esc_html__( 'No', 'titan-zero-trades-front-desk' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p>' . esc_html__( 'Chat history table', 'titan-zero-trades-front-desk' ) . ': ';
		if ( $audit['chat_table']['exists'] ) {
			echo esc_html( sprintf( /* translators: %d: number of stored chat rows */ __( '%d rows (kept)', 'titan-zero-trades-front-desk' ), (int) $audit['chat_table']['rows'] ) );
		} else {
			echo esc_html__( 'not present on this site', 'titan-zero-trades-front-desk' );
		}
		echo '</p>';

		echo '<h2>' . esc_html__( 'Export', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_EXPORT ) . '" />';
		wp_nonce_field( self::ACTION_EXPORT );
		submit_button( __( 'Download JSON export', 'titan-zero-trades-front-desk' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Delete legacy AI options', 'titan-zero-trades-front-desk' ) . '</h2>';
		echo '<p>' . esc_html__( 'Deletes only the option rows listed above. Chat history, bookings, logs and upgrade settings are not changed. Download the export first, and keep a site backup.', 'titan-zero-trades-front-desk' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE ) . '" />';
		wp_nonce_field( self::ACTION_DELETE );
		echo '<p><label for="tzfd-legacy-ai-phrase">' . esc_html( sprintf( /* translators: %s: exact phrase to type */ __( 'Type %s to confirm', 'titan-zero-trades-front-desk' ), self::DELETE_PHRASE ) ) . '</label><br />';
		echo '<input type="text" id="tzfd-legacy-ai-phrase" name="confirm_phrase" autocomplete="off" class="regular-text" /></p>';
		echo '<p><label><input type="checkbox" name="backup_confirmed" value="1" /> ' . esc_html__( 'I have a backup of this site and downloaded the export.', 'titan-zero-trades-front-desk' ) . '</label></p>';
		submit_button( __( 'Delete legacy AI options', 'titan-zero-trades-front-desk' ), 'delete', 'submit', false );
		echo '</form></div>';
	}

	/** admin-post handler: stream the export as a download. Administrators only, with a valid nonce. */
	public static function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), 403 );
		}
		check_admin_referer( self::ACTION_EXPORT );
		$json = wp_json_encode( self::export_payload(), JSON_PRETTY_PRINT );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tzfd-legacy-ai-export-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download body, not HTML.
		exit;
	}

	/** admin-post handler: delete legacy AI options only after the phrase and backup confirmation. */
	public static function handle_delete() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'titan-zero-trades-front-desk' ), 403 );
		}
		check_admin_referer( self::ACTION_DELETE );
		$phrase = isset( $_POST['confirm_phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm_phrase'] ) ) : '';
		$backup = isset( $_POST['backup_confirmed'] ) && '1' === wp_unslash( $_POST['backup_confirmed'] );
		if ( ! self::confirmation_is_complete( $phrase, $backup ) ) {
			self::redirect_back( 'refused' );
		}
		self::delete_options();
		self::redirect_back( 'deleted' );
	}

	/** Redirect to the page with a fixed notice key. */
	private static function redirect_back( $notice ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, self::NOTICE_ARG => $notice ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/** Register the chat-history exporter with WordPress privacy tools. */
	public static function register_exporter( $exporters ) {
		$exporters['tzfd-legacy-ai-chats'] = array(
			'exporter_friendly_name' => __( 'Titan Zero Front Desk AI chat history (legacy)', 'titan-zero-trades-front-desk' ),
			'callback'               => array( __CLASS__, 'export_chats' ),
		);
		return $exporters;
	}

	/**
	 * Export one bounded page of legacy chat rows for an exact email address. Read-only.
	 * Rows are matched only through a verified email column; tables without one are reported as not matched.
	 *
	 * @param string $email_address Address supplied by WordPress privacy tools.
	 * @param int    $page          1-based page number.
	 * @return array
	 */
	public static function export_chats( $email_address, $page = 1 ) {
		global $wpdb;
		$empty = array( 'data' => array(), 'done' => true );
		if ( ! is_string( $email_address ) || ! is_int( $page ) || $page < 1 ) { return $empty; }
		$email_address = strtolower( trim( $email_address ) );
		if ( '' === $email_address || ! is_email( $email_address ) || sanitize_email( $email_address ) !== $email_address ) { return $empty; }
		if ( ! self::chat_table_exists() ) { return $empty; }

		$table   = self::chat_table();
		$columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ) );
		$columns = is_array( $columns ) ? array_map( 'strval', $columns ) : array();
		$email_column = '';
		foreach ( self::EMAIL_COLUMNS as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) { $email_column = $candidate; break; }
		}
		if ( '' === $email_column || ! in_array( 'id', $columns, true ) ) { return $empty; }

		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE LOWER(%i) = %s ORDER BY id ASC LIMIT %d OFFSET %d',
				$table,
				$email_column,
				$email_address,
				self::PAGE_SIZE + 1,
				$offset
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		$done = count( $rows ) <= self::PAGE_SIZE;
		$rows = array_slice( $rows, 0, self::PAGE_SIZE );

		$items = array();
		foreach ( $rows as $row ) {
			$fields = array();
			foreach ( $row as $column => $value ) {
				if ( self::is_secret_name( $column ) ) { continue; }
				$fields[] = array(
					'name'  => (string) $column,
					'value' => is_scalar( $value ) ? wp_strip_all_tags( (string) $value ) : '',
				);
			}
			$items[] = array(
				'group_id'    => 'tzfd-legacy-ai-chats',
				'group_label' => __( 'Titan Zero Front Desk AI chat history (legacy)', 'titan-zero-trades-front-desk' ),
				'item_id'     => 'tzfd-legacy-ai-chat-' . (int) $row['id'],
				'data'        => $fields,
			);
		}
		return array( 'data' => $items, 'done' => $done );
	}
}
