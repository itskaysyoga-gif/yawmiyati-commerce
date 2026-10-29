# yawmiyati-commerce

Arabic, mobile-first cash-on-delivery stores for Yawmiyati (يومياتي), supplied by Taager.
One WordPress + WooCommerce install per country (Egypt, Iraq, Saudi Arabia, UAE, Oman; Egypt and Iraq open first),
all running the theme and plugin in this repo from one release. Start with `docs/PROJECT-STATE.md`; the launch brief lives in the Yawmiyati Claude Project.

| Folder | What lives there | Status |
| --- | --- | --- |
| `theme/yawmiyati` | Block theme: header with country switcher, home, product page, checkout, order received, policy pages, consent banner (warm Yawmiyati look) | implemented |
| `plugin/yawmiyati-commerce` | Five-country config and market gates, offer groups, COD checkout, idempotent submission, COD controls and review queue, order lifecycle and report, supplier tracking, catalog and media gate, reviews, measurement, SEO, operations board, scheduled jobs, WP-CLI | implemented, tested locally and in CI |
| `plugin/.../copy`, `content` | Customer text per country (Egyptian, Iraqi, shared Gulf) and policy pages | draft, not native-reviewed |
| `tests/` | Playwright end-to-end tests (`e2e/`) and in-store checks (`integration/`) | passing locally and in CI |
| `dev/` | Local stores: pinned WordPress, WooCommerce, WP-CLI (checksummed), MariaDB 10.11 | working |
| `tools/` | `export-preview.js` (static snapshot, stamped), `review-shots.js` (phone + desktop review PDF from a clean commit), `perf-lab.js`, `copy/` (Anthropic draft pipeline), `spend/` (the one Anthropic API ledger, docs/API-BUDGET.md), `language/` (copy ledger, rendered-page inventory, AI critique, native review packages) | working |
| `studio/` | Content Studio: facts → API briefs (CI, capped) → Remotion renders → QA → batch approval → Meta publisher (docs/CONTENT-STUDIO.md) | locally tested; no account connected |
| `.claude/skills/` | Seven custom project skills (catalog-evidence … growth-review); Remotion's own skills via `studio/skills-sync.sh` | — |
| `deploy/` | Domain-root chooser and robots.txt; `backup.sh`, `restore.sh` | ready for launch |
| `research/` | Catalog export, benchmarks, launch assortment, selection pipeline and five-country shortlists, pricing | not supplier-verified, nothing approved |
| `media/interim/` | Low-resolution crops of Taager images; blocked as final media | interim |
| `docs/` | State, decisions, launch checklist, measurement, SEO, operations, voice guides, language review | current |

## Run the stores locally

Needs PHP 8.3 (mysqli, zip, intl, gd), MariaDB 10.11 (`mariadbd`, `mariadb-install-db`), Node 22 and curl.

```bash
dev/setup.sh      # downloads pinned versions, checks SHA-256, installs both stores, seeds products
dev/serve.sh      # MariaDB on 127.0.0.1:3307; stores EG :8081, IQ :8082, SA :8083, AE :8084, OM :8085
dev/wp.sh eg ...  # WP-CLI against a store, e.g. dev/wp.sh iq wc product list --user=1
dev/reset.sh      # drop all stores and reinstall
dev/restore-drill.sh  # back up the Egypt store, restore into a scratch store, compare
```

The local admin password is random and never printed; use WP-CLI instead of the admin screens.

## Tests

```bash
npm ci                      # Playwright 1.63.0, exact versions from package-lock.json
npm test                    # end-to-end tests against the running stores (WebKit test skipped if not installed)
npm run test:integration    # in-store checks on all five stores
node --test studio/test/*.test.mjs tools/spend/test/*.test.mjs   # Content Studio (fake Graph API) and the API ledger
node tools/perf-lab.js out.json   # lab Core Web Vitals on a throttled phone profile (local numbers are not production)
```

Covered: orders end to end in five countries with server-side prices and fees; tampering; duplicate submissions
(parallel at the same millisecond, a first request alive past 35 s, a crash after saving, lost responses, back
navigation); stock running out mid-checkout; COD controls (honeypot, rate limits, holds, risk records, Turnstile
stub); market gates; the lifecycle report; consent, withdrawal, purchase acknowledgement, late consent, key removal,
verified delivered events, retries; SEO; catalog, search, quantity and totals, switcher, long titles, 200 % text,
sticky bar, reviews; logs and cache safety; the operations board and operator role. Nothing here talks to Taager.

Before launch: `dev/wp.sh eg ym launch-check` lists everything still open for that store.

CI: `.github/workflows/test.yml` builds both stores from the pinned versions and runs both test suites on every push. First green run: 28 Oct 2026.

## Portable preview

```bash
dev/serve.sh && npm run preview   # writes preview/ (ignored by git)
cd preview && python3 -m http.server 8090   # or open preview/index.html directly in a browser
```

The preview is a static copy of the real pages with their CSS, fonts, images and scripts. Ordering is disabled in it.
