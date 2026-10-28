"""Contribution economics per submitted order. Formula, inputs and sources: docs/PRICING.md.

    E = p_kept * M  -  q_refused * F  -  q_returned * R  -  q_cancelled * K  -  q_partial * L  -  O  -  A

    M  wallet profit of a delivered-and-kept order = Taager price - Taager cost - VAT on uplift
       (VAT: Saudi only, 15 % of the amount above Taager's minimum)
    F  refusal charge to the merchant (refused at the door)
    R  charge for a return after delivery          (Egypt 5 EGP published; elsewhere unknown, assumption A2: R = F)
    K  charge for a cancellation before dispatch   (assumption A1: 0)
    L  loss on a partial refund                    (share 0 in every scenario; no data)
    O  per-order operating cost                    (assumption A4: 0, no per-order tool is paid today)
    A  acquisition cost per submitted order        (break-even CPA = E with A = 0)

Contribution is measured at the Taager wallet. Withdrawal/payout fees are unknown and are NOT inside E;
"money actually paid out" = wallet - unknown payout costs.

Unknown never becomes zero: if F (or R, via A2) is unknown, E and break-even are None. A range is only
produced under assumption A3 (0 <= F <= one delivery fee), and every such value is labelled.

Rates are scenarios, never measurements: p_kept = 30 / 45 / 60 % with all other orders treated as refused
at the door (the costliest outcome with a known charge). There is no order history (0 orders).
"""
from __future__ import annotations

import math
from dataclasses import dataclass

COUNTRY_RULES = "docs/COUNTRY-RULES.md (Taager published shipping policy, captured Sept 2026)"
WORKBOOK = "yawmiyati_pricing_v3_taager_mechanics.xlsx, sheet TAAGER MECHANICS (workbook said; decisions discarded)"
SUPPORT_CHAT = "Taager support chat 2026-10-28 (Taager said; not verified)"


@dataclass(frozen=True)
class Input:
    value: float | None
    status: str          # published | taager_said | workbook_said | assumption | unknown
    source: str

    @property
    def known(self) -> bool:
        return self.value is not None


CURRENCY = {"EGY": "EGP", "IRQ": "IQD", "SAU": "SAR", "ARE": "AED", "OMN": "OMR"}
DECIMALS = {"EGY": 2, "IRQ": 0, "SAU": 2, "ARE": 2, "OMN": 3}

# Delivery fee the customer pays to Taager on top of the Taager price (one per order: Taager said).
DELIVERY_FEE = {
    "EGY": (95.0, 100.0, 105.0),   # zone 1 / 2 / 3
    "IRQ": (7000.0,),
    "SAU": (28.0,),
    "ARE": (19.0,),
    "OMN": (3.0,),
}
DELIVERY_SOURCE = f"{COUNTRY_RULES}; same figures in {WORKBOOK}"

REFUSAL_FEE = {
    "EGY": Input(5.0, "published", f"{COUNTRY_RULES}: 5 EGP. {SUPPORT_CHAT} said no deduction; 5 kept (conservative)"),
    "IRQ": Input(0.0, "taager_said", f"{SUPPORT_CHAT}: no deduction on refusal. Published policy: not published"),
    "SAU": Input(None, "unknown", f"not published ({COUNTRY_RULES}; workbook v3 also 'not published')"),
    "ARE": Input(None, "unknown", f"not published ({COUNTRY_RULES}; workbook v3 also 'not published')"),
    "OMN": Input(None, "unknown", f"not published ({COUNTRY_RULES}; workbook v3 also 'not published')"),
}

VAT_ON_UPLIFT = {
    "SAU": Input(0.15, "published",
                 f"{COUNTRY_RULES}: 15 % on profit above minimum; {WORKBOOK}: '+100 SAR puts 85 SAR in your wallet'. "
                 "Published Saudi policy: 15 % of the added profit (uplift). Open: applies to a non-Saudi merchant?"),
}

ASSUMPTIONS = {
    "A1": "cancellation before dispatch costs the merchant nothing (no source lists a charge; profit is not earned)",
    "A2": "a return after delivery costs the merchant the same as a refusal (profit not earned + F); "
          "Taager's charge to the merchant for returns is not published in any country",
    "A3": "where F is unknown, a range is shown for 0 <= F <= one delivery fee (stress bound, not evidence; "
          "Egypt's published 5 EGP is ~5 % of its fee)",
    "A4": "no per-order operating cost (hosting and tools are fixed costs; nothing is paid per order today)",
}

SCENARIOS = (0.30, 0.45, 0.60)   # delivered-and-kept share of submitted orders: scenarios, not measured


def fee_range(country: str) -> tuple[float, float]:
    f = DELIVERY_FEE[country]
    return min(f), max(f)


