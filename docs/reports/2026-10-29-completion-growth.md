# Completion and growth round, 29 Oct 2026

Scope: ChatGPT's "Yawmiyati unified completion and growth brief" (29 Oct), on top of the stabilization round. Status
words as in docs/LAUNCH-CHECKLIST.md (implemented, locally tested, provider-tested, commercially approved). **Nothing
here is supplier- or provider-tested**: no Taager, Meta or Brevo account was used.

## Baseline (before any change)
Fresh container, pristine `c3cdab3` (code `27177a8`): MariaDB 10.11.14 installed, `dev/setup.sh`, `dev/serve.sh`,
`npm ci`. **90 end-to-end tests (89 passed, 1 skipped: the WebKit/iPhone test runs only in CI) and 667 in-store checks
(EG 142, IQ 143, SA 129, AE 123, OM 130), all passing**, matching the stabilization report and CI run 40780852912.
(A first baseline attempt was discarded: the stores load the plugin from the working tree, so edits leaked into it;
it was rerun on an untouched checkout.)

## Result
- Commits: `768f71f` (consent, storefront, recommendations, email) … `1a166b7` (studio batch, cart, docs) and the
  closing docs commit. CI: see docs/PROJECT-STATE.md for the final commit and run.
- Tests: see "Counts" at the end (filled from the final run).

