# First assortment, Egypt and Iraq (recommendation v1)

_29 Oct 2026. Status: **recommended, awaiting Rashid's yes.** Nothing here is approved, published or supplier-verified.
Built by `research/scripts/first_wave.py` → `research/selection/first_wave.csv` from `research/selection/offers.csv`
(Taager catalog export, undated, before 28 Oct 2026; competitor evidence captured 28 Oct 2026,
`research/data/benchmarks_egy_v2.csv`, `benchmarks_irq_v2.csv`)._

## The recommendation
Start each country with **four products and five offers**, all from the researched launch set (each has item-level
competitor evidence). A small set keeps the $150 test concentrated: one lead offer per country takes most of the ad
money, the rest are sold on the same pages, in carts and as second items. Everything else on the 40-row shortlists
stays a candidate until it has item-level evidence.

- **Egypt:** lead with the 2-set absorbent mats offer (749), the single set beside it; the double rack as the second
  advertised product; wearable blanket and heater for the winter. The heater publishes only after its electrical
  specification and warranty are in writing.
- **Iraq:** lead with the over-sink rack and its rack-plus-mats offer; corner organizer and heater as the second wave;
  mats only as an add-on. **Iraq ads wait for the refusal charge in writing**: at a refusal charge of one delivery
  fee (7,000) instead of the 0 Taager said, the organizer and heater barely break even and the mats lose money.
- **One price change:** the Egypt double rack goes from 649 to 689. Four identical competitor offers, three of them
  confirmed as likely the same item, sit at a median of 842; 689 keeps the customer's total under 800 in every
  governorate. Every other price stays at launch v2: their "identical" competitor offers were not confirmed as the same
  item, so the evidence does not justify a rise.
- **Reserve (not in the first wave):** Iraq double clothes rack `IQ030104STAN99` (one identical offer only) and Iraq
  magnetic window cleaner `IQ030107MASA99` (supported glass thickness unknown, breakage in transit).

## Egypt (EGP; delivery 95 / 100 / 105 by governorate, paid by the customer)
| Role | Offer | Taager SKU (product ID) | Price | Customer pays | Margin per kept order | Break-even cost per order at 30 / 45 / 60 % kept | Stock at export |
| --- | --- | --- | --- | --- | --- | --- | --- |
| lead offer (ads) | Absorbent mats, 2 sets (4 pieces) (عرض قطعتين من طقم الموكيت فائق الامتصاص 2 قطعة) | `EG030108BUND99` (16842) | 749 | 844-854 | 399 | 116 / 177 / 237 | not reported |
| lead product, single option | Absorbent mats, 1 set (2 pieces) (طقم موكيت فائق الامتصاص 2 قطعة الحديث) | `EG030106MKKT99` (13438) | 399 | 494-504 | 219 | 62 / 96 / 129 | above_1000 (more_than_1000) |
| core | Double clothes rack on wheels (ستاند ملابس زوجي بعجل مستورد) | `EG030106STPG99` (21300) | 689 (was 649) | 784-794 | 244 | 70 / 107 / 144 | below_200 (less_than_50) |
| seasonal (Nov-Feb) | Wearable blanket, grey (بطانيه قابله للارتداء رمادي) | `EG030401GB99` (17954) | 629 | 724-734 | 199 | 56 / 87 / 117 | below_200 (from_100_to_200) |
| seasonal, conditional | 2-in-1 heater with remote (دفاية كهربائية 180 درجه 2 في 1) | `EG030301HAEE99` (12437) | 799 | 894-904 | 234 | 67 / 102 / 138 | between_200_to_1000 (from_400_to_600) |

## Iraq (IQD; delivery 7,000, paid by the customer)
| Role | Offer | Taager SKU (product ID) | Price | Customer pays | Margin per kept order | Break-even cost per order at 30 / 45 / 60 % kept | …at 45 % if the refusal charge were 7,000 | Stock at export |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| lead product (ads) | Over-sink rack (رف للسنك الترند) | `IQ030403SENK99` (10966) | 43,000 | 50,000 | 13,000 | 3,900 / 5,850 / 7,800 | 2,000 | not reported |
| quantity option | Over-sink rack + 2-piece absorbent mats (عرض رف للسنك الترند + طقم 2 قطعة موكيت فائق الامتصاص) | `IQ030501SDMN99` (21568) | 58,000 | 65,000 | 22,000 | 6,600 / 9,900 / 13,200 | 6,050 | not reported |
| core | Corner clothes organizer (منظم ملابس زاوية) | `IQ030106MONZ99` (13630) | 28,000 | 35,000 | 9,000 | 2,700 / 4,050 / 5,400 | 200 | not reported |
| seasonal, conditional | Round 180-degree electric heater (صوبة كهربائية 180 درجة) | `IQ030301SOBA99` (12493) | 32,000 | 39,000 | 9,000 | 2,700 / 4,050 / 5,400 | 200 | not reported |
| add-on (not advertised alone) | Absorbent mats, 2 pieces (طقم 2 قطعة موكيت فائق الامتصاص) | `IQ030106MATT99` (12998) | 18,000 | 25,000 | 8,000 | 2,400 / 3,600 / 4,800 | -250 | not reported |

