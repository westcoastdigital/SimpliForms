<?php
/**
 * Plugin Name: Simpli Forms
 * Plugin URI:  https://simpliweb.com.au
 * Description: Drop-in HTML form handler for WordPress. Logging, email templates, spam protection — zero backend form building required.
 * Version:     1.0.0
 * Author:      SimpliWeb
 * License:     GPL-2.0+
 *
 * ─── USAGE ───────────────────────────────────────────────────────────────────
 *
 * 1. Activate as a plugin OR require this file in your theme's functions.php:
 *       require_once get_template_directory() . '/inc/simpliforms.php';
 *
 * 2. Create a plain HTML file with your form. No special markup needed.
 *       Your form just needs a submit button — everything else is injected.
 *
 * 3. In your template, instantiate and render:
 *
 *       $form = new SimpliForm( 'contact', [
 *           'template' => get_template_directory() . '/forms/contact.html',
 *           'email'    => [
 *               'to'             => 'hello@example.com',
 *               'subject'        => 'New enquiry from the website',
 *               'template'       => get_template_directory() . '/forms/emails/contact-notification.php',
 *               'reply_to_field' => 'email',   // name attr of the email input
 *           ],
 *           'auto_response' => [
 *               'enabled'   => true,
 *               'to_field'  => 'email',
 *               'subject'   => 'Thanks for getting in touch!',
 *               'template'  => get_template_directory() . '/forms/emails/contact-auto-response.php',
 *           ],
 *       ] );
 *       echo $form->render();
 *
 * ─── EMAIL TEMPLATES ────────────────────────────────────────────────────────
 *
 * Your email template is a standard PHP file. You have access to:
 *   $fields     — associative array of all submitted field values
 *   $form_id    — the form identifier string
 *   $form_label — prettified form label
 *
 * Individual fields are also extracted as variables, e.g. echo $name; echo $email;
 * You can also use token replacement in subject lines: {{field_name}}
 *
 * If no template is provided, a clean default HTML table email is sent.
 *
 * ─── CUSTOM VALIDATION ──────────────────────────────────────────────────────
 *
 *   $form = new SimpliForm( 'contact', [
 *       ...
 *       'before_submit' => function( array $fields ) {
 *           if ( empty( $fields['message'] ) ) {
 *               return new WP_Error( 'validation', 'Please enter a message.' );
 *           }
 *           return true;
 *       },
 *       'after_submit' => function( array $fields, int $submission_id ) {
 *           // e.g. subscribe to Mailchimp, create a CPT, etc.
 *       },
 *   ] );
 *
 * ─── SPAM PROTECTION (all on by default) ────────────────────────────────────
 *
 *   'spam' => [
 *       'honeypot'   => true,   // Hidden field bots fill in
 *       'nonce'      => true,   // WordPress nonce verification
 *       'rate_limit' => 5,      // Max submissions per hour per IP (0 = off)
 *   ]
 *
 * ─── FRONTEND CSS HOOKS ─────────────────────────────────────────────────────
 *
 *   .simpliforms-wrapper        — outer wrapper div
 *   .simpliforms-response       — message area (empty by default)
 *   .simpliforms-success        — applied on success
 *   .simpliforms-error          — applied on error
 *   .simpliforms-loading        — applied to wrapper during submission
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Database Layer ───────────────────────────────────────────────────────────

class SimpliForm_DB {

	const TABLE   = 'simpliforms_submissions';
	const VERSION = '1.0.0';
	const OPTION  = 'simpliforms_db_version';

	/**
	 * Create or update the submissions table.
	 * Safe to call multiple times (uses dbDelta).
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

	public static function get_submissions( string $form_id = '', int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( $form_id ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE form_id = %s ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
					$form_id, $limit, $offset
				),
				ARRAY_A
			) ?: [];
		}

		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY submitted_at DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		) ?: [];
	}

	public static function get_submission( int $id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) ?: null;
	}

	public static function count( string $form_id = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( $form_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %s", $form_id ) );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public static function count_new( string $form_id = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		if ( $form_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %s AND status = 'new'", $form_id ) );
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new'" );
	}

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

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . self::TABLE, [ 'id' => $id ], [ '%d' ] );
	}

	public static function get_form_ids(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		return $wpdb->get_col( "SELECT DISTINCT form_id FROM {$table} ORDER BY form_id" ) ?: [];
	}
}

// ─── Main Form Class ──────────────────────────────────────────────────────────

class SimpliForm {

	private string $id;
	private array  $config;

	/** All instantiated forms — keyed by form ID. */
	private static array $registry = [];

	/**
	 * @param string $form_id  Unique slug for this form, e.g. 'contact', 'quote-request'
	 * @param array  $config   Configuration array (see file header for full reference)
	 */
	public function __construct( string $form_id, array $config = [] ) {
		$this->id = sanitize_key( $form_id );

		// ── Merge defaults ────────────────────────────────────────────────────
		$this->config = wp_parse_args( $config, [
			'template'        => '',
			'email'           => [],
			'auto_response'   => [],
			'spam'            => [],
			'log'             => true,
			'success_message' => 'Thank you! Your message has been sent.',
			'error_message'   => 'Something went wrong. Please try again.',
			'before_submit'   => null,  // callable( array $fields ): true|WP_Error
			'after_submit'    => null,  // callable( array $fields, int $submission_id ): void
		] );

		$this->config['email'] = wp_parse_args( $this->config['email'], [
			'to'             => get_option( 'admin_email' ),
			'subject'        => 'New submission: ' . $form_id,
			'template'       => '',
			'reply_to_field' => 'email',
			'from_name'      => get_bloginfo( 'name' ),
			'from_email'     => get_option( 'admin_email' ),
		] );

		$this->config['auto_response'] = wp_parse_args( $this->config['auto_response'], [
			'enabled'   => false,
			'to_field'  => 'email',
			'subject'   => 'Thanks for getting in touch',
			'template'  => '',
		] );

		$this->config['spam'] = wp_parse_args( $this->config['spam'], [
			'honeypot'   => true,
			'nonce'      => true,
			'rate_limit' => 5,   // max per hour per IP; 0 = disabled
		] );

		self::$registry[ $this->id ] = $this;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Render the form HTML. Echo or return.
	 */
	public function render(): string {
		if ( empty( $this->config['template'] ) || ! file_exists( $this->config['template'] ) ) {
			return sprintf(
				'<p style="color:red;"><strong>SimpliForm:</strong> Template not found for form <code>%s</code>.</p>',
				esc_html( $this->id )
			);
		}

		$html = file_get_contents( $this->config['template'] );
		$html = $this->inject_hidden_fields( $html );

		return sprintf(
			'<div class="simpliforms-wrapper" id="simpliforms-%1$s" data-form-id="%1$s">%2$s<div class="simpliforms-response" role="alert" aria-live="polite"></div></div>',
			esc_attr( $this->id ),
			$html
		);
	}

	// ── Injection ─────────────────────────────────────────────────────────────

	/**
	 * Injects the required hidden fields into the HTML template's <form> tag.
	 * Also removes any existing action/method attributes and sets them correctly.
	 */
	private function inject_hidden_fields( string $html ): string {
		$hidden = '';

		// Form routing
		$hidden .= sprintf( '<input type="hidden" name="simpliforms_action" value="%s">', esc_attr( $this->id ) );

		// WordPress nonce
		if ( $this->config['spam']['nonce'] ) {
			$hidden .= wp_nonce_field( 'simpliforms_submit_' . $this->id, 'simpliforms_nonce', true, false );
		}

		// Honeypot — visually hidden, tab-skipped, autocomplete off
		if ( $this->config['spam']['honeypot'] ) {
			$hp_name = 'sf_hp_' . $this->id;
			$hidden .= sprintf(
				'<div style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">' .
				'<label for="%1$s">Leave this field blank</label>' .
				'<input type="text" id="%1$s" name="%1$s" value="" tabindex="-1" autocomplete="off">' .
				'</div>',
				esc_attr( $hp_name )
			);
		}

		// Insert hidden fields just before </form>
		$html = preg_replace( '/<\/form\s*>/i', $hidden . '</form>', $html, 1 );

		return $html;
	}

	// ── AJAX dispatch ─────────────────────────────────────────────────────────

	public static function handle_ajax(): void {
		$form_id = sanitize_key( $_POST['simpliforms_action'] ?? '' );

		if ( ! $form_id || ! isset( self::$registry[ $form_id ] ) ) {
			wp_send_json_error( [ 'message' => 'Invalid form identifier.' ], 400 );
		}

		self::$registry[ $form_id ]->process();
	}

	// ── Process submission ────────────────────────────────────────────────────

	private function process(): void {

		// ── 1. Nonce ──────────────────────────────────────────────────────────
		if ( $this->config['spam']['nonce'] ) {
			if ( ! check_ajax_referer( 'simpliforms_submit_' . $this->id, 'simpliforms_nonce', false ) ) {
				wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page and try again.' ], 403 );
			}
		}

		// ── 2. Honeypot ───────────────────────────────────────────────────────
		if ( $this->config['spam']['honeypot'] ) {
			$hp_name = 'sf_hp_' . $this->id;
			if ( ! empty( $_POST[ $hp_name ] ) ) {
				// Silently succeed — fool the bot, don't reveal the check
				wp_send_json_success( [ 'message' => $this->config['success_message'] ] );
			}
		}

		// ── 3. Rate limiting ──────────────────────────────────────────────────
		if ( (int) $this->config['spam']['rate_limit'] > 0 ) {
			$ip  = $this->client_ip();
			$key = 'sf_rl_' . $this->id . '_' . md5( $ip );
			$hit = (int) get_transient( $key );

			if ( $hit >= (int) $this->config['spam']['rate_limit'] ) {
				wp_send_json_error( [ 'message' => 'Too many submissions. Please try again later.' ], 429 );
			}

			set_transient( $key, $hit + 1, HOUR_IN_SECONDS );
		}

		// ── 4. Collect & sanitize fields ──────────────────────────────────────
		$fields = $this->collect_fields();

		// ── 5. Custom validation callback ─────────────────────────────────────
		if ( is_callable( $this->config['before_submit'] ) ) {
			$result = call_user_func( $this->config['before_submit'], $fields );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 422 );
			}
		}

		// ── 6. Log to DB ──────────────────────────────────────────────────────
		$submission_id = 0;
		if ( $this->config['log'] ) {
			$submission_id = SimpliForm_DB::insert(
				$this->id,
				$fields,
				$this->client_ip(),
				sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' )
			);
		}

		// ── 7. Send notification email ────────────────────────────────────────
		$this->send_notification( $fields );

		// ── 8. Send auto-response ─────────────────────────────────────────────
		if ( $this->config['auto_response']['enabled'] ) {
			$this->send_auto_response( $fields );
		}

		// ── 9. After-submit hook ──────────────────────────────────────────────
		if ( is_callable( $this->config['after_submit'] ) ) {
			call_user_func( $this->config['after_submit'], $fields, (int) $submission_id );
		}

		wp_send_json_success( [ 'message' => $this->config['success_message'] ] );
	}

	// ── Field collection ──────────────────────────────────────────────────────

	private function collect_fields(): array {
		$internal = [
			'simpliforms_action',
			'simpliforms_nonce',
			'_wpnonce',
			'action',
			'sf_hp_' . $this->id,
		];

		$fields = [];

		foreach ( $_POST as $key => $value ) {
			if ( in_array( $key, $internal, true ) ) {
				continue;
			}
			// Drop any other honeypot-style keys
			if ( str_starts_with( $key, 'sf_hp_' ) ) {
				continue;
			}

			$clean_key = sanitize_key( $key );

			if ( is_array( $value ) ) {
				$fields[ $clean_key ] = array_map( 'sanitize_text_field', $value );
			} else {
				$fields[ $clean_key ] = sanitize_text_field( $value );
			}
		}

		return $fields;
	}

	// ── Email helpers ─────────────────────────────────────────────────────────

	private function send_notification( array $fields ): void {
		$cfg     = $this->config['email'];
		$subject = $this->replace_tokens( $cfg['subject'], $fields );
		$body    = $this->build_email( $cfg['template'], $fields, 'notification' );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $cfg['from_name'], $cfg['from_email'] ),
		];

		// Use the submitter's email as reply-to if available
		$reply_field = $cfg['reply_to_field'];
		if ( ! empty( $fields[ $reply_field ] ) && is_email( $fields[ $reply_field ] ) ) {
			$reply_name  = $fields['name'] ?? $fields['first_name'] ?? '';
			$headers[]   = sprintf( 'Reply-To: %s <%s>', $reply_name, $fields[ $reply_field ] );
		}

		wp_mail( $cfg['to'], $subject, $body, $headers );
	}

	private function send_auto_response( array $fields ): void {
		$cfg      = $this->config['auto_response'];
		$to_field = $cfg['to_field'];

		if ( empty( $fields[ $to_field ] ) || ! is_email( $fields[ $to_field ] ) ) {
			return;
		}

		$subject = $this->replace_tokens( $cfg['subject'], $fields );
		$body    = $this->build_email( $cfg['template'], $fields, 'auto_response' );

		$email_cfg = $this->config['email'];
		$headers   = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $email_cfg['from_name'], $email_cfg['from_email'] ),
		];

		wp_mail( $fields[ $to_field ], $subject, $body, $headers );
	}

	/**
	 * Build email body from a PHP template file, or fall back to a clean default table.
	 *
	 * Inside your PHP template you have access to:
	 *   $fields     — full associative array of submitted values
	 *   $form_id    — the form's slug
	 *   $form_label — prettified form label
	 *   Individual field variables are extracted, e.g. echo $name; echo $email;
	 */
	private function build_email( string $template_path, array $fields, string $type ): string {

		if ( $template_path && file_exists( $template_path ) ) {
			ob_start();
			$form_id    = $this->id;
			$form_label = ucwords( str_replace( [ '-', '_' ], ' ', $this->id ) );
			// Extract field values as variables — sanitised for output
			extract( array_map( 'esc_html', $fields ), EXTR_SKIP );
			// Also keep the raw array for loops
			$form_fields = $fields;
			include $template_path;
			return ob_get_clean();
		}

		// ── Default fallback email ────────────────────────────────────────────
		$rows = '';
		foreach ( $fields as $key => $value ) {
			$label = esc_html( ucwords( str_replace( [ '_', '-' ], ' ', $key ) ) );
			$val   = is_array( $value ) ? implode( ', ', $value ) : $value;
			$rows .= sprintf(
				'<tr>
					<th style="text-align:left;padding:10px 16px;background:#f9f9f9;border:1px solid #e5e7eb;width:28%%;white-space:nowrap;vertical-align:top;">%s</th>
					<td style="padding:10px 16px;border:1px solid #e5e7eb;vertical-align:top;">%s</td>
				</tr>',
				$label,
				nl2br( esc_html( $val ) )
			);
		}

		$site_name = esc_html( get_bloginfo( 'name' ) );
		$form_label = ucwords( str_replace( [ '-', '_' ], ' ', $this->id ) );
		$submitted  = esc_html( current_time( 'd M Y \a\t H:i' ) );

		return <<<HTML
		<!DOCTYPE html>
		<html lang="en">
		<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
		<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
		  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:40px 16px;">
		    <tr><td align="center">
		      <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.08);">
		        <tr>
		          <td style="background:#1d1d1d;padding:24px 32px;">
		            <p style="margin:0;color:#ffffff;font-size:18px;font-weight:600;">{$site_name}</p>
		            <p style="margin:4px 0 0;color:#a3a3a3;font-size:13px;">New {$form_label} submission</p>
		          </td>
		        </tr>
		        <tr>
		          <td style="padding:32px;">
		            <table width="100%" cellpadding="0" cellspacing="0">
		              {$rows}
		            </table>
		            <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">Submitted {$submitted}</p>
		          </td>
		        </tr>
		      </table>
		    </td></tr>
		  </table>
		</body>
		</html>
		HTML;
	}

	/**
	 * Replace {{field_name}} tokens in strings (useful in subject lines).
	 * e.g. 'Enquiry from {{name}}' → 'Enquiry from Jon'
	 */
	private function replace_tokens( string $string, array $fields ): string {
		return preg_replace_callback( '/\{\{(\w+)\}\}/', function ( $matches ) use ( $fields ) {
			$key = sanitize_key( $matches[1] );
			return isset( $fields[ $key ] ) ? esc_html( $fields[ $key ] ) : '';
		}, $string );
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	private function client_ip(): string {
		$keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
		foreach ( $keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				// X-Forwarded-For can be a comma-separated list; take the first
				return sanitize_text_field( trim( explode( ',', $_SERVER[ $key ] )[0] ) );
			}
		}
		return '';
	}

	// ── Registry ──────────────────────────────────────────────────────────────

	public static function get_registry(): array {
		return self::$registry;
	}
}

