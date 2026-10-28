# Reviews

_29 Oct 2026. Code: `class-ym-reviews.php`. Tests: `tests/e2e/storefront.test.js`, `tests/integration/accounting.php`._

- Only an order with a **verified delivery** can be reviewed. Staff send the signed link themselves
  (`wp ym review-link <order>`, e.g. by WhatsApp after the delivery). The link carries an HMAC, never the order key.
- She keeps that right after a return or a full refund: a customer who received the item and sent it back has an
  honest review to give. A refusal at the door never qualifies (she never had it). An order whose history is in open
  conflict waits until it is resolved.
- The review page loads no measuring tags and sends no referrer (its link is a credential).
- One review per order and product; rating 1-5, text, optional first name.
- Every review waits for moderation: `wp ym reviews` (pending), `wp ym reviews approve <id>`,
  `wp ym reviews reject <id> --reason="…"` (reason kept). Reject only for personal data, abuse or off-topic text,
  never because it is negative.
- Shown on the product page with "bought and received". Structured data shows a rating only from 3 or more approved
  verified reviews.
- No fake reviews, no imported reviews, no admin path to write one, no review counts or stars anywhere until real
  ones exist.
