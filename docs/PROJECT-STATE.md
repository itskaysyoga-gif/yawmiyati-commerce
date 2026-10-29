# Project state

_Last updated: 2026-10-29 night (staging tightening and development freeze, docs/reports/2026-10-29-staging-tightening.md;
before that the polish pass and staging preparation, docs/reports/2026-10-29-polish-staging.md)_

Status words used everywhere in this repo (also in docs/LAUNCH-CHECKLIST.md):
- **Implemented**: code in this repo runs on the local stores.
- **Locally tested**: an automated check passes locally and in GitHub Actions (CI). No Taager, Meta or Brevo involved.
- **Provider-tested / supplier-verified**: observed on the real service (a Taager test order, a Meta test-account post,
  a Brevo send) or a written answer. A support chat answer is "Taager said", not verified.
- **Commercially approved**: Rashid's yes on facts, prices, media rights and spend.

Nothing is supplier-verified. There is no live store, no Taager link and no real order.
Repository: still **public** (launch checklist #1). **Development is frozen**: only staging work, defects found on
staging, and changes a verified launch blocker needs.

## Readiness (definitions and per-country status: docs/LAUNCH-CHECKLIST.md)
- **Development complete: yes** for everything buildable without accounts, all five countries, including this
  round's review (API budget, workflows, language foundations, checkout, email and consent reliability, hero).
  Final commit and CI run: recorded in the Project handoff (`claude/session-handoff-2026-10-28.md`). Tests on that tree,
  run locally: **116 end-to-end** (the WebKit/iPhone test only in CI), **802 in-store checks** (EG 169, IQ 170, SA 156, AE 150, OM 157) and
  **31 studio, API-ledger and staging-deploy checks**. Up from 116 / 777 / 17 on `047ec68` (29 Oct evening).
- **Design: approved** (Rashid, 29 Oct evening: warm berry/ivory/blush/sage). One bounded polish pass done; what still
  needs assets is in docs/PHOTOGRAPHY.md (home photo per country, all twelve product photos).
- **Next phase: staging** (docs/STAGING.md): hosting, the Taager plugin on Egypt then Iraq staging, test orders
  E1-E10 / I1-I10 in two classes (C cancelled before shipping; D delivered, each with Rashid's yes). Releases by tag
  `staging-YYYYMMDD-N` on a main commit with green tests on main; destination checks, backup, automatic rollback;
  order-test window by `staging-mode-*` tags (deploy-staging workflow, idle until the staging settings exist).
- **Staging blocked only by external items**: O4 hosting → GitHub staging settings → O1, O3, O5 (one Taager request),
  O7 plugin link, O22 test-order phone and delivered-order recipient (docs/LAUNCH-CHECKLIST.md "What blocks staging now").
- **Email: dormant at launch** (Rashid, 29 Oct): phone-only stores; the hardened email code stays off.
- **Language: AI-reviewed, not native-reviewed.** Every owned string is in the copy ledger (docs/language/); 15 AI
  critique batches, all items resolved; native review packages ready (O8).
- **Video: scheduled last.** The 29 Oct samples are technical, not approved for ads (docs/CONTENT-STUDIO.md).
- **Staging verified: no.** Waits for hosting and the domain (O4), then the Taager plugin on staging.
- **Commercially ready: no.** Also needs the approved assortment, verified product records and media, policy facts,
  native review and a payout test.
- **Growth tools live: no.** Email flows and organic publishing are built and locally tested, switched off, and wait
  for their provider tests (Brevo O14, Meta O12).

## Where things stand
| Area | Implemented | Tested | Supplier-verified |
| --- | --- | --- | --- |
| Staging release workflow: commit, destination, protection and environment checks; backup; swap; HTTP verification; rollback; interrupted-run recovery; order-test window | yes | yes (fake host in CI; real-WordPress rehearsal locally) | no host yet |
| Five local stores from one plugin/theme: EG :8081, IQ :8082, SA :8083, AE :8084, OM :8085 | yes | yes (orders in all five; OMR 3 decimals) | — |
| Market gates (prepared, catalog, checkout, ads) — all "prepared", none open | yes | yes | — |
| One order per submission (conditional insert + DB lock; slow, crashed, parallel, lost-response cases) | yes | yes | Taager duplicate handling unknown |
| Order lifecycle history, report (unique orders, charges once, stage funnel, outcomes, mature cohorts, unknowns) | yes | yes | status names unknown |
| Line-level returns, line and amount-only refunds, supplier credits, reconciliation; contradictions held open until resolved | yes | yes | Taager credit and return-charge formats unknown |
| Order key removed by redirect before any tag; review page without tags | yes | yes | — |
| Consent withdrawal shared by all stores (ledger, fails closed) | yes | yes (cross-store) | needs the shared DB on the host |
| Client IP from trusted proxies only; atomic counters; strict Turnstile hostname/action | yes | yes (incl. concurrency) | real host's proxy headers unknown |
| COD controls: honeypot, rate limits, review holds with reasons, risk records, Turnstile (off until keys) | yes | yes | Taager's handling of on-hold orders unknown |
| Storefront: catalog, categories, search, curated home, quantity and totals, switcher, breadcrumbs, social links | yes | yes | — |
| Reviews after verified delivery, moderated | yes | yes | — |
| Media coverage gate (clean, detail, scale; exact SKU; source, rights, original) | yes | yes | no product passes: originals requested |
| Measurement: consent, withdrawal, purchase ack, late consent, key removal, verified delivered, stable retries | yes | yes (local) | live checks L1-L12 open; no accounts yet |
| SEO: indexing by market, canonicals, breadcrumbs, category pages, retired-product policy, sitemaps | yes | yes | Search Console at launch |
| Operations board, operator role, 90-day logs, backups + restore drill | yes | yes (drill passed locally) | — |
| Consent withdrawal holds when a store can read but not write the ledger (two-phase events, store certificates, atomic queue) | yes | yes (cross-store, concurrency, backup/restore) | needs the shared DB and 5-min cron on the host |
| Consent card: all / analytics only / essentials only | yes | yes | real devices at P1 |
| Home focal image + action, curated orderable picks, tip, ordering steps; phone cart as cards | yes | yes | — |
| Card derivatives (WebP + JPEG, one optical scale, weak photos flagged); media rights channels/expiry/approval | yes | yes | no image has written rights yet |
| Recommendations v1 (curated, deterministic) | yes | yes | — |
| Email permissions, double opt-in, cross-store suppression, caps, Brevo adapter + webhook; flows off | yes | yes (captured locally) | Brevo not connected |
| Kill-gates on contribution with an 80 % band | yes | yes | needs mature orders |
| Content Studio: facts export, API briefs (CI, capped), Remotion renders, QA, batch approval, Meta publisher | yes | yes (fake Graph API) | no Meta account connected; first batch blocked for production |
| Copy: Egyptian, Iraqi, neutral Gulf base + SA/AE/OM glossaries; copy ledger; rendered-page inventory | revised 29 Oct | AI-critiqued, resolved | native review not done |
| Anthropic API budget: one ledger, reservations, CAS on branch ledger/anthropic | yes | yes (concurrency) | used by CI critique runs |
| Email reliability: send states, weekly cap under lock, single-use POST confirmations | yes | yes | Brevo not connected |
| Consent: explicit stores; server events off by default | yes | yes | needs host |
| Product selection: shortlists EG 40, IQ 40, SA 40, AE 15, OM 22; pricing on delivered contribution | research | scripts run | nothing approved |
| First assortment recommended: Egypt 4 products / 5 offers, Iraq 4 / 5 (docs/ASSORTMENT-v1.md) | research | script run | awaiting Rashid's yes |
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
The one table is in docs/LAUNCH-CHECKLIST.md ("Owner actions", O1-O22). In order: buy the domain and hosting (O4) and add
the GitHub staging settings; make the repo private (O1); yes on docs/ASSORTMENT-v1.md (O3); send the one Taager request
(O5, docs/requests/taager-request.md); find a test-order phone and one delivered-order recipient per country (O22).
The design is approved (O2, 29 Oct).

## Next
Launch checklist P0 in order (docs/LAUNCH-CHECKLIST.md): staging, Egypt Taager link + ordinary and held test orders,
Iraq the same, verified product records (sizes for the mats and the rack first: their fit text depends on them),
policy facts, native review. Ads only after P1. Growth tools (P3) one at a time after their provider tests.

## How to run locally
See README.md. Five stores, about 18 minutes for the full CI run.
