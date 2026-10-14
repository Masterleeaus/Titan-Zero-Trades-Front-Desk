<?php
/**
 * Specialist: booking check. Runs the compliance rules on a processing booking.
 * Pass moves it to processed. Fail escalates it to staff. Tier: specialist. Actions: check.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-agent.php';

final class TZFD_Specialist_Booking_Check extends TZFD_Agent {

    const NAME = 'booking-check';

    public function __construct() {
        parent::__construct( self::NAME, TZFD_Agent_Tier::SPECIALIST );
    }

    protected function handle( $action, array $args ) {
        if ( TZFD_Agent_Tier::CHECK !== $action ) {
            return $this->fail( 'unsupported_action' );
        }
        $id  = isset( $args['booking_id'] ) ? (int) $args['booking_id'] : 0;
        $row = TZFD_Pipeline::row( $id );
        if ( ! $row ) {
            return $this->fail( 'not_found' );
        }
        if ( TZFD_Pipeline_Rules::PROCESSING !== $row->state ) {
            return array( 'ok' => true, 'passed' => TZFD_Pipeline_Rules::PROCESSED === $row->state, 'state' => (string) $row->state );
        }

        $facts = TZFD_Pipeline::details( $id );
        if ( null === $facts ) {
            TZFD_Pipeline::move( $id, TZFD_Pipeline_Rules::ESCALATED, 'booking_check', 'booking_unreadable', self::NAME );
            return array( 'ok' => true, 'passed' => false, 'state' => TZFD_Pipeline::state_of( $id ) );
        }

        $check = TZFD_Pipeline_Rules::compliance( $facts, current_time( 'Y-m-d H:i' ) );
        if ( ! $check['ok'] ) {
            TZFD_Pipeline::move( $id, TZFD_Pipeline_Rules::ESCALATED, 'booking_check', implode( ', ', $check['reasons'] ), self::NAME );
            return array( 'ok' => true, 'passed' => false, 'state' => TZFD_Pipeline::state_of( $id ) );
        }

        $moved = TZFD_Pipeline::move( $id, TZFD_Pipeline_Rules::PROCESSED, 'booking_check', 'passed booking checks', self::NAME );
        return array( 'ok' => true, 'passed' => $moved, 'state' => TZFD_Pipeline::state_of( $id ) );
    }
}
