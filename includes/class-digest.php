<?php
/**
 * Daily summary email (safety net, as on the BDH site): the enquiries received since the last summary,
 * and every enquiry still waiting for an acknowledgement. Sent every day, also when there is nothing
 * new, so a missing summary shows that email (or the site) needs checking. If sending fails, the next
 * summary covers the same period again.
 *
 * Runs on WP-Cron, so it is sent on the first visit to the site after the chosen hour (site time zone).
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Digest {

	const HOOK       = 'ts_enquiries_daily_summary';
	const LAST_RUN   = 'ts_enquiries_summary_last_run';
	const LAST_ERROR = 'ts_enquiries_summary_last_error';

	public static function register() {
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
	}

	public static function schedule() {
		if ( 'yes' !== Settings::get( 'digest_on' ) ) {
			return;
		}
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$hour = (int) Settings::get( 'digest_hour' );
		$next = new \DateTimeImmutable( sprintf( 'today %02d:00', $hour ), wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next = $next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * @return bool Whether the summary was handed to the mail system.
	 */
	public static function send() {
		if ( 'yes' !== Settings::get( 'digest_on' ) || get_transient( 'ts_enquiries_summary_lock' ) ) {
			return false;
		}
		set_transient( 'ts_enquiries_summary_lock', 1, 10 * MINUTE_IN_SECONDS );

		$now     = time();
		$since   = (int) get_option( self::LAST_RUN, $now - DAY_IN_SECONDS );
		$new     = self::query( array( 'date_query' => array( array( 'column' => 'post_date_gmt', 'after' => gmdate( 'Y-m-d H:i:s', $since ) ) ) ) );
		$waiting = self::query(
			array(
				'meta_query' => array(
					array(
						'key'     => Store::META . 'status',
						'value'   => array( 'new', 'assigned' ),
						'compare' => 'IN',
					),
				),
			)
		);

		$subject = sprintf(
			/* translators: 1: date, 2: number of new enquiries, 3: number waiting. */
			__( 'Enquiries summary – %1$s – %2$d new, %3$d waiting for an acknowledgement', 'travelstore-enquiries' ),
			wp_date( 'j M Y', $now ),
			count( $new ),
			count( $waiting )
		);
		$result = Mailer::send( self::recipient(), $subject, self::body( $new, $waiting, $since, $now ) );
		if ( $result['ok'] ) {
			update_option( self::LAST_RUN, $now, false );
			delete_option( self::LAST_ERROR );
		} else {
			update_option( self::LAST_ERROR, array( 'time' => $now, 'error' => $result['error'] ), false );
		}
		delete_transient( 'ts_enquiries_summary_lock' );

		return $result['ok'];
	}

	public static function recipient() {
		$email = sanitize_email( (string) Settings::get( 'digest_email' ) );

		return is_email( $email ) ? $email : Settings::office_email();
	}

	private static function query( array $args ) {
		return get_posts(
			array_merge(
				array(
					'post_type'      => Store::POST_TYPE,
					'post_status'    => 'private',
					'posts_per_page' => 200,
					'orderby'        => 'date',
					'order'          => 'ASC',
				),
				$args
			)
		);
	}

	private static function line( $post ) {
		$id     = $post->ID;
		$status = Store::statuses()[ Store::status( $id ) ];
		$staff  = (string) Store::get( $id, 'assigned' );
		$office = 'failed' === Store::get( $id, 'office_status' ) ? ' – ' . __( 'OFFICE EMAIL FAILED', 'travelstore-enquiries' ) : '';

		return array(
			sprintf( '- %1$s – %2$s (%3$s)%4$s', wp_date( 'j M, H:i', strtotime( $post->post_date_gmt . ' UTC' ) ), (string) Store::get( $id, 'name' ), (string) Store::get( $id, 'type' ), $office ),
			sprintf( '  %1$s: %2$s%3$s', __( 'Status', 'travelstore-enquiries' ), $status, '' !== $staff ? ' – ' . sprintf( __( 'assigned to %s', 'travelstore-enquiries' ), $staff ) : '' ),
			'  ' . Store::view_url( $id ),
		);
	}

	/**
	 * "IN THE PIPELINE": how many enquiries sit at each step, and with whom (only the ones still being worked on).
	 */
	private static function pipeline_lines() {
		$statuses = Store::statuses();
		$lines    = array( __( 'IN THE PIPELINE (being worked on)', 'travelstore-enquiries' ) );
		$total    = 0;
		foreach ( Store::active_statuses() as $key ) {
			$ids = self::query(
				array(
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'meta_query'     => array( array( 'key' => Store::META . 'status', 'value' => $key ) ),
				)
			);
			// Enquiries saved before the status was stored count as New.
			if ( ! $ids ) {
				continue;
			}
			$by = array();
			foreach ( $ids as $id ) {
				$who          = (string) Store::get( $id, 'assigned' );
				$who          = '' !== $who ? $who : __( 'nobody yet', 'travelstore-enquiries' );
				$by[ $who ]   = isset( $by[ $who ] ) ? $by[ $who ] + 1 : 1;
			}
			$parts = array();
			foreach ( $by as $who => $n ) {
				$parts[] = $who . ' ' . $n;
			}
			$total  += count( $ids );
			$lines[] = sprintf( '- %1$s: %2$d (%3$s)', $statuses[ $key ], count( $ids ), implode( ', ', $parts ) );
		}
		if ( ! $total ) {
			$lines[] = __( 'Nothing is being worked on.', 'travelstore-enquiries' );
		}

		return $lines;
	}

	private static function body( array $new, array $waiting, $since, $now ) {
		$format = 'j M Y, H:i';
		$lines  = array(
			sprintf( __( '%s – daily enquiries summary', 'travelstore-enquiries' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			sprintf( __( 'Period: %1$s to %2$s (%3$s)', 'travelstore-enquiries' ), wp_date( $format, $since ), wp_date( $format, $now ), wp_timezone_string() ),
			'',
		);
		$lines[] = sprintf( __( 'NEW ENQUIRIES: %d', 'travelstore-enquiries' ), count( $new ) );
		if ( empty( $new ) ) {
			$lines[] = __( 'No new enquiries in this period.', 'travelstore-enquiries' );
		} else {
			$lines[] = __( 'Each one was also emailed to the office when it arrived. If one never reached the inbox, check its Spam folder.', 'travelstore-enquiries' );
			foreach ( $new as $post ) {
				$lines = array_merge( $lines, array( '' ), self::line( $post ) );
			}
		}
		$lines[] = '';
		$lines[] = sprintf( __( 'WAITING FOR AN ACKNOWLEDGEMENT: %d', 'travelstore-enquiries' ), count( $waiting ) );
		if ( empty( $waiting ) ) {
			$lines[] = __( 'None – every enquiry has been acknowledged or closed.', 'travelstore-enquiries' );
		} else {
			foreach ( $waiting as $post ) {
				$lines = array_merge( $lines, array( '' ), self::line( $post ) );
			}
		}
		$lines = array_merge( $lines, array( '' ), self::pipeline_lines() );
		$lines[] = '';
		$lines[] = sprintf( __( 'All enquiries: %s', 'travelstore-enquiries' ), admin_url( 'edit.php?post_type=' . Store::POST_TYPE ) );
		$lines[] = __( 'This summary is sent every day, also when there is nothing new. If it stops arriving, please check the website.', 'travelstore-enquiries' );

		return implode( "\n", $lines );
	}
}