def rnd(country: str, x: float | None):
    if x is None:
        return None
    return round(x, DECIMALS[country]) if DECIMALS[country] else int(round(x))


def refusal_fee(country: str, strict: bool = False) -> Input:
    """strict=True treats 'taager_said' as unknown (only published/verified figures count)."""
    f = REFUSAL_FEE[country]
    if strict and f.status == "taager_said":
        return Input(None, "unknown", f.source + " [strict mode: support-chat answer not counted]")
    return f


def vat(country: str, taager_price: float, taager_min: float) -> float:
    rate = VAT_ON_UPLIFT.get(country)
    return 0.0 if rate is None else rate.value * max(0.0, taager_price - taager_min)


def wallet_margin(country: str, taager_price: float, cost: float, taager_min: float) -> float:
    """M: what lands in the Taager wallet when the order is delivered and kept."""
    return taager_price - cost - vat(country, taager_price, taager_min)


@dataclass
class Result:
    value: float | None
    missing: tuple[str, ...]
    assumptions: tuple[str, ...]


def expected_contribution(country: str, margin: float, p_kept: float, *, q_refused: float | None = None,
                          q_returned: float = 0.0, q_cancelled: float = 0.0, q_partial: float = 0.0,
                          partial_loss: float = 0.0, operating: float = 0.0, acquisition: float = 0.0,
                          refusal: float | None = None, strict: bool = False) -> Result:
    """Expected contribution per submitted order. None when a needed charge is unknown."""
    if q_refused is None:   # scenario default: every order not kept is refused at the door
        q_refused = 1.0 - p_kept - q_returned - q_cancelled - q_partial
    shares = p_kept + q_refused + q_returned + q_cancelled + q_partial
    if abs(shares - 1.0) > 1e-9:
        raise ValueError(f"outcome shares sum to {shares}, not 1")
    f_in = Input(refusal, "given", "argument") if refusal is not None else refusal_fee(country, strict)
    used = ["A1", "A4"]
    if q_returned:
        used.append("A2")
    if not f_in.known and (q_refused > 0 or q_returned > 0):
        return Result(None, (f"refusal charge {country} ({f_in.status})",), tuple(used))
    f = f_in.value or 0.0
    r = f  # A2
    k = 0.0  # A1
    e = p_kept * margin - q_refused * f - q_returned * r - q_cancelled * k - q_partial * partial_loss - operating - acquisition
    return Result(e, (), tuple(used))


def breakeven_cpa(country: str, margin: float, p_kept: float, strict: bool = False) -> Result:
    return expected_contribution(country, margin, p_kept, strict=strict)


def breakeven_range_a3(country: str, margin: float, p_kept: float) -> tuple[float, float]:
    """Only for an unknown F: break-even CPA for F = one delivery fee (low) and F = 0 (high). Assumption A3."""
    hi = expected_contribution(country, margin, p_kept, refusal=0.0).value
    lo = expected_contribution(country, margin, p_kept, refusal=fee_range(country)[1]).value
    return lo, hi


# ---------------------------------------------------------------- offer structures
def structure_a(country: str, cost: float, tmin: float, price: float) -> dict:
    """(a) product price + clearly disclosed delivery: Taager price = product price; customer adds the fee."""
    lo, hi = fee_range(country)
    return {"taager_price": price, "customer_total_min": price + lo, "customer_total_max": price + hi,
            "shipping_subsidy": 0.0, "valid": price >= tmin,
            "margin": wallet_margin(country, price, cost, tmin)}


def structure_b(country: str, cost: float, tmin: float, headline: float) -> dict:
    """(b) delivery included: one headline total; Taager price = headline - highest fee (so no zone pays more
    than the headline). Subsidy = that fee, paid out of margin on every delivered order."""
    hi = fee_range(country)[1]
    tp = headline - hi
    return {"taager_price": tp, "customer_total_min": headline - (hi - fee_range(country)[0]),
            "customer_total_max": headline, "shipping_subsidy": hi, "valid": tp >= tmin,
            "margin": wallet_margin(country, tp, cost, tmin)}


def structure_c(country: str, bundle_cost: float, bundle_min: float, bundle_price: float, units: int) -> dict:
    """(c) Taager multi-unit SKU: one SKU, one delivery fee by construction."""
    lo, hi = fee_range(country)
    return {"taager_price": bundle_price, "customer_total_min": bundle_price + lo,
            "customer_total_max": bundle_price + hi, "shipping_subsidy": 0.0, "units": units,
            "valid": bundle_price >= bundle_min, "margin": wallet_margin(country, bundle_price, bundle_cost, bundle_min),
            "saving_vs_separate_orders_min": (units - 1) * lo}


