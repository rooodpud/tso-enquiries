<?php
/**
 * Enquiries > Insights (1.3.0; was "Reports" in 1.2.0): answers first, charts second.
 *
 * Pages: Overview (what changed, key figures, alerts, who is waiting), Needs attention (open
 * enquiries waiting for a reply, stuck or not assigned), Demand (volume, days and hours, types,
 * seasonality), Marketing ("How did you hear", automatic visit source, campaigns, landing pages),
 * Pipeline (won / lost / open, conversion, lost reasons). One filter bar for all, remembered per
 * user; every count links to the Enquiries list showing exactly those enquiries (drill_ids()).
 * Figures come from Insights_Data (facts table); drawing from Report_View. Nothing stored is changed.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports {

	const PAGE       = 'ts-enquiry-reports';
	const CSV_ACTION = 'ts_enquiry_report_csv';
	const REBUILD    = 'ts_enquiry_insights_rebuild';
	const USER_META  = 'ts_enq_insights_q';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_' . self::CSV_ACTION, array( __CLASS__, 'csv' ) );
		add_action( 'admin_post_' . self::REBUILD, array( __CLASS__, 'rebuild' ) );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . Store::POST_TYPE, __( 'Enquiry insights', 'travelstore-enquiries' ), __( 'Insights', 'travelstore-enquiries' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ), 1 );
	}

	public static function tabs() {
		return array(
			'overview'  => __( 'Overview', 'travelstore-enquiries' ),
			'attention' => __( 'Needs attention', 'travelstore-enquiries' ),
			'demand'    => __( 'Demand', 'travelstore-enquiries' ),
			'marketing' => __( 'Marketing', 'travelstore-enquiries' ),
			'pipeline'  => __( 'Pipeline', 'travelstore-enquiries' ),
		);
	}

	public static function channels() {
		return array(
			'all'     => __( 'All website contacts', 'travelstore-enquiries' ),
			'enquiry' => __( 'Enquiries only', 'travelstore-enquiries' ),
			'booking' => __( 'Vehicle hire bookings only', 'travelstore-enquiries' ),
		);
	}

	public static function stages() {
		return array(
			'open' => __( 'Open', 'travelstore-enquiries' ),
			'won'  => __( 'Won', 'travelstore-enquiries' ),
			'lost' => __( 'Lost', 'travelstore-enquiries' ),
		);
	}

	/* ------------------------------------------------------------------ Filter bar */

	private static function query( array $in, $remember = false ) {
		$keys = array( 'tab', 'range', 'from', 'to', 'week', 'month', 'compare', 'channel', 'type', 'by' );
		$text = static function ( $key ) use ( $in ) {
			return isset( $in[ $key ] ) && is_scalar( $in[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $in[ $key ] ) ) : '';
		};
		$q    = array();
		foreach ( $keys as $key ) {
			$q[ $key ] = $text( $key );
		}
		$user = get_current_user_id();
		if ( $remember && $user ) {
			if ( '' === $q['range'] ) {
				// No filter in the address: start from what this person used last.
				$saved = get_user_meta( $user, self::USER_META, true );
				if ( is_array( $saved ) ) {
					foreach ( $saved as $key => $value ) {
						if ( 'tab' !== $key && in_array( $key, $keys, true ) && is_scalar( $value ) ) {
							$q[ $key ] = sanitize_text_field( (string) $value );
						}
					}
				}
			} else {
				update_user_meta( $user, self::USER_META, array_diff_key( $q, array( 'tab' => 1 ) ) );
			}
		}
		$q['tab']     = array_key_exists( $q['tab'], self::tabs() ) ? $q['tab'] : 'overview';
		$q['range']   = array_key_exists( $q['range'], Report_Data::ranges() ) ? $q['range'] : 'this_month';
		$q['channel'] = array_key_exists( $q['channel'], self::channels() ) ? $q['channel'] : 'all';
		$q['compare'] = 'ly' === $q['compare'] ? 'ly' : 'prev';
		$q['by']      = in_array( $q['by'], array( 'day', 'week', 'month' ), true ) ? $q['by'] : '';

		return $q;
	}

	public static function page_url( array $q, array $change = array() ) {
		$args = array_filter(
			array_merge( $q, $change ),
			static function ( $v ) {
				return '' !== $v && null !== $v;
			}
		);

		return add_query_arg( array_merge( array( 'post_type' => Store::POST_TYPE, 'page' => self::PAGE ), $args ), admin_url( 'edit.php' ) );
	}

	/**
	 * Link to the Enquiries list showing the enquiries behind a number: the page's period, Show and
	 * type, plus $f (heard, vchannel, stage, reason, weekday, hour, month, from, to, replied, assigned,
	 * campaign, landing). $f['attention'] (waiting|overdue|stuck|unassigned) ignores the period.
	 */
	public static function list_url( array $c, array $f = array() ) {
		$args = array( 'post_type' => Store::POST_TYPE, 'ts_i' => 1 );
		if ( empty( $f['attention'] ) && empty( $f['test'] ) && empty( $f['office_failed'] ) ) {
			$args['ts_from'] = $c['p']['start'];
			$args['ts_to']   = $c['p']['end'];
			if ( 'all' !== $c['q']['channel'] ) {
				$args['ts_channel'] = $c['q']['channel'];
			}
			if ( '' !== $c['q']['type'] ) {
				$args['ts_type'] = $c['q']['type'];
			}
		}
		foreach ( $f as $key => $value ) {
			$args[ 'ts_' . $key ] = $value;
		}

		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), admin_url( 'edit.php' ) );
	}

	/**
	 * For the Enquiries list: the IDs behind a drill-down link, and a sentence saying what they are.
	 *
	 * @return array|null array( ids, text ) or null when the address has no Insights filter.
	 */
	public static function drill( array $get ) {
		if ( empty( $get['ts_i'] ) ) {
			return null;
		}
		$v = static function ( $key ) use ( $get ) {
			return isset( $get[ 'ts_' . $key ] ) && is_scalar( $get[ 'ts_' . $key ] ) ? sanitize_text_field( wp_unslash( (string) $get[ 'ts_' . $key ] ) ) : '';
		};
		global $wpdb;
		if ( '' !== $v( 'test' ) ) {
			$ids = array_map( 'intval', (array) $wpdb->get_col( 'SELECT enquiry_id FROM ' . Facts::facts_table() . ' WHERE is_test = 1' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return array( $ids, __( 'Enquiries marked as test or spam (left out of Insights).', 'travelstore-enquiries' ) );
		}
		if ( '' !== $v( 'office_failed' ) ) {
			$ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = 'failed' WHERE p.post_type = %s AND p.post_status = 'private' AND p.post_date_gmt >= %s",
						Store::META . 'office_status',
						Store::POST_TYPE,
						gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
					)
				)
			);
			return array( $ids, __( 'Enquiries of the last 7 days whose office email failed.', 'travelstore-enquiries' ) );
		}
		$att = $v( 'attention' );
		if ( '' !== $att ) {
			$a     = Insights_Data::attention();
			if ( 'overdue' === $att ) {
				$rows = array_filter( $a['waiting'], static function ( $r ) {
					return $r['overdue'];
				} );
			} elseif ( 'open' === $att ) {
				$rows = array_filter( Insights_Data::all(), static function ( $r ) {
					return 'open' === $r['stage'];
				} );
			} else {
				$rows = $a[ $att ] ?? array();
			}
			$texts = array(
				'waiting'    => __( 'Open enquiries waiting for a first reply.', 'travelstore-enquiries' ),
				'overdue'    => __( 'Open enquiries waiting longer than the reply target.', 'travelstore-enquiries' ),
				'stuck'      => __( 'Open enquiries with no status change for a while.', 'travelstore-enquiries' ),
				'unassigned' => __( 'Open enquiries not assigned to anyone.', 'travelstore-enquiries' ),
				'open'       => __( 'All open enquiries.', 'travelstore-enquiries' ),
			);
			return array( array_map( 'intval', array_column( $rows, 'id' ) ), $texts[ $att ] ?? '' );
		}

		$n      = Report_Data::names( Insights_Data::all() );
		$groups = Report_Data::saved_groups();
		$f      = array();
		$parts  = array();
		foreach ( array( 'from', 'to', 'channel', 'type', 'heard', 'vchannel', 'stage', 'reason', 'weekday', 'hour', 'month', 'replied', 'assigned' ) as $key ) {
			if ( '' !== $v( $key ) ) {
				$f[ $key ] = $v( $key );
			}
		}
		$rows = Insights_Data::filter( Insights_Data::all(), $f, $groups, $n['names'] );
		foreach ( array( 'campaign', 'landing' ) as $key ) {
			if ( '' !== $v( $key ) ) {
				$want = $v( $key );
				$rows = array_filter(
					$rows,
					static function ( $r ) use ( $key, $want ) {
						return 'campaign' === $key ? self::campaign_of( $r ) === $want : $r['landing'] === $want;
					}
				);
				$parts[] = $want;
			}
		}
		if ( isset( $f['from'], $f['to'] ) ) {
			array_unshift( $parts, self::range_text( $f['from'], $f['to'] ) );
		}
		if ( isset( $f['month'] ) ) {
			$parts[] = self::day_text( $f['month'] . '-01', 'F Y' );
		}
		if ( isset( $f['channel'] ) ) {
			$parts[] = self::channels()[ $f['channel'] ] ?? '';
		}
		foreach ( array( 'type', 'heard' ) as $key ) {
			if ( isset( $f[ $key ] ) ) {
				$parts[] = self::label( $f[ $key ] );
			}
		}
		if ( isset( $f['vchannel'] ) ) {
			$parts[] = Visit::channels()[ 'none' === $f['vchannel'] ? '' : $f['vchannel'] ] ?? '';
		}
		if ( isset( $f['stage'] ) ) {
			$parts[] = self::stages()[ $f['stage'] ] ?? '';
		}
		if ( isset( $f['reason'] ) ) {
			$parts[] = '__none' === $f['reason'] ? __( 'no reason given', 'travelstore-enquiries' ) : $f['reason'];
		}
		if ( isset( $f['weekday'] ) ) {
			$parts[] = self::day_text( '2024-01-0' . (int) $f['weekday'], 'l' ) . 's';
		}
		if ( isset( $f['hour'] ) ) {
			$parts[] = sprintf( '%02d:00–%02d:59', (int) $f['hour'], (int) $f['hour'] );
		}
		if ( isset( $f['replied'] ) ) {
			$replied = array(
				'late'   => __( 'first reply later than the target', 'travelstore-enquiries' ),
				'ontime' => __( 'first reply within the target', 'travelstore-enquiries' ),
				'none'   => __( 'no reply recorded', 'travelstore-enquiries' ),
			);
			$parts[] = $replied[ $f['replied'] ] ?? '';
		}

		return array( array_map( 'intval', array_column( $rows, 'id' ) ), implode( ' · ', array_filter( $parts ) ) );
	}

	/* ------------------------------------------------------------------ Small helpers */

	public static function label( $group ) {
		if ( Report_Data::NOT_ASKED === $group ) {
			return __( 'Not asked (flyout, Alia, hire booking)', 'travelstore-enquiries' );
		}

		return Report_Data::NOT_ANSWERED === $group ? __( 'Not answered', 'travelstore-enquiries' ) : $group;
	}

	public static function day_text( $ymd, $format = 'D j M Y' ) {
		return date_i18n( $format, strtotime( $ymd . ' 00:00:00 UTC' ), true );
	}

	public static function range_text( $from, $to ) {
		return $from === $to ? self::day_text( $from ) : self::day_text( $from, 'j M Y' ) . ' – ' . self::day_text( $to, 'j M Y' );
	}

	private static function pct( $part, $whole ) {
		return $whole ? round( $part / $whole * 100 ) . '%' : '—';
	}

	/** OMR amount for tables and CSV: whole rials, no currency word ("—" when nothing). */
	private static function omr( $value ) {
		return $value > 0 ? (int) round( $value ) : '—';
	}

	private static function pts( $now, $before ) {
		if ( null === $now || null === $before ) {
			return '—';
		}
		$d = (int) round( ( $now - $before ) * 100 );

		return 0 === $d ? __( 'no change', 'travelstore-enquiries' ) : sprintf( '%+d pts', $d );
	}

	private static function campaign_of( array $r ) {
		if ( '' !== $r['utm_campaign'] ) {
			return $r['utm_campaign'];
		}

		return '' !== $r['utm_source'] ? $r['utm_source'] . ( '' !== $r['utm_medium'] ? ' / ' . $r['utm_medium'] : '' ) : '';
	}

	/** First and last day of a bucket, kept inside the period. */
	private static function bucket_span( $key, $by, array $p ) {
		if ( 'month' === $by ) {
			$from = $key . '-01';
			$to   = gmdate( 'Y-m-t', strtotime( $from . ' UTC' ) );
		} elseif ( 'week' === $by ) {
			$from = $key;
			$to   = gmdate( 'Y-m-d', strtotime( $key . ' UTC' ) + 6 * DAY_IN_SECONDS );
		} else {
			$from = $key;
			$to   = $key;
		}

		return array( max( $from, $p['start'] ), min( $to, $p['end'] ) );
	}

	private static function by_word( $by ) {
		$words = array(
			'day'   => __( 'day', 'travelstore-enquiries' ),
			'week'  => __( 'week', 'travelstore-enquiries' ),
			'month' => __( 'month', 'travelstore-enquiries' ),
		);

		return $words[ $by ];
	}

	private static function csv_url( array $c, $table ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => self::CSV_ACTION, 'table' => $table ), array_filter( $c['q'] ) ), admin_url( 'admin-post.php' ) ), self::CSV_ACTION );
	}

	/* ------------------------------------------------------------------ Tables (screen and CSV) */

	/**
	 * Every table of every page, built once. Cells link to the enquiries behind them.
	 *
	 * @return array name => table (title, head, rows, num; trend tables also series).
	 */
	private static function tables( array $c ) {
		$t      = array();
		$cur    = $c['cur'];
		$prev   = $c['prev'];
		$g      = $c['groups'];
		$names  = $c['names'];
		$total  = count( $cur );
		$L      = static function ( array $f ) use ( $c ) {
			return self::list_url( $c, $f );
		};
		$before = __( 'Before', 'travelstore-enquiries' );
		$change = __( 'Change', 'travelstore-enquiries' );
		$won    = __( 'Won', 'travelstore-enquiries' );
		$conv   = __( 'Conversion', 'travelstore-enquiries' );

		// Volume per bucket.
		$split = Report_Data::series( $cur, $c['buckets'], $c['by'], $c['ws'], static function ( $r ) {
			return $r['channel'];
		} );
		$rows  = array();
		foreach ( $c['buckets'] as $key => $label ) {
			list( $from, $to ) = self::bucket_span( $key, $c['by'], $c['p'] );
			$e      = $split['enquiry'][ $key ] ?? 0;
			$b      = $split['booking'][ $key ] ?? 0;
			$rows[] = array( $label, Report_View::link( $e, $e ? $L( array( 'from' => $from, 'to' => $to, 'channel' => 'enquiry' ) ) : '' ), Report_View::link( $b, $b ? $L( array( 'from' => $from, 'to' => $to, 'channel' => 'booking' ) ) : '' ), Report_View::link( $e + $b, $e + $b ? $L( array( 'from' => $from, 'to' => $to ) ) : '' ) );
		}
		$t['volume'] = array(
			/* translators: %s: day, week or month */
			'title' => sprintf( __( 'Website contacts per %s', 'travelstore-enquiries' ), self::by_word( $c['by'] ) ),
			'head'  => array( __( 'Period', 'travelstore-enquiries' ), __( 'Enquiries', 'travelstore-enquiries' ), __( 'Vehicle hire bookings', 'travelstore-enquiries' ), __( 'Total', 'travelstore-enquiries' ) ),
			'rows'  => $rows,
			'num'   => array( 1, 2, 3 ),
		);

		// Weekdays.
		$wd   = Report_Data::weekdays( $cur, $c['p']['start'], $c['p']['end'], $c['ws'] );
		$rows = array();
		foreach ( $wd as $iso => $v ) {
			$rows[] = array( self::day_text( '2024-01-0' . $iso, 'l' ), Report_View::link( $v[0], $v[0] ? $L( array( 'weekday' => $iso ) ) : '' ), $v[1], $v[1] ? round( $v[0] / $v[1], 1 ) : 0.0, Report_Data::share( $v[0], $total ) . '%' );
		}
		$t['weekdays'] = array(
			'title' => __( 'By day of the week', 'travelstore-enquiries' ),
			'head'  => array( __( 'Day', 'travelstore-enquiries' ), __( 'Contacts', 'travelstore-enquiries' ), __( 'Such days in the period', 'travelstore-enquiries' ), __( 'Average per day', 'travelstore-enquiries' ), __( 'Share', 'travelstore-enquiries' ) ),
			'rows'  => $rows,
			'num'   => array( 1, 2, 3, 4 ),
		);

		// Types.
		$now  = Report_Data::counts( $cur, 'type', $g, $names );
		$was  = null !== $prev ? Report_Data::counts( $prev, 'type', $g, $names ) : array();
		$out  = Insights_Data::outcomes( $cur, static function ( $r ) use ( $g, $names ) {
			return Report_Data::group( 'type', $r['type'], $g, $names );
		} );
		$rows = array();
		foreach ( self::in_order( $now, $c['types'] ) as $grp => $n ) {
			$rows[] = array( $grp, Report_View::link( $n, $L( array( 'type' => $grp ) ) ), Report_Data::share( $n, $total ) . '%', Report_View::link( $out[ $grp ]['won'], $out[ $grp ]['won'] ? $L( array( 'type' => $grp, 'stage' => 'won' ) ) : '' ), self::pct( $out[ $grp ]['won'], $n ), null === $prev ? '—' : ( $was[ $grp ] ?? 0 ), null === $prev ? '—' : Report_Data::change( $n, $was[ $grp ] ?? 0 ), self::omr( $out[ $grp ]['won_value'] ) );
		}
		$t['types'] = array(
			'title' => __( 'Enquiries per type', 'travelstore-enquiries' ),
			'head'  => array( __( 'Type', 'travelstore-enquiries' ), __( 'Contacts', 'travelstore-enquiries' ), __( 'Share', 'travelstore-enquiries' ), $won, $conv, $before, $change, __( 'Won value (OMR)', 'travelstore-enquiries' ) ),
			'rows'  => $rows,
			'num'   => array( 1, 2, 3, 4, 5, 6, 7 ),
		);
		$t['types_trend'] = self::trend_table( $c, 'type', __( 'Types over time', 'travelstore-enquiries' ) );

		// Month by month per type, with the change from the month before (seasonality).
		$m     = Report_Data::months( $c['scoped'], $c['p']['start'], $c['p']['end'], $g, $names );
		$mcols = array_values( array_filter( $c['types'], static function ( $ty ) use ( $m ) {
			foreach ( $m['counts'] as $counts ) {
				if ( ! empty( $counts[ $ty ] ) ) {
					return true;
				}
			}
			return false;
		} ) );
		$ly_counts = array();
		foreach ( $c['scoped'] as $r ) {
			$ly_counts[ substr( $r['day'], 0, 7 ) ] = ( $ly_counts[ substr( $r['day'], 0, 7 ) ] ?? 0 ) + 1;
		}
		$head = array( __( 'Month', 'travelstore-enquiries' ) );
		foreach ( $mcols as $ty ) {
			$head[] = $ty;
			$head[] = $ty . ' · ' . __( 'vs month before', 'travelstore-enquiries' );
		}
		$head[] = __( 'Total', 'travelstore-enquiries' );
		$head[] = __( 'Total · vs month before', 'travelstore-enquiries' );
		$head[] = __( 'Same month last year', 'travelstore-enquiries' );
		$rows   = array();
		$last   = $m['before'];
		foreach ( $m['months'] as $ym => $label ) {
			$whole = $ym . '-01' >= $c['p']['start'] && gmdate( 'Y-m-t', strtotime( $ym . '-01 UTC' ) ) <= $c['p']['end'];
			/* translators: %s: month, e.g. Nov 2026 */
			$row = array( $whole ? $label : sprintf( __( '%s (part)', 'travelstore-enquiries' ), $label ) );
			foreach ( $mcols as $ty ) {
				$n     = $m['counts'][ $ym ][ $ty ] ?? 0;
				$row[] = Report_View::link( $n, $n ? $L( array( 'month' => $ym, 'type' => $ty ) ) : '' );
				$row[] = Report_Data::change( $n, $m['counts'][ $last ][ $ty ] ?? 0 );
			}
			$sum    = array_sum( $m['counts'][ $ym ] );
			$row[]  = Report_View::link( $sum, $sum ? $L( array( 'month' => $ym ) ) : '' );
			$row[]  = Report_Data::change( $sum, array_sum( $m['counts'][ $last ] ) );
			$row[]  = $ly_counts[ ( (int) substr( $ym, 0, 4 ) - 1 ) . substr( $ym, 4 ) ] ?? 0;
			$rows[] = $row;
			$last   = $ym;
		}
		$t['types_months'] = array(
			'title' => __( 'Month by month, per type', 'travelstore-enquiries' ),
			'head'  => $head,
			'rows'  => $rows,
			'num'   => range( 1, count( $head ) - 1 ),
		);

		// "How did you hear about us?"
		$now     = Report_Data::counts( $cur, 'heard', $g, $names );
		$was     = null !== $prev ? Report_Data::counts( $prev, 'heard', $g, $names ) : array();
		$answers = $total - ( $now[ Report_Data::NOT_ASKED ] ?? 0 ) - ( $now[ Report_Data::NOT_ANSWERED ] ?? 0 );
		$out     = Insights_Data::outcomes( $cur, static function ( $r ) use ( $g, $names ) {
			return Report_Data::group( 'heard', $r['heard'], $g, $names );
		} );
		$rows    = array();
		foreach ( self::in_order( $now, $c['heard'] ) as $grp => $n ) {
			$special = 0 === strpos( $grp, '__' );
			$rows[]  = array( self::label( $grp ), Report_View::link( $n, $L( array( 'heard' => $grp ) ) ), Report_Data::share( $n, $total ) . '%', $special ? '—' : Report_Data::share( $n, $answers ) . '%', Report_View::link( $out[ $grp ]['won'], $out[ $grp ]['won'] ? $L( array( 'heard' => $grp, 'stage' => 'won' ) ) : '' ), self::pct( $out[ $grp ]['won'], $n ), null === $prev ? '—' : ( $was[ $grp ] ?? 0 ), null === $prev ? '—' : Report_Data::change( $n, $was[ $grp ] ?? 0 ), self::omr( $out[ $grp ]['won_value'] ) );
		}
		$t['sources'] = array(
			'title' => __( 'How enquirers heard about us', 'travelstore-enquiries' ),
			'head'  => array( __( 'Source', 'travelstore-enquiries' ), __( 'Contacts', 'travelstore-enquiries' ), __( 'Share of all', 'travelstore-enquiries' ), __( 'Share of answers', 'travelstore-enquiries' ), $won, $conv, $before, $change, __( 'Won value (OMR)', 'travelstore-enquiries' ) ),
			'rows'  => $rows,
			'num'   => array( 1, 2, 3, 4, 5, 6, 7, 8 ),
		);
		$t['sources_trend'] = self::trend_table( $c, 'heard', __( 'Sources over time', 'travelstore-enquiries' ) );

		// Automatic visit source.
		$labels = Visit::channels();
		$out    = Insights_Data::outcomes( $cur, static function ( $r ) {
			return $r['visit_channel'];
		} );
		$was    = null !== $prev ? Insights_Data::outcomes( $prev, static function ( $r ) {
			return $r['visit_channel'];
		} ) : array();
		$rows   = array();
		foreach ( $out as $key => $o ) {
			$rows[] = array( $labels[ $key ] ?? $key, Report_View::link( $o['n'], $L( array( 'vchannel' => '' === $key ? 'none' : $key ) ) ), Report_Data::share( $o['n'], $total ) . '%', Report_View::link( $o['won'], $o['won'] ? $L( array( 'vchannel' => '' === $key ? 'none' : $key, 'stage' => 'won' ) ) : '' ), self::pct( $o['won'], $o['n'] ), null === $prev ? '—' : ( $was[ $key ]['n'] ?? 0 ), null === $prev ? '—' : Report_Data::change( $o['n'], $was[ $key ]['n'] ?? 0 ), self::omr( $o['won_value'] ) );
		}
		$t['visit'] = array(
			'title' => __( 'Where visitors came from (recorded automatically)', 'travelstore-enquiries' ),
			'head'  => array( __( 'Channel', 'travelstore-enquiries' ), __( 'Contacts', 'travelstore-enquiries' ), __( 'Share', 'travelstore-enquiries' ), $won, $conv, $before, $change, __( 'Won value (OMR)', 'travelstore-enquiries' ) ),
			'rows'  => $rows,
			'num'   => array( 1, 2, 3, 4, 5, 6, 7 ),
		);

		// Campaigns and landing pages.
		foreach ( array( 'campaigns' => 'campaign', 'landing' => 'landing' ) as $name => $key ) {
			$out  = Insights_Data::outcomes(
				array_filter( $cur, static function ( $r ) use ( $key ) {
					return '' !== ( 'campaign' === $key ? self::campaign_of( $r ) : $r['landing'] );
				} ),
				static function ( $r ) use ( $key ) {
					return 'campaign' === $key ? self::campaign_of( $r ) : $r['landing'];
				}
			);
			$rows = array();
			foreach ( array_slice( $out, 0, 25, true ) as $value => $o ) {
				$rows[] = array( (string) $value, Report_View::link( $o['n'], $L( array( $key => (string) $value ) ) ), Report_View::link( $o['won'], $o['won'] ? $L( array( $key => (string) $value, 'stage' => 'won' ) ) : '' ), self::pct( $o['won'], $o['n'] ), self::omr( $o['won_value'] ) );
			}
			$t[ $name ] = array(
				'title' => 'campaign' === $key ? __( 'Campaigns (from the campaign tags in links)', 'travelstore-enquiries' ) : __( 'First page of the visit (top 25)', 'travelstore-enquiries' ),
				'head'  => array( 'campaign' === $key ? __( 'Campaign', 'travelstore-enquiries' ) : __( 'Page', 'travelstore-enquiries' ), __( 'Contacts', 'travelstore-enquiries' ), $won, $conv, __( 'Won value (OMR)', 'travelstore-enquiries' ) ),
				'rows'  => $rows,
				'num'   => array( 1, 2, 3, 4 ),
			);
		}

		// Source × type, and self-reported × automatic.
		foreach ( array( 'cross' => 'type', 'cross_visit' => 'visit' ) as $name => $col ) {
			$grid = array();
			$cols = array();
			foreach ( $cur as $r ) {
				$h = Report_Data::group( 'heard', $r['heard'], $g, $names );
				$k = 'type' === $col ? Report_Data::group( 'type', $r['type'], $g, $names ) : $r['visit_channel'];
				if ( 'visit' === $col && ( '' === $k || 0 === strpos( $h, '__' ) ) ) {
					continue;
				}
				$grid[ $h ][ $k ] = ( $grid[ $h ][ $k ] ?? 0 ) + 1;
				$cols[ $k ]       = true;
			}
			$cols = 'type' === $col ? array_values( array_intersect( $c['types'], array_keys( $cols ) ) ) : array_values( array_intersect( array_keys( $labels ), array_keys( $cols ) ) );
			$rows = array();
			foreach ( self::in_order( $grid, $c['heard'] ) as $h => $by ) {
				$row = array( self::label( $h ) );
				foreach ( $cols as $k ) {
					$n     = $by[ $k ] ?? 0;
					$row[] = Report_View::link( $n, $n ? $L( 'type' === $col ? array( 'heard' => $h, 'type' => $k ) : array( 'heard' => $h, 'vchannel' => $k ) ) : '' );
				}
				$row[]  = array_sum( $by );
				$rows[] = $row;
			}
			$t[ $name ] = array(
				'title' => 'type' === $col ? __( 'Source by enquiry type', 'travelstore-enquiries' ) : __( 'What people said vs what was recorded', 'travelstore-enquiries' ),
				'head'  => array_merge( array( __( 'Heard about us', 'travelstore-enquiries' ) ), 'type' === $col ? $cols : array_map( static function ( $k ) use ( $labels ) {
					return $labels[ $k ];
				}, $cols ), array( __( 'Total', 'travelstore-enquiries' ) ) ),
				'rows'  => $rows,
				'num'   => range( 1, count( $cols ) + 1 ),
			);
		}

		// Pipeline by type / source / channel.
		$by_sets = array(
			'pipe_types'   => array( __( 'Type', 'travelstore-enquiries' ), static function ( $r ) use ( $g, $names ) {
				return Report_Data::group( 'type', $r['type'], $g, $names );
			}, 'type' ),
			'pipe_sources' => array( __( 'Heard about us', 'travelstore-enquiries' ), static function ( $r ) use ( $g, $names ) {
				return Report_Data::group( 'heard', $r['heard'], $g, $names );
			}, 'heard' ),
			'pipe_visit'   => array( __( 'Channel (recorded)', 'travelstore-enquiries' ), static function ( $r ) {
				return $r['visit_channel'];
			}, 'vchannel' ),
		);
		foreach ( $by_sets as $name => $set ) {
			$rows = array();
			foreach ( Insights_Data::outcomes( $cur, $set[1] ) as $key => $o ) {
				$fk     = 'vchannel' === $set[2] && '' === $key ? 'none' : $key;
				$label  = 'vchannel' === $set[2] ? ( $labels[ $key ] ?? $key ) : self::label( $key );
				$rows[] = array(
					$label,
					Report_View::link( $o['n'], $L( array( $set[2] => $fk ) ) ),
					Report_View::link( $o['won'], $o['won'] ? $L( array( $set[2] => $fk, 'stage' => 'won' ) ) : '' ),
					Report_View::link( $o['lost'], $o['lost'] ? $L( array( $set[2] => $fk, 'stage' => 'lost' ) ) : '' ),
					Report_View::link( $o['open'], $o['open'] ? $L( array( $set[2] => $fk, 'stage' => 'open' ) ) : '' ),
					self::pct( $o['won'], $o['n'] ),
					self::pct( $o['won'], $o['won'] + $o['lost'] ),
					self::omr( $o['won_value'] ),
					$o['won'] ? self::omr( $o['won_value'] / $o['won'] ) : '—',
				);
			}
			$t[ $name ] = array(
				'title' => '',
				'head'  => array( $set[0], __( 'Contacts', 'travelstore-enquiries' ), $won, __( 'Lost', 'travelstore-enquiries' ), __( 'Open', 'travelstore-enquiries' ), $conv, __( 'Win rate (decided)', 'travelstore-enquiries' ), __( 'Won value (OMR)', 'travelstore-enquiries' ), __( 'Average won (OMR)', 'travelstore-enquiries' ) ),
				'rows'  => $rows,
				'num'   => array( 1, 2, 3, 4, 5, 6, 7, 8 ),
			);
		}
		$t['pipe_types']['title']   = __( 'Outcome by enquiry type', 'travelstore-enquiries' );
		$t['pipe_sources']['title'] = __( 'Outcome by "How did you hear about us?"', 'travelstore-enquiries' );
		$t['pipe_visit']['title']   = __( 'Outcome by recorded channel', 'travelstore-enquiries' );

		// Lost reasons.
		$lost    = array_filter( $cur, static function ( $r ) {
			return 'lost' === $r['stage'];
		} );
		$reasons = array();
		foreach ( $lost as $r ) {
			$k             = '' !== $r['reason'] ? $r['reason'] : '__none';
			$reasons[ $k ] = ( $reasons[ $k ] ?? 0 ) + 1;
		}
		arsort( $reasons );
		$rows = array();
		foreach ( $reasons as $k => $n ) {
			$rows[] = array( '__none' === $k ? __( 'No reason given', 'travelstore-enquiries' ) : $k, Report_View::link( $n, $L( array( 'stage' => 'lost', 'reason' => $k ) ) ), Report_Data::share( $n, count( $lost ) ) . '%' );
		}
		$t['reasons'] = array(
			'title' => __( 'Why enquiries were lost', 'travelstore-enquiries' ),
			'head'  => array( __( 'Reason', 'travelstore-enquiries' ), __( 'Lost', 'travelstore-enquiries' ), __( 'Share', 'travelstore-enquiries' ) ),
			'rows'  => $rows,
			'num'   => array( 1, 2 ),
		);

		// Open enquiries now by age (any date).
		$ages = array(
			__( '0–2 days', 'travelstore-enquiries' )     => array( 0, 2 ),
			__( '3–7 days', 'travelstore-enquiries' )     => array( 3, 7 ),
			__( '8–14 days', 'travelstore-enquiries' )    => array( 8, 14 ),
			__( '15–30 days', 'travelstore-enquiries' )   => array( 15, 30 ),
			__( 'Over 30 days', 'travelstore-enquiries' ) => array( 31, PHP_INT_MAX ),
		);
		$age  = array_fill_keys( array_keys( $ages ), 0 );
		$aval = array_fill_keys( array_keys( $ages ), 0.0 );
		$now  = time();
		foreach ( $c['all'] as $r ) {
			if ( 'open' !== $r['stage'] ) {
				continue;
			}
			$d = (int) floor( ( $now - strtotime( $r['received_gmt'] . ' UTC' ) ) / DAY_IN_SECONDS );
			foreach ( $ages as $label => $span ) {
				if ( $d >= $span[0] && $d <= $span[1] ) {
					++$age[ $label ];
					$aval[ $label ] += null === $r['value'] ? 0 : (float) $r['value'];
				}
			}
		}
		$rows = array();
		foreach ( $age as $label => $n ) {
			$rows[] = array( $label, $n, self::omr( $aval[ $label ] ) );
		}
		$t['ages'] = array(
			'title' => __( 'Open enquiries now, by age', 'travelstore-enquiries' ),
			'head'  => array( __( 'Received', 'travelstore-enquiries' ), __( 'Open', 'travelstore-enquiries' ), __( 'Value entered (OMR)', 'travelstore-enquiries' ) ),
			'rows'  => $rows,
			'num'   => array( 1, 2 ),
		);

		return $t;
	}

	private static function trend_table( array $c, $kind, $title ) {
		$g      = $c['groups'];
		$names  = $c['names'];
		$series = Report_Data::series( $c['cur'], $c['buckets'], $c['by'], $c['ws'], static function ( $r ) use ( $kind, $g, $names ) {
			return Report_Data::group( $kind, $r[ $kind ], $g, $names );
		} );
		$series = self::in_order( $series, 'heard' === $kind ? $c['heard'] : $c['types'] );
		$rows   = array();
		foreach ( $c['buckets'] as $key => $label ) {
			$row = array( $label );
			foreach ( $series as $values ) {
				$row[] = $values[ $key ];
			}
			$rows[] = $row;
		}

		return array(
			'title'  => $title,
			'head'   => array_merge( array( __( 'Period', 'travelstore-enquiries' ) ), array_map( array( __CLASS__, 'label' ), array_keys( $series ) ) ),
			'rows'   => $rows,
			'num'    => range( 1, count( $series ) ),
			'series' => $series,
		);
	}

	private static function in_order( array $items, array $order ) {
		$out = array();
		foreach ( $order as $grp ) {
			if ( array_key_exists( $grp, $items ) ) {
				$out[ $grp ] = $items[ $grp ];
			}
		}

		return $out + $items;
	}

	/* ------------------------------------------------------------------ Page */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to see this page.', 'travelstore-enquiries' ), '', array( 'response' => 403 ) );
		}
		$q   = self::query( $_GET, true ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only filters.
		$c   = Insights_Data::context( $q );
		$c['q']['tab'] = $q['tab'];
		$t   = self::tables( $c );
		$tab = $q['tab'];
		$att = $c['attention'];
		?>
		<div class="wrap ts-rep">
			<h1><?php esc_html_e( 'Enquiry insights', 'travelstore-enquiries' ); ?></h1>
			<?php self::notices( $c ); ?>
			<nav class="nav-tab-wrapper ts-rep-noprint">
				<?php foreach ( self::tabs() as $key => $label ) : ?>
					<a class="nav-tab<?php echo $key === $tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::page_url( $c['q'], array( 'tab' => $key ) ) ); ?>"><?php echo esc_html( $label ); ?><?php if ( 'attention' === $key && $att['overdue'] ) : ?> <span class="ts-rep-badge"><?php echo esc_html( number_format_i18n( $att['overdue'] ) ); ?></span><?php endif; ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'attention' !== $tab ) {
				self::filters( $c );
				self::period_line( $c );
			}
			switch ( $tab ) {
				case 'attention':
					self::tab_attention( $c );
					break;
				case 'demand':
					self::tab_demand( $c, $t );
					break;
				case 'marketing':
					self::tab_marketing( $c, $t );
					break;
				case 'pipeline':
					self::tab_pipeline( $c, $t );
					break;
				default:
					self::tab_overview( $c, $t );
			}
			self::footer( $c );
			?>
			<div class="ts-rep-tip" hidden></div>
		</div>
		<?php
		Report_View::script();
	}

	private static function notices( array $c ) {
		$saved = isset( $_GET['ts_saved'] ) ? sanitize_key( wp_unslash( $_GET['ts_saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'groups' === $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Groups saved. The stored answers are unchanged.', 'travelstore-enquiries' ) . '</p></div>';
		}
		if ( 'rebuilt' === $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Insights rebuilt from the enquiries.', 'travelstore-enquiries' ) . '</p></div>';
		}
		global $wpdb;
		$facts = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Facts::facts_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$posts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ( 'trash', 'auto-draft' )", Store::POST_TYPE ) );
		if ( $facts !== $posts ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( /* translators: 1: enquiries counted, 2: enquiries saved */ __( 'Insights counts %1$d of %2$d saved enquiries. Click "Rebuild Insights" at the bottom of the page to bring them all in (the enquiries are only read).', 'travelstore-enquiries' ), $facts, $posts ) ) . '</p></div>';
		}
	}

	private static function filters( array $c ) {
		$q = $c['q'];
		?>
		<form method="get" class="ts-rep-filters ts-rep-noprint">
			<input type="hidden" name="post_type" value="<?php echo esc_attr( Store::POST_TYPE ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="tab" value="<?php echo esc_attr( $q['tab'] ); ?>">
			<label><span><?php esc_html_e( 'Period', 'travelstore-enquiries' ); ?></span>
				<select name="range" class="ts-rep-range">
					<?php foreach ( Report_Data::ranges() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $q['range'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label data-for="week"><span><?php esc_html_e( 'Week', 'travelstore-enquiries' ); ?></span>
				<input type="week" name="week" value="<?php echo esc_attr( 'week' === $q['range'] && '' !== $q['week'] ? $q['week'] : wp_date( 'o-\WW' ) ); ?>">
			</label>
			<label data-for="month"><span><?php esc_html_e( 'Month', 'travelstore-enquiries' ); ?></span>
				<input type="month" name="month" value="<?php echo esc_attr( 'month' === $q['range'] && '' !== $q['month'] ? $q['month'] : wp_date( 'Y-m' ) ); ?>">
			</label>
			<label data-for="custom"><span><?php esc_html_e( 'From', 'travelstore-enquiries' ); ?></span>
				<input type="date" name="from" value="<?php echo esc_attr( 'custom' === $q['range'] ? $c['p']['start'] : '' ); ?>">
			</label>
			<label data-for="custom"><span><?php esc_html_e( 'To', 'travelstore-enquiries' ); ?></span>
				<input type="date" name="to" value="<?php echo esc_attr( 'custom' === $q['range'] ? $c['p']['end'] : '' ); ?>">
			</label>
			<label><span><?php esc_html_e( 'Compare with', 'travelstore-enquiries' ); ?></span>
				<select name="compare">
					<option value="prev" <?php selected( $q['compare'], 'prev' ); ?>><?php esc_html_e( 'The period before', 'travelstore-enquiries' ); ?></option>
					<option value="ly" <?php selected( $q['compare'], 'ly' ); ?>><?php esc_html_e( 'The same dates last year', 'travelstore-enquiries' ); ?></option>
				</select>
			</label>
			<label><span><?php esc_html_e( 'Show', 'travelstore-enquiries' ); ?></span>
				<select name="channel">
					<?php foreach ( self::channels() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $q['channel'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label><span><?php esc_html_e( 'Enquiry type', 'travelstore-enquiries' ); ?></span>
				<select name="type">
					<option value=""><?php esc_html_e( 'All types', 'travelstore-enquiries' ); ?></option>
					<?php foreach ( $c['types'] as $type ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $q['type'], $type ); ?>><?php echo esc_html( $type ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php if ( '' !== $q['by'] ) : ?>
				<input type="hidden" name="by" value="<?php echo esc_attr( $q['by'] ); ?>">
			<?php endif; ?>
			<button class="button button-primary"><?php esc_html_e( 'Show', 'travelstore-enquiries' ); ?></button>
			<button type="button" class="button ts-rep-noprint" onclick="document.querySelectorAll('.ts-rep details').forEach(function(d){d.open=true;});window.print();"><?php esc_html_e( 'Print / Save as PDF', 'travelstore-enquiries' ); ?></button>
		</form>
		<?php
	}

	private static function period_line( array $c ) {
		$p    = $c['p'];
		$text = sprintf( /* translators: 1: dates, 2: timezone */ __( 'Showing %1$s (%2$s time).', 'travelstore-enquiries' ), self::range_text( $p['start'], $p['end'] ), wp_timezone_string() );
		if ( '' !== $p['prev_start'] ) {
			$text .= ' ' . sprintf( /* translators: 1: e.g. the month before, 2: dates */ __( 'Compared with %1$s (%2$s).', 'travelstore-enquiries' ), $p['prev_name'], self::range_text( $p['prev_start'], $p['prev_end'] ) );
		}
		echo '<p class="ts-rep-period">' . esc_html( $text ) . ' <span class="ts-enq-muted">' . esc_html__( 'Every number links to the enquiries behind it.', 'travelstore-enquiries' ) . '</span></p>';
	}

	/** Day / Week / Month switch for the charts over time. */
	private static function by_switch( array $c ) {
		echo '<div class="ts-rep-by ts-rep-noprint" role="group" aria-label="' . esc_attr__( 'Count per', 'travelstore-enquiries' ) . '">';
		foreach ( array( 'day', 'week', 'month' ) as $by ) {
			$on = $by === $c['by'];
			echo '<a class="button' . ( $on ? ' button-primary' : '' ) . '"' . ( $on ? ' aria-current="true"' : '' ) . ' href="' . esc_url( self::page_url( $c['q'], array( 'by' => $by ) ) ) . '">' . esc_html( ucfirst( self::by_word( $by ) ) ) . '</a>';
		}
		echo '</div>';
	}

	/** A key figure; $sub is "+18% vs …" (coloured) or plain text. */
	private static function card( $label, $value, $sub = '', $href = '', $good_when_up = true ) {
		$cls = '';
		if ( preg_match( '/^([+-])\d/', $sub, $m ) ) {
			$cls = ( '+' === $m[1] ) === $good_when_up ? ' up' : ' down';
		}
		$value = '' !== $href ? '<a href="' . esc_url( $href ) . '">' . esc_html( (string) $value ) . '</a>' : esc_html( (string) $value );
		echo '<div class="ts-rep-tile">' . esc_html( $label ) . '<b>' . $value . '</b>' . ( '' !== $sub ? '<span class="cmp' . esc_attr( $cls ) . '">' . esc_html( $sub ) . '</span>' : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	private static function vs_money( array $c, $now, $before ) {
		/* translators: 1: e.g. "+18%", 2: e.g. "the month before", 3: amount then */
		return sprintf( __( '%1$s vs %2$s (%3$s)', 'travelstore-enquiries' ), Report_Data::change( $now, $before ), $c['prev_name'], Insights_Data::money( $before ) );
	}

	private static function vs( array $c, $now, $before ) {
		if ( null === $c['prev'] ) {
			return '';
		}

		/* translators: 1: e.g. "+18%", 2: e.g. "the month before", 3: count then */
		return sprintf( __( '%1$s vs %2$s (%3$s)', 'travelstore-enquiries' ), Report_Data::change( $now, $before ), $c['prev_name'], number_format_i18n( $before ) );
	}

	/* ------------------------------------------------------------------ Overview */

	private static function key_figures( array $c ) {
		$cur  = $c['cur'];
		$prev = $c['prev'];
		$s    = Insights_Data::stage_counts( $cur );
		$sp   = null !== $prev ? Insights_Data::stage_counts( $prev ) : null;
		$rep  = Insights_Data::replies( $cur );
		$open = count( array_filter( $c['all'], static function ( $r ) {
			return 'open' === $r['stage'];
		} ) );
		$att  = $c['attention'];
		$conv = count( $cur ) ? $s['won'] / count( $cur ) : null;
		$cp   = null !== $prev && count( $prev ) ? $sp['won'] / count( $prev ) : null;
		echo '<div class="ts-rep-tiles ts-rep-kpis">';
		self::card( 'all' === $c['channel'] ? __( 'Website contacts', 'travelstore-enquiries' ) : self::channels()[ $c['channel'] ], number_format_i18n( count( $cur ) ), self::vs( $c, count( $cur ), null === $prev ? 0 : count( $prev ) ), self::list_url( $c ) );
		self::card( __( 'Won so far', 'travelstore-enquiries' ), number_format_i18n( $s['won'] ), null !== $sp ? self::vs( $c, $s['won'], $sp['won'] ) : '', self::list_url( $c, array( 'stage' => 'won' ) ) );
		self::card(
			__( 'Conversion (won ÷ received)', 'travelstore-enquiries' ),
			null === $conv ? '—' : round( $conv * 100 ) . '%',
			null !== $cp && null !== $conv ? sprintf( /* translators: 1: change in points, 2: e.g. the month before */ __( '%1$s vs %2$s', 'travelstore-enquiries' ), self::pts( $conv, $cp ), $c['prev_name'] ) : ''
		);
		self::card(
			__( 'Usual first reply (office time)', 'travelstore-enquiries' ),
			null === $rep['median'] ? '—' : Office::duration( $rep['median'] ),
			sprintf( /* translators: 1: target, 2: percent */ __( 'target %1$s · %2$s on time', 'travelstore-enquiries' ), Office::duration( Office::target_minutes() ), self::pct( $rep['ontime'], $rep['replied'] ) ),
			$rep['late'] ? self::list_url( $c, array( 'replied' => 'late' ) ) : ''
		);
		$vn = Insights_Data::value_sum( $cur, 'won' );
		self::card(
			__( 'Won value', 'travelstore-enquiries' ),
			Insights_Data::money( $vn ),
			null !== $prev ? self::vs_money( $c, $vn, Insights_Data::value_sum( $prev, 'won' ) ) : '',
			self::list_url( $c, array( 'stage' => 'won' ) )
		);
		self::card(
			__( 'Open now (any date)', 'travelstore-enquiries' ),
			number_format_i18n( $open ),
			/* translators: 1: waiting, 2: overdue, 3: stuck */
			sprintf( __( '%1$d waiting for a reply (%2$d overdue) · %3$d stuck', 'travelstore-enquiries' ), count( $att['waiting'] ), $att['overdue'], count( $att['stuck'] ) ),
			self::page_url( $c['q'], array( 'tab' => 'attention' ) )
		);
		echo '</div>';
	}

	private static function tab_overview( array $c, array $t ) {
		foreach ( Insights_Data::alerts() as $a ) {
			$link = 'office_failed' === $a['link'] ? ' <a href="' . esc_url( self::list_url( $c, array( 'office_failed' => 1 ) ) ) . '">' . esc_html__( 'See them', 'travelstore-enquiries' ) . ' →</a>' : '';
			echo '<div class="notice notice-' . esc_attr( $a['level'] ) . ' inline ts-rep-alert"><p><strong>' . esc_html__( 'Alert:', 'travelstore-enquiries' ) . '</strong> ' . esc_html( $a['text'] ) . $link . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '<section class="ts-rep-said" aria-labelledby="ts-rep-said-h"><h2 id="ts-rep-said-h">' . esc_html__( 'What changed', 'travelstore-enquiries' ) . '</h2>';
		$said = Insights_Data::sentences( $c );
		if ( ! $said ) {
			echo '<p class="ts-enq-muted">' . esc_html( null === $c['prev'] ? __( 'Choose a period other than All time to see what changed.', 'travelstore-enquiries' ) : __( 'Nothing changed enough to mention: no change of 25% or more with at least 5 enquiries.', 'travelstore-enquiries' ) ) . '</p>';
		} else {
			$icons = array( 'up' => '▲', 'down' => '▼', 'info' => '●', 'alert' => '!' );
			echo '<ul>';
			foreach ( $said as $s ) {
				$link = '';
				if ( null !== $s['filter'] ) {
					$href = isset( $s['filter']['attention'] ) ? self::page_url( $c['q'], array( 'tab' => 'attention' ) ) : self::list_url( $c, $s['filter'] );
					$link = ' <a href="' . esc_url( $href ) . '">' . esc_html__( 'See them', 'travelstore-enquiries' ) . ' →</a>';
				}
				echo '<li class="ts-rep-said--' . esc_attr( $s['dir'] ) . '"><span class="ts-rep-icon" aria-hidden="true">' . esc_html( $icons[ $s['dir'] ] ) . '</span><span>' . esc_html( $s['text'] ) . $link . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul>';
		}
		echo '</section>';

		self::key_figures( $c );

		echo '<div class="ts-rep-cols"><section class="ts-rep-panel">';
		$att = $c['attention'];
		echo '<div class="ts-rep-head"><h2>' . esc_html( sprintf( /* translators: %d: count */ __( 'Waiting for a first reply (%d)', 'travelstore-enquiries' ), count( $att['waiting'] ) ) ) . '</h2><a href="' . esc_url( self::page_url( $c['q'], array( 'tab' => 'attention' ) ) ) . '">' . esc_html__( 'See all', 'travelstore-enquiries' ) . ' →</a></div>';
		self::waiting_list( array_slice( $att['waiting'], 0, 6 ) );
		echo '</section><section class="ts-rep-panel">';
		Report_View::section_head( $t['volume']['title'] );
		self::volume_chart( $c, $t );
		echo '</section></div>';

		echo '<div class="ts-rep-cols"><section class="ts-rep-panel">';
		Report_View::section_head( __( 'Top sources', 'travelstore-enquiries' ) );
		$top = array( 'title' => '', 'head' => array( $t['sources']['head'][0], $t['sources']['head'][1], $t['sources']['head'][4], $t['sources']['head'][5] ), 'num' => array( 1, 2, 3 ), 'rows' => array() );
		foreach ( $t['sources']['rows'] as $row ) {
			if ( count( $top['rows'] ) < 6 ) {
				$top['rows'][] = array( $row[0], $row[1], $row[4], $row[5] );
			}
		}
		Report_View::table( $top );
		echo '<p><a href="' . esc_url( self::page_url( $c['q'], array( 'tab' => 'marketing' ) ) ) . '">' . esc_html__( 'All sources and campaigns', 'travelstore-enquiries' ) . ' →</a></p>';
		echo '</section><section class="ts-rep-panel">';
		Report_View::section_head( __( 'Top types', 'travelstore-enquiries' ) );
		$top = array( 'title' => '', 'head' => array( $t['types']['head'][0], $t['types']['head'][1], $t['types']['head'][3], $t['types']['head'][6] ), 'num' => array( 1, 2, 3 ), 'rows' => array() );
		foreach ( $t['types']['rows'] as $row ) {
			if ( count( $top['rows'] ) < 6 ) {
				$top['rows'][] = array( $row[0], $row[1], $row[3], $row[6] );
			}
		}
		Report_View::table( $top );
		echo '<p><a href="' . esc_url( self::page_url( $c['q'], array( 'tab' => 'demand' ) ) ) . '">' . esc_html__( 'Demand and seasonality', 'travelstore-enquiries' ) . ' →</a></p>';
		echo '</section></div>';
	}

	private static function volume_chart( array $c, array $t ) {
		self::by_switch( $c );
		$series = array();
		if ( 'booking' !== $c['channel'] ) {
			$series[ __( 'Enquiries', 'travelstore-enquiries' ) ] = array( Report_Data::COLOURS[0], array_map( array( Report_View::class, 'cell_value' ), array_column( $t['volume']['rows'], 1 ) ) );
		}
		if ( 'enquiry' !== $c['channel'] ) {
			$series[ __( 'Vehicle hire bookings', 'travelstore-enquiries' ) ] = array( Report_Data::COLOURS[1], array_map( array( Report_View::class, 'cell_value' ), array_column( $t['volume']['rows'], 2 ) ) );
		}
		$links = array();
		foreach ( $t['volume']['rows'] as $i => $row ) {
			$links[ $i ] = $row[3]['href'];
		}
		echo Report_View::legend( $series ) . Report_View::svg_bars( $t['volume']['title'], array_values( $c['buckets'] ), $series, $links ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}

	/* ------------------------------------------------------------------ Needs attention */

	private static function waiting_list( array $rows ) {
		if ( ! $rows ) {
			echo '<p class="ts-rep-ok">✓ ' . esc_html__( 'Nobody is waiting for a first reply.', 'travelstore-enquiries' ) . '</p>';
			return;
		}
		echo '<ul class="ts-rep-wait">';
		foreach ( $rows as $r ) {
			echo '<li><a href="' . esc_url( Store::view_url( $r['id'] ) ) . '">' . esc_html( (string) Store::get( $r['id'], 'name' ) ) . '</a> <span class="ts-enq-muted">· ' . esc_html( $r['type'] ) . '</span> <span class="ts-rep-wait-time' . ( $r['overdue'] ? ' is-late' : '' ) . '">' . esc_html( Office::duration( $r['waited'] ) ) . ( $r['overdue'] ? ' · ' . esc_html__( 'overdue', 'travelstore-enquiries' ) : '' ) . '</span></li>';
		}
		echo '</ul>';
	}

	private static function tab_attention( array $c ) {
		$att      = $c['attention'];
		$statuses = Store::statuses();
		echo '<p class="ts-rep-period">' . esc_html( sprintf( /* translators: 1: target, 2: days */ __( 'Everything open right now, whatever the date. Reply target: %1$s (office hours only). Stuck: no status change for %2$d days. Change these under Enquiries > Settings.', 'travelstore-enquiries' ), Office::duration( Office::target_minutes() ), Office::stuck_days() ) ) . '</p>';

		$sections = array(
			'waiting'    => array(
				/* translators: 1: count, 2: overdue */
				sprintf( __( 'Waiting for a first reply (%1$d, %2$d overdue)', 'travelstore-enquiries' ), count( $att['waiting'] ), $att['overdue'] ),
				array( __( 'Received', 'travelstore-enquiries' ), __( 'Name', 'travelstore-enquiries' ), __( 'Type', 'travelstore-enquiries' ), __( 'Assigned to', 'travelstore-enquiries' ), __( 'Waiting (office time)', 'travelstore-enquiries' ) ),
				__( 'Nobody is waiting for a first reply.', 'travelstore-enquiries' ),
			),
			'stuck'      => array(
				/* translators: %d: count */
				sprintf( __( 'Stuck (%d)', 'travelstore-enquiries' ), count( $att['stuck'] ) ),
				array( __( 'Received', 'travelstore-enquiries' ), __( 'Name', 'travelstore-enquiries' ), __( 'Type', 'travelstore-enquiries' ), __( 'Status', 'travelstore-enquiries' ), __( 'Assigned to', 'travelstore-enquiries' ), __( 'Days without a change', 'travelstore-enquiries' ) ),
				__( 'Nothing is stuck.', 'travelstore-enquiries' ),
			),
			'unassigned' => array(
				/* translators: %d: count */
				sprintf( __( 'Not assigned to anyone (%d)', 'travelstore-enquiries' ), count( $att['unassigned'] ) ),
				array( __( 'Received', 'travelstore-enquiries' ), __( 'Name', 'travelstore-enquiries' ), __( 'Type', 'travelstore-enquiries' ), __( 'Status', 'travelstore-enquiries' ) ),
				__( 'Every open enquiry has someone looking after it.', 'travelstore-enquiries' ),
			),
		);
		foreach ( $sections as $key => $s ) {
			Report_View::section_head( $s[0] );
			$rows = $att[ $key ];
			if ( ! $rows ) {
				echo '<p class="ts-rep-ok">✓ ' . esc_html( $s[2] ) . '</p>';
				continue;
			}
			echo '<div class="ts-rep-scroll"><table class="widefat striped ts-rep-list"><thead><tr>';
			foreach ( $s[1] as $h ) {
				echo '<th scope="col">' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 100 ) as $r ) {
				$received = wp_date( 'D j M Y, H:i', strtotime( $r['received_gmt'] . ' UTC' ) );
				$name     = '<a href="' . esc_url( Store::view_url( $r['id'] ) ) . '"><strong>' . esc_html( (string) Store::get( $r['id'], 'name' ) ) . '</strong></a>';
				$cells    = array( esc_html( $received ), $name, esc_html( $r['type'] ) );
				if ( 'waiting' === $key ) {
					$cells[] = '' !== $r['assigned'] ? esc_html( $r['assigned'] ) : '<span class="ts-enq-muted">—</span>';
					$cells[] = '<span class="ts-rep-wait-time' . ( $r['overdue'] ? ' is-late' : '' ) . '">' . esc_html( Office::duration( $r['waited'] ) ) . ( $r['overdue'] ? ' · ' . esc_html__( 'overdue', 'travelstore-enquiries' ) : '' ) . '</span>';
				} elseif ( 'stuck' === $key ) {
					$cells[] = esc_html( $statuses[ $r['status'] ] ?? $r['status'] );
					$cells[] = '' !== $r['assigned'] ? esc_html( $r['assigned'] ) : '<span class="ts-enq-muted">—</span>';
					$cells[] = esc_html( number_format_i18n( $r['idle_days'] ) );
				} else {
					$cells[] = esc_html( $statuses[ $r['status'] ] ?? $r['status'] );
				}
				echo '<tr><td>' . implode( '</td><td>', $cells ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			}
			echo '</tbody></table></div>';
			if ( count( $rows ) > 100 ) {
				echo '<p><a href="' . esc_url( self::list_url( $c, array( 'attention' => $key ) ) ) . '">' . esc_html( sprintf( /* translators: %d: count */ __( 'See all %d in the enquiries list', 'travelstore-enquiries' ), count( $rows ) ) ) . ' →</a></p>';
			}
		}
	}

	/* ------------------------------------------------------------------ Demand */

	private static function tab_demand( array $c, array $t ) {
		$cur   = $c['cur'];
		$prev  = null === $c['prev'] ? array() : $c['prev'];
		$count = static function ( $records, $channel ) {
			return count( array_filter( $records, static function ( $r ) use ( $channel ) {
				return $r['channel'] === $channel;
			} ) );
		};
		$days  = (int) ( new \DateTime( $c['p']['start'] ) )->diff( new \DateTime( $c['p']['end'] ) )->days + 1;
		$bests = Report_Data::bests( $cur, $c['ws'] );
		echo '<div class="ts-rep-tiles">';
		if ( 'booking' !== $c['channel'] ) {
			self::card( __( 'Enquiries', 'travelstore-enquiries' ), number_format_i18n( $count( $cur, 'enquiry' ) ), self::vs( $c, $count( $cur, 'enquiry' ), $count( $prev, 'enquiry' ) ), self::list_url( $c, array( 'channel' => 'enquiry' ) ) );
		}
		if ( 'enquiry' !== $c['channel'] ) {
			self::card( __( 'Vehicle hire bookings', 'travelstore-enquiries' ), number_format_i18n( $count( $cur, 'booking' ) ), self::vs( $c, $count( $cur, 'booking' ), $count( $prev, 'booking' ) ), self::list_url( $c, array( 'channel' => 'booking' ) ) );
		}
		self::card( __( 'Average per day', 'travelstore-enquiries' ), number_format_i18n( count( $cur ) / max( 1, $days ), 1 ) );
		$ties = static function ( $best ) {
			/* translators: %d: number of others with the same count */
			return $best[2] ? sprintf( _n( 'tied with %d other', 'tied with %d others', $best[2], 'travelstore-enquiries' ), $best[2] ) : '';
		};
		$b = $bests['day'];
		self::card( __( 'Top day', 'travelstore-enquiries' ), $b ? self::day_text( $b[0] ) . ' · ' . $b[1] : '—', $b ? $ties( $b ) : '', $b ? self::list_url( $c, array( 'from' => $b[0], 'to' => $b[0] ) ) : '' );
		$b = $bests['week'];
		if ( $b ) {
			list( $wf, $wt ) = self::bucket_span( $b[0], 'week', $c['p'] );
		}
		self::card( __( 'Best week', 'travelstore-enquiries' ), $b ? sprintf( /* translators: %s: date */ __( 'Week of %s', 'travelstore-enquiries' ), self::day_text( $b[0], 'j M Y' ) ) . ' · ' . $b[1] : '—', $b ? $ties( $b ) : '', $b ? self::list_url( $c, array( 'from' => $wf, 'to' => $wt ) ) : '' );
		$b = $bests['month'];
		self::card( __( 'Best month', 'travelstore-enquiries' ), $b ? self::day_text( $b[0] . '-01', 'F Y' ) . ' · ' . $b[1] : '—', $b ? $ties( $b ) : '', $b ? self::list_url( $c, array( 'month' => $b[0] ) ) : '' );
		echo '</div>';

		Report_View::section_head( $t['volume']['title'], self::csv_url( $c, 'volume' ) );
		self::volume_chart( $c, $t );
		Report_View::table( $t['volume'], count( $c['buckets'] ) <= 14 );

		Report_View::section_head( $t['weekdays']['title'], self::csv_url( $c, 'weekdays' ), __( '"Average per day" divides by how many of that weekday the period has, so five Mondays and four Sundays are still compared fairly.', 'travelstore-enquiries' ) );
		$links = array();
		foreach ( $t['weekdays']['rows'] as $i => $row ) {
			$links[ $i ] = $row[1]['href'];
		}
		echo Report_View::svg_bars( $t['weekdays']['title'], array_column( $t['weekdays']['rows'], 0 ), array( __( 'Contacts', 'travelstore-enquiries' ) => array( Report_Data::COLOURS[0], array_map( array( Report_View::class, 'cell_value' ), array_column( $t['weekdays']['rows'], 1 ) ) ) ), $links ); // phpcs:ignore WordPress.Security.EscapeOutput
		Report_View::table( $t['weekdays'], false );

		Report_View::section_head( __( 'Day of the week and time of day', 'travelstore-enquiries' ), '', __( 'Darker = more enquiries sent at that hour (site time). Click a square to see them.', 'travelstore-enquiries' ) );
		$grid = array();
		foreach ( $cur as $r ) {
			$grid[ (int) $r['weekday'] ][ (int) $r['hour'] ] = ( $grid[ (int) $r['weekday'] ][ (int) $r['hour'] ] ?? 0 ) + 1;
		}
		$order = array_keys( Report_Data::weekdays( array(), $c['p']['start'], $c['p']['start'], $c['ws'] ) );
		echo Report_View::svg_heat( __( 'Enquiries by day of the week and hour', 'travelstore-enquiries' ), $grid, $order, static function ( $d, $h ) use ( $c ) { // phpcs:ignore WordPress.Security.EscapeOutput
			return self::list_url( $c, array( 'weekday' => $d, 'hour' => $h ) );
		} );

		Report_View::section_head( $t['types']['title'], self::csv_url( $c, 'types' ) );
		$items = array();
		foreach ( $t['types']['rows'] as $row ) {
			$items[ $row[0] ] = array( $c['colours']['type'][ $row[0] ] ?? Report_Data::GREY, $row[1]['v'], $row[1]['href'] );
		}
		echo Report_View::svg_hbars( $t['types']['title'], $items, count( $cur ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		Report_View::table( $t['types'] );

		Report_View::section_head( $t['types_trend']['title'], self::csv_url( $c, 'types_trend' ) );
		self::by_switch( $c );
		self::trend_chart( $c, $t['types_trend'], 'type' );
		Report_View::table( $t['types_trend'], false );

		Report_View::section_head( __( 'Seasonality: enquiries per month and type', 'travelstore-enquiries' ), self::csv_url( $c, 'types_months' ), __( 'Darker = busier. Each month is also compared with the month before and the same month last year, so seasonal ups and downs are not mistaken for trends. Part months count only the days inside the period.', 'travelstore-enquiries' ) );
		self::season_grid( $c, $t['types_months'] );
		Report_View::table( $t['types_months'], false, true );

		Report_View::groups_form( $c, 'type', self::page_url( $c['q'] ) );
	}

	/** Months × types as a shaded grid (darker = more). */
	private static function season_grid( array $c, array $table ) {
		if ( ! $table['rows'] ) {
			return;
		}
		$types = array();
		foreach ( $table['head'] as $i => $h ) {
			if ( $i > 0 && 1 === $i % 2 && $i < count( $table['head'] ) - 3 ) {
				$types[ $i ] = $h;
			}
		}
		$max = 1;
		foreach ( $table['rows'] as $row ) {
			foreach ( array_keys( $types ) as $i ) {
				$max = max( $max, (int) Report_View::cell_value( $row[ $i ] ) );
			}
		}
		echo '<div class="ts-rep-scroll"><table class="ts-rep-season"><thead><tr><th scope="col">' . esc_html__( 'Month', 'travelstore-enquiries' ) . '</th>';
		foreach ( $types as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '<th scope="col">' . esc_html__( 'Total', 'travelstore-enquiries' ) . '</th><th scope="col">' . esc_html__( 'Last year', 'travelstore-enquiries' ) . '</th></tr></thead><tbody>';
		$n = count( $table['head'] );
		foreach ( $table['rows'] as $row ) {
			echo '<tr><th scope="row">' . esc_html( (string) $row[0] ) . '</th>';
			foreach ( array_keys( $types ) as $i ) {
				$v     = (int) Report_View::cell_value( $row[ $i ] );
				$alpha = $v ? round( 0.12 + 0.88 * $v / $max, 2 ) : 0;
				$style = $v ? 'background:rgba(42,120,214,' . $alpha . ');' . ( $alpha > 0.55 ? 'color:#fff;' : '' ) : '';
				$text  = $v ? ( is_array( $row[ $i ] ) && $row[ $i ]['href'] ? '<a href="' . esc_url( $row[ $i ]['href'] ) . '"' . ( $alpha > 0.55 ? ' style="color:#fff"' : '' ) . '>' . esc_html( number_format_i18n( $v ) ) . '</a>' : esc_html( number_format_i18n( $v ) ) ) : '<span class="ts-enq-muted">·</span>';
				echo '<td style="' . esc_attr( $style ) . '" title="' . esc_attr( $row[ $i + 1 ] ?? '' ) . '">' . $text . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<td><strong>' . esc_html( number_format_i18n( (int) Report_View::cell_value( $row[ $n - 3 ] ) ) ) . '</strong></td><td class="ts-enq-muted">' . esc_html( number_format_i18n( (int) $row[ $n - 1 ] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function trend_chart( array $c, array $table, $kind ) {
		$series = array();
		foreach ( $table['series'] as $grp => $values ) {
			if ( 0 === strpos( $grp, '__' ) ) {
				continue;
			}
			$series[ self::label( $grp ) ] = array( $c['colours'][ $kind ][ $grp ] ?? Report_Data::GREY, array_values( $values ) );
		}
		echo Report_View::legend( $series ) . Report_View::svg_lines( $table['title'], array_values( $c['buckets'] ), $series ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/* ------------------------------------------------------------------ Marketing */

	private static function tab_marketing( array $c, array $t ) {
		Report_View::section_head( $t['sources']['title'], self::csv_url( $c, 'sources' ), __( '"How did you hear about us?" is asked on the full enquiry form only, and is optional. The flyout, Alia and hire bookings do not ask it ("Not asked"), so "Share of answers" shows the split among people who answered. Conversion = won ÷ contacts.', 'travelstore-enquiries' ) );
		$items = array();
		foreach ( self::in_order( Report_Data::counts( $c['cur'], 'heard', $c['groups'], $c['names'] ), $c['heard'] ) as $grp => $n ) {
			$items[ self::label( $grp ) ] = array( $c['colours']['heard'][ $grp ] ?? Report_Data::GREY, $n, self::list_url( $c, array( 'heard' => $grp ) ) );
		}
		echo Report_View::svg_hbars( $t['sources']['title'], $items, count( $c['cur'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		Report_View::table( $t['sources'] );

		Report_View::section_head( $t['sources_trend']['title'], self::csv_url( $c, 'sources_trend' ), __( 'Lines show the answers only; "Not asked" and "Not answered" are in the table.', 'travelstore-enquiries' ) );
		self::by_switch( $c );
		self::trend_chart( $c, $t['sources_trend'], 'heard' );
		Report_View::table( $t['sources_trend'], false );

		$since = Insights_Data::visit_since();
		Report_View::section_head(
			$t['visit']['title'],
			self::csv_url( $c, 'visit' ),
			'' === $since
				? __( 'Recorded automatically from TS Enquiries 1.3.0 on (first page, referring site, campaign tags, ad clicks), for every way of enquiring. Nothing is recorded yet; enquiries from before are "Not recorded".', 'travelstore-enquiries' )
				/* translators: %s: date */
				: sprintf( __( 'Recorded automatically for every way of enquiring since %s (first page, referring site, campaign tags, ad clicks). Earlier enquiries are "Not recorded".', 'travelstore-enquiries' ), self::day_text( $since, 'j M Y' ) )
		);
		$items  = array();
		$labels = Visit::channels();
		$i      = 0;
		foreach ( $t['visit']['rows'] as $row ) {
			$key              = array_search( $row[0], $labels, true );
			$items[ $row[0] ] = array( '' === $key ? Report_Data::GREY : Report_Data::COLOURS[ $i++ % count( Report_Data::COLOURS ) ], $row[1]['v'], $row[1]['href'] );
		}
		echo Report_View::svg_hbars( $t['visit']['title'], $items, count( $c['cur'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		Report_View::table( $t['visit'] );

		Report_View::section_head( $t['campaigns']['title'], self::csv_url( $c, 'campaigns' ), __( 'Links with campaign tags (utm_campaign, or utm_source / utm_medium) in adverts, newsletters and social posts show up here.', 'travelstore-enquiries' ) );
		Report_View::table( $t['campaigns'], true, false, __( 'No enquiries came from tagged links in this period.', 'travelstore-enquiries' ) );
		Report_View::section_head( $t['landing']['title'], self::csv_url( $c, 'landing' ) );
		Report_View::table( $t['landing'], true, false, __( 'No first pages recorded in this period.', 'travelstore-enquiries' ) );

		Report_View::section_head( $t['cross']['title'], self::csv_url( $c, 'cross' ), __( 'Which channels bring which kind of enquiry.', 'travelstore-enquiries' ) );
		Report_View::table( $t['cross'], true, true );
		Report_View::section_head( $t['cross_visit']['title'], self::csv_url( $c, 'cross_visit' ), __( 'Enquirers who answered "How did you hear" and whose visit was recorded. Many people find you on social media first and then search for your name: both are true.', 'travelstore-enquiries' ) );
		Report_View::table( $t['cross_visit'], true, true, __( 'Not enough enquiries with both answers yet.', 'travelstore-enquiries' ) );

		Report_View::groups_form( $c, 'heard', self::page_url( $c['q'] ) );
	}

	/* ------------------------------------------------------------------ Pipeline */

	private static function tab_pipeline( array $c, array $t ) {
		$cur  = $c['cur'];
		$s    = Insights_Data::stage_counts( $cur );
		$rep  = Insights_Data::replies( $cur );
		$n    = count( $cur );
		$days = array();
		foreach ( $cur as $r ) {
			if ( 'won' === $r['stage'] && $r['won_gmt'] ) {
				$days[] = max( 0, ( strtotime( $r['won_gmt'] . ' UTC' ) - strtotime( $r['received_gmt'] . ' UTC' ) ) / DAY_IN_SECONDS );
			}
		}
		$med = Insights_Data::median( $days );
		echo '<p class="description ts-rep-intro">' . esc_html__( 'Enquiries received in the period and where they are now. Won = confirmed, booked or payment received; lost = dropped or rejected; "Closed" counts as won if the enquiry was ever confirmed or booked, otherwise as lost. Recent periods still have many open enquiries, so their conversion grows over time.', 'travelstore-enquiries' ) . '</p>';
		echo '<div class="ts-rep-tiles">';
		self::card( __( 'Received', 'travelstore-enquiries' ), number_format_i18n( $n ), '', self::list_url( $c ) );
		self::card( __( 'Replied', 'travelstore-enquiries' ), number_format_i18n( $rep['replied'] ), self::pct( $rep['replied'], $n ) . ' ' . __( 'of received', 'travelstore-enquiries' ), $rep['none'] ? self::list_url( $c, array( 'replied' => 'none' ) ) : '' );
		self::card( __( 'Won', 'travelstore-enquiries' ), number_format_i18n( $s['won'] ), self::pct( $s['won'], $n ) . ' ' . __( 'of received', 'travelstore-enquiries' ), self::list_url( $c, array( 'stage' => 'won' ) ) );
		self::card( __( 'Lost', 'travelstore-enquiries' ), number_format_i18n( $s['lost'] ), self::pct( $s['lost'], $n ) . ' ' . __( 'of received', 'travelstore-enquiries' ), self::list_url( $c, array( 'stage' => 'lost' ) ) );
		self::card( __( 'Still open', 'travelstore-enquiries' ), number_format_i18n( $s['open'] ), '', self::list_url( $c, array( 'stage' => 'open' ) ) );
		self::card( __( 'Win rate (of decided)', 'travelstore-enquiries' ), self::pct( $s['won'], $s['won'] + $s['lost'] ), __( 'won ÷ (won + lost)', 'travelstore-enquiries' ) );
		$won_value  = Insights_Data::value_sum( $cur, 'won' );
		$open_value = Insights_Data::value_sum( $c['all'], 'open' );
		self::card( __( 'Won value', 'travelstore-enquiries' ), Insights_Data::money( $won_value ), $s['won'] ? sprintf( /* translators: %s: amount */ __( 'average %s per won enquiry', 'travelstore-enquiries' ), Insights_Data::money( $won_value / $s['won'] ) ) : '', self::list_url( $c, array( 'stage' => 'won' ) ) );
		self::card( __( 'Open pipeline (any date)', 'travelstore-enquiries' ), Insights_Data::money( $open_value ), __( 'value entered on open enquiries', 'travelstore-enquiries' ), self::list_url( $c, array( 'attention' => 'open' ) ) );
		self::card( __( 'Usual time to win', 'travelstore-enquiries' ), null === $med ? '—' : sprintf( /* translators: %s: days */ _n( '%s day', '%s days', (int) ceil( $med ), 'travelstore-enquiries' ), number_format_i18n( $med, 1 ) ), __( 'from enquiry to confirmed or booked', 'travelstore-enquiries' ) );
		echo '</div>';

		Report_View::section_head( __( 'From enquiry to booking', 'travelstore-enquiries' ) );
		$items = array(
			__( 'Received', 'travelstore-enquiries' )   => array( Report_Data::COLOURS[0], $n, self::list_url( $c ) ),
			__( 'Replied', 'travelstore-enquiries' )    => array( Report_Data::COLOURS[0], $rep['replied'], '' ),
			__( 'Won', 'travelstore-enquiries' )        => array( Report_Data::COLOURS[2], $s['won'], self::list_url( $c, array( 'stage' => 'won' ) ) ),
			__( 'Lost', 'travelstore-enquiries' )       => array( Report_Data::COLOURS[1], $s['lost'], self::list_url( $c, array( 'stage' => 'lost' ) ) ),
			__( 'Still open', 'travelstore-enquiries' ) => array( Report_Data::GREY, $s['open'], self::list_url( $c, array( 'stage' => 'open' ) ) ),
		);
		echo Report_View::svg_hbars( __( 'From enquiry to booking', 'travelstore-enquiries' ), $items, $n ); // phpcs:ignore WordPress.Security.EscapeOutput

		foreach ( array( 'pipe_types', 'pipe_sources', 'pipe_visit' ) as $name ) {
			Report_View::section_head( $t[ $name ]['title'], self::csv_url( $c, $name ) );
			Report_View::table( $t[ $name ] );
		}

		Report_View::section_head( $t['reasons']['title'], self::csv_url( $c, 'reasons' ), __( 'Staff choose a reason when they set an enquiry to Dropped, Rejected by client or Closed without a booking. The list of reasons is under Enquiries > Settings.', 'travelstore-enquiries' ) );
		$items = array();
		foreach ( $t['reasons']['rows'] as $i => $row ) {
			$items[ $row[0] ] = array( __( 'No reason given', 'travelstore-enquiries' ) === $row[0] ? Report_Data::GREY : Report_Data::COLOURS[1], $row[1]['v'], $row[1]['href'] );
		}
		echo Report_View::svg_hbars( $t['reasons']['title'], $items, $s['lost'] ); // phpcs:ignore WordPress.Security.EscapeOutput
		Report_View::table( $t['reasons'], true, false, __( 'No lost enquiries in this period.', 'travelstore-enquiries' ) );

		Report_View::section_head( $t['ages']['title'], self::csv_url( $c, 'ages' ), __( 'Every open enquiry now, whatever the period above.', 'travelstore-enquiries' ) );
		Report_View::table( $t['ages'] );
	}

	/* ------------------------------------------------------------------ Footer, CSV, rebuild */

	private static function footer( array $c ) {
		$tests   = Insights_Data::count_tests();
		$samples = Insights_Data::count_samples();
		echo '<div class="ts-rep-foot ts-rep-noprint">';
		if ( 'attention' !== $c['q']['tab'] ) {
			echo '<p><a class="button" href="' . esc_url( self::csv_url( $c, 'records' ) ) . '">' . esc_html__( 'Download the enquiries of this view (CSV, one row each)', 'travelstore-enquiries' ) . '</a></p>';
		}
		$notes = array();
		if ( $tests ) {
			/* translators: %d: count */
			$notes[] = '<a href="' . esc_url( self::list_url( $c, array( 'test' => 1 ) ) ) . '">' . esc_html( sprintf( _n( '%d enquiry marked as test or spam', '%d enquiries marked as test or spam', $tests, 'travelstore-enquiries' ), $tests ) ) . '</a> ' . esc_html__( 'is left out of every figure.', 'travelstore-enquiries' );
		}
		if ( $samples ) {
			/* translators: %d: count */
			$notes[] = esc_html( sprintf( __( 'The figures include %d sample enquiries made for testing (titles start with "[Sample]").', 'travelstore-enquiries' ), $samples ) );
		}
		$notes[] = '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Store::POST_TYPE . '&page=' . Settings::PAGE . '#insights' ) ) . '">' . esc_html__( 'Office hours, reply target and lost reasons', 'travelstore-enquiries' ) . '</a>';
		echo '<p class="ts-enq-muted">' . implode( ' · ', $notes ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::REBUILD ) . '">';
		wp_nonce_field( self::REBUILD );
		echo '<button class="button button-small">' . esc_html__( 'Rebuild Insights', 'travelstore-enquiries' ) . '</button> <span class="description">' . esc_html__( 'Reads every enquiry again (they are not changed). Only needed if figures look out of date.', 'travelstore-enquiries' ) . '</span></form>';
		echo '</div>';
	}

	public static function csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'travelstore-enquiries' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::CSV_ACTION );
		$c     = Insights_Data::context( self::query( $_GET ) );
		$which = isset( $_GET['table'] ) ? sanitize_key( wp_unslash( $_GET['table'] ) ) : '';
		if ( 'records' === $which ) {
			$statuses = Store::statuses();
			$labels   = Visit::channels();
			$rows     = array();
			foreach ( $c['cur'] as $r ) {
				$rows[] = array(
					$r['id'],
					$r['day'],
					sprintf( '%02d:00', $r['hour'] ),
					self::day_text( $r['day'], 'l' ),
					'booking' === $r['channel'] ? __( 'Vehicle hire booking', 'travelstore-enquiries' ) : __( 'Enquiry', 'travelstore-enquiries' ),
					$r['type'],
					Report_Data::group( 'type', $r['type'], $c['groups'], $c['names'] ),
					null === $r['heard'] ? '' : $r['heard'],
					self::label( Report_Data::group( 'heard', $r['heard'], $c['groups'], $c['names'] ) ),
					$labels[ $r['visit_channel'] ] ?? $r['visit_channel'],
					self::campaign_of( $r ),
					$r['landing'],
					$statuses[ $r['status'] ] ?? $r['status'],
					self::stages()[ $r['stage'] ] ?? $r['stage'],
					$r['reason'],
					$r['assigned'],
					null === $r['reply_minutes'] ? '' : (int) $r['reply_minutes'],
					$r['country'],
					null === $r['value'] ? '' : number_format( (float) $r['value'], 3, '.', '' ),
				);
			}
			$table = array(
				'head' => array( 'ID', __( 'Received (site time)', 'travelstore-enquiries' ), __( 'Hour', 'travelstore-enquiries' ), __( 'Weekday', 'travelstore-enquiries' ), __( 'Channel', 'travelstore-enquiries' ), __( 'Type as sent', 'travelstore-enquiries' ), __( 'Type counted as', 'travelstore-enquiries' ), __( 'Heard about us (as sent)', 'travelstore-enquiries' ), __( 'Source counted as', 'travelstore-enquiries' ), __( 'Recorded channel', 'travelstore-enquiries' ), __( 'Campaign', 'travelstore-enquiries' ), __( 'First page', 'travelstore-enquiries' ), __( 'Status', 'travelstore-enquiries' ), __( 'Outcome', 'travelstore-enquiries' ), __( 'Lost reason', 'travelstore-enquiries' ), __( 'Assigned to', 'travelstore-enquiries' ), __( 'First reply (office minutes)', 'travelstore-enquiries' ), __( 'Country', 'travelstore-enquiries' ), __( 'Value (OMR)', 'travelstore-enquiries' ) ),
				'rows' => $rows,
			);
		} else {
			$tables = self::tables( $c );
			if ( ! isset( $tables[ $which ] ) ) {
				wp_die( esc_html__( 'Unknown report.', 'travelstore-enquiries' ) );
			}
			$table = $tables[ $which ];
		}
		Report_View::csv_send( $table, sprintf( 'enquiries-%s-%s-to-%s.csv', 'records' === $which ? 'list' : str_replace( '_', '-', $which ), $c['p']['start'], $c['p']['end'] ) );
	}

	/** Rebuild the facts in batches of 300 per request, so a large site never times out. */
	public static function rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'travelstore-enquiries' ), '', array( 'response' => 403 ) );
		}
		$offset = isset( $_REQUEST['offset'] ) ? absint( $_REQUEST['offset'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- checked next.
		check_admin_referer( self::REBUILD );
		$r = Facts::rebuild( $offset, 300 );
		if ( $r['done'] > 0 && $r['next'] < $r['total'] ) {
			wp_safe_redirect( wp_nonce_url( add_query_arg( array( 'action' => self::REBUILD, 'offset' => $r['next'] ), admin_url( 'admin-post.php' ) ), self::REBUILD ) );
			exit;
		}
		wp_safe_redirect( self::page_url( array(), array( 'ts_saved' => 'rebuilt' ) ) );
		exit;
	}
}
