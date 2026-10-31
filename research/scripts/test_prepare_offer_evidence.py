import unittest
from prepare_offer_evidence import prepare, minor

class ImportTests(unittest.TestCase):
    def test_precision(self):
        self.assertEqual(minor('1.125','OM'),1125)
        self.assertEqual(minor('43000','IQ'),43000)
        self.assertEqual(minor('399','EG'),39900)
        for value in ('1.1', 'NaN', '-1'):
            with self.assertRaises(ValueError): minor(value,'IQ')

    def test_import_is_unverified_and_quantity_cost_remains_total(self):
        r=prepare(dict(country='EGY',taager_sku='ONE',taager_cost='180',taager_min_price='280',
                       proposed_price='399',name_ar='test',multi_sku='TWO',multi_units='2.0',
                       multi_cost='300',multi_min='500'))
        self.assertEqual(r['tiers'][1]['supplier_total'],30000)
        self.assertEqual(r['tiers'][1]['quantity'],2)
        self.assertFalse(r['tiers'][0]['supplier_verified'])
        self.assertIsNone(r['tiers'][0]['refusal_cost'])
        self.assertIsNone(r['scenario']['kept'])
        self.assertEqual(r['competitors'],[])

if __name__=='__main__': unittest.main()
