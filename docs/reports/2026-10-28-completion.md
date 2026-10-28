# Completion package: ChatGPT consolidated brief, 28 Oct 2026

Applied in commits 0700841, a65e2e8, e111fea, 2a6ba49, e4ebf87. Status words as in PROJECT-STATE.md. **Nothing is
supplier-verified.** Local tests do not verify Taager's behaviour, and one test order will not verify returns,
payouts or long-term stock.

## 1. CI on the final commit
**Green on e4ebf87: run 40770712452** (24 min, all five stores, e2e incl. WebKit, integration). Earlier runs this
session: 40759391207 (a65e2e8), 40763997719 (e111fea), 40768139667 (2a6ba49), all green. This docs-only commit
records the result.

## 2. Preview
Claude artifact "Yawmiyati Store Preview", republished from e4ebf87 (Egypt, Iraq, Oman) (home, catalog, search, product, checkout,
policy pages). Static: ordering, search and switching are disabled in it.

## 3. Five-country readiness
docs/MARKETS.md (matrix and gate rules). Short version: all five prepared and tested locally; none open. Open Egypt and
Iraq first; Saudi Arabia after its refusal charge is known; the UAE at most one test product; Oman stays closed (the
supplier catalog there is too thin to justify a store).

## 4. Product shortlist and publication status
docs/PRODUCT-SELECTION.md and research/selection/offers.csv. Shortlists: EG 40, IQ 40, SA 40, AE 15, OM 22. The 27
launch-set rows are "researched"; every new row is "candidate"; **approved 0, published 0, promoted 0**. First-wave
picks beyond the launch set: five each for EG, IQ and SA, one for AE, none for OM. The brief allowed more than 30-40;
I did not go further because the evidence per row (item-level competitor checks, stock) would not support it.

## 5. Pricing
docs/PRICING.md and research/selection/pricing.csv. Expected contribution per submitted order = P(delivered and kept)
× contribution − expected losses of other outcomes − acquisition cost, under labelled 30/45/60 % scenarios (no order
history exists). Unknown inputs stay unknown: every Saudi, UAE and Oman offer is "undecided: needs the refusal/return
charge". Egypt and Iraq: product price + disclosed delivery (free delivery would push 141 of 157 offers below Taager's
minimum price). Settled from the published policy in review: Saudi VAT is 15 % of the added profit; Egypt charges 5 EGP
on a delivered-then-returned order.

## 6. Order-scenario test report
85 end-to-end tests (84 run locally; the WebKit/iPhone test runs only in CI) and 370 in-store checks across five
stores: Egypt 82, Iraq 83, Saudi Arabia 70, UAE 64, Oman 71 (`npm test`, `npm run test:integration`).
_Correction 29 Oct: this line first said 82 and 365, figures from an earlier run; the counts above are from the final
local run on e4ebf87, as in the handoff note. Current counts: docs/reports/2026-10-29-stabilization.md._

| Group | Covered by | Not covered (why) |
| --- | --- | --- |
| Submission | double click; six copies at the same millisecond (EG, IQ); first request alive 35 s; lost response; crash after save; creation failure; copied token from another session; back navigation | — |
| Pricing | injected prices; edited totals; offer from another group; governorate fee change; quantity; bundle offers; OMR three-decimal total | expired discount: coupons are disabled |
| Stock | out-of-stock bundle; sold out during checkout; stock check expiry (gate); publishing an unready product saves a draft | stale supplier data beyond 48 h is a process (stock guard job) |
| Customer details | Arabic and Persian digits; +CC, 00CC, CC, missing trunk 0; wrong-country phone; governorate outside the country; landmark optional | — |
| Supplier hand-off | unconfirmed after the wait; late reference; reconciliation found / not found / expiry; manual entry then a plugin reference (conflict) | real Taager behaviour (staging) |
| Status updates | duplicate update recorded once; delivered after refused flagged; status-only evidence unverified until the sync is verified | signed status updates: Taager's sync mechanism unknown |
| Customer changes | cancel before hand-off; cancel after shipment | address correction and replacement: done in Taager by hand (OPERATIONS.md); no code path claims otherwise |
| After delivery | refusal reasons (customer vs operation); full return; partial return by units; partial refund; full refund | replacement: as above |
| Privacy | order page without key; another browser with the key; guessing order numbers; no personal data in dataLayer, server payloads or logs | — |
| Infrastructure | sweep job and health endpoint; failing ad-platform API with stable retries; catalog pages cacheable, checkout not; restore drill | cron stopping and queue backlog on the real host; deployment rollback (procedure in OPERATIONS.md, run on staging) |

## 7. Performance (lab, local; **not** a real-host report)
`tools/perf-lab.js`, Pixel 7 profile, CPU 4× slower, 1.6 Mbps / 150 ms network, median of 3, local PHP server, GTM
stubbed. Raw: docs/reports/perf-lab-2026-10-28.json.

