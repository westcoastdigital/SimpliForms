<?php
/**
 * Simpli Forms — Database Layer
 *
 * Handles all direct database operations for the submissions table.
 * No user-facing strings here — all error reporting is left to callers.
 *
 * @package SimpliForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SimpliForm_DB {

	const TABLE   = 'simpliforms_submissions';
	const VERSION = '1.0.0';
	const OPTION  = 'simpliforms_db_version';

	// ── Schema ────────────────────────────────────────────────────────────────

	/**
	 * Create or update the submissions table.
	 * Safe to call multiple times — uses dbDelta.
	 */
	public static function install(): void {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id       VARCHAR(100)    NOT NULL,
			submitted_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			ip_address    VARCHAR(45)     DEFAULT NULL,
			user_agent    TEXT            DEFAULT NULL,
			fields        LONGTEXT        NOT NULL,
			status        VARCHAR(20)     NOT NULL DEFAULT 'new',
			PRIMARY KEY  (id),
			KEY form_id  (form_id),
			KEY submitted_at (submitted_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::OPTION, self::VERSION );
	}

	// ── Write ─────────────────────────────────────────────────────────────────

	/**
	 * Insert a submission row.
	 *
	 * @param string $form_id  Form slug.
	 * @param array  $fields   Sanitised field values.
	 * @param string $ip       Client IP address.
	 * @param string $ua       User-agent string.
	 * @return int|false       Inserted row ID, or false on failure.
	 */
	public static function insert( string $form_id, array $fields, string $ip = '', string $ua = '' ): int|false {
		global $wpdb;

		$result = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			[
				'form_id'      => $form_id,
				'submitted_at' => current_time( 'mysql' ),
				'ip_address'   => $ip,
				'user_agent'   => $ua,
				'fields'       => wp_json_encode( $fields ),
				'status'       => 'new',
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $result ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update a submission's status.
	 */
	public static function update_status( int $id, string $status ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			[ 'status' => $status ],
			[ 'id'     => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Delete a submission by ID.
	 */
	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . self::TABLE, [ 'id' => $id ], [ '%d' ] );
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/**
	 * Fetch a paginated, optionally filtered list of submissions.
	 *
	 * @param string $form_id   Filter by form slug (empty = all).
	 * @param int    $limit     Rows per page.
	 * @param int    $offset    Row offset.
	 * @param string $orderby   Column to sort on.
	 * @param string $order     'ASC' or 'DESC'.
	 * @param string $date_from ISO date string YYYY-MM-DD (inclusive).
	 * @param string $date_to   ISO date string YYYY-MM-DD (inclusive).
	 * @return array
	 */
	public static function get_submissions(
		string $form_id   = '',
		int    $limit     = 20,
		int    $offset    = 0,
		string $orderby   = 'submitted_at',
		string $order     = 'DESC',
		string $date_from = '',
		string $date_to   = ''
	): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// Whitelist sortable columns.
		$allowed_orderby = [ 'id', 'form_id', 'submitted_at', 'status' ];
		$orderby = in_array( $orderby, $allowed_orderby, true ) ? $orderby : 'submitted_at';
		$order   = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

		$where  = [];
		$params = [];

		if ( $form_id ) {
			$where[]  = 'form_id = %s';
			$params[] = $form_id;
		}
		if ( $date_from ) {
			$where[]  = 'submitted_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where[]  = 'submitted_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$params[]  = $limit;
		$params[]  = $offset;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				...$params
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Fetch a single submission by ID.
	 */
	public static function get_submission( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) ?: null;
	}

	/**
	 * Count submissions, with optional filters.
	 */
	public static function count( string $form_id = '', string $date_from = '', string $date_to = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$where  = [];
		$params = [];

		if ( $form_id ) {
			$where[]  = 'form_id = %s';
			$params[] = $form_id;
		}
		if ( $date_from ) {
			$where[]  = 'submitted_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where[]  = 'submitted_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$params ) );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Count unread ('new') submissions, optionally filtered by form.
	 */
	public static function count_new( string $form_id = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( $form_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE form_id = %s AND status = 'new'",
				$form_id
			) );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" );
	}

	/**
	 * Return all distinct form IDs that have at least one submission.
	 *
	 * @return string[]
	 */
	public static function get_form_ids(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return $wpdb->get_col( "SELECT DISTINCT form_id FROM {$table} ORDER BY form_id" ) ?: [];
	}
}