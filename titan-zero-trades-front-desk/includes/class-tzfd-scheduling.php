<?php
/**
 * Deterministic scheduling primitives, independently implemented for Titan Zero Trades: Front Desk.
 *
 * @license GPL-2.0-only
 * @package TZFD
 */
if (!defined('ABSPATH')) { exit; }

final class TZFD_Scheduling {
    const MAX_CAPACITY = 1000000;
    const MAX_OCCURRENCES = 366;

    /** Strict finite whole number; unlike intval(), never accepts an array or numeric prefix. */
    public static function integer($tzfd_value, $minimum = 0, $maximum = self::MAX_CAPACITY) {
        if (is_int($tzfd_value)) { $number = $tzfd_value; }
        elseif (is_float($tzfd_value) && is_finite($tzfd_value) && floor($tzfd_value) === $tzfd_value && $tzfd_value < (float)PHP_INT_MAX) { $number = $tzfd_value; }
        elseif (is_string($tzfd_value) && preg_match('/\A[0-9]+\z/D', $tzfd_value)) {
            $digits = ltrim($tzfd_value, '0');
            if (strlen($digits) > strlen((string)$maximum) || (strlen($digits) === strlen((string)$maximum) && strcmp($digits,(string)$maximum)>0)) { return null; }
            $number = (int)$tzfd_value;
        } else { return null; }
        return $number >= $minimum && $number <= $maximum ? (int)$number : null;
    }

    public static function capacity($tzfd_value) {
        $capacity = self::integer($tzfd_value, 1);
        return $capacity === null ? 1 : $capacity;
    }

    /** Retains the legacy request upper clamp while closing zero/negative/free bookings. */
    public static function quantity($tzfd_value, $capacity) {
        $quantity = self::integer($tzfd_value, 1);
        return min($quantity === null ? 1 : $quantity, self::capacity($capacity));
    }

    public static function date($tzfd_value) {
        if (!is_string($tzfd_value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $tzfd_value)) {
            throw new InvalidArgumentException('invalid_date');
        }
        $tzfd_date = DateTimeImmutable::createFromFormat('!Y-m-d', $tzfd_value, new DateTimeZone('UTC'));
        if (!$tzfd_date || $tzfd_date->format('Y-m-d') !== $tzfd_value || (int)$tzfd_date->format('Y') < 1970) {
            throw new InvalidArgumentException('invalid_date');
        }
        return $tzfd_date;
    }

    /** Site wall-clock intervals; UTC here is an arithmetic frame, not a timezone conversion. */
    public static function interval($tzfd_date, $slot, $before = 0, $after = 0) {
        $day = self::date($tzfd_date);
        if (!is_string($slot) || !preg_match('/\A([0-9]{2}):([0-9]{2})\/([0-9]{2}):([0-9]{2})\z/D', $slot, $m)) {
            throw new InvalidArgumentException('invalid_slot');
        }
        if ((int)$m[1] > 23 || (int)$m[2] > 59 || (int)$m[3] > 47 || (int)$m[4] > 59) {
            throw new InvalidArgumentException('invalid_slot');
        }
        $start = (int)$m[1]*60 + (int)$m[2];
        $end = (int)$m[3]*60 + (int)$m[4];
        if ($end <= $start) { $end += 1440; }
        $before = self::integer($before, 0, 1440);
        $after = self::integer($after, 0, 1440);
        if ($end - $start > 1440 || $before === null || $after === null) {
            throw new InvalidArgumentException('invalid_duration_or_buffer');
        }
        return array('start'=>$day->getTimestamp()+($start-$before)*60,
            'end'=>$day->getTimestamp()+($end+$after)*60, 'duration'=>$end-$start);
    }

    public static function slot($start_time, $duration) {
        $duration = self::integer($duration, 1, 1440);
        if ($duration === null || !is_string($start_time) || !preg_match('/\A([0-9]{2}):([0-9]{2})\z/D', $start_time, $m) || (int)$m[1]>23 || (int)$m[2]>59) {
            throw new InvalidArgumentException('invalid_start_time_or_duration');
        }
        $end = (int)$m[1]*60 + (int)$m[2] + $duration;
        return $start_time . '/' . sprintf('%02d:%02d', intdiv($end,60), $end%60);
    }

