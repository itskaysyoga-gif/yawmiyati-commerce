# Language and storefront round, 29 Oct 2026

Scope: ChatGPT's review "Yawmiyati: storefront and language completion round" (read-only review of `e10abdf`),
applied in its stated order, with corrections below. Status words as in docs/LAUNCH-CHECKLIST.md. **Nothing here is
supplier- or provider-tested**: no Taager, Meta or Brevo account was used. The Anthropic API was used for the language
critique only, through the new shared ledger.

## Baseline (before any change)
Fresh container, untouched `e10abdf`, MariaDB 10.11.14: **106 end-to-end (105 passed, 1 skipped: WebKit/iPhone runs
only in CI), 772 in-store checks (EG 163, IQ 164, SA 150, AE 144, OM 151), 10 studio checks**, all passing; CI run
40837909686 on `e10abdf`: success. Matches the previous handoff.

## What changed, in the review's order, and the evidence
| # | Area | Change | Evidence |
| --- | --- | --- | --- |
| 1 | API budget | One ledger for every paid generator (`tools/spend/`), on branch `ledger/anthropic`, written by compare-and-swap pushes; reserve the maximum (prompt bytes + max_tokens at 1.5× list) before each call; settle with returned usage; unknown or failed calls keep their reservation; 40 USD cap with envelopes EG 18 / IQ 12 / Gulf 5 / reserve 5; models and rates only from `rates.json`; studio and copy drafts moved onto it; earlier calls migrated (docs/API-BUDGET.md) | tools/spend/test: limits; 12 processes on a file store and 8 on two git checkouts racing, none overspent or lost; timeout → unknown kept; HTTP error → failed kept; retry reserves again; key never in the ledger |
| 1 | Workflows | Inputs validated and passed as environment variables; rule errors and product errors fail the run while drafts are kept as rejected; actions pinned to commit SHAs (checkout v5.1.0, setup-node v5.0.0, setup-php 2.40.0); `permissions: {}` at the top and per job; tests cancel superseded runs, paid runs queue in one group; `render.mjs`/`publish.mjs` refuse to run in Actions | CI runs of this round; three `language-critique` runs |
| 2 | Language | Evidence per country (docs/language/evidence/); three layers in the voice guides; Iraqi spelling policy; SA/AE/OM glossaries; copy ledger per country (every owned string: existing, proposed, reason, facts, status); rendered-page inventory of WooCommerce strings (four overridden); full revision of EG, IQ and the Gulf base; 15 critique batches in four runs (Claude Opus 5.5, separate context) with every "revise"/"question" resolved; native review packages | docs/language/, tools/language/critique/*.json |
| 3 | Checkout | Before a governorate: "مجموع المنتجات" + "delivery after choosing", never "المطلوب وقت الاستلام"; after: one total with the fee; no order without a governorate | reliability.test.js (exact state of the review's screenshot) |
| 3 | Email | Send states sending/accepted/failed/unknown (unknown never resent, counted, resolved by provider event or a person); Brevo idempotency key; one marketing email a week across stores and flows, reserved under a row lock with suppression re-read inside it; weekly newsletter only via `wp ym mail newsletter`; confirmation links bound to nonce + wording, single-use; links act only on POST (button or RFC 8058 one-click); per-address cooldowns, generous per-IP; complaints and bounces lifted only by a person; webhook saves suppression first, answers 500 on failure | reliability.test.js: cap race (six senders, three stores: one sent; **fails without the lock**, checked), old link after unsubscribe + new signup refused, single use, scanner GET changes nothing, timeout/refusal outcomes, webhook half-failures |
| 3 | Consent | `YM_CONSENT_STORES` has no default (an uninstalled SA/AE/OM listed by default would have held every Egypt and Iraq event forever); server-side ad events off unless `YM_SERVER_EVENTS` is true; invariant and window written exactly (MEASUREMENT.md §4) and the window reproduced | reliability.test.js: stores not configured / not listed → wait; uninstalled markets hold nothing; events off by default, order unaffected; the window reproduced and closed after the flush |
| 4 | Storefront | Hero product chosen by a person (`wp ym hero`, seed "hero"); "conditional" items (heaters) never shown and nothing substituted; focal image at its own proportions on an offset panel (no white tile); card prices on one baseline; category names standard Arabic everywhere; delivery times hidden in the fee table until observed | reliability.test.js (Iraq hero = rack; conditional → no product picture); storefront tests |
| 4 | Products | Removed unsupported claims ("فائق الامتصاص", "بسرعة", "صغيرة"); no prices inside notes; saving lines say what they are saved against and are checked against the prices of their parts; fit texts removed until sizes exist | in-store check "saving lines match the price of their parts" |
| 5 | Review pack | `tools/review-shots.js` refuses uncommitted plugin/theme changes and production stores; main part (screens on the way to an order) then appendix; long pages cut into screen-height parts; one figure per page, never split | PDF of the final commit (handoff) |
| 2 | Repo hygiene | History scan of all 40 commits: no credentials (Anthropic, GitHub, AWS, Google, Brevo, Meta token patterns, private keys), no real phone numbers or addresses (only test placeholders) | scan in this session |

## Corrections to the review (where it was wrong, conflicted with the repo, or needed a different answer)
1. **"إتمام الطلب" for the order button → "إرسال الطلب".** The order is a request until the call confirms it. "Complete"
   or "confirm" on the button next to a page that says "not confirmed yet" contradicts itself; "send" says what happens.
2. **"هنتواصل معاكي لتأكيد التفاصيل" → kept agentless ("هيوصلك اتصال").** Who calls is the supplier's confirmation team
   and is not verified (P0-8); "we will contact you" would name the wrong caller.
3. **Gulf "generic voice".** The shared Gulf file was already close to standard Arabic, not a dialect; the guide's
   "light Gulf flavour" line was the only overreach. Now explicitly neutral Arabic, with SA/AE/OM glossaries and
   checklists; each country still needs its own reviewer.
4. **"Imported" claim.** No customer string contained it (only the supplier title recorded in research data, where the
   assortment already says to drop it). Nothing to change.
5. **"المية على الأرض بعد الحوض؟"** is in a studio brief (video), not in store copy. Video is scheduled last, so it is
   not rewritten now; it goes to the video phase's native-reviewed captions.
6. **Old rates.** The old studio ledger counted at 2.5× the Sonnet 5.5 list price ($2/$10 per MTok), so it overstated
   spend; the review is right that environment-editable rates were not a safe bound. Now 1.5× list from a file.
7. **Consent window.** Reproduced (as asked); it cannot be closed while a store that cannot write the shared database
   has no other channel, so the answer is the review's alternative: server events off by default, decided by Rashid
   at P1 (O21).
8. **Weak card photos** (the blanket): no code fix exists without stretching or cropping, which the design forbids;
   it needs another original from Taager (O6).
9. **Beyond the review:** (a) delivery times in the fee table came from the supplier's publication: hidden until
   observed, under the same no-timing-promise rule the review applied to the confirmation call; (b) Egypt's consumer
   law may give a 14-day return without a defect, wider than our supplier-based returns line: owner question (O17);
   (c) the completion report's "no Meta account" wording is corrected (Rashid owns the accounts; the app, token and
   permissions are missing).

## Anthropic API usage this round
Four critique runs, **15 calls** (Claude Opus 5.5), all settled through the shared ledger: 150,450 input and 70,369
output tokens; **2.01 USD at list price, 3.01 USD counted** (1.5×). Ledger total including earlier calls: **4.42 USD
held of 40** (2.28 at list for settled calls, plus a 1 USD reservation for unrecorded calls in earlier failed runs);
**35.58 USD remaining**. By envelope: EG 1.41 / 18, IQ 1.23 / 12, Gulf 0.79 / 5, reserve 1.00 / 5. No video or copy
drafting calls. The Anthropic console is the source of truth for what was charged (O20).

## Residual risk, stated plainly
- Language: AI-written and AI-critiqued, **not native-reviewed**; Iraqi spelling and Egyptian warmth are judgements
  until O8.
- Email and consent: locally tested only; Brevo's webhook headers, idempotency behaviour and event payloads are
  checked at O14; host cron and the shared database at staging.
- The consent window above, if server events are switched on.
- Everything about Taager remains unproven until staging.

## Counts
Local, on the final tree: **115 end-to-end tests** (114 run here, the WebKit/iPhone test in CI), **777 in-store
checks** (EG 164, IQ 165, SA 151, AE 145, OM 152), **17 studio and API-ledger checks** (10 + 7). The last full local
run had one failure, an assertion still expecting the old review-thanks wording; it was updated and that test rerun
green. The first CI run of the round was cancelled at the 55-minute job limit (the suite grew by nine browser tests);
the new tests were made cheaper (one PHP call per store for setup) and the job limit raised to 80 minutes. CI on the
final commit: recorded in the Project handoff and docs/PROJECT-STATE.md.

## Decision taken with Rashid this round
Email stays dormant at launch: phone-only stores; the hardened email code stays off and is revisited after real orders.
