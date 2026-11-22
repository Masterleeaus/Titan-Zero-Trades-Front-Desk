#!/usr/bin/env bash
# Provisions a DISPOSABLE WordPress site for the runtime suite: fresh database, WordPress core, the plugin
# symlinked from a given folder, activated as user 1 (activation needs an administrator to create the
# slot-hold and call-lifecycle tables).
#
# Usage: provision-wp-site.sh <empty_wp_root> <plugin_dir> [--multisite]
# Env:   WP_CLI (default: wp)            WP-CLI command, e.g. "php /path/wp-cli.phar"
#        TZFD_RT_DB_ADMIN (default: root) MySQL/MariaDB user that may create databases (socket auth)
#        TZFD_RT_DB_NAME  (default: tzfd_rt_$$)  disposable database name
#        TZFD_RT_URL      (default: http://127.0.0.1:8080)
#        TZFD_RT_ADMIN_PASS, TZFD_RT_SUB_PASS      passwords for the disposable admin and subscriber
#        TZFD_RT_WP_VERSION (default: latest)     WordPress core version to download, for example 6.6
# Exit:  0 provisioned; 2 provisioning failed.
set -u

WP_ROOT="${1:-}"; PLUGIN_DIR="${2:-}"; MULTISITE="${3:-}"
WP_CLI="${WP_CLI:-wp}"
WP_VERSION_ARG=""
[ -n "${TZFD_RT_WP_VERSION:-}" ] && WP_VERSION_ARG="--version=$TZFD_RT_WP_VERSION"
DB_ADMIN="${TZFD_RT_DB_ADMIN:-root}"
DB_NAME="${TZFD_RT_DB_NAME:-tzfd_rt_$$}"
DB_USER="${TZFD_RT_DB_USER:-tzfd_rt}"
DB_HOST="${TZFD_RT_DB_HOST:-localhost}"              # config host; set to 127.0.0.1 for a TCP server (CI)
DB_GRANT_HOST="${TZFD_RT_DB_GRANT_HOST:-localhost}" # account host the disposable user is created for
DB_PASS="${TZFD_RT_DB_PASS:-tzfd_rt_$(head -c8 /dev/urandom | od -An -tx1 | tr -d ' \n')}"
URL="${TZFD_RT_URL:-http://127.0.0.1:8080}"
ADMIN_PASS="${TZFD_RT_ADMIN_PASS:-rt_admin_$(head -c9 /dev/urandom | od -An -tx1 | tr -d ' \n')}"
SUB_PASS="${TZFD_RT_SUB_PASS:-rt_sub_$(head -c9 /dev/urandom | od -An -tx1 | tr -d ' \n')}"

fail() { echo "PROVISION FAIL: $*" >&2; exit 2; }

[ -n "$WP_ROOT" ] && [ -n "$PLUGIN_DIR" ] || fail "usage: provision-wp-site.sh <empty_wp_root> <plugin_dir> [--multisite]"
[ -d "$PLUGIN_DIR" ] || fail "plugin dir not found: $PLUGIN_DIR"
mkdir -p "$WP_ROOT" || fail "cannot create $WP_ROOT"
[ -z "$(ls -A "$WP_ROOT")" ] || fail "$WP_ROOT must be empty (refusing to touch an existing folder)"

# Database: fresh, dedicated to this run.
mysql -u"$DB_ADMIN" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4;
  CREATE USER IF NOT EXISTS '$DB_USER'@'$DB_GRANT_HOST' IDENTIFIED BY '$DB_PASS';
  ALTER USER '$DB_USER'@'$DB_GRANT_HOST' IDENTIFIED BY '$DB_PASS';
  GRANT ALL ON \`$DB_NAME\`.* TO '$DB_USER'@'$DB_GRANT_HOST'; FLUSH PRIVILEGES;" || fail "cannot create database (is MySQL/MariaDB running and $DB_ADMIN allowed?)"

$WP_CLI --allow-root --path="$WP_ROOT" core download $WP_VERSION_ARG --quiet || fail "wp core download failed"
$WP_CLI --allow-root --path="$WP_ROOT" config create --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASS" --dbhost=$DB_HOST --skip-check --quiet || fail "config create failed"
$WP_CLI --allow-root --path="$WP_ROOT" core install --url="$URL" --title="Runtime test site" --admin_user=rtadmin \
  --admin_password="$ADMIN_PASS" --admin_email=rt-admin@example.test --skip-email --quiet || fail "core install failed"

ln -s "$PLUGIN_DIR" "$WP_ROOT/wp-content/plugins/titan-zero-trades-front-desk" || fail "cannot link plugin"

if [ "$MULTISITE" = "--multisite" ]; then
  $WP_CLI --allow-root --path="$WP_ROOT" core multisite-convert --title="Runtime multisite" --quiet || fail "multisite-convert failed"
fi

# Activate as user 1 so the activation hook sees an administrator (manage_options).
$WP_CLI --allow-root --path="$WP_ROOT" plugin activate titan-zero-trades-front-desk --user=1 --quiet || fail "plugin activation failed"

# Enable the missed-call add-on for the company (the add-on list is company-scoped, and the REST route
# registers only when the add-on is active), and store a generated inbound secret so the signed HTTP checks
# can run (when the plugin supports it). The secret is random, printed to the caller's environment only, and never written into the code.
INBOUND_LINE="$($WP_CLI --allow-root --path="$WP_ROOT" eval '
$b = TZFD_Local_Business::id();
update_option( TZFD_Scoped_Store::option_name( "tz_fd_addons_active_list", $b ), array( "tzfd-Onboarding", "tzfd-DashboardWidget", "tzfd-MissedCall" ) );
$s = TZFD_Signed_Request::new_secret();
$opt = TZFD_Addon_Support::global_option_name( "missed_call" );
$cur = get_option( $opt, array() ); if ( ! is_array( $cur ) ) { $cur = array(); }
$cur["inbound_secret"] = $s; update_option( $opt, $cur );
echo "\nTZFD_RT_INBOUND_SECRET=" . $s . "\n";' --user=1 2>/dev/null | grep '^TZFD_RT_INBOUND_SECRET=')" || INBOUND_LINE=""  # not every baseline (for example v3.0.128) has these classes; then no secret is exported

# Subscriber for capability tests (password is disposable and printed only to the caller's environment).
$WP_CLI --allow-root --path="$WP_ROOT" user create rtsub rt-sub@example.test --role=subscriber --user_pass="$SUB_PASS" --quiet || fail "subscriber create failed"

cat <<EOF
TZFD_RT_WP_ROOT=$WP_ROOT
TZFD_RT_DB_NAME=$DB_NAME
TZFD_RT_URL=$URL
TZFD_RT_ADMIN_PASS=$ADMIN_PASS
TZFD_RT_SUB_PASS=$SUB_PASS
$INBOUND_LINE
EOF
