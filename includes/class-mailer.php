<?php
/**
 * Emails, all plain text and all sent through wp_mail(), so the site's mail plugin (WP Mail SMTP,
 * FluentSMTP) delivers them:
 *  - office(): every new enquiry, to the office address (automatic);
 *  - acknowledge(): to the customer, only when a team member clicks the button (DEC-020).
 *
 * The office email has no Reply-To header: on the other site (BDH) a free-mail Reply-To (gmail,
 * hotmail) made the host's spam filter flag enquiries. The customer's address is in the body instead.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mailer {

	/**
	 * @return array ok (bool), error (string)
	 */
	public static function send( $to, $subject, $body, $headers = array() ) {
		$error    = '';
		$listener = static function ( $wp_error ) use ( &$error ) {
			if ( is_wp_error( $wp_error ) ) {
				$error = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $listener );
		$ok = wp_mail( $to, $subject, $body, array_merge( array( 'Content-Type: text/plain; charset=UTF-8' ), $headers ) );
		remove_action( 'wp_mail_failed', $listener );

		return array(
			'ok'    => (bool) $ok,
			'error' => $ok ? '' : ( '' !== $error ? $error : __( 'The mail system did not accept the email.', 'travelstore-enquiries' ) ),
		);
	}

	private static function site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * What the customer sent, one "Label: value" per line; the message on its own lines.
	 */
	public static function rows_text( $id, $indent = '' ) {
		$rows  = Store::get( $id, 'rows' );
		$lines = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( '' === $row['value'] ) {
				continue;
			}
			if ( 'message' === $row['key'] ) {
				$lines[] = '';
				$lines[] = $indent . $row['label'] . ':';
				foreach ( preg_split( '/\r\n|\r|\n/', $row['value'] ) as $line ) {
					$lines[] = $indent . $line;
				}
				$lines[] = '';
				continue;
			}
			$lines[] = $indent . $row['label'] . ': ' . $row['value'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * New enquiry to the office. Records the result on the enquiry.
	 */
	public static function office( $id ) {
		$name    = (string) Store::get( $id, 'name' );
		$type    = (string) Store::get( $id, 'type' );
		$subject = sprintf( __( 'New enquiry: %1$s – %2$s', 'travelstore-enquiries' ), $type, mb_substr( $name, 0, 60 ) );
		$post    = get_post( $id );
		$body    = implode(
			"\n",
			array(
				sprintf( __( 'New enquiry from the %1$s website, %2$s.', 'travelstore-enquiries' ), self::site_name(), wp_date( 'j M Y, H:i', strtotime( $post->post_date_gmt . ' UTC' ) ) ),
				'',
				self::rows_text( $id ),
				'',
				sprintf( __( 'Sent from: %s', 'travelstore-enquiries' ), (string) Store::get( $id, 'source_url' ) ),
				sprintf( __( 'Open it in WordPress: %s', 'travelstore-enquiries' ), Store::view_url( $id ) ),
				'',
				__( 'The customer has not been emailed. When someone has taken this enquiry, assign it in WordPress and click "Send acknowledgement email".', 'travelstore-enquiries' ),
			)
		);
		$result = self::send( Settings::office_email(), $subject, $body );
		Store::set( $id, 'office_status', $result['ok'] ? 'sent' : 'failed' );
		Store::set( $id, 'office_error', $result['error'] );
		Store::set( $id, 'office_to', Settings::office_email() );
		Store::log(
			$id,
			$result['ok']
				? sprintf( __( 'Emailed to the office (%s).', 'travelstore-enquiries' ), Settings::office_email() )
				: sprintf( __( 'Email to the office (%1$s) failed: %2$s', 'travelstore-enquiries' ), Settings::office_email(), $result['error'] ),
			false
		);

		return $result;
	}

	/**
	 * The acknowledgement text for an enquiry (also shown as a preview on the enquiry screen).
	 */
	public static function acknowledgement_body( $id ) {
		$o     = Settings::all();
		$first = Store::first_name( $id );
		$parts = array();
		$greet = trim( (string) $o['ack_greeting'] );
		$parts[] = trim( $greet . ( '' !== $first ? ' ' . $first : '' ) ) . ',';
		$parts[] = '';
		$parts[] = trim( (string) $o['ack_message'] );
		$staff   = (string) Store::get( $id, 'assigned' );
		if ( 'yes' === $o['ack_mention_staff'] && '' !== $staff ) {
			$parts[] = '';
			$parts[] = sprintf( __( 'Your enquiry is being looked after by %s.', 'travelstore-enquiries' ), $staff );
		}
		if ( 'yes' === $o['ack_include_copy'] ) {
			$parts[] = '';
			$parts[] = __( 'For your reference, this is what you sent us:', 'travelstore-enquiries' );
			$parts[] = '';
			$parts[] = self::rows_text( $id, '  ' );
		}
		if ( '' !== trim( (string) $o['ack_signature'] ) ) {
			$parts[] = '';
			$parts[] = trim( (string) $o['ack_signature'] );
		}

		return preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $parts ) );
	}

	/**
	 * Acknowledgement to the customer (only ever called from the team's button). Replies go to the office.
	 */
	public static function acknowledge( $id ) {
		$to = sanitize_email( (string) Store::get( $id, 'email' ) );
		if ( ! is_email( $to ) ) {
			return array( 'ok' => false, 'error' => __( 'The customer\'s email address is not valid.', 'travelstore-enquiries' ) );
		}
		$subject = (string) Settings::get( 'ack_subject' );
		$result  = self::send( $to, '' !== $subject ? $subject : self::site_name(), self::acknowledgement_body( $id ), array( 'Reply-To: ' . Settings::office_email() ) );
		if ( $result['ok'] ) {
			$user = wp_get_current_user();
			Store::set( $id, 'ack_at', current_time( 'mysql' ) );
			Store::set( $id, 'ack_by', $user->exists() ? $user->display_name : '' );
			Store::set( $id, 'ack_count', (int) Store::get( $id, 'ack_count' ) + 1 );
			Store::log( $id, sprintf( __( 'Acknowledgement email sent to %s.', 'travelstore-enquiries' ), $to ) );
		} else {
			Store::log( $id, sprintf( __( 'Acknowledgement email to %1$s failed: %2$s', 'travelstore-enquiries' ), $to, $result['error'] ) );
		}

		return $result;
	}
}
