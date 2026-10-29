# Launch checklist

_29 Oct 2026 (language and storefront round, docs/reports/2026-10-29-language-storefront.md; previous: completion and
growth round). **This is the one checklist**:
owner actions, verification and launch steps all live here; other documents point to it. Ordered by what blocks what.
`wp ym launch-check` on each store mirrors the items marked (auto). Status: open · in progress · done.
Owner: R = Rashid, C = Claude, T = Taager (an answer or a test order). Egypt opens first, Iraq follows when its fees,
stock and operational tests pass. Saudi Arabia, UAE and Oman stay prepared and closed (checkout, ads, indexing)._

## Evidence levels (used everywhere in this repo)
| Level | Means |
| --- | --- |
| **Implemented** | Code in this repo runs on the local stores |
| **Locally tested** | An automated check passes locally and in GitHub Actions (CI). No Taager, Meta or Brevo involved |
| **Provider-tested** | Observed on the real service with a real account: Taager staging order, Meta test account post, Brevo send |
| **Commercially approved** | Rashid's yes on facts, prices, media rights and spend |

## This round, by evidence level (29 Oct, language and storefront)
| Item | Implemented | Locally tested | Provider-tested | Commercially approved |
| --- | --- | --- | --- | --- |
| Shared API budget (ledger branch, reservations, CAS, envelopes) | yes | yes (file and git stores under concurrency; unknown/failed kept) | used by 3 real critique runs in CI | Rashid's 40 USD ceiling |
| Workflow hardening (inputs, failing checks, pinned actions, permissions, concurrency) | yes | CI runs | — | — |
| Copy revision EG, IQ, Gulf base; SA/AE/OM glossaries | yes | AI-critiqued (15 batches), all items resolved | — | **native review: no** (O8) |
| Checkout subtotal before governorate | yes | yes (regression) | — | — |
| Email: send states, weekly cap under lock, single-use POST confirmations, webhook retries, address cooldowns | yes | yes (regressions; cap race proven) | **no** (Brevo O14) | — |
| Consent: explicit stores, server events off, window reproduced | yes | yes | no (needs host, O10/O11) | Rashid decides server events at P1 (O21) |
| Curated hero, editorial focal image, card baselines | yes | yes | — | design yes pending (O2) |
| Fee table without delivery times until observed | yes | launch-check item | at test orders (P0-8/9) | — |
| Video | scheduled last | studio tests only | no | samples **not approved for ads** |

## Readiness per country
| Level | Means | Egypt | Iraq | SA / AE / OM |
| --- | --- | --- | --- | --- |
| **Development complete** | Everything launch needs is implemented and locally tested; no known open defect | yes (PROJECT-STATE.md: commit and CI run) | yes | yes (prepared, closed) |
| **Staging verified** | P0 4-11, 19-20 done on staging with that country's Taager store linked | no | no | no |
| **Commercially ready** | Staging verified + P0 12-18 | no | no | no |
| **Ready for ads** | Commercially ready + P1 | no | no | no |
| **Growth tools live** | P3 (email, content publishing) each switched on only after its provider test | no | no | no |

