# COD abuse controls

_29 Oct 2026. Code: `class-ym-risk.php`, `class-ym-net.php` (client IP), `class-ym-db.php` (counters), duplicate matching
in `class-ym-orders.php`. Tests: `tests/e2e/risk.test.js`, `tests/integration/accounting.php` (client IP, counters)._

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

**Which address counts.** `REMOTE_ADDR` is the client unless the connection comes from a configured trusted proxy
(`YM_TRUSTED_PROXIES`, IPs or CIDR ranges, from the environment). Only then is a forwarding header believed:
`CF-Connecting-IP`, or the right-most `X-Forwarded-For` hop that is not one of our proxies (`YM_CLIENT_IP_HEADER`).
A client that sends those headers itself on a direct connection changes nothing (tested: rotating forged headers
does not reset the per-connection count). The order records the same address. On staging, compare `REMOTE_ADDR`
and the headers with your own IP, then set the proxy list if a proxy sits in front (launch-check item).

**Counting is atomic.** Each attempt increments a per-minute counter row in one SQL statement and reads back its own
position (`LAST_INSERT_ID(n + 1)`), so simultaneous requests can never lose a count or share a number. Tested: 10
simultaneous submissions from one session with a limit of 6 are refused exactly 4 times; 8 simultaneous ones from one
connection with a limit of 5, exactly 3. Raw session IDs and IPs are never stored, only salted hash prefixes.

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

A "success" is accepted only when the answer's `action` is exactly `checkout` (the widget's action; a missing action
is refused) and its `hostname` is this store's own host or one listed in `ym_turnstile_hostnames`. Tested: a token
for another site, another action or no action is refused and creates nothing. Cloudflare's test keys answer with
their own hostname, so add it to `ym_turnstile_hostnames` on staging while using them.

## 5. Held orders and the supplier (verification task)
Proven locally: a held order goes pending → on-hold and never passes through processing; a probe on the usual
supplier-plugin hooks (new order, checkout created/processed, status processing, payment complete, thank-you) never
sees it in processing. **Not proven:** what Taager's plugin does. If it sends orders on `woocommerce_new_order` or
`woocommerce_checkout_order_processed` regardless of status, a held order would still reach Taager. Before launch:
read the installed Taager plugin's code for its hook and status check, then place one deliberately held test order on
staging and confirm it does not appear in Taager until released. If it does appear, the plugin's handler must be
unhooked for held orders (code change after reading it) or holds must be cancelled in Taager by hand.
