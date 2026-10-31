"""Evidence-backed offer research. Never publishes products or changes prices.

Money is integer minor units; supplier_total is the TOTAL for the offered quantity.
Run with an evidence JSON file. See docs/OFFER-DECISIONS.md for accounting semantics.
"""
import argparse
import json
from datetime import datetime, timezone
from decimal import Decimal, ROUND_CEILING
from statistics import median
from urllib.parse import urlparse

MARKETS = {'EG': ('EGP', 2), 'IQ': ('IQD', 0), 'SA': ('SAR', 2),
           'AE': ('AED', 2), 'OM': ('OMR', 3)}
OUTCOMES = ('kept', 'refused', 'returned', 'cancelled', 'partial')
COSTS = ('supplier_total', 'fulfilment_cost', 'refusal_cost', 'return_loss',
         'cancellation_cost', 'partial_loss', 'other_cost', 'ad_cost',
         'kept_tax_cost', 'kept_payout_cost', 'customer_total')


def integer(value, field, positive=False):
    if type(value) is not int or value < (1 if positive else 0):
        raise ValueError(f'{field} must be a {"positive" if positive else "nonnegative"} integer in minor units')
    return value


def fresh(value, now, hours):
    try:
        checked = datetime.fromisoformat(value.replace('Z', '+00:00'))
        return checked.tzinfo is not None and 0 <= (now - checked).total_seconds() <= hours * 3600
    except (AttributeError, ValueError, TypeError):
        return False


def probabilities(scenario):
    parsed = {}
    for key in OUTCOMES:
        try:
            value = Decimal(str(scenario[key]))
            if not value.is_finite() or not 0 <= value <= 1:
                raise ValueError()
            parsed[key] = value
        except (KeyError, ValueError, ArithmeticError, TypeError):
            raise ValueError('Explicit outcome probabilities required, including partial returns') from None
    if sum(parsed.values()) != 1:
        raise ValueError('Outcome probabilities must sum to one')
    return parsed


def comparison(item, country, now):
    reasons = []
    url = urlparse(str(item.get('url', '')))
    if url.scheme not in ('http', 'https') or not url.hostname or url.username or url.password:
        reasons.append('invalid_source_url')
    if item.get('country') != country or item.get('currency') != MARKETS[country][0]:
        reasons.append('different_market_or_currency')
    if item.get('match') != 'exact' or not item.get('identity_evidence'):
        reasons.append('exact_identity_not_evidenced')
    if item.get('in_stock') is not True:
        reasons.append('availability_unverified')
    if not fresh(item.get('checked_at'), now, 7 * 24):
        reasons.append('comparison_stale_or_undated')
    for key in ('quantity', 'delivered_total'):
        try:
            integer(item.get(key), key, positive=True)
        except ValueError:
            reasons.append('invalid_' + key)
    return {**item, 'usable_for_price_comparison': not reasons, 'exclusions': reasons}


