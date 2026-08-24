=== AM Popularity Rank for WooCommerce ===
Contributors: aukejomm
Requires at least: 5.6
Tested up to: 6.8
Requires PHP: 7.0
WC requires at least: 6.0
WC tested up to: 9.9
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Calculates a 0.0–100.0 popularity rank score per product from recent order
data and stores it in product meta (_am_popularity_rank_score) for use in feed
exports such as Google Merchant Center.

== Description ==

WooCommerce stores have sales data but no normalized popularity score you can
export as a product feed attribute. This plugin fills that gap.

It reads recent orders, calculates a recent-sales score per product, and saves
a value from 0.0 to 100.0 in product meta under `_am_popularity_rank_score`. Your
feed plugin can then map that meta key to a feed attribute such as Google
Merchant Center's popularity_rank.

This is a calculation tool only. It does NOT add a settings page or a product
edit field. After activation it runs itself once a day in the background via
WP-Cron, so there is nothing to click and nothing to run by hand. It never runs
during a normal page load, so it does not slow down your storefront or admin.

How the score works:

1. Reads orders from the last 90 days (configurable), counting only
   processing, completed, and on-hold orders as sales.
2. Sums recent net revenue and quantity sold per product (variation sales
   roll up into the parent product).
3. Normalizes both metrics across the catalog and blends them: 70% revenue,
   30% quantity.
4. Percentile-ranks the result so your best seller is 100.0 and everything
   else is relative to it, rounded to one decimal.
5. Saves the value using WooCommerce product methods (no direct post meta),
   so hooks and caches stay consistent.

Built for large catalogs. When WooCommerce Analytics is enabled (the default),
the plugin reads its lookup tables with a single aggregate SQL query instead of
loading thousands of order objects, so it stays fast and light on memory. If
Analytics is unavailable or not yet populated, it automatically falls back to a
batched, HPOS-safe scan of order objects (works with High-Performance Order
Storage and legacy order tables), flushing caches between batches to keep
memory flat.

== Installation ==

1. Copy the `am-popularity-rank` folder into `wp-content/plugins/`, OR zip the
   folder and upload it via Plugins → Add New → Upload Plugin.
2. Activate "AM Popularity Rank for WooCommerce" from the Plugins screen.
3. Make sure WooCommerce is active.

That is all. On activation the plugin schedules a daily background job that
recalculates every product's score automatically. The first run happens within
a few minutes (once your site next gets traffic), then once a day after that.
You do not need to run anything.

Deactivating the plugin removes the scheduled job again.

== Running it manually (optional) ==

You normally never need this, but if you want to force a recalculation right
now, you can:

Via WP-CLI (if your host provides it):

    wp popularity-rank calculate
    wp popularity-rank calculate --lookback_days=180 --log_transform
    wp popularity-rank calculate --include_unsold
    wp popularity-rank calculate --no_lookup   (force the slower per-order scan)

Via PHP (your own code or a scheduled task — never on a page load):

    $result = am_calculate_popularity_ranks();
    // => array( 'products_scored' => 42, 'orders_scanned' => 310, 'scores' => [...] )

It is safe to run repeatedly. Each run overwrites the single stored value per
product and never creates duplicate data.

== A note on WP-Cron ==

WordPress's built-in cron fires on site visits, so the daily job runs shortly
after the scheduled time as soon as someone visits your site. On a store with
normal traffic this is completely fine and requires no setup.

If your store is very large, or you want the job to run at an exact time
regardless of traffic, point a real server cron at WP-Cron instead. That is an
optional hosting-level tweak, not required for the plugin to work.

== Options ==

Pass these as WP-CLI flags or as an array to am_calculate_popularity_ranks():

* lookback_days   – Days of order history to consider. Default 90.
* order_statuses  – Which statuses count as a sale. Default processing,
                    completed, on-hold. (PHP array only.)
* revenue_weight  – Share of the score from revenue. Default 0.70.
* qty_weight      – Share of the score from quantity. Default 0.30.
* include_unsold  – Score products with no recent sales as 0.0. Default off.
* log_transform   – Log-soften revenue and quantity before normalizing, for
                    catalogs where a few products dominate. Default off.
* force_order_scan – Skip the Analytics lookup tables and scan order objects
                    instead (WP-CLI: --no_lookup). Mainly for debugging.
                    Default off.

== Reading the score back ==

Stored in product meta under `_am_popularity_rank_score` as a one-decimal string,
e.g. "72.4".

    $product = wc_get_product( $product_id );
    $score   = $product->get_meta( '_am_popularity_rank_score' );

Point your feed plugin at the `_am_popularity_rank_score` meta key to map it to a
custom feed attribute.

== Notes on the score ==

The score is relative to your current catalog and time window. It reflects how
popular a product is compared to your other products right now, which is what a
popularity feed attribute is meant to convey. Re-run on a schedule to keep the
values current.

== Changelog ==

= 1.0.1 =
* New primary data source: WooCommerce Analytics lookup tables
  (wc_order_product_lookup + wc_order_stats). Scores are now aggregated with a
  single GROUP BY query instead of loading order objects, which is far faster
  and lighter on memory on large catalogs.
* Automatic fallback to the batched, HPOS-safe order scan when Analytics is
  disabled, missing, or not yet populated, so results stay correct everywhere.
* Added the force_order_scan option (WP-CLI: --no_lookup) to force the order
  scan, for debugging and comparing the two data sources.
* The run summary and WP-CLI output now report which data source was used.
* Table-existence checks are escaped with esc_like() and all queries run
  through $wpdb->prepare().

= 1.0.0 =
* Initial release. Recent-sales popularity scoring via WooCommerce Analytics
  lookup tables (single aggregate query) with automatic fallback to a batched,
  HPOS-safe order scan. Automatic daily recalculation via WP-Cron, optional
  WP-CLI command, and optional log-transform and include-unsold modes.
