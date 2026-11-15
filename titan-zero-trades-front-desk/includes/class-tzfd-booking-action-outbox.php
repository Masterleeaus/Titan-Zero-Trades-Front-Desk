<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned custom tables. Live transactional data must not be served from cache; values are bound with prepare() or %i placeholders.
/** Pass 48: idempotent booking-action delivery. Never automatically confirms reservations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class TZFD_Booking_Action_Outbox {
    const HOOK = 'tzfd_booking_action_dispatch_v1';
    const ACTIONS = array( 'reminder', 'waitlist_offer', 'inbox_dispatch' );
    public static function table() { return TZFD_Tables::checked_name( TZFD_Tables::BOOKING_ACTIONS ); }
    public static function installed() {
        global $wpdb; $table=self::table();
        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($table)));
    }
    public static function install() {
        global $wpdb;
        if (!current_user_can('manage_options')) return false;
        if (!function_exists('dbDelta')) require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table=self::table(); $collate=$wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id bigint(20) unsigned NOT NULL,
            event_hash char(64) NOT NULL,
            booking_id bigint(20) unsigned NOT NULL,
            action_type varchar(24) NOT NULL,
            recipient_id bigint(20) unsigned NOT NULL,
            state varchar(16) NOT NULL DEFAULT 'pending',
            attempts smallint unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            lease_token char(64) NOT NULL DEFAULT '',
            locked_until datetime DEFAULT NULL,
            receipt_id varchar(120) NOT NULL DEFAULT '',
            last_error varchar(80) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY company_event (company_id,event_hash),
            KEY pending_due (state,available_at)
        ) $collate;");
        return self::schema_ready();
    }
    /** Verify required columns after dbDelta; table existence alone is not an upgrade success. */
    public static function schema_ready() {
        global $wpdb;
        if ( ! self::installed() ) { return false; }
        $table = self::table(); // Registered, checked table name; never caller-supplied.
        $columns = $wpdb->get_col( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), 0  );
        if ( ! is_array( $columns ) ) { return false; }
        $actual = array_fill_keys( $columns, true );
        foreach ( array( 'company_id', 'event_hash', 'booking_id', 'lease_token', 'locked_until', 'receipt_id', 'available_at' ) as $column ) {
            if ( ! isset( $actual[ $column ] ) ) { return false; }
        }
        return true;
    }
    public static function valid($company,$booking,$action,$recipient,$event) {
        return is_int($company) && $company>0 && is_int($booking) && $booking>0 &&
          in_array($action,self::ACTIONS,true) && is_int($recipient) && $recipient>0 &&
          is_string($event) && (bool)preg_match('/^[A-Za-z0-9_.:-]{8,120}$/D',$event);
    }
    /** Input must be created by server-side, approved booking workflows, never raw REST fields. */
    public static function enqueue($company,$booking,$action,$recipient,$event) {
        global $wpdb;
        if (!self::valid($company,$booking,$action,$recipient,$event)) return false;
        // Only install() creates this table. The reminder, waitlist and inbox dispatch bridges call enqueue(), so a missing table is reported instead of dropping the job silently.
        if (!self::installed()) return new WP_Error('tzfd_outbox_missing',__( 'Booking action table is not installed; nothing was queued.', 'titan-zero-trades-front-desk' ));
        if (true!==apply_filters('tzfd_booking_verify_owner_v1',false,$company,$booking) ||
            true!==apply_filters('tzfd_booking_verify_recipient_v1',false,$company,$recipient,$action) ||
            true!==apply_filters('tzfd_booking_verify_action_v1',false,$company,$booking,$action,$recipient)) return false;
        $table=self::table(); $hash=hash('sha256',$company.':'.$booking.':'.$action.':'.$event);
        $duplicate_error=false;
        $changed=$wpdb->query($wpdb->prepare("INSERT INTO %i (company_id,event_hash,booking_id,action_type,recipient_id,available_at) VALUES (%d,%s,%d,%s,%d,UTC_TIMESTAMP())", $table, $company, $hash, $booking, $action, $recipient));
        // Only a verified unique-key collision may be interpreted as an idempotent retry.
        // INSERT IGNORE masks other failures (including data truncation) and is unsafe here.
        if (false===$changed) {
            if (! isset( $wpdb->dbh ) || ! ( $wpdb->dbh instanceof mysqli ) || 1062 !== (int) $wpdb->dbh->errno) return false;
            $duplicate_error=true;
            $changed=0;
        }
        // A zero-row INSERT is not a duplicate-key error; do not infer success.
        if (1 !== (int)$changed && !$duplicate_error) return false;
        if (0 === (int) $changed) {
            $existing=$wpdb->get_row($wpdb->prepare("SELECT booking_id,action_type,recipient_id FROM %i WHERE company_id=%d AND event_hash=%s", $table, $company, $hash),ARRAY_A);
            if (!is_array($existing) || (int)$existing['booking_id']!==$booking ||
                $existing['action_type']!==$action || (int)$existing['recipient_id']!==$recipient) return false;
        }
        self::schedule();
        return array('queued'=>true,'inserted'=>1===(int)$changed,'deduplicated'=>0===(int)$changed,'delivered'=>false);
    }
    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) return wp_schedule_single_event(time()+10,self::HOOK);
        return true;
    }
    /**
     * Schedule the next pending attempt, including retries not yet due and
     * leases that may need recovery after worker interruption.
     * Database DATETIME values are UTC; never parse them as the site timezone.
     */
    public static function next_due_timestamp( $value, $now = null ) {
        if ( ! is_string( $value ) || ! preg_match( '/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/D', $value ) ) { return false; }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
        if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) { return false; }
        $now = null === $now ? time() : (int) $now;
        return max( $now + 10, $date->getTimestamp() );
    }
    public static function schedule_pending() {
        global $wpdb;
        $table = self::table();
        $next = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(CASE WHEN state='pending' THEN available_at WHEN state='leased' THEN locked_until ELSE NULL END) FROM %i WHERE state IN ('pending','leased')", $table ) );
        if ( null === $next ) { return false; }
        $when = self::next_due_timestamp( $next );
        if ( false === $when ) { return false; }
        // Do not replace an earlier job, and do not create duplicate cron events.
        $existing = wp_next_scheduled( self::HOOK );
        if ( $existing && $existing <= $when ) { return true; }
        if ( $existing ) {
            // Retain the existing event if WP cannot atomically remove it.
            if ( ! wp_unschedule_event( $existing, self::HOOK ) ) { return false; }
        }
        return (bool) wp_schedule_single_event( $when, self::HOOK );
    }
    public static function boot() {add_action(self::HOOK,array(__CLASS__,'dispatch'));}
    public static function valid_receipt($receipt,$company,$action,$delivery_id=null) {
        return is_array($receipt) && ($receipt['ok']??null)===true &&
            ($receipt['company_id']??null)===$company && ($receipt['action']??null)===$action &&
            (null===$delivery_id || ($receipt['delivery_id']??null)===$delivery_id) &&
            is_string($receipt['receipt_id']??null) &&
            (bool)preg_match('/^[A-Za-z0-9_.:-]{8,120}$/D',$receipt['receipt_id']);
    }
    /**
     * Waitlist offers are time-sensitive. A queued offer never proves the slot is
     * still free, the offer is unexpired or the customer remains eligible.
     * Only an independently trusted server-side availability resolver can clear
     * the delivery at the instant the worker claims it. No resolver = no send.
     * This check is deliberately specific to waitlist offers; reminders and
     * inbox requests retain their existing policy checks.
     */
    public static function waitlist_delivery_current($row) {
        if (!is_array($row) || ($row['action_type'] ?? null) !== 'waitlist_offer') return false;
        $company=(int)($row['company_id'] ?? 0);
        $booking=(int)($row['booking_id'] ?? 0);
        $recipient=(int)($row['recipient_id'] ?? 0);
        $event=$row['event_hash'] ?? null;
        if ($company<1 || $booking<1 || $recipient<1 || !is_string($event) ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $event)) return false;
        // This trusted filter must check expiration, revision, company, capacity,
        // recipient consent and whether an offer has already been accepted.
        try {
            return true === apply_filters('tzfd_booking_waitlist_offer_current_v1', false,
                $company, $booking, $recipient, $event);
        } catch (Throwable $exception) { return false; }
    }
    /**
     * A queued reminder is not proof that its booking is still approved, its
     * start time is unchanged, or the reminder is still timely. The trusted
     * booking owner must verify all of these against the current booking and
     * the original reminder event identity. No owner resolver means no send.
     */
    public static function reminder_delivery_current($row) {
        if (!is_array($row) || ($row['action_type'] ?? null) !== 'reminder') return false;
        $company=(int)($row['company_id'] ?? 0);
        $booking=(int)($row['booking_id'] ?? 0);
        $recipient=(int)($row['recipient_id'] ?? 0);
        $event=$row['event_hash'] ?? null;
        if ($company<1 || $booking<1 || $recipient<1 || !is_string($event) ||
            !preg_match('/\A[a-f0-9]{64}\z/D', $event)) return false;
        // Trusted owner must verify approved status, unchanged appointment
        // version/time, original reminder schedule, consent and recipient.
        try {
            return true === apply_filters('tzfd_booking_reminder_current_v1', false,
                $company, $booking, $recipient, $event);
        } catch (Throwable $exception) { return false; }
    }
    public static function dispatch($limit=10) {
        global $wpdb; $out=array('accepted'=>0,'retried'=>0);
        if (!self::installed()) return $out;
        $table=self::table(); $limit=max(1,min(25,(int)$limit));
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE (state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP()) ORDER BY id LIMIT %d", $table, $limit));
        foreach ((array)$ids as $id) {
            try {$token=bin2hex(random_bytes(32));}catch(Throwable $e){continue;}
            $claim=$wpdb->query($wpdb->prepare("UPDATE %i SET state='leased',lease_token=%s,locked_until=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 120 SECOND),attempts=attempts+1 WHERE id=%d AND ((state='pending' AND available_at<=UTC_TIMESTAMP()) OR (state='leased' AND locked_until<UTC_TIMESTAMP()))", $table, $token, $id));
            if (1!==(int)$claim) continue;
            $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE id=%d", $table, $id),ARRAY_A);
            if (!$row || !hash_equals($token,(string)$row['lease_token'])) continue;
            $company=(int)$row['company_id'];$action=$row['action_type'];
            // Re-verify ownership, consent and policy at delivery time; queued does not imply authorised forever.
            $allowed=true===apply_filters('tzfd_booking_verify_owner_v1',false,$company,(int)$row['booking_id']) &&
                     true===apply_filters('tzfd_booking_verify_recipient_v1',false,$company,(int)$row['recipient_id'],$action) &&
                     true===apply_filters('tzfd_booking_verify_action_v1',false,$company,(int)$row['booking_id'],$action,(int)$row['recipient_id']);
            if ($allowed && $action==='waitlist_offer') {
                $allowed=self::waitlist_delivery_current($row);
            }
            if ($allowed && $action==='reminder') {
                $allowed=self::reminder_delivery_current($row);
            }
            $receipt=null;
            if($allowed) {
                try {$receipt=apply_filters('tzfd_booking_deliver_v1',null,array('company_id'=>$company,'booking_id'=>(int)$row['booking_id'],'action'=>$action,'recipient_id'=>(int)$row['recipient_id'],'delivery_id'=>$row['event_hash']));}
                catch(Throwable $e){$receipt=null;}
            }
            if($allowed && self::valid_receipt($receipt,$company,$action,$row['event_hash'])) {
                $changed=$wpdb->query($wpdb->prepare("UPDATE %i SET state='accepted',receipt_id=%s,lease_token='',locked_until=NULL WHERE id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()", $table, $receipt['receipt_id'], $id, $token));
                if(1===(int)$changed)++$out['accepted'];
            } else {
                $attempts=(int)$row['attempts'];
                $state=$attempts>=8?'failed':'pending';$delay=min(3600,30*(2**min(7,$attempts)));
                $changed=$wpdb->query($wpdb->prepare("UPDATE %i SET state=%s,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL %d SECOND),lease_token='',locked_until=NULL,last_error=%s WHERE id=%d AND state='leased' AND lease_token=%s AND locked_until>UTC_TIMESTAMP()", $table, $state, $delay, $allowed?'unacknowledged':'policy_not_verified', $id, $token));
                if(1===(int)$changed)++$out['retried'];
            }
        }
        // Include future retries and expired-lease recovery, not only rows due now.
        // Otherwise a worker that finds no immediately due work strands the queue.
        self::schedule_pending();
        return $out;
    }
}
