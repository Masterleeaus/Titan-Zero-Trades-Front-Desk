<?php
/** Pure resource eligibility/capacity preview; never commits a reservation. GPL-2.0-only. */
// Reject direct requests; standalone tests explicitly supply a WordPress bootstrap marker.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once __DIR__.'/class-tzfd-resource-catalog.php';
final class TZFD_Resource_Availability {
    const MAX_RESERVATIONS=5000;
    const MAX_REQUIRED_RESOURCES=50;

    public static function evaluate($catalog,$request,$reservations=array()) {
        $catalog=TZFD_Resource_Catalog::validate($catalog);
        if (!is_array($request)) throw new InvalidArgumentException('Invalid preview request.');
        $service=TZFD_Resource_Catalog::identifier(isset($request['service_id'])?$request['service_id']:null);
        $location=isset($request['location_id'])?$request['location_id']:'';
        if (''!==$location) $location=TZFD_Resource_Catalog::identifier($location);
        $postcode=TZFD_Resource_Catalog::postcode(isset($request['postcode'])?$request['postcode']:'');
        $quantity=TZFD_Resource_Catalog::integer(isset($request['quantity'])?$request['quantity']:null,1,10000,'quantity');
        $start=self::instant(isset($request['start'])?$request['start']:null);
        $end=self::instant(isset($request['end'])?$request['end']:null);
        if ($end<=$start || $end-$start>7*86400) throw new InvalidArgumentException('Preview interval must be positive and no longer than seven days.');
        $locations=array_column($catalog['locations'],null,'id');
        $resources=array_column($catalog['resources'],null,'id');
        if (''!==$location && !isset($locations[$location])) throw new InvalidArgumentException('Unknown requested location.');
        $required_ids=array(); $required=array();
        $requested=TZFD_Resource_Catalog::list_value(isset($request['required_resource_ids'])?$request['required_resource_ids']:array(),self::MAX_REQUIRED_RESOURCES,'required_resource_ids');
        $required_locations=array();
        foreach($requested as $id) {
            $id=TZFD_Resource_Catalog::identifier($id);
            if (isset($required[$id])) throw new InvalidArgumentException('Duplicate required resource ID.');
            if (!isset($resources[$id])) throw new InvalidArgumentException('Unknown required resource ID.');
            $required[$id]=true; $required_ids[]=$id;
            $required_locations[$resources[$id]['location_id']]=true;
        }
        if (count($required_locations)>1) throw new InvalidArgumentException('Required resources must share one location.');
        $tzfd_rows=self::reservations($reservations,$resources);
        $result=array('schema_version'=>1,'reservation_committed'=>false,'reservation_source'=>'caller_supplied',
            'start'=>gmdate('Y-m-d\TH:i:s\Z',$start),'end'=>gmdate('Y-m-d\TH:i:s\Z',$end),
            'required_resource_ids'=>$required_ids,'selection_available'=>$required_ids?true:null,'resources'=>array());
        foreach($resources as $resource) {
            if ($required_ids && !isset($required[$resource['id']])) continue;
            $place=$locations[$resource['location_id']]; $reasons=array();
            if (!$resource['enabled']) $reasons[]='resource_disabled';
            if (!$place['enabled']) $reasons[]='location_disabled';
            if (''!==$location && $location!==$place['id']) $reasons[]='location_mismatch';
            if ($resource['service_ids'] && !in_array($service,$resource['service_ids'],true)) $reasons[]='service_mismatch';
            if ($place['postcodes'] && !in_array($postcode,$place['postcodes'],true)) $reasons[]='postcode_outside_coverage';
            $from=$start-$resource['buffer_before']*60; $until=$end+$resource['buffer_after']*60;
            $zone=new DateTimeZone($place['timezone']);
            if (self::blackout($from,$until,$zone,$resource['blackouts'])) $reasons[]='blackout';
            if (!self::within_hours($from,$until,$zone,$resource['weekly'])) $reasons[]='outside_working_hours';
            $peak=self::peak(isset($tzfd_rows[$resource['id']])?$tzfd_rows[$resource['id']]:array(),$from,$until,$resource);
            $remaining=max(0,$resource['capacity']-$peak);
            if ($quantity>$remaining) $reasons[]='insufficient_capacity';
            $available=!$reasons;
            $result['resources'][]=array('resource_id'=>$resource['id'],'name'=>$resource['name'],'kind'=>$resource['kind'],
                'location_id'=>$place['id'],'available'=>$available,'reasons'=>$reasons,
                'capacity'=>$resource['capacity'],'remaining_capacity'=>$remaining);
            if ($required_ids && !$available) $result['selection_available']=false;
        }
        return $result;
    }

    /** Exact seconds plus explicit timezone offset: no parser-relative or ambiguous local input. */
    public static function instant($tzfd_value) {
        if (!is_string($tzfd_value) || !preg_match('/^(\d{4}-\d{2}-\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D',$tzfd_value,$m)) {
            throw new InvalidArgumentException('Use YYYY-MM-DDTHH:MM:SSZ or an explicit timezone offset.');
        }
        TZFD_Resource_Catalog::date($m[1]);
        if ('Z'!==$m[5] && '14'===substr($m[5],1,2) && '00'!==substr($m[5],4,2)) throw new InvalidArgumentException('Invalid UTC offset.');
        $normalized='Z'===$m[5]?substr($tzfd_value,0,-1).'+00:00':$tzfd_value;
        $tzfd_date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP',$normalized);
        if (!$tzfd_date || $tzfd_date->format('Y-m-d\TH:i:sP')!==$normalized) throw new InvalidArgumentException('Invalid timestamp.');
        return $tzfd_date->getTimestamp();
    }

