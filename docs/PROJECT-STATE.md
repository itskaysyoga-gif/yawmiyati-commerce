# Project state

_Last updated: 2026-10-28_

## Done
- Launch brief v2 agreed (Claude Project doc "Yawmiyati Commerce — Launch Brief v2"), including ChatGPT's review.
- Taager confirmed (28 Oct): one account, a separate store per country; WooCommerce orders reach Taager automatically;
  no stock sync; the customer pays delivery.
- Repo created; logo extracted as vector from the Kite Creative business card (`brand/`).

- Local test stores running: WordPress 7.1.3 + WooCommerce 11.2.1, Arabic/RTL, SQLite, Egypt (EGP, :8081) and Iraq (IQD, :8082). `dev/setup.sh`, `dev/serve.sh`, `dev/wp.sh`.
- First candidate shortlist: 15 Egypt + 15 Iraq offers, 10 shared (`research/data/candidates_v1.csv`).

- Competitor pricing done (Egypt 92 offers, Iraq 53 offers) and launch assortment v1 decided: `research/launch_assortment_v1.md`.

## In progress
- Flagship product page design (needs media.taager.com on the allowlist for real product photos).

## Taager data access
- Rashid's network allowlist now includes wordpress.org, downloads.wordpress.org, github.com, raw.githubusercontent.com (works), repo.packagist.org, taager.com. api.wordpress.org is not allowed (language packs are installed from downloads.wordpress.org directly).
- Taager catalog and media come through the Taager plugin's product import once a store is live and linked, plus any official export Taager provides. Taager's private merchant API is not used.

## Waiting on
- Rashid: buy yawmiyati.shop and SiteGround GrowBig (blocks Taager link and test orders).
- ANTHROPIC_API_KEY added as a GitHub Actions secret (28 Oct).
- Taager: delivery fee per order or per item; status sync to WooCommerce; media import and ad rights; Iraq refusal fee;
  payout to a UAE account in the brother's name; official product export; Iraq return window (3 vs 14 days).
- Rashid: buy yawmiyati.shop (available on 28 Oct) and SiteGround GrowBig.
- Hosting before: Taager plugin link and test orders, Meta Pixel, Manychat links.

## Next
1. Shortlist and competitor pricing for Egypt and Iraq.
2. Theme foundations and the flagship product page design.
3. COD form, cart, bundles in the plugin.
