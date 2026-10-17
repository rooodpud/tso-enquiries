<?php
/**
 * Enquiries > Staff: the names an enquiry can be assigned to (add, rename, remove) and how many enquiries each one
 * is looking after. Staff are names only, they do not need a WordPress login. The names are kept in the
 * "staff" setting (one per line), which the enquiry screen, the acknowledgement email and the daily summary read.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Staff {

	const PAGE = 'ts-enquiries-staff';

	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_post_ts_enquiry_staff', array( __CLASS__, 'save' ) );
	}

	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=' . Store::POST_TYPE,
			__( 'Enquiry staff', 'travelstore-enquiries' ),
			__( 'Staff', 'travelstore-enquiries' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'post_type' => Store::POST_TYPE, 'page' => self::PAGE ), $args ), admin_url( 'edit.php' ) );
	}

	/**
	 * Enquiries per staff name: active (still being worked on) and finished. Trashed enquiries are not counted.
	 *
	 * @return array<string, array{active:int, finished:int}>
	 */
	public static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.meta_value AS staff, s.meta_value AS status, COUNT(*) AS n
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' ) AND a.meta_value <> ''
				GROUP BY a.meta_value, s.meta_value",
				Store::META . 'assigned',
				Store::META . 'status',
				Store::POST_TYPE
			)
		);
		$out      = array();
		$finished = Store::finished_statuses();
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $out[ $row->staff ] ) ) {
				$out[ $row->staff ] = array( 'active' => 0, 'finished' => 0 );
			}
			$key                        = in_array( (string) $row->status, $finished, true ) ? 'finished' : 'active';
			$out[ $row->staff ][ $key ] += (int) $row->n;
		}

		return $out;
	}

	private static function store( array $names ) {
		$settings          = Settings::all();
		$settings['staff'] = implode( "\n", array_values( array_unique( $names ) ) );
		update_option( Settings::OPTION, $settings );
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'travelstore-enquiries' ) );
		}
		check_admin_referer( 'ts_enquiry_staff' );

		$do    = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['name'] ) ) ) : '';
		$old   = isset( $_POST['old'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['old'] ) ) ) : '';
		$names = Settings::staff();
		$msg   = 'saved';
		$args  = array();

		if ( 'add' === $do ) {
			if ( '' === $name ) {
				$msg = 'empty';
			} elseif ( in_array( mb_strtolower( $name ), array_map( 'mb_strtolower', $names ), true ) ) {
				$msg = 'exists';
			} else {
				$names[] = $name;
				self::store( $names );
				$msg = 'added';
			}
		} elseif ( 'rename' === $do && in_array( $old, $names, true ) ) {
			if ( '' === $name ) {
				$msg = 'empty';
			} elseif ( $name !== $old && in_array( mb_strtolower( $name ), array_map( 'mb_strtolower', $names ), true ) ) {
				$msg = 'exists';
			} elseif ( $name !== $old ) {
				self::store( array_map( static function ( $n ) use ( $old, $name ) {
					return $n === $old ? $name : $n;
				}, $names ) );
				// Enquiries keep the name they were assigned to: follow the rename so nothing is orphaned.
				$ids = get_posts(
					array(
						'post_type'      => Store::POST_TYPE,
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'meta_key'       => Store::META . 'assigned', // phpcs:ignore WordPress.DB.SlowDBQuery
						'meta_value'     => $old, // phpcs:ignore WordPress.DB.SlowDBQuery
					)
				);
				foreach ( $ids as $id ) {
					Store::set( $id, 'assigned', $name );
					Store::log( $id, sprintf( __( 'Staff name changed from %1$s to %2$s.', 'travelstore-enquiries' ), $old, $name ) );
				}
				$msg = 'renamed';
			}
		} elseif ( 'remove' === $do && in_array( $old, $names, true ) ) {
			$counts = self::counts();
			if ( ! empty( $counts[ $old ]['active'] ) ) {
				$msg  = 'busy';
				$args = array( 'who' => $old );
			} else {
				self::store( array_values( array_diff( $names, array( $old ) ) ) );
				$msg = 'removed';
			}
		}

		wp_safe_redirect( self::url( array_merge( array( 'ts_msg' => $msg ), $args ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$names  = Settings::staff();
		$counts = self::counts();
		$list   = 'edit.php?post_type=' . Store::POST_TYPE;
		$msg    = isset( $_GET['ts_msg'] ) ? sanitize_key( wp_unslash( $_GET['ts_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$who    = isset( $_GET['who'] ) ? sanitize_text_field( wp_unslash( $_GET['who'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$notes  = array(
			'added'   => array( 'success', __( 'Added.', 'travelstore-enquiries' ) ),
			'renamed' => array( 'success', __( 'Renamed. Their enquiries now show the new name.', 'travelstore-enquiries' ) ),
			'removed' => array( 'success', __( 'Removed from the list.', 'travelstore-enquiries' ) ),
			'empty'   => array( 'error', __( 'Please type a name.', 'travelstore-enquiries' ) ),
			'exists'  => array( 'error', __( 'There is already a person with that name.', 'travelstore-enquiries' ) ),
			'busy'    => array( 'error', sprintf( __( '%s still has enquiries being worked on. Assign those to someone else first (use the "Assigned to" filter on the enquiries list), then remove the name.', 'travelstore-enquiries' ), $who ) ),
		);
		?>
		<div class="wrap ts-enq-admin">
			<h1><?php esc_html_e( 'Enquiry staff', 'travelstore-enquiries' ); ?></h1>
			<p><?php esc_html_e( 'The people an enquiry can be assigned to. They only need a name here; they do not need a WordPress login.', 'travelstore-enquiries' ); ?></p>
			<?php if ( isset( $notes[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notes[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notes[ $msg ][1] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ts-enq-staff-add">
				<input type="hidden" name="action" value="ts_enquiry_staff">
				<input type="hidden" name="do" value="add">
				<?php wp_nonce_field( 'ts_enquiry_staff' ); ?>
				<label for="ts-staff-new"><strong><?php esc_html_e( 'Add a person', 'travelstore-enquiries' ); ?></strong></label><br>
				<input type="text" id="ts-staff-new" name="name" class="regular-text" placeholder="<?php esc_attr_e( 'Name, for example Aisha', 'travelstore-enquiries' ); ?>" maxlength="60">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Add', 'travelstore-enquiries' ); ?></button>
			</form>

			<table class="widefat striped ts-enq-staff-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'travelstore-enquiries' ); ?></th>
						<th><?php esc_html_e( 'Being worked on', 'travelstore-enquiries' ); ?></th>
						<th><?php esc_html_e( 'Finished', 'travelstore-enquiries' ); ?></th>
						<th><?php esc_html_e( 'Change', 'travelstore-enquiries' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $names ) ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'No staff yet. Add the first name above.', 'travelstore-enquiries' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $names as $name ) : ?>
						<?php
						$c      = isset( $counts[ $name ] ) ? $counts[ $name ] : array( 'active' => 0, 'finished' => 0 );
						$filter = admin_url( $list . '&ts_staff=' . rawurlencode( $name ) );
						?>
						<tr>
							<td><strong><?php echo esc_html( $name ); ?></strong></td>
							<td><?php echo $c['active'] ? '<a href="' . esc_url( $filter . '&ts_status=active' ) . '">' . (int) $c['active'] . '</a>' : '0'; ?></td>
							<td><?php echo $c['finished'] ? '<a href="' . esc_url( $filter . '&ts_status=finished' ) . '">' . (int) $c['finished'] . '</a>' : '0'; ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ts-enq-staff-row">
									<input type="hidden" name="action" value="ts_enquiry_staff">
									<input type="hidden" name="old" value="<?php echo esc_attr( $name ); ?>">
									<?php wp_nonce_field( 'ts_enquiry_staff' ); ?>
									<label class="screen-reader-text" for="ts-rename-<?php echo esc_attr( md5( $name ) ); ?>"><?php echo esc_html( sprintf( __( 'New name for %s', 'travelstore-enquiries' ), $name ) ); ?></label>
									<input type="text" id="ts-rename-<?php echo esc_attr( md5( $name ) ); ?>" name="name" value="<?php echo esc_attr( $name ); ?>" maxlength="60">
									<button type="submit" name="do" value="rename" class="button"><?php esc_html_e( 'Rename', 'travelstore-enquiries' ); ?></button>
									<button type="submit" name="do" value="remove" class="button" onclick="return window.confirm('<?php echo esc_js( sprintf( __( 'Remove %s from the list?', 'travelstore-enquiries' ), $name ) ); ?>');"><?php esc_html_e( 'Remove', 'travelstore-enquiries' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Removing a name does not change enquiries that were already finished by that person; they keep showing it. A person with enquiries still being worked on cannot be removed until those are assigned to someone else.', 'travelstore-enquiries' ); ?></p>
		</div>
		<?php
	}
}
