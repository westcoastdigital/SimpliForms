<?php
/**
 * Plugin Name: Simpli Forms
 * Plugin URI:  https://simpliweb.com.au
 * Description: Drop-in HTML form handler for WordPress. Logging, email templates, spam protection — zero backend form building required.
 * Version:     1.1.0
 * Author:      SimpliWeb
 * Author URI:  https://simpliweb.com.au
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simpliforms
 * Domain Path: /languages
 *
 * ─── USAGE ───────────────────────────────────────────────────────────────────
 *
 * 1. Activate as a plugin OR require this file in your theme's functions.php:
 *       require_once get_template_directory() . '/inc/simpliforms.php';
 *
 * 2. Create a plain HTML file with your form. No special markup needed.
 *       Every input/select/textarea must have a name attribute — everything else is injected.
 *
 * 3. Register the form inside an init hook in functions.php.
 *       IMPORTANT: Do not instantiate SimpliForm in a page template. WordPress AJAX
 *       requests never render templates, so the form won't be registered when a
 *       submission comes in and every submit will fail.
 *
 *       add_action( 'init', function () {
 *           $GLOBALS['simpliforms']['contact'] = new SimpliForm( 'contact', [
 *               'template' => get_template_directory() . '/forms/contact.html',
 *               'email'    => [
 *                   'to'             => 'hello@example.com',
 *                   'subject'        => 'New enquiry from {{name}}',
 *                   'template'       => get_template_directory() . '/forms/emails/contact-notification.php',
 *                   'reply_to_field' => 'email',
 *               ],
 *               'auto_response' => [
 *                   'enabled'  => true,
 *                   'to_field' => 'email',
 *                   'subject'  => 'Thanks for getting in touch!',
 *                   'template' => get_template_directory() . '/forms/emails/contact-auto-response.php',
 *               ],
 *           ] );
 *       } );
 *
 * 4. Render in your page template:
 *       echo $GLOBALS['simpliforms']['contact']->render();
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

// ─── Constants ────────────────────────────────────────────────────────────────

define( 'SIMPLIFORMS_VERSION', '1.1.0' );
define( 'SIMPLIFORMS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SIMPLIFORMS_FILE',    __FILE__ );

// ─── Includes ─────────────────────────────────────────────────────────────────

require_once SIMPLIFORMS_DIR . 'includes/class-db.php';
require_once SIMPLIFORMS_DIR . 'includes/class-form.php';
require_once SIMPLIFORMS_DIR . 'includes/class-admin.php';

// ─── GitHub Auto-Updater ──────────────────────────────────────────────────────

require_once SIMPLIFORMS_DIR . 'github-updater.php';

if ( class_exists( 'SimpliWeb_GitHub_Updater' ) ) {
	$updater = new SimpliWeb_GitHub_Updater( __FILE__ );
	$updater->set_username( 'westcoastdigital' );
	$updater->set_repository( 'SimpliForms' );

	// For private repos, uncomment and add your token:
	// if ( defined( 'GITHUB_ACCESS_TOKEN' ) ) {
	//     $updater->authorize( GITHUB_ACCESS_TOKEN );
	// }

	$updater->initialize();
}

// ─── Text Domain ──────────────────────────────────────────────────────────────

add_action( 'init', function () {
	load_plugin_textdomain(
		'simpliforms',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}, 1 );

// ─── Database ─────────────────────────────────────────────────────────────────

/**
 * DB install on plugin activation.
 * If using as a theme include (not a plugin), this hook won't fire —
 * the init hook below handles it instead.
 */
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, [ 'SimpliForm_DB', 'install' ] );
}

add_action( 'init', function () {
	// Create/update table if needed (idempotent).
	if ( get_option( SimpliForm_DB::OPTION ) !== SimpliForm_DB::VERSION ) {
		SimpliForm_DB::install();
	}
} );

// ─── AJAX Handlers ────────────────────────────────────────────────────────────

// Registered late so theme/plugin forms have time to register themselves.
add_action( 'wp_ajax_simpliforms_submit',        [ 'SimpliForm', 'handle_ajax' ] );
add_action( 'wp_ajax_nopriv_simpliforms_submit', [ 'SimpliForm', 'handle_ajax' ] );

// ─── Frontend Script ──────────────────────────────────────────────────────────

