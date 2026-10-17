<?php
/**
 * "Ask about Oman": a rule-based FAQ assistant inside the contact flyout. No AI and no outside service.
 *
 * The answers live in data/oman-faq.json (plain file, edited by hand). The matching runs in the visitor's
 * browser (assets/chatbot.js), so questions are never sent to or stored on the server. This class only
 * prints the chat panel markup and hands the FAQ file plus the contact options to the script.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chatbot {

	const ACTION = 'ts_chat_data';

	public static function register() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'ajax' ) );
	}

	public static function enabled() {
		return 'yes' === Settings::get( 'chat_on' ) && is_readable( self::file() );
	}

	private static function file() {
		return TSE_PATH . 'data/oman-faq.json';
	}

	/** Script and style for the chat; called from the flyout's asset hook. */
	public static function assets() {
		if ( ! self::enabled() ) {
			return;
		}
		$css = TSE_PATH . 'assets/chatbot.css';
		$js  = TSE_PATH . 'assets/chatbot.js';
		wp_enqueue_style( 'ts-chatbot', TSE_URL . 'assets/chatbot.css', array( 'ts-flyout' ), file_exists( $css ) ? (string) filemtime( $css ) : TSE_VERSION );
		wp_enqueue_script( 'ts-chatbot', TSE_URL . 'assets/chatbot.js', array( 'ts-flyout' ), file_exists( $js ) ? (string) filemtime( $js ) : TSE_VERSION, true );
		wp_localize_script(
			'ts-chatbot',
			'tsChat',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::ACTION,
			)
		);
	}

	/** The chat panel, printed inside the flyout panel next to the form. */
	public static function panel() {
		if ( ! self::enabled() ) {
			return;
		}
		?>
		<section class="ts-chat" id="ts-chat" aria-labelledby="ts-chat-title" hidden>
			<button type="button" class="ts-flyout__back" data-ts-chat-back><?php esc_html_e( '← Back', 'travelstore-enquiries' ); ?></button>
			<p class="ts-flyout__title" id="ts-chat-title"><?php esc_html_e( 'Ask about Oman', 'travelstore-enquiries' ); ?></p>
			<p class="ts-chat__notice"><?php esc_html_e( 'This is an automated assistant for general questions about Oman. Please do not share personal details here. Your questions are not saved.', 'travelstore-enquiries' ); ?></p>
			<div class="ts-chat__log" id="ts-chat-log" role="log" aria-live="polite" aria-relevant="additions" tabindex="0"></div>
			<div class="ts-chat__chips" id="ts-chat-chips"></div>
			<form class="ts-chat__form" id="ts-chat-form" novalidate>
				<label class="ts-chat__sr" for="ts-chat-input"><?php esc_html_e( 'Your question about Oman', 'travelstore-enquiries' ); ?></label>
				<input type="text" id="ts-chat-input" name="q" maxlength="200" autocomplete="off" placeholder="<?php echo esc_attr__( 'Type your question…', 'travelstore-enquiries' ); ?>">
				<button type="submit" class="ts-chat__send"><?php esc_html_e( 'Send', 'travelstore-enquiries' ); ?></button>
			</form>
		</section>
		<?php
	}

	public static function ajax() {
		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'code' => 'off' ), 404 );
		}
		$raw  = file_get_contents( self::file() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || empty( $data['faqs'] ) || ! is_array( $data['faqs'] ) ) {
			wp_send_json_error( array( 'code' => 'bad_file' ), 500 );
		}

		$wa      = preg_replace( '/\D+/', '', (string) Settings::get( 'flyout_whatsapp' ) );
		$wa_text = trim( (string) Settings::get( 'flyout_wa_text' ) );
		$phone   = trim( (string) Settings::get( 'flyout_phone' ) );
		$contact = get_page_by_path( 'contact' );

		$out = array(
			'settings' => isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : array(),
			'faqs'     => $data['faqs'],
			'contact'  => array(
				'phone'      => $phone,
				'tel'        => preg_replace( '/[^\d+]/', '', $phone ),
				'whatsapp'   => '' !== $wa ? 'https://wa.me/' . $wa . ( '' !== $wa_text ? '?text=' . rawurlencode( $wa_text ) : '' ) : '',
				'email'      => Settings::office_email(),
				'contactUrl' => ( $contact && 'publish' === $contact->post_status ) ? get_permalink( $contact ) : '',
			),
		);
		unset( $out['settings']['_how_to_edit'] );

		nocache_headers();
		wp_send_json_success( $out );
	}
}
