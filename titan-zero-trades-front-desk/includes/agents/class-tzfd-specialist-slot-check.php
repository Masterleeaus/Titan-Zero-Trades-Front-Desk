<?php
/**
 * Specialist: slot check. Confirms the slot is still free under the calendar's capacity lock, and asks
 * any extra jobs checks (workers, resources) through a filter. Pass leaves the booking processed.
 * Fail escalates it. Tier: specialist. Actions: check.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-agent.php';

final class TZFD_Specialist_Slot_Check extends TZFD_Agent {

    const NAME = 'slot-check';

    public function __construct() {
        parent::__construct( self::NAME, TZFD_Agent_Tier::SPECIALIST );
    }

    protected function handle( $action, array $args ) {
        global $wpdb;
        if ( TZFD_Agent_Tier::CHECK !== $action ) {
            return $this->fail( 'unsupported_action' );
        }
        $id  = isset( $args['booking_id'] ) ? (int) $args['booking_id'] : 0;
        $row = TZFD_Pipeline::row( $id );
        if ( ! $row ) {
            return $this->fail( 'not_found' );
        }
        if ( TZFD_Pipeline_Rules::PROCESSED !== $row->state ) {
            return array( 'ok' => true, 'passed' => false, 'state' => (string) $row->state );
        }

        $facts = TZFD_Pipeline::details( $id );
        if ( null === $facts ) {
            TZFD_Pipeline::move( $id, TZFD_Pipeline_Rules::ESCALATED, 'slot_check', 'booking_unreadable', self::NAME );
            return array( 'ok' => true, 'passed' => false, 'state' => TZFD_Pipeline::state_of( $id ) );
        }

        $lock = TZFD_Capacity_Lock::acquire( $wpdb, $facts['formid'] );
        if ( false === $lock ) {
            // Left processed. The supervisor retries it.
            return array( 'ok' => true, 'passed' => false, 'busy' => true, 'state' => TZFD_Pipeline_Rules::PROCESSED );
        }
        $check  = TZFD_Pipeline_Rules::fulfilment( array(
            'occupied' => TZFD_Pipeline::occupied_count( $facts['formid'], $facts['date'], $facts['slot'], $id ),
            'capacity' => TZFD_Pipeline::capacity( $facts['formid'], $facts['date'], $facts['slot'] ),
        ) );
        $extra  = (array) apply_filters( 'tzfd_pipeline_fulfilment_reasons', array(), $facts, $id );
        TZFD_Capacity_Lock::release( $wpdb, $lock );

        $reasons = TZFD_Pipeline_Rules::merge_reasons( $check['reasons'], array_values( array_filter( $extra, 'is_string' ) ) );
        if ( ! empty( $reasons ) ) {
            TZFD_Pipeline::move( $id, TZFD_Pipeline_Rules::ESCALATED, 'slot_check', implode( ', ', $reasons ), self::NAME );
            return array( 'ok' => true, 'passed' => false, 'state' => TZFD_Pipeline::state_of( $id ) );
        }

        TZFD_Pipeline::note( $id, 'slot_check', 'passed the slot check; waiting for approval' );
        return array( 'ok' => true, 'passed' => true, 'state' => TZFD_Pipeline_Rules::PROCESSED );
    }
}
