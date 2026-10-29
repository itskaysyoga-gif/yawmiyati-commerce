# Measurement specification

_Version 2, 28 Oct 2026 (late evening): withdrawal, purchase acknowledgement, late consent, page_location, verified delivery, five countries._ Version 1, 28 Oct 2026. Implemented in `plugin/yawmiyati-commerce/includes/class-ym-tracking.php` and
`class-ym-ops.php`; tested locally by `tests/e2e/measurement.test.js`. Live checks (section 9) are not done._

No ad spend until every live check in section 9 passes and the report is signed off.

## 1. Principles
1. **One owner per event and destination.** The plugin pushes browser events to the dataLayer; GTM is the only thing
   that sends them to GA4, Meta Pixel and Snap Pixel. The plugin is the only thing that sends server events (Meta and
   Snap Conversions API). No other plugin (including the Taager plugin or any WooCommerce marketing plugin) may load
   these pixels. Checked in section 9.
2. **One ID per order across every system:** the country-prefixed transaction ID `EG-<order id>` / `IQ-<order id>`.
   It is GA4 `transaction_id`, Meta `eventID`/`event_id` and Snap `transaction_id`/`event_id`.
3. **No personal data in analytics.** The dataLayer and GA4 never receive name, phone, address or email. Server events
   carry the phone only as a SHA-256 hash, and only for orders placed with consent.
4. **Consent first.** Nothing that measures or advertises loads before the visitor accepts.
5. **COD honesty.** A submitted order is not revenue. Ads are judged on delivered contribution (section 8).

## 2. Accounts and IDs (to create; Rashid's yes needed where it costs money)
| Item | Recommendation | Where it is set |
| --- | --- | --- |
| GTM | One web container for yawmiyati.shop (both stores) | option `ym_gtm_id` on each store |
| GA4 | One property, one web stream for yawmiyati.shop; country as a custom dimension `ym_country` | GA4 tag in GTM; option `ym_ga4_id` |
| Meta | One dataset (pixel) for yawmiyati.shop, ad sets per country | option `ym_meta_pixel_id`; token `YM_META_CAPI_TOKEN` |
| Snap | One pixel, campaigns per country | option `ym_snap_pixel_id`; token `YM_SNAP_CAPI_TOKEN`; or `ym_snap_disabled` |

Tokens are environment variables on the host (SiteGround) read into `wp-config.php`, never stored in options, git or
chat. Meta Conversions API calls go to Graph API v26.0.

## 3. Events
| Event | When | Sent by | GA4 | Meta | Snap | Dedup key |
| --- | --- | --- | --- | --- | --- | --- |
| page_view | Every page, after consent | GTM | page_view | PageView | PAGE_VIEW | — |
| view_item | Product page load | dataLayer → GTM | view_item | ViewContent | VIEW_CONTENT | `ym_event_id` (UUID) |
| add_to_cart | Next page after "add to cart" or "buy now" (`ym_intent` says which) | dataLayer → GTM | add_to_cart | AddToCart | ADD_CART | `ym_event_id` |
| begin_checkout | Checkout page with items | dataLayer → GTM | begin_checkout | InitiateCheckout | START_CHECKOUT | `ym_event_id` |
| purchase (browser) | Order-received page, pushed until the browser acknowledges it with GTM loaded (`POST /ym/v1/dl-ack`, an HMAC token, never the order key); rendering alone is not evidence | dataLayer → GTM | purchase | Purchase (eventID = transaction ID) | PURCHASE (transaction_id) | transaction ID |
| Purchase (server) | Order created, consent granted | plugin, queued | — | Purchase via CAPI | PURCHASE via CAPI | transaction ID |
| OrderDelivered (server) | A **verified** delivery is recorded (YM_Lifecycle: mapped status after the sync is verified, or staff with evidence). A bare WooCommerce "completed" is never enough. event_time = when it was delivered | plugin, queued | — | custom event via CAPI | — | `<transaction ID>-delivered` |

Parameters (GA4 ecommerce schema): `currency`, `value`, `items[]` with `item_id` (SKU), `item_name`, `price`, `quantity`.
Purchase `value` is the products total **without** the delivery fee (the fee is passed separately as `shipping`).
Every push also carries `ym_country`, `ym_currency`, `ym_env` (from the first dataLayer push).

## 4. Consent
- Compact card on first visit (29 Oct): "موافقة", "الضروري بس" and "اختاري بنفسك", which opens two unticked choices:
  usage measurement (analytics) and advertising measurement (Meta, Instagram, Snap). Re-openable from the footer
  ("إعدادات الكوكيز"); never shown again after a choice. While open, the page reserves its height below the content so
  it never covers a button or a field (tested at 390 px on the product page and checkout).
