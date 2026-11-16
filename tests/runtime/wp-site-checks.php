<?php
/**
 * On-site checks for a disposable WordPress test site. Run with WP-CLI:
 *
 *   wp --allow-root eval-file wp-site-checks.php seed   /tmp/tzfd-upgrade-manifest.json   # on 3.0.128, before upgrading
 *   wp --allow-root eval-file wp-site-checks.php verify /tmp/tzfd-upgrade-manifest.json   # on the new build, after upgrading
 *   wp --allow-root eval-file wp-site-checks.php lock-hold 1 8     # holds form 1's capacity lock for 8 seconds
 *   wp --allow-root eval-file wp-site-checks.php lock-try 1        # must report BUSY while lock-hold runs
 *
 * Not shipped in the plugin. Requires the plugin active at
 * /var/www/html/wp-content/plugins/titan-zero-trades-front-desk/ (same path as runtime-tests.php).
 * Each mode prints PASS/FAIL lines and exits non-zero on any failure.
 */

$tz_inc = '/var/www/html/wp-content/plugins/titan-zero-trades-front-desk/includes/';
foreach ( array( 'class-tzfd-tables.php', 'class-tzfd-capacity-lock.php', 'class-tzfd-stored-data.php', 'class-tzfd-migrations.php' ) as $tz_file ) {
    require_once $tz_inc . $tz_file;
}

global $wpdb;
$tz_mode  = isset( $args[0] ) ? $args[0] : '';
$tz_arg   = isset( $args[1] ) ? $args[1] : '';
$tz_table = $wpdb->prefix . TZFD_Tables::BOOKINGS;
$tz_fail  = 0;

function tz_site_check( $name, $ok, $detail = '' ) {
    global $tz_fail;
    if ( ! $ok ) { $tz_fail++; }
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( '' !== $detail ? ' -- ' . $detail : '' ) . "\n";
}

function tz_site_done() {
    global $tz_fail;
    echo $tz_fail ? "Result: FAILED ($tz_fail)\n" : "Result: all checks passed\n";
    exit( $tz_fail ? 1 : 0 );
}

// Snapshot of every booking row: id, company, and a hash of the stored payload.
function tz_snapshot( $wpdb, $table ) {
    $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, company_id, posted_data FROM %i ORDER BY id', $table ), ARRAY_A );
    $out  = array();
    foreach ( (array) $rows as $row ) {
        $out[ (string) $row['id'] ] = array(
            'company_id' => (string) $row['company_id'],
            'sha256'     => hash( 'sha256', (string) $row['posted_data'] ),
            'decodes'    => null !== TZFD_Stored_Data::decode_booking( $row['posted_data'] ),
        );
    }
    return $out;
}

switch ( $tz_mode ) {

    case 'seed':
        // Run on v3.0.128 before upgrading. Records what must survive the upgrade.
        if ( '' === $tz_arg ) { tz_site_check( 'manifest path given', false, 'usage: seed <manifest.json>' ); tz_site_done(); }
        $snapshot = tz_snapshot( $wpdb, $tz_table );
        tz_site_check( 'at least one existing booking to protect', count( $snapshot ) > 0, 'rows=' . count( $snapshot ) );
        tz_site_check( 'every existing booking decodes before upgrade', 0 === count( array_filter( $snapshot, fn( $r ) => ! $r['decodes'] ) ) );
        file_put_contents( $tz_arg, json_encode( array( 'taken_at' => gmdate( 'c' ), 'bookings' => $snapshot ), JSON_PRETTY_PRINT ) );
        tz_site_check( 'manifest written', is_file( $tz_arg ) );
        tz_site_done();
        break;

    case 'verify':
        // Run on the new build after upgrading. No booking may be lost, changed or left undecodable.
        if ( ! is_file( $tz_arg ) ) { tz_site_check( 'manifest exists', false, $tz_arg ); tz_site_done(); }
        $manifest = json_decode( file_get_contents( $tz_arg ), true );
        $before   = is_array( $manifest ) ? $manifest['bookings'] : array();
        $after    = tz_snapshot( $wpdb, $tz_table );
        $lost     = array_diff_key( $before, $after );
        tz_site_check( 'no pre-upgrade booking is missing', 0 === count( $lost ), 'missing=' . implode( ',', array_keys( $lost ) ) );
        $changed = array();
        foreach ( $before as $id => $row ) {
            if ( isset( $after[ $id ] ) && $after[ $id ]['sha256'] !== $row['sha256'] ) { $changed[] = $id; }
        }
        tz_site_check( 'stored payloads are byte-identical to the pre-upgrade snapshot', 0 === count( $changed ), 'changed=' . implode( ',', $changed ) );
        $undecodable = array_filter( $after, fn( $r ) => ! $r['decodes'] );
        tz_site_check( 'every booking still decodes after upgrade', 0 === count( $undecodable ) );
        $plan = TZFD_Migrations::plan();
        tz_site_check( 'no migration runs implicitly on upgrade', true === $plan['no_changes_made'] );
        tz_site_done();
        break;

    case 'lock-hold':
        $form  = (int) $tz_arg;
        $secs  = isset( $args[2] ) ? (int) $args[2] : 8;
        $lock  = TZFD_Capacity_Lock::acquire( $wpdb, $form, 0 );
        tz_site_check( 'holder acquires the form lock', is_array( $lock ) );
        if ( is_array( $lock ) ) {
            sleep( $secs );
            tz_site_check( 'holder releases its own lock', true === TZFD_Capacity_Lock::release( $wpdb, $lock ) );
        }
        tz_site_done();
        break;

    case 'lock-try':
        // A second connection must be refused while lock-hold runs; a zero-second wait returns at once.
        $form = (int) $tz_arg;
        $lock = TZFD_Capacity_Lock::acquire( $wpdb, $form, 0 );
        tz_site_check( 'second connection is refused while the form is locked', false === $lock, is_array( $lock ) ? 'lock was granted, run lock-hold first' : '' );
        if ( is_array( $lock ) ) { TZFD_Capacity_Lock::release( $wpdb, $lock ); }
        tz_site_done();
        break;

    default:
        tz_site_check( 'known mode', false, 'use seed, verify, lock-hold or lock-try' );
        tz_site_done();
}
