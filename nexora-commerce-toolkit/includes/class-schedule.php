<?php
/**
 * Pickup and rider time slots, shared by the WooCommerce shipping methods and the built-in store.
 *
 * @package NexoraToolkit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Time slot helpers.
 */
class Nexora_Toolkit_Schedule {

	/**
	 * Parse "08:00-10:00" into start/end minutes.
	 *
	 * @param string $line Raw line.
	 * @return array|null
	 */
	public static function parse_slot( $line ) {
		if ( ! preg_match( '/^\s*(\d{1,2}):(\d{2})\s*[-–]\s*(\d{1,2}):(\d{2})\s*$/u', (string) $line, $m ) ) {
			return null;
		}
		$start = (int) $m[1] * 60 + (int) $m[2];
		$end   = (int) $m[3] * 60 + (int) $m[4];
		if ( $start >= $end || $end > 24 * 60 || (int) $m[2] > 59 || (int) $m[4] > 59 ) {
			return null;
		}
		return array(
			'key'   => sprintf( '%02d:%02d-%02d:%02d', $m[1], $m[2], $m[3], $m[4] ),
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Human label for a slot key such as "08:00-10:00".
	 *
	 * @param string $key Slot key.
	 * @return string
	 */
	public static function slot_label( $key ) {
		$slot = self::parse_slot( $key );
		if ( ! $slot ) {
			return (string) $key;
		}
		$format = get_option( 'time_format', 'g:i a' );
		$base   = new DateTimeImmutable( '2000-01-01 00:00', wp_timezone() );
		return sprintf(
			/* translators: 1: start time, 2: end time */
			__( '%1$s – %2$s', 'nexora-commerce-toolkit' ),
			wp_date( $format, $base->modify( '+' . $slot['start'] . ' minutes' )->getTimestamp() ),
			wp_date( $format, $base->modify( '+' . $slot['end'] . ' minutes' )->getTimestamp() )
		);
	}

	/**
	 * "Today · Tue, Oct 27" style date label.
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	public static function date_label( $ymd ) {
		$day = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $ymd, wp_timezone() );
		if ( ! $day ) {
			return (string) $ymd;
		}
		$label = wp_date( _x( 'D, M j', 'pickup date format', 'nexora-commerce-toolkit' ), $day->getTimestamp() );
		$today = current_datetime()->setTime( 0, 0 );
		if ( $day == $today ) { // phpcs:ignore Universal.Operators.StrictComparisons -- compares DateTime values.
			/* translators: %s: date */
			return sprintf( __( 'Today · %s', 'nexora-commerce-toolkit' ), $label );
		}
		if ( $day == $today->modify( '+1 day' ) ) { // phpcs:ignore Universal.Operators.StrictComparisons
			/* translators: %s: date */
			return sprintf( __( 'Tomorrow · %s', 'nexora-commerce-toolkit' ), $label );
		}
		return $label;
	}

	/**
	 * Bookable dates and slots, respecting preparation time and closed days.
	 *
	 * @param string $slots_text One slot per line.
	 * @param int    $lead_hours Preparation time.
	 * @param int    $days_ahead Days customers can book ahead.
	 * @param array  $closed     Closed weekdays ("0" = Sunday).
	 * @return array<string,array{label:string,slots:array<string,string>}> Keyed by Y-m-d.
	 */
	public static function build( $slots_text, $lead_hours, $days_ahead, $closed ) {
		$slots = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $slots_text ) as $line ) {
			$slot = self::parse_slot( $line );
			if ( $slot ) {
				$slots[ $slot['key'] ] = $slot;
			}
		}
		uasort(
			$slots,
			static function ( $a, $b ) {
				return $a['start'] - $b['start'];
			}
		);

		$now      = current_datetime();
		$earliest = $now->modify( '+' . max( 0, (int) $lead_hours ) . ' hours' );
		$closed   = array_map( 'strval', (array) $closed );
		$ahead    = min( 30, max( 0, (int) $days_ahead ) );
		$today    = $now->setTime( 0, 0 );
		$out      = array();

		// Look a little past the window so a long preparation time still leaves bookable days.
		for ( $d = 0; $d <= $ahead + 7 && count( $out ) <= $ahead; $d++ ) {
			$day = $today->modify( '+' . $d . ' days' );
			if ( in_array( $day->format( 'w' ), $closed, true ) ) {
				continue;
			}
			$open = array();
			foreach ( $slots as $key => $slot ) {
				if ( $day->modify( '+' . $slot['start'] . ' minutes' ) >= $earliest ) {
					$open[ $key ] = self::slot_label( $key );
				}
			}
			if ( $open ) {
				$out[ $day->format( 'Y-m-d' ) ] = array(
					'label' => self::date_label( $day->format( 'Y-m-d' ) ),
					'slots' => $open,
				);
			}
		}
		return $out;
	}

	/**
	 * "Wed, Oct 28 · 8:00 am – 10:00 am".
	 *
	 * @param string $date Date.
	 * @param string $slot Slot key.
	 * @return string
	 */
	public static function when( $date, $slot ) {
		if ( ! $date ) {
			return '';
		}
		return $slot ? self::date_label( $date ) . ' · ' . self::slot_label( $slot ) : self::date_label( $date );
	}
}
