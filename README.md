# yawmiyati-commerce

Arabic, mobile-first cash-on-delivery stores for Yawmiyati (يومياتي), supplied by Taager.
One WordPress + WooCommerce install per country (`/eg/`, `/iq/` at launch), all running the
theme and plugin in this repo. Start with `docs/PROJECT-STATE.md`; the launch brief lives in the Yawmiyati Claude Project.

| Folder | What lives there | Status |
| --- | --- | --- |
| `theme/yawmiyati` | Block theme: header with country switcher, home, product page, checkout, order received, policy pages, consent banner (warm Yawmiyati look) | implemented |
| `plugin/yawmiyati-commerce` | Country rules, delivery fees, offer groups, COD checkout, idempotent submission, duplicate review, supplier tracking and reconciliation, catalog gate, measurement, SEO, scheduled jobs, WP-CLI | implemented, tested locally |
| `plugin/.../copy`, `content` | Customer text per country (Egyptian / Iraqi Arabic) and policy pages | draft, not native-reviewed |
| `tests/` | Playwright end-to-end tests (`e2e/`) and in-store checks (`integration/`) | passing locally and in CI |
| `dev/` | Local stores: pinned WordPress, WooCommerce, WP-CLI (checksummed), MariaDB 10.11 | working |
| `tools/` | `export-preview.js` (static snapshot), `copy/` (Anthropic draft pipeline) | working |
| `deploy/root/` | Country chooser and robots.txt for the domain root | ready for launch |
| `research/` | Catalog export, benchmarks, launch assortment v2, media checklist | not supplier-verified |
| `media/interim/` | Low-resolution crops of Taager images; blocked as final media | interim |
| `docs/` | State, decisions, launch checklist, measurement, SEO, operations, voice guides, language review | current |

## Run the stores locally

Needs PHP 8.3 (mysqli, zip, intl, gd), MariaDB 10.11 (`mariadbd`, `mariadb-install-db`), Node 22 and curl.

```bash
dev/setup.sh      # downloads pinned versions, checks SHA-256, installs both stores, seeds products
dev/serve.sh      # starts MariaDB on 127.0.0.1:3307 and the stores: Egypt http://localhost:8081, Iraq http://localhost:8082
dev/wp.sh eg ...  # WP-CLI against a store, e.g. dev/wp.sh iq wc product list --user=1
dev/reset.sh      # drop both stores and reinstall
```

The local admin password is random and never printed; use WP-CLI instead of the admin screens.

## Tests

```bash
npm install                 # Playwright 1.63.0
npm test                    # 42 end-to-end tests against the running stores (WebKit test skipped if not installed)
npm run test:integration    # phone rules, fees, currency, COD only, SKU/cost/Taager ID, catalog gate, language lint
```

What the tests cover: orders end to end in both countries with server-side prices and delivery fees; tampering;
truly parallel duplicate submissions (8 PHP workers, server-side delay hook); retries after a failed or lost
request; four customers at once; buy now keeping the cart; out-of-stock bundles; order-page privacy; supplier
timeout, reconciliation and conflict; consent, dataLayer, server-event payloads and attribution; SEO output on rendered
pages; Android (Chromium) and iPhone (WebKit, CI) flows. They run against local stores only. Nothing here talks to
Taager, so supplier-side duplicate prevention is not proven.

Before launch: `dev/wp.sh eg ym launch-check` lists everything still open for that store.

CI: `.github/workflows/test.yml` builds both stores from the pinned versions and runs both test suites on every push. First green run: 28 Oct 2026.

## Portable preview

```bash
dev/serve.sh && npm run preview   # writes preview/ (ignored by git)
cd preview && python3 -m http.server 8090   # or open preview/index.html directly in a browser
```

The preview is a static copy of the real pages with their CSS, fonts, images and scripts. Ordering is disabled in it.