## How to read the numbers
Break-even cost per order is the most an order may cost in ads before it loses money: contribution per **submitted**
order with no ad cost, E = p × M − (1 − p) × F, where M is the margin on a delivered-and-kept order (price − Taager
cost), p the delivered-and-kept rate and F the refusal charge (Egypt 5 EGP, published; Iraq 0, "Taager said"). Every
order not kept is treated as refused at the door. **p is a scenario (30 / 45 / 60 %), not a measurement**: there is no
order history. The scenarios are replaced by measured rates once 50 own orders per country are mature. These margins
are thin: in Egypt the lead offer can afford about 177 EGP per submitted order at 45 % kept, the others about 87-107.
The test answers whether real ad costs sit below that.

## Kill-gates (per offer, per country)
1. Pause the ad set when spend reaches 3 × its break-even cost (45 % column) with no order.
2. Pause it when the cost per submitted order stays above break-even (45 %) after 20 orders.
3. Pause the product when fewer than 35 % of its first 30 mature orders were delivered and kept.
4. Pause at once when Taager stock for the SKU is not confirmed within 48 hours (the store stops selling it anyway).

## Evidence and open checks per offer
Common to every offer before it can publish (the store's catalog gate enforces them): current price and minimum in
Taager (the export is undated); stock checked under 48 h before ads; dimensions, materials and box contents from
Taager in writing; original media with written rights covering a clean view, a detail and a scale view; photos of
this exact SKU.

### Egypt
- **Absorbent mats, 2 sets (4 pieces)** `EG030108BUND99`: launch v2 price kept; 2 x 399 less 49; one delivery fee by construction (one Taager SKU). Evidence: none of its own (priced from its parts). Open: stock not reported for bundle SKUs: check in Taager.
- **Absorbent mats, 1 set (2 pieces)** `EG030106MKKT99`: kept: similar sets median 379 (n=2); identical 580 is one offer only. Evidence: identical: n=1 (likely-matches 0), median 580, delivery known for 0 || similar: n=2, median 379.
- **Double clothes rack on wheels** `EG030106STPG99`: raised from 649: identical items median 842 (n=4, 3 confirmed as likely the same item); 689 keeps the customer total under 800 in every governorate. Evidence: identical: n=4 (likely-matches 3), median 842, delivery known for 0 || similar: n=2, median 684.5. Open: stock was under 50 units: confirm depth before ads; drop the word "imported" (unverified).
- **Wearable blanket, grey** `EG030401GB99`: kept at 629 (lowered from 649 in launch v2): identical median 739 (n=3) but none confirmed as the same item. Evidence: identical: n=3 (likely-matches 0), median 739, delivery known for 1, delivered median 739 || similar: n=3, median 529. Open: size and fabric must be stated.
- **2-in-1 heater with remote** `EG030301HAEE99`: kept: identical median 899 (n=3), delivered 919; customer total crosses 800, so no rise. Evidence: identical: n=3 (likely-matches 0), median 899, delivery known for 1, delivered median 919 || similar: n=2, median 924.5. Open: electrical: wattage, voltage, plug, thermal cut-off, tip-over switch and warranty in writing before publishing.

### Iraq
- **Over-sink rack** `IQ030403SENK99`: kept: identical median 60,650 (n=2), delivered 50,799. Evidence: identical: n=2 (likely-matches 0), median 60649.5, delivery known for 1, delivered median 50799. Open: dimensions (sink width it fits) must be stated.
- **Over-sink rack + 2-piece absorbent mats** `IQ030501SDMN99`: launch v2 price kept: 43,000 + 18,000 less 3,000; one Taager SKU. Evidence: workbook archetype HOME-03 not used: inconsistent with category home_textiles (workbook mapping is title-based and unreliable). Open: stock not reported in Iraq at all.
- **Corner clothes organizer** `IQ030106MONZ99`: kept: identical median 36,999 (n=3, delivered 35,500) but none confirmed as the same item; raise only after a match. Evidence: identical: n=3 (likely-matches 0), median 36999, delivery known for 2, delivered median 35499.5 || similar: n=1, median 125000. Open: assembly and size must be stated.
- **Round 180-degree electric heater** `IQ030301SOBA99`: kept: identical 40,599 (n=1) but similar median 31,500 (n=3); evidence too thin to raise. Evidence: identical: n=1 (likely-matches 0), median 40599, delivery known for 1, delivered median 40599 || similar: n=3, median 31500. Open: electrical: wattage, voltage, plug, thermal cut-off, tip-over switch and warranty in writing before publishing.
- **Absorbent mats, 2 pieces** `IQ030106MATT99`: kept: identical 22,500 (n=1, delivery not shown). Evidence: identical: n=1 (likely-matches 0), median 22500, delivery known for 0. Open: margin too thin to carry its own ads; sold with the rack and as a second item.
- Iraq's export reports no stock for any SKU: every Iraq offer needs a stock answer from Taager before ads.

## Saudi Arabia, UAE and Oman
Prepared and closed. Their commercial recommendations are **provisional until current supplier evidence exists**:
Saudi Arabia after its refusal and return charges are published or measured on a refused test order; the UAE at most
one test product; Oman not opened while its catalog stays thin. None of their offers is recommended for approval now.

## What your yes does
Reply "approved" (or name what to drop). The approved rows get stage `approved` with the date in
`research/selection/offers.csv`, and the Egypt rack price becomes 689 in the store records. Publishing still waits for
each offer's catalog gate (stock, specs, media rights), and ads for the P1 checklist (docs/LAUNCH-CHECKLIST.md).
