// Shopping experience: social links, catalog browsing, search, curated home, product page quantity and totals,
// country switcher, image loading, long titles, large text, unavailable products, sticky bar, genuine reviews.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const h = require('./helpers');

let browser;
before(async () => { browser = await chromium.launch(); });
after(async () => { await browser.close(); });
const overflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
const ld = (html) => [...html.matchAll(/<script type="application\/ld\+json">(.*?)<\/script>/gs)].map((m) => JSON.parse(m[1]));

test('Social: Facebook and Instagram in the footer and on the contact page, with accessible names; Organization sameAs lists both', async () => {
  const { ctx, page } = await h.context(browser);
  for (const path of ['/', '/contact/']) {
    await page.goto(h.BASE.iq + path);
    for (const [k, url] of [['facebook', 'https://www.facebook.com/yawmiyatimag'], ['instagram', 'https://www.instagram.com/yawmiyatimag']]) {
      const a = page.locator(`[data-ym-social="${k}"]`).first();
      assert.equal(await a.getAttribute('href'), url);
      assert.ok((await a.getAttribute('aria-label')).length > 3);
    }
  }
  const org = ld(await (await fetch(h.BASE.iq + '/')).text()).find((x) => x['@graph']);
  assert.deepEqual(org['@graph'][0].sameAs, ['https://www.facebook.com/yawmiyatimag', 'https://www.instagram.com/yawmiyatimag']);
  await ctx.close();
});

test('Home: one focal image and one clear action, categories, a curated selection (at most 6), a tip and how ordering works; only the focal image loads eagerly', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(h.BASE.iq + '/');
  const cards = await page.locator('.ym-shop .ym-card').count();
  assert.ok(cards > 0 && cards <= 6, `cards ${cards}`);
  assert.ok(await page.locator('a.ym-more, a.ym-shop__all').count() >= 1);
  assert.ok(await page.locator('.ym-cats a').count() >= 2, 'category routes');
  assert.equal(await page.locator('.ym-hero img').count(), 1, 'one focal image, not a row of thumbnails');
  assert.equal(await page.locator('.ym-hero img[fetchpriority="high"]').count(), 1);
  const cta = page.locator('[data-ym-hero-cta]');
  assert.equal(await cta.isVisible(), true);
  assert.match(await cta.getAttribute('href'), /\/shop\/$/);
  const box = await cta.boundingBox();
  assert.ok(box.y + box.height <= 844, 'the main action is visible without scrolling on a phone');
  assert.equal(await page.locator('.ym-steps li').count(), 3);
  assert.ok((await page.locator('.ym-tip p').innerText()).length > 20);
  assert.equal(await page.locator('img[fetchpriority="high"]').count(), 1, 'only one image is prioritised');
  const gridLoading = await page.$$eval('.ym-grid img', (els) => els.map((e) => e.getAttribute('loading')));
  assert.ok(gridLoading.every((l) => l === 'lazy'), gridLoading.join());
  await ctx.close();
});

