<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.
/** Thin scheduling adapter around the retained donor engine. GPL-2.0-only. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/class-tzfd-stored-data.php';

require_once __DIR__ . '/class-tzfd-scheduling.php';
require_once __DIR__ . '/class-tzfd-calendar.php';
require_once __DIR__ . '/class-tzfd-capacity-lock.php';

class TZFD_Scheduling_Plugin extends TZFD_FrontDeskPlugin {
    private $titan_zero_front_desk_invalid_selection = false;
    private $titan_zero_front_desk_capacity_submissions = array();

    /** Acquire before the registered engine's final occupancy read. */
    public function begin_capacity_reservation($tzfd_params) {
        global $wpdb;
        $has_apps = is_array($tzfd_params) && isset($tzfd_params['apps']) && is_array($tzfd_params['apps']) && count($tzfd_params['apps']) > 0;
        $nested_capacity_write = false;
        foreach ($this->titan_zero_front_desk_capacity_submissions as $frame) {
            if (!empty($frame['has_apps'])) { $nested_capacity_write = true; break; }
        }
        $frame = array('has_apps'=>$has_apps, 'lock'=>false, 'failed'=>false);
        if ($has_apps) {
            if ($nested_capacity_write) {
                // A nested write on this PHP request cannot acquire a second
                // connection-scoped reservation; fail it without releasing its parent.
                $frame['failed'] = true;
            } else {
                $frame['lock'] = TZFD_Capacity_Lock::acquire($wpdb, (int)$this->item);
                $frame['failed'] = ($frame['lock'] === false);
            }
        }
        $this->titan_zero_front_desk_capacity_submissions[] = $frame;
        return !$frame['failed'];
    }

    /** The donor calls this after the inserted row is visible to later capacity reads. */
    public function finish_capacity_reservation($tzfd_params = array()) {
        $this->pop_capacity_submission();
    }

    private function pop_capacity_submission() {
        global $wpdb;
        if (empty($this->titan_zero_front_desk_capacity_submissions)) { return; }
        $frame = array_pop($this->titan_zero_front_desk_capacity_submissions);
        if (isset($frame['lock']) && is_array($frame['lock'])) {
            TZFD_Capacity_Lock::release($wpdb, $frame['lock']);
        }
    }

    /** Normalise before the parent calculates price; never silently accept a partial bad cart. */
    public function extract_appointments($tzfd_form, $tzfd_data, $sequence) {
        $this->titan_zero_front_desk_invalid_selection = false;
        try {
            if (!is_array($tzfd_data) || !is_string($sequence)) { throw new InvalidArgumentException('invalid_submission'); }
            $config=TZFD_Scheduling::configuration($tzfd_form);
            $safe_form=array(); $selected_count=0;
            foreach ($tzfd_form as $field) {
                if (!is_object($field)) { throw new InvalidArgumentException('invalid_configuration'); }
                $copy=clone $field;
                if (!isset($field->ftype) || $field->ftype !== 'fapp') { $safe_form[]=$copy; continue; }
                $copy->services=array();
                foreach($field->services as $index=>$service){
                    $safe=clone $service;$safe->capacity=$config[$field->name]['services'][$index]['capacity'];$copy->services[$index]=$safe;
                }
                $tzfd_key=$field->name.$sequence;
                if (!isset($tzfd_data[$tzfd_key]) || $tzfd_data[$tzfd_key]==='') { $safe_form[]=$copy; continue; }
                if (!is_string($tzfd_data[$tzfd_key]) || strlen($tzfd_data[$tzfd_key])>32768) { throw new InvalidArgumentException('invalid_selection'); }
                $clean=array();
                foreach(explode(';',$tzfd_data[$tzfd_key]) as $tzfd_selection){
                    if(trim($tzfd_selection)===''){continue;}
                    if(++$selected_count>TZFD_Scheduling::MAX_OCCURRENCES){throw new InvalidArgumentException('selection_limit');}
                    $parts=preg_split('/\s+/',trim($tzfd_selection));
                    if(count($parts)<3 || count($parts)>4){throw new InvalidArgumentException('invalid_selection');}
                    $index=TZFD_Scheduling::integer($parts[2],0);
                    if($index===null || !isset($config[$field->name]['services'][$index])){throw new InvalidArgumentException('unknown_service');}
                    $service=$config[$field->name]['services'][$index];
                    $range=TZFD_Scheduling::interval($parts[0],$parts[1]);
                    if($range['duration']!==$service['duration'] && !defined('TZFD_HOUR_BLOCK_TIMES_PROCESS')){throw new InvalidArgumentException('duration_mismatch');}
                    $quantity=TZFD_Scheduling::quantity(isset($parts[3])?$parts[3]:1,$service['capacity']);
                    $clean[]=$parts[0].' '.$parts[1].' '.$index.' '.$quantity;
                }
                if(count($clean)===0){throw new InvalidArgumentException('empty_selection');}
                $tzfd_data[$tzfd_key]=implode(';',$clean);$safe_form[]=$copy;
            }
            return parent::extract_appointments($safe_form,$tzfd_data,$sequence);
        } catch(InvalidArgumentException $error) {
            $this->titan_zero_front_desk_invalid_selection=true;
            return array();
        }
    }

    /** Reject before the donor pre-insert hooks; explicit administrator blocks retain their semantics. */
    public function calendar_selection_is_valid($apps) {
        if ($this->titan_zero_front_desk_invalid_selection) { return false; }
        if (defined('TZFD_HOUR_BLOCK_TIMES_PROCESS')) { return true; }
        try { $result=TZFD_Calendar::assess($apps,$this->scheduling_fields(),$this->get_option('date_format','mm/dd/yy')); return $result['ok']; }
        catch (InvalidArgumentException $error) { return false; }
    }

    private function scheduling_fields() {
        $decoded=json_decode($this->cleanJSON($this->get_option('form_structure',TZFD_BOOK_DEFAULT_form_structure)));
        if(!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])){throw new InvalidArgumentException('invalid_configuration');}
        return $decoded[0];
    }

    /** Query every relevant date, never just the newest 2,000 submissions. No customer data is returned to the preview. */
    private function stored_appointments($apps, $skip_id=0) {
        global $wpdb;
        $dates=array();
        foreach($apps as $tzfd_app){
            $day=TZFD_Scheduling::date($tzfd_app['date']);
            // One-day visits and up to one-day buffers can touch three adjacent dates.
            for($tzfd_offset=-3;$tzfd_offset<=3;$tzfd_offset++){$dates[$day->modify(($tzfd_offset>=0?'+':'').$tzfd_offset.' days')->format('Y-m-d')]=true;}
        }
        $stored=array();$seen=array();
        // One literal prepared query per date; results are de-duplicated by id below.
        foreach(array_keys($dates)as$tzfd_date){
            $tzfd_rows=$wpdb->get_results($wpdb->prepare(
                'SELECT id, notifyto, posted_data FROM %i WHERE formid=%d AND posted_data LIKE %s',
                $wpdb->prefix.'tzfd_messages',(int)$this->item,'%'.$wpdb->esc_like($tzfd_date).'%'
            ));
            if(!is_array($tzfd_rows) || !empty($wpdb->last_error)){throw new InvalidArgumentException('booking_storage_unavailable');}
            foreach($tzfd_rows as$row){
                $id=isset($row->id)?(int)$row->id:0;
                if($id===$skip_id || isset($seen[$id])){continue;}$seen[$id]=true;
                if(!isset($row->posted_data) || !is_string($row->posted_data)){throw new InvalidArgumentException('invalid_stored_payload');}
                // Never instantiate objects from the serialized legacy storage envelope.
                $tzfd_data=TZFD_Stored_Data::project( $row->posted_data, array( 'apps', 'lock' ) );
                if(!is_array($tzfd_data)){throw new InvalidArgumentException('invalid_stored_payload');}
                if(!isset($tzfd_data['apps'])){continue;}
                if(!is_array($tzfd_data['apps'])){throw new InvalidArgumentException('invalid_stored_payload');}
                foreach($tzfd_data['apps']as$tzfd_app){
                    if(!is_array($tzfd_app)){throw new InvalidArgumentException('invalid_stored_appointment');}
                    if(isset($tzfd_data['lock'])){$tzfd_app['_locked']=true;}
                    // Single-slot admin blocks reserve their selected service/quantity.
                    // Bulk blocks already carry empty field/-1 service identity and
                    // are conservatively expanded across fields by assess().
                    $stored[]=$tzfd_app;
                }
            }
        }
        return $stored;
    }

    /** Non-destructive assessment for an existing booking. Never invokes insert rollback. */
    public function assess_existing_appointment($apps, $booking_id) {
        $id=TZFD_Scheduling::integer($booking_id,1,PHP_INT_MAX);
        if ($id===null || !is_array($apps) || count($apps)!==1) { throw new InvalidArgumentException('invalid_appointments'); }
        $fields=$this->scheduling_fields();
        $calendar=TZFD_Calendar::assess($apps,$fields,$this->get_option('date_format','mm/dd/yy'));
        if (!$calendar['ok']) { return $calendar; }
        $baseline=TZFD_Scheduling::assess($apps,array(),$fields);
        if (!$baseline['ok']) { return $baseline; }
        return TZFD_Scheduling::assess($apps,$this->stored_appointments($apps,$id),$fields);
    }

    private function rollback_failed_capacity_check($is_double_check) {
        global $wpdb;
        $delete_id = TZFD_Scheduling::integer($is_double_check, 1, PHP_INT_MAX);
        if ($delete_id !== null) {
            $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'tzfd_messages WHERE id=%d AND formid=%d',
                $delete_id, (int)$this->item));
        }
    }

    /** Serialize supported booking checks through the matching database write. */
    public function performAdvancedDoubleBookingVerification($tzfd_params, $sequence='_1', $is_double_check=false) {
        global $wpdb;
        $submission = !empty($this->titan_zero_front_desk_capacity_submissions);
        $lock = false;
        $standalone = false;

        if ($this->titan_zero_front_desk_invalid_selection || !is_array($tzfd_params)) {
            $this->rollback_failed_capacity_check($is_double_check);
            if ($submission) { $this->pop_capacity_submission(); }
            return false;
        }
        if (!isset($tzfd_params['apps'])) { return true; }
        if (!is_array($tzfd_params['apps'])) {
            $this->rollback_failed_capacity_check($is_double_check);
            if ($submission) { $this->pop_capacity_submission(); }
            return false;
        }
        if (count($tzfd_params['apps']) === 0) { return true; }

        if ($submission) {
            $index = count($this->titan_zero_front_desk_capacity_submissions) - 1;
            $frame = $this->titan_zero_front_desk_capacity_submissions[$index];
            if (empty($frame['has_apps']) || !empty($frame['failed']) || !is_array($frame['lock'])) {
                $this->rollback_failed_capacity_check($is_double_check);
                $this->pop_capacity_submission();
                return false;
            }
            $lock = $frame['lock'];
        } else {
            $lock = TZFD_Capacity_Lock::acquire($wpdb, (int)$this->item);
            if ($lock === false) {
                $this->rollback_failed_capacity_check($is_double_check);
                return false;
            }
            $standalone = true;
        }

        $ok = false;
        try {
            $fields = $this->scheduling_fields();
            $custom_duration = defined('TZFD_HOUR_BLOCK_TIMES_PROCESS');
            $baseline = $custom_duration ? array('ok'=>true) :
                TZFD_Calendar::assess($tzfd_params['apps'], $fields, $this->get_option('date_format','mm/dd/yy'));
            if ($baseline['ok']) {
                $baseline = TZFD_Scheduling::assess($tzfd_params['apps'], array(), $fields, $custom_duration);
            }
            if ($baseline['ok']) {
                $skip = TZFD_Scheduling::integer($is_double_check, 1, PHP_INT_MAX);
                $stored = $this->stored_appointments($tzfd_params['apps'], $skip === null ? 0 : $skip);
                $result = TZFD_Scheduling::assess($tzfd_params['apps'], $stored, $fields, $custom_duration);
                $ok = $result['ok'];
            }
        } catch (InvalidArgumentException $error) {
            $ok = false;
        }

        $delete_id = TZFD_Scheduling::integer($is_double_check, 1, PHP_INT_MAX);
        if (!$ok && $delete_id !== null) {
            // Preserve the inherited rollback, scoped to this form and supplied row.
            $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->prefix.'tzfd_messages WHERE id=%d AND formid=%d',
                $delete_id, (int)$this->item));
        }

        // A normal registered submission keeps the lock until the donor's
        // post-insert action. Direct checks and inherited post-insert checks are
        // read/check operations, so release immediately.
        if ($standalone) {
            TZFD_Capacity_Lock::release($wpdb, $lock);
        } elseif (!$ok || $delete_id !== null) {
            $this->pop_capacity_submission();
        }
        return $ok;
    }

    /** Read-only batch preview used by the administrator planner; no booking/email/payment writes. */
    public function preview_schedule($field_name, $service_index, $start_date, $start_time, $quantity, $rule) {
        $fields=$this->scheduling_fields();$map=TZFD_Scheduling::configuration($fields);
        $index=TZFD_Scheduling::integer($service_index,0);
        if(!is_string($field_name) || !isset($map[$field_name]) || $index===null || !isset($map[$field_name]['services'][$index])){throw new InvalidArgumentException('unknown_service');}
        $service=$map[$field_name]['services'][$index];
        $slot=TZFD_Scheduling::slot($start_time,$service['duration']);
        $dates=TZFD_Scheduling::expand_dates($start_date,$rule);$apps=array();
        foreach($dates as$tzfd_date){$apps[]=array('field'=>$field_name,'serviceindex'=>$index,'date'=>$tzfd_date,'slot'=>$slot,
            'quant'=>TZFD_Scheduling::quantity($quantity,$service['capacity']),'cancelled'=>'');}
        $stored=$this->stored_appointments($apps);
        $result=TZFD_Calendar::assess($apps,$fields,$this->get_option('date_format','mm/dd/yy'));
        if($result['ok']){$result=TZFD_Scheduling::assess($apps,$stored,$fields);}
        return array('appointments'=>$apps,'available'=>$result['ok'],'conflicts'=>$result['conflicts'],
            'availability_scope'=>'calendar_rules_occupancy_and_buffers','creates_bookings'=>false);
    }
}

