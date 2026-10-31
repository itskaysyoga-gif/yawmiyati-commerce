# Five-country implementation — 31 October 2026

Owner instruction supersedes the earlier Egypt/Iraq-only launch and development freeze. Target: EG, IQ, SA, AE, OM available from day one. Email dormant; phone-first records; video production deferred. No live launch or price change is authorized by a research recommendation alone.

## Architecture and qualification
Keep one independent WooCommerce installation and one country-specific Taager connection per country, sharing the existing plugin/theme. Never reuse a connection token across markets. Record the actual plugin version, country mapping, webhook destination and reference keys separately. Run ordinary, held, retry, quantity-discount, mixed-basket, cancellation, return/status and delivered-order checks independently in all five countries. Do not claim Taager supports this arrangement until observed. Each market retains its own catalog/checkout/ads gates. A public country page may explain availability while checkout remains closed if qualification fails; all-five simultaneous selling depends on all-five passing.

## Offer engine delivered in this branch
research/scripts/offer_decision.py evaluates explicit quantity-specific supplier TOTAL costs in integer minor currency units. It requires current verified cost evidence and explicit outcome probabilities. Missing charges block a recommendation. It never writes live prices. Six regression tests cover quantity math, unknown charges, stale evidence, invalid rates/costs and unverified discounts.

This is a calculation foundation, not a complete selection engine. Competitor comparison validation currently checks required fields only; freshness, currency, identity and pack-size validation must precede production use. Tax, payout and other deductions must be included in the relevant explicit costs after country-specific verification. Scenarios are assumptions, not observed outcomes.

## Next implementation
1. Fetch the latest Lovable source (the attached ZIP predates the latest reported edits). Adapt approved design into the existing theme; do not port the demo checkout.
2. Extend existing research CSV pipeline: evidence timestamps, exact/similar identity, currency, total delivered prices, stock and supplier quantity ladders. Keep competitor sales volume unknown unless there is attributable evidence; views and ad presence are not sales.
3. Rank by conservative contribution, audience fit, demonstrated utility, availability and operational risk. Separate confidence from opportunity score. No guaranteed virality or invented sales estimates.
4. Generate country-specific SEO copy only from verified facts. Store title, description, metadata, alt text, source facts and review state. Use existing tools/spend ledger for all Anthropic calls; reconcile the aggregate $40 before spending. Native review remains distinct from AI critique.
5. Compare single, supplier-supported quantity bundle and delivery-included offers. Never assume two units receive a discount or mixed products share a shipping charge. Show selected quantities and payable totals consistently.
6. Phone records: normalized country phone, customer/order history, delivery outcomes and separately evidenced marketing permission. No customer records in GitHub. Email stays off. WhatsApp campaigns wait for connection and permission checks.
7. Integrate operator decision screen with existing reporting; proposals explain evidence and uncertainty. Approval required before publication.

## External dependencies
Host/domain and credentials; five Taager links/plugin access; current SKU stock/cost/quantity discounts; country fees and policy/tax confirmation; original media (owner can add manually); rights and native copy review; real delivered/payout evidence.

## Handoff
Work branch: codex/five-market-commerce. No paid API calls, publishing, real orders or live price changes performed in this round. Full existing WooCommerce test suite has not been rerun; only the six new evaluator tests passed locally. Storefront redesign and supplier integration remain pending.
