# Visual polish pass and staging preparation, 29 Oct 2026 (evening)

Scope: Rashid approved the warm berry/ivory/blush/sage direction and asked for one bounded polish pass (no redesign,
no new features), then staging preparation. Email stays dormant, video deferred, **no API budget spent**.

## What changed, by Rashid's seven points
| # | Point | Change | Evidence |
| --- | --- | --- | --- |
| 1 | Hero | Desktop split kept. Phones: hero about 20 % shorter (≈500 px instead of ≈630 at 390 px): tighter spacing, 27 px headline, focal image height-capped at 210 px (whole picture, never cropped), smaller offset panel; the main action stays full-width and above the fold. Exact licensed photo per country specified | docs/PHOTOGRAPHY.md §1; home test (action visible without scrolling) |
| 2 | Cards | Identical 4:5 frames (also when an image is missing), whole photos never stretched or cropped, titles on two reserved lines and prices on one baseline per row. Weak photos recorded, not disguised: 7 of 12 interim images fill under 70 % of the frame and every one is below the 1000 px minimum | docs/PHOTOGRAPHY.md §2 |
| 3 | Navigation | Category counts gone; only categories with an orderable product (on staging/production that includes the catalog gate); one "view all"; home shows four picks (one row on desktop, two on a phone). Conditional products (heaters) are never offered on home, navigation or suggestions, and are a gate blocker of their own | storefront tests: "Home …", "Navigation offers only categories with an orderable product …" |
| 4 | Checkout | "(اختياري)" once; the second phone opens with "+ إضافة رقم …" (same field name and validation, so the order data and hand-off do not change); delivery row reads "التوصيل · 95 ج.م" instead of "الشحنة · التوصيل: 95"; before a governorate, one subtotal row with "delivery after choosing"; after, quantities, delivery and "المطلوب وقت الاستلام" in the storefront's card style; "إرسال الطلب" kept, with the phone-confirmation line right above it. Cart and confirmation tables restyled the same way; quiet "update cart"; Arabic date ("29 أكتوبر 2026") | checkout form test (field order, second phone on request, "optional" once, call note); reliability test (totals before/after the governorate) |
| 5 | Language in context | Egypt and Iraq read page by page at 360 px (headings, product names, buttons, labels, errors, empty states, confirmation). Fixed: amounts split from their currency at line ends (now never break), lone last words (balanced wrapping), 404 page was empty (now a heading, help line, search and "view all"), empty cart in formal WooCommerce Arabic (now ours), gallery buttons read "1 / 2" to screen readers (now "صورة 1 من 2"), Iraqi "التفاصيل بـ [link]" (now "في"), the confirmation table's column header said "what you pay on delivery" (now WooCommerce's "الإجمالي"), a repeated one-fee line on the cart, a redundant note under the Iraq bundle. **Native review still outstanding** (O8) | rendered-page inventory regenerated; review packages regenerated |
| 6 | Screens and overlap | Phone (360, 390) and desktop (1280), Egypt and Iraq, with and without the consent card: at every scroll position nothing important stays covered once scrolled; the sticky order bar now hides while any part of the order form is on screen (it used to cover the quantity stepper); no sideways scroll; Facebook and Instagram links visible with accessible names (Egyptian spelling "إنستجرام" in Egypt) | sweep script (results in this report); review PDF of the final commit |
| 7 | Finished vs needs assets | Finished: everything above. Needs assets: docs/PHOTOGRAPHY.md. External verification: docs/STAGING.md and the checklist | — |

## Staging preparation
- **docs/STAGING.md**: host layout (staging.yawmiyati.shop/eg and /iq, password-protected, noindex; SA/AE/OM not
  installed), store setup, cron and monitor, two phases (order tests with the gate off on the protected host, then
  the gate check), the Taager plugin reading list (which hook, which reference key, on-hold handling, **which fields
  it carries**: second phone, landmark, building), and the test list E1-E9 / I1-I9 with what closes each.
- **deploy-staging workflow**: a `staging-*` tag on a CI-green commit copies only the plugin and theme to each staging
  store over SSH (pinned host key) and prints `wp ym launch-check`; idle until Rashid adds the secrets. Needed because
  this build container cannot reach SiteGround.
- **docs/requests/taager-round-5.md**: test-order cost, whether the second phone and landmark reach Taager, the
  caller's and parcel's name.

## Corrections and choices (with reasons)
- **Card scale on the current photos cannot be balanced honestly.** All twelve interim files are small, wide supplier
  thumbnails; balancing them would need stretching or cropping product edges, which the rules forbid. The card system
  already trims cut-outs to one optical scale, so it balances itself once the replacement originals arrive.
- **Second phone on request** is compatible with the current hand-off (same field and meta); whether Taager's plugin
  carries it at all is unknown and is now test E6, not an assumption.
- **The checkout had no phone-confirmation line next to the button** (it was only on the confirmation page); the
  first "what happens next" step now sits above "إرسال الطلب" (existing, reviewed wording, no new copy).
- Kept the white card frames (approved design) rather than tinting them to hide empty space.

## Counts
Local run on the final tree: **116 end-to-end** (115 pass, the WebKit/iPhone test runs only in CI), **777 in-store
checks** (EG 164, IQ 165, SA 151, AE 145, OM 152), **17 studio and API-ledger checks**. One earlier local run had the
four-customer concurrency test time out once; it passed three times alone and in the full rerun. _Corrected the same
night: the cause was not established, so this is an intermittent timeout, open, not "load timing, not a defect"
(docs/reports/2026-10-29-staging-tightening.md, "Known intermittent")._ API ledger unchanged: 4.42 of 40 USD held, nothing spent this
round. Final commit and CI run: in the Project handoff.
