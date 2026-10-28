# Product selection

Record: `research/selection/offers.csv`, one row per **country offer** (the same product in two countries is two
rows). Fields: `research/selection/offers.schema.json`. Built by `research/scripts/select.py`. Prices and
contribution: `research/selection/pricing.csv` and docs/PRICING.md.
Audit files: `pool.csv` (every in-scope offer after dedupe, scored) and `excluded.csv` (every catalog row that was
filtered out, with the rule that removed it).

Run: `python3 research/scripts/select.py && python3 research/scripts/economics.py`

Data captured on 2026-10-28 from `taager_catalog_export.csv`. The export's own date is unknown (before 28 Oct 2026;
latest `created_at` 2026-09-15). **Nothing here is supplier-verified, and nothing is approved.**

## Catalog columns (verified)
| Column | Meaning | Evidence |
| --- | --- | --- |
| `original_price` | Taager's price to the merchant (cost) | Equals `taager_cost` for all 27 SKUs in launch_assortment_v2.csv |
| `selling_price` | Taager's minimum selling price | Equals `taager_min` for all 27; the workbook's "Taager min price" column matches too |
| `profit` | Minimum profit = `selling_price − original_price` | Holds on 5,249 of 5,249 rows |

Two catches. Iraq reports **no stock at all**: `stock_range` is empty on all 579 rows. Bundles have no stock anywhere.

## Stages
| Stage | Meaning | Who moves it | Gate to enter |
| --- | --- | --- | --- |
| candidate | Passed the catalog filters and was scored | Claude (`select.py`) | Rules below; no evidence beyond the catalog |
| researched | Item-level evidence attached | Claude | At least one identical or similar competitor offer with URL and capture date (benchmarks_*_v2.csv). Similar-archetype ranges alone do **not** count. Price set from that evidence, not from the P1 floor |
| approved | Owner decided to sell it | **Rashid only** | Rashid's yes, recorded with a date. Claude never sets this |
| published | Live on the country store | Claude, after approval | Store catalog gate (DECISIONS 28 Oct): exact identity and contents, critical specs (dimensions, materials; wattage, voltage and safety for electricals; glass thickness for magnetic cleaners), media with source and rights, price ≥ Taager minimum, stock checked **< 48 h** ago. Plus no open `caution` item |
| promoted | Paid ads running | Rashid's yes (money decision) | Published; break-even CPA known at the 30 % scenario (so not in SA/AE/OM until their refusal charge is known) |
| paused / retired | Stopped / dropped | Claude may pause (out of stock, ads) and reports the same day; retire needs Rashid | — |

Existing decisions from launch_assortment_v2 sit in `existing_status` (launch, test, hold, reject, optional add-on,
quantity option). Those rows are `researched` because they have item-level competitor evidence. Rejected rows stay
in the file, unranked, so they are not rediscovered.

## Filters (select.py, in this order; first match wins)
1. **Taager SKU segment 01/02** (phones, gadgets, clothing). The prefix was inferred from the export, not documented.
2. **Title rules**, each a named regex list in the script: warranty SKUs; medical/health devices or claims (massage,
   pain, joints, posture, braces, scales, dental); cosmetics/skin/hair/fragrance/body care; supplements;
   weapons and weapon-like toys; adult; baby/kids; electronics; car; tools/DIY; fitness/outdoor/camping; fashion;
   religious, gift and Ramadan items; pets; large appliances and furniture.
3. **No home sub-category** matched.
4. `stock_range = not_available`.
5. **Mixed bundles** (different products in one SKU). Multi-unit SKUs of one product are kept and attached to the single
   (structure c).
6. Profit 0 (minimum = cost).

The 27 rows of launch_assortment_v2 are kept whatever the filters say.

