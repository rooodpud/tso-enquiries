<?php
/**
 * Contact flyout: a small floating button at the bottom right of every page that opens three ways
 * to get in touch: WhatsApp, phone call, and a short enquiry form.
 *
 * The short form is saved and emailed exactly like the TS Enquiry Form (Store::create + Mailer::office),
 * as an enquiry of type "Quick contact", so it appears in the same Enquiries list. Same protection:
 * hidden spam-trap field, security code (nonce), and one enquiry per visitor every 30 seconds.
 * The numbers and on/off switch are in Enquiries > Settings.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Flyout {

	const ACTION = 'ts_flyout_submit';
	const MAX    = 200;
	const MAX_MESSAGE = 3000;

	public static function register() {
		add_action( 'wp_footer', array( __CLASS__, 'markup' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_ts_flyout_nonce', array( __CLASS__, 'fresh_nonce' ) );
		add_action( 'wp_ajax_nopriv_ts_flyout_nonce', array( __CLASS__, 'fresh_nonce' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	private static function enabled() {
		return 'yes' === Settings::get( 'flyout_on' );
	}

	/** WhatsApp number as digits only (wa.me format, no + or spaces). */
	private static function whatsapp_digits() {
		return preg_replace( '/\D+/', '', (string) Settings::get( 'flyout_whatsapp' ) );
	}

	/** Phone number as shown, and as a tel: value. */
	private static function phone() {
		return trim( (string) Settings::get( 'flyout_phone' ) );
	}

	private static function phone_tel() {
		return preg_replace( '/[^\d+]/', '', self::phone() );
	}

	private static function showing() {
		if ( ! self::enabled() || is_admin() || is_feed() || is_embed() ) {
			return false;
		}
		if ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			return false;
		}

		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Front end                                                           */
	/* ------------------------------------------------------------------ */

	public static function assets() {
		if ( ! self::showing() ) {
			return;
		}
		$css = TSE_PATH . 'assets/flyout.css';
		$js  = TSE_PATH . 'assets/flyout.js';
		wp_enqueue_style( 'ts-flyout', TSE_URL . 'assets/flyout.css', array(), file_exists( $css ) ? (string) filemtime( $css ) : TSE_VERSION );
		wp_enqueue_script( 'ts-flyout', TSE_URL . 'assets/flyout.js', array(), file_exists( $js ) ? (string) filemtime( $js ) : TSE_VERSION, true );
		wp_localize_script(
			'ts-flyout',
			'tsFlyout',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION,
				'nonceAction' => 'ts_flyout_nonce',
				'sending' => __( 'Sending…', 'travelstore-enquiries' ),
				'send'    => __( 'Send my message', 'travelstore-enquiries' ),
				'errors'  => array(
					'name'    => __( 'Please tell us your name.', 'travelstore-enquiries' ),
					'email'   => __( 'Please enter a valid email address.', 'travelstore-enquiries' ),
					'message' => __( 'Please write a few words so we can reply properly.', 'travelstore-enquiries' ),
					'consent' => __( 'Please tick this so we can reply to you.', 'travelstore-enquiries' ),
					'wait'    => __( 'Please wait a moment before sending another message.', 'travelstore-enquiries' ),
					'send'    => __( 'Sorry, your message could not be sent. Please try again, or message us on WhatsApp.', 'travelstore-enquiries' ),
				),
			)
		);
		Chatbot::assets();
		do_action( 'ts_flyout_assets' ); // Extension point: other plugins (Alia) load their flyout assets here.
	}

	public static function markup() {
		if ( ! self::showing() ) {
			return;
		}
		$wa    = self::whatsapp_digits();
		$phone = self::phone();
		$tel   = self::phone_tel();
		$wa_text = trim( (string) Settings::get( 'flyout_wa_text' ) );
		$wa_url  = '' !== $wa ? 'https://wa.me/' . $wa . ( '' !== $wa_text ? '?text=' . rawurlencode( $wa_text ) : '' ) : '';
		$page_id = (int) get_queried_object_id();
		?>
		<div class="ts-flyout" id="ts-flyout" data-post="<?php echo esc_attr( $page_id ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::ACTION ) ); ?>">
			<div class="ts-flyout__panel" id="ts-flyout-panel" role="dialog" aria-modal="false" aria-labelledby="ts-flyout-title">
				<div class="ts-flyout__head">
					<p class="ts-flyout__title" id="ts-flyout-title"><?php esc_html_e( 'How can we help?', 'travelstore-enquiries' ); ?></p>
					<p class="ts-flyout__sub"><?php esc_html_e( 'Choose how you would like to reach us.', 'travelstore-enquiries' ); ?></p>
				</div>

				<ul class="ts-flyout__menu">
					<?php do_action( 'ts_flyout_menu_items' ); // Extension point: print <li> entries here. ?>
					<?php if ( Chatbot::enabled() ) : ?>
						<li><button type="button" class="ts-flyout__opt ts-flyout__opt--chat" data-ts-flyout-chat aria-controls="ts-chat">
							<span class="ts-flyout__ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm.9 15.5h-1.8v-1.8h1.8v1.8Zm1.9-7.1-.8.8c-.7.7-1.1 1.2-1.1 2.4h-1.8v-.5c0-1 .4-1.9 1.1-2.6l1.1-1.1c.3-.3.5-.8.5-1.3 0-1-.8-1.8-1.8-1.8s-1.8.8-1.8 1.8H8.4a3.6 3.6 0 1 1 7.2 0c0 .8-.3 1.5-.8 2.1Z"/></svg></span>
							<span class="ts-flyout__txt"><strong><?php esc_html_e( 'Ask about Oman', 'travelstore-enquiries' ); ?></strong><span><?php esc_html_e( 'Quick answers to common questions', 'travelstore-enquiries' ); ?></span></span>
						</button></li>
					<?php endif; ?>
					<?php if ( '' !== $wa_url ) : ?>
						<li><a class="ts-flyout__opt ts-flyout__opt--wa" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener">
							<span class="ts-flyout__ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12.04 2a9.9 9.9 0 0 0-8.5 14.95L2 22l5.2-1.36A9.9 9.9 0 1 0 12.04 2Zm0 18.1c-1.5 0-2.9-.4-4.1-1.1l-.3-.18-3.08.8.82-3-.2-.31a8.1 8.1 0 1 1 6.86 3.79Zm4.44-6.07c-.24-.12-1.44-.71-1.66-.79-.22-.08-.39-.12-.55.12-.16.24-.63.79-.77.95-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.43-1.34-1.67-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.32-.75-1.8-.2-.47-.4-.4-.55-.41h-.47c-.16 0-.42.06-.64.3-.22.24-.84.82-.84 2s.86 2.32.98 2.48c.12.16 1.69 2.58 4.1 3.62.57.25 1.02.4 1.37.5.58.18 1.1.16 1.51.1.46-.07 1.44-.59 1.64-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28Z"/></svg></span>
							<span class="ts-flyout__txt"><strong><?php esc_html_e( 'WhatsApp', 'travelstore-enquiries' ); ?></strong><span><?php esc_html_e( 'Chat with us now', 'travelstore-enquiries' ); ?></span></span>
						</a></li>
					<?php endif; ?>
					<?php if ( '' !== $tel ) : ?>
						<li><a class="ts-flyout__opt ts-flyout__opt--call" href="tel:<?php echo esc_attr( $tel ); ?>">
							<span class="ts-flyout__ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.25c1.1.37 2.3.57 3.6.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1l-2.22 2.23Z"/></svg></span>
							<span class="ts-flyout__txt"><strong><?php esc_html_e( 'Call us', 'travelstore-enquiries' ); ?></strong><span><?php echo esc_html( $phone ); ?></span></span>
						</a></li>
					<?php endif; ?>
					<li><button type="button" class="ts-flyout__opt ts-flyout__opt--form" data-ts-flyout-form aria-controls="ts-flyout-form">
						<span class="ts-flyout__ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Zm0 2v.5l8 5 8-5V6H4Zm16 3L12 14 4 9v9h16V9Z"/></svg></span>
						<span class="ts-flyout__txt"><strong><?php esc_html_e( 'Contact us', 'travelstore-enquiries' ); ?></strong><span><?php esc_html_e( 'Send us an enquiry', 'travelstore-enquiries' ); ?></span></span>
					</button></li>
				</ul>

				<form class="ts-flyout__form" id="ts-flyout-form" method="post" novalidate hidden>
					<button type="button" class="ts-flyout__back" data-ts-flyout-back><?php esc_html_e( '← Back', 'travelstore-enquiries' ); ?></button>
					<div class="ts-flyout__field">
						<label for="ts-fl-name"><?php esc_html_e( 'Your name', 'travelstore-enquiries' ); ?> <span aria-hidden="true">*</span></label>
						<input type="text" id="ts-fl-name" name="full_name" autocomplete="name" maxlength="<?php echo esc_attr( self::MAX ); ?>" required>
						<p class="ts-flyout__err" data-err="name" hidden></p>
					</div>
					<div class="ts-flyout__field">
						<label for="ts-fl-email"><?php esc_html_e( 'Email', 'travelstore-enquiries' ); ?> <span aria-hidden="true">*</span></label>
						<input type="email" id="ts-fl-email" name="email" autocomplete="email" maxlength="<?php echo esc_attr( self::MAX ); ?>" required>
						<p class="ts-flyout__err" data-err="email" hidden></p>
					</div>
					<div class="ts-flyout__field">
						<label for="ts-fl-phone"><?php esc_html_e( 'Phone / WhatsApp', 'travelstore-enquiries' ); ?> <em><?php esc_html_e( '(optional)', 'travelstore-enquiries' ); ?></em></label>
						<input type="tel" id="ts-fl-phone" name="phone" autocomplete="tel" maxlength="<?php echo esc_attr( self::MAX ); ?>" placeholder="+44 7700 900123">
					</div>
					<div class="ts-flyout__field">
						<label for="ts-fl-message"><?php esc_html_e( 'How can we help?', 'travelstore-enquiries' ); ?> <span aria-hidden="true">*</span></label>
						<textarea id="ts-fl-message" name="message" rows="4" maxlength="<?php echo esc_attr( self::MAX_MESSAGE ); ?>" required></textarea>
						<p class="ts-flyout__err" data-err="message" hidden></p>
					</div>
					<div class="ts-flyout__trap" aria-hidden="true">
						<label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
					</div>
					<div class="ts-flyout__field ts-flyout__field--check">
						<label><input type="checkbox" name="consent" value="yes" required> <span><?php esc_html_e( 'I agree that Travel Store Oman may use these details to reply to my enquiry.', 'travelstore-enquiries' ); ?></span></label>
						<p class="ts-flyout__err" data-err="consent" hidden></p>
					</div>
					<p class="ts-flyout__err ts-flyout__err--form" data-err="form" role="alert" hidden></p>
					<button type="submit" class="ts-flyout__submit"><?php esc_html_e( 'Send my message', 'travelstore-enquiries' ); ?></button>
					<p class="ts-flyout__note"><?php esc_html_e( 'We reply within one working day.', 'travelstore-enquiries' ); ?></p>
				</form>

				<div class="ts-flyout__done" tabindex="-1" role="status" hidden>
					<p class="ts-flyout__title"><?php esc_html_e( 'Thank you — we\'ve got it.', 'travelstore-enquiries' ); ?></p>
					<p><?php esc_html_e( 'Someone from our Muscat team will reply within one working day. If it\'s urgent, message us on WhatsApp.', 'travelstore-enquiries' ); ?></p>
					<?php if ( '' !== $wa_url ) : ?>
						<a class="ts-flyout__submit" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Message us on WhatsApp', 'travelstore-enquiries' ); ?></a>
					<?php endif; ?>
				</div>
				<?php Chatbot::panel(); ?>
				<?php do_action( 'ts_flyout_panels' ); // Extension point: print extra panel views here. ?>
			</div>

			<button type="button" class="ts-flyout__toggle" aria-expanded="false" aria-controls="ts-flyout-panel">
				<span class="ts-flyout__toggle-ico ts-flyout__toggle-ico--open" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26"><path fill="currentColor" d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2Zm-2 12H6v-2h12v2Zm0-3H6V9h12v2Zm0-3H6V6h12v2Z"/></svg></span>
				<span class="ts-flyout__toggle-ico ts-flyout__toggle-ico--close" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z"/></svg></span>
				<span class="ts-flyout__toggle-label"><?php esc_html_e( 'Contact us', 'travelstore-enquiries' ); ?></span>
			</button>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Receiving the short form                                            */
	/* ------------------------------------------------------------------ */

	public static function fresh_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( self::ACTION ) ) );
	}

	public static function ajax() {
		nocache_headers();
		$result = self::process( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in process().
		if ( 'received' === $result['code'] ) {
			wp_send_json_success( array( 'code' => 'received' ) );
		}
		wp_send_json_error( $result, 'invalid' === $result['code'] ? 422 : ( 'wait' === $result['code'] ? 429 : 400 ) );
	}

	/**
	 * @return array code: received | expired | invalid | wait | error; errors for invalid.
	 */
	public static function process( array $input ) {
		if ( ! self::enabled() ) {
			return array( 'code' => 'error' );
		}
		// Spam trap filled in: pretend all went well, save nothing.
		if ( ! empty( $input['website'] ) ) {
			return array( 'code' => 'received' );
		}
		if ( ! wp_verify_nonce( sanitize_text_field( (string) ( $input['nonce'] ?? '' ) ), self::ACTION ) ) {
			return array( 'code' => 'expired' );
		}

		$line = static function ( $key ) use ( $input ) {
			$value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : '';

			return mb_substr( $value, 0, self::MAX );
		};
		$errors = array();
		$data   = array(
			'enquiry_type' => __( 'Quick contact', 'travelstore-enquiries' ),
			'full_name'    => $line( 'full_name' ),
			'email'        => sanitize_email( $line( 'email' ) ),
			'phone'        => $line( 'phone' ),
		);
		if ( '' === $data['full_name'] ) {
			$errors['name'] = true;
		}
		if ( ! is_email( $data['email'] ) ) {
			$errors['email'] = true;
		}
		$message         = isset( $input['message'] ) && is_scalar( $input['message'] ) ? sanitize_textarea_field( (string) $input['message'] ) : '';
		$data['message'] = mb_substr( trim( $message ), 0, self::MAX_MESSAGE );
		if ( '' === $data['message'] ) {
			$errors['message'] = true;
		}
		$data['consent'] = ! empty( $input['consent'] ) ? 'yes' : '';
		if ( 'yes' !== $data['consent'] ) {
			$errors['consent'] = true;
		}
		if ( $errors ) {
			return array( 'code' => 'invalid', 'errors' => $errors );
		}

		$ip   = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		$wait = 'ts_enq_wait_' . md5( $ip );
		if ( get_transient( $wait ) ) {
			return array( 'code' => 'wait' );
		}

		$labels = array(
			'enquiry_type' => __( 'What can we help with?', 'travelstore-enquiries' ),
			'full_name'    => __( 'Your name', 'travelstore-enquiries' ),
			'email'        => __( 'Email', 'travelstore-enquiries' ),
			'phone'        => __( 'Phone / WhatsApp', 'travelstore-enquiries' ),
			'message'      => __( 'Message', 'travelstore-enquiries' ),
			'consent'      => __( 'Agreed to be contacted', 'travelstore-enquiries' ),
		);
		$rows = array();
		foreach ( $labels as $key => $label ) {
			$value  = 'consent' === $key ? __( 'Yes', 'travelstore-enquiries' ) : $data[ $key ];
			$rows[] = array( 'key' => $key, 'label' => $label, 'value' => (string) $value );
		}
		$clean = array(
			'ok'     => true,
			'errors' => array(),
			'data'   => $data,
			'rows'   => $rows,
			'name'   => $data['full_name'],
			'email'  => $data['email'],
			'type'   => $data['enquiry_type'],
		);

		$page_id = absint( $input['post_id'] ?? 0 );
		$post    = $page_id ? get_post( $page_id ) : null;
		if ( ! $post || 'publish' !== $post->post_status ) {
			$page_id = 0;
		}
		$id = Store::create(
			$clean,
			array(
				'page_id' => $page_id,
				'url'     => $page_id ? get_permalink( $page_id ) : home_url( '/' ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return array( 'code' => 'error' );
		}
		set_transient( $wait, 1, Submit::WAIT );
		Mailer::office( $id );

		return array( 'code' => 'received' );
	}
}
