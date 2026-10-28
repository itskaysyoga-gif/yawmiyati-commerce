# Reporting: order lifecycle and money

_29 Oct 2026. Code: `class-ym-lifecycle.php`, `class-ym-report.php`. Tests: `tests/integration/reporting.php`,
`tests/integration/accounting.php`._
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
| returned | came back after delivery: which lines and how many units of each | staff with evidence |
| refund_partial / refund_full | money given back | the WooCommerce refund itself (amount) |

Each event keeps when it happened, when we recorded it, the source, whether it is verified, who, and evidence.
The same update delivered twice is recorded once. Staff record outcomes:
`wp ym outcome <order> <stage> --evidence="…" [--reason=…] [--at=…] [--lines=SKU:units,…] [--units=…]`. A return on an
order with more than one line must say which lines came back (`--lines`); a units-only return there is refused by the
command and, if it arrives another way, leaves that order's money unknown.

**Contradictions stay open until a person decides.** A delivery after a refusal or after a cancellation before
hand-off, a refusal after a delivery, a return of a refused order, or more units returned than the line holds: the
event is kept, flagged and alerted; the order's outcome is `conflict`, it counts in no later funnel stage, sends no
OrderDelivered, and its money is unknown in the report (listed by order ID). `wp ym conflict list`;
`wp ym conflict resolve <order> <event> --accept|--reject --note="what you checked"`. Accept: the flagged event
stands and the events it contradicts are voided. Reject: the flagged event is voided. Voided events stay in the log
with who, when and why; the refusal-fee estimate follows the refusals that still stand. Open conflicts are a
launch-check item.

**Funnel = stages reached.** A delivered order counts as confirmed and shipped even when only "delivered" was
recorded; a refusal at the door counts as confirmed and shipped; a return counts as delivered.

**Outcome per order:** delivered_kept, delivered_partial_refund, returned_full, refused, cancelled_before_handoff,
cancelled_after_shipment, refunded_before_delivery, in_progress, unverified (status-only evidence before the status
sync is verified), conflict (open contradiction).

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
| delivered_sales | value of the units delivered and kept, less money refunded beyond the returned goods |
| refunds | product refunds (delivery-fee refunds apart in `delivery_fee_refunds`); `refunds_split_by_value` = amount-only refunds |
| supplier_credits | money Taager credited back: `wp ym credit <order> <amount> --source=… [--line=SKU]` |
| order_charges_known | order-level charges (refusal fee, return fee, shipping subsidy, payout fee), once per order |
| wallet_expected | Σ over lines: margin on kept units (net price − Taager cost) − refunds beyond returned goods + credits; − order charges |
| wallet_actual | credits recorded from Taager's wallet statement |
| paid_out | money that reached the payout account |
| payout_fees | recorded, or a configured rate × paid out |
| ad_spend | recorded spend; 0 only while the country's "ads" gate is off |
| contribution | wallet_expected − payout fees − ad spend |

Charges: a published policy figure is stored as a `rule` estimate; an actual charge from the wallet replaces it
(`wp ym charge <order> <type> <amount> --source=…`); a second actual charge of the same type is refused, so nothing is
deducted twice.

**Per line, never per order ratio.** Returns, line refunds (WooCommerce refunds that name lines) and line credits stay
on their own line. Order-level charges, order-level credits and amount-only refunds are split by one method,
`line_value_share` (share of the order's product value); the last line takes the rounding rest, so shares always add
back to the order figure. A unit that came back carries no margin, so an unknown cost no longer matters once every
unit of that line is back.

**Reconciliation.** Every report carries `reconciliation`: line value, refunds, credits and charges summed from lines
minus the same figures from the orders' own records (must be 0), product delivered value minus the report's
delivered sales, and product contributions minus `wallet_expected`. If any differs, `wallet_expected` and
`contribution` are null and the report says the figures are not reliable.

**Unknown stays unknown.** A missing Taager cost (stored as null in the order snapshot, never 0), a charge whose
amount nobody published (e.g. refusal fee in Saudi Arabia), an open conflict, a return that did not name its lines,
or missing ad spend while ads are on makes the dependent figure `null`, with the reason in `unknown`. Per product, one
unknown input makes that product's margin or contribution null (with `unknown` reasons) while other products keep
their known figures. The sum of the known parts is shown apart (`wallet_expected_bound.known_part_only`), labelled as
not an estimate.

Money outside WooCommerce: `wp ym money wallet|payout|payout_fee|spend <amount> <YYYY-MM-DD> --source=… [--sku=…]`.

## 4. Mature cohorts
Rates are computed only on orders older than `ym_cohort_maturity_days` (default 14). Younger orders are reported as
`immature_orders`, never as failures. The weekly email sends the last 7 days and the mature cohort of the 4 weeks
before it. Judge products and ads on the mature cohort.

## 5. Commands
`wp ym report --days=30` · `wp ym report --from=2026-11-01 --to=2026-12-01` · `wp ym ops` (board, docs/OPERATIONS.md)
