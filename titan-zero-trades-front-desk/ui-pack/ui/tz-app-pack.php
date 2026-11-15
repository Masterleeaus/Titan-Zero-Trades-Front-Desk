<?php
/**
 * Titan Zero UI pack bootstrap (front-desk). Loads the shared shell, this app's class and its read-only data.
 * Include once from the plugin main file. Does nothing outside wp-admin. The plugin works without this file.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/tz-app-core.php';
require_once __DIR__ . '/tz-app-front-desk-reads.php';
require_once __DIR__ . '/tz-app-front-desk.php';

if ( is_admin() ) {
	TZ_UI_Front_Desk::boot();
}
