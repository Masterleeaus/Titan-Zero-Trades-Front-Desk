<?php
/**
 * Agent tiers and what each tier may do. Pure PHP, no WordPress calls.
 *
 * Workers propose: assist, suggest and draft. They never change a confirmed booking or a sent quote.
 * Specialists act within rules: check, approve and send. They never change limits or policy.
 * Managers set policy and read predictions: predict, set_policy and reject. They do not approve items.
 * Humans sit above all three tiers and are not governed by this table.
 *
 * Each tier lists its own actions. Permissions are not inherited between tiers.
 * An agent may call only agents at its own tier or below. Going up is done by raising an event, never by a call.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TZFD_Agent_Tier {

    const WORKER     = 'worker';
    const SPECIALIST = 'specialist';
    const MANAGER    = 'manager';

    const READ       = 'read';
    const ASSIST     = 'assist';
    const SUGGEST    = 'suggest';
    const DRAFT      = 'draft';
    const CHECK      = 'check';
    const APPROVE    = 'approve';
    const SEND       = 'send';
    const PREDICT    = 'predict';
    const SET_POLICY = 'set_policy';
    const REJECT     = 'reject';
    const ESCALATE   = 'escalate';

    /** Lowest first. */
    public static function tiers() {
        return array( self::WORKER, self::SPECIALIST, self::MANAGER );
    }

    /** 1 for worker, 2 for specialist, 3 for manager, 0 for anything else. */
    public static function rank( $tier ) {
        $index = array_search( (string) $tier, self::tiers(), true );
        return false === $index ? 0 : $index + 1;
    }

    public static function permissions() {
        return array(
            self::WORKER     => array( self::READ, self::ASSIST, self::SUGGEST, self::DRAFT, self::ESCALATE ),
            self::SPECIALIST => array( self::READ, self::CHECK, self::APPROVE, self::SEND, self::ESCALATE ),
            self::MANAGER    => array( self::READ, self::PREDICT, self::SET_POLICY, self::REJECT, self::ESCALATE ),
        );
    }

    public static function permits( $tier, $action ) {
        $all = self::permissions();
        return isset( $all[ (string) $tier ] ) && in_array( (string) $action, $all[ (string) $tier ], true );
    }

    /** True when $caller_tier may call an agent at $target_tier: same tier or lower. */
    public static function can_call( $caller_tier, $target_tier ) {
        $caller = self::rank( $caller_tier );
        $target = self::rank( $target_tier );
        return $caller > 0 && $target > 0 && $target <= $caller;
    }
}