def evaluate(country: str, s: dict, strict: bool = False) -> dict:
    out = {}
    for p in SCENARIOS:
        r = breakeven_cpa(country, s["margin"], p, strict)
        out[f"breakeven_cpa_p{int(p * 100)}"] = rnd(country, r.value)
        if r.value is None:
            lo, hi = breakeven_range_a3(country, s["margin"], p)
            out[f"breakeven_cpa_p{int(p * 100)}_range_A3"] = f"{rnd(country, lo)}..{rnd(country, hi)}"
        else:
            out[f"breakeven_cpa_p{int(p * 100)}_range_A3"] = ""
    return out


def recommend(country: str, a: dict, b: dict, c: dict | None, strict: bool = False) -> tuple[str, str]:
    """Single recommended structure, or 'undecided: needs X'. Returns (recommendation, reason)."""
    if not a["valid"]:
        return "undecided: needs a price at or above Taager's minimum", "proposed price below Taager minimum"
    f = refusal_fee(country, strict)
    if not f.known:
        return (f"undecided: needs the {country} refusal/return charge (refused test order or written answer)",
                "break-even cannot be computed; (a) is the provisional structure")
    e30 = breakeven_cpa(country, a["margin"], 0.30, strict).value
    if e30 is None or e30 <= 0:
        return "reject at this price: not positive at the 30% scenario", f"break-even CPA at 30% = {e30}"
    rec = "a: price + disclosed delivery"
    why = ("(b) at the same headline costs one delivery fee per delivered order and its only possible gain "
           "(conversion) is unmeasured; (b) at the same total is economically identical to (a)")
    if c is not None and c["valid"]:
        ec = breakeven_cpa(country, c["margin"], 0.30, strict).value
        if ec is not None and ec > e30:
            rec = "a, plus c as a quantity option"
            why += "; Taager multi-unit SKU raises break-even CPA per order"
    if f.status == "taager_said":
        why += f"; refusal charge is '{f.status}' (unverified)"
    return rec, why


class Offer:
    """Backward-compatible wrapper (old API). Unknown refusal charge now propagates as None."""

    def __init__(self, country, taager_cost, taager_min, selling_price, strict=False):
        self.country, self.cost, self.tmin, self.price, self.strict = country, taager_cost, taager_min, selling_price, strict

    def margin_per_delivery(self) -> float:
        return wallet_margin(self.country, self.price, self.cost, self.tmin)

    def break_even_cpa(self, p: float) -> float | None:
        return breakeven_cpa(self.country, self.margin_per_delivery(), p, self.strict).value

    def report(self) -> dict:
        a = structure_a(self.country, self.cost, self.tmin, self.price)
        f = refusal_fee(self.country, self.strict)
        out = {"customer_pays": (a["customer_total_min"], a["customer_total_max"]),
               "margin_per_delivery": rnd(self.country, a["margin"]), "valid_price": a["valid"],
               "refusal_fee": f.value, "refusal_fee_status": f.status}
        out.update(evaluate(self.country, a, self.strict))
        if f.value is None:
            out["note"] = "break-even cannot be computed without the refusal charge; *_range_A3 is an assumption"
        return out


def charm_up(country: str, x: float) -> float:
    if country == "IRQ":
        return float(math.ceil(x / 1000.0) * 1000)
    if country == "OMN":
        return math.ceil(x * 2 - 1e-9) / 2.0
    n = math.ceil(x - 1e-9)
    while n % 10 != 9:
        n += 1
    return float(n)


def b_needed_uplift(a_margin: float, b_margin: float) -> float | None:
    """Relative rise in the delivered-and-kept rate that (b, same headline) needs to match (a). Refusal charge
    ignored here (it is equal for both and small or unknown). None = (b) can never match."""
    if b_margin <= 0:
        return None
    return a_margin / b_margin - 1.0


