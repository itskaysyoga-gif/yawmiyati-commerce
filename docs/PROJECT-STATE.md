# Project state

_Last updated: 2026-10-28 (end of session)_

Status words used everywhere in this repo:
- **Designed**: exists in the design canvas only (Claude artifact "Yawmiyati Store Design").
- **Implemented**: code in this repo runs on the local stores.
- **Tested**: covered by an automated check that passes (`npm test`, `tests/integration/run.php`). Local only.
- **Supplier-verified**: confirmed against Taager itself (a real test order, the live catalog or a written answer we can point to). A broad support reply is recorded as "Taager said", not as verified.

Nothing is supplier-verified yet. There is no live store, no Taager link and no real order.

## Where things stand

| Area | Designed | Implemented | Tested | Supplier-verified |
| --- | --- | --- | --- | --- |
| Visual direction A (indigo diary): PDP, home, checkout | yes (v2 canvas) | yes (`theme/yawmiyati`) | screenshots only | — |
| Visual direction B (burgundy boutique) | yes, not recommended | no | — | — |
| Product page with offer groups (1 set / 2 sets; rack / rack + mats) | yes | yes | yes | — |
| Iraq default = rack alone 50,000 total; bundle optional 65,000 | yes | yes | yes | — |
| Delivery fee by governorate (EG 95/100/105, IQ 7,000) | yes | yes | yes | Taager published policy + support answer, not a test order |
| COD-only classic checkout, phone rules, address, landmark, second phone | yes | yes | yes | Taager plugin hook behaviour unknown |
| Server-side price (offer must belong to the page, injected prices ignored) | — | yes | yes | — |
| Double submission / double click = one order | — | yes | yes | Taager side unknown |
| Duplicate review (same phone + product in 24 h held on-hold) | — | yes | yes | Taager handling of on-hold unknown |
| Order snapshot (prices, fee, policy version) | — | yes | yes | — |
| Supplier hand-off watchdog (pending → sent / failed → manual) | — | yes | yes (simulated reference) | real meta key Taager writes unknown |
| Cart (add to cart, one delivery fee per order) | yes | WooCommerce standard cart | partly (fee once per order) | Taager said one fee per order |
| Order bump, cart suggestions, quantity breaks beyond offer groups | brief only | no | — | — |
| Product data: exact SKUs, Taager IDs, costs, prices (`plugin/.../seed/*.json`) | — | yes | yes (SKU, ID, price > cost) | catalog export, not live; stock not checked |
| Specs, sizes, materials | — | unknown rows show `[من تاجر]` locally and are left out in production | — | not yet |
| Product media | — | text-free crops of Taager images (`media/interim/`) | — | Taager said supplier images may be used |
| Portable preview | — | `tools/export-preview.js` | rendered and link-checked | — |
| CI on GitHub | — | `.github/workflows/test.yml` | not run yet | — |
| Content studio, ads, Manychat | brief only | no | — | — |

Latest preview: Claude artifact "Yawmiyati Store Preview" (static snapshot of the real build).

## Verification tasks (open; each needs a real store, a test order or a written answer)
1. Taager WooCommerce plugin: which hook it uses, the order meta key it writes (set it in `ym_supplier_ref_keys`), and what it does with on-hold orders.
2. Governorate mapping: our codes (WooCommerce EG codes; custom IQ codes) against Taager's province names.
3. Stock: per-offer check before each offer goes live (Taager has no stock sync).
4. Payout: whether a third-party (brother's UAE) bank beneficiary is accepted. "Any bank account or Payoneer" is not an answer to that.
5. Iraq return window: 3 days (policy) vs 14 days (tips).
6. Status sync back to WooCommerce: confirm with a test order.
7. SiteGround GrowBig MySQL/MariaDB version (local dev runs MariaDB 10.11).
8. Delivery fee for a 2-item order on a real Taager order.

## Waiting on Rashid
- A yes on design direction A (recommended) before polishing further.
- Buying yawmiyati.shop and SiteGround GrowBig (approved, not bought). Both block the Taager link and test orders.

## Next
1. After Rashid's yes on direction A: staging site on SiteGround, Taager plugin linked to the Egypt store, one real test order through the full flow, then close verification tasks 1, 2, 6 and 8.
2. Fill specs from Taager product pages; replace interim photos.
3. Only then: more products, cart extras (order bump, suggestions), content studio and ads.

## How to run locally
See README.md.
