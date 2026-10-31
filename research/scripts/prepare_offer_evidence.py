"""Convert the existing shortlist to INCOMPLETE research dossiers, never verified offers.
Usage: python3 research/scripts/prepare_offer_evidence.py research/selection/offers.csv
The result is JSON on stdout. Supply current supplier evidence before evaluating.
"""
import csv
import json
import sys
from decimal import Decimal
from offer_decision import MARKETS

COUNTRIES = {'EGY':'EG', 'IRQ':'IQ', 'SAU':'SA', 'ARE':'AE', 'OMN':'OM'}


def minor(value, country):
    if value in ('', None):
        return None
    amount = Decimal(str(value)) * (10 ** MARKETS[country][1])
    if not amount.is_finite() or amount < 0 or amount != amount.to_integral_value():
        raise ValueError('Invalid currency precision: ' + str(value))
    return int(amount)


def prepare(row):
    country = COUNTRIES[row['country']]
    def tier(quantity, sku, cost, minimum, product_price):
        return {'offer_id': sku + '-zone-pending', 'quantity': quantity, 'supplier_sku': sku,
                'supplier_total': minor(cost, country), 'supplier_minimum_product_price': minor(minimum, country),
                'product_price': minor(product_price, country), 'customer_total': None,
                'customer_delivery_charge': None, 'delivery_zone': None, 'fulfilment_cost': None,
                'refusal_cost': None, 'return_loss': None, 'cancellation_cost': None, 'partial_loss': None,
                'kept_tax_cost': None, 'kept_payout_cost': None, 'other_cost': None, 'ad_cost': None,
                'source': row.get('price_source'), 'checked_at': None, 'supplier_verified': False,
                'costs_source': None, 'costs_checked_at': None, 'costs_verified': False,
                'stock_checked_at': None, 'stock_verified': False}
    tiers = [tier(1, row['taager_sku'], row['taager_cost'], row['taager_min_price'], row['proposed_price'])]
    if row.get('multi_sku'):
        quantity = Decimal(row['multi_units'])
        if quantity < 2 or quantity != quantity.to_integral_value():
            raise ValueError('Multi-unit quantity must be a whole number >= 2')
        tiers.append(tier(int(quantity), row['multi_sku'], row['multi_cost'], row['multi_min'], None))
    return {'country': country, 'currency': MARKETS[country][0], 'sku': row['taager_sku'],
            'research_title': row['name_ar'], 'scenario': dict.fromkeys(('kept','refused','returned','cancelled','partial')),
            'scenario_status': 'not supplied; choose explicit assumptions or measured mature outcomes',
            'tiers': tiers, 'competitors': [], 'demand_evidence': [],
            'legacy_candidate_score': row.get('score_total'),
            'warning': 'Imported catalog data is stale and unverified. Legacy score is a research priority, not sales probability. Quantity is supplier sellable units, not pieces inside a set.'}


if __name__ == '__main__':
    with open(sys.argv[1], encoding='utf-8', newline='') as handle:
        records = [prepare(row) for row in csv.DictReader(handle) if row.get('existing_status') != 'reject']
    print(json.dumps(records, ensure_ascii=False, indent=2))