add_action( 'wp_enqueue_scripts', function () {
	wp_register_script( 'simpliforms', false, [], SIMPLIFORMS_VERSION, true );
	wp_enqueue_script( 'simpliforms' );

	wp_localize_script( 'simpliforms', 'SimpliForms', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'i18n'    => [
			'sending'        => __( 'Sending\u2026', 'simpliforms' ),
			'genericError'   => __( 'An error occurred. Please try again.', 'simpliforms' ),
			'networkError'   => __( 'Network error. Please check your connection and try again.', 'simpliforms' ),
			/* translators: %s = HTTP status code */
			'unexpectedResp' => __( 'Server returned an unexpected response (HTTP %s). Check that the form is registered in an init hook.', 'simpliforms' ),
		],
	] );

	wp_add_inline_script( 'simpliforms', simpliforms_frontend_js() );
} );

// ─── Admin ────────────────────────────────────────────────────────────────────

SimpliForm_Admin::init();

// ─── ACF Integration ──────────────────────────────────────────────────────────

if ( class_exists( 'ACF' ) ) {
	require_once SIMPLIFORMS_DIR . 'acf-field.php';
}

add_action( 'init', function () {
	if ( ! class_exists( 'ACF' ) ) {
		return;
	}
	simpliforms_acf_autoregister();
} );

// ─── Frontend JS ──────────────────────────────────────────────────────────────

/**
 * Returns the inline frontend JavaScript.
 * Strings are supplied via the SimpliForms.i18n object localised above.
 */
function simpliforms_frontend_js(): string {
	return <<<'JS'
(function () {
    'use strict';

    var SF       = (typeof SimpliForms !== 'undefined') ? SimpliForms : {};
    var AJAX_URL = SF.ajaxUrl || '/wp-admin/admin-ajax.php';
    var I18N     = SF.i18n   || {};

    function t( key, fallback ) {
        return I18N[ key ] || fallback;
    }

    function init() {
        document.querySelectorAll('.simpliforms-wrapper form').forEach(function (form) {
            form.addEventListener('submit', handleSubmit);
        });
    }

    function handleSubmit(e) {
        e.preventDefault();

        var form     = e.currentTarget;
        var wrapper  = form.closest('.simpliforms-wrapper');
        var msgEl    = wrapper.querySelector('.simpliforms-response');
        var btn      = form.querySelector('[type="submit"]');
        var btnLabel = btn ? btn.textContent : '';

        // Reset state
        setMessage(msgEl, '', '');
        wrapper.classList.add('simpliforms-loading');
        if (btn) { btn.disabled = true; btn.textContent = t('sending', 'Sending\u2026'); }

        var data = new FormData(form);
        data.append('action', 'simpliforms_submit');

        fetch(AJAX_URL, {
            method:      'POST',
            body:        data,
            credentials: 'same-origin',
        })
        .then(function (r) {
            // Always parse JSON — WordPress sends a JSON body even on 4xx responses.
            return r.json().catch(function () {
                var msg = t('unexpectedResp', 'Server returned an unexpected response (HTTP %s). Check that the form is registered in an init hook.');
                throw new Error(msg.replace('%s', r.status));
            });
        })
        .then(function (res) {
            if (res.success) {
                setMessage(msgEl, res.data.message, 'simpliforms-success');
                form.reset();
                wrapper.dispatchEvent(new CustomEvent('simpliforms:success', { bubbles: true, detail: res.data }));
            } else {
                var msg = (res.data && res.data.message)
                    ? res.data.message
                    : t('genericError', 'An error occurred. Please try again.');
                setMessage(msgEl, msg, 'simpliforms-error');
                wrapper.dispatchEvent(new CustomEvent('simpliforms:error', { bubbles: true, detail: res.data }));
            }
        })
        .catch(function (err) {
            var msg = (err && err.message) ? err.message : t('networkError', 'Network error. Please check your connection and try again.');
            setMessage(msgEl, msg, 'simpliforms-error');
            console.error('[SimpliForms]', err);
        })
        .finally(function () {
            wrapper.classList.remove('simpliforms-loading');
            if (btn) { btn.disabled = false; btn.textContent = btnLabel; }
        });
    }

    function setMessage(el, text, cssClass) {
        el.textContent = text;
        el.className   = 'simpliforms-response' + (cssClass ? ' ' + cssClass : '');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;
}