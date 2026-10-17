<?php
/**
 * Admin screens: Enquiries list (with an unread count on the menu), one screen per enquiry
 * (what was sent; Assigned to, Status, Internal notes; the acknowledgement button; history), Settings.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const VIEW = 'ts-enquiry';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu_badge' ), 999 );
		add_action( 'admin_head', array( __CLASS__, 'hide_view_item' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'highlight' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_ts_enquiry_update', array( __CLASS__, 'update' ) );
		add_action( 'load-post.php', array( __CLASS__, 'redirect_editor' ) );
		add_filter( 'get_edit_post_link', array( __CLASS__, 'edit_link' ), 10, 2 );

		$type = Store::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'list_table_primary_column', array( __CLASS__, 'primary_column' ), 10, 2 );
		add_filter( "bulk_actions-edit-{$type}", array( __CLASS__, 'bulk_actions' ) );
		add_filter( "handle_bulk_actions-edit-{$type}", array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'status_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filter' ) );
		add_filter( "views_edit-{$type}", array( __CLASS__, 'list_views' ) );
		add_action( 'admin_notices', array( __CLASS__, 'list_notices' ) );
	}

	/* ------------------------------------------------------------------ Menu */

	public static function menu() {
		$parent = 'edit.php?post_type=' . Store::POST_TYPE;
		add_submenu_page( $parent, __( 'Enquiry', 'travelstore-enquiries' ), __( 'Enquiry', 'travelstore-enquiries' ), 'manage_options', self::VIEW, array( __CLASS__, 'render_view' ) );
		add_submenu_page( $parent, __( 'Enquiry settings', 'travelstore-enquiries' ), __( 'Settings', 'travelstore-enquiries' ), 'manage_options', Settings::PAGE, array( '\TravelStore\Enquiries\Settings', 'render_page' ) );
	}

	/**
	 * The single-enquiry screen is reached from the list, not from the menu.
	 */
	public static function hide_view_item() {
		remove_submenu_page( 'edit.php?post_type=' . Store::POST_TYPE, self::VIEW );
	}

	public static function highlight( $submenu_file ) {
		if ( isset( $_GET['page'] ) && self::VIEW === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return 'edit.php?post_type=' . Store::POST_TYPE;
		}

		return $submenu_file;
	}

	public static function menu_badge() {
		global $menu;
		if ( ! current_user_can( 'manage_options' ) || ! is_array( $menu ) ) {
			return;
		}
		$count = Store::count_unread();
		if ( ! $count ) {
			return;
		}
		$badge = sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>', $count, esc_html( sprintf( _n( '%d unread enquiry', '%d unread enquiries', $count, 'travelstore-enquiries' ), $count ) ) );
		foreach ( $menu as $i => $item ) {
			if ( isset( $item[2] ) && 'edit.php?post_type=' . Store::POST_TYPE === $item[2] ) {
				$menu[ $i ][0] .= $badge; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		}
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( $screen && Store::POST_TYPE === $screen->post_type ) {
			wp_enqueue_style( 'ts-enquiries-admin', TSE_URL . 'assets/admin.css', array(), TSE_VERSION );
		}
	}

	/* ------------------------------------------------------------------ Links */

	public static function edit_link( $link, $post_id ) {
		return Store::is_enquiry( $post_id ) ? Store::view_url( $post_id ) : $link;
	}

	/**
	 * Enquiries are not edited in the post editor: send it to the enquiry screen.
	 */
	public static function redirect_editor() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id && Store::is_enquiry( $id ) && ( ! isset( $_GET['action'] ) || 'edit' === $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( Store::view_url( $id ) );
			exit;
		}
	}

	/* ------------------------------------------------------------------ List */

	public static function columns( $columns ) {
		return array(
			'cb'           => $columns['cb'],
			'ts_received'  => __( 'Received', 'travelstore-enquiries' ),
			'ts_name'      => __( 'Name', 'travelstore-enquiries' ),
			'ts_type'      => __( 'Enquiry', 'travelstore-enquiries' ),
			'ts_dates'     => __( 'Travel dates', 'travelstore-enquiries' ),
			'ts_status'    => __( 'Status', 'travelstore-enquiries' ),
			'ts_assigned'  => __( 'Assigned to', 'travelstore-enquiries' ),
			'ts_ack'       => __( 'Acknowledgement', 'travelstore-enquiries' ),
			'ts_office'    => __( 'Office email', 'travelstore-enquiries' ),
		);
	}

	public static function column( $column, $id ) {
		switch ( $column ) {
			case 'ts_received':
				$post = get_post( $id );
				echo esc_html( wp_date( 'j M Y, H:i', strtotime( $post->post_date_gmt . ' UTC' ) ) );
				break;
			case 'ts_name':
				$unread = '1' === (string) Store::get( $id, 'unread' );
				printf(
					'<a class="row-title" href="%1$s">%2$s</a>%3$s<br><span class="ts-enq-muted">%4$s</span>',
					esc_url( Store::view_url( $id ) ),
					$unread ? '<strong>' . esc_html( (string) Store::get( $id, 'name' ) ) . '</strong>' : esc_html( (string) Store::get( $id, 'name' ) ),
					$unread ? ' <span class="ts-enq-tag ts-enq-tag--new">' . esc_html__( 'Unread', 'travelstore-enquiries' ) . '</span>' : '',
					esc_html( (string) Store::get( $id, 'email' ) )
				);
				break;
			case 'ts_type':
				echo esc_html( (string) Store::get( $id, 'type' ) );
				break;
			case 'ts_dates':
				echo esc_html( self::dates( $id ) );
				break;
			case 'ts_status':
				$status = Store::status( $id );
				echo '<span class="ts-enq-tag ts-enq-tag--' . esc_attr( $status ) . '">' . esc_html( Store::statuses()[ $status ] ) . '</span>';
				break;
			case 'ts_assigned':
				$staff = (string) Store::get( $id, 'assigned' );
				echo '' !== $staff ? esc_html( $staff ) : '<span class="ts-enq-muted">—</span>';
				break;
			case 'ts_ack':
				$at = (string) Store::get( $id, 'ack_at' );
				echo '' !== $at ? esc_html( sprintf( __( 'Sent %s', 'travelstore-enquiries' ), mysql2date( 'j M, H:i', $at ) ) ) : '<span class="ts-enq-muted">' . esc_html__( 'Not sent', 'travelstore-enquiries' ) . '</span>';
				break;
			case 'ts_office':
				$office = (string) Store::get( $id, 'office_status' );
				if ( 'sent' === $office ) {
					esc_html_e( 'Sent', 'travelstore-enquiries' );
				} elseif ( 'failed' === $office ) {
					echo '<span class="ts-enq-tag ts-enq-tag--failed">' . esc_html__( 'Failed', 'travelstore-enquiries' ) . '</span>';
				} else {
					echo '<span class="ts-enq-muted">' . esc_html__( 'Not sent', 'travelstore-enquiries' ) . '</span>';
				}
				break;
		}
	}

	public static function dates( $id ) {
		$from = (string) Store::get( $id, 'date_from' );
		$to   = (string) Store::get( $id, 'date_to' );
		$fmt  = static function ( $d ) {
			return '' !== $d ? wp_date( 'j M Y', strtotime( $d . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ) : '';
		};
		if ( '' === $from && '' === $to ) {
			return '—';
		}

		return trim( $fmt( $from ) . ' – ' . $fmt( $to ), ' –' );
	}

	/**
	 * "Open | Trash" under the name.
	 */
	public static function primary_column( $column, $screen ) {
		return 'edit-' . Store::POST_TYPE === $screen ? 'ts_name' : $column;
	}

	public static function row_actions( $actions, $post ) {
		if ( Store::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		$keep = array();
		if ( 'trash' !== $post->post_status ) {
			$keep['view'] = '<a href="' . esc_url( Store::view_url( $post->ID ) ) . '">' . esc_html__( 'Open', 'travelstore-enquiries' ) . '</a>';
		}
		foreach ( array( 'trash', 'untrash', 'delete' ) as $key ) {
			if ( isset( $actions[ $key ] ) ) {
				$keep[ $key ] = $actions[ $key ];
			}
		}

		return $keep;
	}

	public static function bulk_actions( $actions ) {
		unset( $actions['edit'] );
		$actions['ts_mark_read'] = __( 'Mark as read', 'travelstore-enquiries' );
		foreach ( Settings::staff() as $name ) {
			/* translators: %s: staff name. */
			$actions[ 'ts_assign_' . md5( $name ) ] = sprintf( __( 'Assign to %s', 'travelstore-enquiries' ), $name );
		}
		foreach ( Store::statuses() as $key => $label ) {
			if ( 'new' !== $key ) {
				/* translators: %s: status. */
				$actions[ 'ts_status_' . $key ] = sprintf( __( 'Set status: %s', 'travelstore-enquiries' ), $label );
			}
		}

		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $ids ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $redirect;
		}
		if ( 'ts_mark_read' === $action ) {
			foreach ( $ids as $id ) {
				if ( Store::is_enquiry( $id ) ) {
					Store::set( $id, 'unread', 0 );
				}
			}

			return add_query_arg( 'ts_marked', count( $ids ), $redirect );
		}

		$statuses = Store::statuses();
		$changed  = 0;
		if ( 0 === strpos( $action, 'ts_assign_' ) ) {
			$hash = substr( $action, strlen( 'ts_assign_' ) );
			$who  = '';
			foreach ( Settings::staff() as $name ) {
				if ( md5( $name ) === $hash ) {
					$who = $name;
				}
			}
			if ( '' === $who ) {
				return $redirect;
			}
			foreach ( $ids as $id ) {
				if ( ! Store::is_enquiry( $id ) || (string) Store::get( $id, 'assigned' ) === $who ) {
					continue;
				}
				Store::set( $id, 'assigned', $who );
				Store::log( $id, sprintf( __( 'Assigned to %s.', 'travelstore-enquiries' ), $who ) );
				if ( 'new' === Store::status( $id ) ) {
					Store::set( $id, 'status', 'assigned' );
					Store::log( $id, sprintf( __( 'Status: %s.', 'travelstore-enquiries' ), $statuses['assigned'] ) );
				}
				$changed++;
			}

			return add_query_arg( 'ts_bulk', $changed, $redirect );
		}
		if ( 0 === strpos( $action, 'ts_status_' ) ) {
			$key = substr( $action, strlen( 'ts_status_' ) );
			if ( ! isset( $statuses[ $key ] ) ) {
				return $redirect;
			}
			foreach ( $ids as $id ) {
				if ( ! Store::is_enquiry( $id ) || Store::status( $id ) === $key ) {
					continue;
				}
				Store::set( $id, 'status', $key );
				Store::log( $id, sprintf( __( 'Status: %s.', 'travelstore-enquiries' ), $statuses[ $key ] ) );
				$changed++;
			}

			return add_query_arg( 'ts_bulk', $changed, $redirect );
		}

		return $redirect;
	}

	public static function list_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . Store::POST_TYPE !== $screen->id ) {
			return;
		}
		if ( isset( $_GET['ts_marked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Marked as read.', 'travelstore-enquiries' ) . '</p></div>';
		}
		if ( isset( $_GET['ts_bulk'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d enquiry updated.', '%d enquiries updated.', absint( $_GET['ts_bulk'] ), 'travelstore-enquiries' ), absint( $_GET['ts_bulk'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$error = get_option( Digest::LAST_ERROR );
		if ( is_array( $error ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( 'The last daily summary email could not be sent (%1$s): %2$s', 'travelstore-enquiries' ), wp_date( 'j M Y, H:i', $error['time'] ), $error['error'] ) ) . '</p></div>';
		}
	}

	/**
	 * "Mine / All / Published / Private" mean nothing here: only All and Trash.
	 */
	public static function list_views( $views ) {
		return array_intersect_key( $views, array_flip( array( 'all', 'trash' ) ) );
	}

	public static function status_filter( $post_type ) {
		if ( Store::POST_TYPE !== $post_type ) {
			return;
		}
		$current = isset( $_GET['ts_status'] ) ? sanitize_key( wp_unslash( $_GET['ts_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<label class="screen-reader-text" for="ts-status-filter">' . esc_html__( 'Filter by status', 'travelstore-enquiries' ) . '</label>';
		echo '<select name="ts_status" id="ts-status-filter"><option value="">' . esc_html__( 'All statuses', 'travelstore-enquiries' ) . '</option>';
		echo '<option value="active"' . selected( 'active', $current, false ) . '>' . esc_html__( 'Being worked on (not finished)', 'travelstore-enquiries' ) . '</option>';
		echo '<option value="finished"' . selected( 'finished', $current, false ) . '>' . esc_html__( 'Finished (closed, dropped, rejected)', 'travelstore-enquiries' ) . '</option>';
		echo '<option value="open"' . selected( 'open', $current, false ) . '>' . esc_html__( 'Waiting for an acknowledgement', 'travelstore-enquiries' ) . '</option>';
		foreach ( Store::statuses() as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $key, $current, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		$who = isset( $_GET['ts_staff'] ) ? sanitize_text_field( wp_unslash( $_GET['ts_staff'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<label class="screen-reader-text" for="ts-staff-filter">' . esc_html__( 'Filter by staff', 'travelstore-enquiries' ) . '</label>';
		echo '<select name="ts_staff" id="ts-staff-filter"><option value="">' . esc_html__( 'Everyone', 'travelstore-enquiries' ) . '</option>';
		echo '<option value="__none"' . selected( '__none', $who, false ) . '>' . esc_html__( 'Not assigned', 'travelstore-enquiries' ) . '</option>';
		$names = Settings::staff();
		if ( '' !== $who && '__none' !== $who && ! in_array( $who, $names, true ) ) {
			$names[] = $who;
		}
		foreach ( $names as $name ) {
			echo '<option value="' . esc_attr( $name ) . '"' . selected( $name, $who, false ) . '>' . esc_html( $name ) . '</option>';
		}
		echo '</select>';
	}

	public static function apply_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || Store::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$meta = array();
		if ( ! empty( $_GET['ts_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$status = sanitize_key( wp_unslash( $_GET['ts_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'open' === $status ) {
				$values = array( 'new', 'assigned' );
			} elseif ( 'active' === $status ) {
				$values = Store::active_statuses();
			} elseif ( 'finished' === $status ) {
				$values = Store::finished_statuses();
			} else {
				$values = array_key_exists( $status, Store::statuses() ) ? array( $status ) : array();
			}
			if ( $values ) {
				$meta[] = array(
					'key'     => Store::META . 'status',
					'value'   => $values,
					'compare' => 'IN',
				);
			}
		}
		if ( ! empty( $_GET['ts_staff'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$who = sanitize_text_field( wp_unslash( $_GET['ts_staff'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( '__none' === $who ) {
				$meta[] = array(
					'relation' => 'OR',
					array(
						'key'     => Store::META . 'assigned',
						'value'   => '',
						'compare' => '=',
					),
					array(
						'key'     => Store::META . 'assigned',
						'compare' => 'NOT EXISTS',
					),
				);
			} else {
				$meta[] = array(
					'key'   => Store::META . 'assigned',
					'value' => $who,
				);
			}
		}
		if ( $meta ) {
			$query->set( 'meta_query', array_merge( array( 'relation' => 'AND' ), $meta ) );
		}
	}

	/* ------------------------------------------------------------------ One enquiry */

	public static function render_view() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( 'manage_options' ) || ! Store::is_enquiry( $id ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Enquiry not found', 'travelstore-enquiries' ) . '</h1></div>';
			return;
		}
		if ( '1' === (string) Store::get( $id, 'unread' ) ) {
			Store::set( $id, 'unread', 0 );
			Store::log( $id, __( 'Opened for the first time.', 'travelstore-enquiries' ) );
		}
		$post     = get_post( $id );
		$status   = Store::status( $id );
		$assigned = (string) Store::get( $id, 'assigned' );
		$staff    = Settings::staff();
		if ( '' !== $assigned && ! in_array( $assigned, $staff, true ) ) {
			$staff[] = $assigned; // A name removed from Settings stays visible on older enquiries.
		}
		$ack_at   = (string) Store::get( $id, 'ack_at' );
		$office   = (string) Store::get( $id, 'office_status' );
		$rows     = Store::get( $id, 'rows' );
		$log      = Store::get( $id, 'log' );
		$msg      = isset( $_GET['ts_msg'] ) ? sanitize_key( wp_unslash( $_GET['ts_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$notices  = array(
			'saved'       => array( 'success', __( 'Saved.', 'travelstore-enquiries' ) ),
			'ack_sent'    => array( 'success', __( 'Saved, and the acknowledgement email was sent to the customer.', 'travelstore-enquiries' ) ),
			'ack_failed'  => array( 'error', __( 'Saved, but the acknowledgement email could not be sent. See the history below.', 'travelstore-enquiries' ) ),
			'need_staff'  => array( 'error', __( 'Choose who is looking after this enquiry ("Assigned to") before sending the acknowledgement.', 'travelstore-enquiries' ) ),
			'office_sent' => array( 'success', __( 'The enquiry was emailed to the office again.', 'travelstore-enquiries' ) ),
			'office_fail' => array( 'error', __( 'The email to the office failed again. See the history below.', 'travelstore-enquiries' ) ),
		);
		?>
		<div class="wrap ts-enq-admin">
			<p><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Store::POST_TYPE ) ); ?>">&larr; <?php esc_html_e( 'All enquiries', 'travelstore-enquiries' ); ?></a></p>
			<h1><?php echo esc_html( (string) Store::get( $id, 'name' ) ); ?> <span class="ts-enq-tag ts-enq-tag--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( Store::statuses()[ $status ] ); ?></span></h1>
			<p class="ts-enq-muted">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: enquiry type, 2: date. */
						__( '%1$s · received %2$s', 'travelstore-enquiries' ),
						(string) Store::get( $id, 'type' ),
						wp_date( 'l j M Y, H:i', strtotime( $post->post_date_gmt . ' UTC' ) )
					)
				);
				?>
			</p>
			<?php if ( isset( $notices[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notices[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $msg ][1] ); ?></p></div>
			<?php endif; ?>

			<div class="ts-enq-grid">
				<div class="ts-enq-card">
					<h2><?php esc_html_e( 'What the customer sent', 'travelstore-enquiries' ); ?></h2>
					<table class="ts-enq-table">
						<?php foreach ( is_array( $rows ) ? $rows : array() as $row ) : ?>
							<?php
							if ( '' === $row['value'] ) {
								continue;
							}
							?>
							<tr class="ts-enq-row--<?php echo esc_attr( $row['key'] ); ?>">
								<th scope="row"><?php echo esc_html( $row['label'] ); ?></th>
								<td>
									<?php
									if ( 'email' === $row['key'] ) {
										echo '<a href="' . esc_url( 'mailto:' . $row['value'] ) . '">' . esc_html( $row['value'] ) . '</a>';
									} elseif ( 'phone' === $row['key'] ) {
										$tel = preg_replace( '/[^\d+]/', '', $row['value'] );
										$wa  = preg_replace( '/^00/', '', preg_replace( '/\D/', '', $row['value'] ) );
										echo esc_html( $row['value'] ) . ' &nbsp; <a href="' . esc_url( 'tel:' . $tel ) . '">' . esc_html__( 'Call', 'travelstore-enquiries' ) . '</a> · <a href="' . esc_url( 'https://wa.me/' . $wa ) . '" target="_blank" rel="noopener">' . esc_html__( 'WhatsApp', 'travelstore-enquiries' ) . '</a>';
									} else {
										echo nl2br( esc_html( $row['value'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						<?php $url = (string) Store::get( $id, 'source_url' ); ?>
						<?php if ( '' !== $url ) : ?>
							<tr><th scope="row"><?php esc_html_e( 'Sent from', 'travelstore-enquiries' ); ?></th><td><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $url ); ?></a></td></tr>
						<?php endif; ?>
					</table>
				</div>

				<div class="ts-enq-side">
					<form class="ts-enq-card" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ts_enquiry_update">
						<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
						<?php wp_nonce_field( 'ts_enquiry_update_' . $id ); ?>
						<h2><?php esc_html_e( 'Handling', 'travelstore-enquiries' ); ?></h2>
						<p>
							<label for="ts-assigned"><strong><?php esc_html_e( 'Assigned to', 'travelstore-enquiries' ); ?></strong></label><br>
							<select name="assigned" id="ts-assigned" class="ts-enq-wide">
								<option value=""><?php esc_html_e( '— Nobody yet —', 'travelstore-enquiries' ); ?></option>
								<?php foreach ( $staff as $name ) : ?>
									<option value="<?php echo esc_attr( $name ); ?>"<?php selected( $assigned, $name ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( empty( Settings::staff() ) ) : ?>
								<br><span class="description"><?php echo wp_kses( sprintf( __( 'Add staff names under <a href="%s">Enquiries > Staff</a>.', 'travelstore-enquiries' ), esc_url( Staff::url() ) ), array( 'a' => array( 'href' => array() ) ) ); ?></span>
							<?php endif; ?>
						</p>
						<p>
							<label for="ts-status"><strong><?php esc_html_e( 'Status', 'travelstore-enquiries' ); ?></strong></label><br>
							<select name="status" id="ts-status" class="ts-enq-wide">
								<?php foreach ( array( __( 'In the pipeline', 'travelstore-enquiries' ) => Store::active_statuses(), __( 'Finished', 'travelstore-enquiries' ) => Store::finished_statuses() ) as $group => $keys ) : ?>
									<optgroup label="<?php echo esc_attr( $group ); ?>">
									<?php foreach ( $keys as $key ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $status, $key ); ?>><?php echo esc_html( Store::statuses()[ $key ] ); ?></option>
									<?php endforeach; ?>
									</optgroup>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="ts-notes"><strong><?php esc_html_e( 'Internal notes', 'travelstore-enquiries' ); ?></strong></label><br>
							<textarea name="notes" id="ts-notes" rows="4" class="ts-enq-wide"><?php echo esc_textarea( (string) Store::get( $id, 'notes' ) ); ?></textarea>
							<span class="description"><?php esc_html_e( 'Only seen here, never sent to the customer.', 'travelstore-enquiries' ); ?></span>
						</p>
						<p><button type="submit" name="do" value="save" class="button"><?php esc_html_e( 'Save', 'travelstore-enquiries' ); ?></button></p>

						<hr>
						<h2><?php esc_html_e( 'Acknowledgement email', 'travelstore-enquiries' ); ?></h2>
						<?php if ( '' === $ack_at ) : ?>
							<p><?php echo esc_html( sprintf( __( 'Not sent yet. One click sends it to %s (and saves the handling above).', 'travelstore-enquiries' ), (string) Store::get( $id, 'email' ) ) ); ?></p>
							<p><button type="submit" name="do" value="ack" class="button button-primary button-hero"><?php esc_html_e( 'Send acknowledgement email', 'travelstore-enquiries' ); ?></button></p>
						<?php else : ?>
							<p class="ts-enq-sent">&#10003; <?php echo esc_html( sprintf( __( 'Sent on %1$s by %2$s.', 'travelstore-enquiries' ), mysql2date( 'j M Y, H:i', $ack_at ), (string) Store::get( $id, 'ack_by' ) ) ); ?></p>
							<p><button type="submit" name="do" value="ack_again" class="button" onclick="return window.confirm('<?php echo esc_js( __( 'The acknowledgement was already sent. Send it again?', 'travelstore-enquiries' ) ); ?>');"><?php esc_html_e( 'Send it again', 'travelstore-enquiries' ); ?></button></p>
						<?php endif; ?>
						<details class="ts-enq-preview">
							<summary><?php esc_html_e( 'See the email', 'travelstore-enquiries' ); ?></summary>
							<p><strong><?php esc_html_e( 'Subject:', 'travelstore-enquiries' ); ?></strong> <?php echo esc_html( (string) Settings::get( 'ack_subject' ) ); ?></p>
							<pre><?php echo esc_html( Mailer::acknowledgement_body( $id ) ); ?></pre>
							<p class="description"><?php esc_html_e( 'The wording is set under Enquiries > Settings. "Looked after by" uses the name chosen above once it is saved.', 'travelstore-enquiries' ); ?></p>
						</details>

						<hr>
						<h2><?php esc_html_e( 'Email to the office', 'travelstore-enquiries' ); ?></h2>
						<?php if ( 'sent' === $office ) : ?>
							<p><?php echo esc_html( sprintf( __( 'Sent to %s when the enquiry arrived.', 'travelstore-enquiries' ), (string) Store::get( $id, 'office_to' ) ) ); ?></p>
						<?php else : ?>
							<p class="ts-enq-failed"><?php echo esc_html( sprintf( __( 'Not delivered: %s', 'travelstore-enquiries' ), (string) Store::get( $id, 'office_error' ) ) ); ?></p>
						<?php endif; ?>
						<p><button type="submit" name="do" value="office" class="button"><?php esc_html_e( 'Email it to the office again', 'travelstore-enquiries' ); ?></button></p>
					</form>

					<div class="ts-enq-card">
						<h2><?php esc_html_e( 'History', 'travelstore-enquiries' ); ?></h2>
						<ul class="ts-enq-log">
							<?php foreach ( array_reverse( is_array( $log ) ? $log : array() ) as $entry ) : ?>
								<li><span class="ts-enq-muted"><?php echo esc_html( wp_date( 'j M Y, H:i', (int) $entry['time'] ) ); ?><?php echo '' !== $entry['user'] ? ' · ' . esc_html( $entry['user'] ) : ''; ?></span><br><?php echo esc_html( $entry['text'] ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save the handling fields, and send the acknowledgement or the office email when asked.
	 */
	public static function update() {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! current_user_can( 'manage_options' ) || ! Store::is_enquiry( $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'travelstore-enquiries' ) );
		}
		check_admin_referer( 'ts_enquiry_update_' . $id );

		$do       = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'save';
		$statuses = Store::statuses();
		$assigned = isset( $_POST['assigned'] ) ? sanitize_text_field( wp_unslash( $_POST['assigned'] ) ) : '';
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : Store::status( $id );
		$status   = array_key_exists( $status, $statuses ) ? $status : Store::status( $id );
		$notes    = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$old_assigned = (string) Store::get( $id, 'assigned' );
		$old_status   = Store::status( $id );

		// Choosing someone for a new enquiry moves it to "Assigned".
		if ( '' !== $assigned && '' === $old_assigned && 'new' === $status && 'new' === $old_status ) {
			$status = 'assigned';
		}
		if ( $assigned !== $old_assigned ) {
			Store::set( $id, 'assigned', $assigned );
			Store::log( $id, '' !== $assigned ? sprintf( __( 'Assigned to %s.', 'travelstore-enquiries' ), $assigned ) : __( 'No longer assigned.', 'travelstore-enquiries' ) );
		}
		if ( $notes !== (string) Store::get( $id, 'notes' ) ) {
			Store::set( $id, 'notes', $notes );
			Store::log( $id, __( 'Internal notes changed.', 'travelstore-enquiries' ) );
		}

		$msg = 'saved';
		if ( in_array( $do, array( 'ack', 'ack_again' ), true ) ) {
			if ( '' === $assigned ) {
				$msg = 'need_staff';
			} elseif ( 'ack' === $do && '' !== (string) Store::get( $id, 'ack_at' ) ) {
				$msg = 'saved'; // Already sent (for example a second click): not sent twice.
			} else {
				$result = Mailer::acknowledge( $id );
				$msg    = $result['ok'] ? 'ack_sent' : 'ack_failed';
				if ( $result['ok'] && in_array( $status, array( 'new', 'assigned' ), true ) ) {
					$status = 'acknowledged';
				}
			}
		} elseif ( 'office' === $do ) {
			$result = Mailer::office( $id );
			$msg    = $result['ok'] ? 'office_sent' : 'office_fail';
		}

		if ( $status !== $old_status ) {
			Store::set( $id, 'status', $status );
			Store::log( $id, sprintf( __( 'Status: %s.', 'travelstore-enquiries' ), $statuses[ $status ] ) );
		}

		wp_safe_redirect( add_query_arg( 'ts_msg', $msg, Store::view_url( $id ) ) );
		exit;
	}
}