    private static function reservations($input,$resources) {
        $input=TZFD_Resource_Catalog::list_value($input,self::MAX_RESERVATIONS,'reservations');
        $seen=array(); $groups=array();
        foreach($input as $row) {
            if (!is_array($row)) throw new InvalidArgumentException('Invalid reservation.');
            $id=TZFD_Resource_Catalog::identifier(isset($row['id'])?$row['id']:null);
            $resource=TZFD_Resource_Catalog::identifier(isset($row['resource_id'])?$row['resource_id']:null);
            if (!isset($resources[$resource])) throw new InvalidArgumentException('Unknown reservation resource.');
            $tzfd_key=$resource.'|'.$id;
            if (isset($seen[$tzfd_key])) throw new InvalidArgumentException('Duplicate resource reservation.');
            $seen[$tzfd_key]=true;
            $quantity=TZFD_Resource_Catalog::integer(isset($row['quantity'])?$row['quantity']:null,1,10000,'reservation quantity');
            $status=isset($row['status'])?$row['status']:null;
            if (!in_array($status,array('confirmed','pending','held','cancelled','rejected'),true)) throw new InvalidArgumentException('Unknown reservation status.');
            $start=self::instant(isset($row['start'])?$row['start']:null); $end=self::instant(isset($row['end'])?$row['end']:null);
            if ($end<=$start) throw new InvalidArgumentException('Reservation interval must be positive.');
            if (in_array($status,array('cancelled','rejected'),true)) continue;
            $groups[$resource][]=array('start'=>$start,'end'=>$end,'quantity'=>$quantity);
        }
        return $groups;
    }

    private static function peak($tzfd_rows,$from,$until,$resource) {
        $tzfd_events=array();
        foreach($tzfd_rows as $row) {
            $start=max($from,$row['start']-$resource['buffer_before']*60);
            $end=min($until,$row['end']+$resource['buffer_after']*60);
            if ($end<=$start) continue;
            if (!isset($tzfd_events[$start])) $tzfd_events[$start]=0;
            if (!isset($tzfd_events[$end])) $tzfd_events[$end]=0;
            $tzfd_events[$start]+=$row['quantity']; $tzfd_events[$end]-=$row['quantity'];
        }
        ksort($tzfd_events,SORT_NUMERIC); $used=0; $peak=0;
        foreach($tzfd_events as $delta) { $used+=$delta; $peak=max($peak,$used); }
        return $peak;
    }

    private static function local($timestamp,$zone) {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone($zone);
    }

    private static function blackout($from,$until,$zone,$dates) {
        if (!$dates) return false;
        $day=self::local($from,$zone)->setTime(0,0);
        $last=self::local($until-1,$zone)->format('Y-m-d');
        for($tzfd_i=0;$tzfd_i<12 && $day->format('Y-m-d')<=$last;$tzfd_i++,$day=$day->modify('+1 day')) {
            if (in_array($day->format('Y-m-d'),$dates,true)) return true;
        }
        return false;
    }

    /** Reject nonexistent wall-clock boundaries and narrow ambiguous ones conservatively. */
    private static function boundary($day,$minute,$zone,$opening) {
        $calendar=new DateTimeImmutable($day.' 00:00:00',new DateTimeZone('UTC'));
        if (1440===$minute) { $calendar=$calendar->modify('+1 day'); $minute=0; }
        $wall=$calendar->format('Y-m-d').' '.sprintf('%02d:%02d',intdiv($minute,60),$minute%60);
        $naive=(new DateTimeImmutable($wall,new DateTimeZone('UTC')))->getTimestamp();
        $transitions=$zone->getTransitions($naive-3*86400,$naive+3*86400); $offsets=array();
        if (false===$transitions) {
            $offsets[$zone->getOffset(new DateTimeImmutable('@'.$naive))]=true;
        } else {
            foreach($transitions as $transition) $offsets[$transition['offset']]=true;
        }
        $candidates=array();
        foreach(array_keys($offsets) as $tzfd_offset) {
            $candidate=$naive-$tzfd_offset;
            if (self::local($candidate,$zone)->format('Y-m-d H:i')===$wall) $candidates[]=$candidate;
        }
        if (!$candidates) return null;
        return $opening?max($candidates):min($candidates);
    }

    private static function within_hours($from,$until,$zone,$tzfd_weekly) {
        $day=self::local($from,$zone)->setTime(0,0)->modify('-1 day');
        $last=self::local($until-1,$zone)->format('Y-m-d'); $windows=array();
        for($tzfd_i=0;$tzfd_i<13 && $day->format('Y-m-d')<=$last;$tzfd_i++,$day=$day->modify('+1 day')) {
            foreach($tzfd_weekly as $window) {
                if ((int)$day->format('N')!==$window['day']) continue;
                $start=TZFD_Resource_Catalog::clock($window['start']);
                $end=TZFD_Resource_Catalog::clock($window['end'],true);
                $end_day=$end<$start?$day->modify('+1 day'):$day;
                $a=self::boundary($day->format('Y-m-d'),$start,$zone,true);
                $b=self::boundary($end_day->format('Y-m-d'),$end,$zone,false);
                if (null!==$a && null!==$b && $a<$b && $a<$until && $b>$from) $windows[]=array($a,$b);
            }
        }
        usort($windows,function($a,$b){return $a[0]<=>$b[0];});
        $cursor=$from;
        foreach($windows as $window) {
            if ($window[0]>$cursor) return false;
            $cursor=max($cursor,$window[1]);
            if ($cursor>=$until) return true;
        }
        return false;
    }
}
