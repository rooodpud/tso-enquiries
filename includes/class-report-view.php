<?php
/**
 * Drawing helpers for Enquiries > Insights (1.3.0): inline SVG charts (no outside library), tables
 * whose numbers can link to the enquiries behind them, the CSV writer and the "Count as" lists.
 *
 * A table is array( title, head, rows, num ); a cell is a plain value or array( 'v' => value,
 * 'href' => link ). CSV files use the value only.
 */

namespace TravelStore\Enquiries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Report_View {

	const GROUPS = 'ts_enquiry_report_groups';

	public static function register() {
		add_action( 'admin_post_' . self::GROUPS, array( __CLASS__, 'save_groups' ) );
	}

	public static function cell_value( $cell ) {
		return is_array( $cell ) ? $cell['v'] : $cell;
	}

	public static function link( $value, $href ) {
		return array(
			'v'    => $value,
			'href' => $href,
		);
	}

	/* ------------------------------------------------------------------ Tables */

	public static function section_head( $title, $csv = '', $intro = '' ) {
		echo '<div class="ts-rep-head"><h2>' . esc_html( $title ) . '</h2>' . ( '' !== $csv ? '<a class="button button-small ts-rep-noprint" href="' . esc_url( $csv ) . '">' . esc_html__( 'Download CSV', 'travelstore-enquiries' ) . '</a>' : '' ) . '</div>';
		if ( '' !== $intro ) {
			echo '<p class="description ts-rep-intro">' . esc_html( $intro ) . '</p>';
		}
	}

	public static function table( array $table, $open = true, $sticky = false, $empty = '' ) {
		if ( ! $table['rows'] ) {
			echo '<p class="ts-enq-muted">' . esc_html( '' !== $empty ? $empty : __( 'No enquiries in this period.', 'travelstore-enquiries' ) ) . '</p>';
			return;
		}
		echo '<details class="ts-rep-table"' . ( $open ? ' open' : '' ) . '><summary>' . esc_html__( 'Table', 'travelstore-enquiries' ) . '</summary><div class="ts-rep-scroll"><table class="widefat striped' . ( $sticky ? ' ts-rep-sticky' : '' ) . '"><thead><tr>';
		foreach ( $table['head'] as $i => $h ) {
			echo '<th scope="col"' . ( in_array( $i, $table['num'], true ) ? ' class="n"' : '' ) . '>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $table['rows'] as $row ) {
			echo '<tr>';
			foreach ( $row as $i => $cell ) {
				$value = self::cell_value( $cell );
				$cls   = in_array( $i, $table['num'], true ) ? 'n' : '';
				if ( is_string( $value ) && preg_match( '/^([+-])\d+(\.\d+)?( pts|%)$/', $value, $m ) ) {
					$cls .= '+' === $m[1] ? ' up' : ' down';
				}
				$text = is_float( $value ) ? number_format_i18n( $value, 1 ) : ( is_int( $value ) ? number_format_i18n( $value ) : (string) $value );
				$html = is_array( $cell ) && ! empty( $cell['href'] ) ? '<a href="' . esc_url( $cell['href'] ) . '">' . esc_html( $text ) . '</a>' : esc_html( $text );
				echo 0 === $i ? '<th scope="row">' . $html . '</th>' : '<td class="' . esc_attr( trim( $cls ) ) . '">' . $html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			}
			echo '</tr>';
		}
		echo '</tbody></table></div></details>';
	}

	/* ------------------------------------------------------------------ CSV */

