# Simpli Forms

A lightweight WordPress form handler for custom HTML forms. Write your form in plain HTML, point the class at it, and get AJAX submission, spam protection, email notifications, auto-responses, and a full submission log in the WordPress backend — with zero backend form building.

Built for use alongside Gravity Forms: use Gravity Forms for complex, data-driven forms and Simpli Forms for bespoke HTML forms embedded directly in theme templates.

---

## Features

- **Bring your own HTML** — write a standard HTML form file, no special markup required
- **AJAX submission** — vanilla JS, no jQuery dependency
- **Three-layer spam protection** — nonce verification, honeypot field, and per-IP rate limiting
- **Email notifications** — PHP template, visual editor, or auto-generated HTML table fallback
- **Auto-response emails** — sent to the submitter, fully templated
- **`{{token}}` replacement** in subject lines and WYSIWYG email bodies
- **Submission logging** — every submission stored in a custom DB table with all fields as JSON
- **WordPress admin UI** — tabbed by form, unread badge, bulk delete, paginated list, single detail view
- **ACF field type** — configure forms entirely from the WordPress backend (requires ACF or ACF Pro)
- **Custom validation** — `before_submit` callback returning `true` or `WP_Error`
- **Post-submit hook** — `after_submit` callback for CRM integrations, CPT creation, etc.
- **Custom JS events** — `simpliforms:success` and `simpliforms:error` on the wrapper element
- **Works as a plugin or theme include** — no difference in functionality

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- ACF or ACF Pro (optional — only required for the ACF field type)

---

## Files

| File | Description |
|---|---|
| `simpliforms.php` | Core plugin — form handling, DB, admin UI |
| `simpliforms-acf.php` | ACF field type — optional, requires ACF or ACF Pro |

---

## Installation

### As a plugin

1. Place both files in `/wp-content/plugins/simpliforms/`
2. Activate via **Plugins** in the WordPress admin
3. The database table is created automatically on activation

### As a theme include

```php
// functions.php
require_once get_template_directory() . '/inc/simpliforms.php';

// Optional — only if ACF is active
if ( class_exists( 'ACF' ) ) {
    require_once get_template_directory() . '/inc/simpliforms-acf.php';
}
```

### As a plugin dependency (recommended)

If Simpli Forms lives in its own plugin and ACF may or may not be present:

```php
// my-plugin.php (main plugin file)
require_once plugin_dir_path( __FILE__ ) . 'simpliforms.php';

if ( class_exists( 'ACF' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'simpliforms-acf.php';
}

add_action( 'init', function () {
    if ( class_exists( 'ACF' ) ) {
        simpliforms_acf_autoregister();
    }
} );
```

The `class_exists( 'ACF' )` check covers both the free and Pro versions of ACF.

---

## File Structure

```
theme/
└── forms/
    ├── contact.html
    ├── quote.html
    └── emails/
        ├── contact-notification.php
        └── contact-auto-response.php
```

The forms and emails directories can live anywhere — the path is configured per field when using the ACF field type, or passed directly when using code.

---

## Usage — Two Approaches

Simpli Forms can be configured in code (always available) or through the ACF backend UI (when ACF is active). Both approaches work identically at runtime.

---

## Approach 1 — Code

Best when you want full control in PHP, or when ACF is not available.

### 1. Write your HTML form

Create a plain HTML file. The only requirement is that every input, select, and textarea has a `name` attribute.

```html
<form method="post" novalidate>

    <div class="form-row">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required>
    </div>

    <div class="form-row">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required>
    </div>

    <div class="form-row">
        <label for="message">Message</label>
        <textarea id="message" name="message" rows="5" required></textarea>
    </div>

    <button type="submit">Send Message</button>

</form>
```

Simpli Forms automatically injects the WordPress nonce, honeypot field, and AJAX routing hidden field before rendering. You do not need to add `action`, `method`, or any hidden fields.

### 2. Register in `functions.php`

> **Important:** Always register forms inside `add_action('init', ...)` — never directly in a page template or shortcode callback.
>
> WordPress AJAX requests (`admin-ajax.php`) bootstrap WordPress but never render front-end templates. If `new SimpliForm()` only runs during a normal page load, the form is not registered when the AJAX submission arrives and every submit will fail.

