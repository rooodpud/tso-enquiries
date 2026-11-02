<?php
/**
 * Insights store (1.3.0): two tables kept beside the enquiries, never instead of them.
 *
 *  - {prefix}ts_enq_facts: one flat row per enquiry with what the Insights pages count (dates, type,
 *    sources, visit, travel details, stage, reply time). No name, email or phone: only a scrambled
 *    code of the email so repeat enquirers can be counted.
 *  - {prefix}ts_enq_events: status, assignment, acknowledgement and reply changes with their time.
 *
 * Rows are refreshed at the end of any request that changed an enquiry (any _ts_enq_* or hire meta,
 * trash, restore, delete), so every writer is covered, including other plugins. "Rebuild" on
 * Enquiries > Insights refills the facts from the enquiries at any time; the enquiries are only read.
 * History from before 1.3.0 is read once from each enquiry's history lines and marked approximate.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Facts {

	const DB_VERSION = '2'; // 2: value (1.3.1).
	const DB_OPTION  = 'ts_enquiries_insights_db';
	const VERSION    = 'ts_enquiries_facts_version'; // Changes whenever a row changes (cache key).
	const BACKFILLED = 'ts_enquiries_events_backfilled';

	/** Meta whose change is an event. */
	const EVENT_KEYS = array( 'status', 'assigned', 'ack_at', 'replied_at' );

	private static $queue = array();
	private static $old   = array();

	public static function facts_table() {
		global $wpdb;

		return $wpdb->prefix . 'ts_enq_facts';
	}

	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'ts_enq_events';
	}

	public static function register() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
		add_action( 'ts_enquiry_created', array( __CLASS__, 'created' ), 10, 3 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'remember_old' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
		add_action( 'transition_post_status', array( __CLASS__, 'status_changed' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'deleted' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
	}

	/* ------------------------------------------------------------------ Tables */

	public static function maybe_install() {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$facts   = self::facts_table();
		$events  = self::events_table();
		dbDelta(
			"CREATE TABLE {$facts} (
				enquiry_id bigint(20) unsigned NOT NULL,
				received_gmt datetime NOT NULL,
				day date NOT NULL,
				hour tinyint(3) unsigned NOT NULL DEFAULT 0,
				weekday tinyint(3) unsigned NOT NULL DEFAULT 1,
				channel varchar(10) NOT NULL DEFAULT 'enquiry',
				type_raw varchar(191) NOT NULL DEFAULT '',
				heard_raw varchar(191) NULL,
				visit_channel varchar(30) NOT NULL DEFAULT '',
				utm_source varchar(100) NOT NULL DEFAULT '',
				utm_medium varchar(100) NOT NULL DEFAULT '',
				utm_campaign varchar(150) NOT NULL DEFAULT '',
				ref_domain varchar(150) NOT NULL DEFAULT '',
				landing varchar(191) NOT NULL DEFAULT '',
				device varchar(10) NOT NULL DEFAULT '',
				lang varchar(10) NOT NULL DEFAULT '',
				country varchar(80) NOT NULL DEFAULT '',
				adults smallint(5) unsigned NULL,
				children smallint(5) unsigned NULL,
				travel_from date NULL,
				travel_to date NULL,
				lead_days int(11) NULL,
				nights int(11) NULL,
				vehicle varchar(150) NOT NULL DEFAULT '',
				offer varchar(191) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'new',
				stage varchar(10) NOT NULL DEFAULT 'open',
				reason varchar(100) NOT NULL DEFAULT '',
				value decimal(12,3) NULL,
				won_gmt datetime NULL,
				lost_gmt datetime NULL,
				last_change_gmt datetime NULL,
				assigned varchar(100) NOT NULL DEFAULT '',
				replied_gmt datetime NULL,
				reply_minutes int(11) NULL,
				is_test tinyint(1) NOT NULL DEFAULT 0,
				is_sample tinyint(1) NOT NULL DEFAULT 0,
				email_hash char(16) NOT NULL DEFAULT '',
				refreshed_gmt datetime NOT NULL,
				PRIMARY KEY  (enquiry_id),
				KEY day (day),
				KEY channel_day (channel, day),
				KEY stage (stage)
			) {$charset};
			CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				enquiry_id bigint(20) unsigned NOT NULL,
				event varchar(20) NOT NULL,
				from_value varchar(100) NOT NULL DEFAULT '',
				to_value varchar(100) NOT NULL DEFAULT '',
				by_user varchar(100) NOT NULL DEFAULT '',
				at_gmt datetime NOT NULL,
				approx tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY enquiry_id (enquiry_id),
				KEY event_at (event, at_gmt)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/* ------------------------------------------------------------------ Keeping rows up to date */

	public static function queue( $id ) {
		self::$queue[ (int) $id ] = true;
	}

	/** A new enquiry: keep where the visitor came from, write the "received" event, refresh at the end. */
	public static function created( $id, $clean, $source ) {
		Visit::save( $id, $source );
		self::event( $id, 'created', '', '', '', get_post_field( 'post_date_gmt', $id ) );
		self::queue( $id );
	}

	/** Before a change, note the old value so the event can say "from … to …". */
	public static function remember_old( $check, $object_id, $meta_key, $meta_value ) {
		if ( 0 === strpos( (string) $meta_key, Store::META ) && in_array( substr( $meta_key, strlen( Store::META ) ), self::EVENT_KEYS, true ) ) {
			self::$old[ $object_id . '|' . $meta_key ] = (string) get_post_meta( $object_id, $meta_key, true );
		}

		return $check;
	}

	public static function meta_changed( $meta_id, $object_id, $meta_key, $value ) {
		$key = (string) $meta_key;
		if ( 0 !== strpos( $key, Store::META ) && 0 !== strpos( $key, '_tsh_' ) ) {
			return;
		}
		if ( get_post_type( $object_id ) !== Store::POST_TYPE ) {
			return;
		}
		self::queue( $object_id );
		$short = substr( $key, strlen( Store::META ) );
		if ( 0 !== strpos( $key, Store::META ) || ! in_array( $short, self::EVENT_KEYS, true ) || 'deleted_post_meta' === current_filter() ) {
			return;
		}
		$old = self::$old[ $object_id . '|' . $key ] ?? '';
		unset( self::$old[ $object_id . '|' . $key ] );
		$new = is_scalar( $value ) ? (string) $value : '';
		// The values Store::create() starts with are not changes.
		if ( $old === $new || ( 'added_post_meta' === current_filter() && ( '' === $new || ( 'status' === $short && 'new' === $new ) ) ) ) {
			return;
		}
		$user = wp_get_current_user();
		self::event( $object_id, 'ack_at' === $short ? 'acknowledged' : ( 'replied_at' === $short ? 'replied' : $short ), $old, $new, $user && $user->exists() ? $user->display_name : '' );
	}

	public static function status_changed( $new, $old, $post ) {
		if ( $post && Store::POST_TYPE === $post->post_type && $new !== $old ) {
			self::queue( $post->ID );
		}
	}

	public static function deleted( $id ) {
		global $wpdb;
		$wpdb->delete( self::facts_table(), array( 'enquiry_id' => (int) $id ) );
		$wpdb->delete( self::events_table(), array( 'enquiry_id' => (int) $id ) );
		unset( self::$queue[ (int) $id ] );
		self::bump();
	}

	public static function flush() {
		$ids         = array_keys( self::$queue );
		self::$queue = array();
		foreach ( $ids as $id ) {
			self::refresh( $id );
		}
	}

	public static function event( $id, $event, $from, $to, $by = '', $at_gmt = '', $approx = 0 ) {
		global $wpdb;
		$wpdb->insert(
			self::events_table(),
			array(
				'enquiry_id' => (int) $id,
				'event'      => substr( (string) $event, 0, 20 ),
				'from_value' => mb_substr( (string) $from, 0, 100 ),
				'to_value'   => mb_substr( (string) $to, 0, 100 ),
				'by_user'    => mb_substr( (string) $by, 0, 100 ),
				'at_gmt'     => '' !== $at_gmt ? $at_gmt : current_time( 'mysql', true ),
				'approx'     => $approx ? 1 : 0,
			)
		);
	}

	private static function bump() {
		update_option( self::VERSION, (string) microtime( true ), true );
	}

	public static function version() {
		return (string) get_option( self::VERSION, '0' );
	}

	/* ------------------------------------------------------------------ One row */

	/** Write (or remove) the facts row of one enquiry from what is stored on it. */
	public static function refresh( $id ) {
		global $wpdb;
		$post = get_post( $id );
		if ( ! $post || Store::POST_TYPE !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			$wpdb->delete( self::facts_table(), array( 'enquiry_id' => (int) $id ) );
			self::bump();
			return;
		}
		$wpdb->replace( self::facts_table(), self::row( $post ) );
		self::bump();
	}

	/** The facts row of one enquiry. */
	public static function row( $post ) {
		$id    = (int) $post->ID;
		$tz    = wp_timezone();
		$utc   = new \DateTimeZone( 'UTC' );
		$gmt   = '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : get_gmt_from_date( $post->post_date );
		$local = ( new \DateTimeImmutable( $gmt, $utc ) )->setTimezone( $tz );
		$data  = Store::get( $id, 'data' );
		$data  = is_array( $data ) ? $data : array();
		$type  = trim( (string) Store::get( $id, 'type' ) );
		$hire  = '' !== (string) get_post_meta( $id, '_tsh_start', true ) || '' !== (string) Store::get( $id, 'hire_ref' ) || Report_Data::BOOKING_TYPE === $type;
		$visit = Visit::of( $id );
		$from  = self::date_or_null( $data['date_from'] ?? '' );
		$to    = self::date_or_null( $data['date_to'] ?? '' );
		if ( $hire ) {
			$start = (string) get_post_meta( $id, '_tsh_start', true );
			$end   = (string) get_post_meta( $id, '_tsh_end', true );
			$from  = $from ? $from : self::date_or_null( substr( $start, 0, 10 ) );
			$to    = $to ? $to : self::date_or_null( substr( $end, 0, 10 ) );
		}
		$status  = Store::status( $id );
		$events  = self::events_of( $id );
		$stage   = self::stage( $status, $events );
		$replied = self::first_reply_gmt( $id );
		$email   = strtolower( trim( (string) Store::get( $id, 'email' ) ) );
		$country = trim( (string) ( $data['country'] ?? '' ) );
		if ( '' === $country ) {
			$country = trim( (string) get_post_meta( $id, '_tsh_country', true ) );
		}
		$last_change = null;
		$won         = null;
		$lost        = null;
		foreach ( $events as $e ) {
			if ( 'status' === $e['event'] ) {
				$last_change = $e['at_gmt'];
				if ( 'won' === self::stage_of_status( $e['to_value'] ) && ! $won ) {
					$won = $e['at_gmt'];
				}
				if ( 'lost' === self::stage_of_status( $e['to_value'] ) ) {
					$lost = $e['at_gmt'];
				}
			}
		}
		$offer = '';
		foreach ( (array) Store::get( $id, 'rows' ) as $r ) {
			if ( is_array( $r ) && 0 === strpos( (string) ( $r['label'] ?? '' ), 'Offer' ) ) {
				$offer = (string) $r['value'];
			}
		}

		return array(
			'enquiry_id'      => $id,
			'received_gmt'    => $gmt,
			'day'             => $local->format( 'Y-m-d' ),
			'hour'            => (int) $local->format( 'G' ),
			'weekday'         => (int) $local->format( 'N' ),
			'channel'         => $hire ? 'booking' : 'enquiry',
			'type_raw'        => mb_substr( $type, 0, 191 ),
			'heard_raw'       => array_key_exists( 'heard_about', $data ) ? mb_substr( trim( (string) $data['heard_about'] ), 0, 191 ) : null,
			'visit_channel'   => Visit::channel( $visit ),
			'utm_source'      => mb_substr( (string) ( $visit['utm_source'] ?? '' ), 0, 100 ),
			'utm_medium'      => mb_substr( (string) ( $visit['utm_medium'] ?? '' ), 0, 100 ),
			'utm_campaign'    => mb_substr( (string) ( $visit['utm_campaign'] ?? '' ), 0, 150 ),
			'ref_domain'      => mb_substr( (string) ( $visit['ref'] ?? '' ), 0, 150 ),
			'landing'         => mb_substr( (string) ( $visit['landing'] ?? '' ), 0, 191 ),
			'device'          => mb_substr( (string) ( $visit['device'] ?? '' ), 0, 10 ),
			'lang'            => mb_substr( (string) ( $visit['lang'] ?? '' ), 0, 10 ),
			'country'         => mb_substr( $country, 0, 80 ),
			'adults'          => isset( $data['adults'] ) && is_numeric( $data['adults'] ) ? (int) $data['adults'] : null,
			'children'        => isset( $data['children'] ) && is_numeric( $data['children'] ) ? (int) $data['children'] : null,
			'travel_from'     => $from,
			'travel_to'       => $to,
			'lead_days'       => $from ? (int) floor( ( strtotime( $from . ' UTC' ) - strtotime( $local->format( 'Y-m-d' ) . ' UTC' ) ) / DAY_IN_SECONDS ) : null,
			'nights'          => $from && $to ? max( 0, (int) floor( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS ) ) : null,
			'vehicle'         => mb_substr( (string) ( $data['vehicle'] ?? '' ), 0, 150 ),
			'offer'           => mb_substr( $offer, 0, 191 ),
			'status'          => $status,
			'stage'           => $stage,
			'reason'          => mb_substr( (string) Store::get( $id, 'outcome_reason' ), 0, 100 ),
			'value'           => self::value( $id, $hire ),
			'won_gmt'         => 'won' === $stage ? $won : null,
			'lost_gmt'        => 'lost' === $stage ? $lost : null,
			'last_change_gmt' => $last_change,
			'assigned'        => mb_substr( (string) Store::get( $id, 'assigned' ), 0, 100 ),
			'replied_gmt'     => $replied,
			'reply_minutes'   => $replied ? Office::minutes_between( strtotime( $gmt . ' UTC' ), strtotime( $replied . ' UTC' ) ) : null,
			'is_test'         => '1' === (string) Store::get( $id, 'test' ) ? 1 : 0,
			'is_sample'       => '1' === (string) Store::get( $id, 'sample' ) ? 1 : 0,
			'email_hash'      => '' !== $email ? substr( hash_hmac( 'sha256', $email, wp_salt( 'auth' ) ), 0, 16 ) : '',
			'refreshed_gmt'   => current_time( 'mysql', true ),
		);
	}

	/**
	 * Value in OMR (1.3.1, owner 2026-11-02): the amount staff typed on the enquiry; for a vehicle
	 * hire booking without one, its total in USD times the OMR rate kept with the booking.
	 */
	public static function value( $id, $hire = false ) {
		$typed = (string) Store::get( $id, 'value' );
		if ( '' !== $typed && is_numeric( $typed ) ) {
			return round( (float) $typed, 3 );
		}
		if ( $hire ) {
			$quote = get_post_meta( $id, '_tsh_quote', true );
			$rate  = (float) get_post_meta( $id, '_tsh_omr_rate', true );
			if ( is_array( $quote ) && isset( $quote['total'] ) && $rate > 0 ) {
				return round( (float) $quote['total'] * $rate, 3 );
			}
		}

		return null;
	}

	private static function date_or_null( $ymd ) {
		return is_string( $ymd ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ? $ymd : null;
	}

	/** Open, won or lost, from one status. "Closed" has no meaning on its own (see stage()). */
	public static function stage_of_status( $status ) {
		if ( in_array( $status, array( 'confirmed', 'booked', 'payment_received' ), true ) ) {
			return 'won';
		}
		if ( in_array( $status, array( 'dropped', 'rejected' ), true ) ) {
			return 'lost';
		}

		return 'closed' === $status ? 'closed' : 'open';
	}

	/** Closed counts as won when the enquiry was ever confirmed or booked, otherwise as lost (owner default, 2026-11-02). */
	public static function stage( $status, array $events ) {
		$stage = self::stage_of_status( $status );
		if ( 'closed' !== $stage ) {
			return $stage;
		}
		foreach ( $events as $e ) {
			if ( 'status' === $e['event'] && 'won' === self::stage_of_status( $e['to_value'] ) ) {
				return 'won';
			}
		}

		return 'lost';
	}

	/** The first reply: "Mark as replied", or the acknowledgement email, whichever came first (GMT). */
	public static function first_reply_gmt( $id ) {
		$times = array();
		$ack   = (string) Store::get( $id, 'ack_at' ); // Saved in site time.
		if ( '' !== $ack ) {
			$times[] = get_gmt_from_date( $ack );
		}
		$replied = (string) Store::get( $id, 'replied_at' ); // Saved in GMT.
		if ( '' !== $replied ) {
			$times[] = $replied;
		}

		return $times ? min( $times ) : null;
	}

	public static function events_of( $id ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT event, from_value, to_value, by_user, at_gmt, approx FROM ' . self::events_table() . ' WHERE enquiry_id = %d ORDER BY at_gmt ASC, id ASC', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name.
	}

	/* ------------------------------------------------------------------ Rebuild and history */

	/**
	 * Refill the facts for a batch of enquiries (oldest first). The first call also reads the history
	 * of enquiries saved before 1.3.0, once.
	 *
	 * @return array done (this batch), next offset, total.
	 */
	public static function rebuild( $offset = 0, $batch = 200 ) {
		global $wpdb;
		self::maybe_install();
		if ( 0 === $offset && ! get_option( self::BACKFILLED ) ) {
			self::backfill_events();
		}
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", Store::POST_TYPE ) );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC LIMIT %d, %d", Store::POST_TYPE, $offset, $batch ) );
		foreach ( $ids as $id ) {
			self::refresh( (int) $id );
		}
		if ( 0 === $offset ) {
			// Rows of enquiries that no longer exist.
			$wpdb->query( $wpdb->prepare( 'DELETE f FROM ' . self::facts_table() . " f LEFT JOIN {$wpdb->posts} p ON p.ID = f.enquiry_id AND p.post_type = %s WHERE p.ID IS NULL", Store::POST_TYPE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return array(
			'done'  => count( $ids ),
			'next'  => $offset + count( $ids ),
			'total' => $total,
		);
	}

	public static function rebuild_all() {
		$offset = 0;
		do {
			$r      = self::rebuild( $offset );
			$offset = $r['next'];
		} while ( $r['done'] > 0 );

		return $offset;
	}

	/**
	 * Events for enquiries saved before 1.3.0, read from their history lines ("Status: Booked.",
	 * "Assigned to Hedi.", "Acknowledgement email sent to …"). Times are exact; the reading of the
	 * words is not, so these rows are marked approx = 1. Runs once; enquiries that already have
	 * events are skipped.
	 */
	public static function backfill_events() {
		global $wpdb;
		$labels = array_flip( Store::statuses() );
		$have   = array_flip( array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT enquiry_id FROM ' . self::events_table() ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID ASC", Store::POST_TYPE ) );
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( isset( $have[ $id ] ) ) {
				continue;
			}
			self::event( $id, 'created', '', '', '', get_post_field( 'post_date_gmt', $id ), 1 );
			$status   = 'new';
			$assigned = '';
			foreach ( (array) Store::get( $id, 'log' ) as $line ) {
				if ( ! is_array( $line ) || empty( $line['time'] ) ) {
					continue;
				}
				$at   = gmdate( 'Y-m-d H:i:s', (int) $line['time'] );
				$text = (string) $line['text'];
				if ( preg_match( '/^Status: (.+)\.$/u', $text, $m ) && isset( $labels[ $m[1] ] ) ) {
					self::event( $id, 'status', $status, $labels[ $m[1] ], $line['user'] ?? '', $at, 1 );
					$status = $labels[ $m[1] ];
				} elseif ( preg_match( '/^Assigned to (.+)\.$/u', $text, $m ) ) {
					self::event( $id, 'assigned', $assigned, $m[1], $line['user'] ?? '', $at, 1 );
					$assigned = $m[1];
				} elseif ( 0 === strpos( $text, 'Acknowledgement email sent' ) ) {
					self::event( $id, 'acknowledged', '', '', $line['user'] ?? '', $at, 1 );
				}
			}
		}
		update_option( self::BACKFILLED, gmdate( 'c' ), false );
	}
}
