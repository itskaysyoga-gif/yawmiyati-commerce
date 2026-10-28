# Launch assortment v2 — 28 Oct 2026 (supersedes v1)

Status: **priced from research, not supplier-verified.** Every row in `data/launch_assortment_v2.csv` carries its exact Taager product ID and SKU from the catalog export (captured before 28 Oct 2026). Price, stock, size and contents must be re-checked in Taager before an offer goes live.

Implemented: the same SKUs, Taager IDs, costs and prices are seeded into the local stores from `plugin/yawmiyati-commerce/seed/eg.json` and `iq.json`; the integration checks confirm every product has a SKU, a Taager ID and a price above cost.

Benchmarks: `data/benchmarks_egy_v2.csv`, `data/benchmarks_irq_v2.csv`, built by `scripts/benchmarks.py`.
- Identical items (same product) and similar items are counted separately; bundles, different sets and components are excluded.
- A competitor's delivery fee counts only when the page states it. "Not shown", "varies" and "likely free" are unknown, never zero.
- Offers marked out of stock are excluded.

## Launch offers

| Country | Offer | Taager SKU | Our price | Customer pays | Profit per delivery | Cross-country |
| --- | --- | --- | --- | --- | --- | --- |
| Egypt | Absorbent mats, 1 set (2 pieces) | EG030106MKKT99 | 399 | 494-504 | 219 | similar item in Iraq, different print |
| Egypt | Absorbent mats, 2 sets (4 pieces) | EG030108BUND99 | 749 | 844-854 | 399 | — |
| Egypt | Double clothes rack | EG030106STPG99 | 649 | 744-754 | 204 | similar item in Iraq, different design |
| Egypt | 2-in-1 heater with remote | EG030301HAEE99 | 799 | 894-904 | 234 | Egypt only |
| Egypt | Wearable blanket | EG030401GB99 | 629 | 724-734 | 199 | Egypt only (lowered from 649) |
| Iraq | Sink rack (default) | IQ030403SENK99 | 43,000 | 50,000 | 13,000 | Iraq only |
| Iraq | Sink rack + mats bundle (optional) | IQ030501SDMN99 | 58,000 | 65,000 | 22,000 | — |
| Iraq | Absorbent mats, 2 pieces | IQ030106MATT99 | 18,000 | 25,000 | 8,000 | similar item in Egypt, different print |
| Iraq | Round 180° heater | IQ030301SOBA99 | 32,000 | 39,000 | 9,000 | Iraq only |
| Iraq | Corner clothes organizer | IQ030106MONZ99 | 28,000 | 35,000 | 9,000 | Iraq only |
| Iraq (test) | Magnetic window cleaner | IQ030107MASA99 | 18,000 | 25,000 | 8,000 | Iraq only |
| Iraq (test) | Double clothes rack | IQ030104STAN99 | 30,000 | 37,000 | 10,000 | similar item in Egypt, different design |

No launch product is verified identical across Egypt and Iraq. The mats, racks and heaters share a category, not an item.
Organic posts therefore show one country's item; the DM offers the other country's similar item with its own photo.

Clothes-care bundles (steam iron + wrinkle spray) moved to "later" to keep the first flow focused.

## Weakest evidence
- Iraq mats: one identical offer (22,500), delivery fee not shown, so our position against the market is unknown.
- Iraq double rack and heater: one identical offer each.
- Egypt mats: one identical offer (580); the rest are similar sets.