def pricing_rows(offers_csv: str) -> list[dict]:
    import csv
    rows = []
    with open(offers_csv, newline="", encoding="utf-8") as fh:
        for o in csv.DictReader(fh):
            if o.get("existing_status") == "reject":
                continue
            c = o["country"]
            cost, tmin, price = float(o["taager_cost"]), float(o["taager_min_price"]), float(o["proposed_price"])
            lo, hi = fee_range(c)
            f = refusal_fee(c)
            a = structure_a(c, cost, tmin, price)
            # (b) at the SAME TOTAL as (a) is the same money (Taager price unchanged); shown as a note only.
            # (b) at the SAME HEADLINE: the product price becomes the all-in total; the merchant absorbs the fee.
            b = structure_b(c, cost, tmin, price)
            r = {"country": c, "rank": o.get("rank", ""), "stage": o["stage"], "taager_sku": o["taager_sku"],
                 "name_ar": o["name_ar"], "currency": CURRENCY[c], "taager_cost": cost, "taager_min_price": tmin,
                 "proposed_price": price, "delivery_fee": "/".join(f"{x:g}" for x in DELIVERY_FEE[c]),
                 "refusal_fee": "" if f.value is None else f.value, "refusal_fee_status": f.status,
                 "vat_on_uplift": rnd(c, vat(c, price, tmin)) if c in VAT_ON_UPLIFT else "",
                 "a_customer_total": f"{a['customer_total_min']:g}" + ("" if lo == hi else f"-{a['customer_total_max']:g}"),
                 "a_margin": rnd(c, a["margin"])}
            r.update({f"a_{k}": v for k, v in evaluate(c, a).items()})
            r.update({"b_headline_total": price, "b_taager_price": rnd(c, b["taager_price"]),
                      "b_valid_vs_taager_min": b["valid"], "b_shipping_subsidy": hi,
                      "b_margin": rnd(c, b["margin"]) if b["valid"] else "",
                      "b_breakeven_cpa_p30": rnd(c, breakeven_cpa(c, b["margin"], 0.30).value) if b["valid"] else "",
                      "b_needs_kept_rate_uplift": ""})
            if b["valid"]:
                u = b_needed_uplift(a["margin"], b["margin"])
                r["b_needs_kept_rate_uplift"] = "never" if u is None else f"{u:.0%}"
            # (c) Taager multi-unit SKU (one SKU = one delivery fee by construction)
            cc = None
            if o.get("multi_sku"):
                units = int(float(o["multi_units"]))
                ccost, cmin = float(o["multi_cost"]), float(o["multi_min"])
                cprice = max(cmin, charm_up(c, units * price) if c != "IRQ" else units * price)
                if o["taager_sku"] == "EG030106MKKT99":
                    cprice = 749.0        # launch_assortment_v2 price for EG030108BUND99
                cc = structure_c(c, ccost, cmin, cprice, units)
                r.update({"c_sku": o["multi_sku"], "c_units": units, "c_price": cprice, "c_valid": cc["valid"],
                          "c_margin": rnd(c, cc["margin"]),
                          "c_breakeven_cpa_p30": rnd(c, breakeven_cpa(c, cc["margin"], 0.30).value),
                          "c_customer_saves_vs_separate_orders": rnd(c, cc["saving_vs_separate_orders_min"])})
            else:
                r.update({"c_sku": "", "c_units": "", "c_price": "", "c_valid": "", "c_margin": "",
                          "c_breakeven_cpa_p30": "", "c_customer_saves_vs_separate_orders": ""})
            r["c_cart_threshold"] = ("not offered: one fee per multi-item order is 'Taager said' only "
                                     "(verification task 7: 2-item test order)")
            rec, why = recommend(c, a, b, cc)
            r["recommended_structure"], r["recommendation_reason"] = rec, why
            unknown = ["payout/withdrawal fee", "return-after-delivery charge (A2 used)", "all outcome rates (scenarios)"]
            if f.value is None:
                unknown.insert(0, "refusal charge")
            elif f.status == "taager_said":
                unknown.insert(0, "refusal charge verified (only 'Taager said' 0)")
            if c == "SAU":
                unknown.append("whether Saudi VAT on the uplift applies to a non-Saudi merchant")
            r["unknown_inputs"] = "; ".join(unknown)
            r["assumptions"] = "A1 A2 A4" + (" A3 (range only)" if f.value is None else "")
            rows.append(r)
    return rows


def write_pricing(offers_csv: str, out_csv: str) -> list[dict]:
    import csv
    rows = pricing_rows(offers_csv)
    keys = list(dict.fromkeys(k for r in rows for k in r))
    with open(out_csv, "w", newline="", encoding="utf-8") as fh:
        w = csv.DictWriter(fh, fieldnames=keys)
        w.writeheader()
        w.writerows(rows)
    return rows


if __name__ == "__main__":
    import os
    import sys
    root = sys.argv[1] if len(sys.argv) > 1 else os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
    print("IRQ sink rack 43,000:", Offer("IRQ", 30000, 34000, 43000).report())
    print("IRQ sink rack, strict (support-chat answers not counted):", Offer("IRQ", 30000, 34000, 43000, strict=True).report())
    print("SAU corner shelf 99:", Offer("SAU", 70, 90, 99).report())
    print("EGY mats 399:", Offer("EGY", 180, 280, 399).report())
    offers = os.path.join(root, "research", "selection", "offers.csv")
    if os.path.exists(offers):
        rows = write_pricing(offers, os.path.join(root, "research", "selection", "pricing.csv"))
        from collections import Counter
        print(f"pricing.csv: {len(rows)} offers;", dict(Counter((r["country"], r["recommended_structure"][:9]) for r in rows)))
