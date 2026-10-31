"""Evaluate country-specific offers from explicit evidence; never change live prices.
Amounts are integer minor currency units. Quantity tiers are total supplier costs.
Run: python3 research/scripts/offer_decision.py evidence.json
"""
import json
import sys
from datetime import datetime, timezone
from decimal import Decimal

COUNTRIES = {'EG', 'IQ', 'SA', 'AE', 'OM'}


def evaluate(record, now=None):
    now = now or datetime.now(timezone.utc)
    if record.get('country') not in COUNTRIES:
        raise ValueError('Country must be EG, IQ, SA, AE or OM')
    quantities = record.get('tiers', [])
    results = []
    for tier in quantities:
        quantity = tier['quantity']
        if not isinstance(quantity, int) or isinstance(quantity, bool) or quantity < 1:
            raise ValueError('Quantity must be a positive integer')
        blockers = []
        for key in ('supplier_total', 'fulfilment_cost', 'refusal_cost', 'return_loss',
                    'other_cost', 'customer_total', 'ad_cost'):
            value = tier.get(key)
            if value is None:
                blockers.append('missing_' + key)
            elif not isinstance(value, int) or isinstance(value, bool) or value < 0:
                raise ValueError(key + ' must be a nonnegative integer in minor units')
        if not tier.get('source') or not tier.get('supplier_verified'):
            blockers.append('quantity_cost_unverified')
        captured = tier.get('checked_at')
        try:
            checked = datetime.fromisoformat(captured.replace('Z', '+00:00'))
            age = (now - checked).total_seconds()
            if age < 0 or age > 48 * 3600:
                blockers.append('supplier_evidence_stale')
        except (AttributeError, ValueError, TypeError):
            blockers.append('invalid_evidence_timestamp')
        rates = record.get('scenario', {})
        parsed = {}
        for key in ('kept', 'refused', 'returned', 'cancelled'):
            try:
                parsed[key] = Decimal(str(rates[key]))
                if not parsed[key].is_finite() or not 0 <= parsed[key] <= 1:
                    raise ValueError()
            except (KeyError, ValueError, ArithmeticError):
                raise ValueError('Explicit outcome probabilities required')
        if sum(parsed.values()) != 1:
            raise ValueError('Outcome probabilities must sum to one')
        # Cancellation costs must be explicit too; no unknown-to-zero fallback.
        cancel = tier.get('cancellation_cost')
        if cancel is None:
            blockers.append('missing_cancellation_cost')
        elif not isinstance(cancel, int) or isinstance(cancel, bool) or cancel < 0:
            raise ValueError('Invalid cancellation cost')
        contribution = None
        if not blockers:
            margin = tier['customer_total'] - tier['supplier_total'] - tier['fulfilment_cost']
            contribution = (parsed['kept'] * margin - parsed['refused'] * tier['refusal_cost']
                            - parsed['returned'] * tier['return_loss']
                            - parsed['cancelled'] * cancel - tier['other_cost'] - tier['ad_cost'])
        results.append({'quantity': quantity, 'blockers': blockers,
                        'expected_contribution_minor': str(contribution) if contribution is not None else None,
                        'decision': 'research_required' if blockers else 'test_candidate' if contribution > 0 else 'reject_at_this_offer',
                        'live_price_changed': False})
    comparisons = []
    for competitor in record.get('competitors', []):
        comparisons.append({'url': competitor.get('url'), 'match': competitor.get('match'),
                            'usable_for_price_comparison': bool(competitor.get('url') and
                                competitor.get('match') == 'exact' and competitor.get('delivered_total') is not None
                                and competitor.get('quantity') and competitor.get('checked_at'))})
    return {'country': record['country'], 'sku': record.get('sku'), 'offers': results,
            'competitors': comparisons, 'sales_volume': 'unknown_unless_separately_evidenced',
            'publication_requires_approval': True}


if __name__ == '__main__':
    with open(sys.argv[1], encoding='utf-8') as handle:
        print(json.dumps(evaluate(json.load(handle)), ensure_ascii=False, indent=2))
