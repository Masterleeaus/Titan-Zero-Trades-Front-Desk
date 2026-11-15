<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Vertical-neutral scheduling invariants for Titan Zero Trades: Front Desk.
 *
 * These helpers are deliberately independent of WordPress state so the
 * capacity/overlap contract can be regression-tested without bootstrapping WP.
 */
final class TZFD_Scheduling_Rules {
    public static function normalize_quantity( $tz_value, $capacity = null ) {
        $quantity = intval( $tz_value );
        if ( $quantity < 1 ) {
            $quantity = 1;
        }
        if ( $capacity !== null ) {
            $capacity = max( 1, intval( $capacity ) );
            $quantity = min( $quantity, $capacity );
        }
        return $quantity;
    }

    public static function is_occupied_status( $status, $locked = false ) {
        if ( $locked ) {
            return true;
        }
        return in_array( (string) $status, array( '', 'Pending', 'Attended' ), true );
    }

    public static function parse_slot( $slot ) {
        if ( ! is_string( $slot ) ) {
            return false;
        }
        $parts = array_map( 'trim', explode( '/', $slot, 2 ) );
        if ( count( $parts ) !== 2 ) {
            return false;
        }
        $start = self::parse_time( $parts[0] );
        $end   = self::parse_time( $parts[1] );
        if ( $start === false || $end === false ) {
            return false;
        }
        if ( $end <= $start ) {
            $end += 1440;
        }
        return array( 'start' => $start, 'end' => $end );
    }

    public static function with_service_configuration( $appointment, $tz_form_data ) {
        $appointment = is_array( $appointment ) ? $appointment : array();
        $appointment['capacity'] = isset( $appointment['capacity'] ) ? max( 1, intval( $appointment['capacity'] ) ) : 1;
        $appointment['buffer_before'] = isset( $appointment['buffer_before'] ) ? max( 0, intval( $appointment['buffer_before'] ) ) : 0;
        $appointment['buffer_after'] = isset( $appointment['buffer_after'] ) ? max( 0, intval( $appointment['buffer_after'] ) ) : 0;
        $appointment['avoid_overlapping'] = ! empty( $appointment['avoid_overlapping'] );

        if ( ! is_array( $tz_form_data ) || ! isset( $tz_form_data[0] ) ) {
            return $appointment;
        }

        foreach ( $tz_form_data[0] as $field ) {
            if ( ! isset( $field->name ) || $field->name !== ( $appointment['field'] ?? '' ) ) {
                continue;
            }

            $appointment['avoid_overlapping'] = ! empty( $field->avoidOverlaping );
            $index = self::service_index( $appointment['serviceindex'] ?? null );

            if ( $index === false && ! empty( $appointment['service'] ) && isset( $field->services ) && is_array( $field->services ) ) {
                $matches = array();
                foreach ( $field->services as $candidate_index => $service ) {
                    if ( isset( $service->name ) && (string) $service->name === (string) $appointment['service'] ) {
                        $matches[] = intval( $candidate_index );
                    }
                }
                if ( count( $matches ) === 1 ) {
                    $index = $matches[0];
                    $appointment['serviceindex'] = $index;
                }
            }

            if ( $index !== false && isset( $field->services[$index] ) ) {
                $service = $field->services[$index];
                $appointment['capacity'] = max( 1, intval( $service->capacity ?? 1 ) );
                $appointment['buffer_before'] = max( 0, intval( $service->pb ?? 0 ) );
                $appointment['buffer_after'] = max( 0, intval( $service->pa ?? 0 ) );
            }
            break;
        }
        return $appointment;
    }

    public static function same_service_identity( $left, $right ) {
        if ( ! is_array( $left ) || ! is_array( $right ) ||
             (string) ( $left['field'] ?? '' ) !== (string) ( $right['field'] ?? '' ) ) {
            return false;
        }

        $left_index = self::service_index( $left['serviceindex'] ?? null );
        $right_index = self::service_index( $right['serviceindex'] ?? null );
        if ( $left_index !== false && $right_index !== false ) {
            return $left_index === $right_index;
        }

        $left_service = trim( (string) ( $left['service'] ?? '' ) );
        $right_service = trim( (string) ( $right['service'] ?? '' ) );
        return $left_service !== '' && $left_service === $right_service;
    }

