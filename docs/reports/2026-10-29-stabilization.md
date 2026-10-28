# Stabilization round, 29 Oct 2026

Scope: the six fixes in Rashid's 29 Oct brief, with regression tests. No new features. Status words as in
docs/PROJECT-STATE.md. **Nothing here is supplier-verified**: local tests do not show what Taager's plugin does.

## Result
- Commit: __COMMIT__ · CI: __CI__
- Tests (the one set of counts; earlier documents point here): __COUNTS__

## What changed, and the test that holds it
| # | Fix | Regression tests |
| --- | --- | --- |
| 1 | The confirmation link's `?key=wc_order_…` is never rendered: the server keeps it in her session and redirects to the address without it; the first inline script strips it as a second guard; the review page loads no tags and sends no referrer | e2e "Order key": returning visitor, consent already granted, a tag that runs the moment it loads; every document start and referrer is recorded; the key URL is answered with 302 only |
| 2 | Consent withdrawal reaches all five stores: shared ledger (`YM_SHARED_DB`), checked before every server event in every store; fails closed when unreadable; queued and retried when unwritable | e2e "Consent withdrawal across stores" (withdraw in Egypt, Iraq's queued event skipped, another customer's sent, a later order after re-accepting sent); e2e "Consent ledger unreachable" (waits, then sends); integration ledger checks |
| 3 | Accounting per order line: returns by line and units, line refunds, amount-only refunds split by value, supplier credits (`wp ym credit`), unknown costs null in the snapshot and per product, reconciliation of lines against orders and products against the report; contradictory events (delivered after refused, refused after delivered, delivered after cancellation, over-returns) stay open with outcome `conflict` and unknown money until `wp ym conflict resolve` | integration `accounting.php`: 2-line partial return, line refund, amount-only refund, credits, charge split, units-only return on 2 lines, over-return, conflict accepted and rejected, reconciliation |
| 4 | Client IP from trusted proxies only (`YM_TRUSTED_PROXIES`, none by default); atomic per-minute SQL counters; Turnstile answer must carry action `checkout` and our hostname; the order records the same IP | e2e "Concurrent submissions" (10 at once from one session, limit 6 → exactly 4 refused; 8 at once from one connection, limit 5 → exactly 3); e2e "Client IP" (forged headers do not reset the count); e2e Turnstile (wrong host, wrong action, no action refused); integration client-IP and counter checks |
| 5 | Reviews: allowed after a verified delivery even when the item was later returned or refunded; never after a refusal | integration review checks |
| 6 | `package-lock.json` committed; CI uses `npm ci`; test counts reconciled (the 28 Oct report said 82 / 365; the final run on e4ebf87 was 85 / 370, now corrected there) | CI install step |

Found and fixed on the way: the hourly supplier sweep used a `meta_query` that WooCommerce ignores on this order
storage, so it re-checked every processing order (131 of 182 locally were genuine candidates). It now looks up
pending and unconfirmed hand-offs directly (integration check).

## What local tests cannot show (staging, per country: docs/LAUNCH-CHECKLIST.md #4-#11)
The real Taager plugin's hook and status check, whether a held order stays out of Taager, the reference meta key,
status names synced back, delivery charges on a 2-item order, duplicate handling of a retried checkout, the host's
proxy headers, and performance and measurement with final media and real tags.
