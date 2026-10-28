# COD abuse controls

_28 Oct 2026. Code: `class-ym-risk.php` (and duplicate matching in `class-ym-orders.php`). Tests: `tests/e2e/risk.test.js`._

Decisions come from recorded behaviour and the recorded reason a delivery failed, never from who the customer is.
One signal never blocks anyone permanently: shared family phones, recycled numbers and carrier-shared IPs are normal.

## 1. Refused at submission (no order created)
| Check | Rule |
| --- | --- |
| Honeypot | hidden field filled → neutral "refresh and try again" |
| Browser burst | more than 6 complete submissions from one browser session in 10 min |
| Connection, extreme | more than 120 from one connection in 10 min |
| Bot check (when keys are set) | Cloudflare Turnstile fails |

Typing mistakes never count towards limits (only submissions that passed field validation).

## 2. Created and held for review (on-hold, not handed to the supplier)
| Reason code | Rule |
| --- | --- |
| possible_duplicate | same phone (main or second, any digit form) ordered an overlapping product in 24 h |
| phone_velocity | 3+ orders from that phone in 24 h |
| fast_submit | form sent within 4 s of being shown |
| many_units | more than 6 units in one order |
| ip_burst | 30+ complete submissions from one connection in 10 min (held, not refused: carriers share IPs) |
| confirm_first | phone's risk record is "confirm" |
| risk_hold | phone's risk record is "hold" |
| bot_check_unavailable | Turnstile could not be reached; the order is kept, a person checks it |

The customer sees the normal confirmation; nothing blames her. Staff: `wp ym review list`,
`wp ym review release <order> --note="what you checked"`, `wp ym review cancel <order> --note="why"`; every action is
kept in the order's audit trail. All thresholds are settings (`ym_risk_settings`).

## 3. Risk records (per phone, stored as a hash)
| Situation | Action |
| --- | --- |
| First ordinary order | normal |
| Same phone + overlapping product soon after | held: retry, replacement or a real second order — a person decides |
| One verified customer-caused refusal | record "watch": no effect on ordering |
| 2+ verified customer-caused refusals (180 days) | "confirm" for 90 days: next orders held for a confirmation call before hand-off |
| 2+ verified `fake_order` refusals | "hold" for 30 days, manual review |
| Supplier delay, wrong or damaged item, late delivery, unsupported address | recorded against the operation, never the customer |

Records expire, keep their evidence (order IDs) and full history, and can be set, extended or cleared by staff with a
reason: `wp ym risk show|set|clear <phone> [level] --reason="…" [--days=…]`.

## 4. Turnstile
Off until `ym_turnstile_sitekey` and the secret `YM_TURNSTILE_SECRET` (environment) are set. Token verified on the
server once per checkout: the checkout token is the `idempotency_key`, and a pass is remembered for that checkout so
a retry of the same submission (lost response) is not refused for a spent token. Expired or spent tokens reset the
widget. If Cloudflare is unreachable the order is held, not lost. Tokens are valid 300 s and single-use (Cloudflare
docs, server-side validation).

## 5. Held orders and the supplier (verification task)
Proven locally: a held order goes pending → on-hold and never passes through processing; a probe on the usual
supplier-plugin hooks (new order, checkout created/processed, status processing, payment complete, thank-you) never
sees it in processing. **Not proven:** what Taager's plugin does. If it sends orders on `woocommerce_new_order` or
`woocommerce_checkout_order_processed` regardless of status, a held order would still reach Taager. Before launch:
read the installed Taager plugin's code for its hook and status check, then place one deliberately held test order on
staging and confirm it does not appear in Taager until released. If it does appear, the plugin's handler must be
unhooked for held orders (code change after reading it) or holds must be cancelled in Taager by hand.