    public static function appointments_conflict( $left, $right ) {
        if ( ! is_array( $left ) || ! is_array( $right ) || empty( $left['date'] ) || empty( $right['date'] ) ) {
            return false;
        }
        if ( (string) ( $left['field'] ?? '' ) !== (string) ( $right['field'] ?? '' ) ) {
            return false;
        }

        $same_service = self::same_service_identity( $left, $right );
        if ( ! $same_service && empty( $left['avoid_overlapping'] ) && empty( $right['avoid_overlapping'] ) ) {
            return false;
        }

        $left_interval = self::appointment_interval( $left );
        $right_interval = self::appointment_interval( $right );
        if ( $left_interval === false || $right_interval === false ) {
            return $same_service &&
                   (string) $left['date'] === (string) $right['date'] &&
                   (string) ( $left['slot'] ?? '' ) === (string) ( $right['slot'] ?? '' );
        }

        return self::intervals_overlap( $left_interval, $right_interval );
    }

    public static function capacity_exceeded( $candidate, $stored, $requested ) {
        $candidate = is_array( $candidate ) ? $candidate : array();
        $capacity = max( 1, intval( $candidate['capacity'] ?? 1 ) );
        $candidate_quantity = self::normalize_quantity( $candidate['quant'] ?? 1 );
        $others = array_merge( is_array( $stored ) ? $stored : array(), is_array( $requested ) ? $requested : array() );
        $candidate_interval = self::appointment_interval( $candidate );

        if ( $candidate_interval === false ) {
            $occupied = $candidate_quantity;
            foreach ( $others as $appointment ) {
                if ( ! self::appointments_conflict( $candidate, $appointment ) ) {
                    continue;
                }
                if ( ! self::same_service_identity( $candidate, $appointment ) ) {
                    if ( ! empty( $candidate['avoid_overlapping'] ) || ! empty( $appointment['avoid_overlapping'] ) ) {
                        return true;
                    }
                    continue;
                }
                $occupied += self::normalize_quantity( $appointment['quant'] ?? 1 );
            }
            return $occupied > $capacity;
        }

        $tz_events = array(
            array( $candidate_interval['start'], $candidate_quantity ),
            array( $candidate_interval['end'], -$candidate_quantity ),
        );

        foreach ( $others as $appointment ) {
            if ( ! self::appointments_conflict( $candidate, $appointment ) ) {
                continue;
            }

            if ( ! self::same_service_identity( $candidate, $appointment ) ) {
                if ( ! empty( $candidate['avoid_overlapping'] ) || ! empty( $appointment['avoid_overlapping'] ) ) {
                    return true;
                }
                continue;
            }

            $interval = self::appointment_interval( $appointment );
            if ( $interval === false ) {
                continue;
            }

            $start = max( $candidate_interval['start'], $interval['start'] );
            $end = min( $candidate_interval['end'], $interval['end'] );
            if ( $start >= $end ) {
                continue;
            }

            $quantity = self::normalize_quantity( $appointment['quant'] ?? 1 );
            $tz_events[] = array( $start, $quantity );
            $tz_events[] = array( $end, -$quantity );
        }

        usort(
            $tz_events,
            static function( $left, $right ) {
                if ( $left[0] === $right[0] ) {
                    return $left[1] <=> $right[1];
                }
                return $left[0] <=> $right[0];
            }
        );

        $occupied = 0;
        foreach ( $tz_events as $event ) {
            $occupied += $event[1];
            if ( $occupied > $capacity ) {
                return true;
            }
        }
        return false;
    }

    public static function intervals_overlap( $left, $right, $buffer_before = 0, $buffer_after = 0 ) {
        if ( ! is_array( $left ) || ! is_array( $right ) ) {
            return false;
        }
        $before = max( 0, intval( $buffer_before ) );
        $after  = max( 0, intval( $buffer_after ) );
        $left_start = intval( $left['start'] ) - $before;
        $left_end   = intval( $left['end'] ) + $after;
        return $left_start < intval( $right['end'] ) && $left_end > intval( $right['start'] );
    }


