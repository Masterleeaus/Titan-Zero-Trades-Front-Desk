<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Single source of truth for Front Desk table names.
 *
 * Every table keeps its suffix and moves from the tz_fd_ prefix to tz_. The
 * Front Desk never creates or drops tz_customers; standalone customers are
 * stored in TZFD_Local_Customers. See TABLES.md.
 *
 * Constants hold the bare name. Code adds $wpdb->prefix, or uses name().
 */
final class TZFD_Tables {
    const CALLS              = 'tz_calls';
    const CALL_EVENTS        = 'tz_call_events';
    const SLOT_HOLDS         = 'tz_slot_holds';
    const SMART_TASK_OUTBOX  = 'tz_smart_task_outbox';
    const BOOKING_INBOX      = 'tz_booking_inbox';
    const BOOKING_ACTIONS    = 'tz_booking_actions';
    const TELEPHONE_DELIVERY = 'tz_telephone_delivery';
    const ESCALATION_STATE   = 'tz_escalation_state';
    const FORMS              = 'tz_forms';
    const BOOKINGS           = 'tz_bookings';
    const CACHE              = 'tz_cache';
    const ICALEXPORT         = 'tz_icalexport';
    const SINGLE_DAYS        = 'tz_SingleDaysSelection';
    const ENQUIRIES          = 'tz_enquiries';
    const ENQUIRY_TRIAGE     = 'tz_enquiry_triage';
    const LOCAL_AUDIT        = 'tz_fd_audit_log';
    const PIPELINE           = 'tz_pipeline';
    const PIPELINE_EVENTS    = 'tz_pipeline_events';
    const PIPELINE_NOTIFICATIONS = 'tz_pipeline_notification_outbox';
    const ADDON_RECORDS      = 'tz_addon_records';
    const CONTACT_ACTIONS    = 'tz_contact_actions';
    const SMS_CONVERSATIONS  = 'tz_sms_conversations';
    /** Not created or dropped by Front Desk. Standalone customers use TZFD_Local_Customers. */
    const CUSTOMERS          = 'tz_customers';

    /**
     * Migration map: previous tzfd_ name => current name, for Front Desk's own tables only. Audit reference.
     * Quote, invoice and payment tables are not here: they belong to Titan Zero Quotes and Invoicing.
     */
    const MIGRATION = array(
        'tzfd_forms'              => 'tz_forms',
        'tzfd_messages'           => 'tz_bookings',
        'tzfd_cache'              => 'tz_cache',
        'tzfd_icalexport'         => 'tz_icalexport',
        'tzfd_SingleDaysSelection' => 'tz_SingleDaysSelection',
        'tzfd_pipeline'           => 'tz_pipeline',
        'tzfd_pipeline_events'    => 'tz_pipeline_events',
        'tzfd_addon_records'      => 'tz_addon_records',
        'tzfd_customers'          => 'tz_customers',
    );

    /**
     * Tables this plugin owns (excludes the shell-owned customers table). Quote, invoice, invoice-line and
     * payment tables belong to Titan Zero Quotes and Titan Zero Invoicing (19 Oct split), so they are not listed.
     */
    public static function owned() {
        return array(
            self::CALLS, self::CALL_EVENTS, self::SLOT_HOLDS, self::SMART_TASK_OUTBOX, self::BOOKING_INBOX, self::BOOKING_ACTIONS, self::TELEPHONE_DELIVERY, self::ESCALATION_STATE, self::FORMS, self::BOOKINGS, self::CACHE, self::ICALEXPORT, self::SINGLE_DAYS,
            self::PIPELINE, self::PIPELINE_EVENTS, self::PIPELINE_NOTIFICATIONS, self::ADDON_RECORDS, self::ENQUIRIES, self::ENQUIRY_TRIAGE, self::LOCAL_AUDIT,
            self::CONTACT_ACTIONS, self::SMS_CONVERSATIONS,
        );
    }

    /**
     * Validate a canonical Titan Zero table suffix. Historical aliases are
     * intentionally not accepted here; only migration code may use them.
     */
    public static function is_canonical( $bare ) {
        return is_string( $bare ) && (bool) preg_match( '/^tz_[A-Za-z0-9_]+$/D', $bare );
    }

    /** Return true only for tables this plugin is permitted to own. */
    public static function is_owned( $bare ) {
        return self::is_canonical( $bare ) && in_array( $bare, self::owned(), true );
    }

    /** Front Desk must not mutate Workspace-owned shared tables. */
    public static function is_external_owner( $bare ) {
        return in_array( $bare, array( 'tz_customers', 'tz_sites', 'tz_services', 'tz_staff', 'tz_audit' ), true );
    }

    /** Checked resolution for new table or migration code. */
    public static function checked_name( $bare ) {
        if ( ! self::is_canonical( $bare ) || ! self::is_owned( $bare ) ) {
            return new WP_Error( 'tzfd_invalid_owned_table', __( 'Not a canonical Front Desk-owned table.', 'titan-zero-trades-front-desk' ) );
        }
        return self::name( $bare );
    }

    /** Full name of a canonical Front Desk-owned table only. */
    public static function name( $bare ) {
        if ( ! self::is_owned( $bare ) ) {
            return new WP_Error( 'tzfd_invalid_owned_table', __( 'Not a canonical Front Desk-owned table.', 'titan-zero-trades-front-desk' ) );
        }
        global $wpdb;
        return $wpdb->prefix . $bare;
    }
}
