<?php
/**
 * Weekly summary email (1.3.0): every Sunday at the daily summary hour, to the daily summary address.
 * The last 7 days against the 7 before: what changed (the Insights sentences), the key figures, and
 * the enquiries waiting longer than the reply target. Plain text, like the daily summary.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Weekly {

	const HOOK = 'ts_enquiries_weekly_summary';

	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
		add_action( 'init', array( __CLASS__, 'ensure' ) );
	}

	public static function ensure() {
		if ( 'yes' === Settings::get( 'weekly_on' ) && ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedule() {
		$tz   = wp_timezone();
		$hour = (int) Settings::get( 'digest_hour' );
		$now  = new \DateTimeImmutable( 'now', $tz );
		// Today if it is Sunday before the hour, otherwise next Sunday.
		$next = ( 0 === (int) $now->format( 'w' ) && (int) $now->format( 'G' ) < $hour ? $now : $now->modify( 'next sunday' ) )->setTime( $hour, 0 );
		wp_schedule_event( $next->getTimestamp(), 'weekly', self::HOOK );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function reschedule() {
		self::unschedule();
		if ( 'yes' === Settings::get( 'weekly_on' ) ) {
			self::schedule();
		}
	}

	/** The email for the 7 days before $today (site time). @return array subject, body. */
	public static function compose( $today = null ) {
		$today = $today ? $today : wp_date( 'Y-m-d' );
		$to    = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) - DAY_IN_SECONDS );
		$from  = gmdate( 'Y-m-d', strtotime( $today . ' UTC' ) - 7 * DAY_IN_SECONDS );
		$c     = Insights_Data::context( array( 'range' => 'custom', 'from' => $from, 'to' => $to ), $today );
		$cur   = $c['cur'];
		$prev  = $c['prev'];
		$s     = Insights_Data::stage_counts( $cur );
		$rep   = Insights_Data::replies( $cur );
		$att   = $c['attention'];
		$range = date_i18n( 'j M', strtotime( $from . ' UTC' ), true ) . ' – ' . date_i18n( 'j M Y', strtotime( $to . ' UTC' ), true );

		$subject = sprintf(
			/* translators: 1: dates, 2: count, 3: change */
			__( 'Weekly enquiry summary – %1$s – %2$d website contacts (%3$s)', 'travelstore-enquiries' ),
			$range,
			count( $cur ),
			Report_Data::change( count( $cur ), count( $prev ) )
		);
		$lines   = array();
		$lines[] = sprintf( /* translators: %s: dates */ __( 'Your week in enquiries, %s (compared with the 7 days before).', 'travelstore-enquiries' ), $range );
		$lines[] = '';
		$lines[] = __( 'WHAT CHANGED', 'travelstore-enquiries' );
		$said    = Insights_Data::sentences( $c, 6 );
		if ( ! $said ) {
			$lines[] = '- ' . __( 'Nothing changed enough to mention.', 'travelstore-enquiries' );
		}
		foreach ( $said as $sentence ) {
			$lines[] = '- ' . $sentence['text'];
		}
		$lines[] = '';
		$lines[] = __( 'KEY FIGURES', 'travelstore-enquiries' );
		$lines[] = sprintf( __( 'Website contacts: %1$d (before: %2$d)', 'travelstore-enquiries' ), count( $cur ), count( $prev ) );
		$lines[] = sprintf( __( 'Won so far: %1$d · lost: %2$d · still open: %3$d', 'travelstore-enquiries' ), $s['won'], $s['lost'], $s['open'] );
		$lines[] = sprintf( __( 'Won value: %1$s (before: %2$s)', 'travelstore-enquiries' ), Insights_Data::money( Insights_Data::value_sum( $cur, 'won' ) ), Insights_Data::money( Insights_Data::value_sum( $prev, 'won' ) ) );
		$lines[] = null === $rep['median']
			? __( 'First reply: none recorded yet', 'travelstore-enquiries' )
			: sprintf( __( 'Usual first reply: %1$s (target %2$s); %3$d of %4$d on time', 'travelstore-enquiries' ), Office::duration( $rep['median'] ), Office::duration( Office::target_minutes() ), $rep['ontime'], $rep['replied'] );
		$lines[] = '';
		$lines[] = sprintf( __( 'WAITING FOR A FIRST REPLY NOW (%1$d, %2$d over the target)', 'travelstore-enquiries' ), count( $att['waiting'] ), $att['overdue'] );
		foreach ( array_slice( $att['waiting'], 0, 10 ) as $r ) {
			$lines[] = sprintf( '- %1$s – %2$s – %3$s%4$s', (string) Store::get( $r['id'], 'name' ), $r['type'], Office::duration( $r['waited'] ), $r['overdue'] ? ' – ' . __( 'OVERDUE', 'travelstore-enquiries' ) : '' );
			$lines[] = '  ' . Store::view_url( $r['id'] );
		}
		if ( count( $att['waiting'] ) > 10 ) {
			$lines[] = sprintf( __( '…and %d more.', 'travelstore-enquiries' ), count( $att['waiting'] ) - 10 );
		}
		$lines[] = '';
		$lines[] = __( 'All figures and charts:', 'travelstore-enquiries' ) . ' ' . Reports::page_url( array() );

		return array(
			'subject' => $subject,
			'body'    => implode( "\n", $lines ),
		);
	}

	public static function send() {
		if ( 'yes' !== Settings::get( 'weekly_on' ) ) {
			return false;
		}
		$mail   = self::compose();
		$result = Mailer::send( Digest::recipient(), $mail['subject'], $mail['body'] );

		return $result['ok'];
	}
}
