# yawmiyati-commerce

Arabic, mobile-first cash-on-delivery stores for Yawmiyati (يومياتي), supplied by Taager.
One WordPress + WooCommerce install per country (`/eg/`, `/iq/` at launch), all running the
theme and plugin in this repo. Start with `docs/PROJECT-STATE.md`; the launch brief lives in the Yawmiyati Claude Project.

| Folder | What lives there | Status |
| --- | --- | --- |
| `theme/yawmiyati` | Block theme: header, product page, checkout and order-received styling (design direction A) | implemented |
| `plugin/yawmiyati-commerce` | Country rules, delivery fees, offer groups, COD checkout rules, duplicate guard, supplier watchdog, WP-CLI setup and seed | implemented, tested locally |
| `tests/` | Playwright order-flow tests (`e2e/`) and in-store checks (`integration/`) | passing locally |
| `dev/` | Local stores: pinned WordPress, WooCommerce, WP-CLI (checksummed), MariaDB 10.11 | working |
| `tools/` | `export-preview.js`: static snapshot of the local stores | working |
| `research/` | Catalog export, competitor benchmarks, launch assortment v2, economics | priced, not supplier-verified |
| `design/` | Copies of the design canvas prototypes | reference |
| `media/interim/` | Text-free crops of Taager product images used until final photos exist | interim |
| `brand/` | Logo files | final |
| `docs/` | Project state, decisions, rules and checklists | current |

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
npm test                    # 13 end-to-end tests against the running stores
npm run test:integration    # phone rules, fees, currency, COD only, SKU/cost/Taager ID per product, dialect copy
```

What the tests cover: Egypt and Iraq orders end to end with server-side prices and delivery fees, offer tampering,
injected price fields, out-of-country governorate, concurrent duplicate submission, double click, duplicate review
(on-hold), supplier hand-off failure and manual fallback, phone formats, and the checkout form itself.
They run against local stores only. Nothing here talks to Taager.

CI: `.github/workflows/test.yml` runs the same steps on GitHub. It has not run yet.

## Portable preview

```bash
dev/serve.sh && npm run preview   # writes preview/ (ignored by git)
cd preview && python3 -m http.server 8090   # or open preview/index.html directly in a browser
```

The preview is a static copy of the real pages with their CSS, fonts, images and scripts. Ordering is disabled in it.
