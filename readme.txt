=== PartJoo Product Sync ===
Contributors: partjoo
Tags: woocommerce, products, sync, api
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce products to PartJoo search engine via API v1.2, with change tracking, logs, deletion handling, and WP-CLI.

== Description ==
- API v1.2 payload (`route=crawler/addProductsToPartjoo`).
- Sends only changed products using content signatures; Force resend available.
- Batch sending (max 100).
- Handles stock/price events and deletions (tombstone -> availability -1).
- Optional API key header `X-PartJoo-Key` (if provided).
- Admin UI, logs table, last status, and WP-CLI (`wp partjoo sync`).
- Multisite compatible.

== Installation ==
1. Install and activate WooCommerce.
2. Upload the plugin ZIP and activate.
3. Go to WooCommerce → PartJoo Sync: set **Assigned Domain** and other preferences.
4. Use "Sync CHANGED products" to push data, or rely on cron.

== Frequently Asked Questions ==
= What is the "Assigned Domain"? =
The exact domain PartJoo uses to identify your site in the index.

= How are prices handled? =
If "Convert Toman → Rial" is enabled, prices are multiplied by 10 and unit is set to "rial".

== Changelog ==
= 1.3.0 =
* Added deletion handling (tombstones)
* Added stock/price event hooks
* Added optional API key support
* Added cron recurrence setting
* I18n boilerplate + readme

= 1.2.0 =
* Initial public release with change tracking and logs
