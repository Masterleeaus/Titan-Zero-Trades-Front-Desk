<?php
/**
 * Deterministic guided booking core for the Free plugin. No hosted AI, no slot invention.
 *
 * Intent is detected with fixed rules. Availability comes only from TZFD_Slot_Search over an
 * owner-verified catalogue and a complete reservation snapshot. A confirmation token binds the
 * customer's exact choice (company, service, slot, normalised fields). Submission re-checks the
 * slot against a fresh engine result and refuses on any mismatch. The caller persists the booking
 * through the existing booking path. This class never writes to the database.
 * GPL-2.0-only.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-tzfd-slot-search.php';

final class TZFD_Guided_Booking {
    const INTENT_BOOK         = 'book';
    const INTENT_AVAILABILITY = 'availability';
    const INTENT_CHANGE       = 'change';
    const INTENT_UNKNOWN      = 'unknown';

    /** Message when a customer asks to change or cancel an existing booking. Booking flow never handles these. */
    const CHANGE_MESSAGE = 'This looks like a change to an existing booking. Use the link we emailed you, or ask staff for help.';
    const MAX_TEXT            = 1000;
    const MAX_NAME            = 100;
    const MAX_CONTACT         = 120;
    const MAX_NOTES           = 500;

    /** Shown whenever guidance cannot proceed. The standard online or manual booking form always remains available. */
    const FALLBACK_MESSAGE = 'You can still book with the standard booking form on this page, or call the business directly.';

    /**
     * Fixed-rule intent detection. Anything outside the rules is UNKNOWN and gets the fallback.
     *
     * @param mixed $text Customer text.
     * @return string One of the INTENT_ constants.
     */
    public static function detect_intent( $text ) {
        if ( ! is_string( $text ) || '' === trim( $text ) || strlen( $text ) > self::MAX_TEXT ) {
            return self::INTENT_UNKNOWN;
        }
        $lower = strtolower( $text );
        // Change and cancel wording is checked first. "reschedule my appointment" contains "appointment",
        // which would otherwise start a new booking for an existing one.
        if ( preg_match( '/\b(cancel\w*|reschedul\w*|rebook\w*|postpone\w*|no longer need)\b/', $lower ) ) {
            return self::INTENT_CHANGE;
        }
        if ( preg_match( '/\b(book|booking|appointment|reserve|schedule)\b/', $lower ) ) {
            return self::INTENT_BOOK;
        }
        if ( preg_match( '/\b(available|availability|free slots?|open times?|what times?)\b/', $lower ) ) {
            return self::INTENT_AVAILABILITY;
        }
        return self::INTENT_UNKNOWN;
    }

    /**
     * Services the customer may choose from. The list comes from the owner's catalogue, never from the text.
     *
     * @param array $services Records of id, name, duration_minutes.
     * @return array Normalised services.
     * @throws InvalidArgumentException When a record is invalid.
     */
    public static function normalise_services( $services ) {
        if ( ! is_array( $services ) || count( $services ) > 100 ) {
            throw new InvalidArgumentException( 'Invalid service list.' );
        }
        $out  = array();
        $seen = array();
        foreach ( $services as $service ) {
            if ( ! is_array( $service ) ) { throw new InvalidArgumentException( 'Invalid service record.' ); }
            $id       = TZFD_Resource_Catalog::identifier( isset( $service['id'] ) ? $service['id'] : null );
            $name     = self::plain( isset( $service['name'] ) ? $service['name'] : null, 100 );
            $duration = isset( $service['duration_minutes'] ) ? $service['duration_minutes'] : null;
            if ( isset( $seen[ $id ] ) ) { throw new InvalidArgumentException( 'Duplicate service ID.' ); }
            if ( '' === $name ) { throw new InvalidArgumentException( 'Service name required.' ); }
            if ( ! is_int( $duration ) || $duration < 5 || $duration > 1440 ) { throw new InvalidArgumentException( 'Invalid service duration.' ); }
            $seen[ $id ] = true;
            $out[]       = array( 'id' => $id, 'name' => $name, 'duration_minutes' => $duration );
        }
        return $out;
    }

    /**
     * Services whose name words appear in the customer's text. Several matches mean the customer must choose.
     *
     * @param array  $services Normalised services from normalise_services().
     * @param string $text     Customer text.
     * @return array Matching service records, best match first. Empty when nothing matches.
     */
    public static function match_services( $services, $text ) {
        if ( ! is_string( $text ) || strlen( $text ) > self::MAX_TEXT ) { return array(); }
        $words = array_flip( preg_split( '/[^a-z0-9]+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
        $hits  = array();
        foreach ( $services as $service ) {
            $score = 0;
            foreach ( preg_split( '/[^a-z0-9]+/', strtolower( $service['name'] ), -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
                if ( isset( $words[ $word ] ) ) { $score++; }
            }
            if ( $score > 0 ) { $hits[] = array( 'score' => $score, 'service' => $service ); }
        }
        usort( $hits, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
        return array_map( function ( $hit ) { return $hit['service']; }, $hits );
    }

    /**
     * Next step in the guided flow for one customer message. Pure and deterministic: it never books, never invents
     * a slot, and returns only service IDs from the owner's catalogue.
     *
     * Steps: handoff (change or cancel request), fallback (unknown intent), choose_service (the customer must pick,
     * optionally from a shortlist), confirm_service (one clear match, customer must still confirm) and
     * show_availability (a valid chosen service ID; the caller then runs propose()).
     *
     * @param mixed      $text              Customer text.
     * @param array      $services          Raw service records, validated with normalise_services().
     * @param mixed|null $chosen_service_id Service ID the customer picked in the UI, if any.
     * @return array Keys: step, service_ids, message.
     * @throws InvalidArgumentException When the service list is invalid.
     */
    public static function next_step( $text, $services, $chosen_service_id = null ) {
        $intent = self::detect_intent( $text );
        if ( self::INTENT_CHANGE === $intent ) {
            return array( 'step' => 'handoff', 'service_ids' => array(), 'message' => self::CHANGE_MESSAGE );
        }
        if ( self::INTENT_UNKNOWN === $intent ) {
            return array( 'step' => 'fallback', 'service_ids' => array(), 'message' => self::FALLBACK_MESSAGE );
        }
        $services = self::normalise_services( $services );
        $ids      = array_map( function ( $s ) { return $s['id']; }, $services );
        if ( null !== $chosen_service_id ) {
            if ( is_string( $chosen_service_id ) && in_array( $chosen_service_id, $ids, true ) ) {
                return array( 'step' => 'show_availability', 'service_ids' => array( $chosen_service_id ), 'message' => '' );
            }
            // A choice outside the catalogue is refused, never accepted as a new service.
            return array( 'step' => 'choose_service', 'service_ids' => $ids, 'message' => 'Please choose one of our services.' );
        }
        $hits = self::match_services( $services, $text );
        if ( 1 === count( $hits ) ) {
            return array( 'step' => 'confirm_service', 'service_ids' => array( $hits[0]['id'] ), 'message' => 'Is this the service you want?' );
        }
        $shortlist = count( $hits ) > 1 ? array_map( function ( $s ) { return $s['id']; }, $hits ) : $ids;
        return array( 'step' => 'choose_service', 'service_ids' => $shortlist, 'message' => 'Which service would you like?' );
    }

    /**
     * Validate and normalise the customer's form fields. Fields the owner marked required must be present.
     *
     * @param array $values   Raw field values from the request, keyed by field name.
     * @param array $required Required field names (any of name, contact, notes).
     * @return array Normalised values.
     * @throws InvalidArgumentException When a field is missing or invalid.
     */
    public static function normalise_fields( $values, $required ) {
        if ( ! is_array( $values ) ) { throw new InvalidArgumentException( 'Invalid form values.' ); }
        $out = array();
        foreach ( $required as $field ) {
            if ( ! in_array( $field, array( 'name', 'contact' ), true ) ) { throw new InvalidArgumentException( 'Unknown required field.' ); }
        }
        $name    = self::plain( isset( $values['name'] ) ? $values['name'] : '', self::MAX_NAME );
        $contact = self::plain( isset( $values['contact'] ) ? $values['contact'] : '', self::MAX_CONTACT );
        $notes   = self::plain( isset( $values['notes'] ) ? $values['notes'] : '', self::MAX_NOTES );
        if ( in_array( 'name', $required, true ) && '' === $name ) { throw new InvalidArgumentException( 'Name is required.' ); }
        if ( in_array( 'contact', $required, true ) && '' === $contact ) { throw new InvalidArgumentException( 'Contact is required.' ); }
        if ( '' !== $contact && ! filter_var( $contact, FILTER_VALIDATE_EMAIL ) && ! preg_match( '/^\+?[0-9 ()\-]{6,20}$/', $contact ) ) {
            throw new InvalidArgumentException( 'Contact must be an email address or phone number.' );
        }
        $out['name']    = $name;
        $out['contact'] = $contact;
        $out['notes']   = $notes;
        return $out;
    }

    /**
     * Ask the local engine for bookable slots. Never fabricates a slot.
     *
     * @param array      $catalog      Owner-verified resource catalogue.
     * @param array      $criteria     Criteria for TZFD_Slot_Search, including verified_company_id and snapshot_company_id.
     * @param array|null $reservations Complete company-scoped reservation snapshot, or null when unavailable.
     * @return array Status (ok, no_slots, engine_unavailable, engine_rejected) and slots.
     */
    public static function propose( $catalog, $criteria, $reservations ) {
        if ( null === $reservations ) {
            return array( 'status' => 'engine_unavailable', 'slots' => array() );
        }
        try {
            $result = TZFD_Slot_Search::search( $catalog, $criteria, $reservations );
        } catch ( InvalidArgumentException $e ) {
            return array( 'status' => 'engine_rejected', 'slots' => array() );
        } catch ( Throwable $e ) {
            return array( 'status' => 'engine_unavailable', 'slots' => array() );
        }
        if ( empty( $result['slots'] ) ) {
            return array( 'status' => 'no_slots', 'slots' => array() );
        }
        return array( 'status' => 'ok', 'slots' => $result['slots'] );
    }

    /**
     * Build the confirmation shown to the customer, with the token that must be echoed back to submit.
     * The summary shows local wall-clock times in the location's timezone, so DST changes read correctly.
     *
     * @return array Summary lines and token, or an error status with no token.
     */
    public static function confirmation( $company_id, $catalog, $location_id, $service, $slot, $values ) {
        $company_id = TZFD_Resource_Catalog::identifier( $company_id );
        $payload    = self::payload( $company_id, $service['id'], $slot, $values );
        $zone       = self::zone_for( $catalog, $location_id );
        $local      = self::local_range( $slot['start'], $slot['end'], $zone );
        return array(
            'status'  => 'confirm',
            'lines'   => array(
                'Service: ' . $service['name'],
                'When: ' . $local,
                'Name: ' . $values['name'],
                'Contact: ' . $values['contact'],
            ),
            'token'   => self::token( $payload ),
            'commit'  => false,
        );
    }

    /**
     * Final gate before the caller persists the booking. Re-runs the engine so a slot taken since the
     * confirmation is rejected, and requires the confirmation token to match the exact choice.
     *
     * @return array status ready (with normalised payload), or a refusal status. Never commits.
     */
    public static function submit( $company_id, $catalog, $criteria, $reservations, $service, $slot, $values, $token ) {
        $company_id = TZFD_Resource_Catalog::identifier( $company_id );
        if ( ! isset( $criteria['verified_company_id'] ) || $criteria['verified_company_id'] !== $company_id ) {
            return array( 'status' => 'tenant_mismatch' );
        }
        $payload = self::payload( $company_id, $service['id'], $slot, $values );
        if ( ! is_string( $token ) || ! hash_equals( self::token( $payload ), $token ) ) {
            return array( 'status' => 'confirmation_mismatch' );
        }
        $fresh = self::propose( $catalog, $criteria, $reservations );
        if ( 'ok' !== $fresh['status'] ) {
            return array( 'status' => $fresh['status'] === 'no_slots' ? 'slot_unavailable' : $fresh['status'] );
        }
        $still = false;
        foreach ( $fresh['slots'] as $candidate ) {
            if ( $candidate['start'] === $slot['start'] && $candidate['end'] === $slot['end'] ) { $still = true; break; }
        }
        if ( ! $still ) { return array( 'status' => 'slot_unavailable' ); }
        return array( 'status' => 'ready', 'payload' => $payload );
    }

    /** Canonical payload that the confirmation token covers. */
    private static function payload( $company_id, $service_id, $slot, $values ) {
        return array(
            'company_id' => $company_id,
            'service_id' => $service_id,
            'start'      => (string) $slot['start'],
            'end'        => (string) $slot['end'],
            'resources'  => array_values( (array) $slot['resource_ids'] ),
            'name'       => (string) $values['name'],
            'contact'    => (string) $values['contact'],
            'notes'      => (string) $values['notes'],
        );
    }

    private static function token( $payload ) {
        return hash( 'sha256', (string) json_encode( $payload ) );
    }

    private static function zone_for( $catalog, $location_id ) {
        foreach ( (array) $catalog['locations'] as $location ) {
            if ( $location['id'] === $location_id ) { return new DateTimeZone( $location['timezone'] ); }
        }
        throw new InvalidArgumentException( 'Unknown location for confirmation.' );
    }

    private static function local_range( $start, $end, $zone ) {
        $from = new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) );
        $to   = new DateTimeImmutable( $end, new DateTimeZone( 'UTC' ) );
        return $from->setTimezone( $zone )->format( 'D j M Y, H:i' ) . ' to ' . $to->setTimezone( $zone )->format( 'H:i T' );
    }

    private static function plain( $value, $max ) {
        if ( ! is_string( $value ) ) { return ''; }
        $value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', ' ', $value ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
    }
}