**Flagged, not dropped (`fit_install_safety_flag = caution`):** electrical heating (heaters, irons, steamers,
kettles, fryers, grills, cookers, dryers), titles stating ≥ 1000 W, electricals used with water, gas, magnetic window
cleaners (glass thickness), anything needing installation or fit (showers, taps, filters, door hinges, bathroom
corners), size-fit textiles (sofa and bed covers, sheets), chemicals (sprays, pastes, drain powder, foams), and
third-party brands in the title (DSP, Denx, Silver Crest, RAF, Tefal, DLC, Stanley, Pink Stuff and others: held until
authenticity is verified). Efficacy claims (repellents, dust barriers) go to `quality_concerns`.

**Dedupe:** rows are grouped by Taager product_id and by normalised title (colour words, "الترند/الحديث/CONS"
suffixes and catalog codes removed). Near-identical titles in the same country and category are then merged when
token overlap is ≥ 0.7. The kept SKU is chosen in this order: launch-set row first, then best stock, then single over
bundle, then lowest cost. The others are listed in `variants` as alternates. Their identity is not verified (e.g.
EG030108MMTY99 is an alternate of the launch mats, cost 241 vs 180).

## Scores (0-5 each; every one is a rule in select.py)
| Score | Weight | Rule |
| --- | --- | --- |
| expected_contribution | 30 | Margin per delivered order at the proposed price ÷ highest delivery fee: ≥3 → 5, ≥2 → 4, ≥1.5 → 3, ≥1 → 2, ≥0.5 → 1. The refusal term is the same for every offer in a country, so this ranks the same way as break-even CPA |
| audience_fit | 20 | Category: kitchen tools, storage, cleaning, laundry, bathroom 5; textiles, kitchen appliances 4; home comfort 3; decor 2. −1 for summer items in EG/IQ (off-season Oct-Mar). −1 when the customer total is above the high-scrutiny line (EG 800, IQ 60,000, SA/AE 300, OM 35; workbook Phase 0 research) |
| demonstration_quality | 15 | Category default (3-4), raised to 5 when the title names a visible mechanism (absorbent, magnetic, rotating, foldable, wringing, slicer, peeler, organiser, rack, steam, stain remover), lowered to 2 for scent and decor items |
| operational_risk (inverted) | 15 | +2 electrical heating, high wattage, wet-electric, gas, third-party brand; +1 glass thickness, install/fit, size fit, chemical, efficacy claim, fragile, bulky, above high-scrutiny, stock not reported; capped at 5 |
| availability | 10 | detailed_stock: >1000 → 5; 200-1000 → 4; 100-200 → 3; 50-100 → 2; <50 or not reported → 1 |
| differentiation | 10 | 3 base; −1 commodity textile/tableware; +1 multi-function/mechanism; ±1 when identical competitors with known delivery are above/below our total; −1 when our product price is above the similar-archetype high |

`score_total` = weighted sum ÷ 5 (0-100). **Shortlist** = existing launch-set rows plus the highest-scoring new
rows, with score ≥ 60 (EG, IQ) or ≥ 65 (SA, AE, OM: refusal charge unknown, small audience), up to 40 non-rejected
rows per country. **Proposed price** for new rows uses rule P1, the lowest charm price ≥ max(Taager minimum, cost +
highest delivery fee). P1 is a floor, not a market price. It is replaced by an evidence-based price at `researched`.

**Rule R:** a new row whose first title word matches an existing research reject in the same country inherits that
reject's evidence as an uncertainty and cannot be a first-wave pick. Examples: EG mops vs the rejected squeeze/fold
mops; the EG towel roll with stand vs the rejected roll.

**Rule W (first-wave picks):** new rows only, no caution flag, customer total under the high-scrutiny line,
availability ≥ 3 (Iraq exempt: no stock data), Taager home segment 03xx, not rule R, at most 2 per category,
5 per country.

