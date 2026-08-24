<?php
/**
 * Plugin Name:       AM Popularity Rank for WooCommerce
 * Plugin URI:        https://wpmarketingrobot.com/
 * Description:       Calculates a 0.0–100.0 popularity rank score per product from recent order data and stores it in product meta (_am_popularity_rank_score) for use in feed exports such as Google Merchant Center. Recalculates automatically once a day; no setup required.
 * Version:           1.0.1
 * Requires at least: 5.6
 * Requires PHP:      7.0
 * Author:            WP Marketing Robot
 * Author URI:        https://wpmarketingrobot.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       am-popularity-rank
 *
 * WC requires at least: 6.0
 * WC tested up to:      9.9
 *
 * @package AM_Popularity_Rank
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

if ( ! defined( 'AM_POPULARITY_RANK_VERSION' ) ) {
	define( 'AM_POPULARITY_RANK_VERSION', '1.0.1' );
}

// Name of the scheduled (WP-Cron) event that recalculates scores.
if ( ! defined( 'AM_POPULARITY_RANK_CRON_HOOK' ) ) {
	define( 'AM_POPULARITY_RANK_CRON_HOOK', 'am_popularity_rank_recalculate' );
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}
);

if ( ! class_exists( 'AM_Popularity_Rank_Calculator' ) ) :

	/**
	 * Calculates and stores product popularity rank scores.
	 */
	class AM_Popularity_Rank_Calculator {

		/**
		 * Meta key used to store the final score.
		 *
		 * @var string
		 */
		const META_KEY = '_am_popularity_rank_score';

		/**
		 * Resolved configuration.
		 *
		 * @var array
		 */
		protected $config;

		/**
		 * Which data source the last run used: 'analytics_lookup' or 'order_scan'.
		 *
		 * @var string
		 */
		protected $last_method = '';

		/**
		 * @param array $args Optional configuration overrides.
		 */
		public function __construct( array $args = array() ) {
			$defaults = array(
				// Rolling window of order history to consider, in days.
				'lookback_days'   => 90,
				// Order statuses that count as a real sale (prefixed 'wc-').
				'order_statuses'  => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
				// Weighting of the raw score. Should sum to 1.0.
				'revenue_weight'  => 0.70,
				'qty_weight'      => 0.30,
				// When true, products with no qualifying sales are scored 0.0.
				// When false (default), unsold products are skipped entirely.
				'include_unsold'  => false,
				// When true, log-transform revenue and quantity before normalizing.
				// Softens the long-tail crush on catalogs where a few products
				// dominate sales, giving the percentile buckets more gradation.
				// Off by default so scoring behaviour is unchanged unless enabled.
				'log_transform'   => false,
				// How many orders to load per batch in the fallback order scan.
				'orders_per_page' => 200,
				// Force the slower per-order scan even when the WooCommerce
				// Analytics lookup tables are available. Mainly for debugging;
				// leave false so large catalogs use the fast SQL aggregation.
				'force_order_scan' => false,
			);

			$this->config = wp_parse_args( $args, $defaults );

			// Guard against a zero weight-sum causing division issues.
			$weight_sum = $this->config['revenue_weight'] + $this->config['qty_weight'];
			if ( $weight_sum <= 0 ) {
				$this->config['revenue_weight'] = 0.70;
				$this->config['qty_weight']     = 0.30;
			}
		}

		/**
		 * Run the full calculation: collect → rank → save.
		 *
		 * Safe to run repeatedly; each run overwrites the single stored value.
		 *
		 * @return array {
		 *     Summary of the run.
		 *
		 *     @type int    $products_scored Number of products that received a score.
		 *     @type int    $orders_scanned  Number of orders read.
		 *     @type string $method          Data source used ('analytics_lookup' or 'order_scan').
		 *     @type array  $scores          Map of product_id => score (for logging/testing).
		 * }
		 */
		public function calculate() {
			$sales  = $this->collect_sales_data( $orders_scanned );
			$scores = $this->rank_and_normalize( $sales );
			$saved  = $this->save_scores( $scores );

			return array(
				'products_scored' => $saved,
				'orders_scanned'  => (int) $orders_scanned,
				'method'          => $this->last_method,
				'scores'          => $scores,
			);
		}

		/**
		 * Collect per-product recent sales metrics.
		 *
		 * Prefers the WooCommerce Analytics lookup tables (a single aggregate
		 * SQL query, very light on memory) and falls back to a paginated scan of
		 * order objects when Analytics is unavailable or not yet populated.
		 *
		 * @param int $orders_scanned Passed by reference; set to the order count read.
		 * @return array Map of product_id => array( 'revenue' => float, 'qty' => float ).
		 */
		protected function collect_sales_data( &$orders_scanned = 0 ) {
			if ( $this->lookup_tables_available() ) {
				$this->last_method = 'analytics_lookup';
				return $this->collect_sales_data_from_lookup( $orders_scanned );
			}

			$this->last_method = 'order_scan';
			return $this->collect_sales_data_from_orders( $orders_scanned );
		}

		/**
		 * Are WooCommerce Analytics lookup tables present and populated?
		 *
		 * Requires both wc_order_product_lookup (per-item metrics) and
		 * wc_order_stats (per-order status). Returns false when 'force_order_scan'
		 * is set, when either table is missing, or when the lookup table is empty
		 * (Analytics disabled or history never synced) so we fall back cleanly.
		 *
		 * @return bool
		 */
		protected function lookup_tables_available() {
			if ( ! empty( $this->config['force_order_scan'] ) ) {
				return false;
			}

			global $wpdb;

			$lookup = $wpdb->prefix . 'wc_order_product_lookup';
			$stats  = $wpdb->prefix . 'wc_order_stats';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$has_lookup = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $lookup ) ) ) === $lookup;
			$has_stats  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $stats ) ) ) === $stats;

			if ( ! $has_lookup || ! $has_stats ) {
				return false;
			}

			// Treat a completely empty lookup table as "not usable" so a store
			// with real orders but un-synced Analytics still gets scored.
			$has_rows = $wpdb->get_var( "SELECT order_item_id FROM {$lookup} LIMIT 1" );
			// phpcs:enable

			return ! empty( $has_rows );
		}

		/**
		 * Collect per-product metrics from the Analytics lookup tables.
		 *
		 * One GROUP BY query aggregates quantity and net revenue per product over
		 * qualifying orders in the window. Grouping by lookup.product_id rolls
		 * variation sales up into their parent, matching the order-scan path.
		 *
		 * @param int $orders_scanned Passed by reference; set to the distinct order count.
		 * @return array Map of product_id => array( 'revenue' => float, 'qty' => float ).
		 */
		protected function collect_sales_data_from_lookup( &$orders_scanned = 0 ) {
			global $wpdb;

			$sales          = array();
			$orders_scanned = 0;

			$lookup   = $wpdb->prefix . 'wc_order_product_lookup';
			$stats    = $wpdb->prefix . 'wc_order_stats';
			$statuses = array_values( $this->config['order_statuses'] );
			$after    = gmdate( 'Y-m-d H:i:s', time() - ( absint( $this->config['lookback_days'] ) * DAY_IN_SECONDS ) );

			if ( empty( $statuses ) ) {
				return $sales;
			}

			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$params       = array_merge( $statuses, array( $after ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT lookup.product_id AS product_id,
							SUM(lookup.product_qty) AS qty,
							SUM(lookup.product_net_revenue) AS revenue
					 FROM {$lookup} AS lookup
					 INNER JOIN {$stats} AS stats ON lookup.order_id = stats.order_id
					 WHERE stats.status IN ( {$placeholders} )
					   AND lookup.date_created >= %s
					 GROUP BY lookup.product_id",
					$params
				)
			);

			$orders_scanned = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT lookup.order_id)
					 FROM {$lookup} AS lookup
					 INNER JOIN {$stats} AS stats ON lookup.order_id = stats.order_id
					 WHERE stats.status IN ( {$placeholders} )
					   AND lookup.date_created >= %s",
					$params
				)
			);
			// phpcs:enable

			if ( empty( $rows ) ) {
				return $sales;
			}

			foreach ( $rows as $row ) {
				$product_id = (int) $row->product_id;
				if ( ! $product_id ) {
					continue;
				}

				$sales[ $product_id ] = array(
					'revenue' => (float) $row->revenue,
					'qty'     => (float) $row->qty,
				);
			}

			return $sales;
		}

		/**
		 * Collect per-product metrics by scanning order objects (fallback).
		 *
		 * Uses wc_get_orders() (HPOS-safe) with pagination. Variation sales are
		 * rolled up into their parent product id. WooCommerce object caches are
		 * flushed between batches to keep memory flat on large stores.
		 *
		 * @param int $orders_scanned Passed by reference; set to the order count read.
		 * @return array Map of product_id => array( 'revenue' => float, 'qty' => float ).
		 */
		protected function collect_sales_data_from_orders( &$orders_scanned = 0 ) {
			$sales          = array();
			$orders_scanned = 0;
			$after          = gmdate( 'Y-m-d H:i:s', time() - ( absint( $this->config['lookback_days'] ) * DAY_IN_SECONDS ) );
			$page           = 1;
			$per_page       = absint( $this->config['orders_per_page'] );

			do {
				$orders = wc_get_orders(
					array(
						'status'       => $this->config['order_statuses'],
						'date_created' => '>=' . $after,
						'limit'        => $per_page,
						'paged'        => $page,
						'orderby'      => 'ID',
						'order'        => 'ASC',
						'return'       => 'objects',
					)
				);

				if ( empty( $orders ) ) {
					break;
				}

				foreach ( $orders as $order ) {
					if ( ! $order instanceof WC_Order ) {
						continue;
					}

					$orders_scanned++;

					foreach ( $order->get_items() as $item ) {
						if ( ! $item instanceof WC_Order_Item_Product ) {
							continue;
						}

						// get_product_id() returns the parent for variations,
						// giving us basic parent/child aggregation for free.
						$product_id = $item->get_product_id();
						if ( ! $product_id ) {
							continue;
						}

						if ( ! isset( $sales[ $product_id ] ) ) {
							$sales[ $product_id ] = array(
								'revenue' => 0.0,
								'qty'     => 0.0,
							);
						}

						// Net line revenue (after discounts, excl. tax).
						$sales[ $product_id ]['revenue'] += (float) $item->get_total();
						$sales[ $product_id ]['qty']     += (float) $item->get_quantity();
					}
				}

				$batch_count = count( $orders );

				// Free the loaded order objects and WooCommerce's runtime caches
				// so a long run doesn't accumulate memory batch after batch.
				unset( $orders );
				if ( function_exists( 'wc_reset_order_data_cache' ) ) {
					wc_reset_order_data_cache();
				}
				am_cache_flush_runtime_safe();

				$page++;

				// Stop when the last page returned fewer than a full batch.
			} while ( $batch_count === $per_page );

			return $sales;
		}

		/**
		 * Turn raw sales metrics into a final 0.0–100.0 percentile score per product.
		 *
		 * Steps:
		 *   0. Optionally log-transform revenue and quantity (long-tail softening).
		 *   1. Min–max normalize revenue and quantity across the catalog to 0–100.
		 *   2. Weighted raw score (default 70% revenue, 30% quantity).
		 *   3. Percentile-rank the raw score across all included products.
		 *
		 * @param array $sales Map of product_id => array( 'revenue', 'qty' ).
		 * @return array Map of product_id => float score (1 decimal).
		 */
		protected function rank_and_normalize( array $sales ) {
			if ( empty( $sales ) ) {
				return array();
			}

			// 0: optional log-transform. log1p (log(1 + x)) keeps zeros safe and
			// preserves ordering, while compressing large values so a few
			// blockbuster products don't flatten the rest of the catalog.
			if ( $this->config['log_transform'] ) {
				foreach ( $sales as $product_id => $metrics ) {
					$sales[ $product_id ]['revenue'] = log( 1 + max( 0.0, $metrics['revenue'] ) );
					$sales[ $product_id ]['qty']     = log( 1 + max( 0.0, $metrics['qty'] ) );
				}
			}

			$revenues   = wp_list_pluck( $sales, 'revenue' );
			$quantities = wp_list_pluck( $sales, 'qty' );

			$max_revenue = max( $revenues );
			$max_qty     = max( $quantities );

			$rev_weight = $this->config['revenue_weight'];
			$qty_weight = $this->config['qty_weight'];
			$weight_sum = $rev_weight + $qty_weight;

			// 1 + 2: normalized, weighted raw score per product.
			$raw = array();
			foreach ( $sales as $product_id => $metrics ) {
				$rev_norm = ( $max_revenue > 0 ) ? ( $metrics['revenue'] / $max_revenue ) * 100 : 0.0;
				$qty_norm = ( $max_qty > 0 ) ? ( $metrics['qty'] / $max_qty ) * 100 : 0.0;

				$raw[ $product_id ] = ( ( $rev_norm * $rev_weight ) + ( $qty_norm * $qty_weight ) ) / $weight_sum;
			}

			// 3: percentile rank. percentile = (# products with raw <= this) / N * 100.
			$total  = count( $raw );
			$values = array_values( $raw );
			$scores = array();

			foreach ( $raw as $product_id => $score ) {
				$at_or_below = 0;
				foreach ( $values as $other ) {
					if ( $other <= $score ) {
						$at_or_below++;
					}
				}

				$percentile            = ( $at_or_below / $total ) * 100;
				$scores[ $product_id ] = round( $percentile, 1 );
			}

			return $scores;
		}

		/**
		 * Persist scores to product meta using WooCommerce product methods.
		 *
		 * Uses wc_get_product() + update_meta_data() + save() so WooCommerce hooks
		 * and object caches stay consistent (no direct update_post_meta()).
		 *
		 * @param array $scores Map of product_id => float score.
		 * @return int Number of products successfully saved.
		 */
		protected function save_scores( array $scores ) {
			$saved = 0;

			// Optionally floor every un-scored published product to 0.0.
			if ( $this->config['include_unsold'] ) {
				$scores = $this->add_unsold_products( $scores );
			}

			foreach ( $scores as $product_id => $score ) {
				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				$product->update_meta_data( self::META_KEY, number_format( (float) $score, 1, '.', '' ) );
				$product->save();
				$saved++;
			}

			return $saved;
		}

		/**
		 * Add every published product missing from $scores with a 0.0 score.
		 *
		 * Only called when 'include_unsold' is true.
		 *
		 * @param array $scores Existing scores keyed by product_id.
		 * @return array Scores plus 0.0 entries for unsold products.
		 */
		protected function add_unsold_products( array $scores ) {
			$all_ids = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => -1,
					'return' => 'ids',
				)
			);

			foreach ( $all_ids as $product_id ) {
				if ( ! isset( $scores[ $product_id ] ) ) {
					$scores[ $product_id ] = 0.0;
				}
			}

			return $scores;
		}
	}