    public static function configuration($fields) {
        if (!is_array($fields)) { throw new InvalidArgumentException('invalid_configuration'); }
        $map = array();
        foreach ($fields as $field) {
            if (!is_object($field) || !isset($field->ftype) || $field->ftype !== 'fapp') { continue; }
            if (!isset($field->name) || !is_string($field->name) || $field->name === '' || isset($map[$field->name]) || !isset($field->services) || !is_array($field->services)) {
                throw new InvalidArgumentException('invalid_configuration');
            }
            $services = array();$service_ids=array();
            foreach ($field->services as $index=>$service) {
                if (!is_object($service) || !is_int($index)) { throw new InvalidArgumentException('invalid_configuration'); }
                $duration = self::integer(isset($service->duration)?$service->duration:null, 1, 1440);
                $pb = self::integer(isset($service->pb)?$service->pb:0, 0, 1440);
                $pa = self::integer(isset($service->pa)?$service->pa:0, 0, 1440);
                if ($duration === null || $pb === null || $pa === null) { throw new InvalidArgumentException('invalid_configuration'); }
                if(isset($service->idx) && !is_int($service->idx) && !is_string($service->idx)){throw new InvalidArgumentException('invalid_service_id');}
                $sid=isset($service->idx)?(string)$service->idx:'';
                if($sid!=='' && isset($service_ids[$sid])){throw new InvalidArgumentException('duplicate_service_id');}
                if($sid!==''){$service_ids[$sid]=true;}
                $services[$index] = array('capacity'=>self::capacity(isset($service->capacity)?$service->capacity:null),
                    'duration'=>$duration,'pb'=>$pb,'pa'=>$pa,'sid'=>isset($service->idx)?(string)$service->idx:'',
                    'name'=>isset($service->name) && is_string($service->name)?$service->name:'');
            }
            $map[$field->name] = array('services'=>$services,
                'exclusive'=>isset($field->avoidOverlaping) && in_array($field->avoidOverlaping,array(true,1,'1','true'),true));
        }
        return $map;
    }

    private static function occupied($tzfd_app) {
        if (!empty($tzfd_app['_locked'])) { return true; }
        $status = isset($tzfd_app['cancelled']) ? $tzfd_app['cancelled'] : '';
        // Unknown extension statuses are conservative: only explicit release states free space.
        return !in_array($status,array('Cancelled','Cancelled by customer','Rejected'),true);
    }

    private static function appointment($tzfd_app, $map, $requested, $allow_custom_duration=false) {
        if (!is_array($tzfd_app)) { throw new InvalidArgumentException('invalid_appointment'); }
        $field = isset($tzfd_app['field']) ? $tzfd_app['field'] : '';
        if (!is_string($field) || !isset($map[$field])) { throw new InvalidArgumentException('unknown_field'); }
        $index = self::integer(isset($tzfd_app['serviceindex'])?$tzfd_app['serviceindex']:null,0);
        if (!$requested && isset($tzfd_app['sid']) && $tzfd_app['sid'] !== '') {
            if (!is_scalar($tzfd_app['sid'])) { throw new InvalidArgumentException('invalid_service'); }
            $index = null;
            foreach ($map[$field]['services'] as $tzfd_key=>$service) {
                if ($service['sid'] !== '' && $service['sid'] === (string)$tzfd_app['sid']) { $index=$tzfd_key; break; }
            }
        } elseif (!$requested && $index === null && isset($tzfd_app['service']) && is_string($tzfd_app['service']) && $tzfd_app['service'] !== '') {
            // Retain the merged parent contract for index-less legacy rows. An
            // explicit stable ID above is authoritative, even when unresolved.
            $matches=array();
            foreach ($map[$field]['services'] as $tzfd_key=>$service) {
                if ($service['name'] === $tzfd_app['service']) { $matches[]=$tzfd_key; }
            }
            if (count($matches) === 1) { $index=$matches[0]; }
        }
        $unknown = $index === null || !isset($map[$field]['services'][$index]);
        if ($unknown && $requested) { throw new InvalidArgumentException('unknown_service'); }
        $service = $unknown ? array('capacity'=>1,'duration'=>1,'pb'=>0,'pa'=>0) : $map[$field]['services'][$index];
        $range = self::interval(isset($tzfd_app['date'])?$tzfd_app['date']:null,isset($tzfd_app['slot'])?$tzfd_app['slot']:null,$service['pb'],$service['pa']);
        if($requested && !$allow_custom_duration && $range['duration']!==$service['duration']){throw new InvalidArgumentException('duration_mismatch');}
        $quantity = self::integer(isset($tzfd_app['quant'])?$tzfd_app['quant']:1,1);
        if ($requested && ($quantity === null || $quantity > $service['capacity'])) { throw new InvalidArgumentException('invalid_quantity'); }
        // Never clamp historical occupancy down to today's service capacity.
        if (!$requested) {
            $raw=isset($tzfd_app['quant'])?$tzfd_app['quant']:1;
            $quantity=1;
            if((is_int($raw)||is_float($raw)||is_string($raw)) && is_numeric($raw) && (float)$raw>0){
                $quantity=(float)$raw>=self::MAX_CAPACITY+1?self::MAX_CAPACITY+1:(int)ceil((float)$raw);
            }
        }
        return $range + array('field'=>$field,'serviceindex'=>$index,'quantity'=>$quantity,'capacity'=>$service['capacity'],
            'exclusive'=>$map[$field]['exclusive'],'all_services'=>$unknown || !empty($tzfd_app['_all_services']));
    }

