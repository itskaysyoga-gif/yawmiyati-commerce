# Reporting: order lifecycle and money

_28 Oct 2026. Code: `class-ym-lifecycle.php`, `class-ym-report.php`. Tests: `tests/integration/reporting.php`._
Replaces the old per-line "funnel" (it counted product lines as orders, deducted the refusal fee once per line,
read only the current status, and grouped refunds with refusals).

## 1. Order history (append-only, per order)
| Stage | Meaning | Recorded from |
| --- | --- | --- |
| submitted | accepted at checkout | checkout (always verified) |
| confirmed | confirmation call succeeded | mapped status (verified only after status sync is verified) or staff |
| shipped | handed to the courier | same |
| delivered | received and paid | same; a bare WooCommerce "completed" is not delivery |
| refused | refused at the door, before delivery (reason code) | same |
| cancelled | `before_handoff` or `after_shipment` | our status change |
| returned | came back after delivery, with units | staff with evidence |
| refund_partial / refund_full | money given back | the WooCommerce refund itself (amount) |

Each event keeps when it happened, when we recorded it, the source, whether it is verified, who, and evidence.
The same update delivered twice is recorded once. A delivery reported after a refusal is kept, flagged and alerted,
and sends no OrderDelivered. Staff record outcomes: `wp ym outcome <order> <stage> --evidence="…" [--reason=…] [--at=…] [--units=…]`.

**Funnel = stages reached.** A delivered order counts as confirmed and shipped even when only "delivered" was
recorded; a refusal at the door counts as confirmed and shipped; a return counts as delivered.

**Outcome per order:** delivered_kept, delivered_partial_refund, returned_full, refused, cancelled_before_handoff,
cancelled_after_shipment, refunded_before_delivery, in_progress, unverified (status-only evidence before the status
sync is verified).

**Reason codes** decide whom a failure counts against: customer (changed_mind, not_reachable, not_at_address,
price_dispute, ordered_elsewhere, fake_order) or operation (wrong_item, damaged, late_delivery, supplier_delay,
not_as_described, address_unsupported, duplicate_order). Only verified customer reasons feed the risk records
(docs/RISK.md).

## 2. Counting
Orders are counted once. Lines and units are separate counts. Per product: orders containing it, lines, units,
delivered orders and units. Test and seed orders are excluded.

## 3. Money (kept apart)
| Line | Definition |
| --- | --- |
| submitted_value | product value of submitted orders (delivery fees separate: `delivery_fees_charged`) |
| delivered_sales | product value of delivered orders less refunds on them |
| refunds | partial + full refunds |
| order_charges_known | order-level charges (refusal fee, return fee, shipping subsidy, payout fee), once per order |
| wallet_expected | margin on delivered-and-kept units (price − Taager cost) − order charges |
| wallet_actual | credits recorded from Taager's wallet statement |
| paid_out | money that reached the payout account |
| payout_fees | recorded, or a configured rate × paid out |
| ad_spend | recorded spend; 0 only while the country's "ads" gate is off |
| contribution | wallet_expected − payout fees − ad spend |

Charges: a published policy figure is stored as a `rule` estimate; an actual charge from the wallet replaces it
(`wp ym charge <order> <type> <amount> --source=…`); a second actual charge of the same type is refused, so nothing is
deducted twice. **Allocation to products:** one method, `line_value_share` (share of the order's product value).

**Unknown stays unknown.** A missing Taager cost, a charge whose amount nobody published (e.g. refusal fee in Saudi
Arabia), or missing ad spend while ads are on makes the dependent figure `null`, with the reason in `unknown`. A
partial figure is shown only as a labelled bound (`wallet_expected_bound`).

Money outside WooCommerce: `wp ym money wallet|payout|payout_fee|spend <amount> <YYYY-MM-DD> --source=… [--sku=…]`.

## 4. Mature cohorts
Rates are computed only on orders older than `ym_cohort_maturity_days` (default 14). Younger orders are reported as
`immature_orders`, never as failures. The weekly email sends the last 7 days and the mature cohort of the 4 weeks
before it. Judge products and ads on the mature cohort.

## 5. Commands
`wp ym report --days=30` · `wp ym report --from=2026-11-01 --to=2026-12-01` · `wp ym ops` (board, docs/OPERATIONS.md)
