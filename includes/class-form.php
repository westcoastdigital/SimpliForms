<?php
/**
 * Simpli Forms — Core Form Class
 *
 * Handles form registration, rendering, AJAX submission, spam protection,
 * field collection, email notifications, and the auto-response.
 *
 * ─── AVAILABLE FILTERS ────────────────────────────────────────────────────────
 *
 * simpliforms_form_config( array $config, string $form_id )
 *   Modify a form's merged config array before it is stored. Return the config.
 *
 * simpliforms_render( string $html, string $form_id )
 *   Filter the final rendered HTML before it is returned to the template.
 *
 * simpliforms_collected_fields( array $fields, string $form_id )
 *   Modify, add, or remove submitted fields after collection and sanitisation,
 *   but before validation, DB logging, and email sending.
 *
 * simpliforms_is_spam( bool $is_spam, array $fields, string $form_id )
 *   Inject custom spam checks. Return true to silently discard the submission.
 *
 * simpliforms_notification_subject( string $subject, string $form_id, array $fields )
 * simpliforms_notification_body( string $body, string $form_id, array $fields )
 * simpliforms_notification_headers( array $headers, string $form_id, array $fields )
 *   Modify any part of the outbound notification email.
 *
 * simpliforms_auto_response_subject( string $subject, string $form_id, array $fields )
 * simpliforms_auto_response_body( string $body, string $form_id, array $fields )
 * simpliforms_auto_response_headers( array $headers, string $form_id, array $fields )
 *   Modify any part of the outbound auto-response email.
 *
 * ─── AVAILABLE ACTIONS ────────────────────────────────────────────────────────
 *
 * simpliforms_form_registered( string $form_id, SimpliForm $instance )
 *   Fires immediately after a new SimpliForm instance is added to the registry.
 *
 * simpliforms_before_submit( string $form_id, array $raw_post )
 *   Fires after all spam checks pass, before field collection and processing.
 *   $raw_post is the raw $_POST array at that moment.
 *
 * simpliforms_submission_saved( int $submission_id, string $form_id, array $fields )
 *   Fires after the submission has been written to the database.
 *   $submission_id is 0 if logging is disabled for this form.
 *
 * simpliforms_notification_sent( string $form_id, array $fields )
 *   Fires after the notification email has been dispatched.
 *
 * simpliforms_auto_response_sent( string $form_id, array $fields )
 *   Fires after the auto-response email has been dispatched.
 *
 * simpliforms_after_submit( string $form_id, array $fields, int $submission_id )
 *   Fires at the very end of a successful submission, after all callbacks and emails.
 *
 * @package SimpliForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SimpliForm {

	private string $id;
	private array  $config;

	/** All instantiated forms, keyed by form ID. */
	private static array $registry = [];

	// ── Constructor ───────────────────────────────────────────────────────────

	/**
	 * @param string $form_id  Unique slug for this form, e.g. 'contact', 'quote-request'.
	 * @param array  $config   Configuration array (see file header for full reference).
	 */
	public function __construct( string $form_id, array $config = [] ) {
		$this->id = sanitize_key( $form_id );

		// ── Merge defaults ─────────────────────────────────────────────────────
		$this->config = wp_parse_args( $config, [
			'template'        => '',
			'email'           => [],
			'auto_response'   => [],
			'spam'            => [],
			'log'             => true,
			/* translators: Form success message shown to the user after submission. */
			'success_message' => __( 'Thank you! Your message has been sent.', 'simpliforms' ),
			/* translators: Form error message shown to the user after a failed submission. */
			'error_message'   => __( 'Something went wrong. Please try again.', 'simpliforms' ),
			'before_submit'   => null,  // callable( array $fields ): true|WP_Error
			'after_submit'    => null,  // callable( array $fields, int $submission_id ): void
		] );

		$this->config['email'] = wp_parse_args( $this->config['email'], [
			'to'             => get_option( 'admin_email' ),
			/* translators: %s = form ID slug */
			'subject'        => sprintf( __( 'New submission: %s', 'simpliforms' ), $this->id ),
			'template'       => '',
			'reply_to_field' => 'email',
			'from_name'      => get_bloginfo( 'name' ),
			'from_email'     => get_option( 'admin_email' ),
		] );

		$this->config['auto_response'] = wp_parse_args( $this->config['auto_response'], [
			'enabled'  => false,
			'to_field' => 'email',
			/* translators: Auto-response email subject default. */
			'subject'  => __( 'Thanks for getting in touch', 'simpliforms' ),
			'template' => '',
		] );

		$this->config['spam'] = wp_parse_args( $this->config['spam'], [
			'honeypot'   => true,
			'nonce'      => true,
			'rate_limit' => 5,  // max per hour per IP; 0 = disabled
		] );

		/**
		 * Filter: simpliforms_form_config
		 *
		 * Modify a form's fully-merged config array before it is stored.
		 * Runs once per form registration, at instantiation time.
		 *
		 * @param array  $config  Merged config array.
		 * @param string $form_id Form slug.
		 */
		$this->config = apply_filters( 'simpliforms_form_config', $this->config, $this->id );

		self::$registry[ $this->id ] = $this;

		/**
		 * Action: simpliforms_form_registered
		 *
		 * Fires after the form is added to the registry.
		 *
		 * @param string     $form_id  Form slug.
		 * @param SimpliForm $instance The new instance.
		 */
		do_action( 'simpliforms_form_registered', $this->id, $this );
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Render the form HTML. Echo or return.
	 */
	public function render(): string {
		if ( empty( $this->config['template'] ) || ! file_exists( $this->config['template'] ) ) {
			return sprintf(
				'<p style="color:red;"><strong>SimpliForm:</strong> %s</p>',
				sprintf(
					/* translators: %s = form ID */
					esc_html__( 'Template not found for form: %s', 'simpliforms' ),
					esc_html( $this->id )
				)
			);
		}

		$html = file_get_contents( $this->config['template'] );
		$html = $this->inject_hidden_fields( $html );

		$output = sprintf(
			'<div class="simpliforms-wrapper" id="simpliforms-%1$s" data-form-id="%1$s">%2$s<div class="simpliforms-response" role="alert" aria-live="polite"></div></div>',
			esc_attr( $this->id ),
			$html
		);

		/**
		 * Filter: simpliforms_render
		 *
		 * Filter the final rendered HTML of the form wrapper before it is returned.
		 *
		 * @param string $output  The full wrapper HTML.
		 * @param string $form_id The form slug.
		 */
		return apply_filters( 'simpliforms_render', $output, $this->id );
	}

	// ── Injection ─────────────────────────────────────────────────────────────

	/**
	 * Inject required hidden fields into the HTML template's <form> tag.
	 */
	private function inject_hidden_fields( string $html ): string {
		$hidden = '';

		// Form routing.
		$hidden .= sprintf( '<input type="hidden" name="simpliforms_action" value="%s">', esc_attr( $this->id ) );

		// WordPress nonce.
		if ( $this->config['spam']['nonce'] ) {
			$hidden .= wp_nonce_field( 'simpliforms_submit_' . $this->id, 'simpliforms_nonce', true, false );
		}

		// Honeypot — visually hidden, tab-skipped, autocomplete off.
		if ( $this->config['spam']['honeypot'] ) {
			$hp_name = 'sf_hp_' . $this->id;
			$hidden .= sprintf(
				'<div style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">' .
					'<label for="%1$s">%2$s</label>' .
					'<input type="text" id="%1$s" name="%1$s" value="" tabindex="-1" autocomplete="off">' .
				'</div>',
				esc_attr( $hp_name ),
				esc_html__( 'Leave this field blank', 'simpliforms' )
			);
		}

		// Insert hidden fields just before </form>.
		return preg_replace( '/<\/form\s*>/i', $hidden . '</form>', $html, 1 );
	}

	// ── AJAX Dispatch ─────────────────────────────────────────────────────────

	public static function handle_ajax(): void {
		$form_id = sanitize_key( $_POST['simpliforms_action'] ?? '' );

		if ( ! $form_id || ! isset( self::$registry[ $form_id ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid form identifier.', 'simpliforms' ) ], 400 );
		}

		self::$registry[ $form_id ]->process();
	}

	// ── Process Submission ────────────────────────────────────────────────────

	private function process(): void {

		// ── 1. Nonce ────────────────────────────────────────────────────────────
		if ( $this->config['spam']['nonce'] ) {
			if ( ! check_ajax_referer( 'simpliforms_submit_' . $this->id, 'simpliforms_nonce', false ) ) {
				wp_send_json_error(
					[ 'message' => __( 'Security check failed. Please refresh the page and try again.', 'simpliforms' ) ],
					403
				);
			}
		}

		// ── 2. Honeypot ─────────────────────────────────────────────────────────
		if ( $this->config['spam']['honeypot'] ) {
			$hp_name = 'sf_hp_' . $this->id;
			if ( ! empty( $_POST[ $hp_name ] ) ) {
				// Silently succeed — fool the bot, don't reveal the check.
				wp_send_json_success( [ 'message' => $this->config['success_message'] ] );
			}
		}

		// ── 3. Rate Limiting ────────────────────────────────────────────────────
		if ( (int) $this->config['spam']['rate_limit'] > 0 ) {
			$ip  = $this->client_ip();
			$key = 'sf_rl_' . $this->id . '_' . md5( $ip );
			$hit = (int) get_transient( $key );

			if ( $hit >= (int) $this->config['spam']['rate_limit'] ) {
				wp_send_json_error(
					[ 'message' => __( 'Too many submissions. Please try again later.', 'simpliforms' ) ],
					429
				);
			}

			set_transient( $key, $hit + 1, HOUR_IN_SECONDS );
		}

		/**
		 * Action: simpliforms_before_submit
		 *
		 * Fires after all built-in spam checks pass, before field collection.
		 * You can call wp_send_json_error() here to halt processing.
		 *
		 * @param string $form_id  The form slug.
		 * @param array  $raw_post The raw $_POST at this point.
		 */
		do_action( 'simpliforms_before_submit', $this->id, $_POST );

		// ── 4. Collect & Sanitise Fields ────────────────────────────────────────
		$fields = $this->collect_fields();

		/**
		 * Filter: simpliforms_collected_fields
		 *
		 * Modify, add, or remove submitted fields after sanitisation but before
		 * validation, DB logging, and email dispatch.
		 *
		 * @param array  $fields  Sanitised field values keyed by field name.
		 * @param string $form_id The form slug.
		 */
		$fields = apply_filters( 'simpliforms_collected_fields', $fields, $this->id );

		/**
		 * Filter: simpliforms_is_spam
		 *
		 * Inject a custom spam check. Return true to silently discard the submission
		 * (the submitter receives a fake success response).
		 *
		 * @param bool   $is_spam Initially false.
		 * @param array  $fields  Sanitised fields.
		 * @param string $form_id The form slug.
		 */
		if ( apply_filters( 'simpliforms_is_spam', false, $fields, $this->id ) ) {
			wp_send_json_success( [ 'message' => $this->config['success_message'] ] );
		}

		// ── 5. Custom Validation ────────────────────────────────────────────────
		if ( is_callable( $this->config['before_submit'] ) ) {
			$result = call_user_func( $this->config['before_submit'], $fields );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( [ 'message' => $result->get_error_message() ], 422 );
			}
		}

		// ── 6. Log to DB ────────────────────────────────────────────────────────
		$submission_id = 0;
		if ( $this->config['log'] ) {
			$submission_id = SimpliForm_DB::insert(
				$this->id,
				$fields,
				$this->client_ip(),
				sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' )
			);
		}

		/**
		 * Action: simpliforms_submission_saved
		 *
		 * Fires after the submission has been written to the database.
		 *
		 * @param int    $submission_id Row ID, or 0 if logging is disabled.
		 * @param string $form_id       Form slug.
		 * @param array  $fields        Sanitised field values.
		 */
		do_action( 'simpliforms_submission_saved', (int) $submission_id, $this->id, $fields );

		// ── 7. Send Notification Email ──────────────────────────────────────────
		$this->send_notification( $fields );

		// ── 8. Send Auto-Response ───────────────────────────────────────────────
		if ( $this->config['auto_response']['enabled'] ) {
			$this->send_auto_response( $fields );
		}

		// ── 9. after_submit Callback ────────────────────────────────────────────
		if ( is_callable( $this->config['after_submit'] ) ) {
			call_user_func( $this->config['after_submit'], $fields, (int) $submission_id );
		}

		/**
		 * Action: simpliforms_after_submit
		 *
		 * Fires at the very end of a successful submission, after emails and callbacks.
		 * Useful for third-party integrations (CRMs, CPT creation, etc.) when you
		 * don't want to modify the form config directly.
		 *
		 * @param string $form_id       Form slug.
		 * @param array  $fields        Sanitised field values.
		 * @param int    $submission_id DB row ID, or 0 if logging is disabled.
		 */
		do_action( 'simpliforms_after_submit', $this->id, $fields, (int) $submission_id );

		wp_send_json_success( [ 'message' => $this->config['success_message'] ] );
	}

	// ── Field Collection ──────────────────────────────────────────────────────

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
			// Drop any other honeypot-style keys.
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

	// ── Email Helpers ─────────────────────────────────────────────────────────

	private function send_notification( array $fields ): void {
		$cfg     = $this->config['email'];
		$subject = $this->replace_tokens( $cfg['subject'], $fields );
		$body    = $this->build_email( $cfg['template'], $fields, 'notification' );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $cfg['from_name'], $cfg['from_email'] ),
		];

		// Use the submitter's email as Reply-To if available.
		$reply_field = $cfg['reply_to_field'];
		if ( ! empty( $fields[ $reply_field ] ) && is_email( $fields[ $reply_field ] ) ) {
			$reply_name = $fields['name'] ?? $fields['first_name'] ?? '';
			$headers[]  = sprintf( 'Reply-To: %s <%s>', $reply_name, $fields[ $reply_field ] );
		}

		/**
		 * Filter: simpliforms_notification_subject
		 *
		 * @param string $subject  Resolved email subject (tokens already replaced).
		 * @param string $form_id  Form slug.
		 * @param array  $fields   Sanitised field values.
		 */
		$subject = apply_filters( 'simpliforms_notification_subject', $subject, $this->id, $fields );

		/**
		 * Filter: simpliforms_notification_body
		 *
		 * @param string $body    Rendered email body (HTML).
		 * @param string $form_id Form slug.
		 * @param array  $fields  Sanitised field values.
		 */
		$body = apply_filters( 'simpliforms_notification_body', $body, $this->id, $fields );

		/**
		 * Filter: simpliforms_notification_headers
		 *
		 * @param array  $headers List of email header strings.
		 * @param string $form_id Form slug.
		 * @param array  $fields  Sanitised field values.
		 */
		$headers = apply_filters( 'simpliforms_notification_headers', $headers, $this->id, $fields );

		wp_mail( $cfg['to'], $subject, $body, $headers );

		/**
		 * Action: simpliforms_notification_sent
		 *
		 * Fires after the notification email has been dispatched via wp_mail().
		 *
		 * @param string $form_id Form slug.
		 * @param array  $fields  Sanitised field values.
		 */
		do_action( 'simpliforms_notification_sent', $this->id, $fields );
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

		/**
		 * Filter: simpliforms_auto_response_subject
		 *
		 * @param string $subject  Resolved email subject (tokens already replaced).
		 * @param string $form_id  Form slug.
		 * @param array  $fields   Sanitised field values.
		 */
		$subject = apply_filters( 'simpliforms_auto_response_subject', $subject, $this->id, $fields );

		/**
		 * Filter: simpliforms_auto_response_body
		 *
		 * @param string $body    Rendered email body (HTML).
		 * @param string $form_id Form slug.
		 * @param array  $fields  Sanitised field values.
		 */
		$body = apply_filters( 'simpliforms_auto_response_body', $body, $this->id, $fields );

		/**
		 * Filter: simpliforms_auto_response_headers
		 *
		 * @param array  $headers List of email header strings.
		 * @param string $form_id Form slug.
		 * @param array  $fields  Sanitised field values.
		 */
		$headers = apply_filters( 'simpliforms_auto_response_headers', $headers, $this->id, $fields );

		wp_mail( $fields[ $to_field ], $subject, $body, $headers );

		/**
		 * Action: simpliforms_auto_response_sent
		 *
		 * Fires after the auto-response email has been dispatched via wp_mail().
		 *
		 * @param string $form_id Form slug.
		 * @param array  $fields  Sanitised field values.
		 */
		do_action( 'simpliforms_auto_response_sent', $this->id, $fields );
	}

	/**
	 * Build email body from a PHP template file, inline HTML, or the default table fallback.
	 *
	 * Inside your PHP template you have access to:
	 *   $fields      — full associative array of submitted values
	 *   $form_fields — alias of $fields, useful when looping alongside extracted vars
	 *   $form_id     — the form's slug
	 *   $form_label  — prettified form label
	 *   Each field is also extracted as its own variable (esc_html'd): echo $name; echo $email;
	 */
	private function build_email( string $template_path, array $fields, string $type ): string {

		// ── PHP file template ────────────────────────────────────────────────────
		if ( $template_path && file_exists( $template_path ) ) {
			ob_start();
			$form_id     = $this->id;
			$form_label  = ucwords( str_replace( [ '-', '_' ], ' ', $this->id ) );
			extract( array_map( 'esc_html', $fields ), EXTR_SKIP );
			$form_fields = $fields;
			include $template_path;
			return ob_get_clean();
		}

		// ── Inline HTML from ACF WYSIWYG editor ─────────────────────────────────
		$cfg_key     = $type === 'notification' ? 'email' : 'auto_response';
		$inline_html = $this->config[ $cfg_key ]['inline_html'] ?? '';

		if ( $inline_html ) {
			return $this->replace_tokens( $inline_html, $fields );
		}

		// ── Default fallback: clean HTML table ───────────────────────────────────
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

		$site_name  = esc_html( get_bloginfo( 'name' ) );
		$form_label = ucwords( str_replace( [ '-', '_' ], ' ', $this->id ) );
		$submitted  = esc_html( current_time( 'd M Y \a\t H:i' ) );

		/* translators: %s = prettified form name, e.g. "Contact" */
		$heading_sub = sprintf( __( 'New %s submission', 'simpliforms' ), $form_label );
		/* translators: %s = formatted date/time */
		$submitted_label = sprintf( __( 'Submitted %s', 'simpliforms' ), $submitted );

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
		            <p style="margin:4px 0 0;color:#a3a3a3;font-size:13px;">{$heading_sub}</p>
		          </td>
		        </tr>
		        <tr>
		          <td style="padding:32px;">
		            <table width="100%" cellpadding="0" cellspacing="0">
		              {$rows}
		            </table>
		            <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">{$submitted_label}</p>
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
				// X-Forwarded-For can be a comma-separated list; take the first.
				return sanitize_text_field( trim( explode( ',', $_SERVER[ $key ] )[0] ) );
			}
		}
		return '';
	}

	// ── Registry ──────────────────────────────────────────────────────────────

	/**
	 * Return all registered SimpliForm instances, keyed by form ID.
	 *
	 * @return SimpliForm[]
	 */
	public static function get_registry(): array {
		return self::$registry;
	}
}