```php
// functions.php

add_action( 'init', function () {

    $GLOBALS['simpliforms']['contact'] = new SimpliForm( 'contact', [
        'template' => get_template_directory() . '/forms/contact.html',
        'email' => [
            'to'      => 'hello@yourdomain.com.au',
            'subject' => 'New enquiry from {{name}}',
        ],
        'auto_response' => [
            'enabled'  => true,
            'to_field' => 'email',
            'subject'  => 'Thanks for getting in touch!',
            'template' => get_template_directory() . '/forms/emails/contact-auto-response.php',
        ],
    ] );

} );
```

### 3. Render in your template

```php
echo $GLOBALS['simpliforms']['contact']->render();
```

---

## Approach 2 — ACF Field Type

Best when clients or content editors need to configure forms, choose templates, or write email content without touching code.

Requires ACF or ACF Pro to be installed and active.

### 1. Load the ACF field file

```php
// functions.php or your plugin's main file
if ( class_exists( 'ACF' ) ) {
    require_once plugin_dir_path( __FILE__ ) . 'simpliforms-acf.php';
}
```

### 2. Register on init

```php
add_action( 'init', function () {
    if ( class_exists( 'ACF' ) ) {
        simpliforms_acf_autoregister();
    }
} );
```

`simpliforms_acf_autoregister()` scans all published pages and posts for Simpli Form fields and registers them automatically. On most sites this is the right choice.

If you prefer to register only a specific post or options page:

```php
add_action( 'init', function () {
    // From a specific page ID
    $value = get_field( 'contact_form', 42 );
    if ( $value ) simpliforms_register_from_acf( $value );

    // From an ACF options page
    $value = get_field( 'contact_form', 'options' );
    if ( $value ) simpliforms_register_from_acf( $value );
} );
```

### 3. Add the field in ACF

In the ACF field group editor, add a field of type **Simpli Form**. In the field settings, configure:

| Setting | Description | Example |
|---|---|---|
| Forms Directory | Path to HTML templates, relative to theme root | `forms` |
| Emails Directory | Path to PHP email templates, relative to theme root | `forms/emails` |

### 4. Configure the form in the backend

On any post or page edit screen the field renders four sections:

**General**
- Form Template — dropdown populated from your forms directory (`.html` files)
- Form ID — auto-filled from the template filename, can be overridden
- Success / Error messages
- Log submissions toggle

**Notification Email**
- To, From Name, From Email, Subject (supports `{{tokens}}`), Reply-To Field
- Template mode: **Default** (auto-generated table), **PHP File** (select from emails directory), or **Visual Editor** (WYSIWYG with `{{token}}` support)

**Auto-Response**
- Enable toggle
- Recipient Field, Subject
- Template mode: Default, PHP File, or Visual Editor

**Spam & Security**
- WordPress Nonce, Honeypot, Rate Limit

### 5. Render in your template

```php
// If you know the form ID:
echo $GLOBALS['simpliforms']['contact']->render();

// Or read it dynamically from the field value:
$config = get_field( 'contact_form' );
if ( $config ) {
    echo $GLOBALS['simpliforms'][ $config['form_id'] ]->render();
}
```

---

## Configuration Reference

All options for the code-based approach. The ACF field type exposes the same options through the UI.

```php
new SimpliForm( 'form-id', [

    // Required: absolute path to your HTML form template
    'template' => get_template_directory() . '/forms/contact.html',

    // Log submissions to the database (default: true)
    'log' => true,

    // Messages shown to the user after submission
    'success_message' => 'Thank you! Your message has been sent.',
    'error_message'   => 'Something went wrong. Please try again.',

    // Notification email sent to you
    'email' => [
        'to'             => 'hello@yourdomain.com.au',    // recipient
        'subject'        => 'New enquiry from {{name}}',  // supports {{tokens}}
        'template'       => get_template_directory() . '/forms/emails/notification.php',
        'inline_html'    => '<p>Hi, {{name}} just submitted...</p>', // alternative to template
        'reply_to_field' => 'email',         // field name to use as Reply-To header
        'from_name'      => 'My Site',       // defaults to get_bloginfo('name')
        'from_email'     => 'noreply@yourdomain.com.au',  // defaults to admin_email
    ],

    // Auto-response email sent to the person who submitted
    'auto_response' => [
        'enabled'     => true,
        'to_field'    => 'email',            // which field holds the recipient address
        'subject'     => 'Thanks, {{name}}!',
        'template'    => get_template_directory() . '/forms/emails/auto-response.php',
        'inline_html' => '<p>Thanks {{name}}, we\'ll be in touch.</p>', // alternative to template
    ],

    // Spam protection — all layers enabled by default
    'spam' => [
        'honeypot'   => true,  // hidden field bots fill in
        'nonce'      => true,  // WordPress nonce verification
        'rate_limit' => 5,     // max submissions per hour per IP; 0 to disable
    ],

    // Custom server-side validation
    // Return true to allow, or WP_Error to reject with a visible message
    'before_submit' => function ( array $fields ) {
        if ( strlen( $fields['message'] ?? '' ) < 10 ) {
            return new WP_Error( 'validation', 'Please enter a longer message.' );
        }
        return true;
    },

    // Runs after a successful submission
    // $submission_id is the DB row ID, or 0 if logging is disabled
    'after_submit' => function ( array $fields, int $submission_id ) {
        // Connect to a CRM, create a CPT, subscribe to a list, etc.
    },

] );
```

