<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/** Opt-in, single-appointment customer rescheduling. Independently implemented; GPL-2.0-only. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/class-tzfd-stored-data.php';

require_once __DIR__.'/class-tzfd-customer-cancellation.php';
require_once __DIR__.'/class-tzfd-capacity-lock.php';

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

    public static function token($id, $tzfd_form, $email, $version, $secret) {
        $id=TZFD_Customer_Cancellation::normalize_booking_id($id);
        $tzfd_form=TZFD_Customer_Cancellation::normalize_booking_id($tzfd_form);
        if (!$id || !$tzfd_form || !is_string($email) || !filter_var($email,FILTER_VALIDATE_EMAIL) ||
            !is_string($version) || !preg_match('/^[a-f0-9]{64}$/D',$version) || !is_string($secret) || $secret==='') { return ''; }
        return hash_hmac('sha256','titan-zero-trades-front-desk/customer-reschedule/v1|'.$id.'|'.$tzfd_form.'|'.strtolower($email).'|'.$version,$secret);
    }

    public static function decode($raw) {
        if (!is_string($raw) || $raw==='' || strlen($raw)>1048576) { return false; }
        try { $tzfd_data=TZFD_Stored_Data::decode($raw); }
        catch (Throwable $error) { return false; }
        return is_array($tzfd_data) && self::safe_values($tzfd_data) ? $tzfd_data : false;
    }

    private static function safe_values($tzfd_data, $depth=0) {
        if ($depth>32) { return false; }
        if (is_array($tzfd_data)) {
            foreach ($tzfd_data as $tzfd_value) { if (!self::safe_values($tzfd_value,$depth+1)) { return false; } }
            return true;
        }
        return is_null($tzfd_data) || is_scalar($tzfd_data);
    }

    public static function version($row) {
        return hash('sha256',serialize(array($row->posted_data,$row->data,$row->notifyto,(int)$row->formid)));
    }

    private static function fingerprint($tzfd_data, $text) {
        unset($tzfd_data[self::RECEIPT]);
        return hash('sha256',serialize(array($tzfd_data,$text)));
    }

    private static function recipient($tzfd_value) {
        return is_string($tzfd_value) && !preg_match('/[,;\s<>]/',$tzfd_value) && is_email($tzfd_value) ? $tzfd_value : '';
    }

    /** Invoked only by the actual customer-copy branch, never admin or payer delivery. */
    public function customer_copy($tzfd_message, $row, $headers, $engine) {
        if (!is_string($tzfd_message) || !is_object($row) || !($engine instanceof TZFD_Scheduling_Plugin) ||
            self::recipient($row->notifyto)==='' || $engine->get_option('cu_enable_copy_to_user','')!=='true') { return $tzfd_message; }
        $header_text=is_array($headers)?implode("\n",$headers):$headers;
        if (!is_string($header_text) || preg_match('/(^|[\r\n])\s*(cc|bcc)\s*:/i',$header_text)) { return $tzfd_message; }
        try { $tzfd_data=self::decode($row->posted_data); $this->reconcile($engine,$row,$tzfd_data,null,null); }
        catch (InvalidArgumentException $error) { return $tzfd_message; }
        $version=self::version($row);
        $token=self::token($row->id,$row->formid,$row->notifyto,$version,wp_salt('auth'));
        if ($token==='') { return $tzfd_message; }
        $url=add_query_arg(array('action'=>self::ACTION,'booking_id'=>(int)$row->id,'form_id'=>(int)$row->formid,
            'version'=>$version,'token'=>$token),admin_url('admin-post.php'));
        $tzfd_label=__('Change appointment time','titan-zero-trades-front-desk');
        return $tzfd_message.($engine->get_option('cu_emailformat','text')==='html'
            ? '<p><a href="'.esc_url($url).'">'.esc_html($tzfd_label).'</a></p>' : "\n\n".$tzfd_label.":\n".$url);
    }

    /** Validate and reconstruct the supported donor representation without re-extracting/repricing it. */
    private function reconcile($engine, $row, $tzfd_data, $tzfd_date, $start) {
        if (!is_array($tzfd_data) || array_key_exists('lock',$tzfd_data) || !isset($tzfd_data['apps']) ||
            !is_array($tzfd_data['apps']) || array_keys($tzfd_data['apps'])!==array(0) || !is_array($tzfd_data['apps'][0])) {
            throw new InvalidArgumentException('unsupported_booking');
        }
        $tzfd_app=$tzfd_data['apps'][0];
        foreach (array('id','cancelled','serviceindex','service','duration','price','date','slot','field','quant','sid') as $tzfd_key) {
            if (!array_key_exists($tzfd_key,$tzfd_app)) { throw new InvalidArgumentException('unsupported_booking'); }
        }
        if (TZFD_Scheduling::integer($tzfd_app['id'],1,1)!==1 || TZFD_Scheduling::integer($tzfd_app['serviceindex'],0)===null ||
            (!is_int($tzfd_app['sid']) && !is_string($tzfd_app['sid'])) ||
            (!is_int($tzfd_app['price']) && !is_float($tzfd_app['price']) && !is_string($tzfd_app['price'])) || !is_numeric($tzfd_app['price']) || !is_finite((float)$tzfd_app['price']) || (float)$tzfd_app['price']<0 ||
            (isset($tzfd_app['military']) && !in_array($tzfd_app['military'],array(0,1,'0','1',''),true)) || !in_array($tzfd_app['cancelled'],array('','Approved','Pending'),true) ||
            !is_string($tzfd_app['service']) || !is_string($tzfd_app['field']) || !is_scalar($tzfd_app['sid']) ||
            (isset($tzfd_data['formid']) && TZFD_Customer_Cancellation::normalize_booking_id($tzfd_data['formid'])!==(int)$row->formid)) {
            throw new InvalidArgumentException('unsupported_booking');
        }
        // These alternative extension representations need their own adapter, not a partial edit.
        foreach (array('start','end','starttime','endtime','date_end','start_date','end_date','app_start','app_end','app_start_1','app_end_1','app_startdate_1','app_enddate_1','recurrence','series') as $tzfd_key) {
            if (array_key_exists($tzfd_key,$tzfd_app) || array_key_exists($tzfd_key,$tzfd_data)) { throw new InvalidArgumentException('unsupported_booking'); }
        }
        foreach (array_keys($tzfd_data) as $tzfd_key) {
            if (is_string($tzfd_key) && preg_match('/^app_.*_([0-9]+)$/D',$tzfd_key,$m) && $m[1]!=='1') { throw new InvalidArgumentException('unsupported_booking'); }
        }
        $decoded=json_decode($engine->cleanJSON($engine->get_option('form_structure',TZFD_BOOK_DEFAULT_form_structure)));
        if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) { throw new InvalidArgumentException('invalid_configuration'); }
        $fields=$decoded[0]; $map=TZFD_Scheduling::configuration($fields);
        if (!isset($map[$tzfd_app['field']])) { throw new InvalidArgumentException('unknown_service'); }
        $index=null;
        if ((string)$tzfd_app['sid']!=='') {
            foreach ($map[$tzfd_app['field']]['services'] as $tzfd_key=>$service) {
                if ($service['sid']===(string)$tzfd_app['sid']) { $index=$tzfd_key; break; }
            }
        } else {
            // Services without a stored ID are matched by position, as the scheduler does.
            $requested=TZFD_Scheduling::integer($tzfd_app['serviceindex'],0);
            if ($requested!==null && isset($map[$tzfd_app['field']]['services'][$requested]) &&
                $map[$tzfd_app['field']]['services'][$requested]['sid']==='') { $index=$requested; }
        }
        if ($index===null) { throw new InvalidArgumentException('unknown_service'); }
        $service=$map[$tzfd_app['field']]['services'][$index];
        $interval=TZFD_Scheduling::interval($tzfd_app['date'],$tzfd_app['slot']);
        if (!self::same_day_slot($tzfd_app['slot'])) { throw new InvalidArgumentException('unsupported_overnight'); }
        if (TZFD_Scheduling::integer($tzfd_app['duration'],1,1440)!==$interval['duration'] || $service['duration']!==$interval['duration'] ||
            TZFD_Scheduling::integer($tzfd_app['quant'],1,$service['capacity'])===null) { throw new InvalidArgumentException('unsupported_booking'); }
        $new=$tzfd_app;
        if ($tzfd_date!==null) {
            TZFD_Scheduling::date($tzfd_date);
            $new['date']=$tzfd_date; $new['slot']=TZFD_Scheduling::slot($start,$interval['duration']);
            if (!self::same_day_slot($new['slot'])) { throw new InvalidArgumentException('unsupported_overnight'); }
            $new['serviceindex']=$index;
        }
        $old_text=$engine->get_appointments_text(array($tzfd_app)); $new_text=$engine->get_appointments_text(array($new));
        $old_flat=$this->flat($engine,$tzfd_app); $new_flat=$this->flat($engine,$new);
        foreach ($old_flat as $tzfd_key=>$tzfd_value) {
            if (!array_key_exists($tzfd_key.'_1',$tzfd_data) || $tzfd_data[$tzfd_key.'_1']!==$tzfd_value) { throw new InvalidArgumentException('inconsistent_booking'); }
            $tzfd_data[$tzfd_key.'_1']=$new_flat[$tzfd_key];
            if (array_key_exists($tzfd_key,$tzfd_data)) {
                if ($tzfd_data[$tzfd_key]!==$tzfd_value) { throw new InvalidArgumentException('inconsistent_booking'); }
                $tzfd_data[$tzfd_key]=$new_flat[$tzfd_key];
            }
        }
        foreach (array('status'=>'cancelled','service'=>'service','duration'=>'duration','price'=>'price','quantity'=>'quant') as $flat=>$nested) {
            if (!array_key_exists('app_'.$flat.'_1',$tzfd_data) || $tzfd_data['app_'.$flat.'_1']!==$tzfd_app[$nested]) { throw new InvalidArgumentException('inconsistent_booking'); }
            if (array_key_exists('app_'.$flat,$tzfd_data) && $tzfd_data['app_'.$flat]!==$tzfd_app[$nested]) { throw new InvalidArgumentException('inconsistent_booking'); }
        }
        if (!isset($tzfd_data[$tzfd_app['field']]) || $tzfd_data[$tzfd_app['field']]!==$old_text) { throw new InvalidArgumentException('inconsistent_booking'); }
        $appointment_fields=array();
        foreach ($fields as $field) {
            if (isset($field->ftype,$field->name) && $field->ftype==='fapp' && array_key_exists($field->name,$tzfd_data)) {
                $appointment_fields[$field->name]=true;
                if ($tzfd_data[$field->name]!==$old_text) { throw new InvalidArgumentException('inconsistent_booking'); }
                $tzfd_data[$field->name]=$new_text;
            }
        }
        foreach ($tzfd_data as $tzfd_key=>$tzfd_value) {
            if (is_string($tzfd_key) && !isset($appointment_fields[$tzfd_key]) && $tzfd_value===$old_text) {
                throw new InvalidArgumentException('inconsistent_booking');
            }
        }
        $prefix=__('Appointments','titan-zero-trades-front-desk').":\n".$old_text."\n";
        if (!is_string($row->data) || strncmp($row->data,$prefix,strlen($prefix))!==0) { throw new InvalidArgumentException('inconsistent_booking'); }
        $text=__('Appointments','titan-zero-trades-front-desk').":\n".$new_text."\n".substr($row->data,strlen($prefix));
        $tzfd_data['apps'][0]=$new;
        $assessment=$new; $assessment['serviceindex']=$index;
        return array('data'=>$tzfd_data,'text'=>$text,'appointment'=>$assessment,'original'=>$tzfd_app);
    }

    /** The inherited 12-hour formatter cannot truthfully label next-day end times. */
    public static function same_day_slot($slot) {
        if (!is_string($slot) || !preg_match('/^([0-9]{2}):([0-9]{2})\/([0-9]{2}):([0-9]{2})$/D',$slot,$m)) { return false; }
        return (int)$m[1]<24 && (int)$m[2]<60 && (int)$m[3]<24 && (int)$m[4]<60 && substr($slot,6)>substr($slot,0,5);
    }

    private function flat($engine, $tzfd_app) {
        $parts=explode('/',$tzfd_app['slot']); $non_military=empty($tzfd_app['military']);
        return array('app_date'=>$engine->format_date($tzfd_app['date']), 'app_slot'=>$tzfd_app['slot'],
            'app_starttime'=>$engine->format12hours($parts[0],$non_military), 'app_endtime'=>$engine->format12hours($parts[1],$non_military));
    }

    /** Public capability-link route. is_admin() here does not grant any permission. */
    public function request($engine, $input, $method) {
        global $wpdb;
        if (!in_array($method,array('GET','POST'),true)) { return $this->result(405,'Use the appointment change form.'); }
        if (!($engine instanceof TZFD_Scheduling_Plugin) || !is_array($input)) { return $this->result(503,'Appointment changes are temporarily unavailable.'); }
        $id=TZFD_Customer_Cancellation::normalize_booking_id($input['booking_id']??null);
        $tzfd_form=TZFD_Customer_Cancellation::normalize_booking_id($input['form_id']??null);
        $version=$input['version']??''; $token=$input['token']??'';
        if (!$id || !$tzfd_form || !is_string($version) || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) { return $this->result(404,'This appointment change link is invalid.'); }
        $row=$wpdb->get_row($wpdb->prepare('SELECT id, formid, notifyto, posted_data, data FROM %i WHERE id=%d AND formid=%d',$wpdb->prefix.'tzfd_messages',$id,$tzfd_form));
        if (!empty($wpdb->last_error)) { return $this->result(503,'The booking could not be read. Please try again.'); }
        if (!is_object($row) || self::recipient($row->notifyto)==='') { return $this->result(404,'This appointment change link is invalid.'); }
        $expected=self::token($id,$tzfd_form,$row->notifyto,$version,wp_salt('auth'));
        if ($expected==='' || !hash_equals($expected,$token)) { return $this->result(404,'This appointment change link is invalid.'); }
        $tzfd_data=self::decode($row->posted_data);
        $tzfd_date=$input['date']??null; $start=$input['start']??null;
        if (self::version($row)!==$version) {
            $receipt=is_array($tzfd_data)?($tzfd_data[self::RECEIPT]??null):null;
            $completed=$method==='GET' && ($input['completed']??null)==='1';
            if ((($method==='POST' && ($input['confirm_reschedule']??null)==='1') || $completed) && is_array($receipt) &&
                ($receipt['from_version']??null)===$version && ($receipt['date']??null)===$tzfd_date && ($receipt['start']??null)===$start &&
                ($receipt['fingerprint']??null)===self::fingerprint($tzfd_data,$row->data)) {
                $tzfd_message='This appointment change is already saved. No further change was made. ';
                $tzfd_message.=($tzfd_data['apps'][0]['cancelled']==='Pending'?'Your request remains pending review. ':'Your appointment time has changed. ');
                $tzfd_message.='Saved time: '.$tzfd_data['app_date_1'].' '.$tzfd_data['app_starttime_1'].' to '.$tzfd_data['app_endtime_1'].'.';
                if (($input['notice']??null)==='mail_failed' && is_string($input['notice_token']??null) &&
                    hash_equals(self::completion_signature($token),$input['notice_token'])) {
                    $tzfd_message.=' The change was saved, but the email notice could not be sent.';
                }
                return $this->result(200,$tzfd_message);
            }
            return $this->result(409,'This booking has changed. Please use the latest appointment change link or contact the booking administrator.');
        }
        $original_id=$engine->getId(); $buffered_id=$engine->option_buffered_id; $buffered_item=$engine->option_buffered_item;
        $engine->setId($tzfd_form); $engine->option_buffered_id=-1; $engine->option_buffered_item=false;
        try {
            $base=$this->reconcile($engine,$row,$tzfd_data,null,null);
            $context=array('booking_id'=>$id,'form_id'=>$tzfd_form,'version'=>$version,'token'=>$token,
                'date'=>$base['original']['date'],'start'=>substr($base['original']['slot'],0,5));
            if ($method==='GET' && ($input['completed']??null)==='1') {
                if ($tzfd_date===$base['original']['date'] && $start===substr($base['original']['slot'],0,5)) {
                    return $this->result(200,'This is already your appointment time. No change was made.');
                }
                return $this->result(409,'This appointment change has not been saved. Use the appointment change form to preview and confirm.');
            }
            if ($method==='GET' && $tzfd_date===null && $start===null) {
                return $this->result(200,'Choose a new date and start time. Nothing changes until you confirm.', $context);
            }
            try { $move=$this->reconcile($engine,$row,$tzfd_data,$tzfd_date,$start); }
            catch (InvalidArgumentException $error) {
                if ($error->getMessage()==='unsupported_overnight') { return $this->result(400,'Choose a time that starts and ends on the same calendar day. Overnight appointment changes are not supported. Your appointment has not changed.',$context); }
                return $this->result(400,'Enter a valid date and start time. Your appointment has not changed.',$context); }
            if ($tzfd_date===null || $start===null) { return $this->result(400,'Enter a date and start time.',$context); }
            $context['date']=$tzfd_date; $context['start']=$start;
            $assessment=$engine->assess_existing_appointment(array($move['appointment']),$id);
            if (!$assessment['ok']) { return $this->result(409,'That time conflicts with booking capacity or a block. Choose another time. Your appointment has not changed.',$context); }
            if ($method==='GET') {
                return $this->result(200,'Review the new appointment time: '.$engine->get_appointments_text(array($move['appointment'])).($base['original']['cancelled']==='Pending'?' This request will remain pending review.':''),$context,true);
            }
            if (($input['confirm_reschedule']??null)!=='1') { return $this->result(400,'Preview the time and use Confirm appointment change.',$context); }
            // The confirm POST changes the booking: verify the nonce for this booking before any lock, write or notice.
            if (!wp_verify_nonce($input['_wpnonce']??'','tzfd_reschedule_'.$id)) { return $this->result(403,esc_html__('Your confirmation has expired or is not valid. Review the new time again and confirm.','titan-zero-trades-front-desk'),$context); }
            if ($move['appointment']['date']===$base['original']['date'] && $move['appointment']['slot']===$base['original']['slot']) {
                return $this->result(200,'This is already your appointment time. No change was made.');
            }
            $capacity_lock=TZFD_Capacity_Lock::acquire($wpdb,$tzfd_form);
            if ($capacity_lock===false) {
                return $this->result(503,'Availability could not be checked. Your appointment has not changed. Please try again.',$context);
            }
            try {
                // Recheck after acquiring the shared reservation lock. The earlier
                // assessment was only a display-time preview.
                $final_assessment=$engine->assess_existing_appointment(array($move['appointment']),$id);
                if (!$final_assessment['ok']) {
                    return $this->result(409,'That time conflicts with booking capacity or a block. Choose another time. Your appointment has not changed.',$context);
                }
                $move['data'][self::RECEIPT]=array('from_version'=>$version,'date'=>$tzfd_date,'start'=>$start,
                    'fingerprint'=>self::fingerprint($move['data'],$move['text']));
                // Binary comparison is important: the table's text collation is not a version comparator.
                $changed=$wpdb->query($wpdb->prepare('UPDATE %i SET posted_data=%s, data=%s WHERE id=%d AND formid=%d AND BINARY posted_data=BINARY %s AND BINARY data=BINARY %s AND BINARY notifyto=BINARY %s',
                    $wpdb->prefix.'tzfd_messages',serialize($move['data']),$move['text'],$id,$tzfd_form,$row->posted_data,$row->data,$row->notifyto));
                if ($changed===false) { return $this->result(503,'The change could not be saved. Your original appointment has been kept. Please try again.',$context); }
                if ($changed!==1) { return $this->result(409,'This booking changed before the appointment change could be saved. Please use the latest link.'); }
            } finally {
                TZFD_Capacity_Lock::release($wpdb,$capacity_lock);
            }
            do_action('tzfd_cache_clean',$tzfd_form);
            do_action('titan_zero_front_desk_booking_rescheduled',$id,$tzfd_form,array('old_date'=>$base['original']['date'],'old_slot'=>$base['original']['slot'],
                'date'=>$tzfd_date,'slot'=>$move['appointment']['slot'],'status'=>$base['original']['cancelled']));
            $pending=$base['original']['cancelled']==='Pending';
            $notice=$pending?'Your requested appointment time has changed. Your request remains pending review.':'Your appointment time has changed.';
            $mail_failed=false;
            if ($engine->get_option('cu_enable_copy_to_user','')==='true') {
                $tzfd_updated=clone $row; $tzfd_updated->posted_data=serialize($move['data']); $tzfd_updated->data=$move['text'];
                $headers=array('Content-Type: text/plain; charset=UTF-8');
                $body=$notice."\n\nBooking reference: #".$id."\n".$engine->get_appointments_text(array($move['appointment']));
                // A fresh version-bound link belongs only in this permitted customer notice.
                $format=$engine->get_option('cu_emailformat','text');
                if ($format==='html') { $body=nl2br(esc_html($body)); $headers=array('Content-Type: text/html; charset=UTF-8'); }
                $body=$this->customer_copy($body,$tzfd_updated,$headers,$engine);
                $sent=wp_mail($row->notifyto,__('Appointment time updated','titan-zero-trades-front-desk'),$body,$headers);
                $mail_failed=!$sent;
                if (!$sent) { $notice.=' The change was saved, but the email notice could not be sent.'; }
            }
            return $this->result(200,$notice,array(),false,$mail_failed);
        } catch (InvalidArgumentException $error) {
            if ($error->getMessage()==='unsupported_overnight') { return $this->result(409,'Overnight appointments cannot be changed with this form. Contact the booking administrator. Your appointment has not changed.'); }
            if ($error->getMessage()==='booking_storage_unavailable') { return $this->result(503,'Availability could not be checked. Your appointment has not changed. Please try again.'); }
            return $this->result(409,'This booking cannot be changed with this single-appointment form. Contact the booking administrator.');
        } finally {
            $engine->setId($original_id); $engine->option_buffered_id=$buffered_id; $engine->option_buffered_item=$buffered_item;
        }
    }

    /** Reads only the fields this handler uses, one at a time, each sanitized. Missing or non-text values are left out. */
    private static function read_fields($source) {
        $input=array();
        foreach (array('booking_id','form_id','version','token','date','start','completed','confirm_reschedule','notice','notice_token','_wpnonce') as $field) {
            if (!isset($source[$field])) { continue; }
            $value=wp_unslash($source[$field]);
            if (is_string($value)) { $input[$field]=sanitize_text_field($value); }
        }
        return $input;
    }

    private static function completion_signature($token) {
        return hash_hmac('sha256','titan-zero-trades-front-desk/reschedule-result/v1|'.$token.'|mail_failed',wp_salt('auth'));
    }

    private function result($status, $tzfd_message, $context=array(), $preview=false, $mail_failed=false) {
        return array('status'=>$status,'message'=>$tzfd_message,'context'=>$context,'preview'=>$preview,'mail_failed'=>$mail_failed);
    }

    public function handle_public_request() {
        // admin-post is an admin context, where the shortcode is not registered, so the engine is passed in at boot.
        $engine=$this->engine;
        if (!($engine instanceof TZFD_Scheduling_Plugin)) {
            $engine=$GLOBALS['tzfd_plugin']??null;
        }
        if (!($engine instanceof TZFD_Scheduling_Plugin)) {
            $callback=$GLOBALS['shortcode_tags']['TZFD_FRONT_DESK']??null;
            $engine=is_array($callback)?($callback[0]??null):null;
        }
        nocache_headers();
        if (!headers_sent()) { header('Referrer-Policy: no-referrer'); header('X-Robots-Tag: noindex, nofollow',true); }
        $method=isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])?strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))):'GET';
        // The confirm POST changes the booking. Its nonce is bound to the booking, so it is checked before any POST value is read.
        if ('POST'===$method && !wp_verify_nonce(
            isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '',
            'tzfd_reschedule_'.TZFD_Customer_Cancellation::normalize_booking_id(isset($_POST['booking_id']) ? sanitize_text_field(wp_unslash($_POST['booking_id'])) : '')
        )) {
            status_header(403);
            $this->render($this->result(403,esc_html__('Your confirmation has expired or is not valid. Review the new time again and confirm.','titan-zero-trades-front-desk')));
            exit;
        }
        // GET reads only fill the form. Each value is unslashed and sanitized in read_fields().
        $input=self::read_fields('POST'===$method?$_POST:$_GET);
        $result=$this->request($engine,$input,$method);
        // Successful POSTs leave no sensitive POST history entry for Back/Refresh to resubmit.
        // The GET completion view verifies the same signed identity and the saved receipt again.
        if ($method==='POST' && $result['status']===200 && !$result['context'] && !headers_sent()) {
            $args=array_intersect_key($input,array_flip(array('booking_id','form_id','version','token','date','start')));
            $args['action']=self::ACTION;$args['completed']='1';
            if ($result['mail_failed']) {$args['notice']='mail_failed';$args['notice_token']=self::completion_signature($args['token']);}
            wp_safe_redirect(add_query_arg($args,admin_url('admin-post.php')),303);
            exit;
        }
        status_header($result['status']);
        $this->render($result);
        exit;
    }

    public function render($result) {
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer"><title>'.esc_html__('Change appointment time','titan-zero-trades-front-desk').'</title></head><body><main>';
        echo '<h1>'.esc_html__('Change appointment time','titan-zero-trades-front-desk').'</h1><p role="status">'.esc_html($result['message']).'</p>';
        if ($result['context']) {
            $c=$result['context'];
            echo '<p>'.esc_html__('Times use the WordPress site timezone. Opening hours, closed dates, booking limits, daylight-saving transitions, capacity and blocks are checked again on confirmation.','titan-zero-trades-front-desk').'</p>';
            echo '<form method="'.($result['preview']?'post':'get').'" action="'.esc_url(admin_url('admin-post.php')).'">';
            foreach (array('action'=>self::ACTION,'booking_id'=>$c['booking_id'],'form_id'=>$c['form_id'],'version'=>$c['version'],'token'=>$c['token']) as $tzfd_key=>$tzfd_value) {
                echo '<input type="hidden" name="'.esc_attr($tzfd_key).'" value="'.esc_attr((string)$tzfd_value).'">';
            }
            echo '<p><label for="tzfd-date">'.esc_html__('New appointment date','titan-zero-trades-front-desk').'</label> <input required type="date" id="tzfd-date" name="date" value="'.esc_attr($c['date']).'"'.($result['preview']?' readonly':'').'></p>';
            echo '<p><label for="tzfd-start">'.esc_html__('New start time','titan-zero-trades-front-desk').'</label> <input required type="time" step="60" id="tzfd-start" name="start" value="'.esc_attr($c['start']).'"'.($result['preview']?' readonly':'').'></p>';
            if ($result['preview']) { wp_nonce_field('tzfd_reschedule_'.(int)$c['booking_id'],'_wpnonce',false); }
            echo $result['preview']?'<button type="submit" name="confirm_reschedule" value="1">'.esc_html__('Confirm appointment change','titan-zero-trades-front-desk').'</button>':'<button type="submit">'.esc_html__('Preview appointment change','titan-zero-trades-front-desk').'</button>';
            echo '</form>';
            if ($result['preview']) {
                $url=add_query_arg(array('action'=>self::ACTION,'booking_id'=>$c['booking_id'],'form_id'=>$c['form_id'],'version'=>$c['version'],'token'=>$c['token']),admin_url('admin-post.php'));
                echo '<p><a href="'.esc_url($url).'">'.esc_html__('Choose a different time','titan-zero-trades-front-desk').'</a></p>';
            }
        }
        echo '</main></body></html>';
    }
}
