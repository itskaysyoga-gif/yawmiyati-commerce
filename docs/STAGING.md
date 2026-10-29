# Staging: from hosting to a verified real order

_29 Oct 2026 (tightened the same night: deployment checks, rollback, order classes; docs/reports/2026-10-29-staging-tightening.md).
Goal: Egypt, then Iraq, each with the real Taager plugin linked and real test orders observed end to end. Status words as
in docs/LAUNCH-CHECKLIST.md. Nothing here is done yet; each step names who does it (R = Rashid, C = Claude, T = Taager)
and the evidence that closes it. Photos do not block any step (docs/PHOTOGRAPHY.md). **Development is frozen**: from
here on only staging work, fixes for defects found on staging, and changes a verified launch blocker needs._

## 0. Before starting (R)
| | Action | Evidence |
| --- | --- | --- |
| O1 | Make the GitHub repository private | Repo page shows Private |
| O4 | Buy yawmiyati.shop and SiteGround GrowBig | Receipt |
| O3 | Say yes (or changes) to docs/ASSORTMENT-v1.md | Rows marked approved with date |
| O5 | Send the one Taager request (docs/requests/taager-request.md; replaces rounds 3-5, none of which was sent) | Answers recorded as "Taager said" in COUNTRY-RULES |
| O22 | Test-order people: for each country, a mobile number in that country that someone answers during the test week (for the confirmation call), and **one person at a real address who agrees to receive and pay for one delivered order** (class D below) | Names and governorates (kept off git); their yes |

## 1. Layout on the host (C writes the steps, R clicks where a panel is needed)
- Production later: `yawmiyati.shop/` = the country chooser (deploy/root), `/eg/` and `/iq/` = two separate
  WordPress installs (DECISIONS: one install per country, no Multisite). Saudi Arabia, UAE and Oman are **not
  installed** for now.
- **Staging now**: `staging.yawmiyati.shop/eg/` and `/iq/`, two installs, **password-protected** (Site Tools >
  Security > Protected URLs, the whole `staging.` site including `wp-login.php` and the REST API), noindex everywhere
  (any environment other than production).
- Deploy area outside the web root: `~/ym-deploy` (releases, backups, journal); created by the first release.
- One extra database `ym_shared` (consent ledger, store certificates, email permissions), with both stores' database
  users granted on it.
- Versions as tested: WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.3 (dev/versions.env). Record SiteGround's database
  version (verification task 10).

## 2. Each staging store (C, over SSH once access exists; R where a panel is needed)
1. Install WordPress and WooCommerce at the pinned versions; Arabic language packs. Site URL exactly
   `https://staging.yawmiyati.shop/eg/` (the deploy refuses any other URL).
2. wp-config constants (values in the host panel and the password manager, never in git or chat):
   `WP_ENVIRONMENT_TYPE=staging` (the deploy refuses `production`, and refuses `local`), `DISABLE_WP_CRON=true`,
   `YM_SHARED_DB=<shared db name>`, `YM_CONSENT_STORES=eg,iq` (exactly the installed stores), `YM_MAIL_SECRET`
   (same on both), `YM_ALERT_WEBHOOK`. Leave `YM_SERVER_EVENTS` unset (O21) and every email flow off (email dormant
   at launch). Set the environment type **only** in wp-config.php: the order-test window switches it there.
3. First release (step 3), then activate the plugin and theme, then `wp ym setup --country=EG` (or IQ): currency,
   delivery zone, COD only, guest checkout, pages, Arabic date format.
4. **Cron before anything scheduled is tested** (O10): Site Tools > Devs > Cron Jobs, every 5 minutes per store,
   `cd <store> && php wp-cron.php`. Then run each job once (`wp ym ops-run consent_certify`, `supplier_sweep`,
   `stock_guard`, `daily_digest`, `weekly_report`): until a job has run once the health endpoint answers 503 — the
   weekly report would otherwise keep it at 503 until the first Monday. Evidence: `wp ym ops` shows no overdue job,
   health 200. The order-test window refuses to open unless the 5-minute job ran in the last 15 minutes.
