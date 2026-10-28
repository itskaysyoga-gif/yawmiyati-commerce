"""Competitor benchmarks per product, split by comparability tier.

Tiers (from the analyst's comparability note):
  identical  - "same item ..." (includes "likely" matches, flagged)
  similar    - "similar ..." / "same type"
  other      - different sets, bundles, components (excluded from price references)

Delivery: a fee is used only when the page states it (a number, or explicit free shipping).
"not shown", "varies" and "likely 0" are UNKNOWN and never counted as zero.
Availability: rows whose notes say out of stock / sold out are excluded from "available" stats.
"""
import re
import sys
import pandas as pd

UNAVAILABLE = re.compile(r"out of stock|sold out|unavailable|نفذ|غير متوفر", re.I)


def tier(note: str) -> str:
    n = str(note).lower()
    if n.startswith("same item"):
        return "identical"
    if n.startswith("similar") or n.startswith("same type"):
        return "similar"
    return "other"


def delivery(value: str):
    v = str(value).strip().lower()
    if v.startswith("likely") or v.startswith("not shown") or v.startswith("varies") or v.startswith("free over"):
        return None
    m = re.match(r"^(\d[\d,]*)", v)
    if m:
        return float(m.group(1).replace(",", ""))
    return None


def load(path: str, cur: str) -> pd.DataFrame:
    d = pd.read_csv(path)
    d["price"] = d[f"price_{cur}"].astype(float)
    d["tier"] = d["comparability"].map(tier)
    d["likely"] = d["comparability"].str.contains("likely", case=False, na=False)
    d["delivery_known"] = d[f"delivery_{cur}"].map(delivery)
    d["delivered"] = d["price"] + d["delivery_known"]
    text = d["contents"].astype(str) + " " + d["title"].astype(str) + " " + d["competitor"].astype(str)
    d["available"] = ~text.str.contains(UNAVAILABLE)
    return d


def summarise(d: pd.DataFrame) -> pd.DataFrame:
    rows = []
    for slug, g in d.groupby("slug"):
        row = {"slug": slug}
        for t in ("identical", "similar"):
            x = g[(g.tier == t) & g.available]
            row[f"{t}_n"] = len(x)
            row[f"{t}_likely_n"] = int(x.likely.sum()) if t == "identical" else None
            row[f"{t}_price_min"] = x.price.min() if len(x) else None
            row[f"{t}_price_median"] = x.price.median() if len(x) else None
            row[f"{t}_price_max"] = x.price.max() if len(x) else None
            known = x.dropna(subset=["delivered"])
            row[f"{t}_delivery_known_n"] = len(known)
            row[f"{t}_delivered_median"] = known.delivered.median() if len(known) else None
        row["unavailable_excluded"] = int((~g.available).sum())
        row["other_excluded"] = int((g.tier == "other").sum())
        rows.append(row)
    return pd.DataFrame(rows)


if __name__ == "__main__":
    base = sys.argv[1] if len(sys.argv) > 1 else "research/data"
    for cc, cur in (("egy", "egp"), ("irq", "iqd")):
        s = summarise(load(f"{base}/competitors_{cc}.csv", cur))
        s.to_csv(f"{base}/benchmarks_{cc}_v2.csv", index=False)
        print(cc.upper())
        print(s[["slug", "identical_n", "identical_likely_n", "identical_price_median", "identical_delivery_known_n",
                 "identical_delivered_median", "similar_n", "similar_price_median", "unavailable_excluded"]].to_string(index=False))
