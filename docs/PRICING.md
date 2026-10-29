# Pricing: delivered contribution per offer

Code: `research/scripts/economics.py` · Output: `research/selection/pricing.csv` (one row per shortlisted offer,
built from `research/selection/offers.csv`) · Run: `python3 research/scripts/select.py && python3 research/scripts/economics.py`

## Formula
Expected contribution per **submitted** order:

    E = p_kept × M − q_refused × F − q_returned × R − q_cancelled × K − q_partial × L − O − A

| Symbol | Meaning | Value used | Source / status |
| --- | --- | --- | --- |
| M | Wallet profit of a delivered-and-kept order = Taager price − Taager cost − VAT | per offer | Catalog export (cost = `original_price`, min = `selling_price`), capture date unknown, before 28 Oct 2026 |
| VAT | Saudi only: 15 % of the amount above Taager's minimum | 0.15 × (price − min) | COUNTRY-RULES.md (Taager policy, Sept 2026). Workbook v3 says the same ("+100 SAR puts 85 in your wallet"). Taager's published Saudi policy (project doc Taager_shipping_policy_TEXT.md) says 15 % **of the added profit** is deducted, so 15 % of the uplift is the published rule, not an assumption. Open: whether it applies to a non-Saudi merchant |
| Delivery fee | Paid by the customer to Taager, once per order | EG 95/100/105 · IQ 7,000 · SA 28 · AE 19 · OM 3 | COUNTRY-RULES.md; same figures in the workbook. One fee per multi-item order is "Taager said" (support chat, 28 Oct), not verified |
| F | Charge to the merchant when the customer refuses at the door | EG 5 (published) · IQ 0 ("Taager said") · SA/AE/OM **unknown** | COUNTRY-RULES.md, support chat |
| R | Charge for a return after delivery | Egypt: 5 EGP (published: return fee for a delivered order). Elsewhere = F (**assumption A2**) | Egypt: Taager_shipping_policy_TEXT.md "Return fee for a delivered order: 5 EGP". Not published for IQ, SA, AE, OM |
| K | Charge for a cancellation before dispatch | 0 (**assumption A1**) | No source lists one; the profit is simply never earned |
| L | Loss on a partial refund | outcome share 0 in every scenario | No data |
| O | Per-order operating cost | 0 (**assumption A4**) | Nothing is paid per order today; hosting and tools are fixed costs |
| A | Acquisition cost per submitted order | the output: **break-even CPA** = E with A = 0 | — |
| Payout fee | Withdrawal / transfer cost | **unknown, outside E** | Withdrawal routes are listed (Taager said: bank or Payoneer), fees are not |

E is measured at the Taager wallet. "Money actually paid out" = wallet − payout costs, and those costs are unknown.

