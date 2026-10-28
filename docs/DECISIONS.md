# Decisions

Newest first. Each entry: date, decision, why.

- 2026-10-28 (evening) — Design refined to a warmer, feminine Yawmiyati look on A's shopping structure: ivory page, white surfaces, deep berry actions, blush and sage used sparingly, light borders. Product images sit in white 4:5 frames and are never cropped by CSS. Supersedes the indigo palette. Awaiting Rashid's yes.
- 2026-10-28 (evening) — Customers only ever see Yawmiyati. The supplier's name never appears in customer text (a test fails if it does). What the confirmation caller, parcel, invoice and returns show is a verification task.
- 2026-10-28 (evening) — A product cannot be published or sold on staging/production until identity, critical specs, media (5 assets, ≥1000 px, source and rights), price and a stock check within 48 h are recorded (catalog gate). Missing critical media or specs block publication; enlarged ad crops never count as final images.
- 2026-10-28 (evening) — `wp ym seed` refuses to run on production.
- 2026-10-28 (evening) — Buy now checks out one offer and keeps the rest of the cart aside, restored after the order or when she leaves checkout.
- 2026-10-28 (evening) — Submission tokens are idempotent: a retry after the order exists is sent to that order; a failed attempt releases its token; an in-flight duplicate waits.
- 2026-10-28 (evening) — A missing Taager reference means "unconfirmed", never "failed". Manual entry in Taager requires a recorded "not found" search less than 2 h old; a later plugin reference on a manual order is flagged as a possible double order. Supplier duplicate prevention is not claimed until tested with the connector.
- 2026-10-28 (evening) — Measurement: GTM is the only browser sender, the plugin the only server sender; one country-prefixed ID per order across GA4, Meta and Snap; consent before anything loads; no personal data in analytics; phone hashed for server events only with consent. Spec: docs/MEASUREMENT.md.
- 2026-10-28 (evening) — SEO without a plugin: only production is indexable; hreflang on home pages only (no identical products across countries); Product JSON-LD without ratings or return policy until verified.
- 2026-10-28 (evening) — Customer text lives in `copy/<cc>.json` and `content/<cc>/`, written to separate Egyptian and Iraqi voice guides; drafts come from the Anthropic pipeline (tools/copy) and need native review. Key completeness is not language validation.
- 2026-10-28 (evening) — Local stores run WP-Cron from the command line only and eight PHP workers per store; production uses a real server cron.
- 2026-10-28 — Design direction A (white paper, indigo actions, Aref Ruqaa diary note) recommended over B. Palette superseded the same evening (see above); the structure stays.
- 2026-10-28 — Checkout is WooCommerce's classic checkout (shortcode), not the Checkout block, so every standard order hook fires for the Taager plugin. Orders are created by WooCommerce only.
- 2026-10-28 — Each offer is its own WooCommerce product with its own Taager SKU and price; offers of one product share one page (offer group). A bundle Taager sells as one SKU stays one product.
- 2026-10-28 — Iraq sink rack: rack alone is the default (50,000 IQD with delivery); rack + mats (65,000) is optional.
- 2026-10-28 — Same phone + same product within 24 h is held on-hold for a check, not blocked. A double submission is refused by a one-time token.
- 2026-10-28 — Every order gets a supplier status. ("failed" replaced by "unconfirmed" the same evening, see above.)
- 2026-10-28 — Unknown specs never ship: they show as a placeholder on local stores and are left out in production; critical unknown specs block publication.
- 2026-10-28 — Customer-facing text per country (moved to copy/<cc>.json the same evening).
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