## Owner actions (Rashid): the one table
Continue-while-waiting rule: Claude keeps building everything that does not depend on an open row and does not ask
twice for the same approval.
| # | Item | Exact action | Evidence needed | Capability blocked until done |
| --- | --- | --- | --- | --- |
| O1 | Repo private | GitHub → Settings → Danger zone → Change visibility → Private | Repo page shows "Private" | Nothing technical; exposure of business data |
| O2 | Design yes | **Done 29 Oct**: warm berry/ivory/blush/sage direction approved; one polish pass made (docs/reports/2026-10-29-polish-staging.md) | Your written yes (29 Oct) | — |
| O3 | Assortment yes | Approve docs/ASSORTMENT-v1.md rows (Egypt rack 689 is a proposal) | Rows marked approved with date | Publishing any product; content production for real |
| O4 | Domain + hosting | Buy yawmiyati.shop and SiteGround GrowBig | Purchase receipt | Staging (P0 4+), Search Console, email domain |
| O5 | Taager questions | Send rounds 3, 4 and 5 (docs/requests/; round 5 is about test orders, the second phone and landmark, and the caller's name); ask the Iraq refusal charge **in writing** | Written answers | Iraq ads; Gulf economics |
| O6 | Specs + original media | Ask Taager for sizes, materials, box contents and original photos with written permission for the approved SKUs (mats sizes and rack width are the first two); better originals for the weak wide card photos (Egypt blanket and mats detail; Iraq rack, window cleaner, organizer, heater, mats): a card is never fixed by stretching or cropping | Files + written permission | Catalog gate; fit pages; any content publication |
| O7 | Taager link | Link Taager to Egypt staging, then Iraq; place test orders with Claude (P0 6-9) | Orders visible in Taager | Supplier verification |
| O8 | Native reviewers | One Egyptian and one Iraqi reviewer: give each docs/language/REVIEW-PACKAGE-EG.md or -IQ.md and the review PDF | Reviewer sign-off | Opening a country; publishing scripts |
| O9 | Payout test | Small withdrawal to the intended account | Money received | Commercial readiness |
| O10 | Server cron | On SiteGround: cron every 5 minutes for each store's wp-cron (consent certification runs every 5 min) | `wp ym ops` shows no overdue job | Server ad events in every store (held until all stores certify) |
| O11 | Measurement accounts | GTM, GA4, Meta dataset + CAPI token, Snap (or decide no Snap: then "سناب شات" leaves the consent labels) | IDs and tokens in the environment | Ads (P1 21-22) |
| O12 | Meta publishing (scheduled last) | You own the Facebook and Instagram accounts; what is missing: Instagram professional account linked to the Yawmiyati Page; a private **test** IG account; a Meta app with instagram_content_publish and pages_manage_posts; a long-lived token in the studio's environment | `node studio/publish.mjs` token check passes; one test post published with its ID recorded | Organic publishing (P3 34-36) |
| O13 | Studio facts key + upload user | YM_STUDIO_KEY on every store; a WordPress user that can only upload media, with an application password, for the publisher | Publisher preflight reads facts; upload works | Organic publishing |
| O14 | Brevo (deferred: email dormant at launch, DECISIONS 29 Oct) | Brevo account; authenticate the sending domain (SPF, DKIM, DMARC); a monitored reply address; one list per country | Brevo shows the domain authenticated; test mail lands in Gmail and Outlook | Email flows (P3 30-33) |
| O15 | Music | Choose a track licensed for paid social use, or decide silent | Licence text | Paid video ads with music |
| O16 | Meta spending limit | Ads Manager → account spending limit | Limit visible | Any ad spend |
| O17 | Egypt privacy, marketing and returns rules | Confirm with a local adviser: (a) what the operator must register or record before marketing to Egyptian customers; (b) whether Consumer Protection Law 181/2018 art. 17 gives online buyers a 14-day return without a defect (our returns line follows the supplier: defects only, 14 days) and who honours it | Written note | Email marketing and advertising tags in Egypt; the Egypt returns text |
| O19 | Home photograph, one per country | The exact brief is in docs/PHOTOGRAPHY.md §1 (Egypt: the actual mat set in a lived-in Egyptian home; Iraq: the actual rack over a sink in an Iraqi kitchen; or a licensed home photo with no product in it). Never a generated scene. Claude records it and sets `ym_hero_image` | Licence text + file | The home shows the lead product's interim photo until then; staging does not wait |
| O18 | Operator accounts + alerts | Personal operator logins (no shared password), alert webhook, uptime monitor | Test alert received | P0 19 |
| O20 | Anthropic console check | Compare console usage for 28-29 Oct with `node tools/spend/cli.mjs status`; tell Claude the real figure for the failed runs | Console figure | Lowers the 1 USD "unknown" held in the API reserve (nothing else) |
| O21 | Server-side ad events | Decide at P1 whether to switch on YM_SERVER_EVENTS after reading docs/MEASUREMENT.md §4 (the stated window) | Your written yes or no | Server events (browser tags under consent work without them) |

## Staging runbook
The order of work from hosting to verified test orders, with the test list E1-E9 / I1-I9 and the deploy workflow:
**docs/STAGING.md**. Photography still needed (does not block staging): **docs/PHOTOGRAPHY.md**.

## P0: before the first real order (per country)
| # | Item | Owner | Status | How we know it is done |
| --- | --- | --- | --- | --- |
| 1 | Make the GitHub repo private | R | open | GitHub shows Private |
| 2 | Approve the design on the review screenshots of this round | R | **done 29 Oct** (direction approved; polish pass made) | Rashid's yes |
| 3 | Buy yawmiyati.shop and SiteGround GrowBig | R | open | Purchase done |
| 4 | Staging stores from a CI-green release (tag `staging-*` → deploy-staging workflow, docs/STAGING.md §3); server cron every 5 min; env vars (`YM_SHARED_DB` with every store's DB user granted; `YM_CONSENT_STORES` = exactly the installed stores, no default; `YM_SERVER_EVENTS` unset; `YM_MAIL_SECRET`); staging noindex and protected | C | open | `wp ym launch-check` runs; health 200; consent ledger and certificates fresh for every store (auto) |
| 4a | Client IP source on the real host (`YM_TRUSTED_PROXIES` only if a proxy sits in front) | C | open | `ym_client_ip_checked` set (auto) |
| 5 | Taager round 3 and 4 questions | R | open | Answers in COUNTRY-RULES as "Taager said" |
| 6 | Taager plugin on **Egypt** staging (version recorded); one ordinary test order | R + C | open | Reference meta key found (`ym_supplier_ref_keys_verified`) |
| 7 | Read the Taager plugin's hook and **which fields it sends** (second phone, landmark, building); one **deliberately held** test order | C + R | open | Held order absent from Taager until released |
| 8 | From the Egypt orders: status names, caller identity, parcel and invoice, delivery charge on a 2-item order and on the 2-set mats SKU, delivery days per zone | R + C | open | `ym_stage_map` set; `ym_status_sync_verified` only after statuses arrive; `ym_delivery_days_verified` once days are observed (the fee table then shows them) |
| 8a | Duplicate handling at Taager: lost-response retry and a second order from the same phone | R + C | open | Exactly one Taager order for the retry; the second held by us |
| 9 | Repeat 6-8 on **Iraq** | R + C | open | Same evidence for Iraq |
| 10 | Governorate codes ↔ Taager province names | C + T | open | Mapping in COUNTRY-RULES; test orders land right |
| 11 | Per SKU: identity, critical specs, media coverage and rights, card derivative not weak, price ≥ Taager minimum, stock < 48 h | R + C | open | `wp ym gate` ready = yes (auto) |
| 12 | Approve the first assortment (ASSORTMENT-v1.md) | R | open | Rows approved with date |
| 13 | Replace seed products with verified records | C | open | No `_ym_dev_seed` product (auto) |
| 14 | Resolve every [ym_pending] line on the policy pages; confirm "delivery to every governorate" (today from Taager's published fee table, not observed); refund method and timing for COD returns (the refund email gets one sentence then); Egypt return rights (O17) | C + T | open | launch-check "policy pages" ok (auto) |
| 15 | Native review of copy, pages, emails and scripts per opening country (packages: docs/language/REVIEW-PACKAGE-<CC>.md); review on the phone screens, not JSON | R finds, C applies | open (AI-critiqued, all items resolved) | `ym_language_reviewed_<CC>` set (auto) |
| 16 | Payout test | R | open | Money received |
| 17 | Production install, Search Console, domain-root files (open markets only) | C | open | SEO.md steps done |
| 18 | Market gates by commit: catalog, then checkout | R decides, C commits | open | `wp ym market` |
| 19 | Operator accounts, alert webhook, uptime monitors | R + C | open | Test alert received |
| 20 | Backups scheduled (each store's backup includes the shared consent ledger); restore drill on staging | C | open (local drill passed with the ledger) | Drill output in docs/reports |

## P1: before any ad spend in a country
| # | Item | Owner | Status | How we know it is done |
| --- | --- | --- | --- | --- |
| 21 | GTM, GA4, Meta dataset + CAPI token, Snap or no Snap | R creates, C configures | open | IDs set (auto) |
| 22 | Live measurement checks L1-L12 (MEASUREMENT.md §9) incl. analytics-only consent, withdrawal across stores and a store unable to write the ledger; server events only if O21 is yes | C | open | `ym_measurement_tested` (auto) |
| 23 | Real devices: Android Chrome, iPhone Safari, Instagram and Facebook in-app browsers; consent card accepted, declined, analytics-only | R + C | open | QA-CHECKLIST.md filled |
| 24 | Real-host performance with final media, tags, consent card and recommendations (lab now, field at P2) | C | open | Mobile LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1 in lab |
| 25 | Turnstile keys (optional) | R | open | Keys set |
| 26 | Meta spending limit; Ads connection in approval-required mode | R | open | Limit visible |
| 27 | Stock confirmed < 48 h for every advertised offer | R | open | Gate green that day |
| 28 | "ads" gate on by commit | R decides | open | `wp ym market` |

## P2: before scaling past the first test budget
| # | Item | Owner | Status |
| --- | --- | --- | --- |
| 29 | Field Core Web Vitals at the 75th percentile | C | open |
| 29a | Kill-gates on mature contribution with an 80 % band (`wp ym gates`), weekly | C | implemented, locally tested |
| 29b | Genuine reviews flowing; OrderDelivered events arriving | R + C | open |

## P3: growth tools, each switched on only after its provider test
Email (30-33) is dormant at launch by Rashid's decision of 29 Oct; the stores launch phone-only.
| # | Item | Owner | Status | How we know it is done |
| --- | --- | --- | --- | --- |
| 30 | Brevo domain authenticated (SPF, DKIM, DMARC aligned), reply address monitored | R | open | Brevo dashboard; reply received |
| 31 | Arabic templates checked on mobile Gmail and Outlook (RTL, links, prices, plain text, unsubscribe) | R + C | open | Screenshots in docs/reports |
| 32 | Provider test: confirm → welcome → one-click unsubscribe → suppressed in a second store; bounce webhook | C | open (locally tested) | Brevo logs + `ym_contacts` rows |
| 33 | Switch on flows one at a time (`ym_email_flows`): transactional, newsletter, post_delivery, cross_sell. Weekly newsletter only through `wp ym mail newsletter` (never Brevo's campaign screen: it would bypass the one-a-week cap) | R decides | open | Option set with date |
| 34 | Video phase (scheduled last, CONTENT-STUDIO.md): licensed footage of the actual SKU in use; one concept each for Egypt and Iraq; native-reviewed captions; Rashid approves those two before any batch | R + C | open (pipeline locally tested; 29 Oct samples are technical, not for ads) | Two approved examples |
| 35 | Publish one approved post to the **test** IG account; platform ID recorded; a forced retry does not duplicate. Carousels and Stories are manual exports until built and tested; a Facebook Page video is not a Facebook Reels flow | C | open (fake Graph server only) | Record shows platform ID; one post on the account |
| 36 | First production batch (4 reels + 2 carousels a week) approved | R | open | Batch file with approver and date |
| 37 | Comment and DM handling: Meta Business Suite automated replies + a person; Manychat revisited with real volumes | R + C | open | Reply times in the weekly review |

## Done (local and CI)
29 Oct 2026, language and storefront round (docs/reports/2026-10-29-language-storefront.md): one shared Anthropic API
ledger with reservations and compare-and-swap writes; hardened workflows (validated inputs, failing checks, pinned
actions, per-job permissions, concurrency); copy ledger, rendered-page inventory, local evidence, three-layer voice
guides, per-country Gulf glossaries, AI critique of every owned string (resolved), native review packages; checkout
subtotal before a governorate; email send states, row-locked weekly cap, single-use POST-only confirmations, webhook
half-failure recovery, per-address cooldowns; explicit consent stores; server events off by default; curated hero;
privacy pages that state the real purposes; review PDF without split pictures. History scan: no credentials or real
customer data in the 40 commits.

29 Oct 2026, completion and growth round (this checklist's companion report): consent withdrawal holds when a store can
read but not write the shared ledger (two-phase server events, store certificates, atomic local queue, concurrency and
backup/restore tests); analytics-only consent; compact consent card; home with focal image and one action; 4:5 card
derivatives with weak-photo detection and media rights fields; honest next steps after ordering; recommendations v1;
email permissions with double opt-in, cross-store suppression, caps, Brevo adapter and webhook (flows off); Content
Studio pipeline (facts, API briefs with a spend cap, renders, QA, approval, publisher with reconciliation, pause and dead
letter); kill-gates on contribution; one price source with stamped previews.

29 Oct 2026, stabilization: order key redirect; shared consent ledger; line-level accounting; trusted-proxy IP, atomic
counters, Turnstile checks; reviews after a return; lockfile.

28 Oct 2026: product-to-order flow in five countries; duplicate-order race fixed; lifecycle reporting; market gates;
COD controls; catalog, search, categories; media coverage gate; reviews; operations board; backups with a restore
drill; measurement. None of this is supplier-verified.
