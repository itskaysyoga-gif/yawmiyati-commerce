> Superseded by `launch_assortment_v2.md` (28 Oct 2026). Kept for history.

# Launch assortment v1 — 28 Oct 2026

Source data: `data/taager_catalog_export.csv` (pre-28 Oct, re-verify before publishing), `data/competitors_egy.csv`, `data/competitors_irq.csv` (opened 28 Oct). Full table with reasons: `data/launch_assortment_v1.csv`. Economics: `scripts/economics.py`.

## Decisions
Egypt launch: absorbent mats (shared hero), double clothes rack (shared), 180° heater (shared, winter), wearable blanket (winter). Test: clothes-care bundle (steam iron + wrinkle spray).
Iraq launch: sink rack (hero), absorbent mats (shared hero), 180° heater (shared, winter), corner clothes organizer. Test: magnetic window cleaner, double clothes rack, clothes-care bundle.
Rejected (Taager cost at or above market): Egypt foldable mop, squeeze mop, "spray mop" (actually a glass squeegee), lint remover, dish drying mat, kitchen towel roll, spice rack (margin); Iraq portable dryer, lint remover.
Held: Egypt portable dryer (possible 110 V), shoe cabinet (1 comparable); Iraq spray mop (identity), squeeze-mop bundle, bathroom shelf, shoe cabinet.

## What the numbers say
Break-even cost per submitted order at 45% delivery: Egypt 89-114 EGP, Iraq 3,600-5,850 IQD. At 30% delivery: Egypt 58-74 EGP, Iraq 2,400-3,900 IQD.
Single items carry thin profit, so paid traffic goes to 2-piece offers and bundles first, and organic carries single items.

## Before any product goes live
- Re-check each Taager price and stock on the day (export predates 28 Oct; Iraq rows have no stock data).
- Verify identity, size and contents from Taager media (media.taager.com access pending).
- Confirm the Iraq refusal fee (modelled as 0 here, so Iraq figures are optimistic).

## Image check (28 Oct, Taager thumbnails)
- The Egypt and Iraq "180° heaters" are different products: Egypt is a fireplace-style 2-in-1 heater with a remote; Iraq is a round rotating fan heater. They are not twins; each gets its own content.
- The double clothes racks differ in design (Egypt on wheels with a shoe shelf; Iraq a bar rack with a lower shelf); one reel can show the category but not one exact product.
- The absorbent mat sets look alike in both countries; they remain the shared organic hero.
- Taager thumbnails are ad graphics with Arabic text; the store needs clean product images from the full gallery (plugin import) plus cut-outs.
