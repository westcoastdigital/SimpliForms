<?php
/**
 * Simpli Forms — Meta Box Field Type
 * ────────────────────────────────────
 * Registers a custom Meta Box field type that lets you configure and attach a
 * SimpliForm to any post, page, or options page from the WordPress backend.
 *
 * Requires: Meta Box 5+ (free or Pro), simpliforms.php
 *
 * ─── SETUP ───────────────────────────────────────────────────────────────────
 *
 * 1. Register the meta box and field in functions.php (or your plugin):
 *
 *       add_filter( 'rwmb_meta_boxes', function ( array $meta_boxes ): array {
 *
 *           $meta_boxes[] = [
 *               'title'      => 'Contact Form',
 *               'post_types' => [ 'page' ],
 *               'fields'     => [
 *                   [
 *                       'type'       => 'simpliforms',
 *                       'id'         => 'contact_form',
 *                       'name'       => 'Contact Form',
 *                       'forms_dir'  => 'forms',        // relative to theme root
 *                       'emails_dir' => 'forms/emails', // relative to theme root
 *                   ],
 *               ],
 *           ];
 *
 *           return $meta_boxes;
 *       } );
 *
 * 2. Auto-register all Simpli Form fields found on published pages/posts:
 *
 *       add_action( 'init', function () {
 *           simpliforms_metabox_autoregister();
 *       } );
 *
 *    For better performance on large sites, pass the exact field IDs to check:
 *
 *       add_action( 'init', function () {
 *           simpliforms_metabox_autoregister( [ 'contact_form', 'quote_form' ] );
 *       } );
 *
 * 3. Render in your template:
 *
 *       $value = rwmb_meta( 'contact_form' );
 *       if ( ! empty( $value['form_id'] ) ) {
 *           echo $GLOBALS['simpliforms'][ $value['form_id'] ]->render();
 *       }
 *
 *    Or if you know the form ID ahead of time:
 *
 *       echo $GLOBALS['simpliforms']['contact']->render();
 *
 * ─── OPTIONS PAGE ────────────────────────────────────────────────────────────
 *
 * Works with MB Settings Page (Meta Box Pro add-on). Retrieve the value with:
 *
 *       $value = rwmb_meta( 'contact_form', [ 'object_type' => 'setting' ], 'my-settings-page' );
 *       simpliforms_register_from_metabox( $value );
 *
 * ─── FIELD ATTRIBUTES ────────────────────────────────────────────────────────
 *
 *   forms_dir   Path to HTML form templates, relative to the active theme root.
 *               Default: 'forms'
 *
 *   emails_dir  Path to PHP email templates, relative to the active theme root.
 *               Default: 'forms/emails'
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * @package SimpliForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Registration Helpers ─────────────────────────────────────────────────────

/**
 * Build a SimpliForm config array from a Meta Box field value and register it.
 * Safe to call multiple times — skips if the form ID is already registered.
 *
 * The value is the raw array returned by rwmb_meta() for a 'simpliforms' field.
 *
 * @param array $value  The raw field value.
 * @return SimpliForm|null
 */