| Page | LCP before → after | CLS | Interaction | Weight after |
| --- | --- | --- | --- | --- |
| EG home | 2.66 → 1.45 s | 0 | 56-64 ms | 335 KB, 13 requests |
| EG catalog | 3.69 → 2.00 s | 0 | 40-64 ms | 333 KB |
| EG product | 2.46 → 1.23 s | 0 | 80-96 ms | 228 KB |
| OM product | 2.22 → 0.94 s | 0 | 32-40 ms | 156 KB |
| IQ checkout | — → 2.3 s | 0 | — | 495 KB (WooCommerce scripts needed here) |

Same with tracking accepted and declined. What changed: WooCommerce CSS, jQuery and its scripts only on cart,
checkout and account; no emoji script; fonts cut to two weights; main product image first with high priority,
responsive sizes, everything below lazy; video poster only. Still to do on the real host: the same lab run on staging,
a traffic-burst test, and field data (75th percentile) after launch. Real devices (Android Chrome, iPhone Safari,
Instagram and Facebook in-app browsers) are a launch checklist item: they cannot be run from here.

## 8. Live analytics verification
Not possible yet: no GTM, GA4, Meta or Snap accounts are connected. The live checks are MEASUREMENT.md §9, L1-L12.

## 9. Operations guide
docs/OPERATIONS.md: board, daily (about 15 minutes per open country), weekly, access, notifications and logs,
backups and restore, releases and rollback.

## 10. External dependencies and owner actions
_Superseded 29 Oct: the one owner-action list is now docs/LAUNCH-CHECKLIST.md ("Owner actions")._

| # | Action | Owner | Blocks |
| --- | --- | --- | --- |
| 1 | Make the GitHub repo private | Rashid | security |
| 2 | Approve the design, including catalog, search, reviews | Rashid | launch |
| 3 | Buy yawmiyati.shop + SiteGround GrowBig | Rashid | staging |
| 4 | Send Taager rounds 3 and 4 (docs/requests/) | Rashid | policy facts, pricing for SA/AE/OM, hold handling |
| 5 | Link Taager to the Egypt staging store; ordinary and deliberately held test orders | Rashid + Claude | Egypt launch |
| 6 | Same for Iraq | Rashid + Claude | Iraq launch |
| 7 | Media originals with written permission per SKU | Rashid via Taager | publishing any product |
| 8 | Approve the first assortment per country | Rashid | publishing |
| 9 | Native reviewers: Egyptian, Iraqi (Gulf later) | Rashid | launch per country |
| 10 | Test withdrawal to the intended account | Rashid | payouts |
| 11 | GTM, GA4, Meta dataset + token, Snap pixel + token | Rashid creates, Claude configures | ads |
| 12 | Real-device and in-app browser checks | Rashid + Claude | ads |
| 13 | Turnstile keys (optional) | Rashid | stronger bot check |
| 14 | Operator accounts, alert webhook, uptime monitors | Rashid + Claude | operations |

## Where ChatGPT's brief was wrong or incomplete (and what I did)
- **Missed: WooCommerce's own order attribution** was on. It loaded its own tracking script and set cookies before any
  consent, duplicating ours. Turned off at setup.
- **Missed: order lookups by meta were broken.** On classic order storage `wc_get_orders()` ignores `meta_key`, so the
  consent-withdrawal path would have marked every recent customer's orders as withdrawn, and crash recovery could have
  pointed a retry at someone else's order. One storage-aware lookup now serves all of them, with a test that another
  customer's orders are untouched.
- **"Prove held orders are not transmitted by Taager's plugin"** cannot be proven locally. Proven here: a held order
  never passes through processing at the usual hooks. Taager's part needs its code and a held test order on staging
  (docs/RISK.md §5). Also: setting on-hold before the first save, the obvious way to do it, makes WooCommerce
  complete the order; holds stay on the normal path.
- **Rate limits by IP** would refuse genuine customers behind carrier-shared IPs (common in Iraq and Egypt). A busy
  connection now holds orders for review; only a browser burst or an extreme rate is refused, and typing mistakes
  never count.
- **"Prepare all five"**: done, but readiness is limited by supply, not code. The Oman and UAE catalogs are too thin
  and Iraq's export has no stock data at all.
- **"30-40 products per country"**: supported for EG, IQ, SA; not for AE (15) or OM (22, not worth opening).
- **Two-decimal rounding**: display was already 0 decimals for EGP/IQD; the problems were analytics rounding to 2 and no
  three-decimal path for OMR. Fixed per currency.
- **"Performance: avoid page-builder overhead"** was not the issue; WooCommerce's default CSS and jQuery on every page
  were. Removed where unused.
- Agreed and done as written: the duplicate-order race (CI failure), reporting fixes, funnel by history,
  refund/refusal separation, consent withdrawal, lock expiry, catalog scaling and lazy images, media coverage gate,
  market gates, reviews, operations screen, backups with a tested restore.
