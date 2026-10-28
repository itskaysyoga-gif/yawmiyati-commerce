# QA checklist (before any country goes live)

[x] = passes on the local stores (`npm test`). It still needs the same check on staging with Taager linked before launch.

## Taager integration
- [ ] Express, cart and bundle orders arrive in Taager with correct country, SKU, quantity, customer data and COD total.
- [ ] A double-click or retry creates exactly one Taager order. (Local: [x] one WooCommerce order.)
- [ ] A failed submission is visible in WooCommerce. (Local: [x] supplier status "failed" after 15 min without a reference.)
- [ ] Status sync behaviour recorded.
- [x] Manual fallback cannot duplicate an order.
- [ ] 2-item order: delivery fee charged once or per item recorded.

## Storefront
- [ ] 360/390/430 px layouts; RTL; real phones; Instagram and Facebook in-app browsers.
- [x] Price tampering rejected; wrong-country address rejected; double taps safe.
- [ ] Out-of-stock offer hidden; stale cache never shows another country's price.
- [ ] Pixel and server events deduplicated; link tags stored on the order.
- [ ] Bot check and rate limits on the form.