5. Uptime monitor on `https://staging.yawmiyati.shop/eg/?rest_route=/ym/v1/health` **with the protection's user and
   password** (without them it only ever sees 401). Alert webhook test (O18).
6. Products: `wp ym seed` creates the offers with the same Taager SKUs as the assortment (seed refuses to run on
   production). `wp ym launch-check` lists what is still open; `wp ym hero <sku>` sets the home product.

## 3. Releasing to staging (C)
- A release is a commit **on `main` whose `tests` run on `main` passed** (a pull-request or branch run does not count).
  Push a tag `staging-YYYYMMDD-N` on it. Going back to an older release = tag that older main commit.
- GitHub "staging" environment (R adds them; Claude never sees the values): secrets `STAGING_SSH_KEY` (a key made for
  this, Site Tools > Devs > SSH Keys), `STAGING_SSH_HOST`, `STAGING_SSH_USER`, `STAGING_SSH_PORT` (usually 18765),
  `STAGING_KNOWN_HOSTS` (the host key line, so the connection is pinned), `STAGING_ROOT` (the staging document root,
  e.g. `www/staging.yawmiyati.shop/public_html`), **`STAGING_HTTP_AUTH`** (`user:password` of the URL protection);
  variable **`STAGING_BASE_URL`** = `https://staging.yawmiyati.shop`. Nothing runs until all are set; a partial set fails.
- What the `deploy-staging` workflow does (deploy/ci-staging.sh, deploy/staging-host.sh; every path tested against a
  fake host in deploy/test, run in CI):

| Stage | Checks | On failure |
| --- | --- | --- |
| Commit | on `main`; green `tests` run on `main` for that exact commit | refused |
| Configuration | base URL is `https://staging.<domain>` with no path; document root contains `staging` | refused |
| Upload | release packed, copied to `~/ym-deploy`, checksum verified, unpacked (no store file touched) | refused, nothing changed |
| Destinations, **all stores before the first change** | store, `wp-login.php` and REST API answer 401 without the password; WordPress's home and site URL are exactly `STAGING_BASE_URL/<cc>/`; environment `staging` or `development` (never `production`, never `local`) | refused, nothing changed |
| Per store: backup | `deploy/backup.sh` (database, uploads, consent ledger once the plugin is active) to `~/ym-deploy/backups/<cc>`, 14 kept | stop before the swap |
| Per store: swap | new plugin and theme copied next to the live ones as dot-directories (never loaded), then renamed in under maintenance; a dropped SSH connection cannot stop it half-way; journal `~/ym-deploy/state/<cc>` | — |
| Per store: verify | WordPress loads with every plugin (no PHP fatal); cache flush; over HTTP: still 401 without the password, home 200 with `noindex`, health answers JSON for this country (200, or 503 = jobs not run yet: reported), `wp ym launch-check --format=json` runs | **rolled back** to the previous plugin and theme; the run fails |
| Per store: keep | journal `done`; previous copy moved out of the web root to `~/ym-deploy/previous/<cc>` | the next run finishes it |
| Report | launch-check open items per store, **as expected items, not failures** | — |

- **Interrupted deployment** (runner cancelled, SSH lost, host restart): the journal says where it stopped. The next
  run (or `bash ~/ym-deploy/releases/<sha>/deploy/staging-host.sh recover <cc> <store>`) rolls back anything not yet
  verified: an unverified release is never left live. A release already verified only has its clean-up finished.
  Maintenance mode is ours to remove (WordPress also ignores it after 10 minutes). Tested: swap stopped half-way,
  release swapped but not verified, explicit rollback refused when nothing is unverified.
- **Database**: the plugin keeps data in options and order meta and only adds tables with CREATE TABLE IF NOT EXISTS
  (docs/OPERATIONS.md), so a code rollback needs no database restore. The pre-release backup is restored only by a
  person (`deploy/restore.sh`), because it would discard orders placed since.
- Stores are released one after the other; if Iraq fails after Egypt succeeded, Egypt keeps the new release and the
  summary says so. The concurrency group lets one release or mode switch run at a time.

