# Launch checklist

_28 Oct 2026 (late). Ordered by what blocks what. `wp ym launch-check` on each store mirrors the items marked (auto).
Status: open · in progress · done. Owner: R = Rashid, C = Claude, T = Taager (answer or test order).
Countries open one at a time: Egypt and Iraq first. Saudi Arabia after its refusal charge is known; UAE at most one
test product; Oman stays prepared and closed (docs/MARKETS.md)._

## P0: before the first real order (per country)
| # | Item | Owner | Status | How we know it is done |
| --- | --- | --- | --- | --- |
| 1 | Make the GitHub repo private | R | open | GitHub shows Private (still public on 28 Oct, late) |
| 2 | Approve the warm Yawmiyati design and the new catalog/search/review pages on the preview | R | open | Rashid's yes |
| 3 | Buy yawmiyati.shop and SiteGround GrowBig | R | open | Approved spend; purchase done |
| 4 | Staging stores on SiteGround from a CI-green release tag; server cron; env vars; staging noindex | C | open | `wp ym launch-check` runs on staging; health endpoint 200 |
| 5 | Send Taager round 3 and round 4 questions (docs/requests/) | R | open | Answers in COUNTRY-RULES as "Taager said" |
| 6 | Link the Taager plugin to the **Egypt** staging store; one ordinary test order | R + C | open | Order reaches Taager; reference meta key found and set (`ym_supplier_ref_keys`, `ym_supplier_ref_keys_verified`) |
| 7 | Read the Taager plugin's code for the hook and status check it uses; place one **deliberately held** test order | C + R | open | Held order does not appear in Taager until released (docs/RISK.md §5) |
| 8 | From the Egypt orders: status names synced back, who called and what name they gave, parcel and invoice, fee on a 2-item order | R + C | open | Each recorded with date; `ym_stage_map` set; `ym_status_sync_verified` set only after statuses arrive |
| 9 | Repeat 6-8 on the **Iraq** store | R + C | open | Same evidence for Iraq |
| 10 | Governorate codes mapped to Taager's province names, per country | C + T | open | Mapping table in COUNTRY-RULES; test orders land in the right province |
| 11 | Per SKU: identity, critical specs, media **coverage** (clean, detail, scale; ≥1000 px; exact SKU; source, rights, original), price ≥ Taager minimum, stock < 48 h | R + C | open | `wp ym gate` shows ready = yes (auto) |
| 12 | Approve the first assortment per country from docs/PRODUCT-SELECTION.md | R | open | Rows set to "approved" with date |
| 13 | Replace seed products with verified records (seed is refused on production) | C | open | No product with `_ym_dev_seed` (auto) |
| 14 | Resolve every [ym_pending] line on the policy pages | C + T | open | launch-check "policy pages" ok (auto) |
| 15 | Native review of copy and pages for each country that opens | R finds reviewers, C applies | open | LANGUAGE-REVIEW.md approved; `ym_language_reviewed_<CC>` set (auto) |
| 16 | Payout: small test withdrawal to the intended account | R | open | Money received |
| 17 | Production install, Search Console, domain-root files (deploy/root, open markets only) | C | open | SEO.md steps done; sitemaps submitted |
| 18 | Market gates for that country set in config/markets.json by commit: catalog, then checkout | R decides, C commits | open | `wp ym market` shows them on |
| 19 | Operator accounts (no shared passwords); alert webhook; uptime monitors | R + C | open | Test alert received |
| 20 | Backups scheduled; restore drill passed on staging | C | open (local drill passed) | Drill output saved in docs/reports |

## P1: before any ad spend in a country
| # | Item | Owner | Status | How we know it is done |
| --- | --- | --- | --- | --- |
| 21 | GTM, GA4, Meta dataset + CAPI token, Snap pixel + token (or no Snap); GTM page_location = {{ym_page_location}} | R creates, C configures | open | IDs set; tokens in environment (auto) |
| 22 | Live measurement checks L1-L12 (MEASUREMENT.md §9) for that country | C | open | `ym_measurement_tested` set (auto) |
| 23 | Real-device checks: Android Chrome, iPhone Safari, Instagram and Facebook in-app browsers; consent accepted and declined | R + C | open | Checklist in QA-CHECKLIST.md filled |
| 24 | Real-host performance: lab run on staging (tools/perf-lab.js) and a burst test (docs/reports) | C | open | Mobile LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1 in lab; burst handled |
| 25 | Turnstile keys (optional, recommended once orders flow) | R | open | `ym_turnstile_sitekey` + `YM_TURNSTILE_SECRET` |
| 26 | Meta ad account spending limit; Meta Ads connection in approval-required mode | R | open | Limit visible in Ads Manager |
| 27 | Stock confirmed within 48 h for every advertised offer | R | open | Gate green on the day |
| 28 | "ads" gate on for that country by commit | R decides | open | `wp ym market` |

## P2: before scaling past the $150 test
| # | Item | Owner | Status |
| --- | --- | --- | --- |
| 29 | Field Core Web Vitals (CrUX / Search Console) at the 75th percentile | C | open |
| 30 | Delivered contribution from the mature cohort per product; ads judged on it | C | open |
| 31 | OrderDelivered events arriving; consider optimising on them | C | open |
| 32 | Weekly keep / improve / pause review per product (Claude scheduled task on the live store) | C | open |
| 33 | Genuine reviews flowing (links after verified deliveries) | R | open |

## Done (local and CI, 28 Oct 2026)
Product-to-order flow in five countries; duplicate-order race fixed; lifecycle reporting; market gates; COD controls
with review queue and risk records; catalog, search, categories, quantity and totals; media coverage gate; reviews
after delivery; operations board; backups with a passed local restore drill; measurement fixes. None of this is
supplier-verified.
