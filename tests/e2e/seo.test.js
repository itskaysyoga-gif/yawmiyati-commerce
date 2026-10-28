// Search and social metadata, read from the rendered HTML of the real pages.
// "As production" requests use the local test header X-YM-Test-Env, which renders a page as the live store would.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const h = require('./helpers');

// Market gates (config/markets.json) are off until Rashid approves a market; APPROVED renders as if approved.
const APPROVED = 'prepared,catalog,checkout';
async function get(url, env, follow = false, markets = APPROVED) {
  const headers = env ? { 'x-ym-test-env': env, ...(markets ? { 'x-ym-test-markets': markets } : {}) } : {};
  const res = await fetch(url, { headers, redirect: follow ? 'follow' : 'manual' });
  return { status: res.status, location: res.headers.get('location'), html: await res.text() };
}
const meta = (html, attr, name) => (html.match(new RegExp(`<meta ${attr}="${name}" content="([^"]*)"`)) || [])[1];
const link = (html, rel) => [...html.matchAll(new RegExp(`<link rel="${rel}"([^>]*)>`, 'g'))].map((m) => m[1]);
const robots = (html) => (html.match(/<meta name='robots' content='([^']*)'/) || html.match(/<meta name="robots" content="([^"]*)"/) || [])[1] || '';
const jsonld = (html) => [...html.matchAll(/<script type="application\/ld\+json">(.*?)<\/script>/gs)].map((m) => JSON.parse(m[1]));

test('Indexing: local and staging stores are noindex everywhere; robots.txt disallows all', async () => {
  for (const path of ['/', '/product/absorbent-mats/', '/delivery/']) {
    assert.match(robots((await get(h.BASE.eg + path)).html), /noindex/);
    assert.match(robots((await get(h.BASE.eg + path, 'staging')).html), /noindex/);
  }
  assert.match((await get(h.BASE.eg + '/robots.txt')).html, /Disallow: \//);
});

test('Indexing: as production, shop pages are indexable and cart, checkout and order pages are not', async () => {
  for (const path of ['/', '/product/absorbent-mats/', '/delivery/']) {
    assert.doesNotMatch(robots((await get(h.BASE.eg + path, 'production')).html), /noindex/, path);
  }
  for (const path of ['/cart/', '/checkout/', '/my-account/']) {
    // An empty checkout redirects to the cart; follow it, the landing page must be noindex too.
    assert.match(robots((await get(h.BASE.eg + path, 'production', true)).html), /noindex/, path);
  }
  assert.doesNotMatch((await get(h.BASE.eg + '/robots.txt', 'production')).html, /Disallow: \/\n/);
});

test('Indexing: a live store whose market is not approved stays noindex, disallows crawling and has no sitemap', async () => {
  for (const path of ['/', '/product/absorbent-mats/']) {
    assert.match(robots((await get(h.BASE.iq + path, 'production', false, '')).html), /noindex/, path);
  }
  assert.match((await get(h.BASE.iq + '/robots.txt', 'production', false, '')).html, /Disallow: \//);
  assert.equal((await get(h.BASE.iq + '/wp-sitemap.xml', 'production', false, '')).status, 404);
});

test('Product page: canonical, description, social preview and Product structured data', async () => {
  const { html } = await get(h.BASE.eg + '/product/absorbent-mats/?utm_source=x');
  const canon = link(html, 'canonical');
  assert.equal(canon.length, 1);
  assert.match(canon[0], /href="http:\/\/localhost:8081\/product\/absorbent-mats\/"/);
  assert.ok(meta(html, 'name', 'description').length > 20);
  assert.equal(meta(html, 'property', 'og:type'), 'product');
  assert.equal(meta(html, 'property', 'og:locale'), 'ar_EG');
  assert.match(meta(html, 'property', 'og:image'), /eg-mats-1\.jpg$/);
  assert.equal(meta(html, 'property', 'product:price:amount'), '399');
  assert.equal(meta(html, 'name', 'twitter:card'), 'summary_large_image');
  const products = jsonld(html).filter((d) => d['@type'] === 'Product');
  assert.equal(products.length, 1, 'exactly one Product block (WooCommerce default removed)');
  const p = products[0];
  assert.equal(p.sku, 'EG030106MKKT99');
  assert.equal(p.offers.price, 399);
  assert.equal(p.offers.priceCurrency, 'EGP');
  assert.equal(p.offers.shippingDetails.length, 3);
  assert.deepEqual(p.offers.shippingDetails.map((s) => s.shippingRate.value).sort(), [100, 105, 95]);
  assert.ok(p.offers.shippingDetails[0].shippingDestination.addressRegion.includes('C'));
  assert.equal(p.aggregateRating, undefined);
  assert.equal(p.review, undefined);
});

test('Product page as production: an unverified product is marked out of stock in structured data', async () => {
  const { html } = await get(h.BASE.iq + '/product/sink-rack/', 'production');
  const p = jsonld(html).find((d) => d['@type'] === 'Product');
  assert.equal(p.offers.availability, 'https://schema.org/OutOfStock');
  assert.equal(p.offers.shippingDetails.length, 1);
  assert.equal(p.offers.shippingDetails[0].shippingRate.value, 7000);
});

test('Home pages: hreflang between Egypt and Iraq, Organization data, no hreflang on products', async () => {
  for (const cc of ['eg', 'iq']) {
    const { html } = await get(h.BASE[cc] + '/');
    const alt = link(html, 'alternate').filter((l) => l.includes('hreflang'));
    assert.ok(alt.some((l) => l.includes('hreflang="ar-EG"') && l.includes('localhost:8081')), cc);
    assert.ok(alt.some((l) => l.includes('hreflang="ar-IQ"') && l.includes('localhost:8082')), cc);
    assert.ok(jsonld(html).some((d) => (d['@graph'] || []).some((g) => g['@type'] === 'Organization')));
  }
  const prod = await get(h.BASE.eg + '/product/absorbent-mats/');
  assert.equal(link(prod.html, 'alternate').filter((l) => l.includes('hreflang')).length, 0);
});

test('Secondary offers redirect to their product page', async () => {
  const r = await get(h.BASE.eg + '/product/absorbent-mats-2-sets/');
  assert.equal(r.status, 301);
  assert.match(r.location, /\/product\/absorbent-mats\/$/);
});

test('Sitemaps: product pages and support pages only; no cart, checkout or secondary offers; as production, unverified products are left out', async () => {
  const index = (await get(h.BASE.eg + '/wp-sitemap.xml')).html;
  assert.match(index, /wp-sitemap-posts-product-1\.xml/);
  assert.match(index, /wp-sitemap-posts-page-1\.xml/);
  assert.doesNotMatch(index, /users|taxonomies/);
  const products = (await get(h.BASE.eg + '/wp-sitemap-posts-product-1.xml')).html;
  assert.match(products, /product\/absorbent-mats\//);
  assert.doesNotMatch(products, /absorbent-mats-2-sets/);
  const pages = (await get(h.BASE.eg + '/wp-sitemap-posts-page-1.xml')).html;
  assert.match(pages, /\/delivery\//);
  assert.doesNotMatch(pages, /\/cart\/|\/checkout\//);
  const live = (await get(h.BASE.eg + '/wp-sitemap-posts-product-1.xml', 'production'));
  assert.doesNotMatch(live.html, /product\/absorbent-mats\//);
});
