<?php
/**
 * Specialist: approver. Confirms a booking in the calendar once the checks have passed and automatic
 * approval is on. Staff approvals do not go through this agent. Tier: specialist. Actions: approve.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-agent.php';

final class TZFD_Specialist_Approver extends TZFD_Agent {

    const NAME = 'approver';

    public function __construct() {
        parent::__construct( self::NAME, TZFD_Agent_Tier::SPECIALIST );
    }

    protected function handle( $action, array $args ) {
        if ( TZFD_Agent_Tier::APPROVE !== $action ) {
            return $this->fail( 'unsupported_action' );
        }
        $id     = isset( $args['booking_id'] ) ? (int) $args['booking_id'] : 0;
        $reason = isset( $args['reason'] ) ? (string) $args['reason'] : 'approved automatically';
        return TZFD_Pipeline::approve( $id, 'specialist:' . self::NAME, $reason );
    }
}