// ─── Admin UI ─────────────────────────────────────────────────────────────────

class SimpliForm_Admin {

	public static function init(): void {
		add_action( 'admin_menu',            [ self::class, 'register_menu' ] );
		add_action( 'admin_init',            [ self::class, 'handle_actions' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
	}

	public static function register_menu(): void {
		$unread = SimpliForm_DB::count_new();
		$bubble = $unread ? sprintf( ' <span class="awaiting-mod">%d</span>', $unread ) : '';

		add_menu_page(
			'Simpli Forms',
			'Simpli Forms' . $bubble,
			'manage_options',
			'simpliforms',
			[ self::class, 'render_page' ],
			'dashicons-email-alt2',
			25
		);
	}

	public static function enqueue_styles( string $hook ): void {
		if ( false === strpos( $hook, 'simpliforms' ) ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', self::admin_css() );
	}

	public static function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Delete a single submission
		if ( isset( $_GET['sf_delete'], $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'sf_delete' ) ) {
			SimpliForm_DB::delete( (int) $_GET['sf_delete'] );
			wp_redirect( remove_query_arg( [ 'sf_delete', '_wpnonce', 'sf_view' ] ) );
			exit;
		}

		// Bulk delete
		if ( isset( $_POST['sf_bulk_delete'], $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'sf_bulk' ) ) {
			$ids = array_map( 'intval', (array) ( $_POST['sf_ids'] ?? [] ) );
			foreach ( $ids as $id ) {
				SimpliForm_DB::delete( $id );
			}
			wp_redirect( remove_query_arg( [ 'sf_view' ] ) );
			exit;
		}
	}

	public static function render_page(): void {
		$form_id  = sanitize_key( $_GET['form_id'] ?? '' );
		$view_id  = (int) ( $_GET['sf_view'] ?? 0 );
		$page_num = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page = 20;
		$offset   = ( $page_num - 1 ) * $per_page;

		$form_ids    = SimpliForm_DB::get_form_ids();
		$total       = SimpliForm_DB::count( $form_id );
		$submissions = SimpliForm_DB::get_submissions( $form_id, $per_page, $offset );
		$total_pages = (int) ceil( $total / $per_page );

		// Viewing a single submission
		if ( $view_id ) {
			$single = SimpliForm_DB::get_submission( $view_id );
			if ( $single ) {
				SimpliForm_DB::update_status( $view_id, 'read' );
				self::render_single( $single );
				return;
			}
		}

		// ── Page wrapper ──────────────────────────────────────────────────────
		echo '<div class="wrap sf-admin">';
		echo '<h1 class="sf-heading">Simpli Forms</h1>';

		// ── Form filter tabs ──────────────────────────────────────────────────
		if ( $form_ids ) {
			echo '<div class="sf-tabs">';
			$all_count = SimpliForm_DB::count();
			$all_new   = SimpliForm_DB::count_new();
			$all_url   = admin_url( 'admin.php?page=simpliforms' );
			printf(
				'<a href="%s" class="sf-tab %s">All <span class="sf-count">%d</span>%s</a>',
				esc_url( $all_url ),
				! $form_id ? 'sf-tab--active' : '',
				$all_count,
				$all_new ? sprintf( ' <span class="sf-new-badge">%d new</span>', $all_new ) : ''
			);

			foreach ( $form_ids as $fid ) {
				$url     = add_query_arg( 'form_id', $fid, admin_url( 'admin.php?page=simpliforms' ) );
				$cnt     = SimpliForm_DB::count( $fid );
				$new_cnt = SimpliForm_DB::count_new( $fid );
				printf(
					'<a href="%s" class="sf-tab %s">%s <span class="sf-count">%d</span>%s</a>',
					esc_url( $url ),
					$form_id === $fid ? 'sf-tab--active' : '',
					esc_html( $fid ),
					$cnt,
					$new_cnt ? sprintf( ' <span class="sf-new-badge">%d new</span>', $new_cnt ) : ''
				);
			}
			echo '</div>';
		}

		// ── Empty state ───────────────────────────────────────────────────────
		if ( ! $submissions ) {
			echo '<div class="sf-empty"><span class="dashicons dashicons-email-alt2"></span><p>No submissions yet.</p></div>';
			echo '</div>';
			return;
		}

		// ── Submissions table ─────────────────────────────────────────────────
		$bulk_url = add_query_arg( 'form_id', $form_id, admin_url( 'admin.php?page=simpliforms' ) );
		echo '<form method="post" action="' . esc_url( $bulk_url ) . '">';
		wp_nonce_field( 'sf_bulk', '_wpnonce' );

		echo '<div class="sf-table-actions">';
		echo '<button type="submit" name="sf_bulk_delete" class="button button-secondary" onclick="return confirm(\'Delete selected submissions?\')">Delete selected</button>';
		echo '</div>';

		echo '<table class="wp-list-table widefat fixed sf-table">';
		echo '<thead><tr>';
		echo '<th class="check-column"><input type="checkbox" id="sf-check-all"></th>';
		echo '<th>ID</th><th>Form</th><th>Date</th><th>IP Address</th><th>Preview</th><th>Status</th><th>Actions</th>';
		echo '</tr></thead><tbody>';

		foreach ( $submissions as $row ) {
			$fields  = json_decode( $row['fields'], true ) ?? [];
			$is_new  = $row['status'] === 'new';

			// Build a short preview from the first 3 fields
			$preview_parts = [];
			$i = 0;
			foreach ( $fields as $k => $v ) {
				if ( $i++ >= 3 ) break;
				$val = is_array( $v ) ? implode( ', ', $v ) : $v;
				$preview_parts[] = '<strong>' . esc_html( ucfirst( $k ) ) . ':</strong> ' . esc_html( mb_substr( $val, 0, 60 ) ) . ( mb_strlen( $val ) > 60 ? '…' : '' );
			}

			$view_url   = add_query_arg( [ 'sf_view' => $row['id'], 'form_id' => $form_id ], admin_url( 'admin.php?page=simpliforms' ) );
			$delete_url = wp_nonce_url( add_query_arg( [ 'sf_delete' => $row['id'], 'form_id' => $form_id ], admin_url( 'admin.php?page=simpliforms' ) ), 'sf_delete' );

			echo '<tr class="' . ( $is_new ? 'sf-row-new' : '' ) . '">';
			echo '<td class="check-column"><input type="checkbox" name="sf_ids[]" value="' . (int) $row['id'] . '"></td>';
			echo '<td>' . (int) $row['id'] . '</td>';
			echo '<td><code>' . esc_html( $row['form_id'] ) . '</code></td>';
			echo '<td>' . esc_html( date_i18n( 'd M Y H:i', strtotime( $row['submitted_at'] ) ) ) . '</td>';
			echo '<td>' . esc_html( $row['ip_address'] ) . '</td>';
			echo '<td class="sf-preview">' . implode( ' &nbsp;·&nbsp; ', $preview_parts ) . '</td>';
			echo '<td><span class="sf-status sf-status--' . esc_attr( $row['status'] ) . '">' . esc_html( $row['status'] ) . '</span></td>';
			echo '<td class="sf-actions"><a href="' . esc_url( $view_url ) . '" class="button button-small">View</a> <a href="' . esc_url( $delete_url ) . '" class="button button-small sf-btn-delete" onclick="return confirm(\'Delete this submission?\')">Delete</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</form>';

		// ── Pagination ────────────────────────────────────────────────────────
		if ( $total_pages > 1 ) {
			echo '<div class="sf-pagination">';
			for ( $i = 1; $i <= $total_pages; $i++ ) {
				$url = add_query_arg( [ 'paged' => $i, 'form_id' => $form_id ], admin_url( 'admin.php?page=simpliforms' ) );
				printf(
					'<a href="%s" class="sf-page-btn %s">%d</a>',
					esc_url( $url ),
					$i === $page_num ? 'sf-page-btn--current' : '',
					$i
				);
			}
			echo '<span class="sf-page-info">Page ' . $page_num . ' of ' . $total_pages . ' &nbsp;·&nbsp; ' . $total . ' total</span>';
			echo '</div>';
		}

		// ── Bulk select JS ────────────────────────────────────────────────────
		echo '<script>document.getElementById("sf-check-all").addEventListener("change",function(){document.querySelectorAll("input[name=\'sf_ids[]\']").forEach(function(c){c.checked=document.getElementById("sf-check-all").checked});});</script>';

		echo '</div>';
	}

	private static function render_single( array $row ): void {
		$fields     = json_decode( $row['fields'], true ) ?? [];
		$back_url   = remove_query_arg( [ 'sf_view', 'paged' ] );
		$delete_url = wp_nonce_url( add_query_arg( 'sf_delete', $row['id'], $back_url ), 'sf_delete' );

		echo '<div class="wrap sf-admin sf-admin--single">';
		echo '<p class="sf-back"><a href="' . esc_url( $back_url ) . '">&larr; Back to submissions</a></p>';

		echo '<div class="sf-single-card">';
		echo '<div class="sf-single-header">';
		printf( '<h2>Submission #%d</h2><code class="sf-form-badge">%s</code>', (int) $row['id'], esc_html( $row['form_id'] ) );
		echo '</div>';

		echo '<div class="sf-meta-row">';
		printf( '<div class="sf-meta-item"><span class="sf-meta-label">Date</span><span>%s</span></div>', esc_html( date_i18n( 'd M Y \a\t H:i:s', strtotime( $row['submitted_at'] ) ) ) );
		printf( '<div class="sf-meta-item"><span class="sf-meta-label">IP</span><span>%s</span></div>', esc_html( $row['ip_address'] ) );
		printf( '<div class="sf-meta-item"><span class="sf-meta-label">Status</span><span class="sf-status sf-status--%s">%s</span></div>', esc_attr( $row['status'] ), esc_html( $row['status'] ) );
		echo '</div>';

		echo '<table class="sf-fields-table">';
		echo '<thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>';

		foreach ( $fields as $key => $value ) {
			$label = esc_html( ucwords( str_replace( [ '_', '-' ], ' ', $key ) ) );
			$val   = is_array( $value ) ? implode( ', ', $value ) : $value;
			echo '<tr><th>' . $label . '</th><td>' . nl2br( esc_html( $val ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<div class="sf-single-meta-row">';
		printf( '<p><strong>User Agent:</strong><br><span class="sf-ua">%s</span></p>', esc_html( $row['user_agent'] ) );
		echo '</div>';

		echo '<div class="sf-single-actions">';
		echo '<a href="' . esc_url( $delete_url ) . '" class="button button-secondary sf-btn-delete" onclick="return confirm(\'Permanently delete this submission?\')">Delete Submission</a>';
		echo '</div>';

		echo '</div></div>';
	}

	private static function admin_css(): string {
		return '
		/* ── Simpli Forms Admin ─────────────────────────────────────────────── */
		.sf-admin { max-width: 1200px; }
		.sf-heading { font-size: 22px; margin-bottom: 16px; }

		/* Tabs */
		.sf-tabs { display: flex; flex-wrap: wrap; gap: 4px; margin: 0 0 20px; }
		.sf-tab { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; background: #f0f0f1; border-radius: 4px; text-decoration: none; color: #2c3338; font-size: 13px; transition: background .15s; }
		.sf-tab:hover { background: #ddd; color: #2c3338; }
		.sf-tab--active { background: #2271b1; color: #fff !important; }
		.sf-count { opacity: .75; font-size: 11px; }
		.sf-new-badge { background: #d63638; color: #fff; font-size: 10px; padding: 1px 6px; border-radius: 8px; }

		/* Table */
		.sf-table-actions { margin-bottom: 8px; }
		.sf-table th, .sf-table td { vertical-align: middle; }
		.sf-row-new td { font-weight: 600; }
		.sf-preview { color: #555; font-size: 12px; max-width: 300px; }
		.sf-actions { white-space: nowrap; }
		.sf-btn-delete { color: #d63638 !important; border-color: #d63638 !important; }
		.sf-btn-delete:hover { background: #d63638 !important; color: #fff !important; }

		/* Status badges */
		.sf-status { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; }
		.sf-status--new { background: #dbeafe; color: #1d4ed8; }
		.sf-status--read { background: #f3f4f6; color: #6b7280; }

		/* Pagination */
		.sf-pagination { display: flex; align-items: center; gap: 4px; margin-top: 16px; flex-wrap: wrap; }
		.sf-page-btn { padding: 5px 12px; background: #f0f0f1; border-radius: 4px; text-decoration: none; color: #2c3338; font-size: 13px; }
		.sf-page-btn--current { background: #2271b1; color: #fff; }
		.sf-page-info { margin-left: 8px; font-size: 12px; color: #787c82; }

		/* Empty state */
		.sf-empty { text-align: center; padding: 60px 20px; color: #787c82; }
		.sf-empty .dashicons { font-size: 48px; width: 48px; height: 48px; margin-bottom: 12px; display: block; margin: 0 auto 12px; }

		/* Single view */
		.sf-back { margin-bottom: 16px; }
		.sf-single-card { background: #fff; border: 1px solid #ddd; border-radius: 6px; overflow: hidden; max-width: 860px; }
		.sf-single-header { display: flex; align-items: center; gap: 12px; padding: 20px 24px; border-bottom: 1px solid #eee; background: #fafafa; }
		.sf-single-header h2 { margin: 0; font-size: 18px; }
		.sf-form-badge { background: #1d1d1d; color: #fff; padding: 3px 10px; border-radius: 4px; font-size: 12px; }
		.sf-meta-row { display: flex; gap: 32px; padding: 14px 24px; border-bottom: 1px solid #eee; flex-wrap: wrap; }
		.sf-meta-item { display: flex; flex-direction: column; gap: 2px; font-size: 13px; }
		.sf-meta-label { font-weight: 600; color: #787c82; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
		.sf-fields-table { width: 100%; border-collapse: collapse; }
		.sf-fields-table th, .sf-fields-table td { padding: 12px 24px; border-bottom: 1px solid #f0f0f1; text-align: left; vertical-align: top; font-size: 14px; }
		.sf-fields-table th { background: #fafafa; width: 200px; font-weight: 600; color: #2c3338; white-space: nowrap; }
		.sf-fields-table tbody tr:last-child th, .sf-fields-table tbody tr:last-child td { border-bottom: none; }
		.sf-single-meta-row { padding: 14px 24px; border-top: 1px solid #eee; }
		.sf-ua { font-size: 12px; color: #787c82; word-break: break-all; }
		.sf-single-actions { padding: 16px 24px; border-top: 1px solid #eee; background: #fafafa; }
		';
	}
}

// ─── Bootstrap ────────────────────────────────────────────────────────────────

/**
 * DB install on plugin activation.
 * If using as a theme include (not a plugin), this hook won't fire —
 * the init hook below handles it instead.
 */
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, [ 'SimpliForm_DB', 'install' ] );
}

add_action( 'init', function () {
	// Create/update table if needed (idempotent)
	if ( get_option( SimpliForm_DB::OPTION ) !== SimpliForm_DB::VERSION ) {
		SimpliForm_DB::install();
	}
} );

// AJAX handlers — registered late so theme/plugin forms have time to register themselves
add_action( 'wp_ajax_simpliforms_submit',        [ 'SimpliForm', 'handle_ajax' ] );
add_action( 'wp_ajax_nopriv_simpliforms_submit', [ 'SimpliForm', 'handle_ajax' ] );

// Enqueue the lightweight frontend JS
add_action( 'wp_enqueue_scripts', function () {
	wp_register_script( 'simpliforms', false, [], '1.0.0', true );
	wp_enqueue_script( 'simpliforms' );
	wp_localize_script( 'simpliforms', 'SimpliForms', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
	] );
	wp_add_inline_script( 'simpliforms', simpliforms_frontend_js() );
} );

// Admin
SimpliForm_Admin::init();

// ─── Frontend JS ──────────────────────────────────────────────────────────────

function simpliforms_frontend_js(): string {
	return <<<'JS'
(function () {
    'use strict';

    var AJAX_URL = (typeof SimpliForms !== 'undefined') ? SimpliForms.ajaxUrl : '/wp-admin/admin-ajax.php';

    function init() {
        document.querySelectorAll('.simpliforms-wrapper form').forEach(function (form) {
            form.addEventListener('submit', handleSubmit);
        });
    }

    function handleSubmit(e) {
        e.preventDefault();

        var form      = e.currentTarget;
        var wrapper   = form.closest('.simpliforms-wrapper');
        var msgEl     = wrapper.querySelector('.simpliforms-response');
        var btn       = form.querySelector('[type="submit"]');
        var btnLabel  = btn ? btn.textContent : '';

        // Reset
        setMessage(msgEl, '', '');
        wrapper.classList.add('simpliforms-loading');
        if (btn) { btn.disabled = true; btn.textContent = 'Sending\u2026'; }

        var data = new FormData(form);
        data.append('action', 'simpliforms_submit');

        fetch(AJAX_URL, {
            method:      'POST',
            body:        data,
            credentials: 'same-origin',
        })
        .then(function (r) {
            // Always parse JSON — WordPress sends a JSON body even on 4xx responses
            return r.json().catch(function () {
                // Body wasn't JSON (e.g. a server 500 with an HTML error page)
                throw new Error('Server returned an unexpected response (HTTP ' + r.status + '). Check that the form is registered in an init hook.');
            });
        })
        .then(function (res) {
            if (res.success) {
                setMessage(msgEl, res.data.message, 'simpliforms-success');
                form.reset();
                // Dispatch event for custom handling
                wrapper.dispatchEvent(new CustomEvent('simpliforms:success', { bubbles: true, detail: res.data }));
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : 'An error occurred. Please try again.';
                setMessage(msgEl, msg, 'simpliforms-error');
                wrapper.dispatchEvent(new CustomEvent('simpliforms:error', { bubbles: true, detail: res.data }));
            }
        })
        .catch(function (err) {
            var msg = (err && err.message) ? err.message : 'Network error. Please check your connection and try again.';
            setMessage(msgEl, msg, 'simpliforms-error');
            console.error('[SimpliForms]', err);
        })
        .finally(function () {
            wrapper.classList.remove('simpliforms-loading');
            if (btn) { btn.disabled = false; btn.textContent = btnLabel; }
        });
    }

    function setMessage(el, text, cssClass) {
        el.textContent  = text;
        el.className    = 'simpliforms-response' + (cssClass ? ' ' + cssClass : '');
    }

    // Run on DOMContentLoaded or immediately if already ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;
}