- Choice stored in the first-party cookie `ym_consent` (180 days): `granted` (both), `analytics` (usage only: GTM
  loads with ad_storage, ad_user_data and ad_personalization denied; no ad click IDs; no server ad events) or `denied`.
  Email marketing is a separate permission (docs/EMAIL.md), never implied by any cookie choice.
- Google Consent Mode v2 defaults are all denied. On accept: consent update to granted, then GTM loads. On "essentials
  only": GTM never loads, ad click IDs are not stored, and no server event is sent for orders placed in that state.
- **Withdrawal** (choosing "essentials only" after accepting): consent update to denied for loaded Google tags,
  measurement cookies deleted (_ga, _gid, _gcl_*, _fbp, _fbc, _scid, …), the server is told (`POST /ym/v1/consent`)
  and the page reloads so nothing that had loaded keeps running. The server marks every order of that browser
  (first-party consent subject `ym_cs`) as `withdrawn` and cancels their queued server events; every server event
  re-checks consent when it is sent.
- **Withdrawal reaches all five stores.** The stores share one domain (`/eg/`, `/iq/`, …), so `ym_consent` and `ym_cs`
  are the same cookies everywhere, but each store has its own database. A withdrawal in any store is written to a
  shared ledger (`ym_consent_ledger` in the database named by `YM_SHARED_DB`, readable and writable by every store).
  Every store reads it immediately before sending any server event: an order whose subject withdrew at or after the
  order was placed is skipped (`consent_withdrawn`) and marked withdrawn, whichever store took it. An order placed
  after she accepted again is sent. Partial failures (29 Oct): a withdrawal is queued in the store's own table first,
  then written to the ledger; a store whose ledger writes fail cannot certify, and server events in **every** store
  are two-phase (decide, wait until every store certified past the decision, re-read the ledger, send), so a
  withdrawal stuck in Egypt holds Iraq's events instead of leaking. If the ledger cannot be read, events wait.
  Residual window, stated plainly: a withdrawal whose ledger write fails in the minutes between another store
  certifying and a send already decided before it (at most 10 minutes; decisions older than that are renewed).
  Tested: withdrawal in the Egypt store stops a queued Iraq event; another customer's event still goes out; a later
  order after re-accepting is sent; an unreachable ledger holds the event and releases it once readable; Egypt's
  write failing while Iraq reads fine holds her queued Iraq event (and everyone's) until Egypt flushes, then it is
  skipped; a sending store that cannot write sends nothing; twelve simultaneous withdrawals with concurrent retries
  lose none. Backup: `deploy/backup.sh` exports the ledger; restore merges it and can never shorten a withdrawal.
- **Order key never reaches a tag, analytics or our logs.** The confirmation link carries `?key=wc_order_…`. The
  server keeps the key in her session and redirects to the same address without it before anything renders, so no
  page that can run a tag has the key in its address, history entry or referrer; the first inline script strips it
  as a second guard. Tested with a returning visitor whose consent is already granted and a tag that runs the moment
  it loads: neither the tag, nor any document start, nor any referrer sees the key, and the store's own logs never
  contain it. The review page (its link is a credential) loads no tags at all and sends no referrer.
  **Where the key still exists (29 Oct correction):** the link itself (in the confirmation message she was sent),
  and the first request line before the redirect, which the host's web server, any CDN/WAF and PHP error logs can
  record. Rules: no error reporting service receives request URLs with query strings (none is installed; any added
  later must scrub `key`, `t`, `token` parameters); the host's access logs are kept no longer than the host default
  and are never exported into analytics or AI prompts; the redirect answer is `no-store`. Customer details never go
  into any URL.
- **Late consent:** click IDs seen before any choice stay in that browser tab only (sessionStorage), are never sent,
  and are kept if she accepts during that visit; declining or closing the tab discards them. Campaign tags (UTM) are
  kept regardless, as before.
- The privacy page (content/<cc>/privacy.html) says this in plain words, including the hashed-phone server events,
  the tab-only click IDs and what withdrawal does.

## 5. Personal data
| Data | dataLayer / GA4 | Meta / Snap server events | Stored on the order |
| --- | --- | --- | --- |
| Name, address, landmark | never | never | yes (needed to deliver) |
| Phone | never | SHA-256 of the international number (e.g. 2010…, 9647…), consent only | yes |
| IP, user agent | GA4 standard collection after consent | consent only | IP by WooCommerce; UA consent only |
| fbp / fbc / Snap cookie | — | consent only | consent only |
| UTM tags | campaign tags via GTM | — | yes (`_ym_attr`) |
| Order key, page query strings | never: the key is removed from the address bar before any tag loads (the session keeps it for reloads); checkout and confirmation send only the origin as referrer; the dataLayer carries `ym_page_location` without query strings | never | — |
| Ad click IDs (fbclid, ScCid, gclid, ttclid) | via GTM after consent | Snap click ID, consent only | consent only |

## 6. Attribution capture
On any landing with UTM tags (always) or click IDs (consent only) the site stores first touch and last touch in the
first-party cookie `ym_attr` (90 days): tags, landing path, referrer host, time. At checkout this is copied to the
order (`_ym_attr`) with the consent state (`_ym_consent`). Internal reporting only; never sent to analytics.

UTM convention for our links: `utm_source` = instagram | facebook | snapchat | manychat, `utm_medium` = social | paid |
dm, `utm_campaign` = `<product-slug>-<yyyymm>`, `utm_content` = creative ID.

## 7. Server events: delivery and retries
Queued with Action Scheduler (non-blocking for the customer). Each attempt is stored on the order
(`_ym_capi_<platform>_<kind>`: status, response code, event ID, payload). Up to 3 attempts, 10 and 20 minutes apart; a
third failure raises an alert. Local and staging stores without tokens store the payload as a dry run.
A retry resends the stored payload: same event ID, same event_time (the order's time for Purchase, the delivery's own
time for OrderDelivered). Consent is re-checked at send time; Meta events older than 7 days are skipped and logged.

## 8. Submitted-to-delivered reporting
Moved to docs/REPORTING.md (order lifecycle history, unique orders vs lines vs units, mature cohorts, money lines
kept apart, unknown costs kept unknown). `wp ym report --days=7`.

## 9. Test report (must pass before advertising)
Local automated checks (done, see docs/reports/measurement-local-2026-10-28.md):
consent gating, event sequence, transaction ID format, value excluding delivery, purchase fired once, no personal data
in the dataLayer, server payloads with matching IDs and hashed phone only, nothing sent without consent, attribution
with and without consent.

Live checks on staging with the real IDs (open):
| # | Check | Tool | Pass when |
| --- | --- | --- | --- |
| L1 | GTM container published; tags fire only after consent | GTM Preview | no tag fires before "موافقة" |
| L2 | GA4 receives view_item → purchase with items and value | GA4 DebugView | one purchase per test order, transaction_id = EG-/IQ- |
| L3 | Meta browser Purchase and CAPI Purchase deduplicate | Meta Events Manager, Test Events | one Purchase, "deduplicated" shown, match quality noted |
| L4 | Snap pixel PURCHASE and CAPI deduplicate | Snap Events Manager / validate endpoint | one PURCHASE per order |
| L5 | No other plugin loads pixels | Browser network panel on every page type | only GTM-originated requests |
| L6 | No personal data reaches GA4 | GA4 DebugView parameters | no phone, name or address in any parameter |
| L7 | "Essentials only" really sends nothing | Network panel + order meta | no googletagmanager/facebook/snap requests, no CAPI record |
| L8 | OrderDelivered arrives after a delivered status | Meta Test Events | event with `-delivered` ID |
| L9 | Ad links keep UTM tags into the order | Test order from a tagged link | `_ym_attr` on the order |
| L10 | Withdrawal stops measurement | Accept, browse, then "essentials only"; network panel | no further GA4/Meta/Snap requests after the reload; `_ym_consent` = withdrawn on her orders |
| L11 | No order key or query string in GA4 page_location / page_referrer | GA4 DebugView on the confirmation page and the next page | no `key=` anywhere |
| L12 | Each country's events carry its own currency and ID prefix | One test order per open country | EG-/IQ-/SA-/AE-/OM- with EGP/IQD/SAR/AED/OMR; OMR values with 3 decimals |

Sign-off: when all pass, set option `ym_measurement_tested` to the date and attach the filled table to
docs/reports/. `wp ym launch-check` reads it.

## 10. GTM container setup
Variables: Data Layer Variables `ecommerce.transaction_id`, `ecommerce.value`, `ecommerce.currency`,
`ecommerce.items`, `ym_event_id`, `ym_country`, `ym_page_location`.
**Required:** the Google tag sets `page_location` = `{{ym_page_location}}` (no query strings, so no order key).
Meta and Snap tags fire only when `ym_consent` is granted (they already only load after consent; this also covers the
moment of withdrawal before the reload).
Triggers: Custom Event `view_item`, `add_to_cart`, `begin_checkout`, `purchase`; All Pages (consent already given,
because the container only loads after consent).
Tags:
- GA4 Configuration (Google tag) with `ym_country` as a user property; GA4 Event tags for the four events, "Send
  ecommerce data" from the dataLayer.
- Meta Pixel base code (Custom HTML or template) on All Pages; Meta events with `eventID` = `{{ym_event_id}}`
  (Purchase: `{{ecommerce.transaction_id}}`), `value`, `currency`, `content_ids` from items.
- Snap Pixel base on All Pages; Snap events with `client_dedup_id` = `{{ym_event_id}}`; PURCHASE with
  `transaction_id` = `{{ecommerce.transaction_id}}`.
Do not enable Meta or Snap "automatic advanced matching" in the browser (it would read form fields).
