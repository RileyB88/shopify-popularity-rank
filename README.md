# Fractel Popularity Rank (Shopify)

Computes a 0.0–100.0 popularity percentile score per product from recent
paid-order history and publishes it as `data/sku_popularity_rank.csv`,
which feeds the Fractel Google Shopping supplemental feed sheet (via
`IMPORTDATA`) for use in Google Merchant Center.

See [`shopify-popularity-rank.md`](./shopify-popularity-rank.md) for the
full algorithm, GraphQL queries, and scoring script
(`scripts/calculate_scores.py`).

Writing the score back to Shopify's `custom.popularity_rank_score` product
metafield is still supported by the pipeline but is **not run** by the
weekly automation — Fractel relies solely on the CSV-driven supplemental
feed. See "Writing scores to Shopify metafields (optional, not automated)"
in `shopify-popularity-rank.md` if you want to turn it back on.

Runs automatically once a week via a scheduled Claude Routine — no server,
no API keys to manage.

Originally a WooCommerce plugin, preserved in
[`legacy-woocommerce/`](./legacy-woocommerce/) for reference; ported to
Shopify for wearefractel.com.