    /** Capacity at every instant, not total bookings which happen to touch the same window. */
    private static function conflict($candidate, $others) {
        $tzfd_events = array();
        foreach ($others as $other) {
            if ($other['field'] !== $candidate['field']) { continue; }
            $start=max($candidate['start'],$other['start']); $end=min($candidate['end'],$other['end']);
            if ($start >= $end) { continue; }
            $same=$other['serviceindex'] === $candidate['serviceindex'];
            if ($other['all_services'] || (!$same && $candidate['exclusive'])) { return 'exclusive_or_blocked_window'; }
            if (!$same) { continue; }
            $amount=min($other['quantity'],$candidate['capacity']+1);
            $tzfd_events[$start]=(isset($tzfd_events[$start])?$tzfd_events[$start]:0)+$amount;
            $tzfd_events[$end]=(isset($tzfd_events[$end])?$tzfd_events[$end]:0)-$amount;
        }
        ksort($tzfd_events,SORT_NUMERIC);$usage=$candidate['quantity'];
        foreach ($tzfd_events as $time=>$delta) {
            $usage += $delta;
            if ($time < $candidate['end'] && $usage > $candidate['capacity']) { return 'capacity_exceeded'; }
        }
        return null;
    }

    /** No customer data, executable code, writes, network or AI in a scheduling decision. */
    public static function assess($requested, $stored, $fields, $allow_custom_duration=false) {
        if (!is_array($requested) || !is_array($stored) || count($requested)>self::MAX_OCCURRENCES) {
            return array('ok'=>false,'conflicts'=>array(array('code'=>'invalid_request')));
        }
        try {
            $map=self::configuration($fields);$planned=array();$occupied=array();$used_fields=array();
            foreach ($requested as $tzfd_app) { $planned[]=self::appointment($tzfd_app,$map,true,$allow_custom_duration);$used_fields[$tzfd_app['field']]=true; }
            foreach ($stored as $tzfd_app) {
                if (!is_array($tzfd_app)) { throw new InvalidArgumentException('invalid_stored_appointment'); }
                if (!self::occupied($tzfd_app)) { continue; }
                // Older records without field identity conservatively occupy each requested field.
                if (!isset($tzfd_app['field']) || $tzfd_app['field'] === '') {
                    foreach ($used_fields as $field=>$used) { $copy=$tzfd_app;$copy['field']=$field;$copy['_all_services']=true;$occupied[]=self::appointment($copy,$map,false); }
                } elseif (is_string($tzfd_app['field']) && isset($used_fields[$tzfd_app['field']])) {
                    $occupied[]=self::appointment($tzfd_app,$map,false);
                }
            }
            $conflicts=array();
            foreach ($planned as $index=>$candidate) {
                $others=$occupied;
                foreach ($planned as $other_index=>$other) { if($other_index!==$index){$others[]=$other;} }
                $code=self::conflict($candidate,$others);
                if ($code!==null) { $conflicts[]=array('index'=>$index,'code'=>$code); }
            }
            return array('ok'=>count($conflicts)===0,'conflicts'=>$conflicts);
        } catch (InvalidArgumentException $error) {
            return array('ok'=>false,'conflicts'=>array(array('code'=>$error->getMessage())));
        }
    }

    /** Calendar recurrence only. It neither stores bookings nor claims an absolute-time/DST conversion. */
    public static function expand_dates($start_date, $rule=array()) {
        $start=self::date($start_date);
        if (!is_array($rule)) { throw new InvalidArgumentException('invalid_recurrence'); }
        $frequency=isset($rule['frequency'])?$rule['frequency']:'weekly';
        $tzfd_count=self::integer(isset($rule['count'])?$rule['count']:1,1,self::MAX_OCCURRENCES);
        $interval=self::integer(isset($rule['interval'])?$rule['interval']:1,1,52);
        $policy=isset($rule['month_policy'])?$rule['month_policy']:'skip';
        if (!in_array($frequency,array('daily','weekly','monthly'),true) || $tzfd_count===null || $interval===null || !in_array($policy,array('skip','last_day'),true)) {
            throw new InvalidArgumentException('invalid_recurrence');
        }
        $excluded=isset($rule['exclude_dates'])?$rule['exclude_dates']:array();
        if(!is_array($excluded) || count($excluded)>self::MAX_OCCURRENCES){throw new InvalidArgumentException('invalid_exclusions');}
        foreach($excluded as $tzfd_date){self::date($tzfd_date);}
        $dates=array();$valid=0;$anchor=(int)$start->format('j');
        for($step=0;$valid<$tzfd_count && $step<self::MAX_OCCURRENCES*12;$step++){
            if($frequency==='monthly'){
                $month=$start->modify('first day of this month')->modify('+'.($step*$interval).' months');
                if((int)$month->format('Y')>9999){throw new InvalidArgumentException('date_out_of_range');}
                $days=(int)$month->format('t');
                if($anchor>$days && $policy==='skip'){continue;}
                $next=$month->setDate((int)$month->format('Y'),(int)$month->format('n'),min($anchor,$days));
            } else {$next=$start->modify('+'.($step*$interval*($frequency==='weekly'?7:1)).' days');}
            $tzfd_date=$next->format('Y-m-d');self::date($tzfd_date);$valid++;
            if(!in_array($tzfd_date,$excluded,true)){$dates[]=$tzfd_date;}
        }
        if($valid!==$tzfd_count){throw new InvalidArgumentException('recurrence_limit');}
        return $dates;
    }
}