## What changed, and the test that holds it
| Area | Change | Tests |
| --- | --- | --- |
| Consent partial failure (§3) | Withdrawal queued locally first in an atomic table; each store certifies its queue to the shared ledger every 5 min; server ad events two-phase in every store (decide → all stores certified past the decision → re-read ledger → send); a store that cannot write can never certify, so everyone holds | e2e: Egypt write fails while Iraq reads → her queued Iraq event (and everyone's) held, then skipped once Egypt flushes; sending store cannot write → nothing sent; 12 simultaneous withdrawals with concurrent retries lose none. Integration: read-only ledger, queue upserts, export/import never shortens, migration of the old option. Restore drill restores the ledger into an empty database |
| Order key wording (§3) | Docs no longer claim the key "never exists": the first request line can reach host and CDN logs; our logs never hold it; the redirect is no-store | e2e: store logs scanned for the key; redirect answer no-store |
| Consent experience (§5) | Compact card (one line, two buttons, "choose myself"); analytics-only state (GTM with ad storage denied, no click IDs, no server ad events); page reserves the card's height | e2e: card ≤ 170 px on a phone, nothing pre-ticked, analytics-only end to end; existing "never covers the order button" test |
| Home (§4) | One focal image + one clear action; categories; curated selection of orderable items only; one home tip; how ordering works. Licensed photo slot (`ym_hero_image`), otherwise the featured product whole | e2e: one focal image, one high-priority image, action above the fold at 390 px, 3 steps, tip; 360 px no overflow |
| Card media (§4) | 4:5 card derivatives from the original: cut-outs trimmed to one optical scale, photos never stretched, weak wide photos flagged in the gate; WebP + JPEG with srcset/sizes/dimensions | gate check; lab run below |
| Media rights (§4) | `wp ym media` records channels, editing rights, expiry and owner approval; gate and studio use them | studio preflight tests |
| Confirmation page (§4) | "What happens next": not confirmed yet, call, pay the courier on delivery | covered by order-flow tests (rendered on every order) |
| Phone cart | Lines as cards; delivery stated in the cart; no "total due" row that left delivery out | e2e: no overflow, no misleading total, delivery shown |
| Data consistency (§2) | Seed prices follow first_wave.csv (Egypt rack 689, labelled proposed); previews stamped with commit, data revision and time, PREVIEW ONLY, noindex | e2e data tests: seed = assortment = store price; copy keys in step; no preview markup or local address in plugin/theme |
| Recommendations (§6) | Curated deterministic rules; product page and cart; labels complement / alternative; never cart items, bundle parts, out-of-stock items or items the page sells cheaper as a bundle; source on the order line; report | e2e: substitute shown and labelled; nothing on the Iraq rack page (its own bundle is cheaper); nothing for items in the cart or out of stock; added item goes through the cart, one delivery fee, `_ym_reco_from` on the line, report counts it |
| Email (§7) | Shared permission records; optional receipt email only when receipts are sent; double opt-in signup (footer, after order); cross-store strongest-wins suppression; caps; COD-correct transactional wording; WooCommerce's customer emails off; Brevo adapter + idempotent webhook; flows off by default | e2e: phone-only order without a made-up address; receipt wording; consent required; confirmation link; welcome with one-click unsubscribe; unsubscribe in Egypt suppresses Iraq; import never re-subscribes; webhook auth and idempotency |
| Kill-gates (§6) | Contribution per submitted order with an 80 % band (`wp ym gates`, ops board) replaces "pause below 35 % kept"; break-even CPA called a ceiling | integration: 35 % kept but profitable → keep; 60 % kept but losing → pause; unknown spend → no decision; band across zero → test |
| Content Studio (§8-9) | studio/: facts export → API brief in CI (spend-capped) → rules → Remotion renders → QA → owner batch approval → Meta publisher with preflight, reconciliation, pause, dead letter; 7 custom skills; Remotion skills fetched at a pinned commit | studio tests (in CI): rules; publish once with ID recorded; timeout reconciled without duplicate; changed offer, stock, file or landing page → invalidated; production refuses blocked records; dead letter; expired token |

## The first real content batch (pipeline proof, not for publication)
Briefs drafted by the Anthropic API in CI (claude-sonnet-5-5, forced through a tool schema and the script rules):
Iraq sink rack passed first time; Egypt mats was **rejected by the rules** (it pointed to sizes the store does not
know and was cut off at the token limit), the prompt was corrected (no measurement talk when sizes are unknown) and
it passed. Renders (studio/records/, files git-ignored): per country 6 vertical reels (3 angles × 2 hooks,
1080×1920, 12.5-15 s), 3 re-composed 4:5 feed versions, a 4-slide carousel and a story. All 14 records pass automated
QA and are **blocked for production** (interim supplier photos without organic rights, dialect review pending, catalog
gate). API spend this round: 4 calls, 0.35 USD counted at deliberately high rates (studio/spend.json; the Anthropic
console shows the real, lower figure). Cap: 40 USD.

## Corrections to the brief (where it was wrong or conflicted with the repo), and why
1. **AI website assistant (§10): not built.** Rashid dropped it on 28 Oct (DECISIONS.md); nothing in this brief is a
   launch blocker that needs it.
2. **Postiz → direct Meta Graph API.** Postiz needs its own server or a paid plan for two destinations we can reach
   directly; less to run, and an account test is needed either way.
3. **Manychat deferred.** Its pricing follows contacts; comment-to-DM on 3.4M follows can create billable contacts
   fast, and the website is the only order path. Meta Business Suite automated replies + a person at launch.
4. **Brevo plugin validation skipped; narrow API adapter from the start.** The plugin syncs WooCommerce customers as
   contacts and adds its own tracking, against consent-only marketing and shared suppression. (Design judgement; the
   plugin was not installed.)
5. **Abandoned-checkout email not built.** The email exists only after submission in a phone-first COD checkout;
   marketing to a half-filled form needs prior consent (Egypt's direct-marketing rules).
6. **Kept-rate kill rule replaced, hard gates kept.** Agreed with the brief that "below 35 % kept" ignores margin;
   the gates stay hard (Rashid's preference) but decide on contribution with an uncertainty band.
7. **Email field only while receipts are sent.** The brief asks for an optional receipt email; showing it while no
   receipt is sent would be a false promise, so it appears only when the transactional flow is on.
8. **AVIF not produced here.** The build container's GD has no AVIF; WebP + JPEG now, AVIF when the host's image
   library supports it.
9. **"One approved post published to a connected test destination" cannot be done yet:** no Meta account or token
   exists. The publisher is tested against a fake Graph API; the real test is owner action O12 / checklist P3-35.
10. **Licensed home photograph:** none exists and a generated "home scene" would not be authentic, so the slot is
    built and the featured product is the focal image until Rashid supplies one (O19).
11. **Narration and music:** not at launch (captions carry the message; no licensed track yet).

## Findings that need data, not code
- **Fit text depends on unknown sizes.** The Egypt mats and Iraq rack pages tell her to compare with sizes the store
  does not have yet (sizes are unknown specs). The catalog gate already blocks both products; Taager must supply the
  sizes (O6), then the fit lines and the fit videos are re-checked.
- **Weak card photos:** Egypt blanket and mats detail, Iraq rack, window cleaner, organizer, heater and mats are wide
  photos that fill under 70 % of a 4:5 card. They need other source images, not crops.
- **"Delivery to every governorate"** rests on Taager's published fee table, not on delivered orders (checklist #14).
- **Egypt rack price 689** is still a proposal awaiting Rashid's yes (O3); the stores and previews now say so.

## Residual risk, stated plainly
- Consent: a withdrawal whose ledger write fails in one store, in the minutes between another store's certificate and a
  send already decided before it (decisions older than 10 minutes are renewed), is the one window left. A database-wide
  write failure holds everything at once (the sending store cannot certify either).
- Everything about Taager, Meta and Brevo behaviour remains unproven until staging and the provider tests.

## Lab performance (local, throttled phone, consent denied/granted; not production)
EG home LCP 1.14 s (was 1.45 s), EG catalog 1.75 s (was 2.0 s), product pages 1.0-1.3 s, IQ checkout 2.3 s; CLS ≤ 0.033;
INP ≤ 80 ms; page weight about 22 % lower (WebP card derivatives). docs/reports/perf-lab-2026-10-29.json.

## Counts
Local, on the final tree: **106 end-to-end tests** (105 run in the container; the WebKit/iPhone test runs in CI), **772 in-store checks** (EG 163, IQ 164, SA 150, AE 144, OM 151), **10 Content Studio checks**, restore drill passed with the shared ledger. CI run for the final commit: recorded in the Project handoff.