## Counts
| Country | Catalog rows | Excluded | In scope (deduped) | Shortlist (ranked) | of which existing launch set | caution |
| --- | --- | --- | --- | --- | --- | --- |
| Egypt | 849 | 644 | 164 | 40 (+7 existing rejects) | 7 + 7 rejects | 7 |
| Iraq | 579 | 435 | 124 | 40 (+2 existing rejects) | 11 + 2 rejects | 11 |
| Saudi Arabia | 3,385 | 2,902 | 424 | 40 | 0 | 13 |
| UAE | 238 | 198 | 34 | 15 | 0 | 3 |
| Oman | 198 | 139 | 58 | 22 | 0 | 4 |

Stages: every new row is `candidate`. The 27 launch-set rows are `researched` (EG 14, IQ 13). Approved, published,
promoted: **0**. Order evidence (confirmation, delivery, return rates): **none**. There are no orders, so the fields are
empty.

## Top 10 per country
Price = product price set in Taager; customer pays = price + delivery. New-row prices are P1 floors.

### Egypt (EGP)
| # | SKU | Offer | Price | Customer pays | Status | Why | Main uncertainty |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | EG030106MKKT99 | Absorbent mats, 2 pcs | 399 | 494-504 | launch | Above-1000 stock; 1 identical offer at 580 | Iraq/Egypt items differ; specs |
| 2 | EG050501PLAT99 | 360° turntable tray | 459 | 554-564 | candidate | Organiser, strong demo, margin 252 | Segment 05 (kids/lifestyle): confirm identity; no competitor |
| 3 | 050401MT0099 | Foldable cutlery set 6-in-1 | 569 | 664-674 | candidate | Kitchen tool, margin 256 | Legacy SKU; <50 stock; segment 05 |
| 4 | 030402DS0099 | Rotating spice dispenser | 639 | 734-744 | candidate | Kitchen organiser, demo | Legacy SKU; <50 stock |
| 5 | EG030104LA99 | 5-drawer organiser | 1,979 | 2,074-2,084 | candidate | High margin (399) | Far above 800 scrutiny line |
| 6 | EG030404CUTT99 | Quick slicer | 479 | 574-584 | candidate | Demo; 200-400 stock; below similar median | No identical competitor |
| 7 | 050601BE0007 | 2-tier personal organiser | 429 | 524-534 | candidate | Organiser, margin 254 | Segment 05, legacy SKU, <50 stock |
| 8 | EG030402SUG99 | Multi-use filling machine | 469 | 564-574 | candidate | Kitchen demo, margin 244 | What it is exactly; no competitor |
| 9 | EG030401GB99 | Wearable blanket (grey) | 629 | 724-734 | launch | 3 identical, median 739 | Winter only; 3 colour alternates |
| 10 | EG030401HADS99C33 | 3-in-1 slicer | 509 | 604-614 | candidate | Kitchen tool demo | 50-100 stock; no competitor |

### Iraq (IQD)
| # | SKU | Offer | Price | Customer pays | Status | Why | Main uncertainty |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | IQ030501SDMN99 | Sink rack + mats (Taager bundle) | 58,000 | 65,000 | launch (add-on) | One SKU, one delivery fee | Stock not reported |
| 2 | IQ030403SENK99 | Sink rack | 43,000 | 50,000 | launch | 2 identical, median 60,650 | Stock not reported |
| 3 | IQ030303MBBS99 | Spray mop | 19,000 | 26,000 | hold | 1 identical | Item identity unverified |
| 4 | IQ030106MONZ99 | Corner clothes organiser | 28,000 | 35,000 | launch | 3 identical, median 36,999 | Stock not reported |
| 5 | IQ030303FRSH99 | Rotating cleaning brush | 14,000 | 21,000 | candidate | Demo; below similar-archetype median | No identical competitor; stock |
| 6 | IQ030107LAMT99 | Broom + dustpan 2-in-1 | 38,000 | 45,000 | candidate | Cleaning demo | Margin only 7,000 at floor |
| 7 | IQ030107MASS99 | Foldable mop | 17,000 | 24,000 | candidate | Cleaning demo | Egypt's foldable mop was rejected on price |
| 8 | IQ030401MAND99 | Mandoline slicer 6-in-1 | 20,000 | 27,000 | candidate | Kitchen demo; below similar median | Blade safety copy; stock |
| 9 | IQ030303FRRS99 | Rotating wall/floor brush | 30,000 | 37,000 | candidate | Demo; ×2 Taager SKU exists | Electric? Specs unknown |
| 10 | IQ030104KAZA99 | Shoe cabinet 3-in-1 | 25,000 | 32,000 | hold | Identical 35,999 | Material unverified |

