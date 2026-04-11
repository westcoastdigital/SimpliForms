<?php
/**
 * Simpli Forms — ACF Field Type
 * ─────────────────────────────
 * Registers a custom ACF field type that lets you configure and attach a
 * SimpliForm to any post, page, or options page from the WordPress backend.
 *
 * Requires: ACF Pro 6+, simpliforms.php
 *
 * ─── SETUP ───────────────────────────────────────────────────────────────────
 *
 * 1. Require both files in functions.php:
 *       require_once get_template_directory() . '/inc/simpliforms.php';
 *       require_once get_template_directory() . '/inc/simpliforms-acf.php';
 *
 * 2. Add a "Simpli Form" field to any field group via the ACF field group editor.
 *    In the field settings configure:
 *       - Forms directory   (relative to theme root, e.g. forms)
 *       - Emails directory  (relative to theme root, e.g. forms/emails)
 *
 * 3. Add this to functions.php to auto-register the form from the field value:
 *
 *       add_action( 'init', function () {
 *           simpliforms_acf_autoregister();
 *       } );
 *
 *    For per-page forms this scans all published pages/posts. For an options
 *    page field, pass the post ID or options key directly (see below).
 *
 * ─── MANUAL REGISTRATION ─────────────────────────────────────────────────────
 *
 * If you prefer explicit control:
 *
 *       add_action( 'init', function () {
 *           $value = get_field( 'contact_form', 'options' );  // or a post ID
 *           simpliforms_register_from_acf( $value );
 *       } );
 *
 * ─── RENDERING ───────────────────────────────────────────────────────────────
 *
 * In your template, retrieve and render by the form ID you configured:
 *
 *       echo $GLOBALS['simpliforms'][ get_field('contact_form')['form_id'] ]->render();
 *
 * Or if you know the form ID:
 *
 *       echo $GLOBALS['simpliforms']['contact']->render();
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Registration helpers ─────────────────────────────────────────────────────

/**
 * Build a SimpliForm config array from an ACF field value and register it.
 * Safe to call multiple times — skips if the form ID is already registered.
 *
 * @param array $value  The raw ACF field value.
 * @return SimpliForm|null
 */
function simpliforms_register_from_acf( array $value ): ?SimpliForm {
	if ( ! class_exists( 'SimpliForm' ) ) {
		return null;
	}

	$form_id = sanitize_key( $value['form_id'] ?? '' );

	if ( ! $form_id ) {
		return null;
	}

	// Already registered — don't double-up
	$registry = SimpliForm::get_registry();
	if ( isset( $registry[ $form_id ] ) ) {
		return $registry[ $form_id ];
	}

	$theme_dir = get_template_directory();

	// ── Resolve form HTML template ────────────────────────────────────────────
	$forms_dir    = trailingslashit( $theme_dir ) . trim( $value['forms_dir'] ?? 'forms', '/' );
	$template     = $value['template_file'] ? trailingslashit( $forms_dir ) . $value['template_file'] : '';

	// ── Resolve email templates / inline HTML ─────────────────────────────────
	$emails_dir   = trailingslashit( $theme_dir ) . trim( $value['emails_dir'] ?? 'forms/emails', '/' );

	$email_cfg = [
		'to'             => sanitize_email( $value['email']['to'] ?? get_option( 'admin_email' ) ),
		'subject'        => sanitize_text_field( $value['email']['subject'] ?? 'New submission' ),
		'reply_to_field' => sanitize_key( $value['email']['reply_to_field'] ?? 'email' ),
		'from_name'      => sanitize_text_field( $value['email']['from_name'] ?? get_bloginfo( 'name' ) ),
		'from_email'     => sanitize_email( $value['email']['from_email'] ?? get_option( 'admin_email' ) ),
	];

	if ( ( $value['email']['template_mode'] ?? 'default' ) === 'file' && ! empty( $value['email']['template_file'] ) ) {
		$email_cfg['template'] = trailingslashit( $emails_dir ) . $value['email']['template_file'];
	} elseif ( ( $value['email']['template_mode'] ?? '' ) === 'wysiwyg' && ! empty( $value['email']['wysiwyg'] ) ) {
		$email_cfg['inline_html'] = wp_kses_post( $value['email']['wysiwyg'] );
	}

	$auto_cfg = [
		'enabled'  => ! empty( $value['auto_response']['enabled'] ),
		'to_field' => sanitize_key( $value['auto_response']['to_field'] ?? 'email' ),
		'subject'  => sanitize_text_field( $value['auto_response']['subject'] ?? 'Thanks for getting in touch' ),
	];

	if ( ( $value['auto_response']['template_mode'] ?? 'default' ) === 'file' && ! empty( $value['auto_response']['template_file'] ) ) {
		$auto_cfg['template'] = trailingslashit( $emails_dir ) . $value['auto_response']['template_file'];
	} elseif ( ( $value['auto_response']['template_mode'] ?? '' ) === 'wysiwyg' && ! empty( $value['auto_response']['wysiwyg'] ) ) {
		$auto_cfg['inline_html'] = wp_kses_post( $value['auto_response']['wysiwyg'] );
	}

	$config = [
		'template'        => $template,
		'log'             => ! empty( $value['log'] ),
		'success_message' => sanitize_text_field( $value['success_message'] ?? 'Thank you! Your message has been sent.' ),
		'error_message'   => sanitize_text_field( $value['error_message'] ?? 'Something went wrong. Please try again.' ),
		'email'           => $email_cfg,
		'auto_response'   => $auto_cfg,
		'spam'            => [
			'honeypot'   => ! empty( $value['spam']['honeypot'] ),
			'nonce'      => ! empty( $value['spam']['nonce'] ),
			'rate_limit' => (int) ( $value['spam']['rate_limit'] ?? 5 ),
		],
	];

	$form = new SimpliForm( $form_id, $config );
	$GLOBALS['simpliforms'][ $form_id ] = $form;

	return $form;
}

