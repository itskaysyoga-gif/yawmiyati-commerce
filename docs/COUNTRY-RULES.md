# Country rules (from Taager's published shipping policy, captured Sept 2026; recheck before launch)

| | Egypt | Iraq | Saudi Arabia | UAE | Oman |
| --- | --- | --- | --- | --- | --- |
| Currency | EGP | IQD | SAR | AED | OMR (3 decimals) |
| Delivery fee paid by customer | 95 / 100 / 105 by zone | 7,000 | 28 | 19 | 3 |
| Refusal or return fee to merchant | 5 EGP shipped-not-received, 5 EGP delivered-then-returned | not published (support said 0) | not published | not published | not published |
| Confirmation calls | 11:00-21:00; before 19:00 = same day | 09:00-17:00; before 15:00 = same day | 09:00-17:00 | 09:00-17:00 | 09:00-17:00 |
| Delivery time | 2 days Cairo/Giza/Alex; up to 5 days far zones | not published | 2-3 days Riyadh, Jeddah, Dammam; 4-5 elsewhere (by city, so not shown per region) | 24-48 h | not published |
| Returns | 14 days for defects via Jawda; personal care not returnable | 3-day money back (policy) vs 14 days (tips): asked | 7 days, refund to bank account | 7 days, refund to bank account | 15 days for defects or mismatch, cash refund via courier |
| Phone (national format) | 01[0125] + 8 digits | 07 + 9 digits | 05 + 8 digits | 05[024568] + 7 digits | [79] + 7 digits (no trunk 0) |
| Regions in the form | 27 governorates | 18 governorates | 13 regions | 7 emirates | 11 governorates |
| VAT on uplift | — | — | 15 % of the profit added above the minimum (published); whether it applies to a non-Saudi merchant: ask | — | — |
| Package opening before payment | Not allowed | Not allowed | — | — | — |

Egypt zones: Zone 1 Cairo, Giza, Alexandria (95). Zone 2 Delta and Canal (100). Zone 3 Upper Egypt, Sinai, Red Sea, Matrouh, North Coast (105).

Profit is credited 6-12 h after delivery and withdrawable 24 h later. Refused or cancelled orders earn nothing.
Taager advice that shapes the form: full name, second phone number, detailed address with a landmark, total including delivery confirmed with the customer.

Implemented in `plugin/yawmiyati-commerce/config/countries.php` (all five countries; gates in `config/markets.json`, docs/MARKETS.md) (fees by governorate, phone rules, currency, dialect copy). Change this file first, then the code.

## Taager answers to Rashid, 28 Oct 2026 (chat with Taager support)
These are what Taager support said. None is supplier-verified until a real store and test order confirm it (see PROJECT-STATE.md, verification tasks).
- One Taager account serves every country; each country needs its own store.
- WooCommerce orders reach Taager automatically; order statuses sync back automatically. Stock does not sync; an assigned account manager advises stock.
- A multi-item order pays one delivery fee.
- Supplier images may be used.
- No deduction when a customer refuses (Egypt's published policy still lists 5 EGP; model Egypt at 5, Iraq at 0).
- Profit can be withdrawn to any bank account or Payoneer. This does not answer whether a third-party beneficiary (the brother's UAE account) is accepted: still open.

## Open questions that change customer-facing text (verification tasks)
- Who the customer hears on the confirmation call and what name is given; what the parcel and invoice show; who
  handles returns and how refunds are paid. Customer text currently names no one ("هيوصلك اتصال").
- Iraq: return window (3 or 14 days) and delivery time per governorate. Iraq pages show these as pending.
- Governorate mapping to Taager province names (our Egypt codes are WooCommerce/ISO codes; Iraq codes are ours).
