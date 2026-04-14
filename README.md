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
- **WordPress admin UI** — tabbed by form, unread badge, bulk actions, paginated list, single detail view
- **ACF field type** — configure forms entirely from the WordPress backend (requires ACF or ACF Pro)
- **Custom validation** — `before_submit` callback returning `true` or `WP_Error`
- **Post-submit hook** — `after_submit` callback for CRM integrations, CPT creation, etc.
- **WordPress hooks & filters** — full extensibility for third-party integrations
- **Translatable** — all strings wrapped with `__()`, text domain `simpliforms`
- **Custom JS events** — `simpliforms:success` and `simpliforms:error` on the wrapper element
- **Works as a plugin or theme include** — no difference in functionality

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- ACF or ACF Pro (optional — only required for the ACF field type)

---

## File Structure

```
simpliforms/
├── simpliforms.php          # Plugin header, constants, bootstrap
├── github-updater.php       # GitHub auto-updater
├── acf-field.php            # ACF field type (optional, requires ACF or ACF Pro)
├── includes/
│   ├── class-db.php         # SimpliForm_DB — database layer
│   ├── class-form.php       # SimpliForm — form registration, rendering, processing
│   └── class-admin.php      # SimpliForm_Admin — WordPress admin UI
└── languages/               # Translation .po/.mo files go here
```

---

## Installation

### As a plugin

1. Place the `simpliforms/` folder in `/wp-content/plugins/`
2. Activate via **Plugins** in the WordPress admin
3. The database table is created automatically on activation

### As a theme include

```php
// functions.php
require_once get_template_directory() . '/inc/simpliforms/simpliforms.php';
```

### As a plugin dependency (recommended)

```php
// my-plugin.php
require_once plugin_dir_path( __FILE__ ) . 'simpliforms/simpliforms.php';

add_action( 'init', function () {
    if ( class_exists( 'ACF' ) ) {
        simpliforms_acf_autoregister();
    }
} );
```

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

Requires ACF or ACF Pro. Best for client sites where forms need to be configured without code.

### 1. Load the ACF field file

The file is included automatically by the main plugin if ACF is active. When using as a theme include:

```php
if ( class_exists( 'ACF' ) ) {
    require_once get_template_directory() . '/inc/simpliforms/acf-field.php';
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

`simpliforms_acf_autoregister()` scans all published pages and posts for Simpli Form fields and registers them automatically.

For specific posts or an options page:

```php
add_action( 'init', function () {
    $value = get_field( 'contact_form', 42 );          // post ID
    if ( $value ) simpliforms_register_from_acf( $value );

    $value = get_field( 'contact_form', 'options' );   // ACF options page
    if ( $value ) simpliforms_register_from_acf( $value );
} );
```

### 3. Add the field in ACF

Add a field of type **Simpli Form** in the ACF field group editor. Configure:

| Setting | Description | Example |
|---|---|---|
| Forms Directory | Path to HTML templates, relative to theme root | `forms` |
| Emails Directory | Path to PHP email templates, relative to theme root | `forms/emails` |

### 4. Configure the form in the backend

Four sections appear on the post/page edit screen:

**General** — Form Template, Form ID, Success/Error messages, Log submissions toggle

**Notification Email** — To, From Name, From Email, Subject (supports `{{tokens}}`), Reply-To Field, Template mode (Default / PHP File / Visual Editor)

**Auto-Response** — Enable toggle, Recipient Field, Subject, Template mode

**Spam & Security** — WordPress Nonce, Honeypot, Rate Limit

### 5. Render in your template

```php
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
        'reply_to_field' => 'email',           // field name to use as Reply-To header
        'from_name'      => 'My Site',         // defaults to get_bloginfo('name')
        'from_email'     => 'noreply@yourdomain.com.au', // defaults to admin_email
    ],

    // Auto-response email sent to the person who submitted
    'auto_response' => [
        'enabled'     => true,
        'to_field'    => 'email',              // which field holds the recipient address
        'subject'     => 'Thanks, {{name}}!',
        'template'    => get_template_directory() . '/forms/emails/auto-response.php',
        'inline_html' => '<p>Thanks {{name}}, we\'ll be in touch.</p>',
    ],

    // Spam protection — all layers enabled by default
    'spam' => [
        'honeypot'   => true,  // hidden field bots fill in
        'nonce'      => true,  // WordPress nonce verification
        'rate_limit' => 5,     // max submissions per hour per IP; 0 to disable
    ],

    // Custom server-side validation — return true to allow, WP_Error to reject
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

---

## Hooks & Filters Reference

Simpli Forms exposes a complete set of WordPress hooks so you can extend or integrate without modifying plugin files.

### Filters

#### `simpliforms_form_config`
Modify a form's fully-merged config before it is stored. Runs once at instantiation.

```php
add_filter( 'simpliforms_form_config', function ( array $config, string $form_id ): array {
    if ( $form_id === 'contact' ) {
        $config['spam']['rate_limit'] = 10;
    }
    return $config;
}, 10, 2 );
```

