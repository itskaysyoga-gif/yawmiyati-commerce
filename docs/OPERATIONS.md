# Operations

Scheduled jobs run inside each store (Action Scheduler, `plugin/.../class-ym-ops.php`). They log to WooCommerce >
Status > Logs (source `yawmiyati`), email the admin, and post to `YM_ALERT_WEBHOOK` when that is set. The same alert is
sent at most once an hour. Run any job by hand: `wp ym ops-run <job>`.

| Job | When (store time) | What it does | Alerts when | Depends on Taager |
| --- | --- | --- | --- | --- |
| consent_certify | every 5 min | Flushes this store's queued consent withdrawals to the shared ledger and, only when none remain, writes this store's certificate. Server ad events in **every** store wait until every store in `YM_CONSENT_STORES` has certified past the moment they were decided (docs/MEASUREMENT.md §4) | a withdrawal cannot reach the ledger (all stores then hold their events) | no |
| supplier_sweep | hourly at :07 | Re-checks processing orders from the last 3 days whose hand-off is pending or unconfirmed (looked up by meta directly); also certifies consent | an order turns unconfirmed or conflict | yes: needs the verified reference key |
| (per order) supplier check | 15, 60, 180 min after the order | Looks for the Taager reference; missing = unconfirmed, never "failed" | unconfirmed, conflict | yes |
| stock_guard | daily 06:00 | Lists offers whose stock check is older than 48 h (they stop selling on staging/production) or expires within 12 h; deletes newsletter signups never confirmed after 30 days | any offer expired or expiring | yes: stock is checked by hand in Taager |
| daily_digest | daily 08:30 | Yesterday's orders by status, duplicate holds, unconfirmed / not found / conflict hand-offs, orders stuck over 72 h | anything needs attention | statuses: yes |
| weekly_report | Monday 09:00 | Submitted-to-delivered funnel and contribution per product (docs/MEASUREMENT.md §8) | — (emailed) | delivered/refused stages: yes |
| capi_send (per order) | queued after the order | Meta and Snap server events, 3 attempts | third failure | no |

Supplier-dependent checks stay marked **pending** until the matching verification task closes (docs/PROJECT-STATE.md).
One test order does not close them all: each country, the payout route, returns and stock are verified separately.

## Hosting setup (SiteGround, at launch)
1. Turn off page-load cron: `define( 'DISABLE_WP_CRON', true );` in both stores' wp-config.php (already so locally).
2. Site Tools > Devs > Cron Jobs, one per store, every 5 minutes:
   `cd ~/www/yawmiyati.shop/public_html/eg && php wp-cron.php` (and `/iq`).
