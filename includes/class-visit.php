<?php
/**
 * Where the visitor came from (1.3.0). A small script (assets/visit.js) remembers how the visit began
 * for the open browser tab only (sessionStorage, no cookie): first page, referring site, campaign
 * tags, whether an ad click code was present (yes/no, the code itself is not kept), device and
 * browser language. Every enquiry form on the page sends it as the hidden field ts_visit; Alia sends
 * it in its hand-off. It is saved on the enquiry as _ts_enq_visit when the enquiry is created.
 * Hire bookings fall back to the hire plugin's own _tsh_src.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Visit {

	const META = '_ts_enq_visit';
	const KEYS = array(
		'landing'      => 191,
		'ref'          => 150,
		'utm_source'   => 100,
		'utm_medium'   => 100,
		'utm_campaign' => 150,
		'utm_content'  => 100,
		'utm_term'     => 100,
		'gads'         => 1,
		'meta'         => 1,
		'device'       => 10,
		'lang'         => 10,
		't'            => 13,
	);

	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ) );
	}

	public static function script() {
		$file = TSE_PATH . 'assets/visit.js';
		wp_enqueue_script( 'ts-enq-visit', TSE_URL . 'assets/visit.js', array(), file_exists( $file ) ? (string) filemtime( $file ) : TSE_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	/** Keep only known keys, as short plain text. */
	public static function clean( $raw ) {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( self::KEYS as $key => $max ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				$value = mb_substr( sanitize_text_field( (string) $raw[ $key ] ), 0, $max );
				if ( '' !== $value ) {
					$out[ $key ] = $value;
				}
			}
		}
		if ( isset( $out['ref'] ) ) {
			$host       = wp_parse_url( ( false === strpos( $out['ref'], '//' ) ? 'https://' : '' ) . $out['ref'], PHP_URL_HOST );
			$out['ref'] = $host ? preg_replace( '/^www\./', '', strtolower( $host ) ) : '';
		}
		if ( isset( $out['landing'] ) ) {
			$out['landing'] = '/' . ltrim( (string) wp_parse_url( $out['landing'], PHP_URL_PATH ), '/' );
		}

		return array_filter( $out, 'strlen' );
	}

	/** Called when an enquiry is created: from the hand-off ($source['visit']) or the form field. */
	public static function save( $id, $source ) {
		$raw = is_array( $source ) && isset( $source['visit'] ) ? $source['visit'] : null;
		if ( null === $raw && isset( $_POST['ts_visit'] ) && is_string( $_POST['ts_visit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- the submission itself was checked by its handler.
			$raw = wp_unslash( $_POST['ts_visit'] ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- cleaned below.
		}
		$visit = self::clean( $raw );
		if ( $visit ) {
			update_post_meta( $id, self::META, $visit );
		}
	}

	/** The visit of one enquiry (empty array when not recorded). */
	public static function of( $id ) {
		$visit = get_post_meta( $id, self::META, true );
		if ( is_array( $visit ) && $visit ) {
			return $visit;
		}
		$src = get_post_meta( $id, '_tsh_src', true );
		if ( is_array( $src ) && ( ! empty( $src['landing'] ) || ! empty( $src['ref'] ) || ! empty( $src['utm_source'] ) ) ) {
			return self::clean( array_intersect_key( $src, self::KEYS ) );
		}

		return array();
	}

	public static function channels() {
		return array(
			'google_ads' => __( 'Google Ads', 'travelstore-enquiries' ),
			'meta_ads'   => __( 'Facebook / Instagram ads', 'travelstore-enquiries' ),
			'search'     => __( 'Search engines', 'travelstore-enquiries' ),
			'social'     => __( 'Social media', 'travelstore-enquiries' ),
			'email'      => __( 'Email', 'travelstore-enquiries' ),
			'campaign'   => __( 'Other campaigns', 'travelstore-enquiries' ),
			'referral'   => __( 'Other websites', 'travelstore-enquiries' ),
			'direct'     => __( 'Direct (typed or bookmarked)', 'travelstore-enquiries' ),
			''           => __( 'Not recorded', 'travelstore-enquiries' ),
		);
	}

	/** The automatic channel of a visit (a key of channels()). */
	public static function channel( array $v ) {
		if ( ! $v ) {
			return '';
		}
		$src    = strtolower( $v['utm_source'] ?? '' );
		$medium = strtolower( $v['utm_medium'] ?? '' );
		$ref    = strtolower( $v['ref'] ?? '' );
		$paid   = (bool) preg_match( '/^(cpc|ppc|paid|paidsocial|paid_social|paid-social|display|cpm|ads?)$/', $medium );
		$social = '/(facebook|instagram|\bfb\b|\big\b|tiktok|youtube|twitter|^x$|linkedin|pinterest|snapchat|t\.co|lnkd|whatsapp)/';
		$search = '/(google|bing|yahoo|duckduckgo|ecosia|yandex|baidu|naver|search)/';
		if ( ! empty( $v['gads'] ) || ( $paid && preg_match( '/google|adwords/', $src ) ) ) {
			return 'google_ads';
		}
		if ( $paid && preg_match( '/facebook|instagram|\bfb\b|\big\b|meta/', $src ) ) {
			return 'meta_ads';
		}
		if ( '' !== $medium && preg_match( '/e-?mail|newsletter|mailchimp/', $medium . ' ' . $src ) ) {
			return 'email';
		}
		if ( '' !== $src ) {
			if ( preg_match( $social, $src ) ) {
				return 'social';
			}
			if ( preg_match( $search, $src ) && ! $paid ) {
				return 'search';
			}
			return 'campaign';
		}
		if ( ! empty( $v['meta'] ) ) {
			return 'social';
		}
		if ( '' !== $ref ) {
			if ( preg_match( '/^(mail\.|outlook\.|webmail)/', $ref ) ) {
				return 'email';
			}
			if ( preg_match( $social, $ref ) ) {
				return 'social';
			}
			if ( preg_match( $search, $ref ) ) {
				return 'search';
			}
			return 'referral';
		}

		return 'direct';
	}
}
