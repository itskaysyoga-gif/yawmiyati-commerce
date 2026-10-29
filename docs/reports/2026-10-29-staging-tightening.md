# Staging tightening and development freeze, 29 Oct 2026 (night)

Scope: ChatGPT's review of the staging preparation, applied narrowly: no new features, no redesign, email dormant,
video deferred, **no API budget spent**. After this round development is frozen; the next work is staging (Egypt,
then Iraq), and it is blocked only by external items (listed at the end).

## Baseline before any change (`047ec68`, untouched checkout)
116 end-to-end (115 pass; the WebKit/iPhone test runs only in CI), 777 in-store checks (EG 164, IQ 165, SA 151,
AE 145, OM 152), 17 studio and ledger checks. CI run 40924869716 on `047ec68`: success (push, main). Exactly the
counts in the handoff.

## The review's five workflow points
| # | Point | Change | Evidence |
| --- | --- | --- | --- |
| 1 | Verify destination URLs and environment; refuse production | Before **any** store changes, every store is proven: base URL `https://staging.<domain>` with no path; document root contains `staging`; store, `wp-login.php` and REST API answer 401 without the password; WordPress's home and site URL exactly `STAGING_BASE_URL/<cc>/` (read with no plugin code loaded, so a broken release cannot block its own fix); environment `staging` or `development`, never `production` or `local`. All stores are checked before the first one changes | deploy/test: 5 refusal cases, each asserting nothing changed and no backup was taken |
| 2 | Approved main commit plus CI | The tagged commit must be on `main` and have a successful `tests` run **on main for that exact commit** (the old check also accepted a pull-request run) | deploy/test: on main + green passes; off main, or no green run, refused |
| 3 | Launch-check blockers vs deployment failures; no blanket `\|\| true` | `wp ym launch-check --format=json` exits 0 once the checks ran and reports `open`; the workflow lists open items as expected. SSH, PHP, WordPress, cache-flush, health (non-JSON, wrong country, 500), missing `noindex` and a launch-check that cannot run all fail the run, after rollback. Health 503 (jobs not yet run) is reported, not hidden and not a failure | integration check (all stores); deploy/test cases for 500, non-JSON health, no noindex, broken launch-check |
| 4 | Backup and rollback for an interrupted deployment | Per store: `deploy/backup.sh` first (now also works before the plugin is active); new plugin and theme copied next to the live ones as dot-directories (WordPress ignores them: checked with `wp plugin list`), swapped by rename under maintenance with signals ignored, journal in `~/ym-deploy/state/<cc>`; WordPress must load; HTTP checks; then keep, else roll back. The next run rolls back anything unverified. Upload is `ssh … cat` with a checksum (no rsync dependency) | deploy/test: mid-swap and unverified interruptions recovered, explicit rollback refused when nothing is unverified; **real WordPress rehearsal** below |
| 5 | Password protection before the development-mode tests, restoration afterwards | Test window by tag `staging-mode-<cc>-development-…`: refuses unless protected and the 5-minute cron job ran in the last 15 minutes; sets the environment, reads it back, re-checks the password (switches straight back if protection fails). Closing tag `staging-mode-<cc>-staging-…`: reads `staging` back, fails unless the catalog gate is enforced and no unverified product is purchasable, lists test orders without an outcome | deploy/test: 4 mode cases; real WordPress rehearsal |

## Real WordPress rehearsal (local, scratch copy of the Egypt store with its own database)
Host script run as on the host: identity read; install with backup (database, uploads, consent ledger) and swap;
`launch-check --format=json` (staging, 24 open); commit (previous copy moved out of the web root). A release with a
PHP parse error: WordPress failed to load, **rolled back automatically**, previous release live, maintenance cleared,
journal `rolled_back`. Cron check refused before the 5-minute job had run and passed after it. Development: gate off
(5 unverified products orderable), an order marked `_ym_test=1`; back to staging: gate enforced, 0 purchasable
unverified products, that order listed as open. Scratch store and database removed afterwards.