## 4. Taager on Egypt staging (R links, C reads and tests)
1. R installs the Taager WooCommerce plugin on the Egypt staging store and links it to the Taager Egypt store;
   record the plugin version.
2. C reads the plugin's code: which hook sends the order, which meta key holds the Taager reference
   (`ym_supplier_ref_keys_verified`), **whether on-hold orders are sent**, and **which fields it sends** — in
   particular the second phone (`_ym_phone2`), the nearest landmark (`_ym_landmark`) and building details
   (`billing_address_2`). If the plugin ignores them, the confirmation team never sees them; the fix is decided then
   (for example copying them into the order note the plugin does send), not before.
3. **Open the order-test window**: push tag `staging-mode-eg-development-YYYYMMDD-N` on a green main commit. It
   refuses unless the store, its login and REST API demand the password and the 5-minute cron job ran in the last 15
   minutes; then sets `WP_ENVIRONMENT_TYPE=development`, reads it back and checks the password again (if protection
   does not hold it switches straight back to staging). In development the catalog gate does not block, so the interim
   products can be ordered, and **every order placed is marked `_ym_test` automatically**, so reports and kill-gates
   leave it out.
4. Test orders, in two classes that are never mixed:

**Class C: cancelled before shipping** (no goods move; cost per Taager's answer to question 4 of the request).
Placed with the test phone (O22); cancelled in Taager as soon as the check is read; recorded with
`wp ym outcome <id> cancelled --reason=before_handoff --evidence="Taager order …, cancelled <date>"`.

| # | Test | Pass when |
| --- | --- | --- |
| E1 | Ordinary order, one item, Cairo | One order in Taager with the right SKU, quantity, name, phone, governorate, address, COD total |
| E2 | Two items in one order | One delivery fee in Taager (today "Taager said") |
| E3 | The 2-set mats SKU | Taager's price and fee match the store's |
| E4 | A deliberately held order (duplicate phone) | Absent from Taager until released; appears once after release |
| E5 | Lost-response retry (submit, cut the connection, submit again) | Exactly one Taager order |
| E6 | Second phone and landmark filled | Seen in Taager, or recorded as not carried (then step 4.2 decides) |
| E7 | Status sync up to cancellation | "confirmed" (if reached) and "cancelled" arrive in WooCommerce; names recorded for `ym_stage_map` |

**Class D: delivered, approved one by one by Rashid** (real money: the recipient pays the COD total, Taager credits
the profit). One per country at first. Rashid's written yes names the offer, the price and the recipient (O22) before
the order is placed; nothing in class D is placed on Claude's own initiative.

| # | Test | Pass when |
| --- | --- | --- |
| E8 | Confirmation call, parcel, invoice | Who called, from which number, what name was given; what the parcel and invoice show (customer text is updated to match) |
| E9 | Delivery | Shipped and delivered statuses arrive in WooCommerce → `ym_stage_map` complete, then `ym_status_sync_verified`; days for that governorate recorded (`ym_delivery_days_verified` only once several zones are observed; until then the fee table shows fees only) |
| E10 | Payout (O9) | The order's profit appears in the Taager balance; a small withdrawal reaches the intended account; fee recorded |

   Recorded with `wp ym outcome <id> delivered --evidence=…`. Test orders of both classes stay out of reports.
5. **Close the window**: tag `staging-mode-eg-staging-YYYYMMDD-N`. It sets `staging`, reads it back, checks the
   password, and fails unless the catalog gate is enforced and no unverified product can be bought; it lists test
   orders that still have no outcome.
6. Governorate codes ↔ Taager province names checked on the orders (P0-10).
7. Results go to `docs/reports/staging-<date>.md` with order numbers and screenshots; supplier-dependent checks move
   from "Taager said" to "supplier-verified" only with that evidence.

## 5. Iraq staging
The same steps and tests on the Iraq store (I1-I10, same classes), plus: the refusal charge in writing (request
question 9), stock per offer (Iraq's export has none), return window (3 or 14 days).

## 6. Then
Native language review on the staging pages (O8), production install from the same release (P0-17), market gates by
commit. Ads only after P1. Email and video stay off.
