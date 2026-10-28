// Order-lifecycle edge cases not covered elsewhere, privacy and infrastructure checks, and the operations board.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const h = require('./helpers');

let browser;
before(async () => { browser = await chromium.launch(); });
after(async () => { await browser.close(); });

test('Back navigation after ordering: going back and pressing order again creates nothing new', async () => {
  const { ctx, page } = await h.context(browser);
  const before = h.orderCount('eg');
  await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
  await page.goBack();
  await page.waitForLoadState('networkidle');
  if (await page.locator('#place_order').count()) {
    await page.locator('#place_order').click();
    await page.waitForTimeout(2500);
  }
  assert.equal(h.orderCount('eg'), before + 1);
  await ctx.close();
});

test('Sold out during checkout: the order is refused with a clear message and nothing is created', async () => {
  const { ctx, page } = await h.context(browser);
  const before = h.orderCount('iq');
  await page.goto(`${h.BASE.iq}/product/heater-180/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.iq, phone: h.phone.iq() });
  h.wpEval('iq', '$p = wc_get_product(wc_get_product_id_by_sku("IQ030301SOBA99")); $p->set_stock_status("outofstock"); $p->save();');
  try {
    await page.locator('#place_order').click();
    await page.waitForSelector('.woocommerce-error, .wc-block-components-notice-banner.is-error', { timeout: 20000 });
    assert.equal(h.orderCount('iq'), before);
  } finally {
    h.wpEval('iq', '$p = wc_get_product(wc_get_product_id_by_sku("IQ030301SOBA99")); $p->set_stock_status("instock"); $p->save();');
    await ctx.close();
  }
});

test('Order links: guessing order numbers without the key shows no customer details', async () => {
  const { ctx, page } = await h.context(browser);
  const phone = h.phone.eg();
  const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone } });
  await ctx.close();
  for (let n = id - 3; n <= id; n++) {
    const html = await (await fetch(`${h.BASE.eg}/checkout/order-received/${n}/`)).text();
    assert.ok(!html.includes(phone) && !html.includes(h.CUSTOMER.eg.area), `order ${n} leaked details`);
  }
  // With the key but from another browser: still no details.
  const key = h.order('eg', id).key;
  const html = await (await fetch(`${h.BASE.eg}/checkout/order-received/${id}/?key=${key}`)).text();
  assert.ok(!html.includes(phone));
});

test('Logs: no phone number, name or address in the shop logs', async () => {
  const { ctx, page } = await h.context(browser, { consent: 'granted' });
  const phone = h.phone.iq();
  await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone } });
  await ctx.close();
  h.wp('iq', 'action-scheduler', 'run', '--hooks=ym_capi_send', '--batch-size=50');
  const dir = path.join(h.ROOT, 'dev/sites/iq/wp-content/uploads/wc-logs');
  const text = fs.existsSync(dir) ? fs.readdirSync(dir).map((f) => fs.readFileSync(path.join(dir, f), 'utf8')).join('\n') : '';
  for (const pii of [phone, phone.slice(1), h.CUSTOMER.iq.name, h.CUSTOMER.iq.area]) assert.ok(!text.includes(pii), `log contains ${pii}`);
  const debug = path.join(h.ROOT, 'dev/sites/iq/wp-content/debug.log');
  if (fs.existsSync(debug)) assert.ok(!fs.readFileSync(debug, 'utf8').includes(phone));
});

test('Ad platform failing: the event is retried with the same ID and time, then an alert; never resent once sent', async () => {
  h.wp('eg', 'option', 'update', 'ym_meta_pixel_id', '1234567890');
  h.wp('eg', 'option', 'update', 'ym_test_capi_http', '500');
  try {
    const { ctx, page } = await h.context(browser, { consent: 'granted' });
    const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
    await ctx.close();
    h.wpEval('eg', `YM_Tracking::send(${id}, "meta", "purchase");`);
    const first = h.order('eg', id).capi_meta;
    assert.equal(first.status, 'error');
    assert.equal(first.tries, 1);
    h.wpEval('eg', `YM_Tracking::send(${id}, "meta", "purchase");`);
    const second = h.order('eg', id).capi_meta;
    assert.equal(second.tries, 2);
    assert.equal(second.payload.data[0].event_id, first.payload.data[0].event_id);
    assert.equal(second.payload.data[0].event_time, first.payload.data[0].event_time);
    h.wp('eg', 'option', 'update', 'ym_test_capi_http', '200');
    h.wpEval('eg', `YM_Tracking::send(${id}, "meta", "purchase");`);
    assert.equal(h.order('eg', id).capi_meta.status, 'sent');
    h.wpEval('eg', `YM_Tracking::send(${id}, "meta", "purchase");`);
    assert.equal(h.order('eg', id).capi_meta.status, 'sent', 'not resent');
  } finally {
    h.wp('eg', 'option', 'delete', 'ym_test_capi_http');
    h.wp('eg', 'option', 'delete', 'ym_meta_pixel_id');
  }
});

test('Caching: catalog pages carry no visitor cookie (safe to cache per country); cart, checkout and confirmation are never cacheable', async () => {
  for (const p of ['/', '/shop/', '/product/sink-rack/', '/delivery/']) {
    const r = await fetch(h.BASE.iq + p);
    assert.equal(r.headers.get('set-cookie'), null, `${p} sets a cookie`);
  }
  const { ctx, page } = await h.context(browser);
  await page.goto(`${h.BASE.iq}/product/sink-rack/`);
  const [resp] = await Promise.all([page.waitForResponse((r) => /\/checkout\/$/.test(r.url())), page.locator('button[value="buy"]').click()]);
  assert.match(resp.headers()['cache-control'] || '', /no-store|no-cache|private/);
  await h.fillCheckout(page, { ...h.CUSTOMER.iq, phone: h.phone.iq() });
  const [thanks] = await Promise.all([page.waitForResponse((r) => /order-received/.test(r.url()) && r.request().resourceType() === 'document'), page.locator('#place_order').click()]);
  assert.match(thanks.headers()['cache-control'] || '', /no-store|no-cache|private/);
  // Each country's pages name only that country's currency.
  assert.doesNotMatch(await (await fetch(h.BASE.om + '/')).text(), /ج\.م|د\.ع/);
  await ctx.close();
});

test('Operations board: an operator can open it but not the plugins screen; a customer account cannot open it', async () => {
  const pass = 'Op-' + Date.now();
  h.wp('om', 'user', 'create', 'operator_t', 'op@example.test', '--role=ym_operator', `--user_pass=${pass}`, '--porcelain');
  h.wp('om', 'user', 'create', 'customer_t', 'cu@example.test', '--role=customer', `--user_pass=${pass}`, '--porcelain');
  try {
    const login = async (user) => {
      const { ctx, page } = await h.context(browser);
      await page.goto(`${h.BASE.om}/wp-login.php`);
      await page.fill('#user_login', user);
      await page.fill('#user_pass', pass);
      await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
      const r = await page.goto(`${h.BASE.om}/wp-admin/admin.php?page=ym-ops`);
      return { ctx, page, status: r.status() };
    };
    const op = await login('operator_t');
    assert.match(await op.page.locator('.wrap h1').innerText(), /Yawmiyati ops · OM/);
    assert.match(await op.page.locator('.wrap').innerText(), /Actions for Rashid/);
    const settings = await op.page.goto(`${h.BASE.om}/wp-admin/plugins.php`);
    assert.notEqual(settings.status(), 200, 'operator cannot manage plugins');
    await op.ctx.close();
    const cu = await login('customer_t');
    assert.doesNotMatch(await cu.page.content(), /Yawmiyati ops · OM/);
    await cu.ctx.close();
  } finally {
    h.wp('om', 'user', 'delete', 'operator_t', '--yes');
    h.wp('om', 'user', 'delete', 'customer_t', '--yes');
  }
});
