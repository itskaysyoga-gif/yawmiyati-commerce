# Offer research and pricing proposals

31 October 2026. Offline research tools; no scraping, API spend, publishing or live price changes.
These tools supplement `economics.py` and the legacy selection CSVs. They do not replace the live order accounting
or silently reinterpret old estimates as verified facts.

## Start with the existing shortlist

```sh
python3 research/scripts/prepare_offer_evidence.py research/selection/offers.csv > /tmp/offer-evidence.json
python3 research/scripts/offer_decision.py /tmp/one-completed-offer.json
python3 -m unittest discover -s research/scripts -p 'test_*.py'
```

The importer currently produces 157 dossiers: EG 40, IQ 40, SA 40, AE 15, OM 22. These are research candidates, not
approved products. It preserves supplier bundle SKUs and their TOTAL costs. It deliberately leaves outcomes,
current stock, fees, comparisons and verification empty. Fill the evidence before evaluation; an empty scenario
fails input validation. `--as-of` supports reproducible historical analysis, not a way to certify current stock.

## Complete each dossier

- Country-specific `sku`, ISO `currency`, and five mutually exclusive probabilities summing to one: kept, refused,
  returned, cancelled, partial. All are explicit, even zero. Document whether they are assumptions or mature
  observed outcomes; do not multiply unrelated dashboard rates without knowing their denominators.
- Each `tiers` entry has a unique `offer_id`, positive integer quantity in supplier sellable units, delivery zone,
  actual supplier SKU (a multi-unit SKU may differ), total supplier cost, minimum product price, product price,
  customer delivery charge, and final customer total. One mat set may contain two physical pieces; quantity one
  means one sellable set, not one piece. Compare alternative delivery-included proposals with separate offer IDs.
- `supplier_verified=true`, source and timezone-qualified `checked_at` within 48 hours. This means manually
  confirmed evidence, not merely imported from a listing. Stock must also be verified within 48 hours.
- Explicit cost schedule, source and verification date within seven days. Recheck on any supplier change. No fee
  is assumed to be zero because it is absent. Unknown charges block a test recommendation.
- Run separately for each delivery zone; never replace Egypt's zone fees with an average. Mixed-SKU baskets need
  their own supplier quote and shipping test; this tool does not infer them from same-item quantity discounts.

## Accounting contract

All monetary inputs are **integer minor units**: EGP/SAR/AED ×100, IQD ×1, OMR ×1000. Do not mix major and minor units.

- `customer_total = product_price + customer_delivery_charge`: total actually collected from the customer.
- `supplier_total`: cost of all offered units, not a per-unit number multiplied again.
- `fulfilment_cost`: total merchant-borne fulfilment deduction on a kept delivery, including the supplier's
  shipping deduction even when separately charged to the customer. Do not subtract that deduction twice.
- `kept_tax_cost`, `kept_payout_cost`: explicit kept-order deductions, verified per country. Zero requires evidence.
- `refusal_cost`, `return_loss`, `cancellation_cost`, `partial_loss`: NET loss per corresponding outcome,
  after credits, retained revenue and refunds. Partial loss is conservative/nonnegative; a profitable partial
  order requires separate manual accounting, not an invented negative cost here.
- `other_cost`: expected operating expense per submitted order, excluding the outcome costs above.
- `ad_cost`: acquisition expense per submitted order, not cost per delivered order.

Expected contribution = kept probability × (customer total − supplier total − fulfilment − kept tax − kept payout)
minus probability-weighted refusal/return/cancellation/partial losses, other expense, and acquisition expense.
The break-even acquisition cost is contribution before ad cost. The break-even customer total rounds UP to a minor
unit and assumes the supplied costs remain constant at that price. Price-dependent tax or commissions must be
recomputed for every proposed price; this is not a legal/tax calculator or an automatic pricing service.

Delivery included at the same final total has identical economics. Absorbing delivery while keeping the same
product headline reduces contribution; the tool does not invent a conversion uplift. A supplier minimum price
still applies. No automatic blanket free delivery or arbitrary cart threshold.

## Competitor evidence

Each comparison needs a public HTTP(S) source URL, same country and currency, exact identity evidence (model,
variant and contents), quantity, delivery zone, delivered total, observed availability and capture time within
seven days. Different packs/zones, similar-looking items, missing shipping and stale observations are excluded
from the relevant median. Similar products remain useful qualitative research, never exact price anchors.
A median is context, not an instruction to undercut. Do not equate ad presence, engagement or reviews with sales.

## Interpreting the result

`research_required` means evidence is missing; `reject_at_this_offer` means nonpositive contribution under the
provided scenario; `test_candidate` means positive scenario economics ONLY. `best_test_offer_by_zone` compares
contribution per submitted order with the SAME assumed outcomes, not total revenue potential or conversion.
Run conservative/base/optimistic scenarios separately. No forecast of virality or guaranteed sales is produced.

Before publication: verify identity/specs/media rights, native copy, supplier linkage, stock, price rules, and owner
approval using the existing catalog gates. Actual mature order contribution decides what continues after launch.
Demand evidence, automated competitor collection, the operator proposal UI and live integration remain future work.
