<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cron hooks the plugin schedules. Shared by the deactivation handler in
 * titan-zero-front-desk.php and by uninstall.php, so both stop the same events.
 * Add every new wp_schedule_event / wp_schedule_single_event hook here.
 */
return array(
    // Transactional notification and booking queues; both retry wakes and recovery jobs.
    'tzfd_booking_action_dispatch_v1',
    'tzfd_customer_event_outbox_dispatch',
    'tzfd_customer_event_outbox_wake',
    'tzfd_pipeline_notifications_dispatch_v1',
    'tzfd_pipeline_notifications_wake_v1',
    'tzfd_smart_task_outbox_dispatch_v1',
    'tzfd_telephone_delivery_dispatch_v1',
    'titan_zero_front_desk_outbox_run',
    'titan_zero_front_desk_addon_reminder_send',
    'titan_zero_front_desk_addon_followup_send',
    'titan_zero_front_desk_addon_autocancel_run',
    'titan_zero_front_desk_deliver_webhook',
    'titan_zero_front_desk_sync_run',
    'tz_fd_acct_refresh',
    'tz_fd_ai_cascade_tick',
    'tz_fd_ai_prune_cron',
    'tz_fd_ical_remove_file',
    'tz_fd_analytics_daily_rollup',
    'tz_fd_analytics_scheduled_report',
    'tz_fd_every_minute',
    'tz_fd_pipeline_supervisor',
    'tz_fd_plans_tick',
    'tz_fd_receptionist_callback_cron',
    'tz_fd_recovery_cron',
    'tz_fd_reminders_tick',
    'tz_fd_reports_cron',
);