### Saudi Arabia (SAR)
| # | SKU | Offer | Price | Customer pays | Status | Why | Main uncertainty |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | SA030104KADNEW699 | 3-tier folding drying rack on wheels | 169 | 197 | candidate | Laundry, 400-600 stock, margin 57.65 after VAT | Refusal charge unknown; 2 alternates |
| 2 | SA030101FC0099 | High-pressure steam cleaner | 199 | 227 | candidate | Strong demo | **caution**: electrical heating specs |
| 3 | SA030109EIF799 | Self-adhesive magnetic wall holder | 109 | 137 | candidate | >1000 stock; ×5 Taager SKU | Adhesive claim unverified |
| 4 | SA030104CSNEW0099 | 4-tier corner organiser | 99 | 127 | candidate | >1000 stock | Margin 30 SAR; dims |
| 5 | SA030302MOBARD0099 | Cooler/heater/dryer 3-in-1 | 189 | 217 | candidate | >1000 stock | **caution**: heating appliance |
| 6 | SA030303REW099 | Cordless electric mop "Denx" | 259 | 287 | candidate | Demo, margin 63 | **caution**: third-party brand |
| 7 | SA030101AD0099 | Foldable travel iron | 109 | 137 | candidate | 800-1000 stock | **caution**: heating appliance |
| 8 | SA030403DIS5N1 | Rotating grain/snack dispenser | 149 | 177 | candidate | 400-600 stock | No benchmark |
| 9 | SA0301FV1E0099 | Self-wringing spin mop | 89 | 117 | candidate | Demo | Margin 33 SAR |
| 10 | SA0304010099 | Slicer + peeler | 129 | 157 | candidate | 200-400 stock | No competitor |

### UAE (AED)
| # | SKU | Offer | Price | Customer pays | Status | Why | Main uncertainty |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | UA030107HPNW99 | Smart vacuum 5-in-1 (large) | 79 | 98 | candidate | 200-400 stock; below similar median | Refusal charge unknown |
| 2 | UA030101LAMAN99 | Cordless broom + dustpan 3-in-1 | 79 | 98 | candidate | Demo | 50-100 stock |
| 3 | UA030107TW0099 | Rotating mop | 39 | 58 | candidate | Demo | <50 stock |
| 4 | UA030101MC0099 | Mandoline slicer 4-in-1 | 69 | 88 | candidate | Demo; ×2 SKU | Margin 20 AED |
| 5 | UA030107LQ0099 | Stain/stone cleaner | 59 | 78 | candidate | Demo | Chemical label unknown; <50 stock |
| 6 | UA030101HBCL99 | Clothes storage bag | 59 | 78 | candidate | Storage | <50 stock |
| 7 | UA030101CMLR99 | Lint remover | 59 | 78 | candidate | Demo | <50 stock; electric? |
| 8 | UA030107HP0099 | Smart vacuum (tight spaces) | 69 | 88 | candidate | Cleaning | Near-duplicate of #1 |
| 9 | UA030404XF0099 | "German original" blender + grinder | 99 | 118 | candidate | Margin 29 | "Original" claim unverified |
| 10 | UA030401UKMA99 | 3-speed chopper | 69 | 88 | candidate | Demo | 50-100 stock |

