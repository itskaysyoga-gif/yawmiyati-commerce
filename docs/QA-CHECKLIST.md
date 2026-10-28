# QA checklist (before any country goes live)

[x] = passes on the local stores and in CI (`npm test`, `npm run test:integration`). Every item still needs the same
check on staging with Taager linked, in **each** country that opens.

## Taager integration (staging, per country)
- [ ] Express, cart and bundle orders arrive in Taager with correct country, SKU, quantity, customer data and COD total.
- [ ] A double click or retry creates exactly one Taager order. (Local: [x] one WooCommerce order, including six copies at the same millisecond and a first request alive past 35 s.)
- [ ] A held order does not reach Taager until released. (Local: [x] never passes through processing at the usual hooks.)
- [ ] A missing reference shows as "unconfirmed", never "failed". (Local: [x])
- [ ] Status names sync back and map to stages; `ym_status_sync_verified` set.
- [x] Manual fallback cannot duplicate an order.
- [ ] 2-item order: delivery fee charged once (Taager said) confirmed on a real order.

## Storefront
- [x] 360/390 px layouts without sideways scroll; long Arabic titles; text at 200 %.
- [ ] Real phones: Android Chrome, iPhone Safari, Instagram and Facebook in-app browsers (consent accepted and declined).
- [x] Price tampering rejected; wrong-country address rejected; double taps safe; quantity limit enforced.
- [x] Final payable total shown before submission and updated for quantity, offer and governorate.
- [x] Out-of-stock and sold-out-during-checkout handled; retired products redirect.
- [x] Catalog pages set no cookie (cacheable per country); cart, checkout and confirmation are not cacheable.
- [ ] Host cache configured so no page is shared between country paths or with logged-in users.
- [x] Pixel and server events share IDs; retries keep ID and time; link tags stored on the order; withdrawal stops tags.
- [ ] Live receipt in GA4 DebugView, Meta Test Events, Snap (L1-L12).
- [x] Honeypot, rate limits on complete submissions, review holds with reason codes. Turnstile ready (off until keys).
