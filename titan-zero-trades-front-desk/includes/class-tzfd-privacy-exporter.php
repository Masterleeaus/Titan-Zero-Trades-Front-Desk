<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/**
 * WordPress personal-data export and erase support for the plugin's booking, customer, quote, chat and staff records, and client-portal sign-in records.
 *
 * @package TZFDFrontDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/class-tzfd-stored-data.php';


/** Export customer-submitted fields from bookings matched by their exact email. */
class TZFD_Privacy_Exporter {
	const PAGE_SIZE = 50;

	/** Register the exporter, the eraser and the suggested privacy policy text. */
	public static function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_policy_content' ) );
	}

	/** Add the suggested text for the site's privacy policy page. */
	public static function add_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Titan Zero Trades: Front Desk', 'titan-zero-trades-front-desk' ),
			wp_kses_post( self::policy_text() )
		);
	}

	/** Suggested privacy policy text. Describes what the plugin stores; services receive data only when turned on. */
	/** Suggested privacy policy text. Describes what the plugin stores and each outside service it can contact. */
	private static function policy_text() {
		$sections = array(
			array(
				__( 'Bookings and booking forms', 'titan-zero-trades-front-desk' ),
				__( 'Each booking or form submission stores what the customer entered, such as name, email address, phone number, address, booking date and time, and the answers to the form fields. While the setting "Store user\'s IP address?" is on (it is on by default), the IP address of the request is stored with the booking too. The activity log records booking changes with the booking number, the time and the logged-in user who made the change. Booking records are kept until you delete them in Titan Zero Trades: Front Desk. Calendar invitation files that contain booking details are saved in your uploads folder and are normally deleted once the email is sent.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Customers and staff contacts', 'titan-zero-trades-front-desk' ),
				__( 'The customer list stores name, company, email address, phone number, address, city, region, postcode, country, preferred contact method, source and notes. The staff contact list stores the name, phone number and email address of each staff member you add. Both are kept until you delete them in Titan Zero Trades: Front Desk.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Quotes', 'titan-zero-trades-front-desk' ),
				__( 'Quotes store the customer\'s name, email address, phone number, service address, job description, customer message, notes and quote items. When a customer signs a quote, the signer\'s name and email address, the signature image, a hash of the IP address and the browser\'s user agent are stored with the quote.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Booking assistant chats', 'titan-zero-trades-front-desk' ),
				__( 'The AI booking assistant stores each chat: the messages, the booking draft, the customer\'s email address if they gave one, and a hash of the IP address. A short rate-limit counter is kept under a hash of the IP address. Photos sent in a chat are saved in a folder inside your uploads folder under a random file name. Chats and photos are deleted after the retention period set in the AI settings (30 days by default).', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Payments', 'titan-zero-trades-front-desk' ),
				__( 'Payment records store the customer\'s name, amount, payment method, reference and note, and the booking they relate to. They are kept until you delete them in Titan Zero Trades: Front Desk.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Client portal', 'titan-zero-trades-front-desk' ),
				__( 'When a client asks for a sign-in link, their email address is stored with the one-time link for 20 minutes and with their session for 2 hours. A cookie named tzfd_portal_session holds the session token. After these times the records stop working, and WordPress removes them during its routine cleanup.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Missed-call texts and webhooks', 'titan-zero-trades-front-desk' ),
				__( 'If missed-call texts are turned on, the caller\'s phone number is sent to the SMS gateway you set up. The site counts texts per number for 24 hours, under a hashed key, to limit how many are sent. The missed-call log keeps only the last four digits. When a caller replies STOP, UNSUBSCRIBE, END, CANCEL or QUIT, the full number is added to an opt-out list kept in your WordPress options, and no more texts are sent until the caller replies START. The WordPress erase tool cannot find this list, because it is keyed by phone number. If a webhook is turned on, each booking event sends the booking details to the address you enter.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Captcha', 'titan-zero-trades-front-desk' ),
				__( 'The captcha sets a cookie named rand_code and a temporary record. Both expire after 30 minutes.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Exporting and erasing', 'titan-zero-trades-front-desk' ),
				__( 'The WordPress export tool includes the booking records (with IP addresses), customer records, quotes and quote signatures, booking assistant chats and staff contacts that match the email address requested. Payment records and the phone-number opt-out list are not matched by email address, so they are not exported. The WordPress erase tool removes the client-portal sign-in links and sessions for that address. It does not delete bookings, quotes, customers, staff contacts or chats. These are reported as kept, and you delete them in Titan Zero Trades: Front Desk.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Deleting the plugin', 'titan-zero-trades-front-desk' ),
				__( 'Deleting the plugin does not delete bookings, quotes, customers or settings unless you add define( \'TZFD_DELETE_DATA_ON_UNINSTALL\', true ); to wp-config.php before you delete it. Chat photos and calendar invitation files are removed when the plugin is deleted.', 'titan-zero-trades-front-desk' ),
			),
		);

		// Outside services: the same list as the readme's External services section.
		$services = array(
			array(
				__( 'An AI provider you choose, or another OpenAI-compatible server you enter. The booking assistant sends its chat messages, the booking details collected so far and photo links. Quote suggestions send the job description, service type, urgency, the customer\'s name and address, matching catalogue items and up to five earlier accepted quotes for similar work. Nothing is sent until you save a provider and, where one is needed, a key.', 'titan-zero-trades-front-desk' ),
				array(
					'OpenAI'           => 'https://openai.com/policies/privacy-policy/',
					'Anthropic'        => 'https://www.anthropic.com/legal/privacy',
					'Groq'             => 'https://groq.com/privacy-policy/',
					'OpenRouter'       => 'https://openrouter.ai/privacy',
					'Google AI Studio' => 'https://policies.google.com/privacy',
					'Cerebras'         => 'https://www.cerebras.ai/privacy-policy',
				),
			),
			array(
				__( 'A local AI server you run, such as Ollama, LM Studio, vLLM or LocalAI, at an address you enter. The same data is sent only to that address. If you enter an address that is not on your own computer or network, that server receives the data like any other provider.', 'titan-zero-trades-front-desk' ),
				array(),
			),
			array(
				__( 'An SMS gateway you choose, and your phone system. When a call is missed, the caller\'s phone number and one text message with the booking link are sent to the gateway address you enter. The plugin does not include a gateway or phone provider, so check that provider\'s terms and privacy policy before you turn this on.', 'titan-zero-trades-front-desk' ),
				array(),
			),
			array(
				__( 'A web page you name in the setup wizard for service import. The site makes one ordinary request for that page and reads its list items once. No customer data is sent. The page owner\'s terms and privacy policy apply.', 'titan-zero-trades-front-desk' ),
				array(),
			),
			array(
				__( 'A webhook endpoint you enter. Each booking created, booking status update or payment update sends a signed message with the booking details. If the endpoint does not accept it, the plugin retries, making up to 5 attempts in total. The receiving service\'s terms and privacy policy apply.', 'titan-zero-trades-front-desk' ),
				array(),
			),
			array(
				__( 'WhatsApp and SMS buttons below the booking form. These are links, not a connection: when a visitor taps one, their own phone or browser opens WhatsApp or their messaging app. The site does not contact WhatsApp.', 'titan-zero-trades-front-desk' ),
				array( 'WhatsApp' => 'https://www.whatsapp.com/legal/privacy-policy' ),
			),
		);

		$html = '<p>' . esc_html__( 'Titan Zero Trades: Front Desk stores the personal data below in your WordPress database. It does not create an account, collect a licence key or report usage to its author, and nothing is sent to the plugin author.', 'titan-zero-trades-front-desk' ) . '</p>';
		foreach ( $sections as $section ) {
			$html .= '<h4>' . esc_html( $section[0] ) . '</h4><p>' . esc_html( $section[1] ) . '</p>';
		}
		$html .= '<h4>' . esc_html__( 'Outside services', 'titan-zero-trades-front-desk' ) . '</h4>';
		$html .= '<p>' . esc_html__( 'These services are used only when you turn them on and enter their details. The plugin does not load scripts, fonts or images from outside services on public pages.', 'titan-zero-trades-front-desk' ) . '</p><ul>';
		foreach ( $services as $service ) {
			$html .= '<li>' . esc_html( $service[0] );
			$links = array();
			foreach ( $service[1] as $name => $url ) {
				$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
			}
			if ( array() !== $links ) {
				$html .= ' ' . esc_html__( 'Privacy policy:', 'titan-zero-trades-front-desk' ) . ' ' . implode( ', ', $links ) . '.';
			}
			$html .= '</li>';
		}
		$html .= '</ul>';
		return $html;
	}

	/** Add this eraser to the WordPress privacy tools registry.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['tzfd-bookings'] = array(
			'eraser_friendly_name' => __( 'Titan Zero Trades: Front Desk records', 'titan-zero-trades-front-desk' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/** Remove client-portal sign-in records for an exact email address. Bookings, quotes, customers, payments and chats are never removed here; they are reported as kept.
	 *
	 * @param string $email_address Email address supplied by WordPress privacy tools.
	 * @param int    $page          One-based page number.
	 * @return array
	 */
	public static function erase( $email_address, $page = 1 ) {
		global $wpdb;
		$response = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );

		// Same identity rule as export: invalid input matches nothing.
		if ( ! is_string( $email_address ) || 1 !== (int) $page ) {
			return $response;
		}
		$email_address = strtolower( trim( $email_address ) );
		if ( '' === $email_address || ! is_email( $email_address ) || sanitize_email( $email_address ) !== $email_address ) {
			return $response;
		}
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix, $wpdb->options ) || ! is_string( $wpdb->prefix ) || '' === $wpdb->prefix ) {
			return self::erase_unavailable();
		}

		// Records that stay until deleted in Front Desk: reported as kept, never deleted here.
		$kept = array(
			array( 'tzfd_messages', 'notifyto', __( 'Booking records are kept. Delete them in Titan Zero Trades: Front Desk.', 'titan-zero-trades-front-desk' ) ),
			array( 'tzfd_quotes', 'customer_email', __( 'Quotes are kept. Delete them in Titan Zero Trades: Front Desk.', 'titan-zero-trades-front-desk' ) ),
			array( 'tzfd_quote_signatures', 'signer_email', __( 'Quote signatures are kept with their quotes. Delete the quote in Titan Zero Trades: Front Desk.', 'titan-zero-trades-front-desk' ) ),
			array( 'tzfd_customers', 'email', __( 'Customer records are kept. Delete them in Titan Zero Trades: Front Desk.', 'titan-zero-trades-front-desk' ) ),
			array( 'tzfd_ai_conversations', 'user_email', __( 'Booking assistant chats are kept until the retention period ends, then they are deleted automatically.', 'titan-zero-trades-front-desk' ) ),
			array( 'tzfd_receptionist_staff', 'email', __( 'Staff contacts are kept. Delete them in Titan Zero Trades: Front Desk.', 'titan-zero-trades-front-desk' ) ),
		);
		foreach ( $kept as $item ) {
			$count = self::count_rows( $item[0], $item[1], $email_address );
			if ( null === $count ) {
				return self::erase_unavailable();
			}
			if ( $count > 0 ) {
				$response['items_retained'] = true;
				$response['messages'][]     = $item[2];
			}
		}

		// Client-portal sign-in links and sessions hold the email address as their value. Their keys are hashed, so they are found by value.
		$removed = 0;
		foreach ( array( 'tzfd_portal_link_', 'tzfd_portal_session_' ) as $prefix ) {
			$names = $wpdb->get_col( $wpdb->prepare(
				"SELECT option_name FROM %i WHERE option_name LIKE %s AND LOWER(option_value) = %s",
				$wpdb->options,
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$email_address
			) );
			if ( ! is_array( $names ) || ! empty( $wpdb->last_error ) ) {
				return self::erase_unavailable();
			}
			foreach ( $names as $name ) {
				if ( delete_transient( substr( (string) $name, strlen( '_transient_' ) ) ) ) {
					++$removed;
				}
			}
		}
		if ( $removed > 0 ) {
			$response['items_removed'] = true;
			/* translators: %d: number of client-portal sign-in records removed. */
			$response['messages'][] = sprintf( _n( '%d client-portal sign-in record removed.', '%d client-portal sign-in records removed.', $removed, 'titan-zero-trades-front-desk' ), $removed );
		}
		return $response;
	}

	/** Row count for one email column, or 0 when the table is not installed. Null on a database error. */
	private static function count_rows( $table, $column, $email_address ) {
		global $wpdb;
		$full = $wpdb->prefix . $table;
		if ( $full !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) ) ) {
			return 0;
		}
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE LOWER(%i) = %s', $full, $column, $email_address ) );
		if ( ! empty( $wpdb->last_error ) || null === $count ) {
			return null;
		}
		return (int) $count;
	}

	private static function erase_unavailable() {
		return array(
			'items_removed'  => false,
			'items_retained' => true,
			'messages'       => array( __( 'Titan Zero Trades: Front Desk records could not be checked. Please retry.', 'titan-zero-trades-front-desk' ) ),
			'done'           => true,
		);
	}

	/** Add this exporter to the WordPress privacy tools registry.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['tzfd-bookings'] = array(
			'exporter_friendly_name' => __( 'Titan Zero Trades: Front Desk bookings', 'titan-zero-trades-front-desk' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		foreach ( self::email_tables() as $tzfd_key => $tzfd_table ) {
			$exporters[ 'tzfd-' . $tzfd_key ] = array(
				'exporter_friendly_name' => $tzfd_table['label'],
				'callback'               => function ( $email_address, $page = 1 ) use ( $tzfd_key ) {
					return self::export_table( $tzfd_key, $email_address, $page );
				},
			);
		}
		return $exporters;
	}

	/** Tables that hold a customer's or staff member's contact details: the column with the email address, and the fields to export.
	 *
	 * @return array
	 */
	private static function email_tables() {
		return array(
			'customers'          => array(
				'table'  => 'tzfd_customers',
				'column' => 'email',
				'label'  => __( 'Titan Zero Trades: Front Desk customer records', 'titan-zero-trades-front-desk' ),
				'fields' => array(
					'name'     => __( 'Name', 'titan-zero-trades-front-desk' ),
					'company'  => __( 'Company', 'titan-zero-trades-front-desk' ),
					'email'    => __( 'Email address', 'titan-zero-trades-front-desk' ),
					'phone'    => __( 'Phone', 'titan-zero-trades-front-desk' ),
					'address'  => __( 'Address', 'titan-zero-trades-front-desk' ),
					'city'     => __( 'City', 'titan-zero-trades-front-desk' ),
					'region'   => __( 'Region', 'titan-zero-trades-front-desk' ),
					'postcode' => __( 'Postcode', 'titan-zero-trades-front-desk' ),
					'country'  => __( 'Country', 'titan-zero-trades-front-desk' ),
					'notes'    => __( 'Notes', 'titan-zero-trades-front-desk' ),
					'created'  => __( 'Created', 'titan-zero-trades-front-desk' ),
				),
			),
			'quotes'             => array(
				'table'  => 'tzfd_quotes',
				'column' => 'customer_email',
				'label'  => __( 'Titan Zero Trades: Front Desk quotes', 'titan-zero-trades-front-desk' ),
				'fields' => array(
					'reference'        => __( 'Quote reference', 'titan-zero-trades-front-desk' ),
					'status'           => __( 'Status', 'titan-zero-trades-front-desk' ),
					'customer_name'    => __( 'Name', 'titan-zero-trades-front-desk' ),
					'customer_email'   => __( 'Email address', 'titan-zero-trades-front-desk' ),
					'customer_phone'   => __( 'Phone', 'titan-zero-trades-front-desk' ),
					'service_address'  => __( 'Service address', 'titan-zero-trades-front-desk' ),
					'service_type'     => __( 'Service type', 'titan-zero-trades-front-desk' ),
					'description'      => __( 'Job description', 'titan-zero-trades-front-desk' ),
					'customer_message' => __( 'Message to customer', 'titan-zero-trades-front-desk' ),
					'notes'            => __( 'Notes', 'titan-zero-trades-front-desk' ),
					'created'          => __( 'Created', 'titan-zero-trades-front-desk' ),
				),
			),
			'quote-signatures'   => array(
				'table'  => 'tzfd_quote_signatures',
				'column' => 'signer_email',
				'label'  => __( 'Titan Zero Trades: Front Desk quote signatures', 'titan-zero-trades-front-desk' ),
				'fields' => array(
					'signer_name'    => __( 'Signer name', 'titan-zero-trades-front-desk' ),
					'signer_email'   => __( 'Signer email address', 'titan-zero-trades-front-desk' ),
					'signature_data' => __( 'Signature (image data)', 'titan-zero-trades-front-desk' ),
					'created'        => __( 'Signed', 'titan-zero-trades-front-desk' ),
				),
			),
			'ai-conversations'   => array(
				'table'  => 'tzfd_ai_conversations',
				'column' => 'user_email',
				'label'  => __( 'Titan Zero Trades: Front Desk booking assistant chats', 'titan-zero-trades-front-desk' ),
				'fields' => array(
					'created'  => __( 'Started', 'titan-zero-trades-front-desk' ),
					'messages' => __( 'Chat messages', 'titan-zero-trades-front-desk' ),
					'draft'    => __( 'Booking draft', 'titan-zero-trades-front-desk' ),
				),
			),
			'receptionist-staff' => array(
				'table'  => 'tzfd_receptionist_staff',
				'column' => 'email',
				'label'  => __( 'Titan Zero Trades: Front Desk staff contacts', 'titan-zero-trades-front-desk' ),
				'fields' => array(
					'name'    => __( 'Name', 'titan-zero-trades-front-desk' ),
					'email'   => __( 'Email address', 'titan-zero-trades-front-desk' ),
					'phone'   => __( 'Phone', 'titan-zero-trades-front-desk' ),
					'created' => __( 'Created', 'titan-zero-trades-front-desk' ),
				),
			),
		);
	}

	/** Export one bounded page of an email-keyed table for an exact email address.
	 *
	 * @param string $key           Key from email_tables().
	 * @param string $email_address Email address supplied by WordPress privacy tools.
	 * @param int    $page          One-based page number.
	 * @return array|WP_Error
	 */
	private static function export_table( $key, $email_address, $page ) {
		global $wpdb;
		$tables = self::email_tables();
		if ( ! isset( $tables[ $key ] ) || ! is_string( $email_address ) ) {
			return array( 'data' => array(), 'done' => true );
		}
		$config        = $tables[ $key ];
		$email_address = strtolower( trim( $email_address ) );
		if ( '' === $email_address || ! is_email( $email_address ) || sanitize_email( $email_address ) !== $email_address ) {
			return array( 'data' => array(), 'done' => true );
		}
		$max_page = intdiv( PHP_INT_MAX, self::PAGE_SIZE );
		if ( ( ! is_int( $page ) && ! is_string( $page ) ) ||
			false === filter_var( $page, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => $max_page ) ) ) ) {
			return new WP_Error( 'titan_zero_front_desk_invalid_export_page', __( 'Invalid booking export page.', 'titan-zero-trades-front-desk' ) );
		}
		$page = (int) $page;
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) || ! is_string( $wpdb->prefix ) || '' === $wpdb->prefix ) {
			return self::storage_error();
		}

		// A table that is not installed holds no records for this address.
		$table = $wpdb->prefix . $config['table'];
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return array( 'data' => array(), 'done' => true );
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE LOWER(%i) = %s ORDER BY id ASC LIMIT %d OFFSET %d', $table, $config['column'], $email_address, self::PAGE_SIZE + 1, ( $page - 1 ) * self::PAGE_SIZE )
		);
		if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
			return self::storage_error();
		}

		$has_more = count( $rows ) > self::PAGE_SIZE;
		$data     = array();
		foreach ( array_slice( $rows, 0, self::PAGE_SIZE ) as $row ) {
			$items = array();
			foreach ( $config['fields'] as $column => $label ) {
				if ( ! isset( $row->$column ) || '' === (string) $row->$column ) {
					continue;
				}
				$items[] = array( 'name' => $label, 'value' => self::plain_text( $row->$column ) );
			}
			if ( array() === $items ) {
				continue;
			}
			$data[] = array(
				'group_id'    => 'tzfd-' . $key,
				'group_label' => $config['label'],
				'item_id'     => $key . '-' . absint( isset( $row->id ) ? $row->id : 0 ),
				'data'        => $items,
			);
		}
		return array( 'data' => $data, 'done' => ! $has_more );
	}

	/** Export one bounded page of booking records for an exact email address.
	 *
	 * @param string $email_address Email address supplied by WordPress privacy tools.
	 * @param int    $page          One-based page number.
	 * @return array|WP_Error
	 */
	public static function export( $email_address, $page = 1 ) {
		global $wpdb;

		// Validate identity before sanitizing: stripping characters must never
		// turn an invalid input into a different customer's valid address.
		if ( ! is_string( $email_address ) ) { return array( 'data' => array(), 'done' => true ); }
		$email_address = strtolower( trim( $email_address ) );
		if ( '' === $email_address || ! is_email( $email_address ) || sanitize_email( $email_address ) !== $email_address ) {
			return array( 'data' => array(), 'done' => true );
		}
		$max_page = intdiv( PHP_INT_MAX, self::PAGE_SIZE );
		if ( ( ! is_int( $page ) && ! is_string( $page ) ) ||
			false === filter_var( $page, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1, 'max_range' => $max_page ) ) ) ) {
			return new WP_Error( 'titan_zero_front_desk_invalid_export_page', __( 'Invalid booking export page.', 'titan-zero-trades-front-desk' ) );
		}
		$page = (int) $page;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_results' ) ||
			! method_exists( $wpdb, 'get_row' ) || ! isset( $wpdb->prefix ) || ! is_string( $wpdb->prefix ) || '' === $wpdb->prefix ) {
			return self::storage_error();
		}

		$table  = $wpdb->prefix . 'tzfd_messages';
		$tzfd_offset = ( $page - 1 ) * self::PAGE_SIZE;
		// Validate this fixed WordPress table identifier before placing it in SQL.
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			return self::storage_error();
		}
		$tzfd_rows = $wpdb->get_results(
			$wpdb->prepare("SELECT id, formid, time, notifyto, ipaddr, posted_data FROM %i WHERE LOWER(notifyto) = %s ORDER BY id ASC LIMIT %d OFFSET %d", $table, $email_address, self::PAGE_SIZE + 1, $tzfd_offset)
		);
		if ( ! is_array( $tzfd_rows ) || ! empty( $wpdb->last_error ) ) {
			return self::storage_error();
		}

		$has_more = count( $tzfd_rows ) > self::PAGE_SIZE;
		$tzfd_rows     = array_slice( $tzfd_rows, 0, self::PAGE_SIZE );
		$forms    = array();
		$tzfd_data     = array();
		foreach ( $tzfd_rows as $row ) {
			$booking = self::decode_booking( isset( $row->posted_data ) ? $row->posted_data : null );
			if ( null === $booking ) {
				continue;
			}

			$form_id = isset( $row->formid ) ? absint( $row->formid ) : 0;
			if ( ! array_key_exists( $form_id, $forms ) ) {
				$forms[ $form_id ] = self::get_form( $form_id );
			}
			$tzfd_form = $forms[ $form_id ];
			if ( $tzfd_form instanceof WP_Error ) { return $tzfd_form; }
			if ( null === $tzfd_form ) {
				continue;
			}

			$fields = self::submitted_fields( $booking, $tzfd_form['fields'] );
			$ip_address = isset( $row->ipaddr ) ? self::plain_text( $row->ipaddr ) : '';
			if ( '' !== $ip_address ) {
				$fields[] = array( 'name' => __( 'IP address', 'titan-zero-trades-front-desk' ), 'value' => $ip_address );
			}
			$tzfd_data[] = array(
				'group_id'    => 'tzfd-bookings',
				'group_label' => __( 'Titan Zero Trades: Front Desk bookings', 'titan-zero-trades-front-desk' ),
				'item_id'     => 'booking-' . absint( isset( $row->id ) ? $row->id : 0 ),
				'data'        => array_merge(
					array(
						array( 'name' => __( 'Booking record', 'titan-zero-trades-front-desk' ), 'value' => absint( isset( $row->id ) ? $row->id : 0 ) ),
						array( 'name' => __( 'Submitted', 'titan-zero-trades-front-desk' ), 'value' => self::plain_text( isset( $row->time ) ? $row->time : '' ) ),
						array( 'name' => __( 'Booking form', 'titan-zero-trades-front-desk' ), 'value' => $tzfd_form['name'] ),
					),
					$fields
				),
			);
		}

		return array( 'data' => $tzfd_data, 'done' => ! $has_more );
	}

	/** Decode only array/scalar legacy payloads; never restore PHP objects. */
	private static function decode_booking( $serialized ) {
		if ( ! is_string( $serialized ) || '' === $serialized ) {
			return null;
		}
		$booking = TZFD_Stored_Data::decode($serialized);
		if ( ! is_array( $booking ) || ! self::is_safe_value( $booking ) ) {
			return null;
		}
		return $booking;
	}

	/** Reject incomplete-class objects, resources and excessive nesting. */
	private static function is_safe_value( $tzfd_value, $depth = 0 ) {
		if ( $depth > 16 ) {
			return false;
		}
		if ( is_array( $tzfd_value ) ) {
			foreach ( $tzfd_value as $tzfd_key => $child ) {
				if ( ! is_int( $tzfd_key ) && ! is_string( $tzfd_key ) ) {
					return false;
				}
				if ( ! self::is_safe_value( $child, $depth + 1 ) ) {
					return false;
				}
			}
			return true;
		}
		return is_scalar( $tzfd_value ) || null === $tzfd_value;
	}

	/** Load one form's labels and field names from the legacy form table. */
	private static function get_form( $form_id ) {
		global $wpdb;
		if ( ! $form_id ) {
			return null;
		}
		$table = $wpdb->prefix . 'tzfd_forms';
		$row   = $wpdb->get_row( $wpdb->prepare("SELECT form_name, form_structure FROM %i WHERE id = %d", $table, $form_id) );
		if ( ! empty( $wpdb->last_error ) ) { return self::storage_error(); }
		if ( ! is_object( $row ) || ! isset( $row->form_structure ) ) {
			return null;
		}

		$structure = json_decode( str_replace( '&qquot;', '"', (string) $row->form_structure ), true );
		if ( ! is_array( $structure ) || empty( $structure[0] ) || ! is_array( $structure[0] ) ) {
			return null;
		}
		$fields = array();
		foreach ( $structure[0] as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) || empty( $field['title'] ) || ! is_string( $field['name'] ) || ! is_string( $field['title'] ) ) {
				continue;
			}
			$type = isset( $field['ftype'] ) && is_string( $field['ftype'] ) ? strtolower( $field['ftype'] ) : '';
			if ( in_array( $type, array( 'fhidden', 'fpassword', 'fcaptcha', 'fhtml', 'fsection', 'fbutton', 'fsubmit' ), true ) ) {
				continue;
			}
			$fields[ $field['name'] ] = sanitize_text_field( $field['title'] );
		}
		if ( empty( $fields ) ) {
			return null;
		}
		return array(
			'name'   => self::plain_text( isset( $row->form_name ) ? $row->form_name : '' ),
			'fields' => $fields,
		);
	}

	/** Return only configured customer form fields, excluding internal metadata. */
	private static function submitted_fields( $booking, $field_labels ) {
		$tzfd_data = array();
		foreach ( $field_labels as $field_name => $tzfd_label ) {
			if ( ! array_key_exists( $field_name, $booking ) || ! self::is_safe_value( $booking[ $field_name ] ) ) {
				continue;
			}
			$tzfd_value = self::export_value( $booking[ $field_name ] );
			if ( null === $tzfd_value || '' === $tzfd_value || array() === $tzfd_value ) {
				continue;
			}
			// WordPress' HTML export formatter expects a string, not an array.
			// JSON preserves nested checkbox values without flattening them away.
			if ( is_array( $tzfd_value ) ) {
				$tzfd_value = wp_json_encode( $tzfd_value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
				if ( false === $tzfd_value ) { $tzfd_value = ''; }
			}
			$tzfd_data[] = array( 'name' => $tzfd_label, 'value' => $tzfd_value );
		}
		return $tzfd_data;
	}

	/** Normalize submitted values for the WordPress privacy exporter format. */
	private static function export_value( $tzfd_value ) {
		if ( is_array( $tzfd_value ) ) {
			$values = array();
			foreach ( $tzfd_value as $tzfd_key => $child ) {
				$values[ $tzfd_key ] = self::export_value( $child );
			}
			return $values;
		}
		return is_scalar( $tzfd_value ) ? self::plain_text( $tzfd_value ) : null;
	}

	private static function storage_error() {
		return new WP_Error( 'titan_zero_front_desk_export_unavailable', __( 'Booking export data is temporarily unavailable. Please retry.', 'titan-zero-trades-front-desk' ) );
	}

	private static function plain_text( $tzfd_value ) {
		return sanitize_text_field( is_scalar( $tzfd_value ) ? (string) $tzfd_value : '' );
	}
}
