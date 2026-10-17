<?php
/**
 * Enquiries are saved as private posts of the type ts_enquiry (visible to administrators only).
 * Each one keeps what the customer sent (with the labels the form showed), its status, who it is
 * assigned to, internal notes, the email results and a history of what the team did.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Store {

	const POST_TYPE = 'ts_enquiry';
	const META      = '_ts_enq_';

	public static function statuses() {
		// The pipeline, in the order an enquiry normally moves through it, then the three ways it can end.
		return array(
			'new'              => __( 'New', 'travelstore-enquiries' ),
			'assigned'         => __( 'Assigned', 'travelstore-enquiries' ),
			'acknowledged'     => __( 'Acknowledged', 'travelstore-enquiries' ),
			'in_progress'      => __( 'In progress', 'travelstore-enquiries' ),
			'confirmed'        => __( 'Confirmed', 'travelstore-enquiries' ),
			'booked'           => __( 'Booked', 'travelstore-enquiries' ),
			'payment_received' => __( 'Payment received', 'travelstore-enquiries' ),
			'closed'           => __( 'Closed', 'travelstore-enquiries' ),
			'dropped'          => __( 'Dropped', 'travelstore-enquiries' ),
			'rejected'         => __( 'Rejected by client', 'travelstore-enquiries' ),
		);
	}

	/**
	 * Statuses where the enquiry is finished and needs no more work.
	 *
	 * @return string[]
	 */
	public static function finished_statuses() {
		return array( 'closed', 'dropped', 'rejected' );
	}

	/**
	 * Statuses where the enquiry is still being worked on.
	 *
	 * @return string[]
	 */
	public static function active_statuses() {
		return array_values( array_diff( array_keys( self::statuses() ), self::finished_statuses() ) );
	}

	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	public static function register_post_type() {
		$admin = 'manage_options';
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Enquiries', 'travelstore-enquiries' ),
					'singular_name' => __( 'Enquiry', 'travelstore-enquiries' ),
					'menu_name'     => __( 'Enquiries', 'travelstore-enquiries' ),
					'all_items'     => __( 'All enquiries', 'travelstore-enquiries' ),
					'edit_item'     => __( 'Enquiry', 'travelstore-enquiries' ),
					'view_item'     => __( 'View enquiry', 'travelstore-enquiries' ),
					'search_items'  => __( 'Search enquiries', 'travelstore-enquiries' ),
					'not_found'     => __( 'No enquiries yet.', 'travelstore-enquiries' ),
					'not_found_in_trash' => __( 'No enquiries in the Trash.', 'travelstore-enquiries' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'menu_position'       => 26,
				'menu_icon'           => 'dashicons-email-alt',
				'supports'            => array( 'title' ),
				'map_meta_cap'        => false,
				'capabilities'        => array(
					'edit_post'              => $admin,
					'read_post'              => $admin,
					'delete_post'            => $admin,
					'edit_posts'             => $admin,
					'edit_others_posts'      => $admin,
					'publish_posts'          => $admin,
					'read_private_posts'     => $admin,
					'delete_posts'           => $admin,
					'delete_private_posts'   => $admin,
					'delete_published_posts' => $admin,
					'delete_others_posts'    => $admin,
					'edit_private_posts'     => $admin,
					'edit_published_posts'   => $admin,
					'create_posts'           => 'do_not_allow',
				),
			)
		);
	}

	/**
	 * Save a checked submission (TS_Enquiry_Fields::clean() result).
	 *
	 * @return int|\WP_Error
	 */
	public static function create( array $clean, array $source ) {
		/*
		 * 1.1.1: other plugins may add lines before saving (Travel Store Promotions adds "Offer: …"
		 * when the visitor came from an offer). Keep the same array shape.
		 */
		$filtered = apply_filters( 'ts_enquiry_before_save', $clean, $source );
		if ( is_array( $filtered ) && isset( $filtered['rows'], $filtered['data'] ) ) {
			$clean = $filtered;
		}
		$title = trim( $clean['name'] . ( '' !== $clean['type'] ? ' — ' . $clean['type'] : '' ) );
		$id    = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => sanitize_text_field( '' !== $title ? $title : __( 'Website enquiry', 'travelstore-enquiries' ) ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$data = $clean['data'];
		$meta = array(
			'rows'          => $clean['rows'],
			'data'          => $data,
			'name'          => $clean['name'],
			'email'         => $clean['email'],
			'phone'         => isset( $data['phone'] ) ? $data['phone'] : '',
			'type'          => $clean['type'],
			'date_from'     => isset( $data['date_from'] ) ? $data['date_from'] : '',
			'date_to'       => isset( $data['date_to'] ) ? $data['date_to'] : '',
			'status'        => 'new',
			'assigned'      => '',
			'notes'         => '',
			'unread'        => 1,
			'office_status' => 'pending',
			'office_error'  => '',
			'ack_at'        => '',
			'ack_by'        => '',
			'ack_count'     => 0,
			'source_page'   => absint( $source['page_id'] ),
			'source_url'    => esc_url_raw( (string) $source['url'] ),
			'consent_at'    => 'yes' === ( $data['consent'] ?? '' ) ? current_time( 'mysql' ) : '',
			'log'           => array(),
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, self::META . $key, $value );
		}
		self::log( $id, __( 'Enquiry received from the website.', 'travelstore-enquiries' ), false );

		return $id;
	}

	public static function get( $id, $key ) {
		return get_post_meta( $id, self::META . $key, true );
	}

	public static function set( $id, $key, $value ) {
		update_post_meta( $id, self::META . $key, $value );
	}

	public static function is_enquiry( $id ) {
		$post = get_post( $id );

		return $post && self::POST_TYPE === $post->post_type;
	}

	public static function status( $id ) {
		$status = (string) self::get( $id, 'status' );

		return array_key_exists( $status, self::statuses() ) ? $status : 'new';
	}

	/**
	 * Add a line to the enquiry's history, with the time and (by default) the logged-in user.
	 */
	public static function log( $id, $text, $with_user = true ) {
		$log   = self::get( $id, 'log' );
		$log   = is_array( $log ) ? $log : array();
		$user  = $with_user ? wp_get_current_user() : null;
		$log[] = array(
			'time' => time(),
			'user' => $user && $user->exists() ? $user->display_name : '',
			'text' => $text,
		);
		self::set( $id, 'log', $log );
	}

	public static function first_name( $id ) {
		$name  = trim( (string) self::get( $id, 'name' ) );
		$parts = preg_split( '/\s+/', $name );

		return '' !== $name ? $parts[0] : '';
	}

	public static function count_unread() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
				WHERE p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' ) AND m.meta_key = %s AND m.meta_value = '1'",
				self::POST_TYPE,
				self::META . 'unread'
			)
		);
	}

	public static function view_url( $id ) {
		return add_query_arg(
			array(
				'post_type' => self::POST_TYPE,
				'page'      => Admin::VIEW,
				'id'        => (int) $id,
			),
			admin_url( 'edit.php' )
		);
	}
}
