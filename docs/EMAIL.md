# Customer permissions and email

_29 Oct 2026, reliability changes the same day (review §5). Code: `YM_Mail` (plugin/yawmiyati-commerce/includes/class-ym-mail.php).
Tests: tests/e2e/mail.test.js and tests/e2e/reliability.test.js. Nothing here is provider-tested: Brevo is not connected._

## Records (shared by the five stores, in YM_SHARED_DB)
- `ym_contacts`: stable id, email (lower-cased), status (pending, subscribed, unsubscribed, bounced, complained),
  consent time, consent wording version (`2026-10-29`), source (footer, after_order), country, language,
  confirmation time, unsubscribe time. Phones are never used to merge people (households share phones).
- `ym_contacts.nonce`: the current signup's one-time value; a confirmation link is signed over it and the wording
  version, so only the link for the latest signup works, and only once.
- `ym_mail_log`: every send attempt (address hash, kind, reference, store, marketing flag, time, **state**, provider
  message ID, note). States: `sending` → `accepted` | `failed` | `unknown`.
- `ym_mail_events`: provider events, `applied = 1` once their suppression is saved.
- Orders link to a contact through order meta `_ym_contact_id` (a link, never a merge). WooCommerce stays the source
  of truth for orders. Unconfirmed signups are deleted after 30 days; suppressions are kept (they protect her).

## Rules
- Checkout stays guest-first and phone-first. The email field appears only while receipts are actually sent
  (`transactional` flow on), is optional, and is a receipt address, not marketing consent. No address is ever made up.
- Newsletter signup is separate, unticked, double opt-in (signed confirmation link), in the footer and after
  ordering; never a popup, never inside the checkout form. Cookie acceptance, ordering and Instagram follows never
  imply it. Social followers are never imported.
- Suppression is shared and strongest-wins: complained > bounced > unsubscribed. Only her own new confirmed opt-in
  re-subscribes; `YM_Mail::import()` and any sync never do.
- **One marketing email per person per week, across all stores and every flow** (welcome, review invitation,
  cross-sell, newsletter): the promise in the signup text. Order-service emails are separate and not capped.
  The allowance is reserved in one transaction holding a row lock on her contact; suppression is read inside the same
  lock (the record passed in is never trusted). Tested: six sends from three stores at the same moment, one goes out;
  the same test fails without the lock.
- The weekly newsletter is sent with `wp ym mail newsletter <issue> --subject=… --body-file=…` (this country's
  subscribers, through the same cap). **Never from Brevo's campaign screen**: a campaign sent there would bypass the
  cap and the promise.
- One-click unsubscribe (`List-Unsubscribe` + `List-Unsubscribe-Post`, RFC 8058 POST) on every marketing message.
- **Links never act on a GET.** Opening a confirmation or unsubscribe link shows a page with one button; only the
  button (a POST) or a mail client's one-click POST acts. Mail security scanners that open links change nothing.
- Signup limits per address (a confirmation email at most every 10 minutes and 3 a day) plus a generous per-IP limit
  (30 / 10 min: carriers share IPs). The answer is the same whatever the address's state (no list disclosure).
- A bounce or complaint is never lifted by a form. A person lifts it after checking: `wp ym mail unblock <email>
  --note=…`; she then has to opt in again.

## Send outcomes (never "probably not sent")
| Provider answer | State | What happens next |
| --- | --- | --- |
| 2xx | accepted | provider message ID kept; never sent again |
| 4xx except 408/429 | failed | a definite refusal; a later call may try again (same row) |
| timeout, broken connection, 408, 429, 5xx | unknown | it may have gone out: never resent automatically, counts against caps, ops alert; resolved by a provider event carrying our reference (`X-Mailin-custom: ym:<id>`) or by a person (`wp ym mail unknown`, `wp ym mail resolve <id> accepted|failed --note=…`) |

Brevo is also given an idempotency key per message (header `idempotencyKey` inside the request's `headers`; Brevo
remembers keys for 15 minutes, per its changelog). Whether Brevo echoes `X-Mailin-custom` in webhook events, and the
exact idempotency behaviour, are checked at the provider test (O14); until then "unknown" is resolved by a person.
- WooCommerce's own customer emails ("processing", "completed", "refunded"…) are switched off: their wording implies
  payment or completion that a COD request does not have.

## Flows (all OFF until tested; option `ym_email_flows`)
| Flow | Trigger | Who | Notes |
| --- | --- | --- | --- |
| transactional: received | order submitted | address given at checkout | "a request, not confirmed; pay the courier on delivery" |
| transactional: confirmed, shipped, cancelled, refund | verified lifecycle event only | same | never from an unverified status; refund only when recorded |
| newsletter: confirm, welcome | signup, then confirmation | that address | Brevo contact created only on confirmation |
| post_delivery | verified delivery + 2 days | subscribed | care/assembly guidance + neutral review invitation; a return later does not remove review eligibility |
| cross_sell | verified delivery + 10 days | subscribed, outcome delivered_kept, no open support flag (`_ym_support`) | one recommendation from YM_Reco |
| weekly newsletter | `wp ym mail newsletter` per country | subscribed | shares the one-a-week cap; never sent from Brevo's campaign screen |
| win-back | later, on real inactivity | — | not built |
| abandoned checkout | — | — | **not built** (see corrections) |

## Brevo
Narrow API adapter, not Brevo's WooCommerce plugin: transactional `POST /v3/smtp/email` (tags: transactional or
marketing, kind, country), contacts `POST /v3/contacts` (list per country, attributes COUNTRY, CONSENT_AT,
CONSENT_WORDING, SOURCE; `emailBlacklisted` true on any suppression). Webhook `POST /wp-json/ym/v1/mail-events`
(header `X-YM-Key` = YM_BREVO_WEBHOOK_KEY; whether Brevo's webhook settings can send a custom header is checked at
O14) for hard_bounce, invalid_email, blocked, spam, unsubscribed. Suppression is saved first and the event recorded
after; if the save fails the webhook answers 500 and Brevo's retry finishes it (tested). Order references are country-prefixed (EG-123, IQ-123), so two stores with the same numeric order ID
never collide.

## Corrections to the 29 Oct brief (and why)
- **No validation run of Brevo's WooCommerce plugin; the narrow adapter from the start.** The plugin syncs WooCommerce
  customers and orders as contacts and adds its own tracking, which conflicts with consent-only marketing and the
  shared cross-store suppression; the brief itself names the adapter as the fallback. (Design judgement: the plugin
  was not installed or tested here.)
- **Abandoned-checkout email is not built.** The checkout is phone-first with an optional email that exists only
  after she submits; capturing a half-filled form to market to her would need prior marketing consent (Egypt's
  rules address direct marketing consent), and the confirmation call already covers submitted orders.

## Before switching a flow on (owner actions in LAUNCH-CHECKLIST.md)
Brevo account; sending domain authenticated with SPF, DKIM and DMARC (alignment tested); a monitored reply address
(`ym_mail_reply_to`); sender (`ym_mail_from`); list IDs per country (`ym_brevo_lists`); keys in the environment
(YM_BREVO_KEY, YM_BREVO_WEBHOOK_KEY, YM_MAIL_SECRET shared by all stores); templates read on mobile Gmail and Outlook
(Arabic RTL, links, prices, plain text, unsubscribe). Delivery and purchases matter more than open rates.
