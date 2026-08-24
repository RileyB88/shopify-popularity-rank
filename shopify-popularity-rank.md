# Fractel Popularity Rank — Shopify

Port of the original WooCommerce "AM Popularity Rank" plugin (see
`legacy-woocommerce/`) to Shopify. Calculates a 0.0–100.0 popularity
percentile score per product from recent paid-order history and writes it to
the `custom.popularity_rank_score` product metafield on wearefractel.com, for
use in feed exports (e.g. Google Merchant Center's `popularity_rank` custom
label/attribute).

## How it works

1. **Collect sales data** — run a Shopify Bulk Operation
   (`bulkOperationRunQuery`) against `orders`, filtered to orders created in
   the last `LOOKBACK_DAYS` (default **90**) whose financial status is
   `paid` or `partially_paid`. For each order, pull each line item's
   `quantity` and `discountedTotalSet` (net of line-level discounts,
   pre-tax — the closest Shopify equivalent to WooCommerce's net line
   revenue), plus the parent `product.id` (Shopify's `LineItem.product`
   already resolves to the parent product, so variant sales roll up for
   free, matching the original plugin's behaviour).

   A bulk operation is used instead of paginated `orders(first: 250)`
   queries because a single 90-day window easily contains thousands of
   qualifying orders (Fractel: ~6,800 as of the port) — bulk queries run
   server-side and return one JSONL file instead of dozens of paginated
   round-trips, mirroring the original plugin's preference for the fast
   Analytics-lookup path over a per-order scan.

2. **Aggregate & score** (`scripts/calculate_scores.py`) — sum revenue and
   quantity per product across the JSONL export, then:
   - min–max normalize revenue and quantity to 0–100 across the catalog,
   - blend them **70% revenue / 30% quantity** (same default as the
     original plugin),
   - percentile-rank the blended score across every product with
     qualifying sales, rounded to 1 decimal — so the best seller scores
     100.0 and everything else is relative to it.
   - `LOG_TRANSFORM` (off by default) and unsold-product handling exist as
     the same optional knobs the original plugin had; flip the constants
     at the top of the script if you want them later.

3. **Write scores back** — batch the results into Shopify's
   `metafieldsSet` mutation (max 25 per call) writing to
   `namespace: "custom", key: "popularity_rank_score", type:
   "number_decimal"` on each `gid://shopify/Product/...`.

## Order-qualifying rule

Only `paid` and `partially_paid` orders count as a sale — the Shopify
equivalent of the WooCommerce plugin's `processing`/`completed`/`on-hold`
filter (money has actually changed hands). Cancelled, voided, refunded, and
payment-pending orders are excluded.

## Running a calculation

### 1. Kick off the bulk query

```graphql
mutation {
  bulkOperationRunQuery(
    groupObjects: false
    query: """
    {
      orders(query: "created_at:>=<CUTOFF_ISO8601> AND (financial_status:paid OR financial_status:partially_paid)") {
        edges {
          node {
            id
            lineItems {
              edges {
                node {
                  quantity
                  discountedTotalSet { shopMoney { amount } }
                  product { id }
                }
              }
            }
          }
        }
      }
    }
    """
  ) {
    bulkOperation { id status }
    userErrors { field message }
  }
}
```

`<CUTOFF_ISO8601>` = now minus `LOOKBACK_DAYS` (default 90), e.g.
`2026-05-26T00:00:00Z`.

### 2. Poll until complete

```graphql
{ currentBulkOperation { id status errorCode objectCount url } }
```

Poll every ~15–30s. On `COMPLETED`, download the file at `url` (a signed,
time-limited link valid for 7 days — no auth header needed).

### 3. Score

```
python3 scripts/calculate_scores.py orders.jsonl > scores.json
```

### 4. Write back

For each batch of ≤25 products from `scores.json`, call:

```graphql
mutation SetPopularity($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields { id }
    userErrors { field message code }
  }
}
```

with `metafields` entries of the form:

```json
{
  "ownerId": "gid://shopify/Product/123456789",
  "namespace": "custom",
  "key": "popularity_rank_score",
  "type": "number_decimal",
  "value": "87.4"
}
```

## Schedule

Runs once a day via a Claude Routine (no separate hosting or API
credentials to manage — it reuses the Shopify connection already
authorized in this workspace). See the "Fractel Popularity Rank – Daily"
Routine for the exact cron and prompt.

## Configuration

| Setting | Default | Notes |
|---|---|---|
| Lookback window | 90 days | |
| Order statuses | paid, partially_paid | |
| Revenue weight | 0.70 | |
| Quantity weight | 0.30 | |
| Log-transform | off | flip `LOG_TRANSFORM` in `calculate_scores.py` for catalogs where a few products dominate |
| Include unsold products (score 0.0) | off | not implemented in this port; ask if you want it added |

## Reading the score back

```graphql
{
  product(id: "gid://shopify/Product/123") {
    metafield(namespace: "custom", key: "popularity_rank_score") { value }
  }
}
```
