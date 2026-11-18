#!/usr/bin/env bash
# Creates the scratch databases used by the DB integration tests:
#   tzfd_lane_test    (tests/integration/contact-db-tests.php)
#   tzfd_triage_test  (tests/integration/enquiry-triage-db-test.php)
# Safe to re-run: uses CREATE DATABASE IF NOT EXISTS. The tests drop and recreate their own tables inside these databases only.
#
# Environment (all optional):
#   TZFD_TEST_DB_HOST  server host (default 127.0.0.1)
#   TZFD_TEST_DB_PORT  server TCP port (default 3306)
#   TZFD_TEST_DB_USER  user with CREATE privilege (default root)
#   TZFD_TEST_DB_PASS  password (default empty)
#
# Run from the repository root:
#   TZFD_TEST_DB_PASS=root bash tests/integration/setup-scratch-dbs.sh
set -euo pipefail

host="${TZFD_TEST_DB_HOST:-127.0.0.1}"
port="${TZFD_TEST_DB_PORT:-3306}"
user="${TZFD_TEST_DB_USER:-root}"
export MYSQL_PWD="${TZFD_TEST_DB_PASS:-}"

if ! command -v mysql >/dev/null 2>&1; then
	echo "The mysql client is required to create the scratch databases." >&2
	exit 2
fi

for db in tzfd_lane_test tzfd_triage_test; do
	mysql --protocol=tcp -h "$host" -P "$port" -u "$user" \
		-e "CREATE DATABASE IF NOT EXISTS \`${db}\` DEFAULT CHARACTER SET utf8mb4;"
	echo "Scratch database ready: ${db} (${host}:${port})"
done