	public static function csv_send( array $table, $file ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $file ) . '"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // So Excel reads the file as UTF-8.
		fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $table['head'] ) );
		foreach ( $table['rows'] as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), array_map( array( __CLASS__, 'cell_value' ), $row ) ) );
		}
		fclose( $out );
		exit;
	}

	/** A spreadsheet must never run a cell as a formula; plain numbers such as -5% stay numbers. */
	public static function csv_cell( $cell ) {
		$cell = '—' === $cell ? '' : (string) $cell;
		if ( '' !== $cell && preg_match( '/^[=+\-@\t\r]/', $cell ) && ! preg_match( '/^[+-]?\d+(\.\d+)?(%| pts)?$/', $cell ) ) {
			return "'" . $cell;
		}

		return $cell;
	}

	/* ------------------------------------------------------------------ Charts */

	public static function legend( array $series ) {
		if ( count( $series ) < 2 ) {
			return '';
		}
		$out = '<ul class="ts-rep-legend">';
		foreach ( $series as $name => $s ) {
			$out .= '<li><span style="background:' . esc_attr( $s[0] ) . '"></span>' . esc_html( $name ) . '</li>';
		}

		return $out . '</ul>';
	}

	/** Gridlines at 0, half and the top, with whole numbers. */
	private static function axis( $max, $x0, $x1, $y0, $h ) {
		$top = max( 1, (int) ceil( $max ) );
		$out = '';
		foreach ( array_unique( array( 0, (int) round( $top / 2 ), $top ) ) as $v ) {
			$y    = round( $y0 + $h - $h * $v / $top, 1 );
			$out .= '<line x1="' . $x0 . '" y1="' . $y . '" x2="' . $x1 . '" y2="' . $y . '" stroke="#dcdcde"' . ( $v ? ' stroke-dasharray="2 3"' : '' ) . '/><text x="' . ( $x0 - 6 ) . '" y="' . ( $y + 4 ) . '" font-size="11" text-anchor="end" fill="#50575e">' . esc_html( number_format_i18n( $v ) ) . '</text>';
		}

		return array( $top, $out );
	}

	private static function x_labels( array $labels, $x0, $slot, $y ) {
		$n    = count( $labels );
		$step = max( 1, (int) ceil( $n / 10 ) );
		$out  = '';
		foreach ( $labels as $i => $label ) {
			if ( 0 === $i % $step ) {
				$out .= '<text x="' . round( $x0 + $slot * ( $i + 0.5 ), 1 ) . '" y="' . $y . '" font-size="11" text-anchor="middle" fill="#50575e">' . esc_html( preg_replace( '/^Week of /', '', $label ) ) . '</text>';
			}
		}

		return $out;
	}

	/** Hover text for one column: the label and every series' value. */
	private static function tip( $label, array $series, $i ) {
		$parts = array();
		foreach ( $series as $name => $s ) {
			$parts[] = $name . ': ' . number_format_i18n( $s[1][ $i ] ?? 0 );
		}

		return $label . "\n" . implode( "\n", $parts );
	}

	/** A transparent hover/click area over one column; a link when $href is given. */
	private static function hit( $x, $y, $w, $h, $tip, $href = '' ) {
		$rect = '<rect class="ts-rep-hit" x="' . round( $x, 1 ) . '" y="' . round( $y, 1 ) . '" width="' . round( $w, 1 ) . '" height="' . round( $h, 1 ) . '" fill="transparent" data-tip="' . esc_attr( $tip ) . '"/>';

		return '' !== $href ? '<a href="' . esc_url( $href ) . '" aria-label="' . esc_attr( str_replace( "\n", ', ', $tip ) ) . '">' . $rect . '</a>' : $rect;
	}

	/**
	 * Vertical bars, stacked when there is more than one series.
	 *
	 * @param array $series name => array( colour, values ).
	 * @param array $links  Column index => list link (optional).
	 */
	public static function svg_bars( $title, array $labels, array $series, array $links = array() ) {
		$n = count( $labels );
		if ( ! $n || ! $series ) {
			return '';
		}
		$w      = 720;
		$h      = 200;
		$x0     = 40;
		$x1     = $w - 8;
		$y0     = 8;
		$slot   = ( $x1 - $x0 ) / $n;
		$bw     = min( 36, max( 1, $slot * 0.72 ) );
		$totals = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$totals[ $i ] = 0;
			foreach ( $series as $s ) {
				$totals[ $i ] += $s[1][ $i ] ?? 0;
			}
		}
		list( $top, $grid ) = self::axis( max( $totals ), $x0, $x1, $y0, $h );
		$out = '<figure class="ts-rep-chart"><svg viewBox="0 0 ' . $w . ' ' . ( $h + $y0 + 26 ) . '" role="img" aria-label="' . esc_attr( $title ) . '">' . $grid;
		for ( $i = 0; $i < $n; $i++ ) {
			$x    = $x0 + $slot * $i + ( $slot - $bw ) / 2;
			$base = $y0 + $h;
			foreach ( $series as $s ) {
				$v = $s[1][ $i ] ?? 0;
				if ( $v <= 0 ) {
					continue;
				}
				$bh    = $h * $v / $top;
				$base -= $bh;
				$out  .= '<rect x="' . round( $x, 1 ) . '" y="' . round( $base, 1 ) . '" width="' . round( $bw, 1 ) . '" height="' . round( $bh, 1 ) . '" rx="' . ( $bw > 6 ? 2 : 0 ) . '" fill="' . esc_attr( $s[0] ) . '" stroke="#fff" stroke-width="1"/>';
			}
			if ( $n <= 14 && $totals[ $i ] > 0 ) {
				$out .= '<text x="' . round( $x + $bw / 2, 1 ) . '" y="' . round( $base - 4, 1 ) . '" font-size="11" text-anchor="middle" fill="#1d2327">' . esc_html( number_format_i18n( $totals[ $i ] ) ) . '</text>';
			}
			$out .= self::hit( $x0 + $slot * $i, $y0, $slot, $h, self::tip( $labels[ $i ], $series, $i ), $totals[ $i ] > 0 ? ( $links[ $i ] ?? '' ) : '' );
		}

		return $out . self::x_labels( $labels, $x0, $slot, $y0 + $h + 18 ) . '</svg></figure>';
	}

	/** One line per series over time. */
	public static function svg_lines( $title, array $labels, array $series ) {
		$n = count( $labels );
		if ( ! $n || ! $series ) {
			return '<p class="ts-enq-muted">' . esc_html__( 'No answers in this period.', 'travelstore-enquiries' ) . '</p>';
		}
		$w    = 720;
		$h    = 200;
		$x0   = 40;
		$x1   = $w - 8;
		$y0   = 8;
		$slot = ( $x1 - $x0 ) / $n;
		$max  = 0;
		foreach ( $series as $s ) {
			$max = max( $max, max( $s[1] ) );
		}
		list( $top, $grid ) = self::axis( $max, $x0, $x1, $y0, $h );
		$out = '<figure class="ts-rep-chart"><svg viewBox="0 0 ' . $w . ' ' . ( $h + $y0 + 26 ) . '" role="img" aria-label="' . esc_attr( $title ) . '">' . $grid;
		foreach ( $series as $s ) {
			$pts = array();
			foreach ( $s[1] as $i => $v ) {
				$pts[] = round( $x0 + $slot * ( $i + 0.5 ), 1 ) . ',' . round( $y0 + $h - $h * $v / $top, 1 );
			}
			$out .= 1 === $n ? '' : '<polyline points="' . implode( ' ', $pts ) . '" fill="none" stroke="' . esc_attr( $s[0] ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
			if ( $n <= 31 ) {
				foreach ( $pts as $pt ) {
					list( $cx, $cy ) = explode( ',', $pt );
					$out .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="3.5" fill="' . esc_attr( $s[0] ) . '" stroke="#fff" stroke-width="1.5"/>';
				}
			}
		}
		for ( $i = 0; $i < $n; $i++ ) {
			$out .= self::hit( $x0 + $slot * $i, $y0, $slot, $h, self::tip( $labels[ $i ], $series, $i ) );
		}

		return $out . self::x_labels( $labels, $x0, $slot, $y0 + $h + 18 ) . '</svg></figure>';
	}

	/**
	 * Horizontal bars with the count and share.
	 *
	 * @param array $items label => array( colour, count, link ).
	 */
	public static function svg_hbars( $title, array $items, $total ) {
		if ( ! $items ) {
			return '<p class="ts-enq-muted">' . esc_html__( 'No enquiries in this period.', 'travelstore-enquiries' ) . '</p>';
		}
		$w   = 720;
		$row = 26;
		$lx  = 230;
		$bx1 = $w - 110;
		$max = max( 1, max( array_column( $items, 1 ) ) );
		$out = '<figure class="ts-rep-chart"><svg viewBox="0 0 ' . $w . ' ' . ( count( $items ) * $row + 4 ) . '" role="img" aria-label="' . esc_attr( $title ) . '">';
		$i   = 0;
		foreach ( $items as $label => $item ) {
			$y    = $i * $row + 4;
			$bw   = max( 2, ( $bx1 - $lx ) * $item[1] / $max );
			$text = mb_strlen( $label ) > 34 ? mb_substr( $label, 0, 33 ) . '…' : $label;
			$val  = number_format_i18n( $item[1] ) . ' · ' . Report_Data::share( $item[1], $total ) . '%';
			$out .= '<text x="' . ( $lx - 8 ) . '" y="' . ( $y + 15 ) . '" font-size="12" text-anchor="end" fill="#1d2327">' . esc_html( $text ) . '</text>';
			$out .= '<rect x="' . $lx . '" y="' . $y . '" width="' . round( $bw, 1 ) . '" height="18" rx="2" fill="' . esc_attr( $item[0] ) . '"/>';
			$out .= '<text x="' . round( $lx + $bw + 6, 1 ) . '" y="' . ( $y + 14 ) . '" font-size="12" fill="#1d2327">' . esc_html( $val ) . '</text>';
			$out .= self::hit( 0, $y - 2, $w, $row, $label . "\n" . $val, $item[2] ?? '' );
			++$i;
		}

		return $out . '</svg></figure>';
	}

	/**
	 * Day of the week × hour of the day, darker = more enquiries.
	 *
	 * @param array    $grid  ISO weekday => hour => count.
	 * @param int[]    $order Weekdays in display order.
	 * @param callable $href  ( weekday, hour ) → list link.
	 */
	public static function svg_heat( $title, array $grid, array $order, $href ) {
		$max = 1;
		foreach ( $grid as $hours ) {
			$max = max( $max, max( $hours ) );
		}
		$cw  = 26;
		$out = '<figure class="ts-rep-chart"><svg viewBox="0 0 ' . ( 44 + 24 * $cw ) . ' ' . ( 7 * 22 + 24 ) . '" role="img" aria-label="' . esc_attr( $title ) . '">';
		foreach ( $order as $row => $d ) {
			$y     = $row * 22;
			$label = date_i18n( 'D', strtotime( '2024-01-0' . $d . ' 12:00 UTC' ), true );
			$out  .= '<text x="0" y="' . ( $y + 15 ) . '" font-size="11" fill="#50575e">' . esc_html( $label ) . '</text>';
			for ( $hr = 0; $hr < 24; $hr++ ) {
				$v    = (int) ( $grid[ $d ][ $hr ] ?? 0 );
				$op   = $v ? 0.15 + 0.85 * $v / $max : 0;
				$tip  = sprintf( '%s %02d:00–%02d:59 · %d', $label, $hr, $hr, $v );
				$cell = '<rect x="' . ( 44 + $hr * $cw ) . '" y="' . $y . '" width="' . ( $cw - 2 ) . '" height="20" rx="2" fill="' . ( $v ? '#2a78d6' : '#f0f0f1' ) . '"' . ( $v ? ' fill-opacity="' . round( $op, 2 ) . '"' : '' ) . ' class="ts-rep-hit" data-tip="' . esc_attr( $tip ) . '"/>';
				$out .= $v ? '<a href="' . esc_url( $href( $d, $hr ) ) . '" aria-label="' . esc_attr( $tip ) . '">' . $cell . '</a>' : $cell;
			}
		}
		for ( $hr = 0; $hr < 24; $hr += 3 ) {
			$out .= '<text x="' . ( 44 + $hr * $cw + 2 ) . '" y="' . ( 7 * 22 + 14 ) . '" font-size="11" fill="#50575e">' . esc_html( sprintf( '%02d', $hr ) ) . '</text>';
		}

		return $out . '</svg></figure>';
	}

	/* ------------------------------------------------------------------ "Count as" */

	/** Group spellings and synonyms. Saved in an option; enquiries are not changed. */
	public static function groups_form( array $c, $kind, $back ) {
		$seen = $c['seen'][ $kind ];
		if ( ! $seen ) {
			return;
		}
		$known = 'heard' === $kind ? array_keys( Report_Data::default_rules() ) : array();
		foreach ( $c[ 'heard' === $kind ? 'heard' : 'types' ] as $grp ) {
			if ( 0 !== strpos( $grp, '__' ) ) {
				$known[] = $grp;
			}
		}
		$known = array_unique( $known );
		sort( $known );
		$title = 'heard' === $kind ? __( 'Group answers that mean the same', 'travelstore-enquiries' ) : __( 'Group types that mean the same', 'travelstore-enquiries' );
		?>
		<details class="ts-rep-groups ts-rep-noprint">
			<summary><?php echo esc_html( $title ); ?></summary>
			<p class="description"><?php esc_html_e( 'Every different answer received, with what it is counted as. Differences in capitals, spaces and punctuation are merged automatically. To count an answer with another, pick the group or type a new name. Only the figures change; each enquiry keeps exactly what was sent.', 'travelstore-enquiries' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::GROUPS ); ?>">
				<input type="hidden" name="kind" value="<?php echo esc_attr( $kind ); ?>">
				<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>">
				<?php wp_nonce_field( self::GROUPS ); ?>
				<div class="ts-rep-scroll"><table class="widefat striped">
					<thead><tr><th scope="col"><?php esc_html_e( 'Answer as sent', 'travelstore-enquiries' ); ?></th><th scope="col" class="n"><?php esc_html_e( 'Times (all time)', 'travelstore-enquiries' ); ?></th><th scope="col"><?php esc_html_e( 'Count as', 'travelstore-enquiries' ); ?></th><th scope="col"><?php esc_html_e( 'Or a new group name', 'travelstore-enquiries' ); ?></th></tr></thead>
					<tbody>
					<?php
					$i = 0;
					foreach ( $seen as $key => $info ) :
						++$i;
						$chosen = $c['groups'][ $kind ][ $key ] ?? '';
						$auto   = Report_Data::auto_group( $kind, $key, $c['names'] );
						$id     = 'ts-rep-g-' . $kind . '-' . $i;
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $info[0] ); ?></label><?php if ( count( $info[2] ) > 1 ) : ?><br><span class="ts-enq-muted"><?php echo esc_html( sprintf( /* translators: %s: other spellings */ __( 'also sent as: %s', 'travelstore-enquiries' ), implode( ', ', array_slice( $info[2], 1 ) ) ) ); ?></span><?php endif; ?></th>
							<td class="n"><?php echo esc_html( number_format_i18n( $info[1] ) ); ?></td>
							<td>
								<select id="<?php echo esc_attr( $id ); ?>" name="map[<?php echo esc_attr( $key ); ?>]">
									<option value=""><?php echo esc_html( sprintf( /* translators: %s: group */ __( 'Automatic: %s', 'travelstore-enquiries' ), $auto ) ); ?></option>
									<?php if ( $auto !== $info[0] ) : ?>
										<option value="<?php echo esc_attr( Report_Data::OWN ); ?>" <?php selected( $chosen, Report_Data::OWN ); ?>><?php echo esc_html( sprintf( /* translators: %s: the answer */ __( 'Its own name: %s', 'travelstore-enquiries' ), $info[0] ) ); ?></option>
									<?php endif; ?>
									<?php foreach ( $known as $grp ) : ?>
										<?php if ( $grp !== $auto ) : ?>
											<option value="<?php echo esc_attr( $grp ); ?>" <?php selected( $chosen, $grp ); ?>><?php echo esc_html( $grp ); ?></option>
										<?php endif; ?>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input type="text" class="regular-text" name="new[<?php echo esc_attr( $key ); ?>]" value="" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: answer */ __( 'New group name for %s', 'travelstore-enquiries' ), $info[0] ) ); ?>"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<p><button class="button button-primary"><?php esc_html_e( 'Save groups', 'travelstore-enquiries' ); ?></button></p>
			</form>
		</details>
		<?php
	}

	public static function save_groups() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'travelstore-enquiries' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::GROUPS );
		$kind = isset( $_POST['kind'] ) && 'type' === $_POST['kind'] ? 'type' : 'heard';
		$map  = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value sanitised below.
		$new  = isset( $_POST['new'] ) && is_array( $_POST['new'] ) ? wp_unslash( $_POST['new'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$all  = Report_Data::saved_groups();
		$out  = array();
		foreach ( $map as $key => $choice ) {
			$key = Report_Data::norm( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$name   = isset( $new[ $key ] ) ? sanitize_text_field( (string) $new[ $key ] ) : '';
			$choice = sanitize_text_field( (string) $choice );
			$value  = '' !== $name ? $name : $choice;
			if ( '' !== $value ) {
				$out[ $key ] = mb_substr( $value, 0, 80 );
			}
		}
		$all[ $kind ] = $out;
		update_option( Report_Data::GROUPS_OPTION, $all, false );
		$back = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( (string) $_POST['back'] ) ) : '';
		wp_safe_redirect( add_query_arg( 'ts_saved', 'groups', $back ? $back : Reports::page_url( array() ) ) );
		exit;
	}

	/** Period field switching and the hover box for the charts. */
	public static function script() {
		?>
		<script>
		( function () {
			var range = document.querySelector( '.ts-rep-range' );
			function show() {
				document.querySelectorAll( '.ts-rep-filters [data-for]' ).forEach( function ( el ) {
					el.hidden = el.getAttribute( 'data-for' ) !== range.value;
				} );
			}
			if ( range ) {
				range.addEventListener( 'change', show );
				show();
			}
			var tip = document.querySelector( '.ts-rep-tip' );
			if ( ! tip ) {
				return;
			}
			function place( e, el ) {
				tip.textContent = el.getAttribute( 'data-tip' );
				tip.hidden = false;
				var x = e.clientX + 14, y = e.clientY + 14;
				if ( x + tip.offsetWidth > window.innerWidth - 8 ) {
					x = e.clientX - tip.offsetWidth - 14;
				}
				tip.style.left = Math.max( 8, x ) + 'px';
				tip.style.top = y + 'px';
			}
			document.querySelectorAll( '.ts-rep-hit' ).forEach( function ( el ) {
				el.addEventListener( 'mousemove', function ( e ) { place( e, el ); } );
				el.addEventListener( 'mouseleave', function () { tip.hidden = true; } );
			} );
		}() );
		</script>
		<?php
	}
}
