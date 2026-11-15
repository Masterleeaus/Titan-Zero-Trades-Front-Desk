<?php
/** Pass 34: bounded allowlisted policy evaluator, with no expression evaluation or SQL. */
if (!defined('ABSPATH')) exit;
final class TZFD_Workflow_Rules {
    public static function evaluate($company_id,$facts,$rules,$context) {
        if (!is_int($company_id)||$company_id<1||!is_array($facts)||!is_array($rules)||!is_array($context)||
            ($context['verified_company_id']??null)!==$company_id || ($context['snapshot_authoritative']??null)!==true || count($rules)>100)
            throw new InvalidArgumentException('Unverified rule inputs.');
        $allowed=['intent','channel','booking_status','call_status'];$matches=[];$ids=[];
        foreach($rules as $rule) {
            if(!is_array($rule)||($rule['company_id']??null)!==$company_id || ($rule['approved']??null)!==true ||
                ($rule['enabled']??null)!==true || !is_string($rule['rule_id']??null) ||
                !preg_match('/^[a-zA-Z0-9:_-]{4,128}$/D',$rule['rule_id']) || isset($ids[$rule['rule_id']]) ||
                !in_array($rule['field']??null,$allowed,true)||
                !is_string($rule['equals']??null) || strlen($rule['equals'])>100 ||
                !in_array($rule['action']??null,['booking_intake','slot_search','callback_queue','human_review'],true))
                throw new InvalidArgumentException('Unsafe or unapproved rule.');
            $ids[$rule['rule_id']]=true;
            if(isset($facts[$rule['field']]) && is_string($facts[$rule['field']]) && $facts[$rule['field']]===$rule['equals'])
                $matches[]=['rule_id'=>$rule['rule_id'],'suggested_action'=>$rule['action']];
        }
        return ['company_id'=>$company_id,'matches'=>$matches,'read_only'=>true,'rules_executed'=>0,'smart_tasks_recorded'=>0];
    }
}
