# Runtime checks (not shipped in the plugin)

These run against a disposable WordPress test site with the plugin active. They are outside the plugin folder, so the WordPress.org package does not include them.

- `runtime-tests.php`: create, rollback, privacy export and erase, run with `wp --allow-root eval-file runtime-tests.php`. The plugin's includes must be at `/var/www/html/wp-content/plugins/titan-zero-trades-front-desk/includes/`.
- `http-auth-tests.sh`: unauthenticated requests to each REST entry point. Each must refuse. Edit `B` for your site (default `http://localhost:8080`). REST is called as `?rest_route=` because pretty `/wp-json/` paths were not routed on the test server. A route that is not registered is reported as FAIL, because a 404 there proves nothing about authorization. The missed-call route needs its addon (`tzfd-MissedCall`) active. Set `SECRET` to the stored missed-call inbound secret to also check that a valid signature is accepted once and its replay refused. The plugin registers no admin-ajax actions in 3.0.128, so there is no AJAX entry point to test.

Last run (3.0.128 source, PHP 8.3.6, MariaDB 10.11, WordPress latest, single site, plugin activated from a clean copy): `runtime-tests.php` 21 passed, 0 failed; `http-auth-tests.sh` 9 passed, 0 failed (with `SECRET` set).

Not covered yet: PHP and WordPress version matrix, multisite, double-booking and capacity under concurrent requests, slot hold, changes, cancellation, refunds and deposits, reminders and outbox, upgrade from v3.0.128 with existing bookings and AI-history options (no newer build exists to upgrade to), uninstall safety, and nonce and capability checks on admin actions.
