# Fractel Popularity Rank — Shopify

Port of the original WooCommerce "AM Popularity Rank" plugin (see
`legacy-woocommerce/`) to Shopify. Calculates a 0.0–100.0 popularity
percentile score per product from recent paid-order history and publishes it
as `data/sku_popularity_rank.csv`, which feeds the Google Shopping
supplemental feed sheet (Google Merchant Center's `popularity_rank` custom
label/attribute). Writing the same score to Shopify's
`custom.popularity_rank_score` product metafield is supported but not run by
the weekly automation — see "Writing scores to Shopify metafields (optional,
not automated)" below.

## How it works

1. **Collect sales data** — paginate through `orders(first: 250, after:
   $cursor, ...)`, filtered to orders created in the last `LOOKBACK_DAYS`
   (default **90**) whose financial status is `paid` or `partially_paid`.
   For each order, pull each line item's `quantity` and
   `discountedTotalSet` (net of line-level discounts, pre-tax — the
   closest Shopify equivalent to WooCommerce's net line revenue), plus the
   parent `product.id` (Shopify's `LineItem.product` already resolves to
   the parent product, so variant sales roll up for free, matching the
   original plugin's behaviour).

   **This must go through the read-only GraphQL query tool, never
   `bulkOperationRunQuery`.** An earlier version of this pipeline used a
   Shopify Bulk Operation instead (server-side export, one JSONL file
   instead of dozens of paginated round-trips) — but `bulkOperationRunQuery`
   is technically a GraphQL *mutation*, and every mutation call through
   this integration requires a human to interactively approve it in the
   Claude Code app, with no way for an unattended session to supply that.
   That's what silently wedged the weekly automation twice (2026-08 and
   2026-09) — the session just sat waiting for an approval that never
   came. Plain `orders(first: 250, ...)` queries carry no such
   restriction, so that's the only supported path for the scheduled job
   now, even though it costs ~30 round-trips instead of one at Fractel's
   order volume (~7,000 orders / 90 days).

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
   - explodes the per-product scores out to `data/sku_popularity_rank.csv`
     (one row per SKU), which is the pipeline's actual deliverable — see
     "Connecting to the Google Shopping supplemental feed" below.

3. **(Optional, not automated) Write scores to Shopify metafields** — the
   weekly Routine does not run this step; Fractel relies on the CSV/feed
   sheet instead. See "Writing scores to Shopify metafields (optional, not
   automated)" below if you want to re-enable it.

## Order-qualifying rule

Only `paid` and `partially_paid` orders count as a sale — the Shopify
equivalent of the WooCommerce plugin's `processing`/`completed`/`on-hold`
filter (money has actually changed hands). Cancelled, voided, refunded, and
payment-pending orders are excluded.

## Running a calculation

### 1. Paginate through orders (read-only — use `graphql_query`, never `graphql_mutation`)

Start with `cursor = null`. Repeat until `pageInfo.hasNextPage` is `false`:

```graphql
query OrdersPage($cursor: String) {
  orders(
    first: 250
    after: $cursor
    sortKey: CREATED_AT
    query: "created_at:>=<CUTOFF_ISO8601> AND (financial_status:paid OR financial_status:partially_paid)"
  ) {
    edges {
      cursor
      node {
        id
        lineItems(first: 250) {
          edges {
            node {
              quantity
              sku
              discountedTotalSet { shopMoney { amount } }
              product { id }
            }
          }
        }
      }
    }
    pageInfo { hasNextPage }
  }
}
```

`<CUTOFF_ISO8601>` = now minus `LOOKBACK_DAYS` (default 90), e.g.
`2026-05-26T00:00:00Z`. Use the last edge's `cursor` as `$cursor` for the
next page.

For every line item in every page, append one line to a local
`orders.jsonl` file in exactly the shape `calculate_scores.py` expects:

```json
{"quantity": 1, "sku": "ABC123", "discountedTotalSet": {"shopMoney": {"amount": "61.07"}}, "product": {"id": "gid://shopify/Product/123"}, "__parentId": "gid://shopify/Order/456"}
```

(The script only reads LineItem rows — it derives `orders_scanned` from
the distinct `__parentId` values, so there's no need to also write a
separate row per Order.) An order with more than 250 line items would
need its own `lineItems` pagination too, but that's not a realistic case
for Fractel's catalog.

### 2. Score

