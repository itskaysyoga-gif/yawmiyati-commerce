#!/usr/bin/env python3
"""Recommended first assortment for Egypt and Iraq (proposal for Rashid's approval).

Reads research/selection/offers.csv (catalog facts and item-level competitor evidence, captured 2026-10-28 from an
undated Taager export) and writes research/selection/first_wave.csv with the proposed price, the contribution
scenarios and the checks still open for each offer.

Prices are raised only where competitor items were confirmed as likely the same item (the Egypt rack); elsewhere the
launch v2 price stands.

Contribution per submitted order, as in docs/PRICING.md:
    E = p_kept * M - (1 - p_kept) * F            (every order not kept is treated as refused at the door)
    M = proposed price - Taager cost             (no VAT in Egypt or Iraq)
    F = refusal charge: Egypt 5 EGP (published); Iraq 0 ("Taager said", support chat) and a stress case F = 7,000
        (one delivery fee), because the Iraq figure is not in writing.
E with no ad cost is the break-even cost per submitted order (CPA). Rates are scenarios: there is no order history.
"""
import csv
import os

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OFFERS = os.path.join(ROOT, 'research/selection/offers.csv')
OUT = os.path.join(ROOT, 'research/selection/first_wave.csv')

# (country, sku, role, name_en, proposed price, why this price, checks beyond the common ones)
PICKS = [
    ('EGY', 'EG030108BUND99', 'lead offer (ads)', 'Absorbent mats, 2 sets (4 pieces)', 749,
     'launch v2 price kept; 2 x 399 less 49; one delivery fee by construction (one Taager SKU)', 'stock not reported for bundle SKUs: check in Taager'),
    ('EGY', 'EG030106MKKT99', 'lead product, single option', 'Absorbent mats, 1 set (2 pieces)', 399,
     'kept: similar sets median 379 (n=2); identical 580 is one offer only', ''),
    ('EGY', 'EG030106STPG99', 'core', 'Double clothes rack on wheels', 689,
     'raised from 649: identical items median 842 (n=4, 3 confirmed as likely the same item); 689 keeps the customer total under 800 in every governorate', 'stock was under 50 units: confirm depth before ads; drop the word "imported" (unverified)'),
    ('EGY', 'EG030401GB99', 'seasonal (Nov-Feb)', 'Wearable blanket, grey', 629,
     'kept at 629 (lowered from 649 in launch v2): identical median 739 (n=3) but none confirmed as the same item', 'size and fabric must be stated'),
    ('EGY', 'EG030301HAEE99', 'seasonal, conditional', '2-in-1 heater with remote', 799,
     'kept: identical median 899 (n=3), delivered 919; customer total crosses 800, so no rise',
     'electrical: wattage, voltage, plug, thermal cut-off, tip-over switch and warranty in writing before publishing'),
    ('IRQ', 'IQ030403SENK99', 'lead product (ads)', 'Over-sink rack', 43000,
     'kept: identical median 60,650 (n=2), delivered 50,799', 'dimensions (sink width it fits) must be stated'),
    ('IRQ', 'IQ030501SDMN99', 'quantity option', 'Over-sink rack + 2-piece absorbent mats', 58000,
     'launch v2 price kept: 43,000 + 18,000 less 3,000; one Taager SKU', 'stock not reported in Iraq at all'),
    ('IRQ', 'IQ030106MONZ99', 'core', 'Corner clothes organizer', 28000,
     'kept: identical median 36,999 (n=3, delivered 35,500) but none confirmed as the same item; raise only after a match', 'assembly and size must be stated'),
    ('IRQ', 'IQ030301SOBA99', 'seasonal, conditional', 'Round 180-degree electric heater', 32000,
     'kept: identical 40,599 (n=1) but similar median 31,500 (n=3); evidence too thin to raise',
     'electrical: wattage, voltage, plug, thermal cut-off, tip-over switch and warranty in writing before publishing'),
    ('IRQ', 'IQ030106MATT99', 'add-on (not advertised alone)', 'Absorbent mats, 2 pieces', 18000,
     'kept: identical 22,500 (n=1, delivery not shown)', 'margin too thin to carry its own ads; sold with the rack and as a second item'),
]
RESERVE = [
    ('IRQ', 'IQ030104STAN99', 'Double clothes rack (test row in launch v2): one identical offer only'),
    ('IRQ', 'IQ030107MASA99', 'Magnetic window cleaner: glass thickness unknown, breakage in transit'),
]
F = {'EGY': 5.0, 'IRQ': 0.0}
F_STRESS = {'EGY': 5.0, 'IRQ': 7000.0}
FEE = {'EGY': '95 / 100 / 105 by governorate', 'IRQ': '7,000 everywhere'}
COMMON = ('current price and minimum in Taager (export undated); stock checked < 48 h before ads; '
          'dimensions, materials and box contents from Taager in writing; original media with written rights '
          '(clean, detail, scale views); photos show this exact SKU')


