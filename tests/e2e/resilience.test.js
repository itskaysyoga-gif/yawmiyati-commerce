// Concurrency, retries, failed-order recovery, cart preservation, stock and order-page privacy.
// Needs the local stores with test hooks (dev/setup.sh) and four PHP workers per store (dev/serve.sh).
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const h = require('./helpers');
const ERR = '.wc-block-components-notice-banner.is-error, .woocommerce-error';

let browser;
before(async () => { browser = await chromium.launch(); });
after(async () => { await browser.close(); });

const FORM = { 'content-type': 'application/x-www-form-urlencoded; charset=UTF-8' };

// Fill a checkout and capture the request the browser would send, without sending it.
async function capturedCheckout(page, cc, slug, customer) {
  await page.goto(`${h.BASE[cc]}/product/${slug}/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER[cc], ...customer });
  let body;
  await page.route('**/?wc-ajax=checkout', (route) => { body = route.request().postData(); route.abort(); });
  await page.locator('#place_order').click();
  for (let i = 0; i < 50 && !body; i++) await page.waitForTimeout(100);
  await page.unroute('**/?wc-ajax=checkout');
  assert.ok(body, 'checkout request captured');
  return body;
}

test('Concurrency: three copies of one submission, each held 1.5 s on the server, overlap and make one order', async () => {
  const { ctx, page } = await h.context(browser);
  const before = h.orderCount('eg');
  const body = await capturedCheckout(page, 'eg', 'absorbent-mats', { phone: h.phone.eg() });
  const t0 = Date.now();
  const send = () => page.request.post(`${h.BASE.eg}/?wc-ajax=checkout`, { data: body, headers: { ...FORM, 'x-ym-test-delay': '1500' } }).then((r) => r.json());
  const results = await Promise.all([send(), send(), send()]);
  const elapsed = Date.now() - t0;
  // Served one after another this would take at least 4.5 s; in parallel it takes a little over 1.5 s.
  assert.ok(elapsed < 4000, `requests overlapped (elapsed ${elapsed} ms)`);
  assert.equal(h.orderCount('eg'), before + 1, JSON.stringify(results));
  const ok = results.filter((r) => r.result === 'success');
  assert.ok(ok.length >= 1);
  assert.equal(new Set(ok.map((r) => r.redirect.match(/order-received\/(\d+)/)[1])).size, 1);
  await ctx.close();
});

test('Concurrency: four different customers checking out at the same moment each get their own correct order', async () => {
  const before = h.orderCount('iq');
  const runs = await Promise.all([0, 1, 0, 1].map(async (offer) => {
    const { ctx, page } = await h.context(browser);
    const id = await h.buy(page, 'iq', { slug: 'sink-rack', offer, customer: { phone: h.phone.iq() } });
    await ctx.close();
    return { offer, id };
  }));
  assert.equal(h.orderCount('iq'), before + 4);
  assert.equal(new Set(runs.map((r) => r.id)).size, 4);
  for (const r of runs) {
    const o = h.order('iq', r.id);
    assert.equal(o.total, r.offer ? 65000 : 50000, `order ${r.id}`);
    assert.equal(o.items.length, 1);
  }
});

test('Recovery: order creation fails once, the same form submitted again creates exactly one order', async () => {
  const { ctx, page } = await h.context(browser);
  const before = h.orderCount('eg');
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone: h.phone.eg() });
  let failed = false;
  await page.route('**/?wc-ajax=checkout', (route) => {
    if (failed) return route.continue();
    failed = true;
    return route.continue({ headers: { ...route.request().headers(), 'x-ym-test-fail': 'create' } });
  });
  await page.locator('#place_order').click();
  await page.waitForSelector(ERR);
  assert.equal(h.orderCount('eg'), before, 'no order after the failure');
  // Same page, same one-time token: the retry must go through.
  await Promise.all([page.waitForURL(/order-received\/(\d+)/, { timeout: 30000 }), page.locator('#place_order').click()]);
  assert.equal(h.orderCount('eg'), before + 1);
  await ctx.close();
});

test('Recovery: a retry after a lost response lands on the existing order instead of creating another', async () => {
  const { ctx, page } = await h.context(browser);
  const body = await capturedCheckout(page, 'eg', 'absorbent-mats', { phone: h.phone.eg() });
  const before = h.orderCount('eg');
  const first = await page.request.post(`${h.BASE.eg}/?wc-ajax=checkout`, { data: body, headers: FORM }).then((r) => r.json());
  assert.equal(first.result, 'success');
  const retry = await page.request.post(`${h.BASE.eg}/?wc-ajax=checkout`, { data: body, headers: FORM }).then((r) => r.json());
  assert.equal(retry.result, 'success');
  assert.equal(retry.redirect, first.redirect);
  assert.equal(h.orderCount('eg'), before + 1);
  await ctx.close();
});

test('Recovery: the same token from another browser session is refused, not replayed', async () => {
  const a = await h.context(browser);
  const body = await capturedCheckout(a.page, 'eg', 'absorbent-mats', { phone: h.phone.eg() });
  const first = await a.page.request.post(`${h.BASE.eg}/?wc-ajax=checkout`, { data: body, headers: FORM }).then((r) => r.json());
  assert.equal(first.result, 'success');
  const b = await h.context(browser);
  await b.page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await Promise.all([b.page.waitForURL(/\/checkout\//), b.page.locator('button[value="buy"]').click()]);
  // Swap in b's own checkout nonce so only the token is foreign.
  const nonce = await b.page.inputValue('#woocommerce-process-checkout-nonce');
  const foreign = body.replace(/woocommerce-process-checkout-nonce=[^&]+/, 'woocommerce-process-checkout-nonce=' + nonce);
  const res = await b.page.request.post(`${h.BASE.eg}/?wc-ajax=checkout`, { data: foreign, headers: FORM }).then((r) => r.json());
  assert.notEqual(res.redirect, first.redirect);
  assert.equal(res.result, 'failure');
  await a.ctx.close();
  await b.ctx.close();
});

test('Buy now: checks out only the chosen offer and keeps the rest of the cart for later', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(`${h.BASE.eg}/product/double-clothes-rack/`);
  await Promise.all([page.waitForURL(/double-clothes-rack/), page.locator('button[value="cart"]').click()]);
  await page.goto(`${h.BASE.eg}/product/heater-2in1-remote/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  const lines = await page.locator('.woocommerce-checkout-review-order-table .cart_item').count();
  assert.equal(lines, 1, 'checkout shows only the buy-now item');
  assert.match(await page.locator('.ym-note').innerText(), /محفوظة/);
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone: h.phone.eg() });
  await Promise.all([page.waitForURL(/order-received\/(\d+)/, { timeout: 30000 }), page.locator('#place_order').click()]);
  const id = Number(page.url().match(/order-received\/(\d+)/)[1]);
  assert.deepEqual(h.order('eg', id).items.map((i) => i.sku), ['EG030301HAEE99']);
  await page.goto(`${h.BASE.eg}/cart/`);
  assert.match(await page.locator('.woocommerce-cart-form').innerText(), /ستاند ملابس/);
  await ctx.close();
});

