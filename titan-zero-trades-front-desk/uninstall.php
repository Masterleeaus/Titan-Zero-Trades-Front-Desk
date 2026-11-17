<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Entry point only. The plan and the direct queries live in includes/class-tzfd-uninstaller.php.
// Runs when the plugin is deleted from WordPress. On a network, WordPress runs this file once and the plan visits every site.
//
// Always, on every site:
//   - clears the plugin's scheduled events,
//   - removes its transients (cache, captcha, rate limits and client-portal sign-in tokens),
//   - removes the calendar invitation files the plugin wrote to its uploads folder.
//
// Only when the site owner adds this line to wp-config.php before deleting the plugin:
//   define( 'TZ_DELETE_DATA_ON_UNINSTALL', true );
// it also drops the plugin's own tables and deletes its settings and credentials on every site.
// Shell-owned tables (tz_customers, tz_sites, ...) are never dropped by this plugin.
//
// Without that line, bookings, enquiries and settings are kept, so deleting the
// plugin cannot remove customer records by accident.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

require_once __DIR__ . '/includes/class-tzfd-tables.php';
require_once __DIR__ . '/includes/class-tzfd-uninstaller.php';

TZFD_Uninstaller::uninstall_network( defined( 'TZ_DELETE_DATA_ON_UNINSTALL' ) && true === TZ_DELETE_DATA_ON_UNINSTALL );
