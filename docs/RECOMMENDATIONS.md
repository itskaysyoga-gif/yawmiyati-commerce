# Recommendations v1

_29 Oct 2026. Code: `YM_Reco`, rules in `plugin/yawmiyati-commerce/config/recommendations.json`. Tests: tests/e2e/reco.test.js._

Deterministic and curated: no live model on page views, no behavioural profile.
- **Rules per country:** SKU → candidates in priority order, each labelled `complement` (used together) or
  `substitute` (answers the same need). `bundles` lists what a bundle SKU physically contains; `exclude_with` lists
  pairs never shown together (size, voltage, duplicate function).
- **Eligible only if** published, purchasable, in stock (on staging/production also the catalog gate, which includes a
  stock check within 48 h), not an offer of the page she is on, not already in her cart (bundles count as their parts,
  both ways), and not cheaper as one of the page's own bundles (the Iraq rack page already sells rack + mats for less
  than the two separately, so the mats are never suggested there).
- **Ranking:** curated priority, then contribution per unit, less any recorded return/defect penalty
  (`_ym_reco_penalty`). At most three; nothing when none qualify.
- **Placement:** product page below the buy panel and delivery terms; cart page under the cart table. Never inside the
  checkout form, never after the order (an order handed to the supplier is never changed).
- **Honest labels:** "goes with it" / "an alternative". No "frequently bought together" until order data shows it.
  Nothing is preselected; adding uses the normal cart, so delivery (one fee per order) and the total are recalculated.
- **Measurement:** GA4 `view_item_list` / `select_item` (consent rules as for every tag); the order line keeps
  `_ym_reco_from`; `wp ym reco-report --days=N` joins added items to verified outcomes. Ranking changes only after
  enough delivered orders show a pattern.

Current rules (seed assortment): Egypt heater ↔ wearable blanket (complements); Iraq corner clothes organizer ↔ double
clothes rack (substitutes). Everything else shows nothing until a genuine pair exists.