For the `email` and `auto_response` blocks, `template` (PHP file path), `inline_html` (raw HTML string), and the default auto-generated table are mutually exclusive. `template` takes priority, then `inline_html`, then the default fallback.

### Multiple forms

```php
add_action( 'init', function () {

    $GLOBALS['simpliforms']['contact'] = new SimpliForm( 'contact', [
        'template' => get_template_directory() . '/forms/contact.html',
        'email'    => [ 'to' => 'hello@yourdomain.com.au' ],
    ] );

    $GLOBALS['simpliforms']['quote'] = new SimpliForm( 'quote-request', [
        'template' => get_template_directory() . '/forms/quote.html',
        'email'    => [ 'to' => 'quotes@yourdomain.com.au' ],
    ] );

} );
```

The form ID is used as the AJAX routing key, the database identifier, and the admin tab label. Use a short descriptive slug with no spaces.

---

## Email Templates

If no template is provided, Simpli Forms sends a clean HTML table email automatically. There are three ways to provide a custom template, in order of priority:

**1. PHP file** — full control, variables available in scope:

| Variable | Type | Description |
|---|---|---|
| `$fields` | `array` | All submitted values, keyed by field `name` attribute |
| `$form_fields` | `array` | Alias of `$fields`, useful for loops alongside extracted vars |
| `$form_id` | `string` | The form slug, e.g. `contact` |
| `$form_label` | `string` | Prettified label, e.g. `Contact` |
| `$name`, `$email`, … | `string` | Each field extracted as its own variable, already `esc_html`'d |

```php
<!-- forms/emails/contact-notification.php -->
<!DOCTYPE html>
<html>
<body>
    <h2>New enquiry from <?php echo $name; ?></h2>
    <p><strong>Email:</strong> <?php echo $email; ?></p>
    <p><strong>Message:</strong><br><?php echo nl2br( $message ); ?></p>
</body>
</html>
```

**2. Inline HTML** — set `inline_html` in config, or written via the ACF Visual Editor. Supports `{{token}}` replacement:

```html
<p>Hi, you have a new message from <strong>{{name}}</strong> ({{email}}).</p>
<p>{{message}}</p>
```

**3. Default** — auto-generated HTML table of all submitted fields. No configuration needed.

### Token replacement in subject lines

Both notification and auto-response subject lines support `{{field_name}}` tokens:

```php
'subject' => 'New enquiry from {{name}} — {{project_type}}',
```

Tokens are resolved from submitted field values. Unrecognised tokens are replaced with an empty string.

---

## Spam Protection

All three layers are enabled by default. Any layer can be disabled individually.

### Nonce

A WordPress nonce is injected as a hidden field and verified server-side before any other processing runs. Confirms the request originated from your site within the current session.

### Honeypot

A visually hidden input is injected into the form (positioned off-screen, `tabindex="-1"`, `autocomplete="off"`). Legitimate users never see or interact with it. When a bot fills it in, the submission silently returns a success response so the bot has no signal to retry.

### Rate limiting

Uses WordPress transients keyed by form ID and hashed client IP. Once a visitor hits the configured limit within a rolling hour window, further submissions are rejected. The counter resets automatically after one hour. Set `rate_limit` to `0` to disable.