test('Catalog: paginated browsing, category filter, Arabic search, and a helpful empty result (noindex)', async () => {
  // 14 temporary products so the catalog needs a second page.
  const ids = h.wpEval('iq', `$out=array(); for($i=0;$i<14;$i++){ $p=new WC_Product_Simple(); $p->set_name("منتج تجربة للتصفح ".$i); $p->set_regular_price("10000"); $p->set_status("publish"); $p->set_category_ids(array(YM_Catalog::category_id("storage"))); $p->update_meta_data("_ym_taager_cost",5000); $p->save(); $out[]=$p->get_id(); } echo implode(",",$out);`);
  const { ctx, page } = await h.context(browser);
  try {
    await page.goto(h.BASE.iq + '/shop/');
    assert.equal(await page.locator('.ym-grid .ym-card').count(), 12);
    assert.ok(await page.locator('.ym-pages a').count() >= 1);
    await page.goto(h.BASE.iq + '/shop/page/2/');
    assert.ok(await page.locator('.ym-grid .ym-card').count() >= 1);
    await page.goto(h.BASE.iq + '/product-category/kitchen/');
    const names = await page.locator('.ym-card__name').allInnerTexts();
    assert.ok(names.length >= 1 && names.every((n) => !/تجربة للتصفح/.test(n)), names.join('|'));
    assert.equal(await page.locator('.ym-chips a[aria-current]').innerText(), 'المطبخ');
    await page.goto(h.BASE.iq + '/');
    await page.goto(h.BASE.iq + '/shop/?ym_find=1');
    await page.fill('#ym-s', 'رف');
    await Promise.all([page.waitForNavigation(), page.press('#ym-s', 'Enter')]);
    assert.ok((await page.locator('.ym-card__name').allInnerTexts()).some((n) => /رف/.test(n)));
    await page.goto(h.BASE.iq + '/?s=' + encodeURIComponent('غسالة صحون فضائية') + '&post_type=product');
    assert.equal(await page.locator('[data-ym-empty]').count(), 1);
    assert.match(await page.locator('[data-ym-empty]').innerText(), /ما لكينا/);
    const html = await (await fetch(h.BASE.iq + '/?s=x&post_type=product', { headers: { 'x-ym-test-env': 'production', 'x-ym-test-markets': 'prepared,catalog,checkout' } })).text();
    assert.match(html, /noindex/);
  } finally {
    h.wpEval('iq', `foreach(array(${ids}) as $id) wp_delete_post($id, true);`);
    await ctx.close();
  }
});

test('Product page: main image first and high priority, thumbnails lazy; breadcrumb shown and matching structured data', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(h.BASE.eg + '/product/absorbent-mats/');
  const main = page.locator('[data-ym-main]');
  assert.equal(await main.getAttribute('fetchpriority'), 'high');
  assert.equal(await main.getAttribute('loading'), 'eager');
  const thumbs = await page.$$eval('.ym-thumb img', (els) => els.map((e) => e.getAttribute('loading')));
  assert.ok(thumbs.every((l) => l === 'lazy'));
  const crumbs = await page.locator('.ym-crumbs li').allInnerTexts();
  const html = await (await fetch(h.BASE.eg + '/product/absorbent-mats/')).text();
  const bl = ld(html).find((x) => x['@type'] === 'BreadcrumbList');
  assert.deepEqual(bl.itemListElement.map((i) => i.name), crumbs.map((c) => c.trim()));
  await ctx.close();
});

test('Quantity and total: the product page shows the total with delivery for the chosen offer and quantity; the order matches', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(h.BASE.om + '/product/dish-rack/');
  assert.match(await page.locator('[data-ym-total]').innerText(), /22\.500/);
  await page.locator('[data-ym-qty="1"]').click();
  assert.match(await page.locator('[data-ym-total]').innerText(), /42\s*ر\.ع/);   // 2 × 19.500 + 3
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.om, phone: h.phone.om() });
  assert.match(await page.locator('.order-total').innerText(), /42\s*ر\.ع/);
  assert.match(await page.locator('.order-total th').innerText(), /المطلوب/);
  await Promise.all([page.waitForURL(/order-received/), page.locator('#place_order').click()]);
  const o = h.order('om', Number(page.url().match(/order-received\/(\d+)/)[1]));
  assert.equal(o.items[0].qty, 2);
  assert.equal(o.total, 42);
  await ctx.close();
});

test('Checkout total: changes with the governorate before submission (Cairo 95, Aswan 105)', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(h.BASE.eg + '/product/absorbent-mats/');
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone: h.phone.eg(), state: 'EGC' });
  assert.match(await page.locator('.order-total').innerText(), /494/);
  await page.evaluate(() => jQuery('#billing_state').val('EGASN').trigger('change'));
  await h.waitIdle(page);
  assert.match(await page.locator('.order-total').innerText(), /504/);
  await ctx.close();
});

