# Project state

_Last updated: 2026-10-28 (late session: ChatGPT completion brief applied, see docs/reports/2026-10-28-completion.md)_

Status words used everywhere in this repo:
- **Designed**: exists only on the design canvas.
- **Implemented**: code in this repo runs on the local stores.
- **Tested**: an automated check passes locally and in GitHub Actions (CI). No Taager involved.
- **Supplier-verified**: confirmed against Taager itself (a real test order, the live catalog, or a written answer). A support chat answer is "Taager said", not verified.

Nothing is supplier-verified. There is no live store, no Taager link and no real order.
Repository: still **public** (launch checklist #1).

Correction to the previous handoff: it said CI was green on commit 9922223. It was not: run 40748106500 failed on a
real duplicate-order race (two simultaneous requests both created an order). Fixed in 0700841; CI green since.
Latest code commit e4ebf87: CI run 40770712452 green. Store preview artifact republished from it.

## Where things stand
| Area | Implemented | Tested | Supplier-verified |
| --- | --- | --- | --- |
| Five local stores from one plugin/theme: EG :8081, IQ :8082, SA :8083, AE :8084, OM :8085 | yes | yes (orders in all five; OMR 3 decimals) | — |
| Market gates (prepared, catalog, checkout, ads) — all "prepared", none open | yes | yes | — |
| One order per submission (conditional insert + DB lock; slow, crashed, parallel, lost-response cases) | yes | yes | Taager duplicate handling unknown |
| Order lifecycle history, report (unique orders, charges once, stage funnel, outcomes, mature cohorts, unknowns) | yes | yes | status names unknown |
| COD controls: honeypot, rate limits, review holds with reasons, risk records, Turnstile (off until keys) | yes | yes | Taager's handling of on-hold orders unknown |
| Storefront: catalog, categories, search, curated home, quantity and totals, switcher, breadcrumbs, social links | yes | yes | — |
| Reviews after verified delivery, moderated | yes | yes | — |
| Media coverage gate (clean, detail, scale; exact SKU; source, rights, original) | yes | yes | no product passes: originals requested |
| Measurement: consent, withdrawal, purchase ack, late consent, key removal, verified delivered, stable retries | yes | yes (local) | live checks L1-L12 open; no accounts yet |
| SEO: indexing by market, canonicals, breadcrumbs, category pages, retired-product policy, sitemaps | yes | yes | Search Console at launch |
| Operations board, operator role, 90-day logs, backups + restore drill | yes | yes (drill passed locally) | — |
| Copy: Egyptian, Iraqi, shared Gulf (SA/AE/OM) | drafts | lint only | native review not done |
| Product selection: shortlists EG 40, IQ 40, SA 40, AE 15, OM 22; pricing on delivered contribution | research | scripts run | nothing approved |
| Performance (lab, local, throttled phone): LCP 0.9-2.0 s on shop pages, CLS 0, INP ≤ 96 ms | — | lab only | real host and field not measured |

## Verification tasks (open; per country, one test order verifies nothing for another country)
1. Taager plugin: order hook, reference meta key, **whether on-hold orders are sent** (read its code, then a held test order).
2. Status names synced back (for `ym_stage_map`), then `ym_status_sync_verified`.
3. Governorate/region codes ↔ Taager province names.
4. Stock per offer every 48 h (Iraq has no stock data in the export at all).
5. Payout to a third-party beneficiary; payout fees.
6. Refusal and return charges: SA, AE, OM unpublished; Iraq "Taager said 0"; Egypt published 5 + 5.
7. Saudi VAT on uplift for a non-Saudi merchant.
8. Caller identity, parcel and invoice branding, return communications.
9. Iraq return window (3 or 14 days) and delivery days.
10. SiteGround database version.
11. Media originals and rights in writing.

## Waiting on Rashid
- Make the repo private.
- Yes on the design, now including catalog, search, reviews and the operations board.
- Buy yawmiyati.shop and SiteGround GrowBig (approved, not bought).
- Send Taager rounds 3 and 4 (docs/requests/).
- Native reviewers: Egyptian and Iraqi now; Gulf later.
- Approve a first assortment per country from docs/PRODUCT-SELECTION.md.

## Next
Launch checklist P0 in order (docs/LAUNCH-CHECKLIST.md): staging, Egypt Taager link + ordinary and held test orders,
Iraq the same, verified product records, policy facts, native review. Ads only after P1.

## How to run locally
See README.md. Five stores, about 18 minutes for the full CI run.
