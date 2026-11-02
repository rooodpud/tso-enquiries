<?php
/**
 * The figures behind Enquiries > Insights (1.3.0), read from the facts table (Facts). No screen code.
 *
 * Rows look like Report_Data records (id, day, type, heard, channel, status) plus the facts columns,
 * so Report_Data's periods, grouping and counting are reused. Test / spam enquiries are always left
 * out (except when the list is asked to show them). Answers are grouped only when counted.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insights_Data {

	private static $all = null;

	/** Every enquiry in the facts table (not test or spam), oldest first, as arrays. */
	public static function all() {
		global $wpdb;
		if ( null !== self::$all ) {
			return self::$all;
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Facts::facts_table() . ' WHERE is_test = 0 ORDER BY received_gmt ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$r['id']    = (int) $r['enquiry_id'];
			$r['type']  = (string) $r['type_raw'];
			$r['heard'] = null === $r['heard_raw'] ? null : (string) $r['heard_raw'];
			$out[]      = $r;
		}
		self::$all = $out;

		return $out;
	}

	public static function reset() {
		self::$all = null;
	}

	public static function count_tests() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Facts::facts_table() . ' WHERE is_test = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count_samples() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Facts::facts_table() . ' WHERE is_sample = 1 AND is_test = 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** First day automatic visit sources were recorded ('' when none yet). */
	public static function visit_since() {
		$days = array();
		foreach ( self::all() as $r ) {
			if ( '' !== $r['visit_channel'] ) {
				$days[] = $r['day'];
			}
		}

		return $days ? min( $days ) : '';
	}

	/* ------------------------------------------------------------------ Context */

	/**
	 * Everything the pages, the CSV files and the weekly summary need for one filter.
	 *
	 * @param array $q range, from, to, week, month (see Report_Data::period), compare (prev|ly),
	 *                 channel (all|enquiry|booking), type (group name), by (day|week|month|'').
	 */
	public static function context( array $q, $today = null ) {
		$all    = self::all();
		$ws     = (int) get_option( 'start_of_week', 1 );
		$today  = $today ? $today : wp_date( 'Y-m-d' );
		$p      = Report_Data::period( $q, $today, Report_Data::first_day( $all ), $ws );
		$cmp    = isset( $q['compare'] ) && 'ly' === $q['compare'] && 'all' !== $p['range'] ? 'ly' : 'prev';
		$ly     = 'all' !== $p['range'] ? array( self::year_back( $p['start'] ), self::year_back( $p['end'] ) ) : null;
		if ( 'ly' === $cmp ) {
			$p['prev_start'] = $ly[0];
			$p['prev_end']   = $ly[1];
			$p['prev_name']  = __( 'the same dates last year', 'travelstore-enquiries' );
		}
		$n       = Report_Data::names( $all );
		$names   = $n['names'];
		$groups  = Report_Data::saved_groups();
		$types   = Report_Data::order( 'type', $all, $groups, $names );
		$heard   = Report_Data::order( 'heard', $all, $groups, $names );
		$type    = isset( $q['type'] ) && in_array( $q['type'], $types, true ) ? $q['type'] : '';
		$channel = isset( $q['channel'] ) && in_array( $q['channel'], array( 'enquiry', 'booking' ), true ) ? $q['channel'] : 'all';
		$base    = array( 'channel' => $channel, 'type' => $type );
		$scoped  = self::filter( $all, $base, $groups, $names );
		$cur     = self::filter( $scoped, array( 'from' => $p['start'], 'to' => $p['end'] ), $groups, $names );
		$prev    = '' !== $p['prev_start'] ? self::filter( $scoped, array( 'from' => $p['prev_start'], 'to' => $p['prev_end'] ), $groups, $names ) : null;
		$by      = isset( $q['by'] ) && in_array( $q['by'], array( 'day', 'week', 'month' ), true ) ? $q['by'] : Report_Data::default_by( $p['start'], $p['end'] );

		return array(
			'q'         => array_merge( $q, array( 'type' => $type, 'channel' => $channel, 'compare' => $cmp ) ),
			'p'         => $p,
			'by'        => $by,
			'ws'        => $ws,
			'all'       => $all,
			'scoped'    => $scoped,
			'cur'       => $cur,
			'prev'      => $prev,
			'ly'        => 'prev' === $cmp && $ly ? self::filter( $scoped, array( 'from' => $ly[0], 'to' => $ly[1] ), $groups, $names ) : null,
			'prev_name' => $p['prev_name'],
			'channel'   => $channel,
			'names'     => $names,
			'seen'      => $n['seen'],
			'groups'    => $groups,
			'types'     => $types,
			'heard'     => $heard,
			'colours'   => array(
				'heard' => Report_Data::colours( $heard ),
				'type'  => Report_Data::colours( $types ),
			),
			'buckets'   => Report_Data::buckets( $p['start'], $p['end'], $by, $ws ),
			'attention' => self::attention(),
		);
	}

	/** The same calendar day a year earlier (29 Feb → 28 Feb). */
	public static function year_back( $ymd ) {
		$y = (int) substr( $ymd, 0, 4 ) - 1;
		$m = (int) substr( $ymd, 5, 2 );
		$d = min( (int) substr( $ymd, 8, 2 ), (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) ) );

		return sprintf( '%04d-%02d-%02d', $y, $m, $d );
	}

	/* ------------------------------------------------------------------ Filters (drill-down too) */

	/**
	 * Filter rows. Keys (all optional): from, to (Y-m-d), channel (enquiry|booking), type, heard
	 * (group names), vchannel (visit channel key, 'none' = not recorded), stage, reason, weekday (1–7),
	 * hour (0–23), assigned ('__none' = nobody), month (Y-m), replied (late|ontime|none).
	 */
	public static function filter( array $rows, array $f, array $groups, array $names ) {
		$target = Office::target_minutes();

		return array_values(
			array_filter(
				$rows,
				static function ( $r ) use ( $f, $groups, $names, $target ) {
					if ( ! empty( $f['from'] ) && $r['day'] < $f['from'] ) {
						return false;
					}
					if ( ! empty( $f['to'] ) && $r['day'] > $f['to'] ) {
						return false;
					}
					if ( ! empty( $f['month'] ) && substr( $r['day'], 0, 7 ) !== $f['month'] ) {
						return false;
					}
					if ( ! empty( $f['channel'] ) && 'all' !== $f['channel'] && $r['channel'] !== $f['channel'] ) {
						return false;
					}
					if ( isset( $f['type'] ) && '' !== $f['type'] && Report_Data::group( 'type', $r['type'], $groups, $names ) !== $f['type'] ) {
						return false;
					}
					if ( isset( $f['heard'] ) && '' !== $f['heard'] && Report_Data::group( 'heard', $r['heard'], $groups, $names ) !== $f['heard'] ) {
						return false;
					}
					if ( isset( $f['vchannel'] ) && '' !== $f['vchannel'] && $r['visit_channel'] !== ( 'none' === $f['vchannel'] ? '' : $f['vchannel'] ) ) {
						return false;
					}
					if ( ! empty( $f['stage'] ) && $r['stage'] !== $f['stage'] ) {
						return false;
					}
					if ( isset( $f['reason'] ) && '' !== $f['reason'] && ( 'lost' !== $r['stage'] || ( '__none' === $f['reason'] ? '' !== $r['reason'] : $r['reason'] !== $f['reason'] ) ) ) {
						return false;
					}
					if ( ! empty( $f['weekday'] ) && (int) $r['weekday'] !== (int) $f['weekday'] ) {
						return false;
					}
					if ( isset( $f['hour'] ) && '' !== $f['hour'] && (int) $r['hour'] !== (int) $f['hour'] ) {
						return false;
					}
					if ( isset( $f['assigned'] ) && '' !== $f['assigned'] && $r['assigned'] !== ( '__none' === $f['assigned'] ? '' : $f['assigned'] ) ) {
						return false;
					}
					if ( ! empty( $f['replied'] ) ) {
						$m = null === $r['reply_minutes'] ? null : (int) $r['reply_minutes'];
						if ( ( 'none' === $f['replied'] && null !== $m ) || ( 'late' === $f['replied'] && ( null === $m || $m <= $target ) ) || ( 'ontime' === $f['replied'] && ( null === $m || $m > $target ) ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);
	}

	/* ------------------------------------------------------------------ Figures */

	public static function stage_counts( array $rows ) {
		$out = array( 'open' => 0, 'won' => 0, 'lost' => 0 );
		foreach ( $rows as $r ) {
			$out[ $r['stage'] ] = ( $out[ $r['stage'] ] ?? 0 ) + 1;
		}

		return $out;
	}

	/** Sum of the OMR values of these rows (only rows with a value), optionally of one stage. */
	public static function value_sum( array $rows, $stage = '' ) {
		$sum = 0.0;
		foreach ( $rows as $r ) {
			if ( isset( $r['value'] ) && ( '' === $stage || $r['stage'] === $stage ) ) {
				$sum += (float) $r['value'];
			}
		}

		return $sum;
	}

	public static function money( $omr ) {
		return 'OMR ' . number_format_i18n( (float) $omr, 0 );
	}

	public static function median( array $values ) {
		$values = array_values( array_filter( $values, 'is_numeric' ) );
		if ( ! $values ) {
			return null;
		}
		sort( $values );
		$n = count( $values );

		return 1 === $n % 2 ? (float) $values[ intdiv( $n, 2 ) ] : ( $values[ $n / 2 - 1 ] + $values[ $n / 2 ] ) / 2;
	}

	/** Reply figures of these rows: median office minutes, how many replied, late, not yet. */
	public static function replies( array $rows ) {
		$target = Office::target_minutes();
		$mins   = array();
		$late   = 0;
		$none   = 0;
		foreach ( $rows as $r ) {
			if ( null === $r['reply_minutes'] ) {
				++$none;
				continue;
			}
			$mins[] = (int) $r['reply_minutes'];
			if ( (int) $r['reply_minutes'] > $target ) {
				++$late;
			}
		}

		return array(
			'median'  => self::median( $mins ),
			'replied' => count( $mins ),
			'late'    => $late,
			'ontime'  => count( $mins ) - $late,
			'none'    => $none,
		);
	}

	/** Win rate among decided enquiries (won ÷ (won + lost)), or null when fewer than $min are decided. */
	public static function win_rate( array $rows, $min = 1 ) {
		$s       = self::stage_counts( $rows );
		$decided = $s['won'] + $s['lost'];

		return $decided >= $min ? $s['won'] / $decided : null;
	}

	/**
	 * Per group: enquiries, won, lost, open, conversion (won ÷ all), win rate (won ÷ decided).
	 *
	 * @param callable $key Row → group name.
	 * @return array group => array( n, won, lost, open )
	 */
	public static function outcomes( array $rows, $key ) {
		$out = array();
		foreach ( $rows as $r ) {
			$g = $key( $r );
			if ( ! isset( $out[ $g ] ) ) {
				$out[ $g ] = array( 'n' => 0, 'won' => 0, 'lost' => 0, 'open' => 0, 'won_value' => 0.0 );
			}
			++$out[ $g ]['n'];
			++$out[ $g ][ $r['stage'] ];
			if ( 'won' === $r['stage'] && null !== $r['value'] ) {
				$out[ $g ]['won_value'] += (float) $r['value'];
			}
		}
		uasort(
			$out,
			static function ( $a, $b ) {
				return $b['n'] <=> $a['n'];
			}
		);

		return $out;
	}

	/* ------------------------------------------------------------------ Needs attention (now, any date) */

	/**
	 * Open enquiries needing someone: waiting for a first reply (with office time waited, overdue
	 * when over the target), stuck (no status change for the set number of days), not assigned.
	 */
	public static function attention( $now = null ) {
		$now    = $now ? $now : time();
		$target = Office::target_minutes();
		$stuck  = Office::stuck_days() * DAY_IN_SECONDS;
		$out    = array( 'waiting' => array(), 'stuck' => array(), 'unassigned' => array() );
		foreach ( self::all() as $r ) {
			if ( 'open' !== $r['stage'] ) {
				continue;
			}
			$received = strtotime( $r['received_gmt'] . ' UTC' );
			if ( null === $r['replied_gmt'] ) {
				$waited                 = Office::minutes_between( $received, $now );
				$r['waited']            = $waited;
				$r['overdue']           = $waited > $target;
				$out['waiting'][]       = $r;
			} else {
				$since = strtotime( ( $r['last_change_gmt'] ? $r['last_change_gmt'] : $r['replied_gmt'] ) . ' UTC' );
				if ( $now - $since > $stuck ) {
					$r['idle_days'] = (int) floor( ( $now - $since ) / DAY_IN_SECONDS );
					$out['stuck'][] = $r;
				}
			}
			if ( '' === $r['assigned'] ) {
				$out['unassigned'][] = $r;
			}
		}
		usort(
			$out['waiting'],
			static function ( $a, $b ) {
				return $b['waited'] <=> $a['waited'];
			}
		);
		usort(
			$out['stuck'],
			static function ( $a, $b ) {
				return $b['idle_days'] <=> $a['idle_days'];
			}
		);
		$out['overdue'] = count(
			array_filter(
				$out['waiting'],
				static function ( $r ) {
					return $r['overdue'];
				}
			)
		);

		return $out;
	}

	/* ------------------------------------------------------------------ Alerts */

	/**
	 * Warnings worth an email or a red box: no enquiries for 48 hours although the last 4 weeks
	 * brought two or more a day ("the form may be broken"), and office emails that failed this week.
	 *
	 * @return array[] each: level (warning|error), text, link (list filter or '').
	 */
	public static function alerts( $now = null ) {
		$now    = $now ? $now : time();
		$alerts = array();
		$enq    = array_values(
			array_filter(
				self::all(),
				static function ( $r ) {
					return 'enquiry' === $r['channel'] && ! (int) $r['is_sample'];
				}
			)
		);
		if ( $enq ) {
			$last  = strtotime( end( $enq )['received_gmt'] . ' UTC' );
			$since = $now - 30 * DAY_IN_SECONDS;
			$until = $now - 2 * DAY_IN_SECONDS;
			$n     = 0;
			foreach ( $enq as $r ) {
				$t = strtotime( $r['received_gmt'] . ' UTC' );
				if ( $t >= $since && $t < $until ) {
					++$n;
				}
			}
			$per_day = $n / 28;
			if ( $now - $last > 2 * DAY_IN_SECONDS && $per_day >= 2 ) {
				$alerts[] = array(
					'level' => 'error',
					/* translators: 1: date and time of the last enquiry, 2: usual number a day */
					'text'  => sprintf( __( 'No enquiries since %1$s, although the last four weeks brought about %2$s a day. Please check that the enquiry forms still work (send a test, then mark it as a test).', 'travelstore-enquiries' ), wp_date( 'D j M, H:i', $last ), number_format_i18n( $per_day, 1 ) ),
					'link'  => '',
				);
			}
		}
		global $wpdb;
		$failed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'failed'
				WHERE p.post_type = %s AND p.post_status = 'private' AND p.post_date_gmt >= %s",
				Store::META . 'office_status',
				Store::POST_TYPE,
				gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS )
			)
		);
		if ( $failed ) {
			$alerts[] = array(
				'level' => 'warning',
				/* translators: %d: number of enquiries */
				'text'  => sprintf( _n( 'The office email failed for %d enquiry in the last 7 days. Open it and use "Email it to the office again", and check the mail settings.', 'The office email failed for %d enquiries in the last 7 days. Open them and use "Email it to the office again", and check the mail settings.', $failed, 'travelstore-enquiries' ), $failed ),
				'link'  => 'office_failed',
			);
		}

		return $alerts;
	}

	/* ------------------------------------------------------------------ "What changed" sentences */

	/**
	 * Plain sentences on what changed, most important first (at most $max). Fixed rules: a change is
	 * kept only when it is big enough to matter (5+ enquiries, 25%+ and 3+ either way).
	 *
	 * @param array $c Context: cur, prev (null for All time), ly (same period last year or null),
	 *                 prev_name, groups, names, channel, attention.
	 * @return array[] each: dir (up|down|info|alert), text, filter (array for the list link, or null).
	 */
	public static function sentences( array $c, $max = 5 ) {
		$out    = array();
		$cur    = $c['cur'];
		$prev   = $c['prev'];
		$g      = $c['groups'];
		$names  = $c['names'];
		$pname  = $c['prev_name'];
		$what   = 'booking' === $c['channel'] ? __( 'Vehicle hire bookings', 'travelstore-enquiries' ) : ( 'enquiry' === $c['channel'] ? __( 'Enquiries', 'travelstore-enquiries' ) : __( 'Website contacts', 'travelstore-enquiries' ) );
		$moved  = static function ( $n, $p ) {
			if ( null === $p ) {
				return false;
			}
			if ( 0 === $p ) {
				return $n >= 5;
			}
			return max( $n, $p ) >= 5 && abs( $n - $p ) >= 3 && abs( $n - $p ) / $p >= 0.25;
		};
		$pct    = static function ( $n, $p ) {
			return 0 === $p ? '' : abs( (int) round( ( $n - $p ) / $p * 100 ) ) . '%';
		};
		$top_of = static function ( array $rows, $kind ) use ( $g, $names ) {
			$counts = array();
			foreach ( $rows as $r ) {
				$k = 'visit' === $kind ? $r['visit_channel'] : Report_Data::group( 'heard', $r['heard'], $g, $names );
				if ( '' === $k || 0 === strpos( $k, '__' ) ) {
					continue;
				}
				$counts[ $k ] = ( $counts[ $k ] ?? 0 ) + 1;
			}
			arsort( $counts );
			$k = array_key_first( $counts );

			return null === $k ? null : array( 'visit' === $kind ? Visit::channels()[ $k ] : $k, $counts[ $k ] );
		};

		// Waiting longer than the reply target: always first, it is what to do now.
		if ( ! empty( $c['attention']['overdue'] ) ) {
			$n     = $c['attention']['overdue'];
			$out[] = array(
				'score'  => 1000,
				'dir'    => 'alert',
				/* translators: 1: number, 2: reply target */
				'text'   => sprintf( _n( '%1$d enquiry is waiting longer than the %2$s reply target.', '%1$d enquiries are waiting longer than the %2$s reply target.', $n, 'travelstore-enquiries' ), $n, Office::duration( Office::target_minutes() ) ),
				'filter' => array( 'attention' => 'overdue' ),
			);
		}

		if ( null !== $prev ) {
			$n = count( $cur );
			$p = count( $prev );
			if ( $moved( $n, $p ) ) {
				$out[] = array(
					'score'  => abs( $n - $p ) * 1.2,
					'dir'    => $n > $p ? 'up' : 'down',
					/* translators: 1: what, 2: e.g. 32%, 3: e.g. the month before, 4: now, 5: before */
					'text'   => 0 === $p ? sprintf( __( '%1$s: %4$d, up from none in %3$s.', 'travelstore-enquiries' ), $what, '', $pname, $n, $p ) : sprintf( $n > $p ? __( '%1$s are up %2$s on %3$s (%4$d vs %5$d).', 'travelstore-enquiries' ) : __( '%1$s are down %2$s on %3$s (%4$d vs %5$d).', 'travelstore-enquiries' ), $what, $pct( $n, $p ), $pname, $n, $p ),
					'filter' => array(),
				);
			}

			// Each enquiry type, with what brought most of them.
			$now_t = Report_Data::counts( $cur, 'type', $g, $names );
			$was_t = Report_Data::counts( $prev, 'type', $g, $names );
			foreach ( $now_t + array_fill_keys( array_keys( $was_t ), 0 ) as $type => $n ) {
				$n = $now_t[ $type ] ?? 0;
				$p = $was_t[ $type ] ?? 0;
				if ( ! $moved( $n, $p ) ) {
					continue;
				}
				$text = 0 === $p
					/* translators: 1: type, 2: count, 3: e.g. the month before */
					? sprintf( __( '%1$s: %2$d, none in %3$s.', 'travelstore-enquiries' ), $type, $n, $pname )
					/* translators: 1: type, 2: e.g. 32%, 3: e.g. the month before, 4: now, 5: before */
					: sprintf( $n > $p ? __( '%1$s is up %2$s on %3$s (%4$d vs %5$d).', 'travelstore-enquiries' ) : __( '%1$s is down %2$s on %3$s (%4$d vs %5$d).', 'travelstore-enquiries' ), $type, $pct( $n, $p ), $pname, $n, $p );
				if ( $n > $p ) {
					$rows = Insights_Data::filter( $cur, array( 'type' => $type ), $g, $names );
					$top  = $top_of( $rows, 'heard' );
					$top  = $top && $top[1] >= 3 && $top[1] / max( 1, $n ) >= 0.3 ? $top : $top_of( $rows, 'visit' );
					if ( $top && $top[1] >= 3 && $top[1] / max( 1, $n ) >= 0.3 ) {
						/* translators: 1: source, 2: count */
						$text .= ' ' . sprintf( __( '%1$s brought %2$d of them.', 'travelstore-enquiries' ), $top[0], $top[1] );
					}
				}
				$out[] = array(
					'score'  => abs( $n - $p ) * 1.1,
					'dir'    => $n > $p ? 'up' : 'down',
					'text'   => $text,
					'filter' => array( 'type' => $type ),
				);
			}

			// Each "How did you hear" source.
			$now_h = Report_Data::counts( $cur, 'heard', $g, $names );
			$was_h = Report_Data::counts( $prev, 'heard', $g, $names );
			foreach ( $now_h + array_fill_keys( array_keys( $was_h ), 0 ) as $src => $n ) {
				$n = $now_h[ $src ] ?? 0;
				$p = $was_h[ $src ] ?? 0;
				if ( 0 === strpos( $src, '__' ) || ! $moved( $n, $p ) ) {
					continue;
				}
				$out[] = array(
					'score'  => abs( $n - $p ),
					'dir'    => $n > $p ? 'up' : 'down',
					/* translators: 1: source, 2: now, 3: before, 4: e.g. the month before */
					'text'   => sprintf( $n > $p ? __( 'More enquirers heard about you through %1$s: %2$d, vs %3$d in %4$s.', 'travelstore-enquiries' ) : __( 'Fewer enquirers heard about you through %1$s: %2$d, vs %3$d in %4$s.', 'travelstore-enquiries' ), $src, $n, $p, $pname ),
					'filter' => array( 'heard' => $src ),
				);
			}

			// Value of what was won.
			$vn = self::value_sum( $cur, 'won' );
			$vp = self::value_sum( $prev, 'won' );
			if ( max( $vn, $vp ) >= 500 && abs( $vn - $vp ) >= 0.25 * max( 1, $vp ) && $vp > 0 ) {
				$out[] = array(
					'score'  => 4 + abs( $vn - $vp ) / max( 1, $vp ) * 4,
					'dir'    => $vn > $vp ? 'up' : 'down',
					/* translators: 1: value now, 2: change, 3: e.g. the month before, 4: value then */
					'text'   => sprintf( __( 'Won bookings are worth %1$s, %2$s on %3$s (%4$s).', 'travelstore-enquiries' ), self::money( $vn ), Report_Data::change( $vn, $vp ), $pname, self::money( $vp ) ),
					'filter' => array( 'stage' => 'won' ),
				);
			}

			// Win rate among decided enquiries.
			$wn = self::win_rate( $cur, 10 );
			$wp = self::win_rate( $prev, 10 );
			if ( null !== $wn && null !== $wp && abs( $wn - $wp ) >= 0.1 ) {
				$out[] = array(
					'score'  => abs( $wn - $wp ) * 40,
					'dir'    => $wn > $wp ? 'up' : 'down',
					/* translators: 1: percent now, 2: percent before, 3: e.g. the month before */
					'text'   => sprintf( __( 'You won %1$s of the enquiries already decided, vs %2$s in %3$s.', 'travelstore-enquiries' ), round( $wn * 100 ) . '%', round( $wp * 100 ) . '%', $pname ),
					'filter' => array( 'stage' => 'won' ),
				);
			}
		}

		// Reply speed against the target.
		$rep = self::replies( $cur );
		if ( null !== $rep['median'] && $rep['replied'] >= 5 ) {
			$target = Office::target_minutes();
			if ( $rep['median'] > $target ) {
				$out[] = array(
					'score'  => 6 + $rep['late'],
					'dir'    => 'down',
					/* translators: 1: median time, 2: target, 3: number late */
					'text'   => sprintf( __( 'The usual (median) first reply took %1$s, over the %2$s target; %3$d replies were late.', 'travelstore-enquiries' ), Office::duration( $rep['median'] ), Office::duration( $target ), $rep['late'] ),
					'filter' => array( 'replied' => 'late' ),
				);
			} elseif ( $rep['late'] / max( 1, $rep['replied'] ) <= 0.1 ) {
				$out[] = array(
					'score'  => 3,
					'dir'    => 'up',
					/* translators: 1: median time, 2: percent on time */
					'text'   => sprintf( __( 'Replies were quick: usually within %1$s, and %2$s within the target.', 'travelstore-enquiries' ), Office::duration( $rep['median'] ), round( $rep['ontime'] / max( 1, $rep['replied'] ) * 100 ) . '%' ),
					'filter' => array( 'replied' => 'ontime' ),
				);
			}
		}

		// Best weekday, when it stands out.
		if ( count( $cur ) >= 20 ) {
			$wd = array_count_values( array_map( 'intval', array_column( $cur, 'weekday' ) ) );
			arsort( $wd );
			$day = array_key_first( $wd );
			if ( $wd[ $day ] / count( $cur ) >= 0.2 ) {
				$out[] = array(
					'score'  => 2.5,
					'dir'    => 'info',
					/* translators: 1: weekday plural, 2: percent */
					'text'   => sprintf( __( '%1$s brought the most enquiries: %2$s of them.', 'travelstore-enquiries' ), date_i18n( 'l', strtotime( '2024-01-0' . $day . ' 12:00 UTC' ), true ) . 's', round( $wd[ $day ] / count( $cur ) * 100 ) . '%' ),
					'filter' => array( 'weekday' => $day ),
				);
			}
		}

		// Main reason for losing.
		$lost = array_filter(
			$cur,
			static function ( $r ) {
				return 'lost' === $r['stage'] && '' !== $r['reason'];
			}
		);
		if ( count( $lost ) >= 5 ) {
			$reasons = array_count_values( array_column( $lost, 'reason' ) );
			arsort( $reasons );
			$top   = array_key_first( $reasons );
			$out[] = array(
				'score'  => count( $lost ) / 2,
				'dir'    => 'info',
				/* translators: 1: reason, 2: count, 3: total */
				'text'   => sprintf( __( 'Main reason for losing enquiries: "%1$s" (%2$d of %3$d with a reason).', 'travelstore-enquiries' ), $top, $reasons[ $top ], count( $lost ) ),
				'filter' => array( 'stage' => 'lost', 'reason' => $top ),
			);
		}

		// Against the same period last year (shows the season is not mistaken for a trend).
		if ( null !== $c['ly'] ) {
			$n = count( $cur );
			$p = count( $c['ly'] );
			if ( $p >= 5 && $moved( $n, $p ) ) {
				$out[] = array(
					'score'  => abs( $n - $p ) * 0.8,
					'dir'    => $n > $p ? 'up' : 'down',
					/* translators: 1: e.g. up 18%, 2: now, 3: a year earlier */
					'text'   => sprintf( __( 'Against the same dates last year: %1$s (%2$d vs %3$d).', 'travelstore-enquiries' ), ( $n > $p ? '+' : '-' ) . $pct( $n, $p ), $n, $p ),
					'filter' => null,
				);
			}
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $out, 0, $max );
	}
}
