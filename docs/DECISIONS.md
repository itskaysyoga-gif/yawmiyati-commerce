# Decisions

Newest first. Each entry: date, decision, why.

- 2026-10-28 — Design direction A (white paper, indigo actions, Aref Ruqaa diary note) is the recommended direction and is what the build implements. Direction B (burgundy boutique) is kept on the canvas. Awaiting Rashid's yes.
- 2026-10-28 — Checkout is WooCommerce's classic checkout (shortcode), not the Checkout block, so every standard order hook fires for the Taager plugin. Orders are created by WooCommerce only.
- 2026-10-28 — Each offer is its own WooCommerce product with its own Taager SKU and price; offers of one product share one page (offer group). A bundle Taager sells as one SKU stays one product.
- 2026-10-28 — Iraq sink rack: rack alone is the default (50,000 IQD with delivery); rack + mats (65,000) is optional.
- 2026-10-28 — Same phone + same product within 24 h is held on-hold for a check, not blocked. A double submission is refused by a one-time token.
- 2026-10-28 — Every order gets a supplier status (pending, sent, failed, manual). If no Taager reference appears within 15 minutes the order is flagged; a manual Taager ID can be entered once and never over an existing reference.
- 2026-10-28 — Unknown specs never ship: they show as [من تاجر] on local stores and are left out in production.
- 2026-10-28 — Customer-facing text lives per country in `YM_Country::RULES[..]['copy']`: Egyptian Arabic for Egypt, Iraqi Arabic for Iraq. A test fails if a key exists in one country only.
- 2026-10-28 — Local stores run on MariaDB 10.11 with pinned, checksummed WordPress 7.1.3, WooCommerce 11.2.1 and WP-CLI 2.12.0 (replaces the earlier SQLite setup).
- 2026-10-28 — Launch assortment v2 replaces v1. Benchmarks separate identical from similar items; an unknown competitor delivery fee is never counted as zero. No launch product is verified identical across Egypt and Iraq.
- 2026-10-28 — Cart and bundles are the main profit lever: Taager charges one delivery fee per order.
- 2026-10-28 — Design reviews happen on published preview pages (Claude artifacts) until a staging site exists; Rashid cannot reach the local dev stores.

- 2026-10-28 — Separate WordPress install per country, not Multisite. SiteGround staging does not support Multisite; Taager links one store per country.
- 2026-10-28 — No free-delivery offers. Taager: the customer always pays delivery. Bundles show savings in money instead.
- 2026-10-28 — Cart plus one-screen express COD form on every country site; quantity breaks, bundles, one order bump, cart suggestions. No post-purchase upsell (Taager calls within 24 h).
- 2026-10-28 — Website AI assistant dropped (Rashid's call).
- 2026-10-28 — One master video per product, country-neutral, no prices; country cuts only for paid ads.
- 2026-10-28 — Meta ads run through Meta's official Ads MCP server in approval-required mode, with an account spending limit.
- 2026-10-28 — Product research is a Claude-run pipeline in `research/`, not a WordPress admin screen.
- 2026-10-28 — Organic content prefers products sold in both Egypt and Iraq; strong single-country products allowed, labelled.
- 2026-10-28 — Hosting SiteGround GrowBig; WordPress + WooCommerce; Taager main supplier, Zambeel later.
