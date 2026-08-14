# WPCodeAlly Event Registration

Commercial WordPress plugin that links **Gravity Forms** event registrations to **WooCommerce** orders. After checkout, customers are redirected to a per-product registration page, submissions are tied to the order line item, and customers can complete or edit registrations from My Account.

## Requirements

| Requirement | Version |
|-------------|---------|
| WordPress | 6.0+ |
| PHP | 7.4+ |
| WooCommerce | Active |
| Gravity Forms | Active |

The plugin declares compatibility with WooCommerce **HPOS** (custom order tables).

## Features

- Per-product registration page or URL after checkout
- Optional global fallback registration page (`WooCommerce → Event registration`)
- Post-checkout redirect with signed order context (`order`, `key`, `wpca_er_item`, and `wpca_er_checkout=1` on checkout return only)
- Gravity Forms validation, submission, and update flows linked to order line items
- My Account **Event registrations** endpoint with paginated list
- My Account **Orders** actions: Complete registration / Edit registration
- Optional shortcodes: `[WPCA_ER_registration]` and `[wpca_er_registration]`
- Order summary appended to Gravity Forms **Text** confirmations
- Debug logging to `wp-content/wpcodeally-event-registration.log` (no PII)
- WordPress privacy export/erase hooks for order linkage metadata

## Installation

1. Upload the plugin folder to `wp-content/plugins/` or install the zip via **Plugins → Add New**.
2. Activate **WPCodeAlly Event Registration**.
3. Ensure **WooCommerce** and **Gravity Forms** are active.
4. Visit **Settings → Permalinks → Save** once to refresh rewrite rules.

## Configuration

### Per product

Edit each event product under **Product data → General → Event registration**:

- **Registration page** or **URL** — where customers go after checkout
- **Gravity Forms form ID** — must match the form embedded on that page
- **Checkout redirect priority** — when one order contains multiple events, lower numbers redirect first

You do **not** need a special shortcode if the page already embeds Gravity Forms normally (block or `[gravityform]`).

### Global defaults

**WooCommerce → Event registration**

- **Default registration page** — used when a product has a form ID but no per-product page
- **Debug logging** — writes technical messages to `wp-content/wpcodeally-event-registration.log`

## Usage flows

### After checkout

1. Customer completes payment.
2. Plugin redirects to the winning line item’s registration landing URL (with `wpca_er_checkout=1` on checkout/gateway return).
3. On that first landing, Woo thank-you tracking runs once (`woocommerce_thankyou`, PixelYourSite, GTM4WP) without sending the customer through `/checkout/order-received/`.
4. Gravity Forms receives a signed hidden context field binding the submission to the order.

### My Account

- **Event registrations** — paginated list of event purchases and registration status
- **Orders** — per-line actions to complete or edit registrations

### Shortcode (optional)

Use on a shared landing page when you want one template to load the correct form from query args:

```
[wpca_er_registration]
```

## Developer hooks

| Hook | Type | Purpose |
|------|------|---------|
| `wpca_er_registration_order_statuses` | filter | Allowed order statuses for registration |
| `wpca_er_registrations_orders_per_page` | filter | Orders per page on My Account list (default `20`) |
| `wpca_er_checkout_redirect_candidates` | filter | Modify post-checkout redirect candidates |
| `wpca_er_show_order_summary_on_confirmation` | filter | Toggle order recap on GF text confirmations |
| `wpca_er_my_account_order_registration_actions` | filter | Modify My Account order action buttons |
| `wpca_er_append_registration_links_orders_actions_column` | filter | Force theme column fallback (default `false`) |
| `wpca_er_enqueue_frontend_styles` | filter | Force-load frontend CSS |
| `wpca_er_compat_modules` | filter | Register compatibility module class names |
| `wpca_er_compat_module_loaded` | action | Fires after a compat module boots |

## Compatibility modules

Optional third-party shims live in `includes/integrations/` and are loaded by `WPCA_ER_Compat_Loader` only when registered and active. Core WooCommerce and Gravity Forms integration remains in the main feature classes; add compat modules when a specific plugin conflict is confirmed.

Copy `includes/integrations/class-wpca-er-compat-template.php` as a starting point, or register a class from a site plugin:

```php
add_filter( 'wpca_er_compat_modules', function ( $modules ) {
	$modules[] = 'My_Custom_WPCA_ER_Compat';
	return $modules;
} );
```

## Data storage

| Location | Keys | Purpose |
|----------|------|---------|
| Product meta | `_wpca_er_gf_form_id`, `_wpca_er_registration_page`, `_wpca_er_redirect_priority` | Product configuration |
| Order meta | `_wpca_er_thankyou_emitted` | Non-PII flag that thank-you tracking already ran for this order |
| Order item meta | `_wpca_er_entry_id`, `_wpca_er_form_id` | Registration linkage |
| GF entry meta | `wpca_er_wc_order_id`, `wpca_er_wc_order_item_id` | Reverse lookup |
| Options | `WPCA_ER_debug`, `WPCA_ER_registration_page_id`, `WPCA_ER_internal_version` | Settings |

Gravity Forms entry field values remain in Gravity Forms. Uninstall removes plugin options and the debug log file only; product meta, order meta, order item meta, and GF entries are preserved.

## Debugging

1. Enable **WooCommerce → Event registration → Debug logging**.
2. Reproduce the issue.
3. Inspect `wp-content/wpcodeally-event-registration.log`.
4. Disable debug logging when finished.

Debug logs must never contain personal data. Do not share log files publicly without review.

## Privacy

The plugin registers WordPress personal data exporters and erasers under **Tools → Export/Erase Personal Data**. Export includes order-registration linkage metadata. Erasure removes WooCommerce linkage meta but **does not delete Gravity Forms entries**.

## Uninstall

On uninstall (`uninstall.php`):

- Deletes plugin options on each site (multisite-aware)
- Deletes `wp-content/wpcodeally-event-registration.log`
- Preserves WooCommerce and Gravity Forms customer records

## Development

### Setup

```bash
cd wpcodeally-event-registration
composer install
```

### Commands

```bash
composer run phpcs      # WordPress Coding Standards
composer run phpcbf     # Auto-fix coding standards where possible
composer test           # PHPUnit
composer run make-pot   # Regenerate languages/wpcodeally-event-registration.pot
```

### Distribution packaging

Exclude from commercial zip packages:

- `vendor/`
- `tests/`
- `Developer/`
- `scripts/`
- `composer.json`, `composer.lock`, `phpcs.xml.dist`, `phpunit.xml.dist`
- `.git/`

Include `languages/wpcodeally-event-registration.pot` if you ship translation templates.

## FAQ

**The Event registrations tab does not appear.**  
Visit **Settings → Permalinks → Save** once. Ensure the customer is logged in and viewing My Account.

**Registration buttons missing on Orders.**  
Some themes override the actions column. Try:

```php
add_filter( 'wpca_er_append_registration_links_orders_actions_column', '__return_true' );
```

**Legacy `ono_` query args still work.**  
v1.0.5+ prefers `wpca_er_` prefixed URLs and form fields; older bookmarks continue to work.

## Changelog

See [readme.txt](readme.txt) for the WordPress.org-style changelog.

## Support

https://www.wpcodeally.com/

## License

GPLv2 or later. See [readme.txt](readme.txt).
