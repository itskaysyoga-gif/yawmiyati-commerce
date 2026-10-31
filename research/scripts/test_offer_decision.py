import unittest
from datetime import datetime, timezone
from offer_decision import evaluate

class OfferTests(unittest.TestCase):
    def record(self):
        return {'country': 'OM', 'scenario': {'kept': .5, 'refused': .3, 'returned': .1, 'cancelled': .1},
                'tiers': [{'quantity': 2, 'supplier_total': 10000, 'fulfilment_cost': 3000,
                           'refusal_cost': 1000, 'return_loss': 2000, 'cancellation_cost': 0,
                           'other_cost': 100, 'customer_total': 20000, 'ad_cost': 500,
                           'source': 'test fixture', 'supplier_verified': True,
                           'checked_at': '2026-10-31T00:00:00Z'}]}
    def run_record(self, r):
        return evaluate(r, datetime(2026,10,31,12,tzinfo=timezone.utc))['offers'][0]
    def test_quantity_total_is_not_multiplied_again(self):
        self.assertEqual(self.run_record(self.record())['expected_contribution_minor'], '2400.0')
    def test_unknown_refusal_blocks(self):
        r=self.record(); r['tiers'][0]['refusal_cost']=None
        self.assertIsNone(self.run_record(r)['expected_contribution_minor'])
    def test_stale_tier_blocks(self):
        r=self.record(); r['tiers'][0]['checked_at']='2026-10-01T00:00:00Z'
        self.assertIn('supplier_evidence_stale', self.run_record(r)['blockers'])
    def test_invalid_probability_fails(self):
        r=self.record(); r['scenario']['kept']=.9
        with self.assertRaises(ValueError): self.run_record(r)
    def test_negative_cost_fails(self):
        r=self.record(); r['tiers'][0]['supplier_total']=-1
        with self.assertRaises(ValueError): self.run_record(r)
    def test_unverified_discount_blocks(self):
        r=self.record(); r['tiers'][0]['supplier_verified']=False
        self.assertIn('quantity_cost_unverified', self.run_record(r)['blockers'])

if __name__=='__main__': unittest.main()
