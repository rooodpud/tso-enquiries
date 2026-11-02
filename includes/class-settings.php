<?php
/**
 * Enquiries > Settings: the office address, the staff list, the acknowledgement email and the daily summary.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION = 'ts_enquiries_settings';
	const PAGE   = 'ts-enquiries-settings';

	public static function defaults() {
		return array(
			'office_email'      => 'info@travelstoreoman.com',
			'staff'             => '',
			'ack_subject'       => __( 'We\'ve received your enquiry – Travel Store Oman', 'travelstore-enquiries' ),
			'ack_greeting'      => __( 'Dear', 'travelstore-enquiries' ),
			'ack_message'       => __( "Thank you for getting in touch with Travel Store Oman. We have received your enquiry and a member of our Muscat team is looking at it now.\n\nWe will reply within one working day with any questions and, for trip enquiries, a first draft route within about 48 hours.\n\nIf anything is urgent, message us on WhatsApp: +968 7410 0010.", 'travelstore-enquiries' ),
			'ack_mention_staff' => 'yes',
			'ack_include_copy'  => 'yes',
			'ack_signature'     => __( "Warm regards,\nThe Travel Store Oman team\n+968 2479 8001 · info@travelstoreoman.com", 'travelstore-enquiries' ),
			'digest_on'         => 'yes',
			'digest_email'      => 'info@travelstoreoman.com',
			'digest_hour'       => '9',
			'flyout_on'         => 'yes',
			'flyout_whatsapp'   => '96874100010',
			'flyout_phone'      => '+968 74100010',
			'flyout_wa_text'    => __( 'Hello Travel Store Oman, I would like to ask about…', 'travelstore-enquiries' ),
			'chat_on'           => 'yes',
			// 1.3.0 Insights.
			'office_days'       => '0,1,2,3,4',
			'office_start'      => '9', // Owner 2026-11-02: Sunday to Thursday, 09:00 to 18:00.
			'office_end'        => '18',
			'reply_target'      => 'day', // Owner 2026-11-02: first reply within one office day.
			'stuck_days'        => '7',
			'lost_reasons'      => implode( "\n", array( __( 'Price', 'travelstore-enquiries' ), __( 'Dates not available', 'travelstore-enquiries' ), __( 'No reply from the guest', 'travelstore-enquiries' ), __( 'Booked elsewhere', 'travelstore-enquiries' ), __( 'Just looking', 'travelstore-enquiries' ), __( 'Not a fit for us', 'travelstore-enquiries' ), __( 'Spam', 'travelstore-enquiries' ) ) ),
			'weekly_on'         => 'yes',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : '';
	}

	/**
	 * Reasons for losing an enquiry (Settings, one per line), offered when an enquiry finishes without a booking.
	 */
	public static function lost_reasons() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::get( 'lost_reasons' ) );

		return array_values( array_unique( array_filter( array_map( 'trim', $lines ), 'strlen' ) ) );
	}

	/**
	 * Staff names, one per line in the settings.
	 */
	public static function staff() {
		$lines = preg_split( '/\r\n|\r|\n/', (string) self::get( 'staff' ) );

		return array_values( array_unique( array_filter( array_map( 'trim', $lines ), 'strlen' ) ) );
	}

	public static function office_email() {
		$email = sanitize_email( (string) self::get( 'office_email' ) );

		return is_email( $email ) ? $email : sanitize_email( get_option( 'admin_email' ) );
	}

	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_post_ts_enquiry_test_mail', array( __CLASS__, 'send_test' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'settings_changed' ), 10, 2 );
	}

	public static function register_setting() {
		register_setting(
			'ts_enquiries',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();
		foreach ( array( 'office_email', 'digest_email' ) as $key ) {
			$email = sanitize_email( isset( $input[ $key ] ) ? $input[ $key ] : '' );
			if ( is_email( $email ) ) {
				$out[ $key ] = $email;
			} else {
				add_settings_error( self::OPTION, $key, __( 'An email address was not valid, so the previous one was kept.', 'travelstore-enquiries' ) );
				$out[ $key ] = self::get( $key );
			}
		}
		$out['staff'] = isset( $input['staff'] ) ? sanitize_textarea_field( $input['staff'] ) : (string) self::get( 'staff' ); // 1.1.0: names are managed on Enquiries > Staff; saving here keeps them.
		foreach ( array( 'ack_message', 'ack_signature' ) as $key ) {
			$out[ $key ] = sanitize_textarea_field( isset( $input[ $key ] ) ? $input[ $key ] : '' );
		}
		foreach ( array( 'ack_subject', 'ack_greeting' ) as $key ) {
			$out[ $key ] = sanitize_text_field( isset( $input[ $key ] ) ? $input[ $key ] : '' );
		}
		foreach ( array( 'ack_mention_staff', 'ack_include_copy', 'digest_on', 'flyout_on', 'chat_on', 'weekly_on' ) as $key ) {
			$out[ $key ] = ! empty( $input[ $key ] ) ? 'yes' : '';
		}
		foreach ( array( 'flyout_whatsapp', 'flyout_phone', 'flyout_wa_text' ) as $key ) {
			$out[ $key ] = sanitize_text_field( isset( $input[ $key ] ) ? $input[ $key ] : '' );
		}
		$out['flyout_whatsapp'] = preg_replace( '/\D+/', '', $out['flyout_whatsapp'] );
		$hour               = isset( $input['digest_hour'] ) ? (int) $input['digest_hour'] : 9;
		$out['digest_hour'] = (string) max( 0, min( 23, $hour ) );
		// Insights (1.3.0).
		$days               = isset( $input['office_days'] ) && is_array( $input['office_days'] ) ? array_map( 'intval', $input['office_days'] ) : array();
		$days               = array_values( array_intersect( range( 0, 6 ), $days ) );
		$out['office_days'] = $days ? implode( ',', $days ) : (string) self::get( 'office_days' );
		$start              = isset( $input['office_start'] ) ? max( 0, min( 23, (int) $input['office_start'] ) ) : 8;
		$end                = isset( $input['office_end'] ) ? max( 1, min( 24, (int) $input['office_end'] ) ) : 17;
		if ( $end <= $start ) {
			add_settings_error( self::OPTION, 'office_hours', __( 'The office closing time must be after the opening time, so the previous hours were kept.', 'travelstore-enquiries' ) );
			$start = (int) self::get( 'office_start' );
			$end   = (int) self::get( 'office_end' );
		}
		$out['office_start'] = (string) $start;
		$out['office_end']   = (string) $end;
		$target              = isset( $input['reply_target'] ) ? (string) $input['reply_target'] : 'day';
		$out['reply_target'] = in_array( $target, array_map( 'strval', self::reply_targets() ), true ) ? $target : 'day';
		$out['stuck_days']   = (string) ( in_array( (int) ( $input['stuck_days'] ?? 7 ), self::stuck_choices(), true ) ? (int) $input['stuck_days'] : 7 );
		$out['lost_reasons'] = isset( $input['lost_reasons'] ) ? sanitize_textarea_field( $input['lost_reasons'] ) : (string) self::get( 'lost_reasons' );

		return $out;
	}

	/** Reply targets on offer, in office hours; 'day' = one office day (the office hours of one day). */
	public static function reply_targets() {
		return array( 1, 2, 3, 4, 6, 8, 'day', 12, 16, 24 );
	}

	public static function stuck_choices() {
		return array( 3, 5, 7, 10, 14, 21, 30 );
	}

	/**
	 * A new summary time or on/off: schedule the summary again.
	 */
	public static function settings_changed( $old, $new ) {
		Digest::unschedule();
		Digest::schedule();
		Weekly::reschedule();
		// New office hours change every reply time: refresh the Insights rows.
		if ( is_array( $old ) && is_array( $new ) ) {
			foreach ( array( 'office_days', 'office_start', 'office_end' ) as $key ) {
				if ( ( $old[ $key ] ?? '' ) !== ( $new[ $key ] ?? '' ) ) {
					Facts::rebuild_all();
					break;
				}
			}
		}
	}

	public static function send_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'travelstore-enquiries' ) );
		}
		check_admin_referer( 'ts_enquiry_test_mail' );
		$result = Mailer::send(
			self::office_email(),
			sprintf( __( 'Test email from %s', 'travelstore-enquiries' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			__( "This is a test from Enquiries > Settings on the website. If you can read it, enquiry emails can reach this address.\n\nIf it went to the Spam folder, mark it as \"Not spam\".", 'travelstore-enquiries' )
		);
		$args = array( 'page' => self::PAGE, 'ts_test' => $result['ok'] ? 'sent' : 'failed' );
		if ( ! $result['ok'] ) {
			set_transient( 'ts_enquiries_test_error', $result['error'], 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php?post_type=' . Store::POST_TYPE ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                                */
	/* ------------------------------------------------------------------ */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o    = self::all();
		$name = self::OPTION;
		?>
		<div class="wrap ts-enq-admin">
			<h1><?php esc_html_e( 'Enquiry settings', 'travelstore-enquiries' ); ?></h1>
			<?php
			settings_errors( self::OPTION );
			if ( isset( $_GET['settings-updated'] ) && ! get_settings_errors( self::OPTION ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'travelstore-enquiries' ) . '</p></div>';
			}
			if ( isset( $_GET['ts_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				if ( 'sent' === $_GET['ts_test'] ) { // phpcs:ignore WordPress.Security.NonceVerification
					echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( 'A test email was handed to the mail system for %s. Check that inbox (and its Spam folder).', 'travelstore-enquiries' ), self::office_email() ) ) . '</p></div>';
				} else {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'The test email could not be sent:', 'travelstore-enquiries' ) . ' ' . esc_html( (string) get_transient( 'ts_enquiries_test_error' ) ) . '</p></div>';
				}
			}
			?>
			<form method="post" action="options.php">
				<?php settings_fields( 'ts_enquiries' ); ?>

				<h2><?php esc_html_e( 'New enquiries', 'travelstore-enquiries' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ts-office"><?php esc_html_e( 'Office email address', 'travelstore-enquiries' ); ?></label></th>
						<td><input type="email" class="regular-text" id="ts-office" name="<?php echo esc_attr( $name ); ?>[office_email]" value="<?php echo esc_attr( $o['office_email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Every new enquiry is emailed here. The customer is never emailed automatically.', 'travelstore-enquiries' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Staff who can be assigned', 'travelstore-enquiries' ); ?></th>
						<td><?php echo esc_html( empty( self::staff() ) ? __( 'No staff yet.', 'travelstore-enquiries' ) : implode( ', ', self::staff() ) ); ?>
						<p class="description"><a href="<?php echo esc_url( Staff::url() ); ?>"><?php esc_html_e( 'Add, rename or remove staff on Enquiries > Staff.', 'travelstore-enquiries' ); ?></a></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Acknowledgement email to the customer', 'travelstore-enquiries' ); ?></h2>
				<p><?php esc_html_e( 'Sent only when someone clicks "Send acknowledgement email" on an enquiry.', 'travelstore-enquiries' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ts-subject"><?php esc_html_e( 'Subject', 'travelstore-enquiries' ); ?></label></th>
						<td><input type="text" class="large-text" id="ts-subject" name="<?php echo esc_attr( $name ); ?>[ack_subject]" value="<?php echo esc_attr( $o['ack_subject'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-greeting"><?php esc_html_e( 'Greeting', 'travelstore-enquiries' ); ?></label></th>
						<td><input type="text" class="small-text" style="width:10em" id="ts-greeting" name="<?php echo esc_attr( $name ); ?>[ack_greeting]" value="<?php echo esc_attr( $o['ack_greeting'] ); ?>">
						<p class="description"><?php esc_html_e( 'The customer\'s first name and a comma are added after it automatically, for example: Dear Sarah,', 'travelstore-enquiries' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-message"><?php esc_html_e( 'Message', 'travelstore-enquiries' ); ?></label></th>
						<td><textarea class="large-text" rows="8" id="ts-message" name="<?php echo esc_attr( $name ); ?>[ack_message]"><?php echo esc_textarea( $o['ack_message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Also include', 'travelstore-enquiries' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ack_mention_staff]" value="yes"<?php checked( 'yes', $o['ack_mention_staff'] ); ?>> <?php esc_html_e( 'Who is looking after the enquiry ("Your enquiry is being looked after by Aisha.")', 'travelstore-enquiries' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ack_include_copy]" value="yes"<?php checked( 'yes', $o['ack_include_copy'] ); ?>> <?php esc_html_e( 'A copy of what the customer sent', 'travelstore-enquiries' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-signature"><?php esc_html_e( 'Signature', 'travelstore-enquiries' ); ?></label></th>
						<td><textarea class="large-text" rows="4" id="ts-signature" name="<?php echo esc_attr( $name ); ?>[ack_signature]"><?php echo esc_textarea( $o['ack_signature'] ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Daily summary email', 'travelstore-enquiries' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Send a daily summary', 'travelstore-enquiries' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[digest_on]" value="yes"<?php checked( 'yes', $o['digest_on'] ); ?>> <?php esc_html_e( 'Yes — new enquiries of the last day, and the ones still waiting for an acknowledgement', 'travelstore-enquiries' ); ?></label>
						<p class="description"><?php esc_html_e( 'Sent every day, also when there is nothing new, so a missing summary shows that something is wrong.', 'travelstore-enquiries' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-digest-email"><?php esc_html_e( 'Send it to', 'travelstore-enquiries' ); ?></label></th>
						<td><input type="email" class="regular-text" id="ts-digest-email" name="<?php echo esc_attr( $name ); ?>[digest_email]" value="<?php echo esc_attr( $o['digest_email'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-digest-hour"><?php esc_html_e( 'At', 'travelstore-enquiries' ); ?></label></th>
						<td><select id="ts-digest-hour" name="<?php echo esc_attr( $name ); ?>[digest_hour]">
							<?php for ( $h = 0; $h < 24; $h++ ) : ?>
								<option value="<?php echo esc_attr( $h ); ?>"<?php selected( (string) $h, (string) $o['digest_hour'] ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
							<?php endfor; ?>
						</select>
						<p class="description">
							<?php
							/* translators: %s: time zone name. */
							echo esc_html( sprintf( __( 'Website time zone: %s (Settings > General > Timezone).', 'travelstore-enquiries' ), wp_timezone_string() ) );
							$next = wp_next_scheduled( Digest::HOOK );
							if ( $next ) {
								echo ' ' . esc_html( sprintf( __( 'Next summary: %s.', 'travelstore-enquiries' ), wp_date( 'j M Y, H:i', $next ) ) );
							}
							?>
						</p></td>
					</tr>
				</table>
				<h2 id="insights"><?php esc_html_e( 'Insights: office hours, reply target, lost reasons', 'travelstore-enquiries' ); ?></h2>
				<p><?php esc_html_e( 'Used by Enquiries > Insights. Reply times count office hours only, so an enquiry sent on Friday evening is not late on Saturday.', 'travelstore-enquiries' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Office days', 'travelstore-enquiries' ); ?></th>
						<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Office days', 'travelstore-enquiries' ); ?></legend>
							<?php foreach ( Office::day_names() as $d => $day ) : ?>
								<label style="margin-right:12px"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[office_days][]" value="<?php echo esc_attr( $d ); ?>"<?php checked( in_array( $d, Office::days(), true ) ); ?>> <?php echo esc_html( $day ); ?></label>
							<?php endforeach; ?>
						</fieldset></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Office hours', 'travelstore-enquiries' ); ?></th>
						<td>
							<label for="ts-office-start" class="screen-reader-text"><?php esc_html_e( 'Opens at', 'travelstore-enquiries' ); ?></label>
							<select id="ts-office-start" name="<?php echo esc_attr( $name ); ?>[office_start]">
								<?php for ( $h = 0; $h < 24; $h++ ) : ?>
									<option value="<?php echo esc_attr( $h ); ?>"<?php selected( (string) $h, (string) $o['office_start'] ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
								<?php endfor; ?>
							</select>
							<?php esc_html_e( 'to', 'travelstore-enquiries' ); ?>
							<label for="ts-office-end" class="screen-reader-text"><?php esc_html_e( 'Closes at', 'travelstore-enquiries' ); ?></label>
							<select id="ts-office-end" name="<?php echo esc_attr( $name ); ?>[office_end]">
								<?php for ( $h = 1; $h <= 24; $h++ ) : ?>
									<option value="<?php echo esc_attr( $h ); ?>"<?php selected( (string) $h, (string) $o['office_end'] ); ?>><?php echo esc_html( 24 === $h ? '24:00' : sprintf( '%02d:00', $h ) ); ?></option>
								<?php endfor; ?>
							</select>
							<p class="description"><?php echo esc_html( sprintf( __( 'Website time zone: %s.', 'travelstore-enquiries' ), wp_timezone_string() ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-reply-target"><?php esc_html_e( 'Reply target', 'travelstore-enquiries' ); ?></label></th>
						<td><select id="ts-reply-target" name="<?php echo esc_attr( $name ); ?>[reply_target]">
							<?php foreach ( self::reply_targets() as $h ) : ?>
								<option value="<?php echo esc_attr( $h ); ?>"<?php selected( (string) $h, (string) $o['reply_target'] ); ?>><?php echo esc_html( 'day' === $h ? __( 'Within 1 office day', 'travelstore-enquiries' ) : sprintf( _n( 'Within %d office hour', 'Within %d office hours', $h, 'travelstore-enquiries' ), $h ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'An enquiry with no first reply after this is shown as overdue. The first reply is the acknowledgement email or "Mark as replied" on the enquiry, whichever comes first.', 'travelstore-enquiries' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-stuck"><?php esc_html_e( 'Stuck after', 'travelstore-enquiries' ); ?></label></th>
						<td><select id="ts-stuck" name="<?php echo esc_attr( $name ); ?>[stuck_days]">
							<?php foreach ( self::stuck_choices() as $d ) : ?>
								<option value="<?php echo esc_attr( $d ); ?>"<?php selected( (string) $d, (string) $o['stuck_days'] ); ?>><?php echo esc_html( sprintf( _n( '%d day without a status change', '%d days without a status change', $d, 'travelstore-enquiries' ), $d ) ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-lost-reasons"><?php esc_html_e( 'Reasons an enquiry is lost', 'travelstore-enquiries' ); ?></label></th>
						<td><textarea class="regular-text" rows="7" id="ts-lost-reasons" name="<?php echo esc_attr( $name ); ?>[lost_reasons]"><?php echo esc_textarea( $o['lost_reasons'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One reason per line. Offered on an enquiry when its status is Dropped, Rejected by client or Closed. Renaming a reason here does not change enquiries that already have the old one.', 'travelstore-enquiries' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Weekly summary', 'travelstore-enquiries' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[weekly_on]" value="yes"<?php checked( 'yes', $o['weekly_on'] ); ?>> <?php esc_html_e( 'Yes — every Sunday at the daily summary time, to the daily summary address: what changed last week, the key figures, and who is waiting for a reply', 'travelstore-enquiries' ); ?></label></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Contact flyout', 'travelstore-enquiries' ); ?></h2>
				<p><?php esc_html_e( 'The small "Contact us" button at the bottom right of every page: WhatsApp, phone call, and a short enquiry form. Messages from the short form arrive in the Enquiries list as "Quick contact".', 'travelstore-enquiries' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show the flyout', 'travelstore-enquiries' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[flyout_on]" value="yes"<?php checked( 'yes', $o['flyout_on'] ); ?>> <?php esc_html_e( 'Yes — on every page of the website', 'travelstore-enquiries' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-fl-wa"><?php esc_html_e( 'WhatsApp number', 'travelstore-enquiries' ); ?></label></th>
						<td><input type="text" class="regular-text" id="ts-fl-wa" name="<?php echo esc_attr( $name ); ?>[flyout_whatsapp]" value="<?php echo esc_attr( $o['flyout_whatsapp'] ); ?>">
						<p class="description"><?php esc_html_e( 'With the country code, for example 96874100010. Leave empty to hide the WhatsApp option.', 'travelstore-enquiries' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-fl-wa-text"><?php esc_html_e( 'First WhatsApp message', 'travelstore-enquiries' ); ?></label></th>
						<td><input type="text" class="large-text" id="ts-fl-wa-text" name="<?php echo esc_attr( $name ); ?>[flyout_wa_text]" value="<?php echo esc_attr( $o['flyout_wa_text'] ); ?>">
						<p class="description"><?php esc_html_e( 'Already typed into WhatsApp when the visitor opens the chat. Leave empty for a blank chat.', 'travelstore-enquiries' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ts-fl-phone"><?php esc_html_e( 'Phone number', 'travelstore-enquiries' ); ?></label></th>
						<td><input type="text" class="regular-text" id="ts-fl-phone" name="<?php echo esc_attr( $name ); ?>[flyout_phone]" value="<?php echo esc_attr( $o['flyout_phone'] ); ?>">
						<p class="description"><?php esc_html_e( 'Shown as written and used for the call button. Leave empty to hide the call option.', 'travelstore-enquiries' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Oman questions assistant', 'travelstore-enquiries' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[chat_on]" value="yes"<?php checked( 'yes', $o['chat_on'] ); ?>> <?php esc_html_e( 'Show "Ask about Oman" in the flyout', 'travelstore-enquiries' ); ?></label>
						<p class="description"><?php esc_html_e( 'An automated assistant that answers common questions from the file data/oman-faq.json in this plugin. It uses the WhatsApp number, phone number and office email above for its contact options.', 'travelstore-enquiries' ); ?></p></td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'travelstore-enquiries' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Check that emails arrive', 'travelstore-enquiries' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ts_enquiry_test_mail">
				<?php wp_nonce_field( 'ts_enquiry_test_mail' ); ?>
				<p><?php echo esc_html( sprintf( __( 'Sends a short test email to %s. Emails are sent by the website\'s mail plugin (for example WP Mail SMTP or FluentSMTP).', 'travelstore-enquiries' ), self::office_email() ) ); ?></p>
				<?php submit_button( __( 'Send a test email', 'travelstore-enquiries' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
