<?php
/**
 * Local advanced reports: booking-to-sale conversion, lead sources, utilisation, cancellations, response
 * speed and revenue from settled transactions. Pure PHP, no WordPress calls, free in the single plugin.
 *
 * All metrics use the enquiry cohort: enquiries whose enquiry_date falls in the requested range. Revenue
 * counts only settled transaction records (never quoted prices) dated in the range for that cohort. Totals
 * are reconciled against the per-lead-source breakdown, and a mismatch throws instead of being shown.
 *
 * @package titan-zero-trades-front-desk
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-tzfd-delegated-roles.php';

final class TZFD_Booking_Reports {

	const MAX_RECORDS = 50000;
	const LEAD_SOURCES = array( 'phone', 'web_form', 'walk_in', 'referral', 'social', 'other', 'unknown' );

	/**
	 * @param string $company_id Company the report is scoped to. Every record must match it.
	 * @param array  $request    from, to (Y-m-d inclusive), location_ids (optional list), lead_sources (optional list).
	 * @param array  $records    Enquiry records, see validate_record().
	 * @param array  $capacity   location_id => capacity minutes for the same period (for utilisation).
	 * @throws InvalidArgumentException On invalid input or failed reconciliation.
	 */
	public static function summarise( $company_id, $request, $records, $capacity = array() ) {
		if ( ! self::is_id( $company_id ) || ! is_array( $request ) || ! is_array( $records ) || ! is_array( $capacity ) ) {
			throw new InvalidArgumentException( 'invalid_report_inputs' );
		}
		if ( count( $records ) > self::MAX_RECORDS ) { throw new InvalidArgumentException( 'too_many_records' ); }
		$from = self::date( $request['from'] ?? null );
		$to   = self::date( $request['to'] ?? null );
		if ( null === $from || null === $to || strcmp( $from, $to ) > 0 ) { throw new InvalidArgumentException( 'invalid_date_range' ); }
		$locations = self::filter_list( $request['location_ids'] ?? null );
		$sources   = self::filter_list( $request['lead_sources'] ?? null );
		if ( null !== $sources && array_diff( $sources, self::LEAD_SOURCES ) ) { throw new InvalidArgumentException( 'unknown_lead_source_filter' ); }

		$seen = array();
		$cohort = array();
		foreach ( $records as $raw ) {
			$r = self::validate_record( $raw, $company_id );
			if ( isset( $seen[ $r['id'] ] ) ) { throw new InvalidArgumentException( 'duplicate_record' ); }
			$seen[ $r['id'] ] = true;
			if ( $r['enquiry_date'] < $from || $r['enquiry_date'] > $to ) { continue; }
			if ( null !== $locations && ! in_array( $r['location_id'], $locations, true ) ) { continue; }
			if ( null !== $sources && ! in_array( $r['lead_source'], $sources, true ) ) { continue; }
			$cohort[] = $r;
		}

		$by_source = array();
		foreach ( self::LEAD_SOURCES as $source ) {
			$by_source[ $source ] = array( 'enquiries' => 0, 'booked' => 0, 'revenue_minor' => 0 );
		}
		$responses = array();
		$cancelled = 0;
		$booked_minutes = array();
		foreach ( $cohort as $r ) {
			$bucket = &$by_source[ $r['lead_source'] ];
			$bucket['enquiries']++;
			if ( null !== $r['response_seconds'] ) { $responses[] = $r['response_seconds']; }
			if ( $r['booked'] ) {
				$bucket['booked']++;
				if ( $r['cancelled'] ) {
					$cancelled++;
				} else {
					$booked_minutes[ (string) $r['location_id'] ] = ( $booked_minutes[ (string) $r['location_id'] ] ?? 0 ) + $r['duration_minutes'];
				}
			}
			foreach ( $r['transactions'] as $t ) {
				if ( ! self::in_settled_range( $t, $from, $to ) ) { continue; }
				$bucket['revenue_minor'] += self::signed_amount( $t );
			}
			unset( $bucket );
		}

		// Independent pass over the same records: the grand total must equal the sum of the source buckets.
		$revenue_total = 0;
		foreach ( $cohort as $r ) {
			foreach ( $r['transactions'] as $t ) {
				if ( self::in_settled_range( $t, $from, $to ) ) { $revenue_total += self::signed_amount( $t ); }
			}
		}
		$totals = array( 'enquiries' => 0, 'booked' => 0, 'revenue_minor' => 0 );
		foreach ( $by_source as $bucket ) {
			$totals['enquiries']     += $bucket['enquiries'];
			$totals['booked']        += $bucket['booked'];
			$totals['revenue_minor'] += $bucket['revenue_minor'];
		}
		if ( $totals['revenue_minor'] !== $revenue_total || $totals['enquiries'] !== count( $cohort ) ) {
			throw new InvalidArgumentException( 'reconciliation_failed' );
		}
		$booked_count = $totals['booked'];

		$utilisation = array();
		foreach ( $booked_minutes as $location => $minutes ) {
			$cap = $capacity[ $location ] ?? null;
			$utilisation[] = array(
				'location_id'      => (string) $location,
				'booked_minutes'   => $minutes,
				'capacity_minutes' => is_int( $cap ) && $cap > 0 ? $cap : null,
				'rate'             => is_int( $cap ) && $cap > 0 ? min( 1.0, $minutes / $cap ) : null,
			);
		}
		foreach ( $capacity as $location => $cap ) {
			if ( isset( $booked_minutes[ $location ] ) || ! is_int( $cap ) || $cap < 0 ) { continue; }
			$utilisation[] = array( 'location_id' => (string) $location, 'booked_minutes' => 0, 'capacity_minutes' => $cap, 'rate' => $cap > 0 ? 0.0 : null );
		}
		usort( $utilisation, function ( $a, $b ) { return strcmp( $a['location_id'], $b['location_id'] ); } );

		return array(
			'company_id'          => $company_id,
			'range'               => array( 'from' => $from, 'to' => $to ),
			'totals'              => $totals,
			'conversion'          => self::ratio( $booked_count, $totals['enquiries'] ),
			'cancellation'        => self::ratio( $cancelled, $booked_count ),
			'response_seconds_median' => self::median( $responses ),
			'by_lead_source'      => $by_source,
			'utilisation'         => $utilisation,
			'reconciled'          => true,
			'read_only'           => true,
		);
	}

	/** Builds CSV from an allowlisted set of columns. Cells that a spreadsheet would run as formulas are neutralised. */
	public static function csv( $columns, $rows ) {
		if ( ! is_array( $columns ) || empty( $columns ) || ! is_array( $rows ) ) { throw new InvalidArgumentException( 'invalid_csv_inputs' ); }
		$allowed = array( 'source', 'enquiries', 'booked', 'revenue_minor', 'location_id', 'booked_minutes', 'capacity_minutes', 'rate' );
		foreach ( $columns as $column ) {
			if ( ! in_array( $column, $allowed, true ) ) { throw new InvalidArgumentException( 'unknown_export_column' ); }
		}
		$lines = array( implode( ',', $columns ) );
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $columns as $column ) {
				$value = is_array( $row ) && array_key_exists( $column, $row ) ? $row[ $column ] : '';
				$cells[] = self::cell( $value );
			}
			$lines[] = implode( ',', $cells );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	private static function cell( $value ) {
		if ( null === $value ) { $value = ''; }
		$text = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
		if ( '' !== $text && in_array( $text[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) { $text = "'" . $text; }
		return '"' . str_replace( '"', '""', $text ) . '"';
	}

	/** @throws InvalidArgumentException */
	private static function validate_record( $raw, $company_id ) {
		if ( ! is_array( $raw ) ) { throw new InvalidArgumentException( 'record_not_array' ); }
		$id = $raw['id'] ?? null;
		if ( ! self::is_id( $id ) ) { throw new InvalidArgumentException( 'invalid_record_id' ); }
		if ( ( $raw['company_id'] ?? null ) !== $company_id ) { throw new InvalidArgumentException( 'cross_company_record' ); }
		$location = $raw['location_id'] ?? null;
		if ( ! self::is_id( $location ) ) { throw new InvalidArgumentException( 'invalid_location_id' ); }
		$source = $raw['lead_source'] ?? 'unknown';
		if ( ! in_array( $source, self::LEAD_SOURCES, true ) ) { throw new InvalidArgumentException( 'unknown_lead_source' ); }
		$enquiry = self::date( $raw['enquiry_date'] ?? null );
		if ( null === $enquiry ) { throw new InvalidArgumentException( 'invalid_enquiry_date' ); }
		$response = $raw['response_seconds'] ?? null;
		if ( null !== $response && ( ! is_int( $response ) || $response < 0 ) ) { throw new InvalidArgumentException( 'invalid_response_seconds' ); }
		$booked = $raw['booked'] ?? false;
		$cancelled = $raw['cancelled'] ?? false;
		if ( ! is_bool( $booked ) || ! is_bool( $cancelled ) || ( $cancelled && ! $booked ) ) { throw new InvalidArgumentException( 'invalid_booking_flags' ); }
		$minutes = $raw['duration_minutes'] ?? 0;
		if ( ! is_int( $minutes ) || $minutes < 0 || $minutes > 1440 ) { throw new InvalidArgumentException( 'invalid_duration' ); }
		$transactions = $raw['transactions'] ?? array();
		if ( ! is_array( $transactions ) ) { throw new InvalidArgumentException( 'invalid_transactions' ); }
		$clean = array();
		foreach ( $transactions as $t ) {
			if ( ! is_array( $t ) || ! self::is_id( $t['id'] ?? null ) ) { throw new InvalidArgumentException( 'invalid_transaction' ); }
			if ( ! in_array( $t['type'] ?? null, array( 'payment', 'refund' ), true ) ) { throw new InvalidArgumentException( 'invalid_transaction_type' ); }
			if ( ! in_array( $t['status'] ?? null, array( 'settled', 'pending', 'failed' ), true ) ) { throw new InvalidArgumentException( 'invalid_transaction_status' ); }
			if ( ! is_int( $t['amount_minor'] ?? null ) || $t['amount_minor'] < 0 ) { throw new InvalidArgumentException( 'invalid_transaction_amount' ); }
			$date = self::date( $t['date'] ?? null );
			if ( null === $date ) { throw new InvalidArgumentException( 'invalid_transaction_date' ); }
			$clean[] = array( 'id' => $t['id'], 'type' => $t['type'], 'status' => $t['status'], 'amount_minor' => $t['amount_minor'], 'date' => $date );
		}
		return array(
			'id' => $id, 'location_id' => $location, 'lead_source' => $source, 'enquiry_date' => $enquiry,
			'response_seconds' => $response, 'booked' => $booked, 'cancelled' => $cancelled,
			'duration_minutes' => $minutes, 'transactions' => $clean,
		);
	}

	private static function in_settled_range( $transaction, $from, $to ) {
		return 'settled' === $transaction['status'] && $transaction['date'] >= $from && $transaction['date'] <= $to;
	}

	private static function signed_amount( $transaction ) {
		return 'refund' === $transaction['type'] ? -$transaction['amount_minor'] : $transaction['amount_minor'];
	}

	private static function ratio( $numerator, $denominator ) {
		return array( 'numerator' => $numerator, 'denominator' => $denominator, 'rate' => $denominator > 0 ? $numerator / $denominator : null );
	}

	private static function median( $values ) {
		if ( empty( $values ) ) { return null; }
		sort( $values );
		$n = count( $values );
		$mid = intdiv( $n, 2 );
		return $n % 2 ? $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}

	private static function filter_list( $value ) {
		if ( null === $value ) { return null; }
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( 'invalid_filter_list' ); }
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === $item ) { throw new InvalidArgumentException( 'invalid_filter_value' ); }
		}
		return array_values( array_unique( $value ) );
	}

	private static function date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $p ) || ! checkdate( (int) $p[2], (int) $p[3], (int) $p[1] ) ) {
			return null;
		}
		return $value;
	}

	private static function is_id( $value ) {
		return is_string( $value ) && (bool) preg_match( TZFD_Delegated_Roles::ID_PATTERN, $value );
	}
}
