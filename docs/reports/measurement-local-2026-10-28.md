# Measurement test report: local stores, 28 Oct 2026

Scope: the plugin's side of measurement on the local Egypt and Iraq stores (WordPress 7.1.3, WooCommerce 11.2.1),
with placeholder IDs (`GTM-TEST123`, Meta `1234567890`, Snap `snap-test-pixel`). GTM requests were intercepted; Meta and
Snap calls were stored as dry runs (no tokens, outbound HTTP blocked). Run: `node --test tests/e2e/measurement.test.js`.

**Result: 5 of 5 automated checks pass. Live checks L1-L9 (docs/MEASUREMENT.md §9) are not done. Not ready for ads.**

| # | Check | Result |
| --- | --- | --- |
| A1 | Banner shows on first visit; GTM not requested before consent or after "essentials only"; requested once right after "موافقة"; Consent Mode update to granted pushed | pass |
| A2 | Event order on a real purchase: view_item → add_to_cart + begin_checkout → purchase. add_to_cart carries the chosen offer SKU (EG030108BUND99) and price 749 | pass |
| A3 | purchase: transaction_id `EG-<id>` equals ym_event_id; value 749 excludes delivery; shipping 95 passed separately; reload does not fire it again | pass |
| A4 | dataLayer contains none of: customer phone, name, area, building | pass |
| A5 | With consent: Meta Purchase and Snap PURCHASE payloads stored with event_id `IQ-<id>` (same as browser), value 58000 IQD, phone only as SHA-256 of the international number (matches sha256("9647…")), Meta fbp passed through; payloads contain no raw phone, name or address | pass |
| A6 | Without consent ("essentials only"): no server event recorded for the order | pass |
| A7 | Attribution: UTM source and campaign stored on the order without consent; fbclid stored only with consent | pass |

Not covered locally (needs real accounts and a staging domain): GTM tag configuration, GA4 receipt, Meta and Snap
deduplication in their event managers, match quality, pixels loaded by other plugins, OrderDelivered after a real
delivered status. These are L1-L9.
