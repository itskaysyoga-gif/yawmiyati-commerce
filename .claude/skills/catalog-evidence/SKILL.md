---
name: catalog-evidence
description: CUSTOM (Yawmiyati). Use before publishing, pricing, advertising or making content for a product - verifies identity, facts, stock, media rights and commercial eligibility against the store's own records.
---
# Catalog evidence (custom skill, Yawmiyati)

A product may be sold, shown in content or advertised only on recorded evidence. Never fill a gap from memory or the
supplier's marketing.

1. `dev/wp.sh <cc> ym gate <sku>` (or `wp ym gate` on staging): every blocker must be cleared. Blockers are evidence
   gaps, not formatting problems.
2. `wp ym studio-facts <sku>`: the only facts content may use. A spec with value null is unknown and must not be
   stated anywhere.
3. Media: `wp ym media <id> --role --sku --source --rights --original [--exact --complete --final --channels --editing
   --expires --approved]`. Supplier integration or a support chat is not written rights. Organic and paid use need
   the owner's approval date on the picture.
4. Stock: confirmed in Taager within 48 h (`wp ym check <sku> --stock`), per offer, per country.
5. Price: at or above Taager's current minimum, with source and capture date (CLAUDE.md).
6. Record what was checked, by whom, when, and the source. "Taager said" is not "verified".
Output: a short table per SKU: identity, specs, media coverage and rights, stock age, price evidence, verdict.