endif;

if ( ! function_exists( 'am_cache_flush_runtime_safe' ) ) :
	/**
	 * Flush the in-memory (runtime) object cache when the backend supports it,
	 * without wiping a persistent cache. Keeps memory flat during long batch runs.
	 */
	function am_cache_flush_runtime_safe() {
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}
endif;

if ( ! function_exists( 'am_calculate_popularity_ranks' ) ) :

	/**
	 * Convenience wrapper: calculate and store popularity ranks in one call.
	 *
	 * Intended for WP-CLI, WP-Cron, or a manual trigger. Do NOT call this on a
	 * normal page load — it reads order history and is meant to run occasionally.
	 *
	 * @param array $args Optional configuration overrides.
	 * @return array|WP_Error Run summary, or WP_Error if WooCommerce is inactive.
	 */
	function am_calculate_popularity_ranks( array $args = array() ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error(
				'woocommerce_inactive',
				'WooCommerce must be active to calculate popularity ranks.'
			);
		}

		$calculator = new AM_Popularity_Rank_Calculator( $args );

		return $calculator->calculate();
	}

endif;

/*
 * ---------------------------------------------------------------------------
 * Automatic daily recalculation (WP-Cron)
 * ---------------------------------------------------------------------------
 * On activation the plugin schedules a daily event; on deactivation it clears
 * it. You install, activate, and the scores refresh themselves every day.
 * No command line and no manual trigger required.
 */

