<?php
/** Deterministic, read-only availability candidate search. GPL-2.0-only. */
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/class-tzfd-resource-availability.php';
final class TZFD_Slot_Search {
    /** Caller must supply owner-verified company-specific catalogue and reservation snapshot. */
    public static function search($catalog, $criteria, $reservations) {
        if (!is_array($criteria)) throw new InvalidArgumentException('Invalid search criteria.');
        $company=isset($criteria['company_id']) ? $criteria['company_id'] : null;
        $company=TZFD_Resource_Catalog::identifier($company);
        if (!isset($criteria['verified_company_id']) || $criteria['verified_company_id'] !== $company) {
            throw new InvalidArgumentException('Company scope is not verified.');
        }
        if (!isset($criteria['required_resource_ids']) || !is_array($criteria['required_resource_ids']) || !count($criteria['required_resource_ids'])) {
            throw new InvalidArgumentException('Explicit required resources are mandatory.');
        }
        if (!is_array($reservations)) throw new InvalidArgumentException('Reservation snapshot unavailable.');
        if (!isset($criteria['snapshot_company_id']) || $criteria['snapshot_company_id'] !== $company || empty($criteria['snapshot_complete'])) {
            throw new InvalidArgumentException('Complete company-scoped reservation snapshot required.');
        }
        $start=TZFD_Resource_Availability::instant(isset($criteria['start'])?$criteria['start']:null);
        $finish=TZFD_Resource_Availability::instant(isset($criteria['end'])?$criteria['end']:null);
        $duration=isset($criteria['duration_minutes'])?$criteria['duration_minutes']:null;
        $step=isset($criteria['step_minutes'])?$criteria['step_minutes']:null;
        $max=isset($criteria['limit'])?$criteria['limit']:20;
        if (!is_int($duration) || $duration<5 || $duration>1440 || !is_int($step) || $step<5 || $step>240 || !is_int($max) || $max<1 || $max>100) {
            throw new InvalidArgumentException('Invalid search bounds.');
        }
        if ($finish<=$start || $finish-$start>7*86400) throw new InvalidArgumentException('Search must cover at most seven days.');
        $slots=array(); $checked=0;
        for ($at=$start; $at+$duration*60<=$finish; $at+=$step*60) {
            if (++$checked>2016) throw new InvalidArgumentException('Too many candidate slots.');
            $request=array('service_id'=>isset($criteria['service_id'])?$criteria['service_id']:null,
                'location_id'=>isset($criteria['location_id'])?$criteria['location_id']:'',
                'postcode'=>isset($criteria['postcode'])?$criteria['postcode']:'',
                'quantity'=>isset($criteria['quantity'])?$criteria['quantity']:1,
                'required_resource_ids'=>$criteria['required_resource_ids'],
                'start'=>gmdate('Y-m-d\TH:i:s\Z',$at), 'end'=>gmdate('Y-m-d\TH:i:s\Z',$at+$duration*60));
            $preview=TZFD_Resource_Availability::evaluate($catalog,$request,$reservations);
            if ($preview['selection_available']===true) {
                $slots[]=array('start'=>$request['start'],'end'=>$request['end'],'resource_ids'=>$preview['required_resource_ids']);
                if (count($slots)>=$max) break;
            }
        }
        return array('schema_version'=>1,'company_id'=>$company,'read_only'=>true,'reservation_committed'=>false,
            'snapshot_source'=>'caller_verified','slots'=>$slots,'candidates_checked'=>$checked);
    }
}
