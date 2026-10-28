# Design system: direction A (recommended, 28 Oct 2026)

Review canvas: Claude artifact "Yawmiyati Store Design" (v2 boards: A and B, mobile and desktop). Source copies: `design/prototype-v2/`.
Built in `theme/yawmiyati` (tokens in `theme.json`, styles in `assets/css/ym.css`). Snapshot of the real build: Claude artifact "Yawmiyati Store Preview".

- Logo: `brand/logo.svg` (ink #1F1D1E); white on dark via the white file.
- Colours: paper #FFFFFF · ink #1F1D1E · nila (laundry-bluing indigo) #2B3192 for actions · nila tint #EEF0FA for info panels · rule #E7E5E1 · muted text #5E5A57 · diary paper #FBFAF7.
- Type: Readex Pro for everything; Aref Ruqaa only for the "ملاحظة من يومياتي" diary note, the one signature element.
- Product page order: image, name + one-line benefit, price + delivery, quantity/bundle choice, order + add-to-cart buttons, diary note, video, 3 benefits, specs, delivery and payment terms, FAQs, order form, footer, sticky order bar.
- Rules: unknown specs show as [من تاجر] on local stores and are left out in production; no fake discounts, timers or reviews; Western digits for prices, Arabic numerals in the diary note.

- Checkout: one column on phones (name, mobile, second number, governorate, area and street, building, landmark), order summary and COD box beside the form from 960 px. Delivery fee appears as soon as a governorate is picked. No coupon field, no order notes.
- Errors: one list at the top plus a short line under each field, in the country's dialect.
