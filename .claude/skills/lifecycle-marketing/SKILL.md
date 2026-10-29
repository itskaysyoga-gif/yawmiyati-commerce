---
name: lifecycle-marketing
description: CUSTOM (Yawmiyati). Use for anything touching customer email - consent, signup, suppression, transactional and marketing flows, Brevo.
---
# Lifecycle marketing (custom skill, Yawmiyati)

Spec: docs/EMAIL.md, code YM_Mail.
- Flows are off until tested (option ym_email_flows). Turning one on is a decision with evidence (test matrix in
  docs/EMAIL.md), not a default.
- Marketing needs her own unticked consent and a confirmed address. Orders, cookie choices and social follows never
  imply it. Phone-only orders never get a made-up email.
- Suppression is shared by all stores and strongest-wins (complained > bounced > unsubscribed). Imports and syncs
  never re-subscribe anyone.
- Caps across stores: 1 marketing email a day, 3 a week per person. Transactional wording follows the COD state
  (received is not confirmed, not paid).
- Never put customer data into prompts, git or URLs. Never import followers as an email list.
