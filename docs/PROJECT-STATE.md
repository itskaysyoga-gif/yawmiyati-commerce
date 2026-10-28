# Project state

_Last updated: 2026-10-28 (evening session)_

Status words used everywhere in this repo:
- **Designed**: exists only on the design canvas.
- **Implemented**: code in this repo runs on the local stores.
- **Tested**: an automated check passes (`npm test`, `tests/integration/run.php`) locally and in GitHub Actions (42 end-to-end tests green in CI on 28 Oct, including the iPhone/WebKit flow, which is skipped locally). No Taager involved.
- **Supplier-verified**: confirmed against Taager itself (a real test order, the live catalog, or a written answer). A support chat answer is recorded as "Taager said", not verified.

Nothing is supplier-verified. There is no live store, no Taager link and no real order.
Repository: GitHub reports it as **public**; Rashid to switch it to private (launch checklist #1).

## Where things stand
| Area | Designed | Implemented | Tested | Supplier-verified |
| --- | --- | --- | --- | --- |
| Warm Yawmiyati look (ivory, white, berry; blush/sage accents; 4:5 full-product frames) | via the build | yes | screenshots | — |
| Home, product page, checkout, order received, cart | — | yes | yes | — |
| Country switcher (header) and support/policy pages (delivery, returns, FAQ, contact, privacy) | — | yes | rendered | policy facts partly pending |
| Offer groups, Iraq rack default 50,000 / bundle 65,000 | — | yes | yes | — |
| Delivery fee by governorate | — | yes | yes | Taager said |
| COD checkout, dialect labels and errors | — | yes | yes | plugin hook unknown |
| Buy now keeps the rest of the cart | — | yes | yes | — |
| One order per submission: overlapping requests, retries replay to the same order, failed creation releases the token | — | yes | yes (real parallel requests) | Taager duplicate handling unknown |
| Order page privacy (details only to the ordering browser) | — | yes | yes | — |
| Catalog gate (identity, specs, media, price, stock) on staging/production | — | yes | yes | no product passes yet |
| Seed command refuses production | — | yes | yes | — |
| Supplier states: pending → sent / unconfirmed → not_found → manual; conflict detection | — | yes | yes (simulated reference) | real meta key unknown |
| Measurement: consent, dataLayer, attribution, Meta/Snap CAPI (dry run) | spec | yes | yes (local) | live checks L1-L9 open |
| SEO: noindex outside production, canonicals, hreflang (home), Product JSON-LD, OG, sitemaps | — | yes | yes (rendered pages) | Search Console at launch |
| Scheduled jobs, alerts, health endpoint, funnel, launch-check | — | yes | partly (sweep, health) | status sync unknown |
| Copy: per-country files, voice guides, language lint | — | yes | lint only | native review not done |
| Anthropic copy drafts (GitHub Actions) | — | yes | drafts for all 10 product pages produced and auto-checked (branches copy-drafts/iq-40742863270, copy-drafts/eg-40743731736); the first two Egypt runs failed, cause not visible from here | not reviewed, not fact-approved |
| Media | standards + per-SKU checklist | interim crops only | gate blocks them | originals requested |
| Content studio, ads, Manychat | brief | no | — | — |

Previews: Claude artifact "Yawmiyati Store Preview" (static snapshot of the real build) and the zip from
`npm run preview`.

## Verification tasks (open)
Each needs the staging store, a test order **per country**, or a written answer. One test order cannot verify all
countries, payouts, returns and stock.
1. Taager plugin: order hook, the reference meta key it writes, and its handling of on-hold orders (EG and IQ).
2. Governorate codes ↔ Taager province names (EG and IQ).
3. Stock per offer, re-confirmed every 48 h (no stock sync).
4. Payout to a third-party bank beneficiary (the brother's UAE account).
5. Iraq return window (3 or 14 days); refund method and who collects returns, both countries.
6. Status sync back to WooCommerce (EG and IQ) and its status names (for the funnel map).
7. Delivery fee on a real 2-item order.
8. Caller identity on the confirmation call, parcel and invoice branding, return communications: whether customers
   see Yawmiyati or Taager.
9. Iraq delivery times per governorate.
10. SiteGround GrowBig database version (local: MariaDB 10.11).
11. Media originals and rights in writing.

## Waiting on Rashid
- Make the repo private.
- A yes on the warm design direction.
- Buy yawmiyati.shop and SiteGround GrowBig (approved, not bought).
- Send Taager the round-3 questions (docs/requests/taager-round-3.md).
- Find one native reviewer each for Egyptian and Iraqi copy.

## Next
Launch checklist P0 in order (docs/LAUNCH-CHECKLIST.md): staging, Egypt Taager link and test order, Iraq test order,
verified product records, policy facts, native review. Ads only after P1.

## How to run locally
See README.md.