```php
'spam' => [
    'rate_limit' => 10,
],
```

---

## Frontend Integration

### CSS hooks

```css
.simpliforms-wrapper   { /* outer container div */ }
.simpliforms-response  { /* message element, empty until a submission is made */ }
.simpliforms-success   { /* added to .simpliforms-response on success */ }
.simpliforms-error     { /* added to .simpliforms-response on error */ }
.simpliforms-loading   { /* added to .simpliforms-wrapper while the request is in-flight */ }
```

Example with Tailwind CSS:

```css
@layer components {
    .simpliforms-response {
        @apply mt-4 rounded-lg p-4 text-sm font-medium empty:hidden;
    }
    .simpliforms-success {
        @apply bg-green-50 text-green-800 ring-1 ring-green-200;
    }
    .simpliforms-error {
        @apply bg-red-50 text-red-800 ring-1 ring-red-200;
    }
    .simpliforms-loading form {
        @apply pointer-events-none opacity-60;
    }
}
```

### JavaScript events

```js
const wrapper = document.querySelector('#simpliforms-contact');

wrapper.addEventListener('simpliforms:success', function (e) {
    console.log('Submitted:', e.detail);
    dataLayer.push({ event: 'form_submit', form_id: 'contact' });
});

wrapper.addEventListener('simpliforms:error', function (e) {
    console.log('Error:', e.detail.message);
});
```

The wrapper element ID is always `simpliforms-{form-id}`, e.g. `simpliforms-contact`.

---

## Admin — Submission Log

After activating the plugin, a **Simpli Forms** entry appears in the WordPress admin sidebar with an unread count bubble.

### Submissions list

- Tabs across the top filter by form ID, each showing total and unread count
- Unread submissions displayed in bold with a blue "new" badge
- Each row shows a preview of the first three fields, IP address, date, and status
- Bulk delete via the checkbox column

### Single submission view

Clicking **View** opens a full detail page showing every submitted field, the submission date, IP address, and browser user agent. Opening a submission automatically marks it as read.

### Statuses

| Status | Meaning |
|---|---|
| `new` | Received but not yet viewed in the admin |
| `read` | Automatically set when the detail view is opened |

---

## Database

Simpli Forms creates one custom table: `{prefix}simpliforms_submissions`.

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Auto-increment primary key |
| `form_id` | `VARCHAR(100)` | The form slug |
| `submitted_at` | `DATETIME` | Submission timestamp (site timezone) |
| `ip_address` | `VARCHAR(45)` | Client IP address (IPv4 or IPv6) |
| `user_agent` | `TEXT` | Browser user-agent string |
| `fields` | `LONGTEXT` | All submitted field values as a JSON object |
| `status` | `VARCHAR(20)` | `new` or `read` |

The table is created or updated via `dbDelta` on plugin activation, and re-checked on every `init` if the version option is out of date. Safe to run across updates without risk of data loss.

---

## Version History

### 1.0.3
- **Fix:** — Errant definition broke the updater

### 1.0.2
- **New:** `simpliforms-acf.php` — ACF field type for full backend configuration of forms, email settings, and email templates (including a WYSIWYG visual editor)
- **New:** `simpliforms_register_from_acf()` — converts an ACF field value into a registered `SimpliForm` instance
- **New:** `simpliforms_acf_autoregister()` — scans all published pages and posts and registers any Simpli Form fields found
- **New:** `inline_html` option on `email` and `auto_response` config blocks — raw HTML email body with `{{token}}` support, used by the ACF Visual Editor mode

### 1.0.1
- **Fix:** Forms must be registered inside `add_action('init', ...)` and stored in `$GLOBALS`. Instantiating in a page template meant the form was never registered during AJAX requests, causing all submissions to fail.
- **Fix:** The fetch error handler now always parses the JSON response body regardless of HTTP status code, so server-side error messages are shown to the user correctly instead of a generic network error.

### 1.0.0
Initial release.

- `SimpliForm` — form rendering and AJAX processing
- `SimpliForm_DB` — custom submissions table
- `SimpliForm_Admin` — WP admin submissions UI
- Nonce, honeypot, and rate-limit spam protection
- PHP email templates with variable extraction
- `{{token}}` subject line replacement
- `before_submit` / `after_submit` callbacks
- `simpliforms:success` / `simpliforms:error` JS events