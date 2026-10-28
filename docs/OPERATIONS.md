# Operations

Scheduled jobs run inside each store (Action Scheduler, `plugin/.../class-ym-ops.php`). They log to WooCommerce >
Status > Logs (source `yawmiyati`), email the admin, and post to `YM_ALERT_WEBHOOK` when that is set. The same alert is
sent at most once an hour. Run any job by hand: `wp ym ops-run <job>`.

| Job | When (store time) | What it does | Alerts when | Depends on Taager |
| --- | --- | --- | --- | --- |
| supplier_sweep | hourly at :07 | Re-checks orders from the last 3 days whose hand-off is pending or unconfirmed | an order turns unconfirmed or conflict | yes: needs the verified reference key |
| (per order) supplier check | 15, 60, 180 min after the order | Looks for the Taager reference; missing = unconfirmed, never "failed" | unconfirmed, conflict | yes |
| stock_guard | daily 06:00 | Lists offers whose stock check is older than 48 h (they stop selling on staging/production) or expires within 12 h | any offer expired or expiring | yes: stock is checked by hand in Taager |
| daily_digest | daily 08:30 | Yesterday's orders by status, duplicate holds, unconfirmed / not found / conflict hand-offs, orders stuck over 72 h | anything needs attention | statuses: yes |
| weekly_report | Monday 09:00 | Submitted-to-delivered funnel and contribution per product (docs/MEASUREMENT.md §8) | — (emailed) | delivered/refused stages: yes |
| capi_send (per order) | queued after the order | Meta and Snap server events, 3 attempts | third failure | no |

Supplier-dependent checks stay marked **pending** until the matching verification task closes (docs/PROJECT-STATE.md).
One test order does not close them all: each country, the payout route, returns and stock are verified separately.

## Hosting setup (SiteGround, at launch)
1. Turn off page-load cron: `define( 'DISABLE_WP_CRON', true );` in both stores' wp-config.php (already so locally).
2. Site Tools > Devs > Cron Jobs, one per store, every 5 minutes:
   `cd ~/www/yawmiyati.shop/public_html/eg && php wp-cron.php` (and `/iq`).
3. Environment variables in wp-config.php: `YM_ALERT_WEBHOOK`, `YM_META_CAPI_TOKEN`, `YM_SNAP_CAPI_TOKEN`
   (read with getenv or set in SiteGround's PHP variables; never committed).
4. Uptime monitor (UptimeRobot, free tier): one HTTP monitor per store on `/wp-json/ym/v1/health`. It returns 503 when
   any job is late (more than twice its interval), so a stopped queue alerts like a site outage. The endpoint shows
   only job names and minutes since last run.
5. `wp ym launch-check` on each store must exit 0 before ads.

## Daily routine (Rashid, 15 minutes)
- Morning: open the daily digest. For each unconfirmed order, search Taager (phone, name, date) and record the result
  in the order's "تاجر" box: found (enter its ID) or not found. Manual entry is possible only after "not found",
  within 2 hours.
- Stock: confirm each active offer in Taager, then `wp ym check <sku> --stock` (or the admin action once added).
  Unconfirmed stock stops the offer selling after 48 hours.
- Duplicate holds: call or message, then move the order to processing or cancel it.
- Evening: ad spend per platform into the spend log (not automated yet), refused orders noted.

## Weekly (Monday)
Weekly report email → keep / improve / stop / next test per product. Claude can draft this review once a Claude
scheduled task is set up against the live store (not created yet: there is no live store).
