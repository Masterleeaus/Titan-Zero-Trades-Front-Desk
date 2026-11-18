<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/**
 * WordPress personal-data export and erase support for the plugin's own tables that hold personal data: bookings,
 * website enquiries and their triage notes, local customers, missed-call and text-reply records, queued reminders and
 * customer emails, booking-assistant chats from earlier versions, and client-portal sign-in records. Customer, quote and
 * payment records belong to Titan Zero Trades: Front Desk, Quotes and Invoicing (19 Oct split).
 *
 * Coverage is listed in email_tables(). Every table that holds personal data is either listed there or is documented
 * in no_personal_data_tables() with the reason it holds none.
 *
 * @package TZFDFrontDesk
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
require_once __DIR__ . '/class-tzfd-stored-data.php';
require_once __DIR__ . '/class-tzfd-tables.php';
require_once __DIR__ . '/class-tzfd-contact-rules.php';


/** Export and erase the personal data this plugin stores, matched by an exact email address (and by phone numbers recorded against it). */
class TZFD_Privacy_Exporter {
	const PAGE_SIZE   = 50;
	const ERASE_BATCH = 100;
	/** Plugin-owned tables outside the TZFD_Tables list, named as their owning classes name them. */
	const LOCAL_CUSTOMERS = 'tzfd_local_customers';
	const LOCAL_SERVICES  = 'tzfd_local_services';
	const EVENT_OUTBOX    = 'tzfd_customer_event_outbox';

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
			__( 'Scheduling & Enquiries Manager', 'titan-zero-trades-front-desk' ),
			wp_kses_post( self::policy_text() )
		);
	}

	/** Suggested privacy policy text. Describes what the plugin stores and each outside service it can contact. */
	private static function policy_text() {
		$sections = array(
			array(
				__( 'Bookings and booking forms', 'titan-zero-trades-front-desk' ),
				__( 'Each booking or form submission stores what the customer entered, such as name, email address, phone number, address, booking date and time, and the answers to the form fields. While the setting "Store user\'s IP address?" is on (it is on by default), the IP address of the request is stored with the booking too. The activity log records booking changes with the booking number, the time and the logged-in user who made the change. Booking records are kept until you delete them in Scheduling & Enquiries Manager. Calendar invitation files that contain booking details are saved in your uploads folder and are normally deleted once the email is sent.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Enquiries, customers and staff contacts', 'titan-zero-trades-front-desk' ),
				__( 'Website enquiries store the name, email address, phone number, preferred contact method and enquiry text. Enquiry triage stores the intent, urgency and spam assessment for each enquiry, a draft quote, and a hashed IP address. Local customer records store the name, email address, phone number, address and notes you enter. The staff contact list stores the name, phone number and email address of each staff member you add. These records are kept until you delete them in Scheduling & Enquiries Manager.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Client portal', 'titan-zero-trades-front-desk' ),
				__( 'When a client asks for a sign-in link, their email address is stored with the one-time link for 20 minutes and with their session for 2 hours. A cookie named tz_fd_portal_session holds the session token. After these times the records stop working, and WordPress removes them during its routine cleanup.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Missed-call texts, text replies and reminders', 'titan-zero-trades-front-desk' ),
				__( 'If missed-call texts are turned on, the caller\'s phone number is sent to the SMS gateway you set up. The site counts texts per number for 24 hours, under a hashed key, to limit how many are sent. The missed-call log keeps only the last four digits. The callback and reply records keep the caller\'s full phone number, and the text-conversation records keep only a hashed key, turn count and opt-out flag. When a caller replies STOP, UNSUBSCRIBE, END, CANCEL or QUIT, the full number is added to an opt-out list kept in your WordPress options, and no more texts are sent until the caller replies START. The reminder and customer-email queues refer to bookings by number and hold the customer email address for messages that have not yet been sent. If a webhook is turned on, each booking event sends the booking details to the address you enter.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Captcha', 'titan-zero-trades-front-desk' ),
				__( 'The captcha sets a cookie named rand_code and a temporary record. Both expire after 30 minutes.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Exporting and erasing', 'titan-zero-trades-front-desk' ),
				__( 'The WordPress export tool includes the bookings, enquiries, enquiry triage notes, local customer records, callback and reply records, queued reminders and customer emails, and booking-assistant chats from earlier versions that match the email address requested. Records that are matched by phone number are found through the phone numbers saved against that address. The WordPress erase tool deletes these matching records, the client-portal sign-in links and sessions for that address, and the booking records that match it. Deleted records cannot be recovered. The phone-number opt-out list is kept as a WordPress option and is not matched by email address, so it is neither exported nor erased. Staff contacts are not deleted by the erase tool.', 'titan-zero-trades-front-desk' ),
			),
			array(
				__( 'Deleting the plugin', 'titan-zero-trades-front-desk' ),
				__( 'Deleting the plugin does not delete bookings or settings unless you add define( \'TZ_DELETE_DATA_ON_UNINSTALL\', true ); to wp-config.php before you delete it. Chat photos and calendar invitation files are removed when the plugin is deleted.', 'titan-zero-trades-front-desk' ),
			),
		);

		// Outside services: the same list as the readme's External services section.
		$services = array(
			array(
				__( 'An SMS gateway you choose, and your phone system. When a call is missed, the caller\'s phone number and one text message with the booking link are sent to the gateway address you enter. The plugin does not include a gateway or phone provider, so check that provider\'s terms and privacy policy before you turn this on.', 'titan-zero-trades-front-desk' ),
				array(),
			),
			array(
				__( 'A web page you name in the setup wizard for service import. The site makes one ordinary request for that page and reads its list items once. No customer data is sent. The page owner\'s terms and privacy policy apply.', 'titan-zero-trades-front-desk' ),
				array(),
			),
			array(
				__( 'A webhook endpoint you enter. Each booking created or booking status update sends a signed message with the booking details. If the endpoint does not accept it, the plugin retries, making up to 5 attempts in total. The receiving service\'s terms and privacy policy apply.', 'titan-zero-trades-front-desk' ),
				array(),
			),
			array(
				__( 'WhatsApp and SMS buttons below the booking form. These are links, not a connection: when a visitor taps one, their own phone or browser opens WhatsApp or their messaging app. The site does not contact WhatsApp.', 'titan-zero-trades-front-desk' ),
				array( 'WhatsApp' => 'https://www.whatsapp.com/legal/privacy-policy' ),
			),
		);

		$html = '<p>' . esc_html__( 'Scheduling & Enquiries Manager stores the personal data below in your WordPress database. It does not create an account, collect a licence key or report usage to its author, and nothing is sent to the plugin author.', 'titan-zero-trades-front-desk' ) . '</p>';
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
			'eraser_friendly_name' => __( 'Scheduling & Enquiries Manager records', 'titan-zero-trades-front-desk' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Plugin-owned tables that hold personal data for the requested address. Each entry:
	 * - label:  name shown in exports and erase messages.
	 * - table:  table name without the WordPress prefix.
	 * - where:  function( $email, $identity ) returning array( SQL with %i/%s placeholders, values ). Used for both export and erase.
	 * - fields: column => export label. Empty values are skipped.
	 * - extra:  optional function( $row ) returning more export items.
	 *
	 * Order matters for erase: enquiry triage is listed before the enquiries it refers to.
	 *
	 * @return array
	 */
	private static function email_tables() {
		return array(
			'enquiry_triage'        => array(
				'label'  => __( 'Enquiry triage notes', 'titan-zero-trades-front-desk' ),
				'table'  => TZFD_Tables::ENQUIRY_TRIAGE,
				'where'  => function ( $email ) {
					global $wpdb;
					return array( 'enquiry_id IN (SELECT id FROM %i WHERE LOWER(email) = %s)', array( $wpdb->prefix . TZFD_Tables::ENQUIRIES, $email ) );
				},
				'fields' => array(
					'primary_intent' => __( 'Main intent', 'titan-zero-trades-front-desk' ),
					'intents'        => __( 'Intents', 'titan-zero-trades-front-desk' ),
					'urgency'        => __( 'Urgency', 'titan-zero-trades-front-desk' ),
					'spam_state'     => __( 'Spam check', 'titan-zero-trades-front-desk' ),
					'route'          => __( 'Route', 'titan-zero-trades-front-desk' ),
					'quote_draft'    => __( 'Draft quote', 'titan-zero-trades-front-desk' ),
					'created'        => __( 'Created', 'titan-zero-trades-front-desk' ),
				),
			),
			'enquiries'             => array(
				'label'  => __( 'Website enquiries', 'titan-zero-trades-front-desk' ),
				'table'  => TZFD_Tables::ENQUIRIES,
				'where'  => function ( $email ) {
					return array( 'LOWER(email) = %s', array( $email ) );
				},
				'fields' => array(
					'name'              => __( 'Name', 'titan-zero-trades-front-desk' ),
					'email'             => __( 'Email address', 'titan-zero-trades-front-desk' ),
					'phone'             => __( 'Phone number', 'titan-zero-trades-front-desk' ),
					'preferred_contact' => __( 'Preferred contact', 'titan-zero-trades-front-desk' ),
					'enquiry'           => __( 'Enquiry', 'titan-zero-trades-front-desk' ),
					'source'            => __( 'Source', 'titan-zero-trades-front-desk' ),
					'status'            => __( 'Status', 'titan-zero-trades-front-desk' ),
					'received_utc'      => __( 'Received (UTC)', 'titan-zero-trades-front-desk' ),
				),
			),
			'local_customers'       => array(
				'label'  => __( 'Local customer records', 'titan-zero-trades-front-desk' ),
				'table'  => self::LOCAL_CUSTOMERS,
				'where'  => function ( $email ) {
					return array( 'LOWER(email) = %s', array( $email ) );
				},
				'fields' => array(
					'name'       => __( 'Name', 'titan-zero-trades-front-desk' ),
					'email'      => __( 'Email address', 'titan-zero-trades-front-desk' ),
					'phone'      => __( 'Phone number', 'titan-zero-trades-front-desk' ),
					'address'    => __( 'Address', 'titan-zero-trades-front-desk' ),
					'notes'      => __( 'Notes', 'titan-zero-trades-front-desk' ),
					'created_at' => __( 'Created (UTC)', 'titan-zero-trades-front-desk' ),
				),
			),
			'missed_call_actions'   => array(
				'label'  => __( 'Missed-call callbacks and text replies', 'titan-zero-trades-front-desk' ),
				'table'  => TZFD_Tables::CONTACT_ACTIONS,
				'where'  => function ( $email, $identity ) {
					return self::in_clause( 'phone', $identity['phones'] );
				},
				'fields' => array(
					'kind'         => __( 'Type', 'titan-zero-trades-front-desk' ),
					'source'       => __( 'Source', 'titan-zero-trades-front-desk' ),
					'phone'        => __( 'Phone number', 'titan-zero-trades-front-desk' ),
					'match_status' => __( 'Customer match', 'titan-zero-trades-front-desk' ),
					'reason'       => __( 'Reason', 'titan-zero-trades-front-desk' ),
					'state'        => __( 'State', 'titan-zero-trades-front-desk' ),
					'created_at'   => __( 'Created (UTC)', 'titan-zero-trades-front-desk' ),
				),
			),
			'sms_conversations'     => array(
				'label'  => __( 'Text-message conversations', 'titan-zero-trades-front-desk' ),
				'table'  => TZFD_Tables::SMS_CONVERSATIONS,
				'where'  => function ( $email, $identity ) {
					return self::in_clause( 'conversation_key', $identity['keys'] );
				},
				'fields' => array(
					'turns'         => __( 'Automated replies sent', 'titan-zero-trades-front-desk' ),
					'opted_out'     => __( 'Opted out of texts', 'titan-zero-trades-front-desk' ),
					'window_started' => __( 'Conversation started (UTC)', 'titan-zero-trades-front-desk' ),
					'updated_at'    => __( 'Updated (UTC)', 'titan-zero-trades-front-desk' ),
				),
			),
			'reminder_outbox'       => array(
				'label'  => __( 'Queued booking reminders', 'titan-zero-trades-front-desk' ),
				'table'  => TZFD_Tables::BOOKING_ACTIONS,
				'where'  => function ( $email ) {
					global $wpdb;
					return array( 'booking_id IN (SELECT id FROM %i WHERE LOWER(notifyto) = %s)', array( $wpdb->prefix . TZFD_Tables::BOOKINGS, $email ) );
				},
				'fields' => array(
					'booking_id'   => __( 'Booking record', 'titan-zero-trades-front-desk' ),
					'action_type'  => __( 'Action', 'titan-zero-trades-front-desk' ),
					'state'        => __( 'State', 'titan-zero-trades-front-desk' ),
					'available_at' => __( 'Due (UTC)', 'titan-zero-trades-front-desk' ),
				),
			),
			'customer_event_outbox' => array(
				'label'  => __( 'Queued customer emails', 'titan-zero-trades-front-desk' ),
				'table'  => self::EVENT_OUTBOX,
				'where'  => function ( $email ) {
					global $wpdb;
					return array(
						'(booking_id IN (SELECT id FROM %i WHERE LOWER(notifyto) = %s) OR LOWER(payload) LIKE %s)',
						array( $wpdb->prefix . TZFD_Tables::BOOKINGS, $email, '%"to":"' . $wpdb->esc_like( $email ) . '"%' ),
					);
				},
				'fields' => array(
					'booking_id'   => __( 'Booking record', 'titan-zero-trades-front-desk' ),
					'event_type'   => __( 'Email type', 'titan-zero-trades-front-desk' ),
					'state'        => __( 'State', 'titan-zero-trades-front-desk' ),
					'available_at' => __( 'Due (UTC)', 'titan-zero-trades-front-desk' ),
				),
				'extra'  => function ( $row ) {
					$payload = json_decode( (string) $row->payload, true );
					if ( ! is_array( $payload ) ) {
						return array();
					}
					$items = array();
					foreach ( array( 'to' => __( 'Sent to', 'titan-zero-trades-front-desk' ), 'subject' => __( 'Subject', 'titan-zero-trades-front-desk' ), 'body' => __( 'Message', 'titan-zero-trades-front-desk' ) ) as $name => $label ) {
						if ( isset( $payload[ $name ] ) && is_string( $payload[ $name ] ) && '' !== $payload[ $name ] ) {
							$items[] = array( 'name' => $label, 'value' => self::plain_text( $payload[ $name ] ) );
						}
					}
					return $items;
				},
			),
		);
	}

	/**
	 * Plugin tables that were checked and hold no personal data, with the reason. A table listed here is not exported
	 * or erased. Tests fail if a table in this list gains a contact column.
	 *
	 * @return array table name => reason
	 */
	public static function no_personal_data_tables() {
		return array(
			self::LOCAL_SERVICES => 'Service catalogue only: name, description, price, duration, GST flag and archived flag. No column identifies a person.',
		);
	}

	/** Add this exporter to the WordPress privacy tools registry.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['tzfd-bookings'] = array(
			'exporter_friendly_name' => __( 'Scheduling & Enquiries Manager bookings', 'titan-zero-trades-front-desk' ),
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

	/** Export one bounded page of an email-keyed or phone-keyed table for an exact email address.
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

		$table = $wpdb->prefix . $config['table'];
		$ready = self::table_state( $table );
		if ( null === $ready ) {
			return self::storage_error();
		}
		// A table that is not installed holds no records for this address.
		if ( ! $ready ) {
			return array( 'data' => array(), 'done' => true );
		}
		$identity = self::identity( $email_address );
		if ( false === $identity ) {
			return self::storage_error();
		}
		list( $sql, $values ) = call_user_func( $config['where'], $email_address, $identity );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE ' . $sql . ' ORDER BY id ASC LIMIT %d OFFSET %d',
				...array_merge( array( $table ), $values, array( self::PAGE_SIZE + 1, ( $page - 1 ) * self::PAGE_SIZE ) )
			)
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
			if ( isset( $config['extra'] ) ) {
				$items = array_merge( $items, call_user_func( $config['extra'], $row ) );
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

	/**
	 * Erase the plugin's personal data for an exact email address.
	 *
	 * Booking records are deleted in batches, with their queued reminders and customer emails in the same batch.
	 * WordPress calls again while done is false. Once every booking is gone, the other tables listed in
	 * email_tables() are cleared for the address, then the client-portal sign-in records.
	 *
	 * @param string $email_address Email address supplied by WordPress privacy tools.
	 * @param int    $page          One-based page number.
	 * @return array
	 */
	public static function erase( $email_address, $page = 1 ) {
		global $wpdb;
		$response = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );

		// Same identity rule as export: invalid input matches nothing.
		if ( ! is_string( $email_address ) || ! is_int( $page ) || $page < 1 ) {
			return $response;
		}
		$email_address = strtolower( trim( $email_address ) );
		if ( '' === $email_address || ! is_email( $email_address ) || sanitize_email( $email_address ) !== $email_address ) {
			return $response;
		}
		if ( ! is_object( $wpdb ) || ! isset( $wpdb->prefix, $wpdb->options ) || ! is_string( $wpdb->prefix ) || '' === $wpdb->prefix ) {
			return self::erase_unavailable();
		}

		// Phone numbers are read before any row is removed, because some tables are matched through them.
		$identity = self::identity( $email_address );
		if ( false === $identity ) {
			return self::erase_unavailable();
		}

		$removed = array();
		$table   = $wpdb->prefix . TZFD_Tables::BOOKINGS;
		$state   = self::table_state( $table );
		if ( null === $state ) {
			return self::erase_unavailable();
		}
		if ( $state ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				'SELECT id FROM %i WHERE LOWER(notifyto) = %s ORDER BY id ASC LIMIT %d',
				$table,
				$email_address,
				self::ERASE_BATCH + 1
			) );
			if ( ! is_array( $ids ) || ! empty( $wpdb->last_error ) ) {
				return self::erase_unavailable();
			}
			$has_more = count( $ids ) > self::ERASE_BATCH;
			$batch    = array_map( 'absint', array_slice( $ids, 0, self::ERASE_BATCH ) );
			if ( array() !== $batch ) {
				$dependents = self::delete_booking_dependents( $batch );
				if ( false === $dependents ) {
					return self::erase_unavailable( $removed );
				}
				$removed = self::add_counts( $removed, $dependents );
			}
			foreach ( $batch as $booking_id ) {
				$wpdb->last_error = '';
				$deleted = $wpdb->delete( $table, array( 'id' => $booking_id ), array( '%d' ) );
				if ( 1 !== $deleted || ! empty( $wpdb->last_error ) ) {
					// Report what was removed and ask WordPress to retry; the rest of the batch is left in place.
					return self::erase_unavailable( $removed );
				}
				$removed['bookings'] = isset( $removed['bookings'] ) ? $removed['bookings'] + 1 : 1;
				do_action( 'tz_fd_item_deleted', $booking_id );
			}
			$response['done'] = ! $has_more;
		}
		if ( ! $response['done'] ) {
			return self::erase_response( $removed, false );
		}

		// Every booking for this address is gone. Clear the other tables, in the order listed in email_tables().
		foreach ( self::email_tables() as $key => $config ) {
			$table_name = $wpdb->prefix . $config['table'];
			$state      = self::table_state( $table_name );
			if ( null === $state ) {
				return self::erase_unavailable( $removed );
			}
			if ( ! $state ) {
				continue;
			}
			list( $sql, $values ) = call_user_func( $config['where'], $email_address, $identity );
			$wpdb->last_error     = '';
			$count                = $wpdb->query( $wpdb->prepare(
				'DELETE FROM %i WHERE ' . $sql,
				...array_merge( array( $table_name ), $values )
			) );
			if ( false === $count || ! empty( $wpdb->last_error ) ) {
				return self::erase_unavailable( $removed );
			}
			$removed[ $key ] = (int) $count;
		}

		// Client-portal sign-in links and sessions store a serialized array whose 'email' entry is the address. Their keys are hashed,
		// so candidates are found by the quoted address inside the serialized text, then each one is decoded and compared exactly.
		$portal = 0;
		foreach ( array( 'tz_fd_portal_link_', 'tz_fd_portal_session_' ) as $prefix ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT option_name, option_value FROM %i WHERE option_name LIKE %s AND option_value LIKE %s",
				$wpdb->options,
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				'%' . $wpdb->esc_like( '"' . $email_address . '"' ) . '%'
			) );
			if ( ! is_array( $rows ) || ! empty( $wpdb->last_error ) ) {
				return self::erase_unavailable( $removed );
			}
			foreach ( $rows as $row ) {
				$identity_row = TZFD_Stored_Data::decode( isset( $row->option_value ) ? $row->option_value : null );
				if ( null === $identity_row || ! isset( $identity_row['email'] ) || ! is_string( $identity_row['email'] ) ||
					strtolower( trim( $identity_row['email'] ) ) !== $email_address ) {
					continue;
				}
				if ( delete_transient( substr( (string) $row->option_name, strlen( '_transient_' ) ) ) ) {
					++$portal;
				}
			}
		}
		if ( $portal > 0 ) {
			$removed['portal'] = $portal;
		}
		return self::erase_response( $removed, true );
	}

	/**
	 * Delete the queued reminders and customer emails for one batch of bookings, before the bookings themselves go.
	 *
	 * @param int[] $booking_ids Booking ids in this batch.
	 * @return array|false Counts by table key, or false when a delete fails.
	 */
	private static function delete_booking_dependents( array $booking_ids ) {
		global $wpdb;
		$counts       = array();
		$placeholders = implode( ', ', array_fill( 0, count( $booking_ids ), '%d' ) );
		foreach ( array( 'reminder_outbox' => TZFD_Tables::BOOKING_ACTIONS, 'customer_event_outbox' => self::EVENT_OUTBOX ) as $key => $bare ) {
			$table = $wpdb->prefix . $bare;
			$state = self::table_state( $table );
			if ( null === $state ) {
				return false;
			}
			if ( ! $state ) {
				continue;
			}
			$wpdb->last_error = '';
			$count            = $wpdb->query( $wpdb->prepare(
				'DELETE FROM %i WHERE booking_id IN (' . $placeholders . ')',
				...array_merge( array( $table ), $booking_ids )
			) );
			if ( false === $count || ! empty( $wpdb->last_error ) ) {
				return false;
			}
			$counts[ $key ] = (int) $count;
		}
		return $counts;
	}

	/** Build the erase response from counts keyed by table, in the same order as the export. */
	private static function erase_response( array $removed, $done ) {
		$response = array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => (bool) $done );
		$labels   = self::erase_labels();
		foreach ( $removed as $key => $count ) {
			if ( $count < 1 || ! isset( $labels[ $key ] ) ) {
				continue;
			}
			$response['items_removed'] = true;
			/* translators: 1: record group name, 2: number of records deleted. */
			$response['messages'][] = sprintf( _n( '%1$s: %2$d record deleted.', '%1$s: %2$d records deleted.', $count, 'titan-zero-trades-front-desk' ), $labels[ $key ], $count );
		}
		return $response;
	}

	/** Group names used in erase messages, by count key. */
	private static function erase_labels() {
		$labels = array(
			'bookings' => __( 'Booking records', 'titan-zero-trades-front-desk' ),
			'portal'   => __( 'Client-portal sign-in records', 'titan-zero-trades-front-desk' ),
		);
		foreach ( self::email_tables() as $key => $config ) {
			$labels[ $key ] = $config['label'];
		}
		return $labels;
	}

	/** Add two sets of counts by key. */
	private static function add_counts( array $total, array $more ) {
		foreach ( $more as $key => $count ) {
			$total[ $key ] = ( isset( $total[ $key ] ) ? $total[ $key ] : 0 ) + (int) $count;
		}
		return $total;
	}

	/**
	 * Phone numbers and conversation keys recorded against an address. Read from the enquiry and local customer
	 * records; keys are the hashes the text-message tables store for each of those numbers.
	 *
	 * @param string $email Lower-cased, validated email address.
	 * @return array{phones: string[], keys: string[]}|false
	 */
	private static function identity( $email ) {
		global $wpdb;
		$phones = array();
		foreach ( array( $wpdb->prefix . TZFD_Tables::ENQUIRIES, $wpdb->prefix . self::LOCAL_CUSTOMERS ) as $table ) {
			$state = self::table_state( $table );
			if ( null === $state ) {
				return false;
			}
			if ( ! $state ) {
				continue;
			}
			$raw = $wpdb->get_col( $wpdb->prepare( 'SELECT phone FROM %i WHERE LOWER(email) = %s', $table, $email ) );
			if ( ! is_array( $raw ) || ! empty( $wpdb->last_error ) ) {
				return false;
			}
			foreach ( $raw as $value ) {
				$e164 = TZFD_Contact_Rules::normalise_e164( (string) $value );
				if ( '' !== $e164 ) {
					$phones[ $e164 ] = true;
				}
			}
		}
		$phones = array_keys( $phones );

		$keys      = array();
		$sms_table = $wpdb->prefix . TZFD_Tables::SMS_CONVERSATIONS;
		if ( array() !== $phones ) {
			$state = self::table_state( $sms_table );
			if ( null === $state ) {
				return false;
			}
			if ( $state ) {
				$companies = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT company_id FROM %i', $sms_table ) );
				if ( ! is_array( $companies ) || ! empty( $wpdb->last_error ) ) {
					return false;
				}
				foreach ( $companies as $company_id ) {
					foreach ( $phones as $e164 ) {
						$keys[] = TZFD_Contact_Rules::conversation_key( (int) $company_id, $e164 );
					}
				}
			}
		}
		return array( 'phones' => $phones, 'keys' => $keys );
	}

	/** Build an IN clause for a list of values. An empty list matches nothing. */
	private static function in_clause( $column, array $values ) {
		if ( array() === $values ) {
			return array( '1 = 0', array() );
		}
		return array( $column . ' IN (' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ')', array_values( $values ) );
	}

	/**
	 * Whether a table exists.
	 *
	 * @return bool|null true or false, or null when the check itself failed.
	 */
	private static function table_state( $table ) {
		global $wpdb;
		$wpdb->last_error = '';
		$found            = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( ! empty( $wpdb->last_error ) ) {
			return null;
		}
		return $table === $found;
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

		$table  = $wpdb->prefix . TZFD_Tables::BOOKINGS;
		$tzfd_offset = ( $page - 1 ) * self::PAGE_SIZE;
		// Validate this fixed WordPress table identifier before placing it in SQL.
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			return self::storage_error();
		}
		$tzfd_local_rows = $wpdb->get_results(
			$wpdb->prepare("SELECT id, formid, time, notifyto, ipaddr, posted_data FROM %i WHERE LOWER(notifyto) = %s ORDER BY id ASC LIMIT %d OFFSET %d", $table, $email_address, self::PAGE_SIZE + 1, $tzfd_offset)
		);
		if ( ! is_array( $tzfd_local_rows ) || ! empty( $wpdb->last_error ) ) {
			return self::storage_error();
		}

		$has_more = count( $tzfd_local_rows ) > self::PAGE_SIZE;
		$tzfd_local_rows     = array_slice( $tzfd_local_rows, 0, self::PAGE_SIZE );
		$forms    = array();
		$tzfd_data     = array();
		foreach ( $tzfd_local_rows as $row ) {
			$booking = self::decode_booking( isset( $row->posted_data ) ? $row->posted_data : null );
			if ( null === $booking ) {
				continue;
			}

			$form_id = isset( $row->formid ) ? absint( $row->formid ) : 0;
			if ( ! array_key_exists( $form_id, $forms ) ) {
				$forms[ $form_id ] = self::get_form( $form_id );
			}
			$tzfd_local_form = $forms[ $form_id ];
			if ( $tzfd_local_form instanceof WP_Error ) { return $tzfd_local_form; }
			if ( null === $tzfd_local_form ) {
				// The calendar form is missing or unreadable: the booking's own stored fields are still this person's data.
				$tzfd_local_form = array( 'name' => __( 'Booking form no longer available', 'titan-zero-trades-front-desk' ), 'fields' => array() );
				foreach ( array_keys( $booking ) as $tzfd_key ) {
					$tzfd_local_form['fields'][ $tzfd_key ] = sanitize_text_field( (string) $tzfd_key );
				}
			}

			$fields = self::submitted_fields( $booking, $tzfd_local_form['fields'] );
			$ip_address = isset( $row->ipaddr ) ? self::plain_text( $row->ipaddr ) : '';
			if ( '' !== $ip_address ) {
				$fields[] = array( 'name' => __( 'IP address', 'titan-zero-trades-front-desk' ), 'value' => $ip_address );
			}
			$tzfd_data[] = array(
				'group_id'    => 'tzfd-bookings',
				'group_label' => __( 'Scheduling & Enquiries Manager bookings', 'titan-zero-trades-front-desk' ),
				'item_id'     => 'booking-' . absint( isset( $row->id ) ? $row->id : 0 ),
				'data'        => array_merge(
					array(
						array( 'name' => __( 'Booking record', 'titan-zero-trades-front-desk' ), 'value' => absint( isset( $row->id ) ? $row->id : 0 ) ),
						array( 'name' => __( 'Submitted', 'titan-zero-trades-front-desk' ), 'value' => self::plain_text( isset( $row->time ) ? $row->time : '' ) ),
						array( 'name' => __( 'Booking form', 'titan-zero-trades-front-desk' ), 'value' => $tzfd_local_form['name'] ),
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
		$table = $wpdb->prefix . TZFD_Tables::FORMS;
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

	/** Erase failed part-way: report what was removed, keep the rest, and ask for a retry. */
	private static function erase_unavailable( array $removed = array() ) {
		$response = self::erase_response( $removed, true );
		$response['items_retained'] = true;
		$response['messages'][]     = __( 'Scheduling & Enquiries Manager records could not be checked. Please retry.', 'titan-zero-trades-front-desk' );
		return $response;
	}

	private static function plain_text( $tzfd_value ) {
		return sanitize_text_field( is_scalar( $tzfd_value ) ? (string) $tzfd_value : '' );
	}
}
