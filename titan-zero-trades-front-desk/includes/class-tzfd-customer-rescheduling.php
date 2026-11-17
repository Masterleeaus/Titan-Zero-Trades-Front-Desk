<?php

// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/** Opt-in, single-appointment customer rescheduling. Independently implemented; GPL-2.0-only. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/../includes/class-tzfd-request-scalar.php';
require_once __DIR__ . '/class-tzfd-stored-data.php';

require_once __DIR__.'/class-tzfd-customer-cancellation.php';
require_once __DIR__.'/class-tzfd-capacity-lock.php';
require_once __DIR__.'/class-tzfd-customer-transaction.php';
require_once __DIR__ . '/class-tzfd-public-confirmation-guard.php';

final class TZFD_Customer_Rescheduling {
    const ACTION = 'titan_zero_front_desk_customer_reschedule';
    const RECEIPT = '_titan_zero_front_desk_reschedule';

    /** @var TZFD_Scheduling_Plugin|null The engine that serves the public change page. */
    private $engine = null;

    public function __construct($engine = null) {
        $this->engine = $engine instanceof TZFD_Scheduling_Plugin ? $engine : null;
    }

    public function register() {
        add_filter('titan_zero_front_desk_customer_copy_message', array($this,'customer_copy'), 10, 4);
        add_action('admin_post_'.self::ACTION, array($this,'handle_public_request'));
        add_action('admin_post_nopriv_'.self::ACTION, array($this,'handle_public_request'));
    }

    /** Reschedule links are valid for this long from issue, the same as cancellation links. */
    const TOKEN_TTL = 1209600; // 14 days

    /**
     * Issues a reschedule link for now: the token plus the issue time and the expiry 14 days later.
     * Returns null when the inputs are not usable, so no link is ever built without a signature.
     */
    public static function issue($id, $tz_form, $email, $version, $secret, $now=null) {
        $issued=null===$now?time():(int)$now;
        $token=self::token($id,$tz_form,$email,$version,$secret,$issued,$issued+self::TOKEN_TTL);
        return $token==='' ? null : array('token'=>$token,'issued'=>$issued,'expires'=>$issued+self::TOKEN_TTL);
    }

    /**
     * HMAC over booking, form, e-mail, booking version, issue time and expiry. A token without an issue time,
     * or whose expiry is not exactly 14 days after its issue time, is not produced.
     */
    public static function token($id, $tz_form, $email, $version, $secret, $issued=0, $expires=0) {
        $id=TZFD_Customer_Cancellation::normalize_booking_id($id);
        $tz_form=TZFD_Customer_Cancellation::normalize_booking_id($tz_form);
        $issued=self::normalize_time($issued); $expires=self::normalize_time($expires);
        if (!$id || !$tz_form || !is_string($email) || !filter_var($email,FILTER_VALIDATE_EMAIL) ||
            !is_string($version) || !preg_match('/^[a-f0-9]{64}$/D',$version) || !is_string($secret) || $secret==='' ||
            !$issued || $expires!==$issued+self::TOKEN_TTL) { return ''; }
        return hash_hmac('sha256','titan-zero-trades-front-desk/customer-reschedule/v2|'.$id.'|'.$tz_form.'|'.strtolower($email).'|'.$version.'|'.$issued.'|'.$expires,$secret);
    }

