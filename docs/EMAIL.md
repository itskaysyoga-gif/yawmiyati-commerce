# Customer permissions and email

_29 Oct 2026. Code: `YM_Mail` (plugin/yawmiyati-commerce/includes/class-ym-mail.php). Tests: tests/e2e/mail.test.js._

## Records (shared by the five stores, in YM_SHARED_DB)
- `ym_contacts`: stable id, email (lower-cased), status (pending, subscribed, unsubscribed, bounced, complained),
  consent time, consent wording version (`2026-10-29`), source (footer, after_order), country, language,
  confirmation time, unsubscribe time. Phones are never used to merge people (households share phones).
- `ym_mail_log`: every email sent (address hash, kind, reference, store, marketing flag, time): sends each message once
  across stores and enforces the caps.
- `ym_mail_events`: provider events already applied (idempotent webhooks).
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
- Caps across all stores: 1 marketing email per person per day, 3 per week.
- One-click unsubscribe (`List-Unsubscribe` + `List-Unsubscribe-Post`) on every marketing message.
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
| weekly newsletter | composed in Brevo per country list | subscribed | content, not code |
| win-back | later, on real inactivity | — | not built |
| abandoned checkout | — | — | **not built** (see corrections) |

## Brevo
Narrow API adapter, not Brevo's WooCommerce plugin: transactional `POST /v3/smtp/email` (tags: transactional or
marketing, kind, country), contacts `POST /v3/contacts` (list per country, attributes COUNTRY, CONSENT_AT,
CONSENT_WORDING, SOURCE; `emailBlacklisted` true on any suppression). Webhook `POST /wp-json/ym/v1/mail-events`
(header `X-YM-Key` = YM_BREVO_WEBHOOK_KEY) for hard_bounce, invalid_email, blocked, spam, unsubscribed; each event
applies once. Order references are country-prefixed (EG-123, IQ-123), so two stores with the same numeric order ID
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
