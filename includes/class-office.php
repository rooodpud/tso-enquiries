<?php
/**
 * Office days and hours (Enquiries > Settings, 1.3.0), for reply times and "overdue":
 * only time inside office hours counts, in the site's timezone. Defaults (owner, 2026-11-02): Sunday
 * to Thursday, 09:00 to 18:00, reply target one office day, "stuck" after 7 days without a status change.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Office {

	/** @return int[] Office days, 0 = Sunday … 6 = Saturday. */
	public static function days() {
		$days = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'office_days' ) ) ), 'strlen' );
		$days = array_values( array_unique( array_map( 'intval', $days ) ) );

		return $days ? $days : array( 0, 1, 2, 3, 4 );
	}

	public static function hours() {
		$start = max( 0, min( 23, (int) Settings::get( 'office_start' ) ) );
		$end   = max( $start + 1, min( 24, (int) Settings::get( 'office_end' ) ) );

		return array( $start, $end );
	}

	public static function target_minutes() {
		$target = (string) Settings::get( 'reply_target' );
		if ( 'day' === $target ) {
			list( $start, $end ) = self::hours();

			return ( $end - $start ) * 60;
		}

		return max( 1, (int) $target ) * 60;
	}

	public static function stuck_days() {
		return max( 1, (int) Settings::get( 'stuck_days' ) );
	}

	/**
	 * Office minutes between two times (timestamps). 0 when $to is not after $from.
	 * Days far apart are counted day by day, at most 400 days.
	 */
	public static function minutes_between( $from, $to, $days = null, $hours = null ) {
		if ( ! $from || ! $to || $to <= $from ) {
			return 0;
		}
		$days  = null === $days ? self::days() : $days;
		$hours = null === $hours ? self::hours() : $hours;
		$tz    = wp_timezone();
		$d     = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( $tz )->setTime( 0, 0 );
		$total = 0;
		for ( $i = 0; $i < 400 && $d->getTimestamp() < $to; $i++, $d = $d->modify( '+1 day' ) ) {
			if ( ! in_array( (int) $d->format( 'w' ), $days, true ) ) {
				continue;
			}
			$open  = $d->setTime( $hours[0], 0 )->getTimestamp();
			$close = 24 === $hours[1] ? $d->modify( '+1 day' )->getTimestamp() : $d->setTime( $hours[1], 0 )->getTimestamp();
			$a     = max( $open, $from );
			$b     = min( $close, $to );
			if ( $b > $a ) {
				$total += $b - $a;
			}
		}

		return (int) floor( $total / 60 );
	}

	/** "3 h 20 min", "2 days 4 h" (office time: one office day = the office hours). */
	public static function duration( $minutes ) {
		$minutes = (int) $minutes;
		list( $s, $e ) = self::hours();
		$day = ( $e - $s ) * 60;
		if ( $minutes < 1 ) {
			return __( 'under 1 min', 'travelstore-enquiries' );
		}
		if ( $minutes < 60 ) {
			/* translators: %d: minutes */
			return sprintf( __( '%d min', 'travelstore-enquiries' ), $minutes );
		}
		if ( $minutes < $day ) {
			$h = intdiv( $minutes, 60 );
			$m = $minutes % 60;
			/* translators: 1: hours, 2: minutes */
			return $m ? sprintf( __( '%1$d h %2$d min', 'travelstore-enquiries' ), $h, $m ) : sprintf( __( '%d h', 'travelstore-enquiries' ), $h );
		}
		$d = intdiv( $minutes, $day );
		$h = intdiv( $minutes % $day, 60 );
		/* translators: 1: office days, 2: hours */
		return $h ? sprintf( _n( '%1$d office day %2$d h', '%1$d office days %2$d h', $d, 'travelstore-enquiries' ), $d, $h ) : sprintf( _n( '%d office day', '%d office days', $d, 'travelstore-enquiries' ), $d );
	}

	public static function day_names() {
		return array(
			0 => __( 'Sunday', 'travelstore-enquiries' ),
			1 => __( 'Monday', 'travelstore-enquiries' ),
			2 => __( 'Tuesday', 'travelstore-enquiries' ),
			3 => __( 'Wednesday', 'travelstore-enquiries' ),
			4 => __( 'Thursday', 'travelstore-enquiries' ),
			5 => __( 'Friday', 'travelstore-enquiries' ),
			6 => __( 'Saturday', 'travelstore-enquiries' ),
		);
	}
}