test('Country switcher: explicit; with items in the cart it says the cart stays; the other store starts empty', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(h.BASE.eg + '/product/absorbent-mats/');
  await page.locator('button[value="cart"]').click();
  await page.waitForLoadState('networkidle');
  await page.locator('.ym-country summary').click();
  assert.ok(await page.locator('[data-ym-cart-stays]').isVisible());
  const links = await page.locator('.ym-country a[data-ym-switch]').count();
  assert.equal(links, 4, 'the four other local markets (all open on local stores)');
  await Promise.all([page.waitForNavigation(), page.locator('.ym-country a[hreflang="ar-IQ"]').click()]);
  assert.equal(await page.locator('.ym-cart__count').count(), 0, 'Iraq cart is empty');
  await page.goto(h.BASE.eg + '/');
  assert.equal(await page.locator('.ym-cart__count').innerText(), '1', 'Egypt cart untouched');
  // As production with only Egypt open, the switcher offers no closed market.
  const html = await (await fetch(h.BASE.eg + '/', { headers: { 'x-ym-test-env': 'production' } })).text();
  assert.doesNotMatch(html, /data-ym-switch/);
  await ctx.close();
});

test('Robustness: a long Arabic title, large text and an unavailable product keep the layout usable', async () => {
  const id = h.wpEval('eg', `$p=new WC_Product_Simple(); $p->set_name(str_repeat("منظّم أدراج المطبخ متعدد الاستخدامات بأقسام قابلة للتعديل ", 3)); $p->set_slug("long-title-test"); $p->set_regular_price("299"); $p->set_status("publish"); $p->set_featured(true); $p->set_menu_order(-1); $p->update_meta_data("_ym_taager_cost",100); $p->set_stock_status("outofstock"); $p->save(); echo $p->get_id();`);
  const { ctx, page } = await h.context(browser, { width: 360 });
  try {
    for (const path of ['/', '/shop/', '/product/long-title-test/']) {
      await page.goto(h.BASE.eg + path);
      assert.ok(await overflow(page) <= 0, `${path} overflows`);
    }
    assert.equal(await page.locator('button[value="buy"]').count(), 0, 'no buy button when unavailable');
    assert.match(await page.locator('.ym-out').innerText(), /مش متاح/);
    await page.goto(h.BASE.eg + '/shop/');
    assert.ok(await page.locator('.ym-card.is-out').count() >= 1);
    // Large text (200%) on the product page and checkout.
    await page.goto(h.BASE.eg + '/product/absorbent-mats/');
    await page.addStyleTag({ content: 'html{font-size:200% !important} body{font-size:32px !important}' });
    assert.ok(await overflow(page) <= 0, 'product page at 200% text');
    await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
    await page.addStyleTag({ content: 'html{font-size:200% !important} body{font-size:32px !important}' });
    assert.ok(await overflow(page) <= 4, 'checkout at 200% text');
  } finally {
    h.wpEval('eg', `wp_delete_post(${id}, true);`);
    await ctx.close();
  }
});

