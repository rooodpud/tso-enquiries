<?php
/**
 * The figures behind Enquiries > Reports (1.2.0). No screen code here: Reports draws the page and
 * the CSV files from what these functions return, and _ts-tools/enquiry-reports/test-report-data.php
 * checks them on fixed sample records.
 *
 * Every date is a day in the site's timezone (Settings > General), worked out from the GMT time each
 * enquiry was saved, so enquiries saved before the timezone was set still land on the right day.
 * Nothing stored is ever changed: answers are grouped only when they are counted.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Report_Data {

	const GROUPS_OPTION = 'ts_enquiries_report_groups';
	const NOT_ASKED     = '__not_asked';
	const NOT_ANSWERED  = '__not_answered';
	const OWN           = '__own';
	const BOOKING_TYPE  = 'Vehicle hire';

	/** Colours for the sources and types, in this fixed order (checked for colour-blind readers); the 8th is "Other". */
	const COLOURS = array( '#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6' );
	const GREY    = '#8c8f94';

	/* ------------------------------------------------------------------ Records */

	/**
	 * Every saved enquiry (not in the Trash) as a small array: id, day (Y-m-d, site time), type,
	 * heard (null when the form did not ask, '' when left blank), channel (enquiry | booking), status.
	 *
	 * @return array[]
	 */
	public static function records() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_date_gmt,
					MAX( CASE WHEN m.meta_key = %s THEN m.meta_value END ) AS type,
					MAX( CASE WHEN m.meta_key = %s THEN m.meta_value END ) AS data,
					MAX( CASE WHEN m.meta_key = %s THEN m.meta_value END ) AS status,
					MAX( CASE WHEN m.meta_key IN ( %s, %s ) THEN 1 ELSE 0 END ) AS booking
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ( %s, %s, %s, %s, %s )
				WHERE p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				GROUP BY p.ID
				ORDER BY p.post_date_gmt ASC",
				Store::META . 'type',
				Store::META . 'data',
				Store::META . 'status',
				Store::META . 'hire_ref',
				'_tsh_start',
				Store::META . 'type',
				Store::META . 'data',
				Store::META . 'status',
				Store::META . 'hire_ref',
				'_tsh_start',
				Store::POST_TYPE
			)
		);
		$tz  = wp_timezone();
		$utc = new \DateTimeZone( 'UTC' );
		$out = array();
		foreach ( $rows as $r ) {
			$data  = maybe_unserialize( (string) $r->data );
			$heard = is_array( $data ) && array_key_exists( 'heard_about', $data ) ? trim( (string) $data['heard_about'] ) : null;
			$type  = trim( (string) $r->type );
			$out[] = array(
				'id'      => (int) $r->ID,
				'day'     => ( new \DateTimeImmutable( $r->post_date_gmt, $utc ) )->setTimezone( $tz )->format( 'Y-m-d' ),
				'type'    => $type,
				'heard'   => $heard,
				'channel' => ( (int) $r->booking || self::BOOKING_TYPE === $type ) ? 'booking' : 'enquiry',
				'status'  => (string) $r->status,
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------ Periods */

	public static function ranges() {
		return array(
			'today'      => __( 'Today', 'travelstore-enquiries' ),
			'last7'      => __( 'Last 7 days', 'travelstore-enquiries' ),
			'this_week'  => __( 'This week', 'travelstore-enquiries' ),
			'week'       => __( 'A specific week', 'travelstore-enquiries' ),
			'this_month' => __( 'This month', 'travelstore-enquiries' ),
			'last_month' => __( 'Last month', 'travelstore-enquiries' ),
			'month'      => __( 'A specific month', 'travelstore-enquiries' ),
			'this_year'  => __( 'This year', 'travelstore-enquiries' ),
			'all'        => __( 'All time', 'travelstore-enquiries' ),
			'custom'     => __( 'Custom range', 'travelstore-enquiries' ),
		);
	}

	/**
	 * The chosen period and the one it is compared with.
	 *
	 * @param array  $q          range, from, to (Y-m-d), week (YYYY-Www), month (YYYY-MM).
	 * @param string $today      Y-m-d in site time.
	 * @param string $first_day  Y-m-d of the first enquiry ('' when there is none).
	 * @param int    $week_start 0 = Sunday … 6 = Saturday (Settings > General).
	 * @return array range, start, end, prev_start, prev_end (both '' for All time), prev_name.
	 */
	public static function period( array $q, $today, $first_day, $week_start ) {
		$range = isset( $q['range'] ) && array_key_exists( $q['range'], self::ranges() ) ? $q['range'] : 'this_month';
		$t     = self::day( $today );
		$prev  = array( '', '' );
		$name  = '';
		switch ( $range ) {
			case 'today':
				$s    = $t;
				$e    = $t;
				$prev = self::shift( $s, $e, 1 );
				$name = __( 'yesterday', 'travelstore-enquiries' );
				break;
			case 'last7':
				$s    = $t->modify( '-6 days' );
				$e    = $t;
				$prev = self::shift( $s, $e, 7 );
				$name = __( 'the 7 days before', 'travelstore-enquiries' );
				break;
			case 'this_week':
			case 'week':
				$pick = 'week' === $range ? self::week_from( $q['week'] ?? '' ) : null;
				$s    = self::week_start( $pick ? $pick : $t, $week_start );
				$e    = max( $s, min( $s->modify( '+6 days' ), $t ) );
				$full = $e == $s->modify( '+6 days' ); // phpcs:ignore Universal.Operators.StrictComparisons -- DateTime values.
				$prev = self::shift( $s, $e, 7 );
				$name = $full ? __( 'the week before', 'travelstore-enquiries' ) : __( 'the same days last week', 'travelstore-enquiries' );
				break;
			case 'this_month':
			case 'last_month':
			case 'month':
				if ( 'this_month' === $range ) {
					$s = $t->modify( 'first day of this month' );
				} elseif ( 'last_month' === $range ) {
					$s = $t->modify( 'first day of last month' );
				} else {
					$s = self::month_from( $q['month'] ?? '' );
					$s = $s ? $s : $t->modify( 'first day of this month' );
				}
				$last = $s->modify( 'last day of this month' );
				$e    = max( $s, min( $last, $t ) );
				$ps   = $s->modify( 'first day of last month' );
				$len  = (int) $s->diff( $e )->days;
				$prev = array( $ps, min( $ps->modify( '+' . $len . ' days' ), $ps->modify( 'last day of this month' ) ) );
				$name = $e == $last ? __( 'the month before', 'travelstore-enquiries' ) : __( 'the same days last month', 'travelstore-enquiries' ); // phpcs:ignore Universal.Operators.StrictComparisons
				break;
			case 'this_year':
				$s    = self::day( $t->format( 'Y' ) . '-01-01' );
				$e    = $t;
				$ps   = $s->modify( '-1 year' );
				$pe   = self::day( ( (int) $t->format( 'Y' ) - 1 ) . '-' . $t->format( 'm' ) . '-01' );
				$pe   = min( $pe->modify( '+' . ( (int) $t->format( 'd' ) - 1 ) . ' days' ), $pe->modify( 'last day of this month' ) );
				$prev = array( $ps, $pe );
				$name = __( 'the same days last year', 'travelstore-enquiries' );
				break;
			case 'all':
				$s = '' !== $first_day ? min( self::day( $first_day ), $t ) : $t;
				$e = $t;
				break;
			default: // custom
				$a = self::valid( $q['from'] ?? '' ) ? self::day( $q['from'] ) : $t->modify( 'first day of this month' );
				$b = self::valid( $q['to'] ?? '' ) ? self::day( $q['to'] ) : $t;
				$s = min( $a, $b );
				$e = max( $a, $b );
				$n = (int) $s->diff( $e )->days + 1;
				$prev = self::shift( $s, $e, $n );
				/* translators: %d: number of days */
				$name = sprintf( _n( 'the day before', 'the %d days before', $n, 'travelstore-enquiries' ), $n );
		}

		return array(
			'range'      => $range,
			'start'      => $s->format( 'Y-m-d' ),
			'end'        => $e->format( 'Y-m-d' ),
			'prev_start' => $prev[0] ? $prev[0]->format( 'Y-m-d' ) : '',
			'prev_end'   => $prev[1] ? $prev[1]->format( 'Y-m-d' ) : '',
			'prev_name'  => $name,
		);
	}

	private static function day( $ymd ) {
		return new \DateTimeImmutable( $ymd . ' 00:00:00', new \DateTimeZone( 'UTC' ) );
	}

	private static function valid( $ymd ) {
		return is_string( $ymd ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) && checkdate( (int) substr( $ymd, 5, 2 ), (int) substr( $ymd, 8, 2 ), (int) substr( $ymd, 0, 4 ) );
	}

	private static function shift( $s, $e, $days ) {
		return array( $s->modify( '-' . $days . ' days' ), $e->modify( '-' . $days . ' days' ) );
	}

	private static function week_from( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-W(\d{2})$/', $value, $m ) || (int) $m[2] < 1 || (int) $m[2] > 53 ) {
			return null;
		}

		return self::day( '2000-01-01' )->setISODate( (int) $m[1], (int) $m[2], 1 );
	}

	private static function month_from( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})$/', $value, $m ) || (int) $m[2] < 1 || (int) $m[2] > 12 ) {
			return null;
		}

		return self::day( $value . '-01' );
	}

	/** The first day of the week holding $d. */
	public static function week_start( $d, $week_start ) {
		$d    = is_string( $d ) ? self::day( $d ) : $d;
		$back = ( (int) $d->format( 'w' ) - (int) $week_start + 7 ) % 7;

		return $d->modify( '-' . $back . ' days' );
	}

	/* ------------------------------------------------------------------ Buckets */

	/** Day, week or month to start with, from the length of the period. */
	public static function default_by( $start, $end ) {
		$n = (int) self::day( $start )->diff( self::day( $end ) )->days + 1;

		return $n <= 31 ? 'day' : ( $n <= 182 ? 'week' : 'month' );
	}

	public static function bucket_key( $ymd, $by, $week_start ) {
		if ( 'month' === $by ) {
			return substr( $ymd, 0, 7 );
		}

		return 'week' === $by ? self::week_start( $ymd, $week_start )->format( 'Y-m-d' ) : $ymd;
	}

	/**
	 * Every day, week or month from $start to $end, so empty ones still show as 0.
	 *
	 * @return array key => label
	 */
	public static function buckets( $start, $end, $by, $week_start ) {
		$out  = array();
		$d    = self::day( self::bucket_key( $start, $by, $week_start ) . ( 'month' === $by ? '-01' : '' ) );
		$last = self::day( $end );
		$step = 'month' === $by ? '+1 month' : ( 'week' === $by ? '+7 days' : '+1 day' );
		$fmt  = 'month' === $by ? 'M Y' : 'j M';
		while ( $d <= $last ) {
			$key         = 'month' === $by ? $d->format( 'Y-m' ) : $d->format( 'Y-m-d' );
			$label       = $d->format( $fmt );
			$out[ $key ] = 'week' === $by ? sprintf( /* translators: %s: first day of the week */ __( 'Week of %s', 'travelstore-enquiries' ), $label ) : $label;
			$d           = $d->modify( $step );
		}

		return $out;
	}

	/* ------------------------------------------------------------------ Filters */

	/**
	 * @param string $channel all | enquiry | booking.
	 * @param string $type    '' or a type group name.
	 */
	public static function filter( array $records, $start, $end, $channel = 'all', $type = '', array $groups = array(), array $names = array() ) {
		return array_values(
			array_filter(
				$records,
				static function ( $r ) use ( $start, $end, $channel, $type, $groups, $names ) {
					return $r['day'] >= $start && $r['day'] <= $end
						&& ( 'all' === $channel || $r['channel'] === $channel )
						&& ( '' === $type || self::group( 'type', $r['type'], $groups, $names ) === $type );
				}
			)
		);
	}

	public static function first_day( array $records ) {
		$days = array_column( $records, 'day' );

		return $days ? min( $days ) : '';
	}

	/* ------------------------------------------------------------------ Grouping */

	/** "  Google-Search! " and "google search" give the same key. */
	public static function norm( $text ) {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		return trim( (string) $text );
	}

	/** Built-in groups for "How did you hear about us?": a word in the answer → the group. */
	public static function default_rules() {
		return array(
			__( 'Search engine', 'travelstore-enquiries' )    => array( 'search', 'google', 'bing', 'yahoo', 'duckduckgo', 'ecosia', 'internet', 'online', 'web' ),
			__( 'Social media', 'travelstore-enquiries' )     => array( 'social', 'instagram', 'insta', 'facebook', 'fb', 'tiktok', 'youtube', 'twitter', 'linkedin', 'pinterest', 'snapchat' ),
			__( 'Friend or family', 'travelstore-enquiries' ) => array( 'friend', 'friends', 'family', 'word of mouth', 'recommendation', 'recommended', 'colleague' ),
			__( 'Travel agent', 'travelstore-enquiries' )     => array( 'agent', 'agency', 'tour operator' ),
			__( 'Returning guest', 'travelstore-enquiries' )  => array( 'returning', 'repeat', 'again', 'before', 'previous' ),
		);
	}

	/**
	 * The group an answer is counted in. Order: the owner's own choice on the Reports page, then the
	 * built-in words (sources only), then the answer itself (spelled as it was most often sent).
	 *
	 * @param string      $kind   heard | type.
	 * @param string|null $raw    The answer as stored.
	 * @param array       $groups Saved choices: kind => key => group name.
	 * @param array       $names  kind => key => the spelling to show (see names()).
	 */
	public static function group( $kind, $raw, array $groups = array(), array $names = array() ) {
		if ( 'heard' === $kind && null === $raw ) {
			return self::NOT_ASKED;
		}
		$key = self::norm( (string) $raw );
		if ( '' === $key ) {
			return 'heard' === $kind ? self::NOT_ANSWERED : __( '(no type)', 'travelstore-enquiries' );
		}
		if ( isset( $groups[ $kind ][ $key ] ) && '' !== $groups[ $kind ][ $key ] ) {
			// OWN: the owner chose "its own name" (keep it apart even when a built-in word matches).
			return self::OWN === $groups[ $kind ][ $key ] ? ( $names[ $kind ][ $key ] ?? $key ) : $groups[ $kind ][ $key ];
		}

		return self::auto_group( $kind, $key, $names );
	}

	/** What the answer counts as when the owner has not chosen. */
	public static function auto_group( $kind, $key, array $names = array() ) {
		if ( 'heard' === $kind ) {
			$padded = ' ' . $key . ' ';
			foreach ( self::default_rules() as $label => $words ) {
				if ( self::norm( $label ) === $key ) {
					return $label;
				}
				foreach ( $words as $word ) {
					if ( false !== strpos( $padded, ' ' . $word . ' ' ) ) {
						return $label;
					}
				}
			}
		}

		return isset( $names[ $kind ][ $key ] ) ? $names[ $kind ][ $key ] : $key;
	}

	/**
	 * For each kind, every distinct answer (by key): the spelling sent most often and how many times.
	 *
	 * @return array names: kind => key => spelling; seen: kind => key => array( spelling, count ).
	 */
	public static function names( array $records ) {
		$tally = array( 'heard' => array(), 'type' => array() );
		foreach ( $records as $r ) {
			foreach ( array( 'heard', 'type' ) as $kind ) {
				$raw = $r[ $kind ];
				if ( null === $raw || '' === self::norm( $raw ) ) {
					continue;
				}
				$key = self::norm( $raw );
				$tally[ $kind ][ $key ][ $raw ] = ( $tally[ $kind ][ $key ][ $raw ] ?? 0 ) + 1;
			}
		}
		$names = array( 'heard' => array(), 'type' => array() );
		$seen  = array( 'heard' => array(), 'type' => array() );
		foreach ( $tally as $kind => $keys ) {
			foreach ( $keys as $key => $spellings ) {
				arsort( $spellings );
				$names[ $kind ][ $key ] = (string) array_key_first( $spellings );
				$seen[ $kind ][ $key ]  = array( $names[ $kind ][ $key ], array_sum( $spellings ), array_keys( $spellings ) );
			}
			uasort(
				$seen[ $kind ],
				static function ( $a, $b ) {
					return $b[1] <=> $a[1];
				}
			);
		}

		return array(
			'names' => $names,
			'seen'  => $seen,
		);
	}

	public static function saved_groups() {
		$saved = get_option( self::GROUPS_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'heard' => isset( $saved['heard'] ) && is_array( $saved['heard'] ) ? $saved['heard'] : array(),
			'type'  => isset( $saved['type'] ) && is_array( $saved['type'] ) ? $saved['type'] : array(),
		);
	}

	/**
	 * Groups in a fixed order (most enquiries over all time first), so a group keeps its colour
	 * whatever period is chosen. The special groups (not asked, not answered) go last.
	 *
	 * @return string[]
	 */
	public static function order( $kind, array $all_records, array $groups, array $names ) {
		$count = array();
		foreach ( $all_records as $r ) {
			$g           = self::group( $kind, $r[ $kind ], $groups, $names );
			$count[ $g ] = ( $count[ $g ] ?? 0 ) + 1;
		}
		uksort(
			$count,
			static function ( $a, $b ) use ( $count ) {
				$sa = 0 === strpos( $a, '__' );
				$sb = 0 === strpos( $b, '__' );
				if ( $sa !== $sb ) {
					return $sa ? 1 : -1;
				}

				return $count[ $b ] <=> $count[ $a ] ?: strcmp( $a, $b );
			}
		);

		return array_keys( $count );
	}

	/** Colour of each group: the first seven real groups get the fixed colours, the rest grey. */
	public static function colours( array $order ) {
		$out = array();
		$i   = 0;
		foreach ( $order as $g ) {
			$out[ $g ] = ( 0 !== strpos( $g, '__' ) && $i < count( self::COLOURS ) ) ? self::COLOURS[ $i++ ] : self::GREY;
		}

		return $out;
	}

	/* ------------------------------------------------------------------ Figures */

	/** @return array day => count (only days with enquiries). */
	public static function per_day( array $records ) {
		$out = array();
		foreach ( $records as $r ) {
			$out[ $r['day'] ] = ( $out[ $r['day'] ] ?? 0 ) + 1;
		}

		return $out;
	}

	/**
	 * Counts per bucket, split by $split (a function record → series name) or one series "all".
	 *
	 * @return array series => bucket key => count (every bucket present).
	 */
	public static function series( array $records, array $buckets, $by, $week_start, $split = null ) {
		$out = array();
		foreach ( $records as $r ) {
			$name = $split ? $split( $r ) : 'all';
			if ( ! isset( $out[ $name ] ) ) {
				$out[ $name ] = array_fill_keys( array_keys( $buckets ), 0 );
			}
			$key = self::bucket_key( $r['day'], $by, $week_start );
			if ( isset( $out[ $name ][ $key ] ) ) {
				++$out[ $name ][ $key ];
			}
		}

		return $out;
	}

	/**
	 * Top day, best week, best month (with ties), from the records already filtered to the period.
	 *
	 * @return array day, week, month: each array( key, count, ties ) or null.
	 */
	public static function bests( array $records, $week_start ) {
		$out = array();
		foreach ( array( 'day', 'week', 'month' ) as $by ) {
			$c = array();
			foreach ( $records as $r ) {
				$k       = self::bucket_key( $r['day'], $by, $week_start );
				$c[ $k ] = ( $c[ $k ] ?? 0 ) + 1;
			}
			if ( ! $c ) {
				$out[ $by ] = null;
				continue;
			}
			$max  = max( $c );
			$tops = array_keys( $c, $max, true );
			sort( $tops );
			$out[ $by ] = array( (string) $tops[0], $max, count( $tops ) - 1 );
		}

		return $out;
	}

	/**
	 * Enquiries per day of the week, with how many such days the period has (for the average).
	 *
	 * @return array ISO weekday (1 = Monday) => array( count, days ), in week order starting at $week_start.
	 */
	public static function weekdays( array $records, $start, $end, $week_start ) {
		$out   = array();
		$first = 0 === (int) $week_start ? 7 : (int) $week_start;
		for ( $i = 0; $i < 7; $i++ ) {
			$out[ ( ( $first - 1 + $i ) % 7 ) + 1 ] = array( 0, 0 );
		}
		for ( $d = self::day( $start ), $last = self::day( $end ); $d <= $last; $d = $d->modify( '+1 day' ) ) {
			++$out[ (int) $d->format( 'N' ) ][1];
		}
		foreach ( $records as $r ) {
			++$out[ (int) self::day( $r['day'] )->format( 'N' ) ][0];
		}

		return $out;
	}

	/** @return array group => count, largest first. */
	public static function counts( array $records, $kind, array $groups, array $names ) {
		$out = array();
		foreach ( $records as $r ) {
			$g         = self::group( $kind, $r[ $kind ], $groups, $names );
			$out[ $g ] = ( $out[ $g ] ?? 0 ) + 1;
		}
		arsort( $out );

		return $out;
	}

	/** @return array source group => type group => count. */
	public static function cross( array $records, array $groups, array $names ) {
		$out = array();
		foreach ( $records as $r ) {
			$h = self::group( 'heard', $r['heard'], $groups, $names );
			$t = self::group( 'type', $r['type'], $groups, $names );
			$out[ $h ][ $t ] = ( $out[ $h ][ $t ] ?? 0 ) + 1;
		}

		return $out;
	}

	/**
	 * Month by month counts per type for the months of the period, plus the month before the first
	 * (so the first month also has a change).
	 *
	 * @param array $records All records with the channel filter applied, any date.
	 * @return array months: Y-m => label; counts: Y-m => type => count (includes the month before).
	 */
	public static function months( array $records, $start, $end, array $groups, array $names ) {
		$months = self::buckets( $start, $end, 'month', 1 );
		$before = self::day( substr( $start, 0, 7 ) . '-01' )->modify( '-1 month' )->format( 'Y-m' );
		$counts = array_fill_keys( array_merge( array( $before ), array_keys( $months ) ), array() );
		foreach ( $records as $r ) {
			$m = substr( $r['day'], 0, 7 );
			if ( isset( $counts[ $m ] ) && ( $m === $before || ( $r['day'] >= $start && $r['day'] <= $end ) ) ) {
				$t                  = self::group( 'type', $r['type'], $groups, $names );
				$counts[ $m ][ $t ] = ( $counts[ $m ][ $t ] ?? 0 ) + 1;
			}
		}

		return array(
			'months' => $months,
			'before' => $before,
			'counts' => $counts,
		);
	}

	/**
	 * Change from $before to $now as a short text: "+18%", "-5%", "no change", "new" (from 0).
	 * Null when there is nothing to compare with.
	 */
	public static function change( $now, $before ) {
		if ( null === $before ) {
			return null;
		}
		$now    = (float) $now;
		$before = (float) $before;
		if ( 0.0 === $before ) {
			return 0.0 === $now ? __( 'no change', 'travelstore-enquiries' ) : __( 'new', 'travelstore-enquiries' );
		}
		$pct = (int) round( ( $now - $before ) / $before * 100 );

		return 0 === $pct ? __( 'no change', 'travelstore-enquiries' ) : ( $pct > 0 ? '+' : '' ) . $pct . '%';
	}

	public static function share( $part, $whole ) {
		return $whole ? round( $part / $whole * 100, 1 ) : 0.0;
	}
}
