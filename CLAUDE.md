# Yawmiyati Commerce — instructions for Claude

Read `docs/PROJECT-STATE.md` first, then `docs/DECISIONS.md`. Update PROJECT-STATE.md at the end of every work session.

## What this is
COD stores for the Yawmiyati audience (Facebook ~2.7M, Instagram ~720K follows; mostly women 25-44; Egypt and Iraq largest).
Supplier: Taager, through its WooCommerce plugin, one Taager store per country. Owner: Rashid (sole operator, based in Lebanon).

## Hard rules
- Never invent product facts: specs, sizes, materials, capacities, certifications, origin. Unknown stays unknown.
- No fake reviews, timers, stock counters, follower claims or compare-at prices.
- No medical or guaranteed-result claims. Real third-party brands are held until authenticity is verified.
- Prices are validated on the server; the install's country is the only shipping country.
- Secrets live in environment variables or GitHub secrets, never in git or chat. Customer data never enters git.
- Money decisions (buying services, launching ads, raising budgets, publishing, changing live prices) need Rashid's yes.
  Claude may pause ads, lower budgets and hide out-of-stock offers on its own, and reports it the same day.
- Every price and catalog figure records its source and capture date.

## Conventions
- Arabic first, RTL everywhere. Egypt copy in Egyptian Arabic, Iraq copy in Iraqi Arabic; specs in plain standard Arabic.
- Latin URL slugs. Currencies: EGP, IQD (0 decimals), SAR, AED, OMR (3 decimals).
- Do not edit WooCommerce core. One SEO plugin, host caching only, no page builders.
- Renders and heavy jobs run in the cloud, never on Rashid's laptop.