3. Environment variables in wp-config.php: `YM_ALERT_WEBHOOK`, `YM_META_CAPI_TOKEN`, `YM_SNAP_CAPI_TOKEN`,
   `YM_SHARED_DB` (the shared database for the consent ledger, store certificates and email permissions; create it
   once and grant every store's DB user on it), `YM_CONSENT_STORES` (required, no default: exactly the installed stores,
   e.g. `eg,iq`; a listed store that never certifies holds every store's server events), `YM_SERVER_EVENTS` (leave
   unset: server-side ad events stay off until Rashid decides at P1, docs/MEASUREMENT.md §4), `YM_MAIL_SECRET` (same value on every store: signed email
   links), `YM_BREVO_KEY` / `YM_BREVO_WEBHOOK_KEY` (when email flows go live), `YM_STUDIO_KEY` (the Content Studio's
   facts endpoint),
   `YM_TRUSTED_PROXIES` / `YM_CLIENT_IP_HEADER` only if a proxy sits in front of PHP (check on staging first)
   (read with getenv or set in SiteGround's PHP variables; never committed).
4. Uptime monitor (UptimeRobot, free tier): one HTTP monitor per store on `/wp-json/ym/v1/health`. It returns 503 when
   any job is late (more than twice its interval), so a stopped queue alerts like a site outage. The endpoint shows
   only job names and minutes since last run.
5. `wp ym launch-check` on each store must exit 0 before ads.

## Operations board
WooCommerce > **Yawmiyati ops** (or `wp ym ops`), per store. Top: "Actions for Rashid", the short list only he can do.
Below: orders held for review, orders without supplier acknowledgement, stock checks due, products not ready to sell
(missing checks or media views), shipments with no outcome after 7 days, returns without a refund, server
measurement failures, overdue jobs, contribution by product (mature orders only), market gates.

## Daily (about 15 minutes per open country)
1. Open the board. Do "Actions for Rashid" top to bottom.
2. **Held orders** (`wp ym review list`): call or message; then `wp ym review release <order> --note="…"` or
   `cancel`. Nothing held reaches the supplier until released (to be proven for Taager's plugin, docs/RISK.md §5).
3. **Supplier acknowledgement:** for each unconfirmed order search Taager (phone, name, date) and record found / not
   found in the order's "تاجر" box. Manual entry only after "not found", within 2 hours. A "possible double" is
   cancelled in Taager the same day.
4. **Outcomes:** record deliveries, refusals (with the reason), returns from Taager's statuses until the status sync
   is verified: `wp ym outcome <order> delivered --evidence="Taager #… delivered 3 Nov"`.
5. **Stock:** confirm every advertised offer in Taager, then `wp ym check <sku> --stock` (it stops selling after 48 h).
6. **Money:** ad spend for the day `wp ym money spend <amount> <date> --source="Ads Manager"`; wallet credits and
   payouts when they happen.
7. **Reviews:** send review links for yesterday's verified deliveries (`wp ym review-link <order>`); approve or reject
   pending reviews (`wp ym reviews`).

Lifecycle commands used daily: `wp ym outcome <order> <stage> --evidence=… [--lines=SKU:units,…]`,
`wp ym conflict list` / `wp ym conflict resolve <order> <event> --accept|--reject --note=…`,
`wp ym credit <order> <amount> --source=… [--line=SKU]` (docs/REPORTING.md).

## Weekly (Monday, about 45 minutes)
Read the Monday email (last 7 days + the mature 4-week cohort). Per product: `wp ym gates` gives too_early /
unknown / test / keep / pause from contribution per submitted order with an 80 % band (docs/ASSORTMENT-v1.md
kill-gates); pause what it says, never on yesterday's undelivered orders. `wp ym reco-report --days=30` for items added
from recommendations. Content: approve next week's studio batch (docs/CONTENT-STUDIO.md) and look at
studio/state/alerts.log (invalidated or dead-letter posts). Check the
shortlist (docs/PRODUCT-SELECTION.md) for the next candidate to research. Look at refusal reasons: operational causes
go to Taager; customer causes feed the risk records automatically. Run the restore drill monthly.

## Access
| Role | Can | Cannot |
| --- | --- | --- |
| Administrator (Rashid) | everything | — |
| Yawmiyati operator (`ym_operator`) | ops board, orders (view, edit, release/cancel holds), record outcomes, moderate reviews, reports | settings, plugins, themes, users, payment methods, products |
| Customer | own account | admin |

Each person has their own login; no shared passwords. Secrets (CAPI tokens, Turnstile secret, alert webhook,
database password) live in the host's environment and the password manager, never in git, options or chat.

## Notifications and logs
Alerts go by email and to `YM_ALERT_WEBHOOK` (e.g. a Slack or Telegram relay), at most once an hour per alert.
Uptime monitors on `/wp-json/ym/v1/health` per store. Logs (WooCommerce > Status > Logs, source "yawmiyati") are kept
90 days, then deleted automatically; they never contain names, phones or addresses (tested).

## Backups and restore
`deploy/backup.sh <store> <dir>`: database + uploads with checksums, 14 daily copies kept; one weekly copy goes off the
host. SiteGround's own daily backups are a second layer, not the only one. `deploy/restore.sh <backup> <store>`
restores after verifying checksums. **Restore drill:** `dev/restore-drill.sh` restores the Egypt store into a scratch
store and compares orders, products, country, money ledger and media (passed 28 Oct 2026, locally). On hosting, run
the same drill monthly on staging.

## Daily and weekly at a glance (one operator)
- Daily, 15-20 min per open country: ops board actions; held orders; unconfirmed hand-offs; outcomes; stock for
  advertised offers; ad spend; review links. If a publish alert or `consent_certify_blocked` alert arrived: look first.
- Weekly, 45-60 min: gates and reco report; approve the next content batch; read the alerts log; restore drill monthly.
- Emergency switches: studio `node studio/publish.mjs --pause`; ads gate off by commit; Meta ads paused in Ads
  Manager (Claude may pause and lower, never raise).

## Releases and rollback
One repository, one release for every country store. A release is a git tag (`release-YYYYMMDD-N`) that passed CI.
Deploy: pull the tag on staging, run `wp ym launch-check` and a test order per open country, then pull the same tag
on production stores. Rollback: put the store in maintenance, check out the previous tag, `wp cache flush`, check
`wp ym ops` and the health endpoint. The plugin keeps data in options and order meta only (no table migrations), so
an older release reads newer data safely. Tables added on 29 Oct (`{prefix}ym_consent_pending`; in the shared
database `ym_consent_ledger`, `ym_consent_cert`, `ym_contacts`, `ym_mail_log`, `ym_mail_events`) are created with
CREATE TABLE IF NOT EXISTS and ignored by older releases. If data itself is bad, restore the last backup (export newer orders first).

## Email operations (when flows are on; docs/EMAIL.md)
- `wp ym mail unknown`: sends whose outcome is unknown (timeout or provider error after sending). Check the Brevo log,
  then `wp ym mail resolve <id> accepted|failed --note="what you saw"`. Never resend an unknown by hand.
- `wp ym mail unblock <email> --note="…"`: lift a bounce or complaint after checking; she must opt in again.
- `wp ym mail newsletter <issue> --subject=… --body-file=… [--dry-run]`: the weekly newsletter for this country,
  through the one-a-week cap. Never send campaigns from Brevo's campaign screen.
- `wp ym hero <sku>` / `--clear`: the home page's featured product (a person's choice; conditional items are refused).
