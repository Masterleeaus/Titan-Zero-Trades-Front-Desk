#!/usr/bin/env bash
# Installs the TEST-ONLY mu-plugin that gives the contract v1 REST route its company 7 credentials and a
# booking.list executor. Used by run-wp-runtime.sh and the CI WordPress job (issue #349). Never shipped.
# Usage: install-contract-test-mu.sh <wp-root> <key>   (key: 32 or more characters, no quotes)
set -eu
WP_ROOT="$1"
KEY="$2"
if [ "${#KEY}" -lt 32 ] || [[ "$KEY" == *"'"* ]]; then echo "key must be 32+ characters with no quotes" >&2; exit 2; fi
mkdir -p "$WP_ROOT/wp-content/mu-plugins"
cat > "$WP_ROOT/wp-content/mu-plugins/tzfd-test-contract.php" <<PHPEOF
<?php
/* TEST ONLY: written by tests/runtime/install-contract-test-mu.sh into the disposable site. Never shipped. */
add_filter( 'tzfd_contract_credentials_v1', function ( \$creds, \$tenant_id ) {
    if ( 7 !== (int) \$tenant_id ) { return \$creds; }
    return array( 'secret' => '$KEY', 'actor' => array( 'type' => 'owner', 'tenant_id' => 7, 'caps' => array( 'tz_fd_access' ) ) );
}, 10, 2 );
add_filter( 'tzfd_contract_executor_v1', function ( \$result, \$op, \$tenant_id, \$payload ) {
    if ( 'booking.list' !== \$op ) { return \$result; }
    return array( 'stub' => true, 'op' => \$op, 'tenant_id' => \$tenant_id, 'items' => array() );
}, 10, 4 );
PHPEOF
