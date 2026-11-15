<?php
/**
 * Worker: booking intake. Writes a booking as pending and marks it processing. It does not check or approve.
 * Tier: worker. Actions: draft.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-agent.php';

final class TZFD_Worker_Booking_Intake extends TZFD_Agent {

    const NAME = 'booking-intake';

    public function __construct() {
        parent::__construct( self::NAME, TZFD_Agent_Tier::WORKER );
    }

    protected function handle( $action, array $args ) {
        if ( TZFD_Agent_Tier::DRAFT !== $action ) {
            return $this->fail( 'unsupported_action' );
        }
        return TZFD_Pipeline::intake(
            isset( $args['formid'] ) ? (int) $args['formid'] : 0,
            isset( $args['draft'] ) && is_array( $args['draft'] ) ? $args['draft'] : array(),
            isset( $args['source'] ) ? (string) $args['source'] : 'ai-assistant'
        );
    }
}
