#!/usr/bin/env python3
"""
Aggregates a Shopify bulk-operation orders JSONL export into per-product
revenue/quantity totals, then computes the same 0.0-100.0 percentile
popularity score used by the original WooCommerce "AM Popularity Rank" plugin.

Input JSONL rows come from a bulkOperationRunQuery run against the query in
shopify-popularity-rank.md (orders -> lineItems -> {quantity,
discountedTotalSet, product{id}, sku}, groupObjects: false). Order rows and
LineItem rows are interleaved in the file; LineItem rows are identified by
having a "quantity" field and carry a "__parentId" pointing at their order.

Usage:
    python3 calculate_scores.py orders.jsonl > scores.json
    python3 calculate_scores.py orders.jsonl scores.json sku_popularity_rank.csv
"""
import csv
import json
import math
import sys

REVENUE_WEIGHT = 0.70
QTY_WEIGHT = 0.30
LOG_TRANSFORM = False


def aggregate(jsonl_path):
    sales = {}
    skus_by_product = {}
    orders_scanned = set()

    with open(jsonl_path) as f:
        for line in f:
            line = line.strip()
            if not line:
                continue

            row = json.loads(line)
            if "quantity" not in row:
                continue  # an Order row, not a LineItem row

            product = row.get("product")
            if not product or not product.get("id"):
                continue  # line item on a deleted/missing product

            product_id = product["id"]
            revenue = float(row["discountedTotalSet"]["shopMoney"]["amount"])
            qty = float(row["quantity"])

            entry = sales.setdefault(product_id, {"revenue": 0.0, "qty": 0.0})
            entry["revenue"] += revenue
            entry["qty"] += qty

            sku = row.get("sku")
            if sku:
                skus_by_product.setdefault(product_id, set()).add(sku)

            parent = row.get("__parentId")
            if parent:
                orders_scanned.add(parent)

    return sales, skus_by_product, len(orders_scanned)


def sku_scores(scores, skus_by_product):
    """Explode product-level scores out to one row per SKU, for feeds
    (like the Google Shopping supplemental feed) that key by SKU/offer
    rather than by parent product."""
    result = {}
    for product_id, skus in skus_by_product.items():
        if product_id not in scores:
            continue
        for sku in skus:
            result[sku] = scores[product_id]
    return result


def write_sku_csv(path, sku_score_map):
    with open(path, "w", newline="") as f:
        writer = csv.writer(f)
        writer.writerow(["sku", "popularity_rank"])
        for sku in sorted(sku_score_map):
            writer.writerow([sku, sku_score_map[sku]])


def rank_and_normalize(sales):
    if not sales:
        return {}

    values = {pid: dict(m) for pid, m in sales.items()}

    # Optional log-transform: log1p keeps zeros safe and preserves ordering
    # while compressing large values, softening long-tail dominance.
    if LOG_TRANSFORM:
        for pid, m in values.items():
            values[pid] = {
                "revenue": math.log1p(max(0.0, m["revenue"])),
                "qty": math.log1p(max(0.0, m["qty"])),
            }

    max_revenue = max(m["revenue"] for m in values.values())
    max_qty = max(m["qty"] for m in values.values())
    weight_sum = REVENUE_WEIGHT + QTY_WEIGHT

    raw = {}
    for pid, m in values.items():
        rev_norm = (m["revenue"] / max_revenue * 100) if max_revenue > 0 else 0.0
        qty_norm = (m["qty"] / max_qty * 100) if max_qty > 0 else 0.0
        raw[pid] = ((rev_norm * REVENUE_WEIGHT) + (qty_norm * QTY_WEIGHT)) / weight_sum

    all_raw = list(raw.values())
    total = len(all_raw)
    scores = {}
    for pid, score in raw.items():
        at_or_below = sum(1 for v in all_raw if v <= score)
        percentile = (at_or_below / total) * 100
        scores[pid] = round(percentile, 1)

    return scores


def main():
    if len(sys.argv) not in (2, 4):
        print(
            "Usage: calculate_scores.py <orders.jsonl> [scores.json sku_popularity_rank.csv]",
            file=sys.stderr,
        )
        sys.exit(1)

    sales, skus_by_product, orders_scanned = aggregate(sys.argv[1])
    scores = rank_and_normalize(sales)

    output = {
        "orders_scanned": orders_scanned,
        "products_scored": len(scores),
        "scores": scores,
    }

    if len(sys.argv) == 4:
        _, _, scores_out_path, csv_out_path = sys.argv
        with open(scores_out_path, "w") as f:
            json.dump(output, f, indent=2)
        write_sku_csv(csv_out_path, sku_scores(scores, skus_by_product))
    else:
        print(json.dumps(output, indent=2))


if __name__ == "__main__":
    main()