### What the model is and is not (29 Oct)
- Break-even CPA is a **ceiling before omitted costs and profit**, never a recommended bid. Bids are set below it.
- Modelled figures (scenarios) and observed figures (the store's report) are kept apart. The store's report
  (docs/REPORTING.md, `YM_Report`) already separates cancellation before hand-off, refusal, delivered-and-kept,
  partial and full return, refunds, supplier credits and order charges per line, and shows unknown money as unknown.
  Delivery is never counted twice: the customer pays it to the courier and it is not part of the supplier margin.
- Decisions use observed contribution with an 80 % band once 15 mature orders exist (`YM_Gates`, `wp ym gates`):
  a bare kept-rate threshold is not used (it ignores margin). Small cohorts are directional.
- Competitor comparisons use delivered prices and genuine SKU matches with their capture date (research/data).

### Rates are scenarios, not facts
p_kept = **30 % / 45 % / 60 %** delivered-and-kept per submitted order. Every other order is treated as refused at the
door, the costliest outcome with a known charge. There is **no order history** (0 orders, no date range). The workbook's
"Taager delivery rate 70-85 %" comes from Phase 0 desk research, is per confirmed order and is not used.
The scenarios are replaced by measured rates once 50 own orders per country are mature (PRODUCT-STANDARDS.md).

### Unknown stays unknown (the fixed bug)
The old `economics.py` replaced an unknown refusal fee with 0 inside break-even and only raised a flag. Now:
- If F is unknown (Saudi Arabia, UAE, Oman), `breakeven_cpa_*` is **empty** in pricing.csv and the report states that
  break-even cannot be computed.
- `*_range_A3` gives a range only under **assumption A3**: 0 ≤ F ≤ one delivery fee. This is a stress bound, not evidence.
  Example, Saudi drying rack SA030104KADNEW699 at 169 SAR: break-even CPA at 30 % lies between −2.3 and 17.3 SAR.
- `Offer(..., strict=True)` also treats "Taager said" figures as unknown. Iraq then has no computable break-even either:
  the 0 IQD refusal charge rests on one support chat.

## The three offer structures
| | What the customer sees | What is set in Taager | Merchant cost |
| --- | --- | --- | --- |
| (a) price + disclosed delivery | product price, plus the delivery fee shown before submission | product price | none |
| (b) delivery included | one all-in headline price | headline − highest delivery fee | that fee, out of margin, on every delivered order; must stay ≥ Taager minimum |
| (c) quantity offer | 2× (or more) at one price, one delivery | Taager's own multi-unit SKU | none; one fee by construction |

pricing.csv computes (b) at the **same headline** as (a). This is the only version that differs economically: (b) at
the same total is the same money as (a) with a different label. `b_needs_kept_rate_uplift` is how much the
delivered-and-kept rate would have to rise for (b) to break even with (a). Examples: Egypt mats +92 %, Iraq sink rack
+117 %, Iraq corner organizer +350 %. For 141 of the 157 rows, (b) at the same headline falls below Taager's minimum
and cannot be set at all.

(c) is computed only where Taager sells a multi-unit SKU of the same product (10 rows). Its price is units ×
single price, rounded, except where launch_assortment_v2 already set one (EG030108BUND99 at 749). **Cart thresholds**
(free delivery above X) are not modelled. They rely on "one fee per multi-item order", which is only "Taager said"
(verification task 7: a 2-item test order).

## Recommendation rule (one per offer)
1. Proposed price below Taager's minimum → `undecided: needs a valid price`.
2. Refusal charge unknown → `undecided: needs the <country> refusal/return charge`. This applies to **all Saudi, UAE and
   Oman rows (77)**. (a) is the provisional structure.
3. Break-even CPA at 30 % ≤ 0 → `reject at this price`.
4. Otherwise → **(a) price + disclosed delivery**, plus **(c) as a quantity option** when the multi-unit SKU gives a
   higher break-even CPA per order (EG mats ×2, EG deep-clean spray ×2, EG shoe-cleaning bag ×2, EG wood polish ×2,
   IQ rotating brush ×2, IQ bathroom corner ×2, IQ foam cleaner ×2).
   (b) is never recommended. It costs one delivery fee per delivered order, and its only possible gain, conversion,
   is unmeasured. It becomes testable once (a) has a measured kept rate.

Result: Egypt 40 and Iraq 40 rows have a recommendation (Iraq rests on the "Taager said" refusal charge of 0). Saudi
Arabia 40, UAE 15 and Oman 22 rows are undecided.

## Launch set (structure a)
| | SKU | Price | Customer pays | Margin | Break-even CPA 30/45/60 % | (b) same headline | (b) needs kept-rate +% | Recommended |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| EG | EG030106MKKT99 mats | 399 | 494-504 | 219 | 62 / 96 / 129 | 294 | 92 % | a + c (EG030108BUND99 at 749) |
| EG | EG030108BUND99 mats ×2 | 749 | 844-854 | 399 | 116 / 177 / 237 | 644 | 36 % | a |
| EG | EG030106STPG99 rack | 649 | 744-754 | 204 | 58 / 89 / 120 | below min | — | a |
| EG | EG030301HAEE99 heater | 799 | 894-904 | 234 | 67 / 103 / 138 | 694 | 81 % | a |
| EG | EG030401GB99 blanket | 629 | 724-734 | 199 | 56 / 87 / 117 | below min | — | a |
| IQ | IQ030403SENK99 sink rack | 43,000 | 50,000 | 13,000 | 3,900 / 5,850 / 7,800 | 36,000 | 117 % | a |
| IQ | IQ030501SDMN99 rack + mats | 58,000 | 65,000 | 22,000 | 6,600 / 9,900 / 13,200 | 51,000 | 47 % | a |
| IQ | IQ030106MATT99 mats | 18,000 | 25,000 | 8,000 | 2,400 / 3,600 / 4,800 | below min | — | a |
| IQ | IQ030301SOBA99 heater | 32,000 | 39,000 | 9,000 | 2,700 / 4,050 / 5,400 | below min | — | a |
| IQ | IQ030106MONZ99 organizer | 28,000 | 35,000 | 9,000 | 2,700 / 4,050 / 5,400 | 21,000 | 350 % | a |
| IQ | IQ030107MASA99 window cleaner | 18,000 | 25,000 | 8,000 | 2,400 / 3,600 / 4,800 | below min | — | a |
| IQ | IQ030104STAN99 rack | 30,000 | 37,000 | 10,000 | 3,000 / 4,500 / 6,000 | 23,000 | 233 % | a |

Iraq break-even assumes F = 0 ("Taager said"). In strict mode with A3 (F up to 7,000), the sink rack at 30 % falls to −1,000 IQD.

## "No free-delivery offers": a Taager rule or our choice?
- **Taager mechanic (source: published shipping policy via COUNTRY-RULES.md; workbook v3 says the same):** Taager adds
  the delivery fee to the customer's total at checkout and does not take it from the merchant's profit. The customer
  is therefore always charged the fee *on top of the Taager price*.
- **Our choice:** whether to *advertise* delivery as free or included. The workbook's own recommendation was to
  include delivery in the headline: set "price − fee" in Taager so the customer lands on the advertised total. That is
  structure (b), and it is allowed only while price − fee ≥ Taager minimum.
- So "customer always pays delivery" is a Taager fact. "No free-delivery offers" is a decision, and the numbers above
  support it: (b) at the same headline costs a full delivery fee per delivered order. The decision is not a supplier
  constraint.
- Not checked: the project doc `Taager_shipping_policy_TEXT.md` could not be opened from this session (the Projects tool
  was not available). The statement above relies on COUNTRY-RULES.md and the workbook, which both cite that policy.
  Re-read the policy text before quoting it to customers.

## What would change these numbers
Refusal charge for SA/AE/OM, and written confirmation for IQ · return charge after delivery (IQ, SA, AE, OM; Egypt's 5 EGP is published) · payout fees ·
whether Saudi VAT (15 % of the uplift, published) applies to a non-Saudi merchant · current Taager price and minimum (the export
is undated) · measured kept rates from mature orders.


_Review note (lead session, 28 Oct): the shipping-policy doc was read in the lead session; it settles the Saudi VAT base (15 % of the added profit) and Egypt's 5 EGP fee on a delivered-then-returned order. Refusal charges for SA, AE, OM and the return charge outside Egypt remain unpublished._