test('Stock: an out-of-stock bundle is shown as unavailable and cannot be ordered; the rack alone still can', async () => {
  const bundle = h.wpEval('iq', 'echo wc_get_product_id_by_sku("IQ030501SDMN99");');
  h.wpEval('iq', `$p = wc_get_product(${bundle}); $p->set_stock_status("outofstock"); $p->save();`);
  try {
    const { ctx, page } = await h.context(browser);
    await page.goto(`${h.BASE.iq}/product/sink-rack/`);
    const radio = page.locator(`input[name="ym_offer"][value="${bundle}"]`);
    assert.equal(await radio.isDisabled(), true);
    assert.match(await page.locator('.ym-offer').nth(1).innerText(), /مو متوفر حاليًا/);
    // Force the disabled offer through the form anyway.
    await page.evaluate((id) => { const r = document.querySelector(`input[name="ym_offer"][value="${id}"]`); r.disabled = false; r.checked = true; }, bundle);
    await page.locator('button[value="buy"]').click();
    await page.waitForLoadState('networkidle');
    assert.doesNotMatch(page.url(), /checkout/);
    assert.match(await page.locator(ERR).innerText(), /مو متوفر/);
    const id = await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
    assert.equal(h.order('iq', id).total, 50000);
    await ctx.close();
  } finally {
    h.wpEval('iq', `$p = wc_get_product(${bundle}); $p->set_stock_status("instock"); $p->save();`);
  }
});

test('Privacy: the order page shows nothing without the order key, and no address or phone to another browser', async () => {
  const a = await h.context(browser);
  const phone = h.phone.eg();
  const id = await h.buy(a.page, 'eg', { slug: 'absorbent-mats', customer: { phone } });
  const o = h.order('eg', id);
  await a.ctx.close();
  const b = await h.context(browser);
  for (const url of [
    `${h.BASE.eg}/checkout/order-received/${id}/`,
    `${h.BASE.eg}/checkout/order-received/${id}/?key=wc_order_wrong`,
    `${h.BASE.eg}/checkout/order-received/${id}/?key=${o.key}`,
    `${h.BASE.eg}/my-account/view-order/${id}/`,
  ]) {
    await b.page.goto(url);
    const text = await b.page.locator('body').innerText();
    assert.ok(!text.includes(phone), `phone hidden at ${url}`);
    assert.ok(!text.includes(h.CUSTOMER.eg.area), `address hidden at ${url}`);
  }
  await b.ctx.close();
});
