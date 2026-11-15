# Runtime checks (not shipped in the plugin)

These run against a disposable WordPress test site with the plugin active. They are outside the plugin folder, so the WordPress.org package does not include them.

- `runtime-tests.php`: create, rollback, privacy export and erase, run with `wp --allow-root eval-file runtime-tests.php`. The plugin's includes must be at `/var/www/html/wp-content/plugins/titan-zero-trades-front-desk/includes/`.
- `http-auth-tests.sh`: unauthenticated requests to each REST and AJAX entry point. Each must refuse. Edit `B` for your site. REST is called as `?rest_route=` because pretty `/wp-json/` paths were not routed on the test server.

Not covered yet: double-booking and capacity under concurrent requests, cancellation and rescheduling, upgrade from v3.0.128 with existing data, uninstall, multisite, and a valid-secret replay of the missed-call webhook.
