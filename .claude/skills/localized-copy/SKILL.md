---
name: localized-copy
description: CUSTOM (Yawmiyati). Use when writing or reviewing any customer-facing Arabic text for Egypt, Iraq or the Gulf stores, emails, captions or video scripts.
---
# Localized copy (custom skill, Yawmiyati)

- Voice guides: docs/voice/EG.md, IQ.md, GULF.md. Egyptian for Egypt, Iraqi for Iraq, plain standard Arabic for
  specs and policies. Address a woman; conversational benefits, precise policies; no exaggerated dialect spelling,
  no gendered slang.
- Text lives in plugin/yawmiyati-commerce/copy/<eg|iq|gulf>.json (same keys everywhere: tests/e2e/data.test.js) and
  content/<cc>/. Claude drafts; it never certifies fluency: every new key goes on docs/LANGUAGE-REVIEW.md for the
  native reviewer.
- Never: a fact not in `wp ym studio-facts`, numbers typed by hand in scripts, "best", "guaranteed", medical or germ
  claims, scarcity, deadlines, discounts, free delivery, testimonials, the supplier's name.
- COD wording: an order is a request until the confirmation call; payment happens to the courier on delivery; she may
  open the parcel after paying. Never promise inspection before payment.
- Studio scripts: studio/lib/rules.mjs checks them mechanically; a person still reads them.
