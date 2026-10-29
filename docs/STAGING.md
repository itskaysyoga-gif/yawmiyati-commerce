# Staging: from hosting to a verified real order

_29 Oct 2026. The next phase. Goal: Egypt, then Iraq, each with the real Taager plugin linked and real test orders
observed end to end. Status words as in docs/LAUNCH-CHECKLIST.md. Nothing here is done yet; each step names who does
it (R = Rashid, C = Claude, T = Taager) and the evidence that closes it. Photos do not block any step
(docs/PHOTOGRAPHY.md)._

## 0. Before starting (R)
| | Action | Evidence |
| --- | --- | --- |
| O1 | Make the GitHub repository private | Repo page shows Private |
| O4 | Buy yawmiyati.shop and SiteGround GrowBig | Receipt |
| O3 | Say yes (or changes) to docs/ASSORTMENT-v1.md | Rows marked approved with date |
| O5 | Send Taager rounds 3-5 (docs/requests/) | Answers recorded as "Taager said" in COUNTRY-RULES |

## 1. Layout on the host (C writes the steps, R clicks where a panel is needed)
- Production later: `yawmiyati.shop/` = the country chooser (deploy/root), `/eg/` and `/iq/` = two separate
  WordPress installs (DECISIONS: one install per country, no Multisite). Saudi Arabia, UAE and Oman are **not
  installed** for now.
- **Staging now**: `staging.yawmiyati.shop/eg/` and `/iq/`, two installs, protected by a password (Site Tools >
  Security > Protected URLs), noindex everywhere (any environment other than production).
- One extra database `ym_shared` (consent ledger, store certificates, email permissions), with both stores' database
  users granted on it.
- Versions as tested: WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.3 (dev/versions.env). Record SiteGround's database
  version (verification task 10).

## 2. Each staging store (C, over SSH once access exists)
1. Install WordPress and WooCommerce at the pinned versions; Arabic language packs.
2. wp-config constants (values in the host panel and the password manager, never in git or chat):
   `WP_ENVIRONMENT_TYPE` (see 5), `DISABLE_WP_CRON=true`, `YM_SHARED_DB=<shared db name>`,
   `YM_CONSENT_STORES=eg,iq` (exactly the installed stores), `YM_MAIL_SECRET` (same on both), `YM_ALERT_WEBHOOK`.
   Leave `YM_SERVER_EVENTS` unset (O21) and every email flow off (email dormant at launch).
3. Deploy the release (step 3 below), activate the plugin and theme, then `wp ym setup --country=EG` (or IQ):
   currency, delivery zone, COD only, guest checkout, pages, Arabic date format.
4. Cron every 5 minutes per store: `cd <store> && php wp-cron.php` (O10). Uptime monitor on `/wp-json/ym/v1/health`.
5. Products: `wp ym seed` creates the offers with the same Taager SKUs as the assortment (seed refuses to run on
   production). Two phases, using what the plugin already has (no new switch):
   - **Order tests** (step 4): the store runs as `WP_ENVIRONMENT_TYPE=development` — still noindex and
     password-protected, but the catalog gate does not block, so the interim products can be ordered and real orders
     reach Taager. Every test order gets the order meta `_ym_test` = 1, so reports leave it out.
   - **Gate check** (before any real customer): switch to `staging`; unverified products fall back to draft and stop
     selling. A product sells again only after `wp ym check <sku> --identity --specs --media --price --stock` records
     real evidence (O6 data and photos).
6. `wp ym launch-check` lists what is still open; `wp ym hero <sku>` sets the home product.

## 3. Deploying a release (C)
- A release is a commit with green CI on `main`. Push a tag `staging-YYYYMMDD-N` on it: the `deploy-staging`
  workflow checks that the commit's tests passed, copies only the plugin and theme to each store over SSH, and prints
  `wp ym launch-check` per store. It does nothing until these secrets exist in the GitHub "staging" environment
  (R adds them; Claude never sees the values): `STAGING_SSH_KEY` (a key made for this, added in Site Tools > Devs >
  SSH Keys), `STAGING_SSH_HOST`, `STAGING_SSH_USER`, `STAGING_SSH_PORT` (SiteGround shows it; usually 18765),
  `STAGING_KNOWN_HOSTS` (the host's key line, so the connection is pinned), `STAGING_ROOT`.
- The build container cannot reach SiteGround directly (network allowlist), so deployment runs in GitHub Actions; the
  first deploy confirms rsync is available on the host (otherwise the step is switched to scp).

## 4. Taager on Egypt staging (R links, C reads and tests)
1. R installs the Taager WooCommerce plugin on the Egypt staging store and links it to the Taager Egypt store;
   record the plugin version.
2. C reads the plugin's code: which hook sends the order, which meta key holds the Taager reference
   (`ym_supplier_ref_keys_verified`), **whether on-hold orders are sent**, and **which fields it sends** — in
   particular the second phone (`_ym_phone2`), the nearest landmark (`_ym_landmark`) and building details
   (`billing_address_2`). If the plugin ignores them, the confirmation team never sees them; the fix is decided then
   (for example copying them into the order note the plugin does send), not before.
3. Test orders, cancelled in Taager before shipping (ask Taager first whether a cancelled test order costs anything):

| # | Test | Pass when |
| --- | --- | --- |
| E1 | Ordinary order, one item, Cairo | One order in Taager with the right SKU, quantity, name, phone, governorate, address, COD total |
| E2 | Two items in one order | One delivery fee in Taager (today "Taager said") |
| E3 | The 2-set mats SKU | Taager's price and fee match the store's |
| E4 | A deliberately held order (duplicate phone) | Absent from Taager until released; appears once after release |
| E5 | Lost-response retry (submit, cut the connection, submit again) | Exactly one Taager order |
| E6 | Second phone and landmark filled | Seen in Taager, or recorded as not carried (then step 4.2 decides) |
| E7 | Status sync | Each status Taager sets arrives in WooCommerce; `ym_stage_map` filled, then `ym_status_sync_verified` |
| E8 | Confirmation call | Who calls, from which number, what name is given; parcel and invoice branding (customer text is updated to match) |
| E9 | Delivery time | Days per zone observed → `ym_delivery_days_verified`, so the fee table may show them |

4. Governorate codes ↔ Taager province names checked on the orders (P0-10).
5. Results go to `docs/reports/staging-<date>.md` with order numbers and screenshots; supplier-dependent checks move
   from "Taager said" to "supplier-verified" only with that evidence.

## 5. Iraq staging
The same steps and tests on the Iraq store (I1-I9), plus: the refusal charge in writing (O5), stock per offer
(Iraq's export has none), return window (3 or 14 days).

## 6. Then
Native language review on the staging pages (O8), payout test (O9), production install from the same release
(P0-17), market gates by commit. Ads only after P1. Email and video stay off.
