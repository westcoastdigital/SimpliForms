<?php
/**
 * Simpli Forms — Admin UI
 *
 * Registers the WordPress admin menu, handles bulk/single actions,
 * and renders the submissions list and single submission views.
 *
 * ─── AVAILABLE FILTERS ────────────────────────────────────────────────────────
 *
 * simpliforms_admin_per_page( int $per_page )
 *   Override the number of submissions shown per page (default 20).
 *
 * simpliforms_admin_row_actions( array $actions, array $row )
 *   Add or remove row action links on the submissions list table.
 *   $actions is an associative array keyed by action slug.
 *   $row is the full submission row array from the DB.
 *
 * ─── AVAILABLE ACTIONS ────────────────────────────────────────────────────────
 *
 * simpliforms_admin_before_single( array $row )
 *   Fires before the single-submission detail card is rendered.
 *
 * simpliforms_admin_after_single( array $row )
 *   Fires after the single-submission detail card is rendered.
 *
 * @package SimpliForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SimpliForm_Admin {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	public static function init(): void {
		add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
		add_action( 'admin_init',            [ self::class, 'handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_assets' ] );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public static function register_menu(): void {
		$unread = SimpliForm_DB::count_new();
		$bubble = $unread ? sprintf( ' <span class="awaiting-mod">%d</span>', $unread ) : '';

		add_menu_page(
			__( 'Simpli Forms', 'simpliforms' ),
			__( 'Simpli Forms', 'simpliforms' ) . $bubble,
			'manage_options',
			'simpliforms',
			[ self::class, 'render_page' ],
			'dashicons-email-alt2',
			25
		);
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public static function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'simpliforms' ) ) {
			return;
		}

		wp_add_inline_style( 'wp-admin', self::admin_css() );

		// Localise admin JS strings for use in inline scripts.
		wp_add_inline_script(
			'jquery',
			'var SimpliFormsAdmin = ' . wp_json_encode( [
				'i18n' => [
					'selectAction'    => __( 'Please select a bulk action.', 'simpliforms' ),
					'selectItems'     => __( 'Please select at least one submission.', 'simpliforms' ),
					'confirmDelete'   => __( 'Permanently delete the selected submissions?', 'simpliforms' ),
					'confirmDeleteOne'=> __( 'Delete this submission?', 'simpliforms' ),
					'confirmDeleteSingle' => __( 'Permanently delete this submission?', 'simpliforms' ),
				],
			] ) . ';',
			'before'
		);
	}

	// ── Action Handling ───────────────────────────────────────────────────────

	public static function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Delete a single submission.
		if ( isset( $_GET['sf_delete'], $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'sf_delete' ) ) {
			SimpliForm_DB::delete( (int) $_GET['sf_delete'] );
			wp_redirect( remove_query_arg( [ 'sf_delete', '_wpnonce', 'sf_view' ] ) );
			exit;
		}

		// Bulk actions.
		if ( isset( $_POST['sf_bulk_action'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'sf_bulk' ) ) {
			$ids    = array_map( 'intval', (array) ( $_POST['sf_ids'] ?? [] ) );
			$action = sanitize_key( $_POST['sf_bulk_action'] );

			foreach ( $ids as $id ) {
				if ( $action === 'delete' ) {
					SimpliForm_DB::delete( $id );
				} elseif ( $action === 'mark_read' ) {
					SimpliForm_DB::update_status( $id, 'read' );
				} elseif ( $action === 'mark_unread' ) {
					SimpliForm_DB::update_status( $id, 'new' );
				}
			}

			wp_redirect( remove_query_arg( [ 'sf_view' ] ) );
			exit;
		}

		// Legacy single-button bulk delete (backwards compat).
		if ( isset( $_POST['sf_bulk_delete'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'sf_bulk' ) ) {
			$ids = array_map( 'intval', (array) ( $_POST['sf_ids'] ?? [] ) );
			foreach ( $ids as $id ) {
				SimpliForm_DB::delete( $id );
			}
			wp_redirect( remove_query_arg( [ 'sf_view' ] ) );
			exit;
		}
	}

	// ── Page Render ───────────────────────────────────────────────────────────

	public static function render_page(): void {
		$form_id   = sanitize_key( $_GET['form_id'] ?? '' );
		$view_id   = (int) ( $_GET['sf_view'] ?? 0 );
		$page_num  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		/**
		 * Filter: simpliforms_admin_per_page
		 *
		 * Override the number of submissions shown per page in the admin.
		 *
		 * @param int $per_page Default 20.
		 */
		$per_page  = (int) apply_filters( 'simpliforms_admin_per_page', 20 );
		$offset    = ( $page_num - 1 ) * $per_page;
		$orderby   = sanitize_key( $_GET['orderby'] ?? 'submitted_at' );
		$order     = strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$date_from = sanitize_text_field( $_GET['date_from'] ?? '' );
		$date_to   = sanitize_text_field( $_GET['date_to']   ?? '' );

		// Validate date format (YYYY-MM-DD).
		$date_from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ? $date_from : '';
		$date_to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to )   ? $date_to   : '';

		$form_ids    = SimpliForm_DB::get_form_ids();
		$total       = SimpliForm_DB::count( $form_id, $date_from, $date_to );
		$submissions = SimpliForm_DB::get_submissions( $form_id, $per_page, $offset, $orderby, $order, $date_from, $date_to );
		$total_pages = (int) ceil( $total / $per_page );

		// Viewing a single submission.
		if ( $view_id ) {
			$single = SimpliForm_DB::get_submission( $view_id );
			if ( $single ) {
				SimpliForm_DB::update_status( $view_id, 'read' );
				self::render_single( $single );
				return;
			}
		}

		// ── Page wrapper ──────────────────────────────────────────────────────────
		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Simpli Forms', 'simpliforms' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		// ── Form filter tabs (subsubsub — native WP style) ───────────────────────
		if ( $form_ids ) {
			$all_count = SimpliForm_DB::count();
			$all_new   = SimpliForm_DB::count_new();
			$all_url   = admin_url( 'admin.php?page=simpliforms' );

			$tab_items   = [];
			$tab_items[] = sprintf(
				'<li class="all"><a href="%s"%s>%s <span class="count">(%d)</span>%s</a>',
				esc_url( $all_url ),
				! $form_id ? ' class="current" aria-current="page"' : '',
				esc_html__( 'All', 'simpliforms' ),
				$all_count,
				$all_new ? sprintf( ' <span class="awaiting-mod">%d</span>', $all_new ) : ''
			);

			foreach ( $form_ids as $fid ) {
				$url     = add_query_arg( 'form_id', $fid, admin_url( 'admin.php?page=simpliforms' ) );
				$cnt     = SimpliForm_DB::count( $fid );
				$new_cnt = SimpliForm_DB::count_new( $fid );
				$tab_items[] = sprintf(
					'<li class="%s"><a href="%s"%s>%s <span class="count">(%d)</span>%s</a>',
					esc_attr( $fid ),
					esc_url( $url ),
					$form_id === $fid ? ' class="current" aria-current="page"' : '',
					esc_html( ucwords( str_replace( [ '-', '_' ], ' ', $fid ) ) ),
					$cnt,
					$new_cnt ? sprintf( ' <span class="awaiting-mod">%d</span>', $new_cnt ) : ''
				);
			}

			echo '<ul class="subsubsub">';
			echo implode( ' | </li>', $tab_items ) . '</li>';
			echo '</ul>';
		}

		// ── Empty state ───────────────────────────────────────────────────────────
		if ( ! $submissions ) {
			echo '<div class="sf-empty"><span class="dashicons dashicons-email-alt2"></span><p>' . esc_html__( 'No submissions yet.', 'simpliforms' ) . '</p></div>';
			echo '</div>';
			return;
		}

		$pagination_html = self::pagination_html( $page_num, $total_pages, $total, $form_id, $orderby, $order, $date_from, $date_to );

		// ── Date filter form — standalone GET form, OUTSIDE the bulk POST form ────
		$clear_url = esc_url( add_query_arg( array_filter( [
			'page'    => 'simpliforms',
			'form_id' => $form_id,
			'orderby' => $orderby !== 'submitted_at' ? $orderby : '',
			'order'   => $order !== 'DESC' ? $order : '',
		] ), admin_url( 'admin.php' ) ) );

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" id="sf-date-filter-form">';
		echo '<input type="hidden" name="page" value="simpliforms">';
		if ( $form_id ) echo '<input type="hidden" name="form_id" value="' . esc_attr( $form_id ) . '">';
		if ( $orderby !== 'submitted_at' ) echo '<input type="hidden" name="orderby" value="' . esc_attr( $orderby ) . '">';
		if ( $order !== 'DESC' ) echo '<input type="hidden" name="order" value="' . esc_attr( $order ) . '">';
		echo '</form>';

		// ── Tablenav top ─────────────────────────────────────────────────────────
		$bulk_url = add_query_arg( array_filter( [
			'form_id'   => $form_id,
			'orderby'   => $orderby !== 'submitted_at' ? $orderby : '',
			'order'     => $order !== 'DESC' ? $order : '',
			'date_from' => $date_from,
			'date_to'   => $date_to,
		] ), admin_url( 'admin.php?page=simpliforms' ) );

		echo '<form method="post" action="' . esc_url( $bulk_url ) . '">';
		wp_nonce_field( 'sf_bulk', '_wpnonce' );

		echo '<div class="tablenav top">';
		echo '<div class="alignleft actions bulkactions">';
		printf( '<label for="bulk-action-selector-top" class="screen-reader-text">%s</label>', esc_html__( 'Select bulk action', 'simpliforms' ) );
		echo '<select name="sf_bulk_action" id="bulk-action-selector-top">';
		printf( '<option value="-1">%s</option>', esc_html__( 'Bulk actions', 'simpliforms' ) );
		printf( '<option value="mark_read">%s</option>', esc_html__( 'Mark as read', 'simpliforms' ) );
		printf( '<option value="mark_unread">%s</option>', esc_html__( 'Mark as unread', 'simpliforms' ) );
		printf( '<option value="delete">%s</option>', esc_html__( 'Delete', 'simpliforms' ) );
		echo '</select>';
		printf( '<input type="submit" name="sf_bulk_apply" id="doaction" class="button action" value="%s">', esc_attr__( 'Apply', 'simpliforms' ) );
		echo '</div>';

		// ── Date filter controls ──────────────────────────────────────────────────
		echo '<div class="alignleft actions" style="display:inline-flex;align-items:center;gap:6px;">';
		printf( '<label for="sf-date-from" class="screen-reader-text">%s</label>', esc_html__( 'From date', 'simpliforms' ) );
		echo '<input type="date" id="sf-date-from" name="date_from" form="sf-date-filter-form" class="sf-date-input" value="' . esc_attr( $date_from ) . '">';
		printf( '<span style="color:#646970;font-size:13px;">%s</span>', esc_html__( 'to', 'simpliforms' ) );
		printf( '<label for="sf-date-to" class="screen-reader-text">%s</label>', esc_html__( 'To date', 'simpliforms' ) );
		echo '<input type="date" id="sf-date-to" name="date_to" form="sf-date-filter-form" class="sf-date-input" value="' . esc_attr( $date_to ) . '">';
		printf( '<input type="submit" form="sf-date-filter-form" class="button" value="%s">', esc_attr__( 'Filter', 'simpliforms' ) );
		if ( $date_from || $date_to ) {
			printf( ' <a href="%s" class="button sf-date-clear">%s</a>', $clear_url, esc_html__( 'Clear', 'simpliforms' ) );
		}
		echo '</div>';

		echo $pagination_html;
		echo '<br class="clear">';
		echo '</div>';

		// ── Submissions table ─────────────────────────────────────────────────────
		$sort_url = function ( string $col ) use ( $form_id, $orderby, $order, $date_from, $date_to ): string {
			$next = ( $orderby === $col && $order === 'DESC' ) ? 'ASC' : ( ( $orderby === $col && $order === 'ASC' ) ? 'DESC' : ( in_array( $col, [ 'form_id', 'status' ], true ) ? 'ASC' : 'DESC' ) );
			return esc_url( add_query_arg( array_filter( [ 'page' => 'simpliforms', 'form_id' => $form_id, 'orderby' => $col, 'order' => $next, 'date_from' => $date_from, 'date_to' => $date_to ] ), admin_url( 'admin.php' ) ) );
		};
		$sort_class = function ( string $col ) use ( $orderby, $order ): string {
			if ( $orderby !== $col ) return 'manage-column sortable desc';
			return 'manage-column sorted ' . ( $order === 'ASC' ? 'asc' : 'desc' );
		};
		$sort_th = function ( string $col, string $label ) use ( $sort_url, $sort_class ): string {
			return '<th scope="col" class="' . $sort_class( $col ) . '"><a href="' . $sort_url( $col ) . '"><span>' . esc_html( $label ) . '</span><span class="sorting-indicator"></span></a></th>';
		};

		echo '<table class="wp-list-table widefat fixed striped posts">';
		echo '<thead><tr>';
		printf( '<td id="cb" class="manage-column column-cb check-column"><label class="screen-reader-text" for="sf-check-all-top">%s</label><input id="sf-check-all-top" type="checkbox"></td>', esc_html__( 'Select All', 'simpliforms' ) );
		printf( '<th scope="col" class="manage-column column-primary">%s</th>', esc_html__( 'Submission', 'simpliforms' ) );
		echo $sort_th( 'form_id',      __( 'Form', 'simpliforms' ) );
		echo $sort_th( 'submitted_at', __( 'Date', 'simpliforms' ) );
		printf( '<th scope="col" class="manage-column">%s</th>', esc_html__( 'IP Address', 'simpliforms' ) );
		echo $sort_th( 'status', __( 'Status', 'simpliforms' ) );
		echo '</tr></thead>';

		echo '<tfoot><tr>';
		printf( '<td class="manage-column column-cb check-column"><label class="screen-reader-text" for="sf-check-all-bottom">%s</label><input id="sf-check-all-bottom" type="checkbox"></td>', esc_html__( 'Select All', 'simpliforms' ) );
		printf( '<th scope="col" class="manage-column column-primary">%s</th>', esc_html__( 'Submission', 'simpliforms' ) );
		echo $sort_th( 'form_id',      __( 'Form', 'simpliforms' ) );
		echo $sort_th( 'submitted_at', __( 'Date', 'simpliforms' ) );
		printf( '<th scope="col" class="manage-column">%s</th>', esc_html__( 'IP Address', 'simpliforms' ) );
		echo $sort_th( 'status', __( 'Status', 'simpliforms' ) );
		echo '</tr></tfoot>';

		echo '<tbody id="the-list">';

		foreach ( $submissions as $row ) {
			$fields  = json_decode( $row['fields'], true ) ?? [];
			$is_new  = $row['status'] === 'new';

			// Build preview title from first meaningful field.
			$title_keys = [ 'name', 'full_name', 'first_name', 'email', 'subject', 'message' ];
			$title_val  = '';
			foreach ( $title_keys as $tk ) {
				if ( ! empty( $fields[ $tk ] ) && ! is_array( $fields[ $tk ] ) ) {
					$title_val = $fields[ $tk ];
					break;
				}
			}
			if ( ! $title_val && $fields ) {
				$first     = reset( $fields );
				$title_val = is_array( $first ) ? implode( ', ', $first ) : $first;
			}
			$title_val = mb_substr( $title_val, 0, 80 ) . ( mb_strlen( $title_val ) > 80 ? '…' : '' );

			// Secondary preview: remaining fields snippet.
			$preview_parts = [];
			$i = 0;
			foreach ( $fields as $k => $v ) {
				if ( $i++ >= 3 ) break;
				$val = is_array( $v ) ? implode( ', ', $v ) : $v;
				$preview_parts[] = esc_html( ucfirst( str_replace( [ '_', '-' ], ' ', $k ) ) ) . ': ' . esc_html( mb_substr( $val, 0, 40 ) ) . ( mb_strlen( $val ) > 40 ? '…' : '' );
			}

			$view_url   = add_query_arg( [ 'sf_view' => $row['id'], 'form_id' => $form_id ], admin_url( 'admin.php?page=simpliforms' ) );
			$delete_url = wp_nonce_url( add_query_arg( [ 'sf_delete' => $row['id'], 'form_id' => $form_id ], admin_url( 'admin.php?page=simpliforms' ) ), 'sf_delete' );

			/**
			 * Filter: simpliforms_admin_row_actions
			 *
			 * Add or remove row action links on the submissions list table.
			 * $actions is a keyed array of HTML anchor strings.
			 *
			 * @param array $actions Associative array of action links.
			 * @param array $row     The full submission DB row.
			 */
			$row_actions = apply_filters( 'simpliforms_admin_row_actions', [
				'view'  => '<a href="' . esc_url( $view_url ) . '">'   . esc_html__( 'View', 'simpliforms' ) . '</a>',
				'trash' => '<a href="' . esc_url( $delete_url ) . '" class="submitdelete" onclick="return confirm(SimpliFormsAdmin.i18n.confirmDeleteOne)">' . esc_html__( 'Delete', 'simpliforms' ) . '</a>',
			], $row );

			$actions_html = '';
			foreach ( $row_actions as $slug => $link ) {
				$actions_html .= '<span class="' . esc_attr( $slug ) . '">' . $link . '</span> | ';
			}
			$actions_html = rtrim( $actions_html, ' | ' );

			$row_class = 'iedit author-self level-0 post-' . (int) $row['id'] . ' type-sf_submission status-' . esc_attr( $row['status'] );
			if ( $is_new ) {
				$row_class .= ' sf-row-new';
			}

			echo '<tr id="post-' . (int) $row['id'] . '" class="' . $row_class . '">';

			// Checkbox.
			printf(
				'<th scope="row" class="check-column"><label class="screen-reader-text" for="cb-select-%1$d">%2$s</label><input id="cb-select-%1$d" type="checkbox" name="sf_ids[]" value="%1$d"></th>',
				(int) $row['id'],
				/* translators: %d = submission number */
				sprintf( __( 'Select submission #%d', 'simpliforms' ), (int) $row['id'] )
			);

			// Primary column.
			echo '<td class="title column-primary page-title" data-colname="' . esc_attr__( 'Submission', 'simpliforms' ) . '">';
			echo '<strong>';
			echo '<a class="row-title" href="' . esc_url( $view_url ) . '">';
			printf(
				'<span class="screen-reader-text">%s</span>',
				/* translators: %d = submission ID */
				sprintf( __( 'Submission #%d: ', 'simpliforms' ), (int) $row['id'] )
			);
			echo esc_html( $title_val ?: __( '(no preview)', 'simpliforms' ) );
			if ( $is_new ) {
				printf( ' — <span class="post-state">%s</span>', esc_html__( 'New', 'simpliforms' ) );
			}
			echo '</a>';
			echo '</strong>';
			if ( $preview_parts ) {
				echo '<p class="sf-preview-snippet">' . implode( ' · ', $preview_parts ) . '</p>';
			}
			echo '<div class="row-actions">' . $actions_html . '</div>';
			printf(
				'<button type="button" class="toggle-row"><span class="toggle-row-icon" aria-hidden="true"></span><span class="screen-reader-text">%s</span></button>',
				esc_html__( 'Show more details', 'simpliforms' )
			);
			echo '</td>';

			// Form.
			echo '<td class="column-form" data-colname="' . esc_attr__( 'Form', 'simpliforms' ) . '"><code>' . esc_html( $row['form_id'] ) . '</code></td>';

			// Date.
			echo '<td class="column-date" data-colname="' . esc_attr__( 'Date', 'simpliforms' ) . '">';
			echo '<abbr title="' . esc_attr( $row['submitted_at'] ) . '">' . esc_html( date_i18n( 'd M Y', strtotime( $row['submitted_at'] ) ) ) . '</abbr>';
			echo '<br><span class="sf-time">' . esc_html( date_i18n( 'H:i', strtotime( $row['submitted_at'] ) ) ) . '</span>';
			echo '</td>';

			// IP.
			echo '<td class="column-ip" data-colname="' . esc_attr__( 'IP Address', 'simpliforms' ) . '">' . esc_html( $row['ip_address'] ) . '</td>';

			// Status.
			$status_label = $row['status'] === 'new'
				? __( 'New', 'simpliforms' )
				: ucfirst( $row['status'] );
			echo '<td class="column-status" data-colname="' . esc_attr__( 'Status', 'simpliforms' ) . '"><span class="sf-status sf-status--' . esc_attr( $row['status'] ) . '">' . esc_html( $status_label ) . '</span></td>';

			echo '</tr>';
		}

		echo '</tbody></table>';

		// ── Tablenav bottom ───────────────────────────────────────────────────────
		echo '<div class="tablenav bottom">';
		echo '<div class="alignleft actions bulkactions">';
		printf( '<label for="bulk-action-selector-bottom" class="screen-reader-text">%s</label>', esc_html__( 'Select bulk action', 'simpliforms' ) );
		echo '<select name="sf_bulk_action" id="bulk-action-selector-bottom">';
		printf( '<option value="-1">%s</option>', esc_html__( 'Bulk actions', 'simpliforms' ) );
		printf( '<option value="mark_read">%s</option>', esc_html__( 'Mark as read', 'simpliforms' ) );
		printf( '<option value="mark_unread">%s</option>', esc_html__( 'Mark as unread', 'simpliforms' ) );
		printf( '<option value="delete">%s</option>', esc_html__( 'Delete', 'simpliforms' ) );
		echo '</select>';
		printf( '<input type="submit" name="sf_bulk_apply" id="doaction2" class="button action" value="%s">', esc_attr__( 'Apply', 'simpliforms' ) );
		echo '</div>';
		echo $pagination_html;
		echo '<br class="clear">';
		echo '</div>';

		echo '</form>';

		// ── Bulk select JS ────────────────────────────────────────────────────────
		echo '<script>
		(function(){
			var i18n = (typeof SimpliFormsAdmin !== "undefined") ? SimpliFormsAdmin.i18n : {};

			function syncCheckboxes(sourceId) {
				var checked = document.getElementById(sourceId).checked;
				document.querySelectorAll("input[name=\'sf_ids[]\']").forEach(function(c){ c.checked = checked; });
				["sf-check-all-top","sf-check-all-bottom"].forEach(function(id){ var el = document.getElementById(id); if(el) el.checked = checked; });
			}
			["sf-check-all-top","sf-check-all-bottom"].forEach(function(id){
				var el = document.getElementById(id);
				if(el) el.addEventListener("change", function(){ syncCheckboxes(id); });
			});

			// Sync top/bottom dropdowns with each other.
			var selTop = document.getElementById("bulk-action-selector-top");
			var selBot = document.getElementById("bulk-action-selector-bottom");
			if(selTop && selBot) {
				selTop.addEventListener("change", function(){ selBot.value = selTop.value; });
				selBot.addEventListener("change", function(){ selTop.value = selBot.value; });
			}

			// Validate and confirm before bulk action.
			document.querySelectorAll("input[name=sf_bulk_apply]").forEach(function(btn){
				btn.addEventListener("click", function(e){
					var action = selTop ? selTop.value : "-1";
					if(action === "-1") { e.preventDefault(); alert(i18n.selectAction || "Please select a bulk action."); return; }
					var checked = document.querySelectorAll("input[name=\'sf_ids[]\']:checked");
					if(!checked.length) { e.preventDefault(); alert(i18n.selectItems || "Please select at least one submission."); return; }
					if(action === "delete" && !confirm(i18n.confirmDelete || "Permanently delete the selected submissions?")) { e.preventDefault(); return; }
				});
			});
		})();
		</script>';

		echo '</div>';
	}

	// ── Pagination ────────────────────────────────────────────────────────────

	private static function pagination_html(
		int    $page_num,
		int    $total_pages,
		int    $total,
		string $form_id,
		string $orderby   = 'submitted_at',
		string $order     = 'DESC',
		string $date_from = '',
		string $date_to   = ''
	): string {
		if ( $total_pages < 1 ) {
			return '';
		}

		$base_args = array_filter( [
			'page'      => 'simpliforms',
			'form_id'   => $form_id,
			'orderby'   => $orderby !== 'submitted_at' ? $orderby : '',
			'order'     => $order !== 'DESC' ? $order : '',
			'date_from' => $date_from,
			'date_to'   => $date_to,
		] );

		$prev_url = $page_num > 1
			? esc_url( add_query_arg( array_merge( $base_args, [ 'paged' => $page_num - 1 ] ), admin_url( 'admin.php' ) ) )
			: '';
		$next_url = $page_num < $total_pages
			? esc_url( add_query_arg( array_merge( $base_args, [ 'paged' => $page_num + 1 ] ), admin_url( 'admin.php' ) ) )
			: '';
		$first_url = esc_url( add_query_arg( array_merge( $base_args, [ 'paged' => 1 ] ), admin_url( 'admin.php' ) ) );
		$last_url  = esc_url( add_query_arg( array_merge( $base_args, [ 'paged' => $total_pages ] ), admin_url( 'admin.php' ) ) );

		$out  = '<div class="tablenav-pages' . ( $total_pages === 1 ? ' one-page' : '' ) . '">';
		$out .= '<span class="displaying-num">' . sprintf(
			/* translators: %s = number of items */
			_n( '%s item', '%s items', $total, 'simpliforms' ),
			number_format_i18n( $total )
		) . '</span>';

		if ( $total_pages > 1 ) {
			$out .= '<span class="pagination-links">';

			$out .= $page_num > 1
				? '<a class="first-page button" href="' . $first_url . '"><span aria-hidden="true">«</span><span class="screen-reader-text">' . esc_html__( 'First page', 'simpliforms' ) . '</span></a> '
				: '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span> ';

			$out .= $prev_url
				? '<a class="prev-page button" href="' . $prev_url . '"><span aria-hidden="true">‹</span><span class="screen-reader-text">' . esc_html__( 'Previous page', 'simpliforms' ) . '</span></a>'
				: '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';

			$out .= ' <span class="screen-reader-text">' . esc_html__( 'Current Page', 'simpliforms' ) . '</span><span id="table-paging" class="paging-input"><span class="tablenav-paging-text">'
				. sprintf(
					/* translators: 1: current page, 2: total pages */
					_x( '%1$s of %2$s', 'paging', 'simpliforms' ),
					$page_num,
					'<span class="total-pages">' . $total_pages . '</span>'
				)
				. '</span></span> ';

			$out .= $next_url
				? '<a class="next-page button" href="' . $next_url . '"><span aria-hidden="true">›</span><span class="screen-reader-text">' . esc_html__( 'Next page', 'simpliforms' ) . '</span></a>'
				: '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';

			$out .= ' ';
			$out .= $page_num < $total_pages
				? '<a class="last-page button" href="' . $last_url . '"><span aria-hidden="true">»</span><span class="screen-reader-text">' . esc_html__( 'Last page', 'simpliforms' ) . '</span></a>'
				: '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';

			$out .= '</span>';
		}

		$out .= '</div>';
		return $out;
	}

	// ── Single View ───────────────────────────────────────────────────────────

	private static function render_single( array $row ): void {
		$fields     = json_decode( $row['fields'], true ) ?? [];
		$back_url   = remove_query_arg( [ 'sf_view', 'paged' ] );
		$delete_url = wp_nonce_url( add_query_arg( 'sf_delete', $row['id'], $back_url ), 'sf_delete' );

		/**
		 * Action: simpliforms_admin_before_single
		 *
		 * Fires before the single-submission detail card is rendered.
		 *
		 * @param array $row The full submission DB row.
		 */
		do_action( 'simpliforms_admin_before_single', $row );

		echo '<div class="wrap sf-admin--single">';
		printf(
			'<p class="sf-back"><a href="%s">%s</a></p>',
			esc_url( $back_url ),
			esc_html__( '← Back to submissions', 'simpliforms' )
		);

		echo '<div class="sf-single-card">';
		echo '<div class="sf-single-header">';
		printf(
			'<h2>%s</h2><code class="sf-form-badge">%s</code>',
			/* translators: %d = submission ID */
			sprintf( esc_html__( 'Submission #%d', 'simpliforms' ), (int) $row['id'] ),
			esc_html( $row['form_id'] )
		);
		echo '</div>';

		echo '<div class="sf-meta-row">';
		printf(
			'<div class="sf-meta-item"><span class="sf-meta-label">%s</span><span>%s</span></div>',
			esc_html__( 'Date', 'simpliforms' ),
			esc_html( date_i18n( 'd M Y \a\t H:i:s', strtotime( $row['submitted_at'] ) ) )
		);
		printf(
			'<div class="sf-meta-item"><span class="sf-meta-label">%s</span><span>%s</span></div>',
			esc_html__( 'IP', 'simpliforms' ),
			esc_html( $row['ip_address'] )
		);
		$status_display = $row['status'] === 'new' ? __( 'New', 'simpliforms' ) : ucfirst( $row['status'] );
		printf(
			'<div class="sf-meta-item"><span class="sf-meta-label">%s</span><span class="sf-status sf-status--%s">%s</span></div>',
			esc_html__( 'Status', 'simpliforms' ),
			esc_attr( $row['status'] ),
			esc_html( $status_display )
		);
		echo '</div>';

		echo '<table class="sf-fields-table">';
		printf(
			'<thead><tr><th>%s</th><th>%s</th></tr></thead><tbody>',
			esc_html__( 'Field', 'simpliforms' ),
			esc_html__( 'Value', 'simpliforms' )
		);

		foreach ( $fields as $key => $value ) {
			$label = esc_html( ucwords( str_replace( [ '_', '-' ], ' ', $key ) ) );
			$val   = is_array( $value ) ? implode( ', ', $value ) : $value;
			echo '<tr><th>' . $label . '</th><td>' . nl2br( esc_html( $val ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<div class="sf-single-meta-row">';
		printf(
			'<p><strong>%s</strong><br><span class="sf-ua">%s</span></p>',
			esc_html__( 'User Agent:', 'simpliforms' ),
			esc_html( $row['user_agent'] )
		);
		echo '</div>';

		echo '<div class="sf-single-actions">';
		printf(
			'<a href="%s" class="button button-secondary sf-btn-delete" onclick="return confirm(SimpliFormsAdmin.i18n.confirmDeleteSingle)">%s</a>',
			esc_url( $delete_url ),
			esc_html__( 'Delete Submission', 'simpliforms' )
		);
		echo '</div>';

		echo '</div></div>';

		/**
		 * Action: simpliforms_admin_after_single
		 *
		 * Fires after the single-submission detail card is rendered.
		 *
		 * @param array $row The full submission DB row.
		 */
		do_action( 'simpliforms_admin_after_single', $row );

		echo '</div>';
	}

	// ── CSS ───────────────────────────────────────────────────────────────────

	private static function admin_css(): string {
		return '
		/* ── Simpli Forms Admin ── */

		.sf-date-input { font-size: 13px; line-height: 1.4; padding: 0 6px; height: 30px; vertical-align: middle; border: 1px solid #8c8f94; border-radius: 3px; }
		.sf-date-clear { margin-left: 2px; }

		#the-list .sf-row-new td,
		#the-list .sf-row-new th { font-weight: 700; }
		#the-list .sf-row-new .row-title { font-weight: 700; }

		.sf-preview-snippet { color: #646970; font-size: 12px; margin: 3px 0 0; line-height: 1.5; }
		.sf-time { color: #646970; font-size: 12px; }

		.sf-status { display: inline-block; padding: 1px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .3px; }
		.sf-status--new  { background: #dbeafe; color: #1d4ed8; }
		.sf-status--read { background: #f0f0f1; color: #646970; }

		.row-actions .submitdelete { color: #b32d2e; }
		.row-actions .submitdelete:hover { color: #b32d2e; text-decoration: underline; }

		.sf-empty { text-align: center; padding: 60px 20px; color: #646970; }
		.sf-empty .dashicons { font-size: 48px; width: 48px; height: 48px; margin: 0 auto 12px; display: block; }

		.sf-admin--single .sf-back { margin-bottom: 16px; }
		.sf-single-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; overflow: hidden; max-width: 860px; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
		.sf-single-header { display: flex; align-items: center; gap: 12px; padding: 20px 24px; border-bottom: 1px solid #dcdcde; background: #f6f7f7; }
		.sf-single-header h2 { margin: 0; font-size: 18px; }
		.sf-form-badge { background: #1d1d1d; color: #fff; padding: 3px 10px; border-radius: 3px; font-size: 12px; }
		.sf-meta-row { display: flex; gap: 32px; padding: 14px 24px; border-bottom: 1px solid #dcdcde; flex-wrap: wrap; }
		.sf-meta-item { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
		.sf-meta-label { font-weight: 600; color: #646970; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
		.sf-fields-table { width: 100%; border-collapse: collapse; }
		.sf-fields-table th,
		.sf-fields-table td { padding: 12px 24px; border-bottom: 1px solid #f0f0f1; text-align: left; vertical-align: top; font-size: 14px; }
		.sf-fields-table th { background: #f6f7f7; width: 200px; font-weight: 600; color: #2c3338; white-space: nowrap; }
		.sf-fields-table tbody tr:last-child th,
		.sf-fields-table tbody tr:last-child td { border-bottom: none; }
		.sf-single-meta-row { padding: 14px 24px; border-top: 1px solid #dcdcde; }
		.sf-ua { font-size: 12px; color: #646970; word-break: break-all; }
		.sf-single-actions { padding: 16px 24px; border-top: 1px solid #dcdcde; background: #f6f7f7; }
		';
	}
}