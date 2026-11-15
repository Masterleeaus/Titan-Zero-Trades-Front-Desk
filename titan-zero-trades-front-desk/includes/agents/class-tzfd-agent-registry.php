<?php
/**
 * Holds the agents and runs calls between them. A call is allowed only from the same tier or a
 * higher one to a lower one. A worker that needs a manager raises an event instead.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-agent.php';

final class TZFD_Agent_Registry {

    private static $agents = array();

    /** Returns false when an agent with that name is already registered. */
    public static function register( TZFD_Agent $agent ) {
        if ( isset( self::$agents[ $agent->name() ] ) ) { return false; }
        self::$agents[ $agent->name() ] = $agent;
        return true;
    }

    public static function get( $name ) {
        return self::$agents[ (string) $name ] ?? null;
    }

    public static function names() {
        return array_keys( self::$agents );
    }

    /**
     * Calls an agent on behalf of a caller at $caller_tier. The orchestrator calls with the manager tier,
     * since it runs the whole pipeline.
     */
    public static function call( $caller_tier, $target_name, $action, array $args = array() ) {
        $target = self::get( $target_name );
        if ( null === $target ) {
            return array( 'ok' => false, 'error' => 'unknown_agent', 'agent' => (string) $target_name );
        }
        if ( ! TZFD_Agent_Tier::can_call( $caller_tier, $target->tier() ) ) {
            return array( 'ok' => false, 'error' => 'upward_call_refused', 'agent' => $target->name() );
        }
        return $target->act( $action, $args );
    }

    /** For tests only. */
    public static function reset() {
        self::$agents = array();
    }
}