function simpliforms_register_from_metabox( array $value ): ?SimpliForm {
	if ( ! class_exists( 'SimpliForm' ) ) {
		return null;
	}

	$form_id = sanitize_key( $value['form_id'] ?? '' );

	if ( ! $form_id ) {
		return null;
	}

	// Already registered — don't double-up.
	$registry = SimpliForm::get_registry();
	if ( isset( $registry[ $form_id ] ) ) {
		return $registry[ $form_id ];
	}

	$theme_dir = get_template_directory();

	// ── Resolve form HTML template ──────────────────────────────────────────────
	$forms_dir = trailingslashit( $theme_dir ) . trim( $value['forms_dir'] ?? 'forms', '/' );
	$template  = ! empty( $value['template_file'] )
		? trailingslashit( $forms_dir ) . $value['template_file']
		: '';

	// ── Resolve email templates / inline HTML ───────────────────────────────────
	$emails_dir = trailingslashit( $theme_dir ) . trim( $value['emails_dir'] ?? 'forms/emails', '/' );

	$email_cfg = [
		'to'             => sanitize_email( $value['email']['to'] ?? get_option( 'admin_email' ) ),
		'subject'        => sanitize_text_field( $value['email']['subject'] ?? __( 'New submission', 'simpliforms' ) ),
		'reply_to_field' => sanitize_key( $value['email']['reply_to_field'] ?? 'email' ),
		'from_name'      => sanitize_text_field( $value['email']['from_name'] ?? get_bloginfo( 'name' ) ),
		'from_email'     => sanitize_email( $value['email']['from_email'] ?? get_option( 'admin_email' ) ),
	];

	$email_mode = $value['email']['template_mode'] ?? 'default';
	if ( $email_mode === 'file' && ! empty( $value['email']['template_file'] ) ) {
		$email_cfg['template'] = trailingslashit( $emails_dir ) . $value['email']['template_file'];
	} elseif ( $email_mode === 'wysiwyg' && ! empty( $value['email']['wysiwyg'] ) ) {
		$email_cfg['inline_html'] = wp_kses_post( $value['email']['wysiwyg'] );
	}

	$auto_cfg = [
		'enabled'  => ! empty( $value['auto_response']['enabled'] ),
		'to_field' => sanitize_key( $value['auto_response']['to_field'] ?? 'email' ),
		'subject'  => sanitize_text_field( $value['auto_response']['subject'] ?? __( 'Thanks for getting in touch', 'simpliforms' ) ),
	];

	$auto_mode = $value['auto_response']['template_mode'] ?? 'default';
	if ( $auto_mode === 'file' && ! empty( $value['auto_response']['template_file'] ) ) {
		$auto_cfg['template'] = trailingslashit( $emails_dir ) . $value['auto_response']['template_file'];
	} elseif ( $auto_mode === 'wysiwyg' && ! empty( $value['auto_response']['wysiwyg'] ) ) {
		$auto_cfg['inline_html'] = wp_kses_post( $value['auto_response']['wysiwyg'] );
	}

	$config = [
		'template'        => $template,
		'log'             => ! empty( $value['log'] ),
		'success_message' => sanitize_text_field( $value['success_message'] ?? __( 'Thank you! Your message has been sent.', 'simpliforms' ) ),
		'error_message'   => sanitize_text_field( $value['error_message'] ?? __( 'Something went wrong. Please try again.', 'simpliforms' ) ),
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
 * Scan published posts for Simpli Form Meta Box fields and register them.
 * Call inside add_action('init', ...) in functions.php.
 *
 * When $field_ids is empty, ALL post meta is scanned for arrays containing
 * the '_simpliforms_field' marker. On large sites with many posts, passing
 * explicit field IDs is significantly faster.
 *
 * @param string[] $field_ids   Optional. Meta keys to check. Default: scan all meta.
 * @param string[] $post_types  Post types to scan. Default: page, post.
 */
function simpliforms_metabox_autoregister( array $field_ids = [], array $post_types = [ 'page', 'post' ] ): void {
	$posts = get_posts( [
		'post_type'      => $post_types,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	foreach ( $posts as $post_id ) {
		if ( $field_ids ) {
			// Targeted: only check the field IDs the developer specified.
			foreach ( $field_ids as $meta_key ) {
				$value = get_post_meta( $post_id, sanitize_key( $meta_key ), true );
				if ( is_array( $value ) && ! empty( $value['_simpliforms_field'] ) ) {
					simpliforms_register_from_metabox( $value );
				}
			}
		} else {
			// Broad scan: check every meta key on this post.
			$all_meta = get_post_meta( $post_id );
			foreach ( $all_meta as $meta_values ) {
				// get_post_meta without a key returns arrays of arrays.
				$value = maybe_unserialize( $meta_values[0] ?? '' );
				if ( is_array( $value ) && ! empty( $value['_simpliforms_field'] ) ) {
					simpliforms_register_from_metabox( $value );
				}
			}
		}
	}
}

// ─── Meta Box Field Type ──────────────────────────────────────────────────────

/**
 * Register the 'simpliforms' field type with Meta Box.
 * The hook fires after Meta Box itself is ready.
 */
add_filter( 'rwmb_field_types', function ( array $field_types ): array {
	$field_types['simpliforms'] = 'RWMB_SimpliForms_Field';
	return $field_types;
} );

// ─── MB Builder Integration ───────────────────────────────────────────────────

/**
 * Register the 'simpliforms' field type with MB Builder (the visual drag-and-drop
 * field group editor). This is a separate add-on to Meta Box and is optional.
 *
 * The controls here define the *field definition* settings that appear in the
 * builder UI (i.e. directory paths). All actual form configuration — email
 * recipients, subjects, spam options, etc. — is done through the rendered field
 * UI on the post edit screen, not here.
 *
 * The filter only fires when MB Builder is active, so \MBB\Control is safe to
 * call inside the callback without an explicit class_exists() guard.
 */
add_filter( 'mbb_field_types', function ( array $field_types ): array {
	$field_types['simpliforms'] = [
		/* translators: Field type label in the MB Builder field picker. */
		'title'    => __( 'Simpli Form', 'simpliforms' ),
		'icon'     => 'email-alt2',   // Dashicons name without the 'dashicons-' prefix.
		'category' => 'advanced',

		'controls' => [
			// ── Standard Meta Box controls ──────────────────────────────────
			'name', 'id', 'type', 'label_description', 'desc',

			// ── Directory configuration ─────────────────────────────────────
			// These become attributes on the field definition array and are
			// passed to RWMB_SimpliForms_Field::normalize() at runtime.

			\MBB\Control::Input( 'forms_dir', [
				'label'       => __( 'Forms Directory', 'simpliforms' ),
				'description' => __( 'Path to HTML form templates relative to your theme root, e.g. forms', 'simpliforms' ),
			], 'forms' ),

			\MBB\Control::Input( 'emails_dir', [
				'label'       => __( 'Emails Directory', 'simpliforms' ),
				'description' => __( 'Path to PHP email templates relative to your theme root, e.g. forms/emails', 'simpliforms' ),
			], 'forms/emails' ),

			// ── Standard tail controls ──────────────────────────────────────
			'before', 'after', 'class', 'save_field', 'custom_settings',
		],
	];

	return $field_types;
} );

// ─── MB Builder Click Fix ─────────────────────────────────────────────────────

/**
 * Fix for "settings fields flash / can't be clicked" in MB Builder.
 *
 * WHY THIS HAPPENS
 * ────────────────
 * MB Builder renders each field item as a clickable Vue component. Clicking
 * anywhere inside a field item — including on an <input> inside the settings
 * panel — bubbles up to the outer field-item container, firing its @click
 * handler and toggling the panel open/closed. Built-in field types avoid this
 * with Vue's @click.stop modifiers on their controls; custom field types added
 * via mbb_field_types don't get that treatment automatically.
 *
 * THE FIX
 * ───────
 * We attach a capture-phase event listener (runs before Vue's delegated
 * handlers) that stops propagation whenever a click or mousedown originates
 * on an interactive element (input, select, textarea, label, non-toggle button)
 * that lives inside a field settings panel.
 *
 * Capture-phase (third arg = true) is essential — it lets us intercept the
 * event before Vue's synthetic handlers on the parent container ever see it.
 *
 * SCOPE
 * ─────
 * The script only outputs on MB Builder's field group editor screens
 * (post_type = 'meta-box' or 'mb-field-group'). It intentionally covers ALL
 * field types' settings panels, not just ours — this is safe because stopping
 * propagation on interactive elements in settings panels is always the right
 * behaviour and prevents the same bug for any other custom field types present.
 */
add_action( 'admin_footer', function () {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	// MB Builder stores field groups as a custom post type.
	// 'meta-box' is the standard slug; some installs use 'mb-field-group'.
	if ( ! in_array( $screen->post_type, [ 'meta-box', 'mb-field-group' ], true ) ) {
		return;
	}
	?>
	<script>
	/* SimpliForms — MB Builder click-propagation fix
	 *
	 * Intercepts click/mousedown in the capture phase and stops them from
	 * reaching MB Builder's outer field-item toggle handler when the
	 * originating element is an interactive control inside a settings panel.
	 *
	 * If inputs still flash after this script loads, open DevTools and check
	 * the class or attribute on the settings panel wrapper for your MB Builder
	 * version, then add it to PANEL_SELECTOR below.
	 */
	(function () {

	    // Interactive element tags that should never trigger the panel toggle.
	    var INTERACTIVE = new Set( ['INPUT', 'SELECT', 'TEXTAREA', 'LABEL'] );

	    // Selectors covering MB Builder's settings panel across known versions.
	    // MB Builder 4.x:  .mb-field-settings
	    // MB Builder 3.x:  .mb-field-body
	    // Fallback:        any element whose class contains "field-settings" or "field-body"
	    var PANEL_SELECTOR = [
	        '.mb-field-settings',
	        '.mb-field-body',
	        '[class*="field-settings"]',
	        '[class*="field-body"]',
	    ].join( ', ' );

	    function stopIfInsidePanel( e ) {
	        // Only act on interactive elements.
	        if ( ! INTERACTIVE.has( e.target.tagName ) ) {
	            // Also catch non-toggle buttons (e.g. template mode tabs).
	            if ( e.target.tagName !== 'BUTTON' ) return;
	            if ( e.target.classList.contains( 'mb-field-toggle' ) ) return;
	            if ( e.target.classList.contains( 'mb-field-clone' ) )  return;
	            if ( e.target.classList.contains( 'mb-field-remove' ) ) return;
	        }

	        if ( e.target.closest( PANEL_SELECTOR ) ) {
	            e.stopPropagation();
	        }
	    }

	    // Attach in the capture phase on both events that trigger the flash.
	    document.addEventListener( 'click',     stopIfInsidePanel, true );
	    document.addEventListener( 'mousedown', stopIfInsidePanel, true );

	})();
	</script>
	<?php
} );

/**
 * Meta Box field class for the 'simpliforms' field type.
 *
 * Register your field in a meta box like this:
 *
 *   [
 *       'type'       => 'simpliforms',
 *       'id'         => 'contact_form',
 *       'name'       => 'Contact Form',
 *       'forms_dir'  => 'forms',
 *       'emails_dir' => 'forms/emails',
 *   ]
 */
class RWMB_SimpliForms_Field extends RWMB_Field {

	// ── Normalise / Defaults ──────────────────────────────────────────────────

	/**
	 * Set default attributes for the field definition.
	 * Runs once when Meta Box processes field configurations.
	 */
	public static function normalize( $field ): array {
		$field = parent::normalize( $field );

		// Directory config — set in the field definition, not via UI.
		$field['forms_dir']  = $field['forms_dir']  ?? 'forms';
		$field['emails_dir'] = $field['emails_dir'] ?? 'forms/emails';

		// Ensure we always store a single value, never multiple.
		$field['multiple'] = false;
		$field['clone']    = false;

		return $field;
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render the field HTML. Meta Box calls this and wraps it in the label row.
	 *
	 * @param mixed $meta   Current saved value (array or empty string on first use).
	 * @param array $field  Field configuration.
	 * @return string       HTML string for the field body.
	 */
	public static function html( $meta, $field ): string {
		$v         = is_array( $meta ) ? $meta : [];
		$field_id  = $field['id'];
		$theme_dir = get_template_directory();

		$forms_dir  = trailingslashit( $theme_dir ) . trim( $field['forms_dir'],  '/' );
		$emails_dir = trailingslashit( $theme_dir ) . trim( $field['emails_dir'], '/' );

		$form_files  = self::scan_dir( $forms_dir,  'html' );
		$email_files = self::scan_dir( $emails_dir, 'php'  );

		// Shorthand helpers — mirror the ACF version.
		$val = function ( ...$keys ) use ( $v ) {
			$current = $v;
			foreach ( $keys as $k ) {
				if ( ! is_array( $current ) || ! isset( $current[ $k ] ) ) return '';
				$current = $current[ $k ];
			}
			return $current;
		};

		$checked = fn( ...$keys ) => $val( ...$keys ) ? 'checked' : '';

		// Input name helper: produces  field_id[path][to][key]
		$n = fn( string $path ) => esc_attr( $field_id . $path );

		ob_start();
		?>
		<div class="sf-acf-wrap" data-rwmb-field-id="<?php echo esc_attr( $field_id ); ?>">

			<?php self::render_css(); ?>

			<!-- Hidden marker so autoregister can identify this field type -->
			<input type="hidden" name="<?php echo $n( '[_simpliforms_field]' ); ?>" value="1">

			<!-- Pass directory paths through for register_from_metabox() -->
			<input type="hidden" name="<?php echo $n( '[forms_dir]' );  ?>" value="<?php echo esc_attr( $field['forms_dir']  ); ?>">
			<input type="hidden" name="<?php echo $n( '[emails_dir]' ); ?>" value="<?php echo esc_attr( $field['emails_dir'] ); ?>">

			<!-- ── Section: General ──────────────────────────────────── -->
			<div class="sf-acf-section">
				<div class="sf-acf-section-header">
					<span class="dashicons dashicons-admin-settings"></span>
					<?php esc_html_e( 'General', 'simpliforms' ); ?>
				</div>
				<div class="sf-acf-section-body">

					<div class="sf-acf-row sf-acf-row--half">

						<!-- Form Template -->
						<div class="sf-acf-field">
							<label>
								<?php esc_html_e( 'Form Template', 'simpliforms' ); ?>
								<span class="sf-acf-required">*</span>
							</label>
							<p class="sf-acf-desc"><?php esc_html_e( 'HTML file from your forms directory.', 'simpliforms' ); ?></p>
							<?php if ( $form_files ) : ?>
								<select name="<?php echo $n( '[template_file]' ); ?>"
								        class="sf-acf-select sf-mb-form-template-select">
									<option value=""><?php esc_html_e( '— Select a template —', 'simpliforms' ); ?></option>
									<?php foreach ( $form_files as $file ) : ?>
										<option value="<?php echo esc_attr( $file ); ?>"
										        <?php selected( $val( 'template_file' ), $file ); ?>>
											<?php echo esc_html( $file ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<p class="sf-acf-notice">
									<?php printf(
										/* translators: 1: .html, 2: directory path */
										esc_html__( 'No %1$s files found in %2$s.', 'simpliforms' ),
										'<code>.html</code>',
										'<code>' . esc_html( $forms_dir ) . '</code>'
									); ?>
								</p>
							<?php endif; ?>
						</div>

						<!-- Form ID -->
						<div class="sf-acf-field">
							<label>
								<?php esc_html_e( 'Form ID', 'simpliforms' ); ?>
								<span class="sf-acf-required">*</span>
							</label>
							<p class="sf-acf-desc"><?php esc_html_e( 'Unique slug for routing and logging. Auto-filled from template name.', 'simpliforms' ); ?></p>
							<input type="text"
							       class="sf-acf-input sf-mb-form-id-input"
							       name="<?php echo $n( '[form_id]' ); ?>"
							       value="<?php echo esc_attr( $val( 'form_id' ) ); ?>"
							       placeholder="<?php esc_attr_e( 'e.g. contact', 'simpliforms' ); ?>">
						</div>

					</div>

					<div class="sf-acf-row sf-acf-row--half">

						<!-- Success Message -->
						<div class="sf-acf-field">
							<label><?php esc_html_e( 'Success Message', 'simpliforms' ); ?></label>
							<input type="text"
							       class="sf-acf-input"
							       name="<?php echo $n( '[success_message]' ); ?>"
							       value="<?php echo esc_attr( $val( 'success_message' ) ?: __( 'Thank you! Your message has been sent.', 'simpliforms' ) ); ?>">
						</div>

						<!-- Error Message -->
						<div class="sf-acf-field">
							<label><?php esc_html_e( 'Error Message', 'simpliforms' ); ?></label>
							<input type="text"
							       class="sf-acf-input"
							       name="<?php echo $n( '[error_message]' ); ?>"
							       value="<?php echo esc_attr( $val( 'error_message' ) ?: __( 'Something went wrong. Please try again.', 'simpliforms' ) ); ?>">
						</div>

					</div>

					<!-- Log Submissions -->
					<div class="sf-acf-field sf-acf-field--inline">
						<label class="sf-acf-toggle">
							<input type="hidden"   name="<?php echo $n( '[log]' ); ?>" value="0">
							<input type="checkbox" name="<?php echo $n( '[log]' ); ?>" value="1"
							       <?php echo $checked( 'log' ) ?: 'checked'; ?>>
							<span><?php esc_html_e( 'Log submissions to the database', 'simpliforms' ); ?></span>
						</label>
					</div>

				</div>
			</div>

			<!-- ── Section: Notification Email ──────────────────────── -->
			<div class="sf-acf-section">
				<div class="sf-acf-section-header">
					<span class="dashicons dashicons-email-alt"></span>
					<?php esc_html_e( 'Notification Email', 'simpliforms' ); ?>
					<span class="sf-acf-section-sub"><?php esc_html_e( 'Sent to you on each submission', 'simpliforms' ); ?></span>
				</div>
				<div class="sf-acf-section-body">

					<div class="sf-acf-row sf-acf-row--half">
						<div class="sf-acf-field">
							<label><?php esc_html_e( 'To', 'simpliforms' ); ?></label>
							<input type="email"
							       class="sf-acf-input"
							       name="<?php echo $n( '[email][to]' ); ?>"
							       value="<?php echo esc_attr( $val( 'email', 'to' ) ?: get_option( 'admin_email' ) ); ?>"
							       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						</div>
						<div class="sf-acf-field">
							<label><?php esc_html_e( 'Subject', 'simpliforms' ); ?></label>
							<p class="sf-acf-desc">
								<?php printf(
									/* translators: %s = {{field_name}} example */
									esc_html__( 'Supports %s tokens.', 'simpliforms' ),
									'<code>{{field_name}}</code>'
								); ?>
							</p>
							<input type="text"
							       class="sf-acf-input"
							       name="<?php echo $n( '[email][subject]' ); ?>"
							       value="<?php echo esc_attr( $val( 'email', 'subject' ) ?: __( 'New enquiry from {{name}}', 'simpliforms' ) ); ?>">
						</div>
					</div>

					<div class="sf-acf-row sf-acf-row--third">
						<div class="sf-acf-field">
							<label><?php esc_html_e( 'From Name', 'simpliforms' ); ?></label>
							<input type="text"
							       class="sf-acf-input"
							       name="<?php echo $n( '[email][from_name]' ); ?>"
							       value="<?php echo esc_attr( $val( 'email', 'from_name' ) ?: get_bloginfo( 'name' ) ); ?>"
							       placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
						</div>
						<div class="sf-acf-field">
							<label><?php esc_html_e( 'From Email', 'simpliforms' ); ?></label>
							<input type="email"
							       class="sf-acf-input"
							       name="<?php echo $n( '[email][from_email]' ); ?>"
							       value="<?php echo esc_attr( $val( 'email', 'from_email' ) ?: get_option( 'admin_email' ) ); ?>"
							       placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						</div>
						<div class="sf-acf-field">
							<label><?php esc_html_e( 'Reply-To Field', 'simpliforms' ); ?></label>
							<p class="sf-acf-desc">
								<?php printf(
									/* translators: %s = "name" HTML attribute */
									esc_html__( 'Field %s attribute that holds the submitter\'s email.', 'simpliforms' ),
									'<code>name</code>'
								); ?>
							</p>
							<input type="text"
							       class="sf-acf-input"
							       name="<?php echo $n( '[email][reply_to_field]' ); ?>"
							       value="<?php echo esc_attr( $val( 'email', 'reply_to_field' ) ?: 'email' ); ?>"
							       placeholder="email">
						</div>
					</div>

					<?php self::render_template_section(
						'email',
						(string) $val( 'email', 'template_mode' ),
						(string) $val( 'email', 'template_file' ),
						(string) $val( 'email', 'wysiwyg' ),
						$email_files,
						$n,
						$field_id . '_email_wysiwyg'
					); ?>

				</div>
			</div>

			<!-- ── Section: Auto-Response ────────────────────────────── -->
			<div class="sf-acf-section">
				<div class="sf-acf-section-header">
					<span class="dashicons dashicons-redo"></span>
					<?php esc_html_e( 'Auto-Response', 'simpliforms' ); ?>
					<span class="sf-acf-section-sub"><?php esc_html_e( 'Sent to the person who submitted', 'simpliforms' ); ?></span>

					<label class="sf-acf-toggle sf-acf-toggle--header sf-mb-autoresponse-toggle">
						<input type="hidden"
						       name="<?php echo $n( '[auto_response][enabled]' ); ?>"
						       value="0">
						<input type="checkbox"
						       name="<?php echo $n( '[auto_response][enabled]' ); ?>"
						       class="sf-mb-autoresponse-checkbox"
						       value="1"
						       <?php echo $checked( 'auto_response', 'enabled' ); ?>>
						<span><?php esc_html_e( 'Enable', 'simpliforms' ); ?></span>
					</label>
				</div>
				<div class="sf-acf-section-body sf-mb-autoresponse-body"
				     style="<?php echo ! $val( 'auto_response', 'enabled' ) ? 'display:none;' : ''; ?>">

					<div class="sf-acf-row sf-acf-row--third">
						<div class="sf-acf-field">
							<label><?php esc_html_e( 'Recipient Field', 'simpliforms' ); ?></label>
							<p class="sf-acf-desc">
								<?php printf(
									/* translators: %s = "name" HTML attribute */
									esc_html__( 'Field %s that holds the submitter\'s email address.', 'simpliforms' ),
									'<code>name</code>'
								); ?>
							</p>
							<input type="text"
							       class="sf-acf-input"
							       name="<?php echo $n( '[auto_response][to_field]' ); ?>"
							       value="<?php echo esc_attr( $val( 'auto_response', 'to_field' ) ?: 'email' ); ?>"
							       placeholder="email">
						</div>
						<div class="sf-acf-field" style="grid-column: span 2;">
							<label><?php esc_html_e( 'Subject', 'simpliforms' ); ?></label>
							<p class="sf-acf-desc">
								<?php printf(
									/* translators: %s = {{field_name}} example */
									esc_html__( 'Supports %s tokens.', 'simpliforms' ),
									'<code>{{field_name}}</code>'
								); ?>
							</p>
							<input type="text"
							       class="sf-acf-input"
							       name="<?php echo $n( '[auto_response][subject]' ); ?>"
							       value="<?php echo esc_attr( $val( 'auto_response', 'subject' ) ?: __( 'Thanks for getting in touch!', 'simpliforms' ) ); ?>">
						</div>
					</div>

					<?php self::render_template_section(
						'auto_response',
						(string) $val( 'auto_response', 'template_mode' ),
						(string) $val( 'auto_response', 'template_file' ),
						(string) $val( 'auto_response', 'wysiwyg' ),
						$email_files,
						$n,
						$field_id . '_auto_wysiwyg'
					); ?>

				</div>
			</div>

			<!-- ── Section: Spam & Security ─────────────────────────── -->
			<div class="sf-acf-section">
				<div class="sf-acf-section-header">
					<span class="dashicons dashicons-shield"></span>
					<?php esc_html_e( 'Spam &amp; Security', 'simpliforms' ); ?>
				</div>
				<div class="sf-acf-section-body">
					<div class="sf-acf-row sf-acf-row--spam">

						<div class="sf-acf-field sf-acf-field--inline">
							<label class="sf-acf-toggle">
								<input type="hidden"   name="<?php echo $n( '[spam][nonce]' ); ?>" value="0">
								<input type="checkbox" name="<?php echo $n( '[spam][nonce]' ); ?>" value="1"
								       <?php echo $checked( 'spam', 'nonce' ) ?: 'checked'; ?>>
								<span><?php esc_html_e( 'WordPress Nonce', 'simpliforms' ); ?></span>
							</label>
							<p class="sf-acf-desc"><?php esc_html_e( 'Verifies submissions came from your site.', 'simpliforms' ); ?></p>
						</div>

						<div class="sf-acf-field sf-acf-field--inline">
							<label class="sf-acf-toggle">
								<input type="hidden"   name="<?php echo $n( '[spam][honeypot]' ); ?>" value="0">
								<input type="checkbox" name="<?php echo $n( '[spam][honeypot]' ); ?>" value="1"
								       <?php echo $checked( 'spam', 'honeypot' ) ?: 'checked'; ?>>
								<span><?php esc_html_e( 'Honeypot Field', 'simpliforms' ); ?></span>
							</label>
							<p class="sf-acf-desc"><?php esc_html_e( 'Hidden field that traps bots silently.', 'simpliforms' ); ?></p>
						</div>

						<div class="sf-acf-field">
							<label><?php esc_html_e( 'Rate Limit', 'simpliforms' ); ?></label>
							<p class="sf-acf-desc"><?php esc_html_e( 'Max submissions per IP per hour. Set to 0 to disable.', 'simpliforms' ); ?></p>
							<div class="sf-acf-rate-wrap">
								<input type="number"
								       class="sf-acf-input sf-acf-input--number"
								       name="<?php echo $n( '[spam][rate_limit]' ); ?>"
								       value="<?php echo (int) ( $val( 'spam', 'rate_limit' ) ?: 5 ); ?>"
								       min="0" max="100">
								<span class="sf-acf-unit"><?php esc_html_e( 'per hour', 'simpliforms' ); ?></span>
							</div>
						</div>

					</div>
				</div>
			</div>

		</div><!-- .sf-acf-wrap -->

		<?php self::render_js( $field_id ); ?>
		<?php
		return ob_get_clean();
	}

	// ── Save ──────────────────────────────────────────────────────────────────

	/**
	 * Sanitize the submitted value before Meta Box saves it to post meta.
	 *
	 * @param mixed  $new         Raw value from $_POST[$field_id].
	 * @param mixed  $old         Previously saved value.
	 * @param int    $object_id   Post / object ID.
	 * @param array  $field       Field configuration.
	 * @param string $object_type 'post', 'term', 'user', 'setting', etc.
	 */
	public static function save( $new, $old, $object_id, $field, $object_type = 'post' ): void {
		if ( is_array( $new ) ) {
			$new = self::sanitize_value( $new );
		}
		parent::save( $new, $old, $object_id, $field, $object_type );
	}

	// ── Retrieve ──────────────────────────────────────────────────────────────

	/**
	 * Return the saved value, ensuring it is always an array.
	 */
	public static function get_value( $field, $args = [], $post_id = null ) {
		$value = parent::get_value( $field, $args, $post_id );
		return is_array( $value ) ? $value : [];
	}

	/**
	 * Transform the stored config array into rendered form HTML.
	 * Called by rwmb_the_value() and rwmb_get_value(), so you can use either
	 * of these in your template instead of manually calling render():
	 *
	 *   rwmb_the_value( 'contact_form' );
	 *   $html = rwmb_get_value( 'contact_form' );
	 *
	 * @param mixed $value      Raw saved value (array or empty string).
	 * @param array $field      Field configuration.
	 * @param int   $object_id  Post ID.
	 * @return string           Rendered form HTML, or empty string if not configured.
	 */
	public static function format_value( $field, $value, $args, $post_id ): string {
		if ( ! is_array( $value ) || empty( $value['form_id'] ) ) {
			return '';
		}

		$form = simpliforms_register_from_metabox( $value );

		if ( ! $form instanceof SimpliForm ) {
			return '';
		}

		return $form->render();
	}

	// ── Sanitization ──────────────────────────────────────────────────────────

	/**
	 * Sanitize a raw $_POST value for this field.
	 * Matches the sanitization performed in the ACF version's update_value().
	 */
	private static function sanitize_value( array $value ): array {
		$value['email']         = $value['email']         ?? [];
		$value['auto_response'] = $value['auto_response'] ?? [];
		$value['spam']          = $value['spam']          ?? [];

		// Marker — always force to 1 so autoregister can detect this field.
		$value['_simpliforms_field'] = 1;

		// Top-level.
		$value['form_id']         = sanitize_key( $value['form_id']         ?? '' );
		$value['template_file']   = sanitize_file_name( $value['template_file'] ?? '' );
		$value['log']             = ! empty( $value['log'] ) ? 1 : 0;
		$value['success_message'] = sanitize_text_field( $value['success_message'] ?? '' );
		$value['error_message']   = sanitize_text_field( $value['error_message']   ?? '' );
		$value['forms_dir']       = sanitize_text_field( $value['forms_dir']       ?? 'forms' );
		$value['emails_dir']      = sanitize_text_field( $value['emails_dir']      ?? 'forms/emails' );

		// Notification email.
		$value['email']['to']             = sanitize_email( $value['email']['to']                    ?? '' );
		$value['email']['subject']        = sanitize_text_field( $value['email']['subject']          ?? '' );
		$value['email']['reply_to_field'] = sanitize_key( $value['email']['reply_to_field']         ?? 'email' );
		$value['email']['from_name']      = sanitize_text_field( $value['email']['from_name']        ?? '' );
		$value['email']['from_email']     = sanitize_email( $value['email']['from_email']            ?? '' );
		$value['email']['template_mode']  = sanitize_key( $value['email']['template_mode']          ?? 'default' );
		$value['email']['template_file']  = sanitize_file_name( $value['email']['template_file']    ?? '' );
		$value['email']['wysiwyg']        = wp_kses_post( $value['email']['wysiwyg']                ?? '' );

		// Auto-response.
		$value['auto_response']['enabled']       = ! empty( $value['auto_response']['enabled'] ) ? 1 : 0;
		$value['auto_response']['to_field']      = sanitize_key( $value['auto_response']['to_field']          ?? 'email' );
		$value['auto_response']['subject']       = sanitize_text_field( $value['auto_response']['subject']    ?? '' );
		$value['auto_response']['template_mode'] = sanitize_key( $value['auto_response']['template_mode']     ?? 'default' );
		$value['auto_response']['template_file'] = sanitize_file_name( $value['auto_response']['template_file'] ?? '' );
		$value['auto_response']['wysiwyg']       = wp_kses_post( $value['auto_response']['wysiwyg']           ?? '' );

		// Spam.
		$value['spam']['honeypot']   = ! empty( $value['spam']['honeypot'] ) ? 1 : 0;
		$value['spam']['nonce']      = ! empty( $value['spam']['nonce'] )    ? 1 : 0;
		$value['spam']['rate_limit'] = max( 0, (int) ( $value['spam']['rate_limit'] ?? 5 ) );

		return $value;
	}

	// ── Template Section ──────────────────────────────────────────────────────

	/**
	 * Render the Default / PHP File / Visual Editor tab group for an email section.
	 */
	private static function render_template_section(
		string   $section,
		string   $mode,
		string   $file,
		string   $wysiwyg,
		array    $email_files,
		callable $n,
		string   $editor_id
	): void {
		$editor_id = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $editor_id ) );
		?>
		<div class="sf-acf-field sf-acf-template-section" data-section="<?php echo esc_attr( $section ); ?>">
			<label><?php esc_html_e( 'Email Template', 'simpliforms' ); ?></label>

			<div class="sf-acf-mode-tabs">
				<button type="button"
				        class="sf-acf-mode-btn <?php echo ( $mode !== 'file' && $mode !== 'wysiwyg' ) ? 'is-active' : ''; ?>"
				        data-mode="default">
					<?php esc_html_e( 'Default', 'simpliforms' ); ?>
				</button>
				<button type="button"
				        class="sf-acf-mode-btn <?php echo $mode === 'file'    ? 'is-active' : ''; ?>"
				        data-mode="file">
					<?php esc_html_e( 'PHP File', 'simpliforms' ); ?>
				</button>
				<button type="button"
				        class="sf-acf-mode-btn <?php echo $mode === 'wysiwyg' ? 'is-active' : ''; ?>"
				        data-mode="wysiwyg">
					<?php esc_html_e( 'Visual Editor', 'simpliforms' ); ?>
				</button>
			</div>

			<input type="hidden"
			       class="sf-acf-mode-input"
			       name="<?php echo $n( "[{$section}][template_mode]" ); ?>"
			       value="<?php echo esc_attr( $mode ?: 'default' ); ?>">

			<!-- Default -->
			<div class="sf-acf-mode-panel <?php echo ( $mode !== 'file' && $mode !== 'wysiwyg' ) ? 'is-active' : ''; ?>"
			     data-panel="default">
				<p class="sf-acf-notice sf-acf-notice--info">
					<?php esc_html_e( 'A clean HTML table email will be generated automatically from the submitted fields.', 'simpliforms' ); ?>
				</p>
			</div>

			<!-- PHP File -->
			<div class="sf-acf-mode-panel <?php echo $mode === 'file' ? 'is-active' : ''; ?>"
			     data-panel="file">
				<?php if ( $email_files ) : ?>
					<select name="<?php echo $n( "[{$section}][template_file]" ); ?>"
					        class="sf-acf-select">
						<option value=""><?php esc_html_e( '— Select an email template —', 'simpliforms' ); ?></option>
						<?php foreach ( $email_files as $f ) : ?>
							<option value="<?php echo esc_attr( $f ); ?>" <?php selected( $file, $f ); ?>>
								<?php echo esc_html( $f ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<p class="sf-acf-notice"><?php esc_html_e( 'No .php files found in the emails directory.', 'simpliforms' ); ?></p>
				<?php endif; ?>
				<p class="sf-acf-desc" style="margin-top:6px;">
					<?php printf(
						/* translators: variable names available inside PHP email templates */
						esc_html__( 'Variables available: %s', 'simpliforms' ),
						'<code>$name</code>, <code>$email</code>, <code>$form_fields</code> (array), <code>$form_id</code>, <code>$form_label</code>'
					); ?>
				</p>
			</div>

			<!-- Visual Editor (WYSIWYG) -->
			<div class="sf-acf-mode-panel <?php echo $mode === 'wysiwyg' ? 'is-active' : ''; ?>"
			     data-panel="wysiwyg">
				<p class="sf-acf-desc">
					<?php printf(
						/* translators: %s = {{field_name}} example */
						esc_html__( 'Write your email in HTML. Use %s tokens to insert submitted values, e.g. {{name}}, {{email}}.', 'simpliforms' ),
						'<code>{{field_name}}</code>'
					); ?>
				</p>
				<?php
				wp_editor(
					$wysiwyg,
					$editor_id,
					[
						'textarea_name' => $n( "[{$section}][wysiwyg]" ),
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

	// ── Utilities ─────────────────────────────────────────────────────────────

	private static function scan_dir( string $dir, string $extension ): array {
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

	// ── CSS ───────────────────────────────────────────────────────────────────

	/**
	 * Output the shared field CSS once per page load.
	 * Re-uses the same class names as the ACF version so both render consistently
	 * if both integrations are active simultaneously.
	 */
	private static function render_css(): void {
		static $rendered = false;
		if ( $rendered ) return;
		$rendered = true;
		?>
		<style>
		/* ── Simpli Forms Meta Box Field — identical classes to ACF version ── */
		.sf-acf-wrap { font-size: 13px; }

		.sf-acf-section { border: 1px solid #dcdcde; border-radius: 6px; overflow: hidden; margin-bottom: 16px; }
		.sf-acf-section-header { display: flex; align-items: center; gap: 8px; padding: 12px 16px; background: #f6f7f7; border-bottom: 1px solid #dcdcde; font-weight: 600; font-size: 13px; color: #1d2327; }
		.sf-acf-section-header .dashicons { color: #787c82; font-size: 16px; width: 16px; height: 16px; }
		.sf-acf-section-sub { font-weight: 400; color: #787c82; font-size: 12px; margin-left: auto; }
		.sf-acf-section-body { padding: 16px; }

		.sf-acf-row { display: grid; gap: 12px 16px; margin-bottom: 14px; }
		.sf-acf-row:last-child { margin-bottom: 0; }
		.sf-acf-row--half  { grid-template-columns: 1fr 1fr; }
		.sf-acf-row--third { grid-template-columns: 1fr 1fr 1fr; }
		.sf-acf-row--spam  { grid-template-columns: 1fr 1fr 1fr; }

		.sf-acf-field > label:first-child { display: block; font-weight: 600; color: #1d2327; margin-bottom: 4px; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; }
		.sf-acf-required { color: #d63638; }
		.sf-acf-desc { margin: 0 0 6px; color: #787c82; font-size: 12px; line-height: 1.5; }
		.sf-acf-field--inline { display: flex; flex-direction: column; justify-content: flex-start; }

		.sf-acf-input { width: 100%; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; color: #2c3338; box-shadow: 0 0 0 transparent; transition: border-color .1s, box-shadow .1s; box-sizing: border-box; }
		.sf-acf-input:focus { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; outline: none; }
		.sf-acf-input--number { width: 80px; }
		.sf-acf-select { width: 100%; padding: 6px 10px; border: 1px solid #8c8f94; border-radius: 4px; font-size: 13px; color: #2c3338; background: #fff; }

		.sf-acf-rate-wrap { display: flex; align-items: center; gap: 8px; }
		.sf-acf-unit { color: #787c82; font-size: 12px; }

		.sf-acf-toggle { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 400; color: #2c3338; }
		.sf-acf-toggle input[type="checkbox"] { margin: 0; width: 16px; height: 16px; cursor: pointer; }
		.sf-acf-toggle--header { margin-left: auto; font-size: 12px; font-weight: 600; }

		.sf-acf-mode-tabs { display: flex; margin-bottom: 10px; border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; width: fit-content; }
		.sf-acf-mode-btn { padding: 6px 14px; background: #f6f7f7; border: none; border-right: 1px solid #dcdcde; font-size: 12px; font-weight: 600; color: #787c82; cursor: pointer; transition: background .12s, color .12s; }
		.sf-acf-mode-btn:last-child { border-right: none; }
		.sf-acf-mode-btn:hover { background: #e9e9e9; color: #2c3338; }
		.sf-acf-mode-btn.is-active { background: #2271b1; color: #fff; }

		.sf-acf-mode-panel { display: none; }
		.sf-acf-mode-panel.is-active { display: block; }

		.sf-acf-notice { padding: 10px 14px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; font-size: 12px; color: #787c82; margin: 0; }
		.sf-acf-notice--info { background: #f0f6fc; border-color: #bdd7ee; color: #2c5f8a; }

		.sf-acf-mode-panel .wp-editor-wrap { border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; }
		</style>
		<?php
	}

	// ── JS ────────────────────────────────────────────────────────────────────

	private static function render_js( string $field_id ): void {
		?>
		<script>
		(function () {
		    var wrap = document.querySelector('.sf-acf-wrap[data-rwmb-field-id="<?php echo esc_js( $field_id ); ?>"]');
		    if (!wrap) return;

		    // ── Auto-fill Form ID from template filename ──────────────────────
		    var templateSelect = wrap.querySelector('.sf-mb-form-template-select');
		    var formIdInput    = wrap.querySelector('.sf-mb-form-id-input');

		    if (templateSelect && formIdInput) {
		        templateSelect.addEventListener('change', function () {
		            if (!formIdInput.value) {
		                var filename = this.value.replace(/\.[^.]+$/, '');
		                formIdInput.value = filename.replace(/[^a-z0-9]+/gi, '-').toLowerCase();
		            }
		        });
		    }

		    // ── Auto-response toggle ──────────────────────────────────────────
		    var autoCheckbox = wrap.querySelector('.sf-mb-autoresponse-checkbox');
		    var autoBody     = wrap.querySelector('.sf-mb-autoresponse-body');

		    if (autoCheckbox && autoBody) {
		        autoCheckbox.addEventListener('change', function () {
		            autoBody.style.display = this.checked ? '' : 'none';
		        });
		    }

		    // ── Template mode tabs ────────────────────────────────────────────
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