/**
 * Auto-scan all published pages and posts for SimpliForm ACF fields and register them.
 * Call this inside add_action('init', ...) in functions.php.
 *
 * @param string[] $post_types  Post types to scan. Default: page, post.
 */
function simpliforms_acf_autoregister( array $post_types = [ 'page', 'post' ] ): void {
	if ( ! function_exists( 'get_fields' ) ) {
		return;
	}

	$posts = get_posts( [
		'post_type'      => $post_types,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	foreach ( $posts as $post_id ) {
		$fields = get_fields( $post_id );
		if ( ! is_array( $fields ) ) {
			continue;
		}

		foreach ( $fields as $value ) {
			if ( is_array( $value ) && ! empty( $value['_simpliforms_field'] ) ) {
				simpliforms_register_from_acf( $value );
			}
		}
	}
}

// ─── ACF Field Type ───────────────────────────────────────────────────────────

add_action( 'acf/include_field_types', function () {
	if ( ! class_exists( 'acf_field' ) ) {
		return;
	}

	class acf_field_simpliforms extends acf_field {

		public function initialize(): void {
			$this->name     = 'simpliforms';
			$this->label    = 'Simpli Form';
			$this->category = 'content';
			$this->icon     = 'dashicons-email-alt2';
			$this->defaults = [
				'forms_dir'       => 'forms',
				'emails_dir'      => 'forms/emails',
				'form_id'         => '',
				'template_file'   => '',
				'log'             => 1,
				'success_message' => 'Thank you! Your message has been sent.',
				'error_message'   => 'Something went wrong. Please try again.',
				'email'           => [
					'to'             => '',
					'subject'        => 'New enquiry from {{name}}',
					'reply_to_field' => 'email',
					'from_name'      => '',
					'from_email'     => '',
					'template_mode'  => 'default',
					'template_file'  => '',
					'wysiwyg'        => '',
				],
				'auto_response'   => [
					'enabled'       => 0,
					'to_field'      => 'email',
					'subject'       => 'Thanks for getting in touch!',
					'template_mode' => 'default',
					'template_file' => '',
					'wysiwyg'       => '',
				],
				'spam'            => [
					'honeypot'   => 1,
					'nonce'      => 1,
					'rate_limit' => 5,
				],
			];
		}

		// ── Field group editor: settings ──────────────────────────────────────

		public function render_field_settings( $field ): void {
			// Forms directory
			acf_render_field_setting( $field, [
				'label'        => 'Forms Directory',
				'instructions' => 'Path to HTML form templates, relative to your theme root. e.g. <code>forms</code>',
				'type'         => 'text',
				'name'         => 'forms_dir',
			] );

			// Emails directory
			acf_render_field_setting( $field, [
				'label'        => 'Emails Directory',
				'instructions' => 'Path to PHP email templates, relative to your theme root. e.g. <code>forms/emails</code>',
				'type'         => 'text',
				'name'         => 'emails_dir',
			] );
		}

		// ── Post edit screen: field UI ────────────────────────────────────────

		public function render_field( $field ): void {
			$v          = $field['value'] ?: [];
			$key        = $field['key'];
			$name       = $field['name'];
			$forms_dir  = trailingslashit( get_template_directory() ) . trim( $field['forms_dir'] ?? 'forms', '/' );
			$emails_dir = trailingslashit( get_template_directory() ) . trim( $field['emails_dir'] ?? 'forms/emails', '/' );

			// Scan directories
			$form_files  = $this->scan_dir( $forms_dir, 'html' );
			$email_files = $this->scan_dir( $emails_dir, 'php' );

			// Shorthand for current values
			$val = function ( ...$keys ) use ( $v ) {
				$current = $v;
				foreach ( $keys as $k ) {
					if ( ! is_array( $current ) || ! isset( $current[ $k ] ) ) return '';
					$current = $current[ $k ];
				}
				return $current;
			};

			$checked = function ( ...$keys ) use ( $v, $val ) {
				return $val( ...$keys ) ? 'checked' : '';
			};

			$input_name = fn( string $path ) => "acf[{$key}]{$path}";

			// A hidden marker so auto-register can identify this field type
			echo '<input type="hidden" name="' . esc_attr( $input_name( '[_simpliforms_field]' ) ) . '" value="1">';

			// Pass directory paths through so register_from_acf can use them
			echo '<input type="hidden" name="' . esc_attr( $input_name( '[forms_dir]' ) ) . '" value="' . esc_attr( $field['forms_dir'] ?? 'forms' ) . '">';
			echo '<input type="hidden" name="' . esc_attr( $input_name( '[emails_dir]' ) ) . '" value="' . esc_attr( $field['emails_dir'] ?? 'forms/emails' ) . '">';

			?>
			<div class="sf-acf-wrap" data-key="<?php echo esc_attr( $key ); ?>">

				<?php $this->render_css(); ?>

				<!-- ── Section: General ──────────────────────────────────── -->
				<div class="sf-acf-section">
					<div class="sf-acf-section-header">
						<span class="dashicons dashicons-admin-settings"></span> General
					</div>
					<div class="sf-acf-section-body">

						<div class="sf-acf-row sf-acf-row--half">

							<!-- Form Template -->
							<div class="sf-acf-field">
								<label>Form Template <span class="sf-acf-required">*</span></label>
								<p class="sf-acf-desc">HTML file from your forms directory.</p>
								<?php if ( $form_files ) : ?>
									<select name="<?php echo esc_attr( $input_name( '[template_file]' ) ); ?>"
									        class="sf-acf-select sf-acf-form-template-select">
										<option value="">— Select a template —</option>
										<?php foreach ( $form_files as $file ) : ?>
											<option value="<?php echo esc_attr( $file ); ?>"
											        <?php selected( $val( 'template_file' ), $file ); ?>>
												<?php echo esc_html( $file ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<p class="sf-acf-notice">No <code>.html</code> files found in
										<code><?php echo esc_html( $forms_dir ); ?></code>.</p>
								<?php endif; ?>
							</div>

							<!-- Form ID -->
							<div class="sf-acf-field">
								<label>Form ID <span class="sf-acf-required">*</span></label>
								<p class="sf-acf-desc">Unique slug used for routing and logging. Auto-filled from template name.</p>
								<input type="text"
								       class="sf-acf-input sf-acf-form-id-input"
								       name="<?php echo esc_attr( $input_name( '[form_id]' ) ); ?>"
								       value="<?php echo esc_attr( $val( 'form_id' ) ); ?>"
								       placeholder="e.g. contact">
							</div>

						</div>

						<div class="sf-acf-row sf-acf-row--half">

							<!-- Success Message -->
							<div class="sf-acf-field">
								<label>Success Message</label>
								<input type="text"
								       class="sf-acf-input"
								       name="<?php echo esc_attr( $input_name( '[success_message]' ) ); ?>"
								       value="<?php echo esc_attr( $val( 'success_message' ) ?: 'Thank you! Your message has been sent.' ); ?>">
							</div>

							<!-- Error Message -->
							<div class="sf-acf-field">
								<label>Error Message</label>
								<input type="text"
								       class="sf-acf-input"
								       name="<?php echo esc_attr( $input_name( '[error_message]' ) ); ?>"
								       value="<?php echo esc_attr( $val( 'error_message' ) ?: 'Something went wrong. Please try again.' ); ?>">
							</div>

						</div>

						<!-- Log Submissions -->
						<div class="sf-acf-field sf-acf-field--inline">
							<label class="sf-acf-toggle">
								<input type="hidden"
								       name="<?php echo esc_attr( $input_name( '[log]' ) ); ?>"
								       value="0">
								<input type="checkbox"
								       name="<?php echo esc_attr( $input_name( '[log]' ) ); ?>"
								       value="1"
								       <?php echo $checked( 'log' ) ?: 'checked'; ?>>
								<span>Log submissions to the database</span>
							</label>
						</div>

					</div>
				</div>

				<!-- ── Section: Notification Email ──────────────────────── -->
				<div class="sf-acf-section">
					<div class="sf-acf-section-header">
						<span class="dashicons dashicons-email-alt"></span> Notification Email
						<span class="sf-acf-section-sub">Sent to you on each submission</span>
					</div>
					<div class="sf-acf-section-body">

						<div class="sf-acf-row sf-acf-row--half">
							<div class="sf-acf-field">
								<label>To</label>
								<input type="email"
								       class="sf-acf-input"
								       name="<?php echo esc_attr( $input_name( '[email][to]' ) ); ?>"
								       value="<?php echo esc_attr( $val( 'email', 'to' ) ?: get_option( 'admin_email' ) ); ?>"
								       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							</div>
							<div class="sf-acf-field">
								<label>Subject</label>
								<p class="sf-acf-desc">Supports <code>{{field_name}}</code> tokens.</p>
								<input type="text"
								       class="sf-acf-input"
								       name="<?php echo esc_attr( $input_name( '[email][subject]' ) ); ?>"
								       value="<?php echo esc_attr( $val( 'email', 'subject' ) ?: 'New enquiry from {{name}}' ); ?>">
							</div>
						</div>

						<div class="sf-acf-row sf-acf-row--third">
							<div class="sf-acf-field">
								<label>From Name</label>
								<input type="text"
								       class="sf-acf-input"
								       name="<?php echo esc_attr( $input_name( '[email][from_name]' ) ); ?>"
								       value="<?php echo esc_attr( $val( 'email', 'from_name' ) ?: get_bloginfo( 'name' ) ); ?>"
								       placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
							</div>
							<div class="sf-acf-field">
								<label>From Email</label>
								<input type="email"
								       class="sf-acf-input"
								       name="<?php echo esc_attr( $input_name( '[email][from_email]' ) ); ?>"
								       value="<?php echo esc_attr( $val( 'email', 'from_email' ) ?: get_option( 'admin_email' ) ); ?>"
								       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							</div>
							<div class="sf-acf-field">
								<label>Reply-To Field</label>
								<p class="sf-acf-desc">Field <code>name</code> attribute that holds the submitter's email.</p>
								<input type="text"
								       class="sf-acf-input"
								       name="<?php echo esc_attr( $input_name( '[email][reply_to_field]' ) ); ?>"
								       value="<?php echo esc_attr( $val( 'email', 'reply_to_field' ) ?: 'email' ); ?>"
								       placeholder="email">
							</div>
						</div>

						<!-- Template mode -->
						<?php $this->render_template_section(
							'email',
							$val( 'email', 'template_mode' ),
							$val( 'email', 'template_file' ),
							$val( 'email', 'wysiwyg' ),
							$email_files,
							$input_name,
							$key . '_email_wysiwyg'
						); ?>

					</div>
				</div>

				<!-- ── Section: Auto-Response ────────────────────────────── -->
				<div class="sf-acf-section">
					<div class="sf-acf-section-header">
						<span class="dashicons dashicons-redo"></span> Auto-Response
						<span class="sf-acf-section-sub">Sent to the person who submitted</span>

						<!-- Enable toggle in the header -->
						<label class="sf-acf-toggle sf-acf-toggle--header sf-acf-autoresponse-toggle">
							<input type="hidden"
							       name="<?php echo esc_attr( $input_name( '[auto_response][enabled]' ) ); ?>"
							       value="0">
							<input type="checkbox"
							       name="<?php echo esc_attr( $input_name( '[auto_response][enabled]' ) ); ?>"
							       class="sf-acf-autoresponse-checkbox"
							       value="1"
							       <?php echo $checked( 'auto_response', 'enabled' ); ?>>
							<span>Enable</span>
						</label>
					</div>
					<div class="sf-acf-section-body sf-acf-autoresponse-body"
					     style="<?php echo ! $val( 'auto_response', 'enabled' ) ? 'display:none;' : ''; ?>">

						<div class="sf-acf-row sf-acf-row--third">
							<div class="sf-acf-field">
								<label>Recipient Field</label>
								<p class="sf-acf-desc">Field <code>name</code> that holds the submitter's email address.</p>
								<input type="text"
								       class="sf-acf-input"
								       name="<?php echo esc_attr( $input_name( '[auto_response][to_field]' ) ); ?>"
								       value="<?php echo esc_attr( $val( 'auto_response', 'to_field' ) ?: 'email' ); ?>"
								       placeholder="email">
							</div>
							<div class="sf-acf-field" style="grid-column: span 2;">
								<label>Subject</label>
								<p class="sf-acf-desc">Supports <code>{{field_name}}</code> tokens.</p>
								<input type="text"
								       class="sf-acf-input"
								       name="<?php echo esc_attr( $input_name( '[auto_response][subject]' ) ); ?>"
								       value="<?php echo esc_attr( $val( 'auto_response', 'subject' ) ?: 'Thanks for getting in touch!' ); ?>">
							</div>
						</div>

						<?php $this->render_template_section(
							'auto_response',
							$val( 'auto_response', 'template_mode' ),
							$val( 'auto_response', 'template_file' ),
							$val( 'auto_response', 'wysiwyg' ),
							$email_files,
							$input_name,
							$key . '_auto_wysiwyg'
						); ?>

					</div>
				</div>

				<!-- ── Section: Spam & Security ─────────────────────────── -->
				<div class="sf-acf-section">
					<div class="sf-acf-section-header">
						<span class="dashicons dashicons-shield"></span> Spam &amp; Security
					</div>
					<div class="sf-acf-section-body">

						<div class="sf-acf-row sf-acf-row--spam">

							<div class="sf-acf-field sf-acf-field--inline">
								<label class="sf-acf-toggle">
									<input type="hidden"
									       name="<?php echo esc_attr( $input_name( '[spam][nonce]' ) ); ?>"
									       value="0">
									<input type="checkbox"
									       name="<?php echo esc_attr( $input_name( '[spam][nonce]' ) ); ?>"
									       value="1"
									       <?php echo $checked( 'spam', 'nonce' ) ?: 'checked'; ?>>
									<span>WordPress Nonce</span>
								</label>
								<p class="sf-acf-desc">Verifies submissions came from your site.</p>
							</div>

							<div class="sf-acf-field sf-acf-field--inline">
								<label class="sf-acf-toggle">
									<input type="hidden"
									       name="<?php echo esc_attr( $input_name( '[spam][honeypot]' ) ); ?>"
									       value="0">
									<input type="checkbox"
									       name="<?php echo esc_attr( $input_name( '[spam][honeypot]' ) ); ?>"
									       value="1"
									       <?php echo $checked( 'spam', 'honeypot' ) ?: 'checked'; ?>>
									<span>Honeypot Field</span>
								</label>
								<p class="sf-acf-desc">Hidden field that traps bots silently.</p>
							</div>

							<div class="sf-acf-field">
								<label>Rate Limit</label>
								<p class="sf-acf-desc">Max submissions per IP per hour. Set to 0 to disable.</p>
								<div class="sf-acf-rate-wrap">
									<input type="number"
									       class="sf-acf-input sf-acf-input--number"
									       name="<?php echo esc_attr( $input_name( '[spam][rate_limit]' ) ); ?>"
									       value="<?php echo (int) ( $val( 'spam', 'rate_limit' ) ?: 5 ); ?>"
									       min="0"
									       max="100">
									<span class="sf-acf-unit">per hour</span>
								</div>
							</div>

						</div>
					</div>
				</div>

			</div><!-- .sf-acf-wrap -->

			<?php $this->render_js( $key ); ?>
			<?php
		}

		// ── Template mode sub-section ─────────────────────────────────────────

		private function render_template_section(
			string $section,
			string $mode,
			string $file,
			string $wysiwyg,
			array  $email_files,
			callable $input_name,
			string $editor_id
		): void {
			$editor_id = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $editor_id ) );
			?>
			<div class="sf-acf-field sf-acf-template-section" data-section="<?php echo esc_attr( $section ); ?>">
				<label>Email Template</label>

				<div class="sf-acf-mode-tabs">
					<button type="button"
					        class="sf-acf-mode-btn <?php echo $mode !== 'file' && $mode !== 'wysiwyg' ? 'is-active' : ''; ?>"
					        data-mode="default">
						Default
					</button>
					<button type="button"
					        class="sf-acf-mode-btn <?php echo $mode === 'file' ? 'is-active' : ''; ?>"
					        data-mode="file">
						PHP File
					</button>
					<button type="button"
					        class="sf-acf-mode-btn <?php echo $mode === 'wysiwyg' ? 'is-active' : ''; ?>"
					        data-mode="wysiwyg">
						Visual Editor
					</button>
				</div>

				<input type="hidden"
				       class="sf-acf-mode-input"
				       name="<?php echo esc_attr( $input_name( "[{$section}][template_mode]" ) ); ?>"
				       value="<?php echo esc_attr( $mode ?: 'default' ); ?>">

				<!-- Default -->
				<div class="sf-acf-mode-panel <?php echo $mode !== 'file' && $mode !== 'wysiwyg' ? 'is-active' : ''; ?>"
				     data-panel="default">
					<p class="sf-acf-notice sf-acf-notice--info">
						A clean HTML table email will be generated automatically from the submitted fields.
					</p>
				</div>

				<!-- PHP File -->
				<div class="sf-acf-mode-panel <?php echo $mode === 'file' ? 'is-active' : ''; ?>"
				     data-panel="file">
					<?php if ( $email_files ) : ?>
						<select name="<?php echo esc_attr( $input_name( "[{$section}][template_file]" ) ); ?>"
						        class="sf-acf-select">
							<option value="">— Select an email template —</option>
							<?php foreach ( $email_files as $f ) : ?>
								<option value="<?php echo esc_attr( $f ); ?>"
								        <?php selected( $file, $f ); ?>>
									<?php echo esc_html( $f ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<p class="sf-acf-notice">No <code>.php</code> files found in the emails directory.</p>
					<?php endif; ?>
					<p class="sf-acf-desc" style="margin-top:6px;">
						Variables available: <code>$name</code>, <code>$email</code>, <code>$form_fields</code> (array), <code>$form_id</code>, <code>$form_label</code>.
					</p>
				</div>

				<!-- WYSIWYG -->
				<div class="sf-acf-mode-panel <?php echo $mode === 'wysiwyg' ? 'is-active' : ''; ?>"
				     data-panel="wysiwyg">
					<p class="sf-acf-desc">
						Write your email in HTML. Use <code>{{field_name}}</code> tokens to insert submitted values, e.g. <code>{{name}}</code>, <code>{{email}}</code>.
					</p>
					<?php
					wp_editor(
						$wysiwyg,
						$editor_id,
						[
							'textarea_name' => $input_name( "[{$section}][wysiwyg]" ),
							'textarea_rows' => 16,
							'media_buttons' => false,
							'teeny'         => false,
							'quicktags'     => [ 'buttons' => 'strong,em,ul,ol,li,link,close' ],
							'tinymce'       => [
								'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,hr,link,unlink,undo,redo,code',
								'toolbar2' => '',
							],
						]
					);
					?>
				</div>

			</div>
			<?php
		}

		// ── Value handling ────────────────────────────────────────────────────

		public function update_value( $value, $post_id, $field ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}

			// Ensure nested arrays exist
			$value['email']         = $value['email'] ?? [];
			$value['auto_response'] = $value['auto_response'] ?? [];
			$value['spam']          = $value['spam'] ?? [];

			// Sanitize top-level
			$value['form_id']         = sanitize_key( $value['form_id'] ?? '' );
			$value['template_file']   = sanitize_file_name( $value['template_file'] ?? '' );
			$value['log']             = ! empty( $value['log'] ) ? 1 : 0;
			$value['success_message'] = sanitize_text_field( $value['success_message'] ?? '' );
			$value['error_message']   = sanitize_text_field( $value['error_message'] ?? '' );

			// Sanitize email
			$value['email']['to']             = sanitize_email( $value['email']['to'] ?? '' );
			$value['email']['subject']        = sanitize_text_field( $value['email']['subject'] ?? '' );
			$value['email']['reply_to_field'] = sanitize_key( $value['email']['reply_to_field'] ?? 'email' );
			$value['email']['from_name']      = sanitize_text_field( $value['email']['from_name'] ?? '' );
			$value['email']['from_email']     = sanitize_email( $value['email']['from_email'] ?? '' );
			$value['email']['template_mode']  = sanitize_key( $value['email']['template_mode'] ?? 'default' );
			$value['email']['template_file']  = sanitize_file_name( $value['email']['template_file'] ?? '' );
			$value['email']['wysiwyg']        = wp_kses_post( $value['email']['wysiwyg'] ?? '' );

			// Sanitize auto_response
			$value['auto_response']['enabled']       = ! empty( $value['auto_response']['enabled'] ) ? 1 : 0;
			$value['auto_response']['to_field']      = sanitize_key( $value['auto_response']['to_field'] ?? 'email' );
			$value['auto_response']['subject']       = sanitize_text_field( $value['auto_response']['subject'] ?? '' );
			$value['auto_response']['template_mode'] = sanitize_key( $value['auto_response']['template_mode'] ?? 'default' );
			$value['auto_response']['template_file'] = sanitize_file_name( $value['auto_response']['template_file'] ?? '' );
			$value['auto_response']['wysiwyg']       = wp_kses_post( $value['auto_response']['wysiwyg'] ?? '' );

			// Sanitize spam
			$value['spam']['honeypot']   = ! empty( $value['spam']['honeypot'] ) ? 1 : 0;
			$value['spam']['nonce']      = ! empty( $value['spam']['nonce'] ) ? 1 : 0;
			$value['spam']['rate_limit'] = max( 0, (int) ( $value['spam']['rate_limit'] ?? 5 ) );

			return $value;
		}

		// ── Utilities ─────────────────────────────────────────────────────────

		/**
		 * Scan a directory and return filenames matching a given extension.
		 */
		private function scan_dir( string $dir, string $extension ): array {
			if ( ! is_dir( $dir ) ) {
				return [];
			}

			$files = array_values( array_filter(
				scandir( $dir ),
				fn( string $f ) => pathinfo( $f, PATHINFO_EXTENSION ) === $extension
			) );

			sort( $files );
			return $files;
		}

		// ── CSS ───────────────────────────────────────────────────────────────

		private function render_css(): void {
			static $rendered = false;
			if ( $rendered ) return;
			$rendered = true;
			?>
			<style>
			/* ── Simpli Forms ACF Field ──────────────────────────────────────── */
			.sf-acf-wrap { font-size: 13px; }

			/* Sections */
			.sf-acf-section { border: 1px solid #dcdcde; border-radius: 6px; overflow: hidden; margin-bottom: 16px; }
			.sf-acf-section-header { display: flex; align-items: center; gap: 8px; padding: 12px 16px; background: #f6f7f7; border-bottom: 1px solid #dcdcde; font-weight: 600; font-size: 13px; color: #1d2327; }
			.sf-acf-section-header .dashicons { color: #787c82; font-size: 16px; width: 16px; height: 16px; }
			.sf-acf-section-sub { font-weight: 400; color: #787c82; font-size: 12px; margin-left: auto; }
			.sf-acf-section-body { padding: 16px; }

			/* Rows / grid */
			.sf-acf-row { display: grid; gap: 12px 16px; margin-bottom: 14px; }
			.sf-acf-row:last-child { margin-bottom: 0; }
			.sf-acf-row--half   { grid-template-columns: 1fr 1fr; }
			.sf-acf-row--third  { grid-template-columns: 1fr 1fr 1fr; }
			.sf-acf-row--spam   { grid-template-columns: 1fr 1fr 1fr; }

			/* Fields */
			.sf-acf-field > label:first-child { display: block; font-weight: 600; color: #1d2327; margin-bottom: 4px; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; }
			.sf-acf-required { color: #d63638; }
			.sf-acf-desc { margin: 0 0 6px; color: #787c82; font-size: 12px; line-height: 1.5; }
			.sf-acf-field--inline { display: flex; flex-direction: column; justify-content: flex-start; }

			/* Inputs */
			.sf-acf-input { width: 100%; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; color: #2c3338; box-shadow: 0 0 0 transparent; transition: border-color .1s, box-shadow .1s; box-sizing: border-box; }
			.sf-acf-input:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }
			.sf-acf-input--number { width: 80px; }
			.sf-acf-select { width: 100%; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; color: #2c3338; background: #fff; }

			/* Rate limit */
			.sf-acf-rate-wrap { display: flex; align-items: center; gap: 8px; }
			.sf-acf-unit { color: #787c82; font-size: 12px; }

			/* Toggle */
			.sf-acf-toggle { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 400; color: #2c3338; }
			.sf-acf-toggle input[type="checkbox"] { margin: 0; width: 16px; height: 16px; cursor: pointer; }
			.sf-acf-toggle--header { margin-left: auto; font-size: 12px; font-weight: 600; }

			/* Template mode tabs */
			.sf-acf-mode-tabs { display: flex; gap: 0; margin-bottom: 10px; border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; width: fit-content; }
			.sf-acf-mode-btn { padding: 6px 14px; background: #f6f7f7; border: none; border-right: 1px solid #dcdcde; font-size: 12px; font-weight: 600; color: #787c82; cursor: pointer; transition: background .12s, color .12s; }
			.sf-acf-mode-btn:last-child { border-right: none; }
			.sf-acf-mode-btn:hover { background: #e9e9e9; color: #2c3338; }
			.sf-acf-mode-btn.is-active { background: #2271b1; color: #fff; }

			/* Mode panels */
			.sf-acf-mode-panel { display: none; }
			.sf-acf-mode-panel.is-active { display: block; }

			/* Notices */
			.sf-acf-notice { padding: 10px 14px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; font-size: 12px; color: #787c82; margin: 0; }
			.sf-acf-notice--info { background: #f0f6fc; border-color: #bdd7ee; color: #2c5f8a; }

			/* wp_editor wrapper */
			.sf-acf-mode-panel .wp-editor-wrap { border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; }
			</style>
			<?php
		}

		// ── JS ────────────────────────────────────────────────────────────────

		private function render_js( string $key ): void {
			$key_js = esc_js( $key );
			?>
			<script>
			(function () {
			    var wrap = document.querySelector('.sf-acf-wrap[data-key="<?php echo esc_js( $key ); ?>"]');
			    if (!wrap) return;

			    // ── Auto-fill Form ID from template filename ──────────────────
			    var templateSelect = wrap.querySelector('.sf-acf-form-template-select');
			    var formIdInput    = wrap.querySelector('.sf-acf-form-id-input');

			    if (templateSelect && formIdInput) {
			        templateSelect.addEventListener('change', function () {
			            if (!formIdInput.value) {
			                var filename = this.value.replace(/\.[^.]+$/, ''); // strip extension
			                formIdInput.value = filename.replace(/[^a-z0-9]+/gi, '-').toLowerCase();
			            }
			        });
			    }

			    // ── Auto-response toggle ──────────────────────────────────────
			    var autoCheckbox = wrap.querySelector('.sf-acf-autoresponse-checkbox');
			    var autoBody     = wrap.querySelector('.sf-acf-autoresponse-body');

			    if (autoCheckbox && autoBody) {
			        autoCheckbox.addEventListener('change', function () {
			            autoBody.style.display = this.checked ? '' : 'none';
			        });
			    }

			    // ── Template mode tabs ────────────────────────────────────────
			    wrap.querySelectorAll('.sf-acf-template-section').forEach(function (section) {
			        var btns      = section.querySelectorAll('.sf-acf-mode-btn');
			        var panels    = section.querySelectorAll('.sf-acf-mode-panel');
			        var modeInput = section.querySelector('.sf-acf-mode-input');

			        btns.forEach(function (btn) {
			            btn.addEventListener('click', function () {
			                var mode = this.dataset.mode;

			                btns.forEach(function (b) { b.classList.remove('is-active'); });
			                panels.forEach(function (p) { p.classList.remove('is-active'); });

			                this.classList.add('is-active');
			                section.querySelector('[data-panel="' + mode + '"]').classList.add('is-active');

			                if (modeInput) modeInput.value = mode;
			            });
			        });
			    });
			})();
			</script>
			<?php
		}
	}

	acf_register_field_type( 'acf_field_simpliforms' );
} );