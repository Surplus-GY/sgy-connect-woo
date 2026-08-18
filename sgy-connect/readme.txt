=== Surplus GY Connect ===
Contributors: surplusgy
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bring your WooCommerce catalogue to the Surplus GY marketplace and keep it in sync.

== Description ==

Surplus GY Connect imports your WooCommerce products into your Surplus GY vendor account and keeps price,
stock and visibility in sync. It shows exactly which Surplus-specific fields are still outstanding, right in
your product editor, so you can bring products over fast and finish them at your own pace.

* One-click bulk import of your published products (they arrive as unapproved drafts on Surplus GY).
* A "Surplus GY" tab on every product with the outstanding fields to fill (dimensions, VAT, condition,
  country of manufacture, pharmacy flag) and a live status chip.
* Instant sync of price, stock, sale price and visibility after the first import.
* Two-way stock: an order on Surplus GY reduces your WooCommerce stock so you never oversell.
* A sync log with correlation ids that match the Surplus GY admin log, for easy support.

The plugin never stores Surplus GY's business rules locally: the required-field list, category options and
currency conversion all come from Surplus GY, so the plugin stays correct as the marketplace evolves.

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. In your Surplus GY vendor dashboard, open Connected Stores and create a WooCommerce connection.
3. Copy the Key ID and Secret it shows (the secret is shown only once).
4. In WordPress: Surplus GY -> Connect. Paste the Key ID and Secret, save, then click Test connection.
5. Go to Surplus GY -> Import products, map your categories, and start the import.
6. Fill any outstanding fields on each product's "Surplus GY" tab.

The Key ID prefix chooses the environment automatically: sgy_test_ connects to staging, sgy_live_ to
production. There is one plugin build for both.

== Updates ==

Updates are delivered through the plugin's built-in updater from GitHub Releases; WordPress will offer new
versions in the usual Plugins screen. To follow the staging (pre-release) channel, define
`SGY_CONNECT_BETA` as true in wp-config.php.

== Changelog ==

= 0.6.1 =
* Prevents Surplus-to-WooCommerce imports and stock updates from being sent straight back to Surplus as duplicate outbound changes.
* Restores the sync guard after an import error and keeps bulk-import progress within the reported total.
* A save with no price edit no longer rounds a converted store price back into a slightly different Surplus price.

= 0.6.0 =
* Products with options (sizes, colours) now work properly in both directions. They used to be skipped on the way up and flattened into a single product on the way down.
* Each option keeps its own price and its own stock number on Surplus GY, and a change to one option only changes that option.
* Fixes a case where saving a product with options in WooCommerce sent Surplus GY a stock figure of 999998 instead of the real numbers, because a variable product's parent holds no stock count of its own.
* A variation with a choice left as "Any" is now named in the log and left out, rather than silently producing the wrong row. Give it an exact value and import again.
* Deleting a variation in WooCommerce now takes that option to zero stock on Surplus GY rather than leaving it on sale. Nothing is deleted, because past orders point at it.

= 0.5.0 =
* Surplus GY product bundles and Custom & Engraving products are now recognised and skipped when importing from Surplus (they have no WooCommerce equivalent), with a clear "skipped" entry in the sync log and on the import screen.

= 0.1.0 =
* Initial release: connect, import, product-editor tab, instant sync, two-way stock, sync log, WP-CLI.
