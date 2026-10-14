<?php
/**
 * Runs the booking pipeline. Infrastructure, not an agent: it runs with manager authority so it can
 * direct the workers and specialists below it. Registers the default agents once.
 *
 * Order for one booking:  booking-intake (worker)  ->  booking-check (specialist)
 *                         ->  slot-check (specialist)  ->  approver (specialist, only when automatic)
 * Each step runs only when the booking is in the state the step expects.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-agent-tier.php';
require_once __DIR__ . '/class-tzfd-agent-registry.php';
require_once __DIR__ . '/class-tzfd-worker-booking-intake.php';
require_once __DIR__ . '/class-tzfd-specialist-booking-check.php';
require_once __DIR__ . '/class-tzfd-specialist-slot-check.php';
require_once __DIR__ . '/class-tzfd-specialist-approver.php';
require_once __DIR__ . '/class-tzfd-manager-pipeline.php';

final class TZFD_Orchestrator {

    /** The orchestrator directs the pipeline, so it calls with manager authority. */
    const AUTHORITY = TZFD_Agent_Tier::MANAGER;

    private static $booted = false;

    public static function boot() {
        if ( self::$booted ) { return; }
        self::$booted = true;
        foreach ( array(
            new TZFD_Worker_Booking_Intake(),
            new TZFD_Specialist_Booking_Check(),
            new TZFD_Specialist_Slot_Check(),
            new TZFD_Specialist_Approver(),
            new TZFD_Manager_Pipeline(),
        ) as $agent ) {
            TZFD_Agent_Registry::register( $agent );
        }
    }

    /** Intake, then the checks. Returns ok, booking_id, state and status for the customer. */
    public static function submit( $formid, array $draft, $source = 'ai-assistant' ) {
        self::boot();
        $intake = TZFD_Agent_Registry::call( self::AUTHORITY, TZFD_Worker_Booking_Intake::NAME, TZFD_Agent_Tier::DRAFT, array(
            'formid' => (int) $formid,
            'draft'  => $draft,
            'source' => (string) $source,
        ) );
        if ( empty( $intake['ok'] ) ) { return $intake; }

        $id    = (int) $intake['booking_id'];
        $state = self::advance( $id );
        return array(
            'ok'         => true,
            'booking_id' => $id,
            'state'      => $state,
            'status'     => TZFD_Pipeline_Rules::APPROVED === $state ? 'confirmed' : 'pending_confirmation',
        );
    }

    /**
     * Runs the next steps a booking is ready for. Returns the state afterwards.
     * processing -> booking check; processed -> slot check, then approval when automatic.
     */
    public static function advance( $id ) {
        self::boot();
        $id    = (int) $id;
        $state = TZFD_Pipeline::state_of( $id );

        if ( TZFD_Pipeline_Rules::PROCESSING === $state ) {
            $check = TZFD_Agent_Registry::call( self::AUTHORITY, TZFD_Specialist_Booking_Check::NAME, TZFD_Agent_Tier::CHECK, array( 'booking_id' => $id ) );
            if ( empty( $check['passed'] ) ) { return TZFD_Pipeline::state_of( $id ); }
            $state = TZFD_Pipeline::state_of( $id );
        }

        if ( TZFD_Pipeline_Rules::PROCESSED === $state ) {
            $slot = TZFD_Agent_Registry::call( self::AUTHORITY, TZFD_Specialist_Slot_Check::NAME, TZFD_Agent_Tier::CHECK, array( 'booking_id' => $id ) );
            if ( empty( $slot['passed'] ) ) { return TZFD_Pipeline::state_of( $id ); }
            $row = TZFD_Pipeline::row( $id );
            if ( $row && TZFD_Pipeline::autonomous( $id, (string) $row->source ) ) {
                TZFD_Agent_Registry::call( self::AUTHORITY, TZFD_Specialist_Approver::NAME, TZFD_Agent_Tier::APPROVE, array(
                    'booking_id' => $id,
                    'reason'     => 'approved automatically',
                ) );
            }
        }
        return TZFD_Pipeline::state_of( $id );
    }
}