## Test plan, cron, Taager
- **Two order classes** (docs/STAGING.md §4). Class C, cancelled before shipping: E1-E7, run inside the open test
  window and recorded as `cancelled --reason=before_handoff`. Class D, delivered: E8 (caller, parcel, invoice), E9
  (shipped/delivered sync, days), E10 (profit in the balance, then the payout test O9); one per country first, **each
  with Rashid's written yes naming offer, price and recipient**. `ym_status_sync_verified` now waits for class D,
  because a cancelled order never shows shipped or delivered.
- **Cron before scheduled operations**: installed and each job run once before any order test (the health endpoint
  stays 503 until every job has run once, which for the weekly report could take until the first Monday); the test
  window refuses without it; the uptime monitor needs the protection's credentials or it only sees 401.
- **One Taager request** (docs/requests/taager-request.md): rounds 3, 4 and 5 merged (none had been sent), duplicates
  removed (caller and parcel name were asked twice), two questions added that the new test plan needs (whether test
  orders affect the account's rates; the delivered test order per country). The old round files are removed.

## Known intermittent (recorded accurately)
"Concurrency: four different customers checking out at the same moment…" (tests/e2e/resilience.test.js) timed out
once in one local full run on 29 Oct evening. It passed three times alone, in that evening's full rerun, in this
round's baseline and final local runs, and in CI runs 40869046117 and 40924869716. **Cause not established; open.**
The earlier report called it "load timing, not a defect"; that was an unproven explanation and is corrected there. If it
recurs: keep the run, note which step timed out, and investigate before calling it environmental.

## Corrections to the review (and why)
1. **No per-release human approval click.** "Approved main commit" is enforced as: on main, green `tests` on main for
   that commit. Requiring Rashid to approve each staging release in GitHub would block every staging fix on someone who
   is not watching, and a staging release is not a money decision (CLAUDE.md). Production keeps Rashid's yes.
2. **Protection is checked on every release, not only before the test window**, and on the login page and REST API
   too: a URL protection that covered only the home page would leave the order endpoint open.
3. **Test-order marking was documented but not implemented.** STAGING.md said every test order gets `_ym_test`; no
   code did it, so test orders would have entered reports and kill-gates. Fixed: every order placed while a store runs
   as `development` is marked (5 integration checks per store). This is our own gap, not the review's.
4. **The payout needs a delivered order.** The review asks for delivered orders to check packaging, delivery and
   payout; it also needs two things nobody had listed: a local phone someone answers for the confirmation calls, and a
   real person at a real address who receives and pays. New owner action O22.
5. **Cron alone does not make the health endpoint green**: each job must have run once. Added as a setup step.
6. **"Proceed with Egypt staging, then Iraq; report actual integration evidence"**: there is none to report. No host
   exists (O4), so nothing has been deployed, no Taager plugin has been linked and no order has reached Taager. Staging
   is prepared and frozen; it starts the moment the external items below are done. Nothing in this repository is
   supplier-verified.
7. The review PDF is stamped with the final commit (the successor of `047ec68`); the storefront is visually unchanged
   in this round (no theme or template change; the plugin changes are a CLI output format and the test-order mark).

## Counts (final tree, local)
116 end-to-end (115 pass, WebKit/iPhone in CI only), **802 in-store checks** (EG 169, IQ 170, SA 156, AE 150, OM 157;
+5 per store: test-order mark in four environments, launch-check JSON), **31 studio, ledger and staging-deploy checks**
(17 + 14 new against a fake host). Final commit and CI run: in the Project handoff.

## External blockers (nothing buildable is left)
O4 domain and hosting → then the GitHub "staging" environment settings (STAGING.md §3) → O1 repo private → O3 assortment
yes → O5 Taager request sent and answered → O7 Taager plugin linked on Egypt staging → O22 test-order phone and one
delivered-order recipient per country. Then E1-E10 on Egypt, I1-I10 on Iraq.
