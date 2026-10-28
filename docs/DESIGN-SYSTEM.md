# Design system: warm Yawmiyati (A's structure), 28 Oct 2026 evening

Review canvas: Claude artifact "Yawmiyati Store Design" (v2 boards: A and B, mobile and desktop). Source copies: `design/prototype-v2/`.
Built in `theme/yawmiyati` (tokens in `theme.json`, styles in `assets/css/ym.css`). Snapshot of the real build: Claude artifact "Yawmiyati Store Preview".

- Logo: `brand/logo.svg` on light surfaces.
- Colours: ivory page #FAF6F1 · white surfaces #FFFFFF · ink #2A2228 · muted #6E6268 · rule #EEE6E0 · berry actions #7B2D58 (hover #63224A) · berry tint #F6ECF1 (selected offer) · blush #F7E7E4 (home hero) · sage #E7EDE4 / sage ink #4C6448 (reassurance chips, COD box, savings) · diary paper #FFFDF9 · error #B42318.
- Images: white 4:5 frames, `object-fit: contain`, never cropped by CSS; galleries rectangular with a soft 14 px radius. Main image must show the complete product (media gate).
- Type: Readex Pro for everything; Aref Ruqaa only for the "ملاحظة من يومياتي" diary note, the one signature element.
- Product page order (built): gallery, name + one-line benefit, price + delivery, offer cards, order now + add to cart, diary note, fit check, benefits, specs, delivery and payment, sticky order bar on phones. Ordering happens on the separate checkout page; the product page has no embedded form.
- Home: blush hero with three whole-product frames and reassurance chips, then every product as a card (frame, name, benefit, price).
- Header: logo, country switcher (Egypt / Iraq with currency), cart. Footer: help line, delivery, returns, FAQ, contact, privacy, cookie settings.
- Rules: unknown specs show as [من تاجر] on local stores and are left out in production; no fake discounts, timers or reviews; Western digits for prices, Arabic numerals in the diary note.

- Checkout: one column on phones (name, mobile, second number, governorate, area and street, building, landmark), order summary and COD box beside the form from 960 px. Delivery fee appears as soon as a governorate is picked. No coupon field, no order notes.
- Errors: one list at the top plus a short line under each field, in the country's dialect.
