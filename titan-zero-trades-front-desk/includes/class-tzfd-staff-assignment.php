<?php
/** Deterministic read-only staff assignment and workload ranking. GPL-2.0-only. */
if (!defined('ABSPATH')) exit;
final class TZFD_Staff_Assignment {
    /** Staff roster and load snapshot must be verified by Workspace and scoped to one company. */
    public static function recommend($request, $staff, $loads) {
        if (!is_array($request) || !is_array($staff) || !is_array($loads)) throw new InvalidArgumentException('Invalid assignment inputs.');
        $company = isset($request['company_id']) ? $request['company_id'] : null;
        if (!is_string($company) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $company) || !isset($request['verified_company_id']) || $request['verified_company_id'] !== $company) throw new InvalidArgumentException('Verified company scope required.');
        if (empty($request['roster_verified']) || empty($request['load_snapshot_complete']) || !isset($request['snapshot_company_id']) || $request['snapshot_company_id'] !== $company) throw new InvalidArgumentException('Verified roster and complete company load snapshot required.');
        $service = isset($request['service_id']) ? $request['service_id'] : null;
        $location = isset($request['location_id']) ? $request['location_id'] : null;
        if (!is_string($service) || $service === '' || !is_string($location) || $location === '') throw new InvalidArgumentException('Service and location required.');
        $required = isset($request['required_skills']) ? $request['required_skills'] : array();
        if (!is_array($required)) throw new InvalidArgumentException('Invalid required skills.');
        $available = isset($request['available_staff_ids']) ? $request['available_staff_ids'] : null;
        if (!is_array($available)) throw new InvalidArgumentException('Authoritative availability list required.');
        $available_ids = array();
        foreach ($available as $id) { if (!is_string($id) || $id === '') throw new InvalidArgumentException('Invalid available staff identifier.'); $available_ids[$id] = true; }
        $score = array(); $seen = array();
        foreach ($staff as $member) {
            if (!is_array($member) || !isset($member['id'], $member['company_id']) || !is_string($member['id']) || $member['id'] === '') throw new InvalidArgumentException('Invalid staff record.');
            $id = $member['id'];
            if (isset($seen[$id])) throw new InvalidArgumentException('Duplicate staff record.');
            $seen[$id] = true;
            if ($member['company_id'] !== $company) throw new InvalidArgumentException('Cross-company roster record.');
            if (empty($member['enabled']) || !isset($available_ids[$id])) continue;
            if (!isset($member['location_ids'], $member['service_ids'], $member['skills']) || !is_array($member['location_ids']) || !is_array($member['service_ids']) || !is_array($member['skills'])) throw new InvalidArgumentException('Incomplete staff qualification data.');
            if (!in_array($location, $member['location_ids'], true) || !in_array($service, $member['service_ids'], true)) continue;
            if (count(array_diff($required, $member['skills']))) continue;
            if (!array_key_exists($id, $loads) || !is_array($loads[$id]) || !isset($loads[$id]['assigned_minutes'], $loads[$id]['capacity_minutes'])) throw new InvalidArgumentException('Missing verified workload entry.');
            $used = $loads[$id]['assigned_minutes']; $capacity = $loads[$id]['capacity_minutes'];
            if (!is_int($used) || !is_int($capacity) || $used < 0 || $capacity <= 0 || $used > $capacity) throw new InvalidArgumentException('Invalid workload entry.');
            $score[] = array('staff_id'=>$id,'assigned_minutes'=>$used,'capacity_minutes'=>$capacity,'utilisation'=>$used/$capacity);
        }
        usort($score, function($a, $b) { $cmp = $a['utilisation'] <=> $b['utilisation']; return $cmp ?: strcmp($a['staff_id'], $b['staff_id']); });
        return array('schema_version'=>1,'company_id'=>$company,'read_only'=>true,'assignment_committed'=>false,'ranked_staff'=>$score,'recommended_staff_id'=>count($score) ? $score[0]['staff_id'] : null,'requires_booking_revalidation'=>true);
    }
}