// Run the calculation when the scheduled event fires.
add_action( AM_POPULARITY_RANK_CRON_HOOK, 'am_calculate_popularity_ranks' );

register_activation_hook( __FILE__, 'am_popularity_rank_activate' );
register_deactivation_hook( __FILE__, 'am_popularity_rank_deactivate' );

if ( ! function_exists( 'am_popularity_rank_activate' ) ) :
	/**
	 * Schedule the daily recalculation when the plugin is activated.
	 *
	 * The first run is queued a minute out so it happens in a background cron
	 * request, never during the activation request itself.
	 */
	function am_popularity_rank_activate() {
		if ( ! wp_next_scheduled( AM_POPULARITY_RANK_CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'daily', AM_POPULARITY_RANK_CRON_HOOK );
		}
	}
endif;

if ( ! function_exists( 'am_popularity_rank_deactivate' ) ) :
	/**
	 * Clear the scheduled recalculation when the plugin is deactivated.
	 */
	function am_popularity_rank_deactivate() {
		wp_clear_scheduled_hook( AM_POPULARITY_RANK_CRON_HOOK );
	}
endif;

/*
 * ---------------------------------------------------------------------------
 * WP-CLI command
 * ---------------------------------------------------------------------------
 * Run the calculation from the command line, where memory limits are higher
 * and nothing runs on a page load:
 *
 *     wp popularity-rank calculate
 *     wp popularity-rank calculate --lookback_days=180 --log_transform=1
 *     wp popularity-rank calculate --include_unsold=1
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Calculate and store WooCommerce product popularity ranks.
	 */
	class AM_Popularity_Rank_CLI_Command {

		/**
		 * Calculate popularity ranks for recently-sold products.
		 *
		 * ## OPTIONS
		 *
		 * [--lookback_days=<days>]
		 * : Days of order history to consider. Default 90.
		 *
		 * [--revenue_weight=<weight>]
		 * : Share of the score driven by revenue (0–1). Default 0.70.
		 *
		 * [--qty_weight=<weight>]
		 * : Share of the score driven by quantity (0–1). Default 0.30.
		 *
		 * [--include_unsold]
		 * : Also score products with no recent sales as 0.0.
		 *
		 * [--log_transform]
		 * : Log-soften revenue and quantity before normalizing.
		 *
		 * [--no_lookup]
		 * : Force the slower per-order scan instead of the Analytics lookup tables.
		 *
		 * ## EXAMPLES
		 *
		 *     wp popularity-rank calculate
		 *     wp popularity-rank calculate --lookback_days=180 --log_transform
		 *
		 * @param array $args       Positional args (unused).
		 * @param array $assoc_args Named options.
		 */
		public function calculate( $args, $assoc_args ) {
			$config = array();

			if ( isset( $assoc_args['lookback_days'] ) ) {
				$config['lookback_days'] = absint( $assoc_args['lookback_days'] );
			}
			if ( isset( $assoc_args['revenue_weight'] ) ) {
				$config['revenue_weight'] = (float) $assoc_args['revenue_weight'];
			}
			if ( isset( $assoc_args['qty_weight'] ) ) {
				$config['qty_weight'] = (float) $assoc_args['qty_weight'];
			}
			$config['include_unsold']   = isset( $assoc_args['include_unsold'] );
			$config['log_transform']    = isset( $assoc_args['log_transform'] );
			$config['force_order_scan'] = isset( $assoc_args['no_lookup'] );

			WP_CLI::log( 'Calculating popularity ranks…' );

			$result = am_calculate_popularity_ranks( $config );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			$source = ( 'analytics_lookup' === $result['method'] )
				? 'WooCommerce Analytics lookup tables'
				: 'per-order scan';

			WP_CLI::success(
				sprintf(
					'Scored %d products from %d orders via the %s.',
					$result['products_scored'],
					$result['orders_scanned'],
					$source
				)
			);
		}
	}

	WP_CLI::add_command( 'popularity-rank', 'AM_Popularity_Rank_CLI_Command' );
}
