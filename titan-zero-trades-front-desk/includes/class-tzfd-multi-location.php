<?php
/** Read-only multi-site scheduling eligibility from trusted Workspace snapshots. GPL-2.0-only. */
if (!defined('ABSPATH')) exit;
final class TZFD_Multi_Location {
    private static function id($v) { return is_string($v) && (bool) preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $v); }
    private static function minutes($v) { return is_int($v) && $v >= 0 && $v <= 1440; }
    private static function overlaps($a,$b,$c,$d) { return $a < $d && $c < $b; }
    /**
     * $request: company_id, verified_company_id, snapshot_company_id, location_id,
     * start_utc, end_utc (Unix seconds), snapshot_complete, sites_verified,
     * staff_schedule_verified, sites and assignments from authoritative owner APIs.
     * An incomplete snapshot is an error rather than an apparently free slot.
     */
    public static function evaluate($request, $sites, $assignments, $travel_minutes) {
        if (!is_array($request) || !is_array($sites) || !is_array($assignments) || !is_array($travel_minutes)) throw new InvalidArgumentException('Invalid multi-location inputs.');
        $company=$request['company_id']??null; $location=$request['location_id']??null;
        if (!self::id($company) || !self::id($location) || ($request['verified_company_id']??null)!==$company || ($request['snapshot_company_id']??null)!==$company || empty($request['snapshot_complete']) || empty($request['sites_verified']) || empty($request['staff_schedule_verified']) || empty($request['candidates_verified'])) throw new InvalidArgumentException('Verified complete company snapshot required.');
        $start=$request['start_utc']??null; $end=$request['end_utc']??null;
        if (!is_int($start) || !is_int($end) || $start<0 || $end<=$start || $end-$start>86400) throw new InvalidArgumentException('Invalid booking interval.');
        $site_map=[];
        foreach ($sites as $site) {
            if (!is_array($site) || !self::id($site['id']??null) || ($site['company_id']??null)!==$company || isset($site_map[$site['id']])) throw new InvalidArgumentException('Invalid or foreign site.');
            if (!isset($site['timezone'],$site['weekly_hours']) || !is_array($site['weekly_hours'])) throw new InvalidArgumentException('Incomplete site hours.');
            try {$tz=new DateTimeZone($site['timezone']);} catch (Throwable $e) {throw new InvalidArgumentException('Invalid site timezone.');}
            $site_map[$site['id']]=$site;
        }
        if (!isset($site_map[$location])) throw new InvalidArgumentException('Unknown requested location.');
        $site=$site_map[$location]; $tz=new DateTimeZone($site['timezone']);
        $local_start=(new DateTimeImmutable('@'.$start))->setTimezone($tz);
        $local_end=(new DateTimeImmutable('@'.$end))->setTimezone($tz);
        // A requested booking must be wholly within a single local civil day and open interval.
        $day=$local_start->format('Y-m-d');
        if ($local_end->format('Y-m-d')!==$day) return self::result($company,$location,false,[],'crosses_local_midnight');
        if (in_array($day,$site['closed_dates']??[],true)) return self::result($company,$location,false,[],'site_closed');
        $weekday=(int)$local_start->format('N');
        $windows=$site['weekly_hours'][$weekday]??[];
        if (!is_array($windows)) throw new InvalidArgumentException('Invalid opening hours.');
        $open=false;
        foreach ($windows as $window) {
            if (!is_array($window) || count($window)!==2 || !self::minutes($window[0]) || !self::minutes($window[1]) || $window[1]<=$window[0]) throw new InvalidArgumentException('Invalid hours interval.');
            $s=(int)$local_start->format('H')*60+(int)$local_start->format('i');
            $e=(int)$local_end->format('H')*60+(int)$local_end->format('i');
            if ((int)$local_end->format('s') || $local_end->format('u')!=='000000') $e++;
            if ($s >= $window[0] && $e <= $window[1]) $open=true;
        }
        if (!$open) return self::result($company,$location,false,[],'outside_site_hours');
        $staff=$request['candidate_staff_ids']??null;
        if (!is_array($staff)) throw new InvalidArgumentException('Candidate staff required.');
        $eligible=[]; $seen=[];
        foreach ($staff as $id) {
            if (!self::id($id) || isset($seen[$id])) throw new InvalidArgumentException('Invalid or duplicate candidate.');
            $seen[$id]=true; $eligible[$id]=true;
        }
        $by_staff=[];
        foreach ($assignments as $a) {
            if (!is_array($a) || !self::id($a['staff_id']??null) || !self::id($a['location_id']??null) || ($a['company_id']??null)!==$company || !isset($site_map[$a['location_id']])) throw new InvalidArgumentException('Invalid or cross-company assignment.');
            if (!is_int($a['start_utc']??null) || !is_int($a['end_utc']??null) || $a['end_utc'] <= $a['start_utc']) throw new InvalidArgumentException('Invalid assignment interval.');
            $by_staff[$a['staff_id']][]=$a;
        }
        foreach ($staff as $id) {
            foreach ($by_staff[$id]??[] as $a) {
                if (self::overlaps($start,$end,$a['start_utc'],$a['end_utc'])) {$eligible[$id]=false;break;}
                $from=$a['location_id']; $to=$location;
                if ($from===$to) continue;
                // Directional buffer lookup: fail closed if not supplied.
                $out=$travel_minutes[$from][$to]??null;
                $back=$travel_minutes[$to][$from]??null;
                if (!self::minutes($out) || !self::minutes($back)) {$eligible[$id]=false;break;}
                if ($a['end_utc'] <= $start && $a['end_utc']+$out*60>$start) {$eligible[$id]=false;break;}
                if ($a['start_utc'] >= $end && $end+$back*60>$a['start_utc']) {$eligible[$id]=false;break;}
            }
        }
        $ids=[];foreach ($staff as $id) if ($eligible[$id]) $ids[]=$id;
        return self::result($company,$location,true,$ids,null);
    }
    private static function result($company,$location,$site_open,$staff,$reason) {
        return ['schema_version'=>1,'company_id'=>$company,'location_id'=>$location,'site_open'=>$site_open,'eligible_staff_ids'=>$staff,'reason'=>$reason,'read_only'=>true,'requires_commit_revalidation'=>true];
    }
}