    /** True only for a well-formed token, issued for this booking version, that has not expired. */
    public static function verify_token($id, $tz_form, $email, $token, $version, $secret, $issued, $expires, $now=null) {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) { return false; }
        $issued=self::normalize_time($issued); $expires=self::normalize_time($expires);
        $now=null===$now?time():(int)$now;
        // A link issued well in the future (beyond a small clock-skew allowance) is refused, as is one past its expiry.
        if (!$issued || !$expires || $issued>$now+300 || $expires<$now) { return false; }
        $expected=self::token($id,$tz_form,$email,$version,$secret,$issued,$expires);
        return '' !== $expected && hash_equals($expected,$token);
    }

    /** Accepts a positive integer or a string of digits, and returns it as an int, or 0. */
    public static function normalize_time($value) {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) { return 0; }
        return (int)$value>0 ? (int)$value : 0;
    }

    public static function decode($raw) {
        if (!is_string($raw) || $raw==='' || strlen($raw)>1048576) { return false; }
        try { $tz_data=TZFD_Stored_Data::decode($raw); }
        catch (Throwable $error) { return false; }
        return is_array($tz_data) && self::safe_values($tz_data) ? $tz_data : false;
    }

    private static function safe_values($tz_data, $depth=0) {
        if ($depth>32) { return false; }
        if (is_array($tz_data)) {
            foreach ($tz_data as $tz_value) { if (!self::safe_values($tz_value,$depth+1)) { return false; } }
            return true;
        }
        return is_null($tz_data) || is_scalar($tz_data);
    }

    public static function version($row) {
        return hash('sha256',serialize(array($row->posted_data,$row->data,$row->notifyto,(int)$row->formid)));
    }

    private static function fingerprint($tz_data, $text) {
        unset($tz_data[self::RECEIPT]);
        return hash('sha256',serialize(array($tz_data,$text)));
    }

    private static function recipient($tz_value) {
        return is_string($tz_value) && !preg_match('/[,;\s<>]/',$tz_value) && is_email($tz_value) ? $tz_value : '';
    }

    /** Invoked only by the actual customer-copy branch, never admin or payer delivery. */
    public function customer_copy($tz_message, $row, $headers, $engine) {
        if (!is_string($tz_message) || !is_object($row) || !($engine instanceof TZFD_Scheduling_Plugin) ||
            self::recipient($row->notifyto)==='' || $engine->get_option('cu_enable_copy_to_user','')!=='true') { return $tz_message; }
        $header_text=is_array($headers)?implode("\n",$headers):$headers;
        if (!is_string($header_text) || preg_match('/(^|[\r\n])\s*(cc|bcc)\s*:/i',$header_text)) { return $tz_message; }
        try { $tz_data=self::decode($row->posted_data); $this->reconcile($engine,$row,$tz_data,null,null); }
        catch (Throwable $error) { return $tz_message; }
        $version=self::version($row);
        $link=self::issue($row->id,$row->formid,$row->notifyto,$version,wp_salt('auth'));
        if (null===$link) { return $tz_message; }
        $url=add_query_arg(array('action'=>self::ACTION,'booking_id'=>(int)$row->id,'form_id'=>(int)$row->formid,
            'version'=>$version,'issued'=>$link['issued'],'expires'=>$link['expires'],'token'=>$link['token']),admin_url('admin-post.php'));
        $tz_label=__( 'Change appointment time', 'titan-zero-trades-front-desk' );
        return $tz_message.($engine->get_option('cu_emailformat','text')==='html'
            ? '<p><a href="'.esc_url($url).'">'.esc_html($tz_label).'</a></p>' : "\n\n".$tz_label.":\n".$url);
    }

    /** Validate and reconstruct the supported donor representation without re-extracting/repricing it. */
    private function reconcile($engine, $row, $tz_data, $tz_date, $start) {
        if (!is_array($tz_data) || array_key_exists('lock',$tz_data) || !isset($tz_data['apps']) ||
            !is_array($tz_data['apps']) || array_keys($tz_data['apps'])!==array(0) || !is_array($tz_data['apps'][0])) {
            throw new InvalidArgumentException('unsupported_booking');
        }
        $tz_app=$tz_data['apps'][0];
        foreach (array('id','cancelled','serviceindex','service','duration','price','date','slot','field','quant','sid') as $tz_key) {
            if (!array_key_exists($tz_key,$tz_app)) { throw new InvalidArgumentException('unsupported_booking'); }
        }
        if (TZFD_Scheduling::integer($tz_app['id'],1,1)!==1 || TZFD_Scheduling::integer($tz_app['serviceindex'],0)===null ||
            (!is_int($tz_app['sid']) && !is_string($tz_app['sid'])) ||
            (!is_int($tz_app['price']) && !is_float($tz_app['price']) && !is_string($tz_app['price'])) || !is_numeric($tz_app['price']) || !is_finite((float)$tz_app['price']) || (float)$tz_app['price']<0 ||
            (isset($tz_app['military']) && !in_array($tz_app['military'],array(0,1,'0','1',''),true)) || !in_array($tz_app['cancelled'],array('','Approved','Pending'),true) ||
            !is_string($tz_app['service']) || !is_string($tz_app['field']) || !is_scalar($tz_app['sid']) ||
            (isset($tz_data['formid']) && TZFD_Customer_Cancellation::normalize_booking_id($tz_data['formid'])!==(int)$row->formid)) {
            throw new InvalidArgumentException('unsupported_booking');
        }
        // These alternative extension representations need their own adapter, not a partial edit.
        foreach (array('start','end','starttime','endtime','date_end','start_date','end_date','app_start','app_end','app_start_1','app_end_1','app_startdate_1','app_enddate_1','recurrence','series') as $tz_key) {
            if (array_key_exists($tz_key,$tz_app) || array_key_exists($tz_key,$tz_data)) { throw new InvalidArgumentException('unsupported_booking'); }
        }
        foreach (array_keys($tz_data) as $tz_key) {
            if (is_string($tz_key) && preg_match('/^app_.*_([0-9]+)$/D',$tz_key,$m) && $m[1]!=='1') { throw new InvalidArgumentException('unsupported_booking'); }
        }
        $decoded=json_decode($engine->cleanJSON($engine->get_option('form_structure',TZFD_BOOK_DEFAULT_form_structure)));
        if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) { throw new InvalidArgumentException('invalid_configuration'); }
        $fields=$decoded[0]; $map=TZFD_Scheduling::configuration($fields);
        if (!isset($map[$tz_app['field']])) { throw new InvalidArgumentException('unknown_service'); }
        $index=null;
        if ((string)$tz_app['sid']!=='') {
            foreach ($map[$tz_app['field']]['services'] as $tz_key=>$service) {
                if ($service['sid']===(string)$tz_app['sid']) { $index=$tz_key; break; }
            }
        } else {
            // Services without a stored ID are matched by position, as the scheduler does.
            $requested=TZFD_Scheduling::integer($tz_app['serviceindex'],0);
            if ($requested!==null && isset($map[$tz_app['field']]['services'][$requested]) &&
                $map[$tz_app['field']]['services'][$requested]['sid']==='') { $index=$requested; }
        }
        if ($index===null) { throw new InvalidArgumentException('unknown_service'); }
        $service=$map[$tz_app['field']]['services'][$index];
        $interval=TZFD_Scheduling::interval($tz_app['date'],$tz_app['slot']);
        if (!self::same_day_slot($tz_app['slot'])) { throw new InvalidArgumentException('unsupported_overnight'); }
        if (TZFD_Scheduling::integer($tz_app['duration'],1,1440)!==$interval['duration'] || $service['duration']!==$interval['duration'] ||
            TZFD_Scheduling::integer($tz_app['quant'],1,$service['capacity'])===null) { throw new InvalidArgumentException('unsupported_booking'); }
        $new=$tz_app;
        if ($tz_date!==null) {
            TZFD_Scheduling::date($tz_date);
            $new['date']=$tz_date; $new['slot']=TZFD_Scheduling::slot($start,$interval['duration']);
            if (!self::same_day_slot($new['slot'])) { throw new InvalidArgumentException('unsupported_overnight'); }
            $new['serviceindex']=$index;
        }
        $old_text=$engine->get_appointments_text(array($tz_app)); $new_text=$engine->get_appointments_text(array($new));
        $old_flat=$this->flat($engine,$tz_app); $new_flat=$this->flat($engine,$new);
        foreach ($old_flat as $tz_key=>$tz_value) {
            if (!array_key_exists($tz_key.'_1',$tz_data) || $tz_data[$tz_key.'_1']!==$tz_value) { throw new InvalidArgumentException('inconsistent_booking'); }
            $tz_data[$tz_key.'_1']=$new_flat[$tz_key];
            if (array_key_exists($tz_key,$tz_data)) {
                if ($tz_data[$tz_key]!==$tz_value) { throw new InvalidArgumentException('inconsistent_booking'); }
                $tz_data[$tz_key]=$new_flat[$tz_key];
            }
        }
        foreach (array('status'=>'cancelled','service'=>'service','duration'=>'duration','price'=>'price','quantity'=>'quant') as $flat=>$nested) {
            if (!array_key_exists('app_'.$flat.'_1',$tz_data) || $tz_data['app_'.$flat.'_1']!==$tz_app[$nested]) { throw new InvalidArgumentException('inconsistent_booking'); }
            if (array_key_exists('app_'.$flat,$tz_data) && $tz_data['app_'.$flat]!==$tz_app[$nested]) { throw new InvalidArgumentException('inconsistent_booking'); }
        }
        if (!isset($tz_data[$tz_app['field']]) || $tz_data[$tz_app['field']]!==$old_text) { throw new InvalidArgumentException('inconsistent_booking'); }
        $appointment_fields=array();
        foreach ($fields as $field) {
            if (isset($field->ftype,$field->name) && $field->ftype==='fapp' && array_key_exists($field->name,$tz_data)) {
                $appointment_fields[$field->name]=true;
                if ($tz_data[$field->name]!==$old_text) { throw new InvalidArgumentException('inconsistent_booking'); }
                $tz_data[$field->name]=$new_text;
            }
        }
        foreach ($tz_data as $tz_key=>$tz_value) {
            if (is_string($tz_key) && !isset($appointment_fields[$tz_key]) && $tz_value===$old_text) {
                throw new InvalidArgumentException('inconsistent_booking');
            }
        }
        $prefix=__('Appointments','titan-zero-trades-front-desk').":\n".$old_text."\n";
        if (!is_string($row->data) || strncmp($row->data,$prefix,strlen($prefix))!==0) { throw new InvalidArgumentException('inconsistent_booking'); }
        $text=__('Appointments','titan-zero-trades-front-desk').":\n".$new_text."\n".substr($row->data,strlen($prefix));
        $tz_data['apps'][0]=$new;
        $assessment=$new; $assessment['serviceindex']=$index;
        return array('data'=>$tz_data,'text'=>$text,'appointment'=>$assessment,'original'=>$tz_app);
    }

    /** The inherited 12-hour formatter cannot truthfully label next-day end times. */
    public static function same_day_slot($slot) {
        if (!is_string($slot) || !preg_match('/^([0-9]{2}):([0-9]{2})\/([0-9]{2}):([0-9]{2})$/D',$slot,$m)) { return false; }
        return (int)$m[1]<24 && (int)$m[2]<60 && (int)$m[3]<24 && (int)$m[4]<60 && substr($slot,6)>substr($slot,0,5);
    }

    private function flat($engine, $tz_app) {
        $parts=explode('/',$tz_app['slot']); $non_military=empty($tz_app['military']);
        return array('app_date'=>$engine->format_date($tz_app['date']), 'app_slot'=>$tz_app['slot'],
            'app_starttime'=>$engine->format12hours($parts[0],$non_military), 'app_endtime'=>$engine->format12hours($parts[1],$non_military));
    }

    /** Public capability-link route. is_admin() here does not grant any permission. */
    public function request($engine, $input, $method) {
        global $wpdb;
        if (!in_array($method,array('GET','POST'),true)) { return $this->result(405,__( 'Use the appointment change form.', 'titan-zero-trades-front-desk' )); }
        if (!($engine instanceof TZFD_Scheduling_Plugin) || !is_array($input)) { return $this->result(503,__( 'Appointment changes are temporarily unavailable.', 'titan-zero-trades-front-desk' )); }
        $id=TZFD_Customer_Cancellation::normalize_booking_id($input['booking_id']??null);
        $tz_form=TZFD_Customer_Cancellation::normalize_booking_id($input['form_id']??null);
        $version=$input['version']??''; $token=$input['token']??'';
        $issued=self::normalize_time($input['issued']??null); $expires=self::normalize_time($input['expires']??null);
        if (!$id || !$tz_form || !is_string($version) || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token) || !$issued || !$expires) { return $this->result(404,__( 'This appointment change link is invalid.', 'titan-zero-trades-front-desk' )); }
        // Public signed-link lookups must fail closed even when a database adapter
        // throws, not just when wpdb reports last_error after returning.
        try {
            $row=$wpdb->get_row($wpdb->prepare('SELECT id, formid, notifyto, posted_data, data FROM %i WHERE id=%d AND formid=%d',$wpdb->prefix.TZFD_Tables::BOOKINGS,$id,$tz_form));
            if (!empty($wpdb->last_error)) { return $this->result(503,__( 'The booking could not be read. Please try again.', 'titan-zero-trades-front-desk' )); }
        } catch (Throwable $tz_error) {
            return $this->result(503,__( 'The booking could not be read. Please try again.', 'titan-zero-trades-front-desk' ));
        }
        if (!is_object($row) || self::recipient($row->notifyto ?? null)==='') { return $this->result(404,__( 'This appointment change link is invalid.', 'titan-zero-trades-front-desk' )); }
        try { $valid=self::verify_token($id,$tz_form,$row->notifyto,$token,$version,wp_salt('auth'),$issued,$expires); }
        catch (Throwable $tz_error) { return $this->result(503,__( 'Appointment verification is temporarily unavailable.', 'titan-zero-trades-front-desk' )); }
        if (!$valid) { return $this->result(404,__( 'This appointment change link is invalid.', 'titan-zero-trades-front-desk' )); }
        // The HMAC above authenticates this booking/form/customer link. A
        // nopriv request may have no active Workspace tenant session; derive
        // its company from the form instead, rejecting explicit mismatches.
        $ownership = TZFD_Customer_Public_Owner::resolve( (int) $row->formid );
        if ( 'unavailable' === $ownership['status'] ) {
            return $this->result(503,__( 'Appointment verification is temporarily unavailable.', 'titan-zero-trades-front-desk' ));
        }
        if ( 'matched' !== $ownership['status'] ) {
            return $this->result(404,__( 'This appointment change link is invalid.', 'titan-zero-trades-front-desk' ));
        }
        $company = $ownership['company'];
        $tz_data=self::decode($row->posted_data);
        $tz_date=$input['date']??null; $start=$input['start']??null;
        if (self::version($row)!==$version) {
            $receipt=is_array($tz_data)?($tz_data[self::RECEIPT]??null):null;
            $completed=$method==='GET' && ($input['completed']??null)==='1';
            if ((($method==='POST' && ($input['confirm_reschedule']??null)==='1') || $completed) && is_array($receipt) &&
                ($receipt['from_version']??null)===$version && ($receipt['date']??null)===$tz_date && ($receipt['start']??null)===$start &&
                ($receipt['fingerprint']??null)===self::fingerprint($tz_data,$row->data)) {
                $tz_message=__( 'This appointment change is already saved. No further change was made. ', 'titan-zero-trades-front-desk' );
                $tz_message.=(($tz_data['apps'][0]['cancelled']??'')==='Pending'?__( 'Your request remains pending review. ', 'titan-zero-trades-front-desk' ):__( 'Your appointment time has changed. ', 'titan-zero-trades-front-desk' ));
                $tz_message.=__( 'Saved time: ', 'titan-zero-trades-front-desk' ).($tz_data['app_date_1']??'').' '.($tz_data['app_starttime_1']??'').' to '.($tz_data['app_endtime_1']??'').'.';
                if (($input['notice']??null)==='mail_failed' && is_string($input['notice_token']??null) &&
                    hash_equals(self::completion_signature($token),$input['notice_token'])) {
                    $tz_message.=' The change was saved, but the email notice could not be sent.';
                }
                if (($input['notice']??null)==='followup_failed' && is_string($input['notice_token']??null) &&
                    hash_equals(self::completion_signature($token,'followup_failed'),$input['notice_token'])) {
                    $tz_message.=' The change was saved, but a follow-up step could not be completed. Contact the booking administrator if you do not receive a confirmation.';
                }
                return $this->result(200,$tz_message);
            }
            return $this->result(409,__( 'This booking has changed. Please use the latest appointment change link or contact the booking administrator.', 'titan-zero-trades-front-desk' ));
        }
        // An unavailable scheduler must not leak an exception through the public endpoint.
        // Snapshot its mutable state before changing the active booking form.
        try {
            $original_id=$engine->getId(); $buffered_id=$engine->option_buffered_id; $buffered_item=$engine->option_buffered_item;
        } catch (Throwable $tz_error) {
            return $this->result(503,__( 'Appointment changes are temporarily unavailable. Please try again.', 'titan-zero-trades-front-desk' ));
        }
        try {
            $engine->setId($tz_form); $engine->option_buffered_id=-1; $engine->option_buffered_item=false;
            $base=$this->reconcile($engine,$row,$tz_data,null,null);
            $context=array('booking_id'=>$id,'form_id'=>$tz_form,'version'=>$version,'issued'=>$issued,'expires'=>$expires,'token'=>$token,
                'date'=>$base['original']['date'],'start'=>substr($base['original']['slot'],0,5));
            if ($method==='GET' && ($input['completed']??null)==='1') {
                if ($tz_date===$base['original']['date'] && $start===substr($base['original']['slot'],0,5)) {
                    return $this->result(200,__( 'This is already your appointment time. No change was made.', 'titan-zero-trades-front-desk' ));
                }
                return $this->result(409,__( 'This appointment change has not been saved. Use the appointment change form to preview and confirm.', 'titan-zero-trades-front-desk' ));
            }
            if ($method==='GET' && $tz_date===null && $start===null) {
                return $this->result(200,__( 'Choose a new date and start time. Nothing changes until you confirm.', 'titan-zero-trades-front-desk' ), $context);
            }
            try { $move=$this->reconcile($engine,$row,$tz_data,$tz_date,$start); }
            catch (InvalidArgumentException $error) {
                if ($error->getMessage()==='unsupported_overnight') { return $this->result(400,__( 'Choose a time that starts and ends on the same calendar day. Overnight appointment changes are not supported. Your appointment has not changed.', 'titan-zero-trades-front-desk' ),$context); }
                return $this->result(400,__( 'Enter a valid date and start time. Your appointment has not changed.', 'titan-zero-trades-front-desk' ),$context); }
            if ($tz_date===null || $start===null) { return $this->result(400,__( 'Enter a date and start time.', 'titan-zero-trades-front-desk' ),$context); }
            $context['date']=$tz_date; $context['start']=$start;
            $assessment=$engine->assess_existing_appointment(array($move['appointment']),$id);
            if (!$assessment['ok']) { return $this->result(409,__( 'That time conflicts with booking capacity or a block. Choose another time. Your appointment has not changed.', 'titan-zero-trades-front-desk' ),$context); }
            if ($method==='GET') {
                return $this->result(200,__( 'Review the new appointment time: ', 'titan-zero-trades-front-desk' ).$engine->get_appointments_text(array($move['appointment'])).($base['original']['cancelled']==='Pending'?' This request will remain pending review.':''),$context,true);
            }
            if (($input['confirm_reschedule']??null)!=='1') { return $this->result(400,__( 'Preview the time and use Confirm appointment change.', 'titan-zero-trades-front-desk' ),$context); }
            // The confirm POST changes the booking: verify the nonce for this booking before any lock, write or notice.
            if ( ! isset( $input['_wpnonce'] ) || ! is_string( $input['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $input['_wpnonce'] ) ), 'tz_fd_reschedule_' . $id ) ) { return $this->result(403,__( 'Your confirmation has expired or is not valid. Review the new time again and confirm.', 'titan-zero-trades-front-desk' ),$context); }
            if ($move['appointment']['date']===$base['original']['date'] && $move['appointment']['slot']===$base['original']['slot']) {
                return $this->result(200,__( 'This is already your appointment time. No change was made.', 'titan-zero-trades-front-desk' ));
            }
            $capacity_lock=TZFD_Capacity_Lock::acquire($wpdb,$tz_form);
            if ($capacity_lock===false) {
                return $this->result(503,__( 'Availability could not be checked. Your appointment has not changed. Please try again.', 'titan-zero-trades-front-desk' ),$context);
            }
            $tz_committed=false;
            $tz_result='failure';
            try {
                if (!TZFD_Customer_Transaction::begin($wpdb)) {
                    $tz_result='storage';
                } else {
                    try {
                        // Reassess availability under the lock, before saving or staging any event.
                        $final_assessment=$engine->assess_existing_appointment(array($move['appointment']),$id);
                        if (!$final_assessment['ok']) { $tz_result='capacity'; }
                        else {
                            $move['data'][self::RECEIPT]=array('from_version'=>$version,'date'=>$tz_date,'start'=>$start,
                                'fingerprint'=>self::fingerprint($move['data'],$move['text']));
                            // Build the optional customer copy from the confirmed updated version BEFORE COMMIT.
                            // The email is queued atomically with the booking, never delivered from this request.
                            $tz_email_payload=null;
                            if ($engine->get_option('cu_enable_copy_to_user','')==='true') {
                                $tz_updated=clone $row;
                                $tz_updated->posted_data=serialize($move['data']);
                                $tz_updated->data=$move['text'];
                                $tz_pending=$base['original']['cancelled']==='Pending';
                                $tz_notice=$tz_pending?__( 'Your requested appointment time has changed. Your request remains pending review.', 'titan-zero-trades-front-desk' ):__( 'Your appointment time has changed.', 'titan-zero-trades-front-desk' );
                                $tz_body=$tz_notice."\n\nBooking reference: #".$id."\n".$engine->get_appointments_text(array($move['appointment']));
                                $tz_format=$engine->get_option('cu_emailformat','text')==='html'?'html':'text';
                                $tz_headers=array('Content-Type: text/'.$tz_format.'; charset=UTF-8');
                                if ($tz_format==='html') { $tz_body=nl2br(esc_html($tz_body)); }
                                $tz_body=$this->customer_copy($tz_body,$tz_updated,$tz_headers,$engine);
                                $tz_email_payload=array('to'=>$row->notifyto,'subject'=>__( 'Appointment time updated', 'titan-zero-trades-front-desk' ),
                                    'body'=>$tz_body,'format'=>$tz_format);
                            }
                            // An oversized confirmation is refused before any write, so the booking is left unchanged.
                            $changed=(null!==$tz_email_payload && !TZFD_Customer_Event_Outbox::valid_reschedule_email($tz_email_payload)) ? 'email_size' : $wpdb->query($wpdb->prepare('UPDATE %i SET posted_data=%s, data=%s WHERE id=%d AND formid=%d AND BINARY posted_data=BINARY %s AND BINARY data=BINARY %s AND BINARY notifyto=BINARY %s',
                                $wpdb->prefix.TZFD_Tables::BOOKINGS,serialize($move['data']),$move['text'],$id,$tz_form,$row->posted_data,$row->data,$row->notifyto));
                            if ($changed===1) {
                                $tz_payload=array('old_date'=>$base['original']['date'],'old_slot'=>$base['original']['slot'],
                                    'date'=>$tz_date,'slot'=>$move['appointment']['slot'],'status'=>$base['original']['cancelled']);
                                if (TZFD_Customer_Event_Outbox::stage($company,$id,$tz_form,'rescheduled',$version,$tz_payload) &&
                                    ($tz_email_payload===null || TZFD_Customer_Event_Outbox::stage($company,$id,$tz_form,'reschedule_email',$version,$tz_email_payload))) {
                                    $tz_result=TZFD_Customer_Transaction::commit($wpdb);
                                    $tz_committed=$tz_result==='committed';
                                    if (!$tz_committed) { $tz_result='commit_unknown'; }
                                } else { $tz_result='stage'; }
                            } else { $tz_result=$changed==='email_size'?'email_size':($changed===false?'write':'conflict'); }
                        }
                    } catch (Throwable $tz_error) { $tz_result='write'; }
                    if (!$tz_committed && $tz_result!=='commit_unknown') { TZFD_Customer_Transaction::rollback($wpdb); }
                }
            } finally {
                TZFD_Capacity_Lock::release($wpdb,$capacity_lock);
            }
            if (!$tz_committed) {
                if ($tz_result==='capacity') { return $this->result(409,__( 'That time conflicts with booking capacity or a block. Your appointment has not changed.', 'titan-zero-trades-front-desk' ),$context); }
                if ($tz_result==='email_size') { return $this->result(409,__( 'The confirmation email for this change is too long to send, so the change was not saved. Your appointment has not changed. Contact the booking administrator.', 'titan-zero-trades-front-desk' ),$context); }
                if ($tz_result==='conflict') { return $this->result(409,__( 'This booking changed before the appointment change could be saved.', 'titan-zero-trades-front-desk' )); }
                return $this->result(503,__( 'The appointment change could not be confirmed safely. Check its status before retrying.', 'titan-zero-trades-front-desk' ),$context);
            }
            // The booking change is committed. A failure in a follow-up step must not report the reschedule as failed.
            $followup_failed=false;
            try {
                TZFD_Customer_Event_Outbox::schedule();
                do_action('tz_fd_cache_clean',$tz_form);
                // Synchronous, after commit: reminder add-ons re-queue for the new slot before the old one can fire.
                do_action('tzfd_booking_time_changed',$id,$tz_form,$company);
            } catch (Throwable $tz_error) { $followup_failed=true; }
            $pending=$base['original']['cancelled']==='Pending';
            $notice=$pending?__( 'Your requested appointment time has changed. Your request remains pending review.', 'titan-zero-trades-front-desk' ):__( 'Your appointment time has changed.', 'titan-zero-trades-front-desk' );
            // A successful booking change queues its customer copy for retryable delivery.
            // No synchronous wp_mail call can be lost in the post-commit window.
            return $this->result(200,$notice,array(),false,false,$followup_failed);
        } catch (InvalidArgumentException $error) {
            if ($error->getMessage()==='unsupported_overnight') { return $this->result(409,__( 'Overnight appointments cannot be changed with this form. Contact the booking administrator. Your appointment has not changed.', 'titan-zero-trades-front-desk' )); }
            if ($error->getMessage()==='booking_storage_unavailable') { return $this->result(503,__( 'Availability could not be checked. Your appointment has not changed. Please try again.', 'titan-zero-trades-front-desk' )); }
            return $this->result(409,__( 'This booking cannot be changed with this single-appointment form. Contact the booking administrator.', 'titan-zero-trades-front-desk' ));
        } catch (Throwable $tz_error) {
            // Unexpected scheduler/Workspace exceptions must not expose a stack trace
            // or turn a customer preview into an unhandled public request failure.
            // This catch also covers failed availability assessment before mutation.
            return $this->result(503,__( 'Appointment changes are temporarily unavailable. Please try again.', 'titan-zero-trades-front-desk' ));
        } finally {
            $engine->setId($original_id); $engine->option_buffered_id=$buffered_id; $engine->option_buffered_item=$buffered_item;
        }
    }

    /** Reads only the fields this handler uses, one at a time, each sanitized. Missing or non-text values are left out. */
    private static function read_fields($source) {
        $input=array();
        foreach (array('booking_id','form_id','version','issued','expires','token','date','start','completed','confirm_reschedule','notice','notice_token','_wpnonce') as $field) {
            if (!isset($source[$field])) { continue; }
            $value=wp_unslash($source[$field]);
            if (is_string($value)) { $input[$field]=sanitize_text_field($value); }
        }
        return $input;
    }

    private static function completion_signature($token,$kind='mail_failed') {
        return hash_hmac('sha256','titan-zero-trades-front-desk/reschedule-result/v1|'.$token.'|'.$kind,wp_salt('auth'));
    }

    private function result($status, $tz_message, $context=array(), $preview=false, $mail_failed=false, $followup_failed=false) {
        return array('status'=>$status,'message'=>$tz_message,'context'=>$context,'preview'=>$preview,'mail_failed'=>$mail_failed,'followup_failed'=>$followup_failed);
    }

    public function handle_public_request() {
        // admin-post is an admin context, where the shortcode is not registered, so the engine is passed in at boot.
        $engine=$this->engine;
        if (!($engine instanceof TZFD_Scheduling_Plugin)) {
            $engine=$GLOBALS['tz_fd_plugin']??null;
        }
        if (!($engine instanceof TZFD_Scheduling_Plugin)) {
            $callback=$GLOBALS['shortcode_tags']['TZFD_FRONT_DESK']??null;
            $engine=is_array($callback)?($callback[0]??null):null;
        }
        nocache_headers();
        if (!headers_sent()) { header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow',true); }
        $method=isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])?strtoupper(tzfd_request_text( 'server', 'REQUEST_METHOD' )):'GET';
        // Only GET may preview, and only POST may mutate. Reject other verbs before any request fields are read.
        if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
            status_header( 405 );
            if ( ! headers_sent() ) { header( 'Allow: GET, POST' ); }
            $this->render( $this->result( 405, __( 'This request method is not supported.', 'titan-zero-trades-front-desk' ) ) );
            return;
        }
        // Explicit POST confirmation: missing, invalid or malformed booking-bound nonces fail closed.
        if ( 'POST' === $method ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST nonce (wp_verify_nonce, action tz_fd_reschedule_<booking_id>) is verified inside TZFD_Public_Confirmation_Guard::authorized() before any POST field is used.
            if ( ! TZFD_Public_Confirmation_Guard::authorized( $method, $_POST, 'tz_fd_reschedule_' ) ) {
                status_header( 403 );
                $this->render( $this->result( 403, __( 'Your confirmation has expired or is not valid. Review the new time again and confirm.', 'titan-zero-trades-front-desk' ) ) );
                exit;
            }
        }
        // GET reads only fill the form. Each value is unslashed and sanitized in read_fields().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- POST is nonce-checked by the guard above; GET is a read-only form preview. Values are unslashed and sanitized in read_fields().
        $input=self::read_fields('POST'===$method?$_POST:$_GET);
        $result=$this->request($engine,$input,$method);
        // Successful POSTs leave no sensitive POST history entry for Back/Refresh to resubmit.
        // The GET completion view verifies the same signed identity and the saved receipt again.
        if ($method==='POST' && $result['status']===200 && !$result['context'] && !headers_sent()) {
            $args=array_intersect_key($input,array_flip(array('booking_id','form_id','version','issued','expires','token','date','start')));
            $args['action']=self::ACTION;$args['completed']='1';
            if ($result['mail_failed']) {$args['notice']='mail_failed';$args['notice_token']=self::completion_signature($args['token']);}
            if ($result['followup_failed']) {$args['notice']='followup_failed';$args['notice_token']=self::completion_signature($args['token'],'followup_failed');}
            wp_safe_redirect(add_query_arg($args,admin_url('admin-post.php')),303);
            exit;
        }
        status_header($result['status']);
        $this->render($result);
        exit;
    }

    public function render($result) {
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>'.esc_html__( 'Change appointment time', 'titan-zero-trades-front-desk' ).'</title></head><body><main>';
        echo '<h1>'.esc_html__( 'Change appointment time', 'titan-zero-trades-front-desk' ).'</h1><p role="status">'.esc_html($result['message']).'</p>';
        if ($result['context']) {
            $c=$result['context'];
            echo '<p>'.esc_html__( 'Times use the WordPress site timezone. Opening hours, closed dates, booking limits, daylight-saving transitions, capacity and blocks are checked again on confirmation.', 'titan-zero-trades-front-desk' ).'</p>';
            echo '<form method="'.($result['preview']?'post':'get').'" action="'.esc_url(admin_url('admin-post.php')).'">';
            foreach (array('action'=>self::ACTION,'booking_id'=>$c['booking_id'],'form_id'=>$c['form_id'],'version'=>$c['version'],'issued'=>$c['issued'],'expires'=>$c['expires'],'token'=>$c['token']) as $tz_key=>$tz_value) {
                echo '<input type="hidden" name="'.esc_attr($tz_key).'" value="'.esc_attr((string)$tz_value).'">';
            }
            echo '<p><label for="tzfd-date">'.esc_html__( 'New appointment date', 'titan-zero-trades-front-desk' ).'</label> <input required type="date" id="tzfd-date" name="date" value="'.esc_attr($c['date']).'"'.($result['preview']?' readonly':'').'></p>';
            echo '<p><label for="tzfd-start">'.esc_html__( 'New start time', 'titan-zero-trades-front-desk' ).'</label> <input required type="time" step="60" id="tzfd-start" name="start" value="'.esc_attr($c['start']).'"'.($result['preview']?' readonly':'').'></p>';
            if ($result['preview']) { wp_nonce_field('tz_fd_reschedule_'.(int)$c['booking_id'],'_wpnonce',false); }
            echo $result['preview']?'<button type="submit" name="confirm_reschedule" value="1">'.esc_html__( 'Confirm appointment change', 'titan-zero-trades-front-desk' ).'</button>':'<button type="submit">'.esc_html__( 'Preview appointment change', 'titan-zero-trades-front-desk' ).'</button>';
            echo '</form>';
            if ($result['preview']) {
                $url=add_query_arg(array('action'=>self::ACTION,'booking_id'=>$c['booking_id'],'form_id'=>$c['form_id'],'version'=>$c['version'],'issued'=>$c['issued'],'expires'=>$c['expires'],'token'=>$c['token']),admin_url('admin-post.php'));
                echo '<p><a href="'.esc_url($url).'">'.esc_html__( 'Choose a different time', 'titan-zero-trades-front-desk' ).'</a></p>';
            }
        }
        echo '</main></body></html>';
    }
}
