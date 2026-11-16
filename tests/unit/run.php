<?php
/**
 * Runs every case file under tests/unit/cases. Exit code 1 when any assertion fails.
 * Usage: php tests/unit/run.php
 */
require __DIR__ . '/bootstrap.php';
foreach ( glob( __DIR__ . '/cases/*.php' ) as $case ) {
    require $case;
}
$pass = $GLOBALS['tzfd_t_pass'];
$fail = $GLOBALS['tzfd_t_fail'];
echo "Assertions passed: $pass, failed: $fail\n";
exit( $fail > 0 ? 1 : 0 );
