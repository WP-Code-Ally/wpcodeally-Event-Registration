=== WPCodeAlly Event Registration ===
Contributors: ono
Tags: woocommerce, gravity-forms, events, registration, checkout
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Per-product registration pages, Gravity Forms linked to orders, checkout redirect, and My Account editing.

== Description ==

**Per product (Products → Edit → General → Event registration)**

* **Registration page** or **URL** — where that product’s customers go after checkout (same idea as pointing Custom Thank You at a page).
* **Gravity Forms form ID** — must match the form embedded on that page so submissions can link to the order line.

**You do not need `[WPCA_ER_registration]`** if the page already embeds Gravity Forms the normal way (Gutenberg block or `[gravityform]` shortcode). The plugin still adds a secure hidden field to the form when the URL contains `order`, `key`, and (when needed) `ono_item`.

**Optional `[WPCA_ER_registration]` shortcode** — useful for a single “default” landing page that loads the correct form from the order, or for the optional **WooCommerce → Event registration** default page.

**WooCommerce → Event registration** — optional default page when a product has a form ID but no per-product page.

**My Account → Event registrations** — list, complete, and edit flows (endpoint is registered with WooCommerce query vars so the tab and URLs behave like core account pages). After updating the plugin, visit the dashboard once so rewrite rules can refresh automatically.

**My Account → Orders** — for each line item that has a registration form ID, extra buttons appear in the **Actions** column when the order is Processing, Completed, or On hold: **Event registration** (not submitted yet) or **Edit registration** (already linked). If a product has a form ID but no registration page or global hub, the link opens **My Account → Event registrations** with `order` and `key` so the correct form still loads there.

If your theme replaces the orders “Actions” column and those buttons never appear, add `add_filter( 'wpca_er_append_registration_links_orders_actions_column', '__return_true' );` in a small custom plugin (may duplicate buttons if the theme already prints WooCommerce actions—disable if that happens).

**Order summary on the form confirmation** — after a successful submit, when the form uses a **Text** confirmation (not a redirect), a short WooCommerce order recap is appended so customers see their order details with the thank-you message. Linkage is saved on `gform_entry_post_save` so the recap is available during the same request as the confirmation. Developers can disable it with the `wpca_er_show_order_summary_on_confirmation` filter.

== Installation ==

1. Activate the plugin.
2. On each event product, set **Registration page** (or URL) and the **Gravity Forms form ID** that matches the form on that page.
3. **Settings → Permalinks → Save** once.
4. Optionally configure **WooCommerce → Event registration** for fallback-only use.

== Frequently Asked Questions ==

= Do I need a special shortcode on my Gravity Forms page? =

No. Use your form block or `[gravityform id="…"]` as today. Keep the **form ID on the product** the same as the form on the page.

= Uninstall =

Removes options `WPCA_ER_debug`, `WPCA_ER_registration_page_id`, and `WPCA_ER_internal_version`, and deletes the `wp-content/wpcodeally-event-registration.log` file. Does not remove product or order line meta.

= Why is there no order block after submit? =

The recap is only added for **Text** confirmations. **Redirect** or **Page** confirmations cannot be extended the same way; use a thank-you page that shows order details instead.

== Changelog ==

= 1.0.4 =
* Clicking **Event registration** on an order that already has a GF entry now opens the form pre-populated for editing instead of showing a blank form.
* Retroactive entry linking: if a GF entry was submitted before form-ID auto-detection was available, the plugin searches GF entry meta and writes the missing `_wpca_er_entry_id` on the order item so the button correctly shows **Edit registration**.
* The "already submitted" notice no longer replaces the form; the form stays editable with a helpful info banner above it.
* Pre-population applies on both the registration page (`?order=&key=&ono_item=`) and the My Account edit flow.

= 1.0.3 =
* When only a Gravity Forms ID is set (no registration page / hub), registration URLs fall back to **My Account → Event registrations**; that endpoint now renders the form when `order` and `key` are present.
* Optional `wpca_er_append_registration_links_orders_actions_column` (default false) for themes that override the orders actions column without using WooCommerce’s action list.

= 1.0.2 =
* Add **Event registration** / **Edit registration** actions to the My Account **Orders** table (`wpca_er_my_account_order_registration_actions` filter).
* Share `WPCA_ER_Product_Admin::get_registration_form_id_for_order_item()` for consistent form-ID resolution.

= 1.0.1 =
* Register the **Event registrations** My Account tab via `woocommerce_get_query_vars` so WooCommerce recognizes the endpoint; admin version check flushes rewrite rules once after upgrade.
* Show order date and formatted order total on the registrations table; clearer empty-state guidance.

= 1.0.0 =
* Per-product landing, GF linkage, checkout redirect, My Account UI, optional shortcode.
* Append WooCommerce order summary to Gravity Forms **Text** confirmations when the submission is tied to an order (`wpca_er_show_order_summary_on_confirmation` filter).
