# Fractel Popularity Rank (Shopify)

Computes a 0.0–100.0 popularity percentile score per product from recent
paid-order history and writes it to the `custom.popularity_rank_score`
product metafield on the Fractel Shopify store (wearefractel.com), for use
in feed exports such as Google Merchant Center.

See [`shopify-popularity-rank.md`](./shopify-popularity-rank.md) for the
full algorithm, GraphQL queries, and scoring script
(`scripts/calculate_scores.py`).

Runs automatically once a week via a scheduled Claude Routine — no server,
no API keys to manage.

Originally a WooCommerce plugin, preserved in
[`legacy-woocommerce/`](./legacy-woocommerce/) for reference; ported to
Shopify for wearefractel.com.
