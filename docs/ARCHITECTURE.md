# Architecture, services and credentials

_29 Oct 2026._

## Map
```
                         ┌────────────────────────────── SiteGround (one account) ───────────────────────────────┐
 customer (phone) ──────►│ yawmiyati.shop/eg  WordPress + WooCommerce + yawmiyati theme + yawmiyati-commerce plugin │
 Instagram / Facebook ──►│ yawmiyati.shop/iq  (same release)        SA / AE / OM: prepared, closed                 │
                         │      │ orders (classic checkout, COD)                                                   │
                         │      ├──► Taager plugin (one Taager store per country) ──► Taager: call, ship, collect   │
                         │      ├──► own DB per store: orders, lifecycle, gates, reviews, rate counters, queue      │
                         │      └──► shared DB (YM_SHARED_DB): consent ledger + store certificates, email          │
                         │           permissions (contacts, mail log, provider events)                            │
                         │  server cron every 5 min → Action Scheduler jobs (certify, sweeps, digests, CAPI)       │
                         └──────┬─────────────────────┬─────────────────────────┬──────────────────────────────────┘
                                │ consented only      │ when flows are on       │ facts endpoint (key)
                                ▼                     ▼                         ▼
                 GTM / GA4 / Meta CAPI / Snap    Brevo (email API,       Content Studio (studio/, cloud: CI or the build
                 (browser: consent card;          webhook back)          container): facts → Anthropic API brief (CI,
                  server: two-phase, ledger)                             capped) → Remotion render → QA → owner batch →
                                                                         publisher → Meta Graph API (Instagram, Page)
 GitHub (repo, CI: stores built from pinned versions, e2e + in-store checks; studio-scripts on studio-run/* branches)
```
Principles: WooCommerce is the source of truth for orders; the supplier name never reaches customer text; nothing is
described as supplier- or provider-verified from local tests; renders never run on the store host.

## Services and credentials (where each secret lives; never in git or chat)
| Service | Used for | Credential | Lives in | Status |
| --- | --- | --- | --- | --- |
| SiteGround | hosting, DB, cron, backups | panel login; DB users per store | Rashid's password manager; wp-config from the panel | not bought |
| WordPress / WooCommerce | stores | personal operator logins (`ym_operator`), admin | password manager | local only |
| Taager | supplier, plugin per store | Taager account; plugin API key per store | Taager plugin settings (not git) | account approved; not linked |
| Shared DB | consent + email permissions | grants for each store's DB user | SiteGround | local only |
| Meta (ads + CAPI) | ads, server events | dataset ID (option), CAPI token `YM_META_CAPI_TOKEN` | environment | not created |
| Snap | ads, server events | pixel ID, `YM_SNAP_CAPI_TOKEN` | environment | not created |
| GTM / GA4 | browser measurement | container / property IDs (options) | WordPress options | not created |
| Meta Graph (publishing) | Instagram Reels, Page videos | long-lived token `YM_META_TOKEN`, `YM_IG_USER_ID`, `YM_FB_PAGE_ID` | CI secrets / studio environment | not created |
| Studio ↔ store | facts endpoint, media upload | `YM_STUDIO_KEY`; upload-only WP user + application password (`YM_WP_USER`, `YM_WP_APP_PASSWORD`) | environment both sides | not created |
| Brevo | email | `YM_BREVO_KEY`, `YM_BREVO_WEBHOOK_KEY`; sending domain DNS (SPF, DKIM, DMARC) | environment; DNS at the registrar | not created |
| Signed email links | confirm / unsubscribe | `YM_MAIL_SECRET` (same on every store) | environment | not created |
| Anthropic API | studio briefs, copy drafts | `ANTHROPIC_API_KEY` | GitHub repository secret | in use (capped) |
| Turnstile | optional bot check | site key (option), `YM_TURNSTILE_SECRET` | environment | optional |
| Alerts | ops alerts | `YM_ALERT_WEBHOOK` | environment | not created |
| GitHub | code, CI | Rashid's account | — | repo still public (O1) |

## Release evidence
docs/PROJECT-STATE.md names the commit and the green CI run; docs/reports/ holds each round's report. Rollback:
docs/OPERATIONS.md "Releases and rollback".