```
python3 scripts/calculate_scores.py orders.jsonl scores.json data/sku_popularity_rank.csv
```

The third argument is optional but used for the Google Shopping feed
connection (see below) — it explodes each product's score out to one row
per SKU (rolling a parent's score down to every variant), since the feed
is keyed by SKU rather than parent product ID. Commit and push
`data/sku_popularity_rank.csv` to the repo's default branch after every
run — that's the whole delivery mechanism; nothing else needs to happen
for the feed sheet to update itself.

## Writing scores to Shopify metafields (optional, not automated)

The pipeline can also write each product's score to Shopify as a product
metafield, for use cases other than the CSV/feed sheet. **The weekly
Routine does not do this** — Fractel relies solely on the CSV-driven
supplemental feed, and the `metafieldsSet` mutation requires interactive
permission approval in the Claude Code app, which previously left the
Routine's session stuck for weeks waiting on an approval that never came
(the CSV silently went stale as a result, since that step blocked the
commit/push step after it). If you want scores on Shopify again, run this
manually rather than re-adding it to the unattended weekly job.

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

Runs once a week via a Claude Routine (no separate hosting or API
credentials to manage — it reuses the Shopify connection already
authorized in this workspace). Each firing starts a fresh session (rather
than reusing one persistent session) so a one-off snag in a given week's
run can't permanently wedge future weeks. See the "Fractel Popularity Rank
– Weekly" Routine for the exact cron and prompt.

## Configuration

| Setting | Default | Notes |
|---|---|---|
| Lookback window | 90 days | |
| Order statuses | paid, partially_paid | |
| Revenue weight | 0.70 | |
| Quantity weight | 0.30 | |
| Log-transform | off | flip `LOG_TRANSFORM` in `calculate_scores.py` for catalogs where a few products dominate |
| Include unsold products (score 0.0) | off | not implemented in this port; ask if you want it added |
| Shopify metafield write-back | off | CSV/feed sheet only; see "Writing scores to Shopify metafields" above to re-enable manually |

## Connecting to the Google Shopping supplemental feed

The live feed sheet ("Fractel - Google Shopping Supplemental Feed", Sheet1)
already has a `popularity_rank` column (column H), keyed by `id` = SKU —
one row per offer/variant, not per parent product.

There's no direct write path from this pipeline into that Google Sheet: no
Google Sheets API connector is available, and Drive's file tools can only
create brand-new files, not edit an existing one's cells in place (doing so
would assign a new file ID and break the sheet's registered Google Merchant
Center supplemental source). So the connection goes through the repo
instead of a direct write:

1. Every run publishes `data/sku_popularity_rank.csv` (sku, popularity_rank)
   to this repo's default branch, at a stable raw URL:
   `https://raw.githubusercontent.com/RileyB88/woocommerce-popularity-rank/main/data/sku_popularity_rank.csv`
2. In the live feed spreadsheet, add a tab named exactly `popularity_rank_score`
   (this is the tab name actually in use — get it exact, since a mismatch
   here is a `#REF!` error waiting to happen) with this formula in cell A1:
   ```
   =IMPORTDATA("https://raw.githubusercontent.com/RileyB88/woocommerce-popularity-rank/main/data/sku_popularity_rank.csv")
   ```
   Google Sheets refreshes `IMPORTDATA` periodically (roughly hourly) and
   on file open.
3. In Sheet1's `popularity_rank` column (H), replace the manually-entered
   values with:
   ```
   =IFERROR(VLOOKUP($A2, popularity_rank_score!A:B, 2, FALSE), "")
   ```
   and fill down **the entire column, all the way to the last row** —
   a partial fill-down is exactly what causes some rows to silently keep
   their old static values while others go live. Rows for SKUs with no
   qualifying sales in the lookback window return blank, matching the
   pipeline's "skip unsold products" default.

This is a one-time manual setup (two formulas). After that, every weekly
run's CSV push flows through automatically with no further action needed.
The repo is public, so the CSV (relative percentile ranks only — no
revenue or order data) is reachable without authentication, which is what
makes plain `IMPORTDATA` work.

## Reading the score back from Shopify

Only relevant if you've run the optional metafield write-back above — the
weekly automation doesn't populate this.

```graphql
{
  product(id: "gid://shopify/Product/123") {
    metafield(namespace: "custom", key: "popularity_rank_score") { value }
  }
}
```
