<?php
/**
 * Receives the TS Enquiry Form: checks it, saves it, then emails the office.
 *
 * Order matters: the enquiry is saved before any email, so it is never lost when email fails
 * (the visitor still sees the thank-you message; the team sees the failure in WordPress and in
 * the daily summary). Protection: a hidden spam-trap field, a security code (nonce), a check that
 * the form really is on that page, and one enquiry per visitor every 30 seconds.
 */

namespace TravelStore\Enquiries;

use TravelStore\TS_Enquiry_Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Submit {

	const ACTION     = 'ts_enquiry_submit';
	const WAIT       = 30;

	public static function register() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'fallback' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'fallback' ) );
		add_action( 'wp_ajax_ts_enquiry_nonce', array( __CLASS__, 'fresh_nonce' ) );
		add_action( 'wp_ajax_nopriv_ts_enquiry_nonce', array( __CLASS__, 'fresh_nonce' ) );
	}

	/**
	 * A page kept in a cache can carry an old security code; the form asks for a new one and retries once.
	 */
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
	 * Without JavaScript: normal post, then back to the page with the result.
	 */
	public static function fallback() {
		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked in process().
		$result = self::process( $input );
		$code   = in_array( $result['code'], array( 'received', 'invalid', 'wait' ), true ) ? $result['code'] : 'error';
		$page   = absint( $input['post_id'] ?? 0 );
		$back   = wp_get_referer();
		if ( ! $back ) {
			$back = $page ? get_permalink( $page ) : home_url( '/' );
		}
		$widget = sanitize_key( $input['widget_id'] ?? '' );
		$url    = add_query_arg(
			array(
				'ts_enquiry'   => $code,
				'ts_enquiry_w' => $widget,
			),
			remove_query_arg( array( 'ts_enquiry', 'ts_enquiry_w' ), $back )
		);
		wp_safe_redirect( $url . '#ts-enq-' . $widget, 303 );
		exit;
	}

	/**
	 * @return array code: received | expired | unverified | invalid | wait | error; errors and message for invalid.
	 */
	public static function process( array $input ) {
		// Spam trap filled in: pretend all went well, save nothing.
		if ( ! empty( $input['website'] ) ) {
			return array( 'code' => 'received' );
		}
		if ( ! wp_verify_nonce( sanitize_text_field( (string) ( $input['nonce'] ?? '' ) ), self::ACTION ) ) {
			return array( 'code' => 'expired' );
		}
		if ( ! class_exists( '\TravelStore\TS_Enquiry_Fields' ) ) {
			return array( 'code' => 'error' );
		}
		$page_id   = absint( $input['post_id'] ?? 0 );
		$widget_id = sanitize_key( (string) ( $input['widget_id'] ?? '' ) );
		$settings  = self::widget_settings( $page_id, $widget_id );
		if ( null === $settings ) {
			return array( 'code' => 'unverified' );
		}

		$clean = TS_Enquiry_Fields::clean( $settings, $input );
		if ( ! $clean['ok'] ) {
			return array(
				'code'    => 'invalid',
				'errors'  => $clean['errors'],
				'message' => TS_Enquiry_Fields::text( TS_Enquiry_Fields::settings( $settings ), 'err_summary' ),
			);
		}

		$ip   = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		$wait = 'ts_enq_wait_' . md5( $ip );
		if ( get_transient( $wait ) ) {
			return array( 'code' => 'wait' );
		}

		$id = Store::create(
			$clean,
			array(
				'page_id' => $page_id,
				'url'     => get_permalink( $page_id ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return array( 'code' => 'error' );
		}
		set_transient( $wait, 1, self::WAIT );
		Mailer::office( $id );

		return array( 'code' => 'received' );
	}

	/**
	 * The settings of the TS Enquiry Form $widget_id on page $page_id, or null when the form is not there.
	 * Only published pages count (logged-in editors may also test on a page they can edit).
	 */
	private static function widget_settings( $page_id, $widget_id ) {
		if ( ! $page_id || '' === $widget_id || ! class_exists( '\Elementor\Plugin' ) ) {
			return null;
		}
		$post = get_post( $page_id );
		if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'edit_post', $page_id ) ) ) {
			return null;
		}
		$document = \Elementor\Plugin::$instance->documents->get( $page_id );
		if ( ! $document ) {
			return null;
		}
		$found = self::find( (array) $document->get_elements_data(), $widget_id );

		return null === $found ? null : ( isset( $found['settings'] ) && is_array( $found['settings'] ) ? $found['settings'] : array() );
	}

	private static function find( array $elements, $widget_id ) {
		foreach ( $elements as $element ) {
			if ( ( $element['id'] ?? '' ) === $widget_id && TS_Enquiry_Fields::WIDGET === ( $element['widgetType'] ?? '' ) ) {
				return $element;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$found = self::find( $element['elements'], $widget_id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}
}