test('Sticky bar: hidden while the main order button is visible and while the cookie choice is open; the cookie panel never covers the order button', async () => {
  const { ctx, page } = await h.context(browser, { consent: null });
  await page.goto(h.BASE.iq + '/product/sink-rack/');
  assert.ok(await page.locator('[data-ym-consent]').isVisible());
  assert.equal(await page.locator('[data-ym-sticky]').isVisible(), false, 'not over the cookie panel');
  await page.locator('[data-ym-consent-no]').click();
  await page.locator('button[value="buy"]').scrollIntoViewIfNeeded();
  await page.waitForTimeout(200);
  assert.equal(await page.locator('[data-ym-sticky]').isVisible(), false, 'not while the real button is on screen');
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(200);
  assert.equal(await page.locator('[data-ym-sticky]').isVisible(), true, 'shown once the real button is off screen');
  // Checkout with the cookie panel still open: the order button can be reached and is not covered.
  const c2 = await h.context(browser, { consent: null });
  await c2.page.goto(h.BASE.iq + '/product/sink-rack/');
  await Promise.all([c2.page.waitForURL(/\/checkout\//), c2.page.locator('button[value="buy"]').click()]);
  await c2.page.waitForLoadState('networkidle');
  await c2.page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  const covered = await c2.page.evaluate(() => { const b = document.querySelector('#place_order').getBoundingClientRect(); const el = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); return !el || el.closest('#place_order') === null; });
  assert.equal(covered, false);
  await c2.ctx.close();
  await ctx.close();
});

test('Reviews: only after a verified delivery, through a signed link; held for moderation; shown as bought-and-received; no rating in structured data below 3', async () => {
  const { ctx, page } = await h.context(browser);
  const id = await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  assert.throws(() => h.wp('iq', 'ym', 'review-link', String(id)), /verified delivery/);
  h.wp('iq', 'ym', 'outcome', String(id), 'delivered', '--evidence=test');
  const link = h.wp('iq', 'ym', 'review-link', String(id));
  await page.goto(link.replace(/t=[0-9a-f]+/, 't=' + '0'.repeat(32)));
  assert.match(await page.locator('main').innerText(), /هالرابط مو شغال/);
  await page.goto(link);
  await page.locator('.ym-stars label').first().click();
  await page.fill('textarea[name="ym_text"]', 'الرف مرتب ومضبوط فوك السنك');
  await page.fill('input[name="ym_name"]', 'زينب');
  await Promise.all([page.waitForNavigation(), page.locator('.ym-review-form button[type="submit"]').click()]);
  assert.match(await page.locator('main').innerText(), /بعد ما نراجعه/);
  await page.goto(h.BASE.iq + '/product/sink-rack/');
  assert.doesNotMatch(await page.locator('body').innerText(), /مرتب ومضبوط/, 'not shown before approval');
  const pending = h.wp('iq', 'ym', 'reviews');
  const rid = pending.split('\n').find((l) => /مرتب/.test(l)).trim().split(/\s+/)[0];
  assert.throws(() => h.wp('iq', 'ym', 'reviews', 'reject', rid), /reason/);
  h.wp('iq', 'ym', 'reviews', 'approve', rid);
  await page.goto(h.BASE.iq + '/product/sink-rack/');
  assert.match(await page.locator('.ym-reviews').innerText(), /مرتب ومضبوط/);
  assert.match(await page.locator('.ym-reviews').innerText(), /اشترت واستلمت/);
  const product = ld(await (await fetch(h.BASE.iq + '/product/sink-rack/')).text()).find((x) => x['@type'] === 'Product');
  assert.equal(product.aggregateRating, undefined, 'fewer than 3 approved reviews: no rating markup');
  await page.goto(link);
  assert.match(await page.locator('main').innerText(), /دزيتي رأيج/);
  h.wpEval('iq', `wp_delete_comment(${rid}, true);`);
  await ctx.close();
});

test('Retired product: permanently redirected to its category and gone from the catalog and sitemap', async () => {
  const id = h.wpEval('eg', `$p=new WC_Product_Simple(); $p->set_name("منتج متوقف"); $p->set_slug("retired-test"); $p->set_sku("YM-RETIRE-TEST"); $p->set_regular_price("100"); $p->set_status("publish"); $p->set_category_ids(array(YM_Catalog::category_id("kitchen"))); $p->update_meta_data("_ym_taager_cost",50); $p->save(); echo $p->get_id();`);
  try {
    h.wp('eg', 'ym', 'retire', 'YM-RETIRE-TEST', '--reason=supplier discontinued');
    const r = await fetch(h.BASE.eg + '/product/retired-test/', { redirect: 'manual' });
    assert.equal(r.status, 301);
    assert.match(r.headers.get('location'), /product-category\/kitchen/);
    assert.doesNotMatch(await (await fetch(h.BASE.eg + '/wp-sitemap-posts-product-1.xml')).text(), /retired-test/);
  } finally {
    h.wpEval('eg', `wp_delete_post(${id}, true);`);
  }
});

test('Cart on a phone: lines as cards, no sideways scroll, delivery stated, and no "total due" that leaves delivery out', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(h.BASE.iq + '/product/sink-rack/');
  await Promise.all([page.waitForLoadState('networkidle'), page.locator('button[value="cart"]').click()]);
  await page.goto(h.BASE.iq + '/cart/', { waitUntil: 'networkidle' });
  assert.ok(await overflow(page) <= 0, 'cart overflows');
  assert.equal(await page.locator('.cart_totals tr.order-total').isVisible(), false, 'the cart cannot know the governorate: no total without delivery');
  assert.match(await page.locator('.cart_totals').innerText(), /7,000/);
  const img = await page.locator('td.product-thumbnail img').first().boundingBox();
  assert.ok(img.width <= 64.5, `thumbnail ${img.width}px`);
  await ctx.close();
});
