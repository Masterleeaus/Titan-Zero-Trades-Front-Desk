<?php
/** Donor calendar policy for new selections. Independently implemented; GPL-2.0-only. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/class-tzfd-scheduling.php';

final class TZFD_Calendar {
    private static function value($o,$tzfd_key,$default) { return property_exists($o,$tzfd_key)?$o->$tzfd_key:$default; }
    private static function fail() { throw new InvalidArgumentException('invalid_calendar_configuration'); }
    private static function whole($tzfd_v,$min,$max) {
        $n=TZFD_Scheduling::integer($tzfd_v,$min,$max); if($n===null){self::fail();} return $n;
    }
    /** Resolve one exact wall time. Neither PHP's gap normalization nor its fold choice is accepted. */
    public static function instant($wall, $zone) {
        $tzfd_date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$wall,new DateTimeZone('UTC'));
        if(!$tzfd_date || $tzfd_date->format('Y-m-d H:i:s')!==$wall){throw new InvalidArgumentException('invalid_local_time');}
        $stamp=$tzfd_date->getTimestamp();$offsets=array();
        $transitions=$zone->getTransitions($stamp-172800,$stamp+172800);
        if($transitions===false){$offsets[]=$zone->getOffset($tzfd_date);}else{foreach($transitions as$t){$offsets[]=$t['offset'];}}
        $matches=array();
        foreach(array_unique($offsets)as$tzfd_offset){$candidate=$stamp-$tzfd_offset;
            if((new DateTimeImmutable('@'.$candidate))->setTimezone($zone)->format('Y-m-d H:i:s')===$wall){$matches[]=$candidate;}}
        if(count($matches)!==1){throw new InvalidArgumentException('nonunique_local_time');}
        return $matches[0];
    }
    /** Strict numeric jQuery date formats; textual/localized formats are explicitly unsupported. */
    private static function formatted_date($tzfd_value,$format) {
        if(!is_string($tzfd_value)||!is_string($format)){self::fail();}
        $tokens=array();$regex='';
        for($tzfd_i=0;$tzfd_i<strlen($format);){
            $token=null;foreach(array('yy','mm','dd','y','m','d')as$t){if(substr($format,$tzfd_i,strlen($t))===$t){$token=$t;break;}}
            if($token!==null){$tokens[]=$token;$regex.='('.($token==='yy'?'[0-9]{4}':($token==='y'?'[0-9]{2}':'[0-9]{1,2}')).')';$tzfd_i+=strlen($token);}
            else{if(ctype_alpha($format[$tzfd_i])){self::fail();}$regex.=preg_quote($format[$tzfd_i],'/');$tzfd_i++;}
        }
        if(count($tokens)!==3||!preg_match('/^'.$regex.'$/D',$tzfd_value,$m)){self::fail();}
        $parts=array();foreach($tokens as$tzfd_i=>$token){$parts[$token[0]]=(int)$m[$tzfd_i+1];if($token==='y'){self::fail();}}
        if(!isset($parts['y'],$parts['m'],$parts['d'])){self::fail();}
        $tzfd_date=sprintf('%04d-%02d-%02d',$parts['y'],$parts['m'],$parts['d']);TZFD_Scheduling::date($tzfd_date);return $tzfd_date;
    }
    private static function bound($tzfd_value,$format,$zone,$now,$maximum) {
        if($tzfd_value===''){return null;}if(!is_string($tzfd_value)&&!is_int($tzfd_value)){self::fail();}
        $parts=explode('@',trim((string)$tzfd_value));if(count($parts)>2||$parts[0]===''){self::fail();}
        $clock=null;if(count($parts)===2){if(!preg_match('/^([01]?[0-9]|2[0-3]):([0-5][0-9])$/D',$parts[1],$m)){self::fail();}$clock=sprintf('%02d:%02d:00',$m[1],$m[2]);}
        $spec=trim($parts[0]);$relative=preg_match('/^(?:[+-]?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)\s*h\s*|[+-]?[0-9]+\s*[dwmy]?\s*)+$/D',$spec) && (preg_match('/^[+-]?[0-9]+$/D',$spec)||preg_match('/[dwmyh]/',$spec));
        $local=$now->setTimezone($zone);$base=TZFD_Scheduling::date($local->format('Y-m-d'));$hours=0;$has_hours=false;
        if($relative){
            preg_match_all('/([+-]?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+))\s*([dwmyh]?)/',$spec,$matches,PREG_SET_ORDER);
            foreach($matches as$m){$n=(float)$m[1];if(abs($n)>36600){self::fail();}$unit=$m[2]?:'d';if($unit!=='h'){$n=(int)$n;}
                if($unit==='h'){$hours+=$n;$has_hours=true;continue;}
                if($unit==='d'||$unit==='w'){$base=$base->modify(($n>=0?'+':'').($unit==='w'?$n*7:$n).' days');}
                else{$year=(int)$base->format('Y');$month=(int)$base->format('n');$day=(int)$base->format('j');
                    if($unit==='y'){$year+=$n;}else{$tzfd_total=$year*12+$month-1+$n;$year=(int)floor($tzfd_total/12);$month=$tzfd_total-$year*12+1;}
                    if($year<1970||$year>9999){self::fail();}$first=TZFD_Scheduling::date(sprintf('%04d-%02d-01',$year,$month));
                    $base=$first->setDate($year,$month,min($day,(int)$first->format('t')));}
            }
            $time=$clock!==null?$clock:(!$maximum||$has_hours?$local->format('H:i:00'):'00:00:00');
            if($maximum&&!$has_hours&&$clock===null){$base=$base->modify('+1 day');}
            // An hours-only lead/horizon is real elapsed time even across a transition.
            if($clock===null&&(($has_hours&&count($matches)===1)||((!$maximum||$has_hours)&&$base->format('Y-m-d')===$local->format('Y-m-d')))){return $now->getTimestamp()-(int)$now->format('s')+$hours*3600;}
            return self::instant($base->format('Y-m-d').' '.$time,$zone)+$hours*3600;
        }
        $day=TZFD_Scheduling::date(self::formatted_date($spec,$format));
        if($maximum&&$clock===null){$day=$day->modify('+1 day');}
        return self::instant($day->format('Y-m-d').' '.($clock===null?'00:00:00':$clock),$zone);
    }
    public static function compile($field,$zone,$now,$bound_format=null) {
        if(!is_object($field)||!($zone instanceof DateTimeZone)||!($now instanceof DateTimeImmutable)){self::fail();}
        $default=array((object)array('name'=>'Default','openhours'=>array((object)array('type'=>'all','d'=>'','h1'=>8,'m1'=>0,'h2'=>17,'m2'=>0))));
        $legacy=self::value($field,'openhours',array());if(!is_array($legacy)){self::fail();}
        $groups=self::value($field,'allOH',$default);
        if(count($legacy)>0){if(!is_object($legacy[0])){self::fail();}$groups=empty($legacy[0]->name)?array((object)array('openhours'=>$legacy)):$legacy;}
        if(!is_array($groups)){self::fail();}$schedules=array();
        foreach($groups as$group){
            if(!is_object($group)||!isset($group->openhours)||!is_array($group->openhours)){self::fail();}$rules=array();
            foreach($group->openhours as$r){
                if(!is_object($r)||!isset($r->type)||!is_string($r->type)){self::fail();}
                $type=$r->type;$dates=null;
                if($type==='special'||$type==='specialrange'){
                    if(!isset($r->d)||!is_string($r->d)){self::fail();}
                    // The donor supports an unfinished empty special-range row as inactive.
                    if($type==='specialrange'&&$r->d===''){continue;}
                    $dates=explode(':',$r->d);if(count($dates)>($type==='special'?1:2)){self::fail();}
                    foreach($dates as$tzfd_d){TZFD_Scheduling::date($tzfd_d);}if(count($dates)===1){$dates[]=$dates[0];}
                    if($dates[0]>$dates[1]){self::fail();}
                }elseif($type!=='all'&&!preg_match('/^d[0-6]$/D',$type)){self::fail();}
                foreach(array('h1','h2','m1','m2')as$tzfd_k){if(!property_exists($r,$tzfd_k)){self::fail();}}
                $start=self::whole($r->h1,0,23)*60+self::whole($r->m1,0,59);
                $end=self::whole($r->h2,0,24)*60+self::whole($r->m2,0,59);if($end>1440){self::fail();}if($end<=$start){$end+=1440;}
                $rules[]=array('type'=>$type,'dates'=>$dates,'start'=>$start,'end'=>$end);
            }$schedules[]=$rules;
        }
        $working=self::value($field,'working_dates',array(true,true,true,true,true,true,true));
        if(!is_array($working)||array_keys($working)!==range(0,6)){self::fail();}
        foreach($working as&$tzfd_v){if(!in_array($tzfd_v,array(true,false,0,1,'0','1'),true)){self::fail();}$tzfd_v=(bool)$tzfd_v;}unset($tzfd_v);
        $format=self::value($field,'dateFormat','mm/dd/yy');$invalid=self::value($field,'invalidDates','');
        if(!is_string($invalid)||strlen($invalid)>32768){self::fail();}$closed=array();$invalid=preg_replace('/\s+/','',$invalid);
        if($invalid!==''){$df=strpos($invalid,'.')!==false?$format:'mm/dd/yy';
            foreach(explode(',',$invalid)as$range){$dates=explode('-',$range);if(count($dates)>2){self::fail();}
                foreach($dates as&$tzfd_d){$tzfd_d=self::formatted_date($tzfd_d,$df);}unset($tzfd_d);if(count($dates)===1){$dates[]=$dates[0];}
                if($dates[0]>$dates[1]){self::fail();}$closed[]=$dates;}}
        if(!isset($field->services)||!is_array($field->services)){self::fail();}$services=array();
        foreach($field->services as$s){if(!is_object($s)){self::fail();}$index=self::value($s,'ohindex',0);
            if($index===''||$index===false||$index===null){$index=0;}$index=self::whole($index,0,10000);
            if(!array_key_exists($index,$schedules)){self::fail();}
            $services[]=array('schedule'=>$index,'duration'=>self::whole(self::value($s,'duration',null),1,1440),
                'before'=>self::whole(self::value($s,'pb',0),0,1440),'after'=>self::whole(self::value($s,'pa',0),0,1440));}
        $min=self::bound(self::value($field,'minDate','0'),($bound_format===null||$bound_format==='')?$format:$bound_format,$zone,$now,false);
        $max=self::bound(self::value($field,'maxDate',''),($bound_format===null||$bound_format==='')?$format:$bound_format,$zone,$now,true);
        if($min!==null&&$max!==null&&$min>=$max){self::fail();}
        return array('version'=>1,'zone'=>$zone->getName(),'now'=>$now->getTimestamp(),'schedules'=>$schedules,'services'=>$services,
            'working'=>$working,'closed'=>$closed,'min'=>$min,'max'=>$max);
    }
    public static function allows($policy,$tzfd_date,$slot,$service) {
        try{
            if(!isset($policy['version'])||$policy['version']!==1||!isset($policy['services'][$service])){return false;}
            $range=TZFD_Scheduling::interval($tzfd_date,$slot);$day=TZFD_Scheduling::date($tzfd_date);$midnight=$day->getTimestamp();
            $s=$policy['services'][$service];$start=($range['start']-$midnight)/60;$end=($range['end']-$midnight)/60;
            if($range['duration']!==$s['duration']){return false;}
            $special=array();$weekday=array();$all=array();
            foreach($policy['schedules'][$s['schedule']]as$r){
                if($r['dates']!==null&&$tzfd_date>=$r['dates'][0]&&$tzfd_date<=$r['dates'][1]){$special[]=$r;}
                elseif($r['type']==='d'.$day->format('w')){$weekday[]=$r;}elseif($r['type']==='all'){$all[]=$r;}}
            if(count($special)===0){if(!$policy['working'][(int)$day->format('w')]){return false;}
                foreach($policy['closed']as$r){if($tzfd_date>=$r[0]&&$tzfd_date<=$r[1]){return false;}}}
            $rules=count($special)?$special:(count($weekday)?$weekday:$all);$inside=false;
            foreach($rules as$r){if($start-$s['before']>=$r['start']&&$end+$s['after']<=$r['end']){$inside=true;break;}}
            if(!$inside){return false;}
            $zone=new DateTimeZone($policy['zone']);
            $a=self::instant(gmdate('Y-m-d H:i:s',$range['start']),$zone);$b=self::instant(gmdate('Y-m-d H:i:s',$range['end']),$zone);
            if($b-$a!==$s['duration']*60){return false;}
            return ($policy['min']===null||$a>=$policy['min'])&&($policy['max']===null||$b<=$policy['max']);
        }catch(InvalidArgumentException $e){return false;}
    }
    public static function site_policy($field,$bound_format=null) {
        try { return self::compile($field,wp_timezone(),current_datetime(),$bound_format); }
        catch (Throwable $error) { throw new InvalidArgumentException('invalid_calendar_configuration'); }
    }
    public static function assess($apps,$fields,$bound_format=null) {
        $policies=array();foreach($fields as$f){if(is_object($f)&&isset($f->ftype)&&$f->ftype==='fapp'){$policies[$f->name]=self::site_policy($f,$bound_format);}}
        $conflicts=array();foreach($apps as$tzfd_i=>$tzfd_app){if(!isset($policies[$tzfd_app['field']])||!self::allows($policies[$tzfd_app['field']],$tzfd_app['date'],$tzfd_app['slot'],$tzfd_app['serviceindex'])){$conflicts[]=array('index'=>$tzfd_i,'reason'=>'calendar_unavailable');}}
        return array('ok'=>count($conflicts)===0,'conflicts'=>$conflicts);
    }
}
