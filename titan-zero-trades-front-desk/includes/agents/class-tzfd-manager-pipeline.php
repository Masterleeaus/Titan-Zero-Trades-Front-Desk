<?php
/**
 * Manager: pipeline. Reads the telemetry (predict), holds the pipeline policy (set_policy) and can reject
 * escalated bookings (reject). It does not approve bookings. Tier: manager.
 *
 * The policy is stored in one option. Specialists read it and never call this agent.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-agent.php';

final class TZFD_Manager_Pipeline extends TZFD_Agent {

    const NAME   = 'pipeline-manager';
    const OPTION = 'tzfd_pipeline_policy';

    public function __construct() {
        parent::__construct( self::NAME, TZFD_Agent_Tier::MANAGER );
    }

    /** Current policy, with defaults. Read by specialists. */
    public static function policy() {
        $stored = get_option( self::OPTION, array() );
        return array(
            'autonomous' => is_array( $stored ) && ! empty( $stored['autonomous'] ),
        );
    }

    protected function handle( $action, array $args ) {
        switch ( $action ) {
            case TZFD_Agent_Tier::PREDICT:
                $days = isset( $args['days'] ) ? (int) $args['days'] : 30;
                return array( 'ok' => true, 'forecast' => TZFD_Pipeline::stats( $days ) );

            case TZFD_Agent_Tier::SET_POLICY:
                $policy = array( 'autonomous' => ! empty( $args['autonomous'] ) );
                update_option( self::OPTION, $policy, false );
                return array( 'ok' => true, 'policy' => $policy );

            case TZFD_Agent_Tier::REJECT:
                // Managers reject only what the checks escalated. They do not reject items the pipeline is still checking.
                $id  = isset( $args['booking_id'] ) ? (int) $args['booking_id'] : 0;
                $row = TZFD_Pipeline::row( $id );
                if ( ! $row || TZFD_Pipeline_Rules::ESCALATED !== $row->state ) {
                    return $this->fail( 'not_escalated' );
                }
                $reason = isset( $args['reason'] ) ? (string) $args['reason'] : 'rejected by manager';
                return TZFD_Pipeline::reject( $id, 'manager:' . self::NAME, $reason );
        }
        return $this->fail( 'unsupported_action' );
    }
}
