import copy
import unittest
from datetime import datetime, timezone
from offer_decision import evaluate, MARKETS

NOW = datetime(2026, 10, 31, 12, tzinfo=timezone.utc)


def fixture():
    return {'country': 'OM', 'currency': 'OMR', 'sku': 'TEST-ONLY',
            'scenario': {'kept': '.5', 'refused': '.3', 'returned': '.1', 'cancelled': '.1', 'partial': '0'},
            'tiers': [{'offer_id': 'two', 'quantity': 2, 'supplier_total': 10000, 'fulfilment_cost': 3000,
                       'refusal_cost': 1000, 'return_loss': 2000, 'cancellation_cost': 0, 'partial_loss': 0,
                       'other_cost': 100, 'customer_total': 20000, 'ad_cost': 500, 'kept_tax_cost': 0,
                       'kept_payout_cost': 0, 'source': 'synthetic fixture', 'supplier_verified': True,
                       'checked_at': '2026-10-31T00:00:00Z', 'costs_verified': True,
                       'costs_source': 'synthetic fixture', 'costs_checked_at': '2026-10-31T00:00:00Z',
                       'stock_verified': True, 'stock_checked_at': '2026-10-31T00:00:00Z',
                       'delivery_zone': 'test-zone', 'product_price': 17000, 'customer_delivery_charge': 3000,
                       'supplier_minimum_product_price': 12000}]}


class OfferTests(unittest.TestCase):
    def first(self, record):
        return evaluate(record, NOW)['offers'][0]

    def test_quantity_total_not_multiplied(self):
        result = self.first(fixture())
        self.assertEqual(result['expected_contribution_minor'], '2400.0')
        self.assertEqual(result['break_even_ad_cost_minor'], '2900.0')
        self.assertEqual(result['break_even_customer_total_minor'], 15200)

    def test_every_unknown_cost_blocks_even_at_zero_outcome_rate(self):
        for field in ('refusal_cost', 'return_loss', 'kept_tax_cost', 'kept_payout_cost', 'partial_loss'):
            with self.subTest(field=field):
                r = fixture(); r['tiers'][0][field] = None
                self.assertIsNone(self.first(r)['expected_contribution_minor'])

    def test_bad_supplier_dates_block(self):
        for date in ('2026-10-01T00:00:00Z', '2026-11-01T00:00:00Z', '2026-10-31', None):
            r = fixture(); r['tiers'][0]['checked_at'] = date
            self.assertIn('supplier_evidence_stale', self.first(r)['blockers'])

    def test_bad_probabilities_fail(self):
        for value in ('.9', 'NaN', 'Infinity', '-.1', True):
            r = fixture(); r['scenario']['kept'] = value
            with self.assertRaises(ValueError): self.first(r)

    def test_invalid_money_fails(self):
        for value in (-1, True, 3.5, '100'):
            r = fixture(); r['tiers'][0]['supplier_total'] = value
            with self.assertRaises(ValueError): self.first(r)

    def test_verification_must_be_boolean(self):
        r = fixture(); r['tiers'][0]['supplier_verified'] = 'false'
        self.assertIn('quantity_cost_unverified', self.first(r)['blockers'])

    def test_free_delivery_deducts_real_fulfilment(self):
        r = fixture(); r['tiers'][0].update(product_price=20000, customer_delivery_charge=0)
        self.assertEqual(self.first(r)['expected_contribution_minor'], '2400.0')
        self.assertTrue(self.first(r)['delivery_included'])
        r['tiers'][0].update(product_price=17000, customer_total=17000)
        self.assertEqual(self.first(r)['expected_contribution_minor'], '900.0')

    def test_omr_currency_precision_all_markets(self):
        for country, (currency, decimals) in MARKETS.items():
            r = fixture(); r.update(country=country, currency=currency)
            self.assertEqual(evaluate(r, NOW)['minor_unit_decimals'], decimals)
        r['currency'] = 'USD'
        with self.assertRaises(ValueError): self.first(r)

    def test_partial_returns_and_tax_payout(self):
        r = fixture(); r['scenario'].update(refused='.2', partial='.1')
        r['tiers'][0].update(partial_loss=4000, kept_tax_cost=200, kept_payout_cost=100)
        self.assertEqual(self.first(r)['expected_contribution_minor'], '1950.0')

    def test_zero_kept_has_no_price_floor(self):
        r = fixture(); r['scenario'].update(kept='0', refused='.8')
        self.assertIsNone(self.first(r)['break_even_customer_total_minor'])
        self.assertEqual(self.first(r)['decision'], 'reject_at_this_offer')

    def test_total_and_minimum_block(self):
        r = fixture(); r['tiers'][0]['product_price'] = 10000
        self.assertIn('below_supplier_minimum', self.first(r)['blockers'])
        self.assertIn('customer_total_mismatch', self.first(r)['blockers'])

    def test_comparisons_exclude_wrong_or_missing_evidence(self):
        good = dict(url='https://example.test/item', country='OM', currency='OMR', match='exact',
                    identity_evidence='verified model and box contents', in_stock=True, quantity=2,
                    delivered_total=21000, checked_at='2026-10-31T00:00:00Z', delivery_zone='test-zone')
        r = fixture(); r['competitors'] = [good]
        self.assertEqual(self.first(r)['comparable_offer_count'], 1)
        for field, value in [('country','AE'), ('currency','AED'), ('quantity',1), ('match','similar'),
                             ('delivered_total',True), ('checked_at','2026-10-01'), ('in_stock',False),
                             ('url','javascript:alert(1)'), ('identity_evidence',''), ('delivery_zone','elsewhere')]:
            r['competitors'] = [{**good, field: value}]
            self.assertEqual(self.first(r)['comparable_offer_count'], 0, field)

    def test_unique_ids_and_nonempty_offers(self):
        r = fixture(); r['tiers'] *= 2
        with self.assertRaises(ValueError): self.first(r)
        r['tiers'] = []
        with self.assertRaises(ValueError): self.first(r)

    def test_best_test_offer_is_per_zone_and_excludes_blocked(self):
        r = fixture()
        r['tiers'].extend([dict(r['tiers'][0], offer_id='other-zone', delivery_zone='elsewhere'),
                           dict(r['tiers'][0], offer_id='higher', product_price=18000, customer_total=21000),
                           dict(r['tiers'][0], offer_id='unknown', customer_total=999999, refusal_cost=None)])
        self.assertEqual(evaluate(r, NOW)['best_test_offer_by_zone'], {'test-zone':'higher','elsewhere':'other-zone'})

if __name__ == '__main__': unittest.main()