def evaluate(record, now=None):
    now = now or datetime.now(timezone.utc)
    country = record.get('country')
    if country not in MARKETS or record.get('currency') != MARKETS[country][0]:
        raise ValueError('Country and ISO currency must match')
    if not isinstance(record.get('sku'), str) or not record['sku'].strip():
        raise ValueError('Country-specific supplier SKU required')
    tiers = record.get('tiers')
    if not isinstance(tiers, list) or not tiers:
        raise ValueError('At least one offer is required')
    rates = probabilities(record.get('scenario', {}))
    comparisons = [comparison(c, country, now) for c in record.get('competitors', [])]
    results, ids = [], set()
    for tier in tiers:
        offer_id = tier.get('offer_id')
        if not isinstance(offer_id, str) or not offer_id.strip() or offer_id in ids:
            raise ValueError('Unique nonempty offer_id required')
        ids.add(offer_id)
        quantity = integer(tier.get('quantity'), 'quantity', positive=True)
        blockers = []
        for key in COSTS:
            if tier.get(key) is None:
                blockers.append('missing_' + key)
            else:
                integer(tier[key], key)
        if not tier.get('source') or tier.get('supplier_verified') is not True:
            blockers.append('quantity_cost_unverified')
        if not fresh(tier.get('checked_at'), now, 48):
            blockers.append('supplier_evidence_stale')
        if tier.get('costs_verified') is not True or not tier.get('costs_source'):
            blockers.append('cost_schedule_unverified')
        if not fresh(tier.get('costs_checked_at'), now, 7 * 24):
            blockers.append('cost_schedule_stale')
        if tier.get('stock_verified') is not True or not fresh(tier.get('stock_checked_at'), now, 48):
            blockers.append('stock_unverified_or_stale')
        delivery = tier.get('customer_delivery_charge')
        price = tier.get('product_price')
        minimum = tier.get('supplier_minimum_product_price')
        for key, value in [('customer_delivery_charge', delivery), ('product_price', price),
                           ('supplier_minimum_product_price', minimum)]:
            if value is None:
                blockers.append('missing_' + key)
            else:
                integer(value, key)
        if price is not None and delivery is not None and tier.get('customer_total') is not None:
            if price + delivery != tier['customer_total']:
                blockers.append('customer_total_mismatch')
        if price is not None and minimum is not None and price < minimum:
            blockers.append('below_supplier_minimum')
        # Each tier is for one actual delivery zone, never an average fee.
        if not tier.get('delivery_zone'):
            blockers.append('delivery_zone_missing')
        comparable = [c['delivered_total'] for c in comparisons
                      if c['usable_for_price_comparison'] and c['quantity'] == quantity
                      and c.get('delivery_zone') == tier.get('delivery_zone')]
        contribution = break_even = floor = None
        if not blockers:
            fixed = (rates['refused'] * tier['refusal_cost'] + rates['returned'] * tier['return_loss']
                     + rates['cancelled'] * tier['cancellation_cost'] + rates['partial'] * tier['partial_loss']
                     + tier['other_cost'])
            kept_cost = tier['supplier_total'] + tier['fulfilment_cost'] + tier['kept_tax_cost'] + tier['kept_payout_cost']
            break_even = rates['kept'] * (tier['customer_total'] - kept_cost) - fixed
            contribution = break_even - tier['ad_cost']
            if rates['kept']:
                floor = int((kept_cost + (fixed + tier['ad_cost']) / rates['kept']).to_integral_value(rounding=ROUND_CEILING))
        results.append({'offer_id': offer_id, 'quantity': quantity, 'delivery_zone': tier.get('delivery_zone'),
                        'blockers': blockers, 'customer_total_minor': tier.get('customer_total'),
                        'delivery_included': delivery == 0,
                        'expected_contribution_minor': str(contribution) if contribution is not None else None,
                        'break_even_ad_cost_minor': str(break_even) if break_even is not None else None,
                        'break_even_customer_total_minor': floor,
                        'comparable_offer_count': len(comparable),
                        'competitor_delivered_median_minor': str(median(comparable)) if comparable else None,
                        'decision': 'research_required' if blockers else 'test_candidate' if contribution > 0 else 'reject_at_this_offer',
                        'live_price_changed': False})
    # Compare only within country AND zone. These are conditional scenario results, not conversion predictions.
    best = {}
    for result in results:
        if result['decision'] == 'test_candidate':
            zone = result['delivery_zone']
            if zone not in best or Decimal(result['expected_contribution_minor']) > Decimal(best[zone]['expected_contribution_minor']):
                best[zone] = result
    return {'country': country, 'currency': MARKETS[country][0], 'minor_unit_decimals': MARKETS[country][1],
            'sku': record['sku'], 'scenario': {k: str(v) for k, v in rates.items()}, 'offers': results,
            'best_test_offer_by_zone': {zone: result['offer_id'] for zone, result in best.items()},
            'competitors': comparisons, 'sales_volume': 'unknown_unless_separately_evidenced',
            'warning': 'Scenario economics only; demand, conversion uplift and live integration remain unproven.',
            'publication_requires_approval': True}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('evidence')
    parser.add_argument('--as-of', help='ISO timestamp for reproducible historical research; not a fresh stock check')
    args = parser.parse_args()
    now = datetime.fromisoformat(args.as_of.replace('Z', '+00:00')) if args.as_of else None
    if now is not None and now.tzinfo is None:
        parser.error('--as-of must include a timezone')
    with open(args.evidence, encoding='utf-8') as handle:
        data = json.load(handle)
    records = data if isinstance(data, list) else [data]
    print(json.dumps([evaluate(record, now) for record in records], ensure_ascii=False, indent=2))


if __name__ == '__main__':
    main()
