<?php
/**
 * Runtime authorisation and tenant tests for issue #8 (not shipped in the plugin).
 * Run: wp --allow-root eval-file tests/runtime/security-runtime-tests.php
 * Requires the plugin active on the disposable test site (see WP-TESTSITE.md).
 */
$tzfd_local_inc = WP_PLUGIN_DIR . '/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-tables.php', 'class-tzfd-intake-mode.php', 'class-tzfd-final-booking-write.php', 'class-tzfd-transaction-storage.php', 'class-tzfd-booking-create-transaction.php', 'class-tzfd-privacy-exporter.php', 'class-tzfd-signed-request.php' ) as $tzfd_local_file ) {
    require_once $tzfd_local_inc . $tzfd_local_file;
}
global $wpdb;
$GLOBALS['sr_pass'] = 0; $GLOBALS['sr_fail'] = 0;
function sr_check( $name, $ok, $detail = '' ) {
    if ( $ok ) { $GLOBALS['sr_pass']++; } else { $GLOBALS['sr_fail']++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

$company = (int) TZFD_Scoped_Store::company_id();
sr_check( 'precondition: company resolves', $company > 0, 'company=' . $company );
$table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$tag   = substr( md5( uniqid( '', true ) ), 0, 10 );

// Own-company booking (through the real create path) and a booking owned by another tenant.
$email = 'sr-' . $tag . '@example.test';
$params = array( 'formid' => 1, 'name' => 'SR Customer', 'email' => $email, 'notes' => 'private note', 'apps' => array( array( 'cancelled' => '' ) ) );
$row = array( 'formid' => 1, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1', 'notifyto' => $email,
    'posted_data' => serialize( $params ), 'data' => 'SR', 'whoadded' => '1' );
wp_set_current_user( 1 );
$res = TZFD_Booking_Create_Transaction::create( $wpdb, $company, $row, $params, TZFD_Intake_Mode::MODE_INSTANT );
$own_id = (int) ( $res['id'] ?? 0 );
sr_check( 'setup: own-company booking created', $own_id > 0 );

$foreign_company = $company + 1000;
$wpdb->insert( $table, array( 'formid' => 1, 'time' => current_time( 'mysql' ), 'ipaddr' => '127.0.0.1',
    'notifyto' => 'other-' . $tag . '@example.test', 'posted_data' => serialize( array( 'apps' => array() ) ),
    'data' => 'OTHER', 'whoadded' => '1', 'company_id' => $foreign_company ) );
$foreign_id = (int) $wpdb->insert_id;
sr_check( 'setup: foreign-tenant booking inserted', $foreign_id > 0 );

function sr_get( $id ) {
    $req = new WP_REST_Request( 'GET', '/titan-zero-front-desk/v1/bookings/' . $id );
    $req->set_body( '' ); // a real HTTP GET has an empty string body, not null
    return rest_do_request( $req );
}

// 1. Anonymous: refused before the callback.
wp_set_current_user( 0 );
$r = sr_get( $own_id );
sr_check( 'anonymous cannot read booking status', $r->get_status() >= 401 && $r->get_status() < 500, 'HTTP ' . $r->get_status() );

// 2. Logged-in subscriber (no staff capability): refused.
$sub = wp_create_user( 'sr-sub-' . $tag, wp_generate_password( 20 ), 'sub-' . $tag . '@example.test' );
$sub_user = new WP_User( (int) $sub );
$sub_user->set_role( 'subscriber' );
wp_set_current_user( (int) $sub );
$r = sr_get( $own_id );
sr_check( 'subscriber without tz_fd_access is refused', 403 === $r->get_status(), 'HTTP ' . $r->get_status() );

// 3. Staff (administrator) reads its own tenant's booking, with minimised data.
wp_set_current_user( 1 );
$r = sr_get( $own_id );
sr_check( 'administrator reads own-company booking', 200 === $r->get_status(), 'HTTP ' . $r->get_status() );
$json = wp_json_encode( $r->get_data() );
sr_check( 'response excludes customer email', false === strpos( $json, $email ) );
sr_check( 'response excludes submitted note', false === strpos( $json, 'private note' ) );
$keys = is_array( $r->get_data() ) ? array_keys( $r->get_data() ) : array();
sort( $keys );
sr_check( 'response has only the minimised keys', array( 'appointment_statuses', 'booking_id', 'created_at', 'form_id', 'schema_version' ) === $keys, implode( ',', $keys ) );

// 4. Tenant isolation: another company's booking id reads as not found, not forbidden-with-data.
$r = sr_get( $foreign_id );
sr_check( 'foreign-tenant booking id is not disclosed', 404 === $r->get_status(), 'HTTP ' . $r->get_status() );
sr_check( 'foreign-tenant response carries no booking fields', ! isset( $r->get_data()['created_at'] ) );

// 5. Forged or malformed ids are refused without touching the database.
foreach ( array( '0', '-1', '1abc', '1%20OR%201=1' ) as $bad ) {
    $req = new WP_REST_Request( 'GET', '/titan-zero-front-desk/v1/bookings/' . $bad );
    $req->set_body( '' );
    $r = rest_do_request( $req );
    sr_check( 'malformed id refused: ' . $bad, $r->get_status() >= 400 && $r->get_status() < 500, 'HTTP ' . $r->get_status() );
}

// 6. Atomic replay claim against the real options table.
require_once ABSPATH . 'wp-includes/option.php';
$sig_a = 'sha256=' . bin2hex( random_bytes( 32 ) );
sr_check( 'claim: first use of a fresh signature wins', TZFD_Signed_Request::claim_signature( $sig_a ) );
sr_check( 'claim: second use of the same signature is refused', false === TZFD_Signed_Request::claim_signature( $sig_a ) );
$opt_a = TZFD_Signed_Request::claim_key( $sig_a );
update_option( $opt_a, (string) ( time() - 700 ), false );
sr_check( 'claim: expired record is reclaimed once', TZFD_Signed_Request::claim_signature( $sig_a ) );
sr_check( 'claim: reclaimed record refuses an immediate repeat', false === TZFD_Signed_Request::claim_signature( $sig_a ) );
delete_option( $opt_a );

// Clean up test rows and user.
$wpdb->delete( $table, array( 'id' => $own_id ) );
$wpdb->delete( $table, array( 'id' => $foreign_id ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( (int) $sub );
wp_set_current_user( 0 );

echo "\nSummary: " . $GLOBALS['sr_pass'] . ' passed, ' . $GLOBALS['sr_fail'] . " failed\n";
