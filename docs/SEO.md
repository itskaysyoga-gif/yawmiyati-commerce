# SEO setup

Implemented in `plugin/yawmiyati-commerce/includes/class-ym-seo.php`, verified on rendered pages by
`tests/e2e/seo.test.js` and `storefront.test.js`. No SEO plugin is used.

## What the stores output
| Item | Behaviour |
| --- | --- |
| Indexing | Only a production store whose market "catalog" gate is on (docs/MARKETS.md) is indexable; a closed market is noindex, disallows crawling and has no sitemap. Local and staging: noindex on every page, robots disallow all. Production: cart, checkout, order pages, account, search and 404 are noindex. |
| Canonical | One per page, without query strings. Secondary offers 301 to their product page. |
| hreflang | Country home pages of open markets only (ar-EG, ar-IQ, ar-SA, ar-AE, ar-OM as they open), plus x-default to the chooser at the domain root (option `ym_xdefault_url`). Products: none, because no launch product is the same item in both countries; a product can opt in with meta `_ym_equivalent` once verified identical. |
| Sitemaps | `/eg/wp-sitemap.xml`, `/iq/wp-sitemap.xml`: product pages and support pages only. No users, no review page, no retired products, no cart/checkout/account, no secondary offers, and on staging/production no product the catalog gate blocks. |
| Structured data | Product: name, description, SKU, images, one Offer (price, currency, availability, condition, seller, delivery fee by region; Egypt regions as ISO 3166-2 codes). Rating only from 3+ approved reviews of verified deliveries (docs/REVIEWS.md); no return policy until verified. BreadcrumbList on product and category pages, identical to the breadcrumb shown. Home: Organization (sameAs Facebook and Instagram) + WebSite. WooCommerce's own Product markup is turned off to avoid duplicates. |
| Social previews | og:title, og:description, og:image (main product image; default image for other pages), og:locale ar_EG / ar_IQ, product price tags, twitter:card summary_large_image. |
| Titles and descriptions | "<page> | يومياتي"; descriptions from the product's benefit line, the category description or the page's first sentence. |
| Catalog and categories | `/shop/` and `/product-category/<slug>/` are indexable with their own canonical (paginated pages canonical to themselves); internal search results are noindex. |
| Unavailable products | Temporarily out of stock: page stays, Offer availability OutOfStock. Retired for good (`wp ym retire <sku> --reason=…`): 301 to the replacement or its category, removed from catalog and sitemap. |
| AI search | Nothing special: Google says no special markup or extra "AI files" are needed for its AI features. Accurate facts and useful pages are the work. |

## Domain root (outside WordPress)
Upload `deploy/root/index.html` (country chooser with hreflang) and `deploy/root/robots.txt` to the domain root
(`public_html/`). Crawlers read robots.txt only at the root, so the stores' private paths and both sitemaps are listed
there. Then set on both stores: `wp option update ym_xdefault_url https://yawmiyati.shop/` and
`wp ym setup --country=EG --store-urls='{"EG":"https://yawmiyati.shop/eg/","IQ":"https://yawmiyati.shop/iq/"}'`
(and the same with IQ on the Iraq store).

## Search Console (at launch, after the domain is live)
1. In Google Search Console add a **Domain property** for `yawmiyati.shop` and verify it with the DNS TXT record
   (SiteGround: Site Tools > Domain > DNS Zone Editor > TXT).
2. Add two **URL-prefix properties**, `https://yawmiyati.shop/eg/` and `https://yawmiyati.shop/iq/`, so each country
   has its own reports. They verify automatically through the domain property.
3. Submit `https://yawmiyati.shop/eg/wp-sitemap.xml` in the EG property and `.../iq/wp-sitemap.xml` in the IQ property.
4. URL Inspection on one product page per country: check "URL is on Google" eligibility, the canonical Google picked,
   and that Product markup is detected without errors. Run the Rich Results Test on the same pages.
5. Bing Webmaster Tools: import from Search Console (optional, five minutes).
6. Re-check after two weeks: Pages report (indexed vs excluded and why), Shopping/Product snippets report, hreflang
   errors (International Targeting is gone; errors appear in URL Inspection).

Staging must stay noindex. If staging was ever indexed, use Search Console's Removals tool on the staging host.
