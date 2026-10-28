# QA checklist (before any country goes live)

## Taager integration
- [ ] Express, cart and bundle orders arrive in Taager with correct country, SKU, quantity, customer data and COD total.
- [ ] A double-click or retry creates exactly one Taager order.
- [ ] A failed submission is visible in WooCommerce.
- [ ] Status sync behaviour recorded.
- [ ] Manual fallback cannot duplicate an order.
- [ ] 2-item order: delivery fee charged once or per item recorded.

## Storefront
- [ ] 360/390/430 px layouts; RTL; real phones; Instagram and Facebook in-app browsers.
- [ ] Price tampering rejected; wrong-country address rejected; double taps safe.
- [ ] Out-of-stock offer hidden; stale cache never shows another country's price.
- [ ] Pixel and server events deduplicated; link tags stored on the order.
- [ ] Bot check and rate limits on the form.