#### `simpliforms_render`
Filter the final rendered HTML wrapper before it is returned to the template.

```php
add_filter( 'simpliforms_render', function ( string $html, string $form_id ): string {
    return '<div class="my-form-outer">' . $html . '</div>';
}, 10, 2 );
```

#### `simpliforms_collected_fields`
Modify, add, or remove submitted fields after sanitisation but before validation and emails.

```php
add_filter( 'simpliforms_collected_fields', function ( array $fields, string $form_id ): array {
    // Combine first/last name into a single field
    if ( isset( $fields['first_name'], $fields['last_name'] ) ) {
        $fields['name'] = $fields['first_name'] . ' ' . $fields['last_name'];
    }
    return $fields;
}, 10, 2 );
```

#### `simpliforms_is_spam`
Inject a custom spam check. Return `true` to silently discard the submission.

```php
add_filter( 'simpliforms_is_spam', function ( bool $is_spam, array $fields, string $form_id ): bool {
    // Reject if message contains a known spam phrase
    if ( str_contains( $fields['message'] ?? '', 'buy cheap' ) ) {
        return true;
    }
    return $is_spam;
}, 10, 3 );
```

#### `simpliforms_notification_subject` / `simpliforms_notification_body` / `simpliforms_notification_headers`
Modify the outbound notification email.

```php
add_filter( 'simpliforms_notification_subject', function ( string $subject, string $form_id, array $fields ): string {
    return '[' . get_bloginfo( 'name' ) . '] ' . $subject;
}, 10, 3 );

add_filter( 'simpliforms_notification_headers', function ( array $headers, string $form_id, array $fields ): array {
    $headers[] = 'Bcc: archive@mysite.com.au';
    return $headers;
}, 10, 3 );
```

#### `simpliforms_auto_response_subject` / `simpliforms_auto_response_body` / `simpliforms_auto_response_headers`
Same signature as above, but applied to the auto-response email.

#### `simpliforms_admin_per_page`
Override the number of submissions shown per page in the admin (default `20`).

```php
add_filter( 'simpliforms_admin_per_page', fn() => 50 );
```

#### `simpliforms_admin_row_actions`
Add or remove row action links on the submissions list table.

```php
add_filter( 'simpliforms_admin_row_actions', function ( array $actions, array $row ): array {
    $export_url = admin_url( 'admin.php?page=simpliforms&sf_export=' . $row['id'] );
    $actions['export'] = '<a href="' . esc_url( $export_url ) . '">Export</a>';
    return $actions;
}, 10, 2 );
```

---

### Actions

#### `simpliforms_form_registered`
Fires immediately after a new `SimpliForm` instance is added to the registry.

```php
add_action( 'simpliforms_form_registered', function ( string $form_id, SimpliForm $instance ) {
    error_log( "SimpliForm registered: {$form_id}" );
}, 10, 2 );
```

#### `simpliforms_before_submit`
Fires after all built-in spam checks pass, before field collection. You can call `wp_send_json_error()` here to halt processing.

```php
add_action( 'simpliforms_before_submit', function ( string $form_id, array $raw_post ) {
    // Log all raw POST data before it's processed
}, 10, 2 );
```

#### `simpliforms_submission_saved`
Fires after the submission is written to the database.

```php
add_action( 'simpliforms_submission_saved', function ( int $submission_id, string $form_id, array $fields ) {
    // Trigger a webhook, update a CPT, etc.
}, 10, 3 );
```

#### `simpliforms_notification_sent` / `simpliforms_auto_response_sent`
Fires after the respective email has been dispatched via `wp_mail()`.

```php
add_action( 'simpliforms_notification_sent', function ( string $form_id, array $fields ) {
    // Log that the notification was sent
}, 10, 2 );
```

#### `simpliforms_after_submit`
Fires at the very end of a successful submission, after all callbacks and emails. The complement to the `after_submit` config callback — useful when you don't control the form config directly.

```php
add_action( 'simpliforms_after_submit', function ( string $form_id, array $fields, int $submission_id ) {
    if ( $form_id === 'contact' ) {
        my_crm_sync( $fields, $submission_id );
    }
}, 10, 3 );
```

#### `simpliforms_admin_before_single` / `simpliforms_admin_after_single`
Fires before/after the single-submission detail card is rendered.

```php
add_action( 'simpliforms_admin_after_single', function ( array $row ) {
    echo '<div class="my-custom-panel">...</div>';
} );
```

---

## Email Templates

Three ways to provide a custom email body, in order of priority:

**1. PHP file** — full control, variables available in scope:

