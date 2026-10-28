# Launch checklist

_28 Oct 2026. Ordered by what blocks what. `wp ym launch-check` on each store mirrors the items marked (auto)._
Status: open · in progress · done. Owner: R = Rashid, C = Claude, T = Taager (answer or test order).

## P0: before the first real order (per country)
| # | Item | Owner | Status | How we know it is done |
| --- | --- | --- | --- | --- |
| 1 | Make the GitHub repo private | R | open | GitHub shows Private |
| 2 | Approve the warm Yawmiyati design on the preview | R | open | Rashid's yes |
| 3 | Buy yawmiyati.shop and SiteGround GrowBig | R | open | Approved spend; purchase done |
| 4 | Staging stores /eg/ and /iq/ on SiteGround from this repo; server cron; env vars; staging stays noindex | C | open | `wp ym launch-check` runs on staging; health endpoint 200 |
| 5 | Send Taager the round-3 questions (media originals, caller name, parcel and invoice, returns, Iraq delivery days, payout beneficiary) | R | open | Answers recorded in COUNTRY-RULES as "Taager said" |
| 6 | Link the Taager plugin to the **Egypt** staging store; one test order | R + C | open | Order reaches Taager; reference meta key found and set (`ym_supplier_ref_keys`, `ym_supplier_ref_keys_verified`) |
| 7 | From that Egypt order: what the plugin does with on-hold orders, whether statuses sync back, who called and what name they gave, what the parcel and invoice show, fee on a 2-item order | R + C | open | Each recorded with date; status map set (`ym_stage_map`, `ym_status_sync_verified`) |
| 8 | Repeat 6-7 on the **Iraq** store (one Egypt order verifies nothing for Iraq) | R + C | open | Same evidence for Iraq |
| 9 | Governorate codes mapped to Taager's province names, both countries | C + T | open | Mapping table in COUNTRY-RULES; test orders land in the right province |
| 10 | Per SKU: identity, critical specs, media (5 assets, ≥1000 px, source and rights), price ≥ Taager minimum, stock | R + C | open | `wp ym gate` shows ready = yes (auto) |
| 11 | Replace seed products with verified records (seed data is refused on production) | C | open | No product with `_ym_dev_seed` (auto) |
| 12 | Resolve every [ym_pending] line on the policy pages | C + T | open | launch-check "policy pages" ok (auto) |
| 13 | Native review of copy and pages: Egyptian reviewer for EG, Iraqi reviewer for IQ | R finds reviewers, C applies | open | LANGUAGE-REVIEW.md approved; `ym_language_reviewed_<CC>` set (auto) |
| 14 | Payout: small test withdrawal to the intended account (third-party beneficiary question answered first) | R | open | Money received |
| 15 | Production install, Search Console, domain-root files (deploy/root) | C | open | SEO.md steps done; sitemaps submitted |

## P1: before any ad spend
| # | Item | Owner | Status | How we know it is done |
| --- | --- | --- | --- | --- |
| 16 | Create GTM, GA4, Meta dataset + CAPI token, Snap pixel + token (or decide no Snap) | R creates, C configures | open | IDs set; tokens in environment (auto) |
| 17 | Live measurement checks L1-L9 (MEASUREMENT.md §9) and signed report | C | open | `ym_measurement_tested` set (auto) |
| 18 | Uptime monitors on both health endpoints; alert webhook | C | open | Test alert received |
| 19 | Meta ad account spending limit; Meta Ads connection in approval-required mode | R | open | Limit visible in Ads Manager |
| 20 | Stock confirmed within 48 h for every advertised offer | R | open | Gate green on the day |

## P2: before scaling past the $150 test
| # | Item | Owner | Status |
| --- | --- | --- | --- |
| 21 | Delivered-stage funnel from real statuses; ads judged on delivered contribution | C | open |
| 22 | OrderDelivered events arriving; consider optimising on them | C | open |
| 23 | Weekly keep / improve / stop review per product (Claude scheduled task on the live store) | C | open |
| 24 | Cart extras (order bump, suggestions), content studio, more products | C | not started |

## Done (local, 28 Oct 2026)
Product-to-order flow for both countries; catalog gate; seed guard; buy-now keeps the cart; safe retries; supplier
reconciliation; consent, dataLayer and server events (dry run); SEO output; scheduled jobs; 41 end-to-end tests and
the integration checks passing locally and in CI. None of this is supplier-verified.