def E(p, M, f):
    return p * M - (1 - p) * f


def main():
    offers = {(r['country'], r['taager_sku']): r for r in csv.DictReader(open(OFFERS, encoding='utf-8'))}
    rows = []
    for cc, sku, role, name_en, price, why, extra in PICKS:
        o = offers[(cc, sku)]
        cost, mn = float(o['taager_cost']), float(o['taager_min_price'])
        assert price >= mn, (sku, price, mn)
        M = price - cost
        fee_lo = 95 if cc == 'EGY' else 7000
        fee_hi = 105 if cc == 'EGY' else 7000
        row = {
            'country': cc, 'role': role, 'taager_product_id': o['taager_product_id'], 'taager_sku': sku,
            'name_ar': o['name_ar'], 'name_en': name_en, 'currency': o['currency'],
            'taager_cost': cost, 'taager_min_price': mn, 'launch_v2_price': o['proposed_price'], 'proposed_price': price,
            'price_reason': why, 'customer_delivery_fee': FEE[cc],
            'customer_total': f'{price + fee_lo:,.0f}' + ('' if fee_lo == fee_hi else f'-{price + fee_hi:,.0f}'),
            'margin_per_kept_order': M,
        }
        for p in (0.30, 0.45, 0.60):
            row[f'breakeven_cpa_kept{int(p * 100)}'] = round(E(p, M, F[cc]), 1)
        row['breakeven_cpa_kept45_if_refusal_fee_is_one_delivery_fee'] = round(E(0.45, M, F_STRESS[cc]), 1)
        row['stock_at_export'] = o['stock_status'] or 'not reported'
        row['competitor_evidence'] = o['competitor_comparisons'].split(' || source')[0]
        row['evidence_source'] = 'research/data/benchmarks_%s_v2.csv (captured 2026-10-28)' % ('egy' if cc == 'EGY' else 'irq')
        row['open_checks'] = COMMON + ('; ' + extra if extra else '')
        row['kill_gate'] = ('pause the ad set when spend reaches 3 x break-even CPA (45 %) with no order, or when cost per '
                            'submitted order stays above break-even (45 %) after 20 orders; pause the product when the '
                            'delivered-and-kept rate of its first 30 mature orders is below 35 %')
        rows.append(row)
    with open(OUT, 'w', newline='', encoding='utf-8') as fh:
        w = csv.DictWriter(fh, fieldnames=list(rows[0].keys()))
        w.writeheader()
        w.writerows(rows)
    for r in rows:
        print(r['country'], r['taager_sku'], r['proposed_price'], r['margin_per_kept_order'], r['breakeven_cpa_kept30'], r['breakeven_cpa_kept45'], r['breakeven_cpa_kept60'], r['breakeven_cpa_kept45_if_refusal_fee_is_one_delivery_fee'], r['stock_at_export'][:24])
    for cc, sku, why in RESERVE:
        print('reserve', cc, sku, why)


if __name__ == '__main__':
    main()