| Variable | Type | Description |
|---|---|---|
| `$fields` | `array` | All submitted values, keyed by field `name` |
| `$form_fields` | `array` | Alias of `$fields`, useful alongside extracted vars |
| `$form_id` | `string` | The form slug, e.g. `contact` |
| `$form_label` | `string` | Prettified label, e.g. `Contact` |
| `$name`, `$email`, … | `string` | Each field extracted as its own variable (already `esc_html`'d) |

**2. Inline HTML** — set `inline_html` in config, or use the ACF Visual Editor. Supports `{{token}}` replacement:

```html
<p>Hi, you have a new message from <strong>{{name}}</strong> ({{email}}).</p>
<p>{{message}}</p>
```

**3. Default** — auto-generated HTML table of all submitted fields. No configuration needed.

---

## Spam Protection

All three layers are enabled by default.

### Nonce
A WordPress nonce is injected and verified server-side before any other processing.

### Honeypot
A visually hidden input is injected (off-screen, `tabindex="-1"`, `autocomplete="off"`). When a bot fills it in, the submission silently succeeds so the bot has no signal to retry.

### Rate limiting
Uses WordPress transients keyed by form ID and hashed client IP. Resets after one hour. Set `rate_limit` to `0` to disable.

---

## Frontend Integration

### CSS hooks

```css
.simpliforms-wrapper   { /* outer container div */ }
.simpliforms-response  { /* message element */ }
.simpliforms-success   { /* added on success */ }
.simpliforms-error     { /* added on error */ }
.simpliforms-loading   { /* added to wrapper during request */ }
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

After activating, a **Simpli Forms** entry appears in the WordPress admin sidebar with an unread count bubble.

### Submissions list

- Tabs filter by form ID, each showing total and unread count
- Unread submissions displayed in bold with a "New" badge
- Each row shows a preview of the first three fields, IP address, date, and status
- Bulk actions: Mark as read, Mark as unread, Delete
- Date range filter

### Single submission view

Clicking **View** opens a full detail page with every submitted field, date, IP address, and browser user agent. Opening a submission automatically marks it as read.

### Statuses

| Status | Meaning |
|---|---|
| `new` | Received but not yet viewed |
| `read` | Automatically set when the detail view is opened |

---

## Database

One custom table: `{prefix}simpliforms_submissions`

| Column | Type | Description |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Auto-increment primary key |
| `form_id` | `VARCHAR(100)` | The form slug |
| `submitted_at` | `DATETIME` | Submission timestamp (site timezone) |
| `ip_address` | `VARCHAR(45)` | Client IP (IPv4 or IPv6) |
| `user_agent` | `TEXT` | Browser user-agent string |
| `fields` | `LONGTEXT` | All submitted field values as JSON |
| `status` | `VARCHAR(20)` | `new` or `read` |

Created or updated via `dbDelta` on activation and re-checked on every `init` if the version option is out of date.

---

## Translations

All user-facing strings use the `simpliforms` text domain. Place `.po`/`.mo` files in the `languages/` directory.

Frontend JavaScript strings are localised via `wp_localize_script` and available under `SimpliForms.i18n`. Admin JavaScript strings are available under `SimpliFormsAdmin.i18n`.

To generate a `.pot` file:

```bash
wp i18n make-pot . languages/simpliforms.pot
```

---

## Version History

### 1.1.0
- **New:** All user-facing strings are now translatable (`simpliforms` text domain, `Text Domain` header corrected from `translate`)
- **New:** Frontend JS strings localised via `wp_localize_script` (`SimpliForms.i18n`)
- **New:** Admin JS strings localised via `SimpliFormsAdmin.i18n`
- **New:** Filters — `simpliforms_form_config`, `simpliforms_render`, `simpliforms_collected_fields`, `simpliforms_is_spam`, `simpliforms_notification_subject/body/headers`, `simpliforms_auto_response_subject/body/headers`, `simpliforms_admin_per_page`, `simpliforms_admin_row_actions`
- **New:** Actions — `simpliforms_form_registered`, `simpliforms_before_submit`, `simpliforms_submission_saved`, `simpliforms_notification_sent`, `simpliforms_auto_response_sent`, `simpliforms_after_submit`, `simpliforms_admin_before_single`, `simpliforms_admin_after_single`
- **New:** Plugin split into `includes/class-db.php`, `includes/class-form.php`, `includes/class-admin.php` — `simpliforms.php` is now the bootstrap only
- **New:** `SIMPLIFORMS_VERSION`, `SIMPLIFORMS_DIR`, `SIMPLIFORMS_FILE` constants defined in bootstrap
- **New:** `languages/` directory added

### 1.0.3
- **Fix:** Errant definition broke the updater

### 1.0.2
- **New:** `acf-field.php` — ACF field type for full backend configuration
- **New:** `simpliforms_register_from_acf()` — converts an ACF field value into a registered `SimpliForm` instance
- **New:** `simpliforms_acf_autoregister()` — scans all published pages/posts and registers any Simpli Form fields found
- **New:** `inline_html` option on `email` and `auto_response` config blocks

### 1.0.1
- **Fix:** Forms must be registered inside `add_action('init', ...)` and stored in `$GLOBALS`
- **Fix:** Fetch handler now parses JSON response regardless of HTTP status code

### 1.0.0
Initial release.