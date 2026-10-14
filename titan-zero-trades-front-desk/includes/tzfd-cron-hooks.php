<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cron hooks the plugin schedules. Shared by the deactivation handler in
 * titan-zero-trades-front-desk.php and by uninstall.php, so both stop the same events.
 * Add every new wp_schedule_event / wp_schedule_single_event hook here.
 */
return array(
    'titan_zero_front_desk_outbox_run',
    'titan_zero_front_desk_addon_reminder_send',
    'titan_zero_front_desk_addon_followup_send',
    'titan_zero_front_desk_addon_autocancel_run',
    'titan_zero_front_desk_deliver_webhook',
    'titan_zero_front_desk_sync_run',
    'tzfd_acct_refresh',
    'tzfd_ai_cascade_tick',
    'tzfd_ai_prune_cron',
    'tzfd_analytics_daily_rollup',
    'tzfd_analytics_scheduled_report',
    'tzfd_every_minute',
    'tzfd_invoices_daily',
    'tzfd_invoices_recurring',
    'tzfd_pipeline_supervisor',
    'tzfd_plans_tick',
    'tzfd_quote_engine_tick',
    'tzfd_receptionist_callback_cron',
    'tzfd_recovery_cron',
    'tzfd_reminders_tick',
    'tzfd_reports_cron',
);