    /**
     * Generate a bounded recurring series of local calendar dates.
     *
     * Supported cadences are intentionally small for Generic V1: daily,
     * weekly and monthly. The result always includes the start date.
     * Monthly recurrence clamps to the last valid day of shorter months.
     */
    public static function recurrence_dates( $start_date, $cadence, $interval = 1, $tz_count = 1 ) {
        $cadence = strtolower( trim( (string) $cadence ) );
        $interval = self::strict_positive_integer( $interval );
        $tz_count = self::strict_positive_integer( $tz_count, 366 );

        if ( ! in_array( $cadence, array( 'daily', 'weekly', 'monthly' ), true ) ||
             $interval === false || $tz_count === false ) {
            return false;
        }

        $start = self::parse_calendar_date( $start_date );
        if ( $start === false ) {
            return false;
        }

        $max_date = new DateTimeImmutable( '9999-12-31', new DateTimeZone( 'UTC' ) );
        $max_days = intval( $start->diff( $max_date )->format( '%a' ) );
        $max_months = ( 9999 - intval( $start->format( 'Y' ) ) ) * 12 + ( 12 - intval( $start->format( 'm' ) ) );

        $dates = array();
        for ( $index = 0; $index < $tz_count; $index++ ) {
            if ( $index === 0 ) {
                $next = $start;
            } elseif ( $cadence === 'daily' ) {
                if ( $interval > intdiv( $max_days, $index ) ) {
                    return false;
                }
                $days = $index * $interval;
                $next = $start->modify( '+' . $days . ' days' );
            } elseif ( $cadence === 'weekly' ) {
                $max_weeks = intdiv( $max_days, 7 );
                if ( $interval > intdiv( $max_weeks, $index ) ) {
                    return false;
                }
                $days = $index * $interval * 7;
                $next = $start->modify( '+' . $days . ' days' );
            } else {
                if ( $interval > intdiv( $max_months, $index ) ) {
                    return false;
                }
                $next = self::add_months_clamped( $start, $index * $interval );
            }

            if ( ! ( $next instanceof DateTimeImmutable ) ) {
                return false;
            }
            $formatted = $next->format( 'Y-m-d' );
            if ( self::parse_calendar_date( $formatted ) === false ) {
                return false;
            }
            $dates[] = $formatted;
        }
        return $dates;
    }

    private static function appointment_interval( $appointment ) {
        if ( ! is_array( $appointment ) || empty( $appointment['date'] ) ) {
            return false;
        }
        $slot = self::parse_slot( $appointment['slot'] ?? '' );
        $day = self::parse_calendar_date( $appointment['date'] );
        if ( $slot === false || $day === false ) {
            return false;
        }

        $epoch = new DateTimeImmutable( '1970-01-01', new DateTimeZone( 'UTC' ) );
        $tz_offset = intval( $epoch->diff( $day )->format( '%r%a' ) ) * 1440;
        return array(
            'start' => $slot['start'] + $tz_offset - max( 0, intval( $appointment['buffer_before'] ?? 0 ) ),
            'end' => $slot['end'] + $tz_offset + max( 0, intval( $appointment['buffer_after'] ?? 0 ) ),
        );
    }

    private static function service_index( $tz_value ) {
        if ( is_int( $tz_value ) ) {
            return $tz_value >= 0 ? $tz_value : false;
        }
        if ( ! is_string( $tz_value ) || ! preg_match( '/^\d+$/D', $tz_value ) ) {
            return false;
        }
        $validated = filter_var( $tz_value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
        return $validated === false ? false : $validated;
    }

    private static function strict_positive_integer( $tz_value, $maximum = null ) {
        if ( is_int( $tz_value ) ) {
            $validated = $tz_value;
        } elseif ( is_string( $tz_value ) && preg_match( '/^[1-9]\d*$/D', $tz_value ) ) {
            $validated = filter_var( $tz_value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
            if ( $validated === false ) {
                return false;
            }
        } else {
            return false;
        }

        if ( $validated < 1 || ( $maximum !== null && $validated > $maximum ) ) {
            return false;
        }
        return $validated;
    }

    private static function parse_calendar_date( $tz_value ) {
        if ( ! is_string( $tz_value ) || ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $tz_value ) ) {
            return false;
        }
        if ( intval( substr( $tz_value, 0, 4 ) ) < 1 ) {
            return false;
        }
        $tz_date = DateTimeImmutable::createFromFormat( '!Y-m-d', $tz_value, new DateTimeZone( 'UTC' ) );
        $errors = DateTimeImmutable::getLastErrors();
        if ( $tz_date === false || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) ||
             $tz_date->format( 'Y-m-d' ) !== $tz_value ) {
            return false;
        }
        return $tz_date;
    }

    private static function add_months_clamped( DateTimeImmutable $start, $months ) {
        $target = $start->modify( 'first day of this month' )->modify( '+' . intval( $months ) . ' months' );
        $day = min( intval( $start->format( 'd' ) ), intval( $target->format( 't' ) ) );
        return $target->setDate( intval( $target->format( 'Y' ) ), intval( $target->format( 'm' ) ), $day );
    }

    private static function parse_time( $tz_value ) {
        $tz_value = trim( (string) $tz_value );
        if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?:\s*([ap]m))?$/i', $tz_value, $m ) ) {
            return false;
        }
        $tz_hour = intval( $m[1] );
        $minute = intval( $m[2] );
        if ( $minute > 59 ) {
            return false;
        }
        if ( ! empty( $m[3] ) ) {
            if ( $tz_hour < 1 || $tz_hour > 12 ) {
                return false;
            }
            $ampm = strtolower( $m[3] );
            $tz_hour = $tz_hour % 12 + ( $ampm === 'pm' ? 12 : 0 );
        } elseif ( $tz_hour > 47 ) {
            return false;
        }
        return $tz_hour * 60 + $minute;
    }
}