### Oman (OMR)
| # | SKU | Offer | Price | Customer pays | Status | Why | Main uncertainty |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | OM030101LAMA99 | Cordless broom + dustpan 3-in-1 | 9.000 | 12.000 | candidate | Demo | <50 stock; margin 3 OMR |
| 2 | OM030107FOAM99 | Foam cleaner | 7.000 | 10.000 | candidate | Demo; 100-200 stock | **caution**: chemical |
| 3 | OM0304010099 | Potato/garlic peeler | 9.000 | 12.000 | candidate | Demo | <50 stock |
| 4 | OM030402LR0099BH | Instant potato cutter | 16.000 | 19.000 | candidate | Demo | <50 stock |
| 5 | OM030401ZCU199 | Manual potato cutter | 9.500 | 12.500 | candidate | Demo | Near-duplicate of #4 |
| 6 | OM030401PTP999 | Veg wash/dry bowl | 9.000 | 12.000 | candidate | Kitchen | <50 stock |
| 7 | OM030103RIM999 | 2-tier dish drainer | 12.500 | 15.500 | candidate | Kitchen organiser | <50 stock |
| 8 | OM030401DCK899 | 2-tier metal/wood dish rack | 11.500 | 14.500 | candidate | Organiser | <50 stock |
| 9 | OM030301DRAF99 | Handheld steam cleaner | 14.000 | 17.000 | candidate | Demo | **caution**: heating |
| 10 | OM030401EL0099 | Smart mandoline | 8.000 | 11.000 | candidate | Demo | <50 stock |

## Decision: first wave beyond the launch set
These are recommendations to research next, not approvals. Each needs an identical or similar competitor check with
a URL, a live Taager stock and price check, and supplier specs before it can go to Rashid.

- **Egypt:** EG030404CUTT99 quick slicer · EG030402SUG99 filling machine · EG030301JASR99 electric peeler (50 W) ·
  EG030405JNB99 silicone squeegee · EG030105LEVS99 juice dispenser + 4 glasses. The higher-scoring EG050501PLAT99,
  050401MT0099 and 050601BE0007 sit in Taager segment 05 or use legacy SKUs. Confirm what they are before using them.
- **Iraq:** IQ030303FRSH99 rotating brush · IQ030401MAND99 mandoline · IQ030401NMTS99 vegetable slicer ·
  IQ030107LAMT99 broom + dustpan · IQ030104DOLB99 folding 3-tier cabinet. Iraq's export has no stock data at all.
  Each pick needs an account-manager stock answer first.
- **Saudi Arabia:** SA030104KADNEW699 drying rack · SA030109EIF799 magnetic wall holder · SA030104CSNEW0099 corner
  organiser · SA030403DIS5N1 grain dispenser · SA0301FV1E0099 spin mop. **Do not launch anything** until the Saudi
  refusal/return charge is known: break-even cannot be computed.
- **UAE:** one pick, UA030107HPNW99. The rest of the UAE catalog is thin (15 rows ≥ 65, mostly <50 units, margins
  19-29 AED). Evidence is too thin for a UAE store beyond a test.
- **Oman:** no pick. 20 of the 22 shortlisted rows have <50 units or unreported stock, and margins are 3-5 OMR at the
  floor. On this catalog Oman does not justify a store.

## Where evidence is thin
- Only the 27 launch-set rows have item-level competitor evidence, and many of those rest on one offer. Every new row
  has at most a **similar-archetype** range (research_price_benchmarks.csv). Those ranges describe similar items, not
  the same item. The archetype mapping comes from the old workbook's title matching. It is attached only when the
  archetype family fits the category. Misfits were dropped on 131 pool rows, 31 of them shortlisted (e.g. an electric
  peeler mapped to BEAU-15).
- No order history: confirmation, delivery and return rates are scenarios (30/45/60 % kept), never measurements.
- Dimensions, materials, contents and media quality are unknown for every new row. Supplier images are "Taager
  said" usable, not in writing.
- The project's claude.ai docs (Taager_shipping_policy_TEXT.md, Market infrastructure, BATCH-01..10, competitor
  report) could not be opened from this session. Delivery and refusal figures come from docs/COUNTRY-RULES.md and the
  workbook, which both cite the Sept 2026 policy.
