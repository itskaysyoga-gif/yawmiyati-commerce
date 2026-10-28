"""Contribution economics per submitted order, per docs/PRODUCT-STANDARDS.md.

contribution = p * (S - C - V - R - W) - (1 - p) * F - O - A
break-even CPA = the same without A.

Unknown costs are passed explicitly; the report says which ones were assumed.
"""
from dataclasses import dataclass

DELIVERY_FEE = {"EGY": 100, "IRQ": 7000, "SAU": 28, "ARE": 19, "OMN": 3}     # paid by the customer (Egypt: 95/100/105 by zone)
REFUSAL_FEE = {"EGY": 5, "IRQ": None, "SAU": None, "ARE": None, "OMN": None}  # None = not published
VAT_ON_UPLIFT = {"SAU": 0.15}
SCENARIOS = (0.30, 0.45, 0.60)


@dataclass
class Offer:
    country: str
    taager_cost: float      # C
    taager_min: float       # Taager's minimum selling price
    selling_price: float    # S
    return_allowance: float = 0.0   # R, per delivered order
    payout_cost: float = 0.0        # W, per delivered order
    operating_cost: float = 0.0     # O, per submitted order
    refusal_fee: float | None = None  # F; defaults to the country's published fee

    def vat(self) -> float:
        rate = VAT_ON_UPLIFT.get(self.country, 0.0)
        return rate * max(0.0, self.selling_price - self.taager_min)

    def margin_per_delivery(self) -> float:
        return self.selling_price - self.taager_cost - self.vat() - self.return_allowance - self.payout_cost

    def fee(self) -> tuple[float, bool]:
        f = self.refusal_fee if self.refusal_fee is not None else REFUSAL_FEE.get(self.country)
        return (0.0, True) if f is None else (float(f), False)

    def break_even_cpa(self, p: float) -> float:
        f, _ = self.fee()
        return p * self.margin_per_delivery() - (1 - p) * f - self.operating_cost

    def customer_total(self) -> float:
        return self.selling_price + DELIVERY_FEE[self.country]

    def report(self) -> dict:
        _, fee_unknown = self.fee()
        out = {"customer_pays": self.customer_total(), "margin_per_delivery": round(self.margin_per_delivery(), 2),
               "valid_price": self.selling_price >= self.taager_min, "refusal_fee_unknown": fee_unknown}
        for p in SCENARIOS:
            out[f"breakeven_cpa_{int(p*100)}"] = round(self.break_even_cpa(p), 2)
        return out


if __name__ == "__main__":
    # Worked example from the brief: Iraq sink rack
    print(Offer("IRQ", 30000, 34000, 45000).report())
