// End-to-end order flow against the local stores (dev/serve.sh). Run: npm test
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const h = require('./helpers');
const ERR = '.wc-block-components-notice-banner.is-error, .woocommerce-error';

let browser;
before(async () => { browser = await chromium.launch(); });
after(async () => { await browser.close(); });

const fresh = (width = 390) => h.context(browser, { width });
const egPhone = h.phone.eg;
const iqPhone = h.phone.iq;

test('Egypt: 2-set offer to order, server price + Cairo delivery, COD, snapshot', async () => {
  const { ctx, page } = await fresh();
  const phone = egPhone();
  const phone2 = h.phone.eg().replace(/^010/, '011');
  const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', offer: 1, customer: { phone, phone2, landmark: 'جنب الصيدلية' } });
  const o = h.order('eg', id);
  assert.equal(o.status, 'processing');
  assert.equal(o.payment, 'cod');
  assert.equal(o.currency, 'EGP');
  assert.equal(o.country, 'EG');
  assert.deepEqual(o.items, [{ sku: 'EG030108BUND99', qty: 1, total: 749 }]);
  assert.equal(o.shipping, 95);
  assert.equal(o.total, 844);
  assert.equal(o.phone2, phone2);
  assert.equal(o.landmark, 'جنب الصيدلية');
  assert.equal(o.snapshot.policy_version, '2026-10-28');
  assert.equal(o.snapshot.items[0].unit_price, 749);
  assert.equal(o.supplier, 'pending');
  assert.match(await page.locator("body").innerText(), /استلمنا طلبك/);
  await ctx.close();
});

test('Egypt: delivery fee follows the governorate zone (Aswan = 105)', async () => {
  const { ctx, page } = await fresh();
  const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: egPhone(), state: 'EGASN' } });
  const o = h.order('eg', id);
  assert.equal(o.shipping, 105);
  assert.equal(o.total, 399 + 105);
  await ctx.close();
});

test('Iraq: rack alone is the default (50,000) and the bundle is optional (65,000)', async () => {
  const a = await fresh();
  const single = h.order('iq', await h.buy(a.page, 'iq', { slug: 'sink-rack', customer: { phone: iqPhone() } }));
  assert.deepEqual(single.items, [{ sku: 'IQ030403SENK99', qty: 1, total: 43000 }]);
  assert.equal(single.total, 50000);
  assert.equal(single.currency, 'IQD');
  await a.ctx.close();
  const b = await fresh();
  const bundle = h.order('iq', await h.buy(b.page, 'iq', { slug: 'sink-rack', offer: 1, customer: { phone: iqPhone() } }));
  assert.deepEqual(bundle.items, [{ sku: 'IQ030501SDMN99', qty: 1, total: 58000 }]);
  assert.equal(bundle.total, 65000);
  await b.ctx.close();
});

test('Validation: a wrong phone number shows the rule and creates no order', async () => {
  const { ctx, page } = await fresh();
  const before = h.orderCount('eg');
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone: '0123' });
  await page.locator('#place_order').click();
  await page.waitForSelector(ERR);
  assert.match(await page.locator(ERR).innerText(), /رقم موبايل مصري من 11 رقم/);
  assert.equal(h.orderCount('eg'), before);
  await ctx.close();
});

test('Tampering: an offer from another product group is refused', async () => {
  const { ctx, page } = await fresh();
  const heater = h.wpEval('eg', 'echo wc_get_product_id_by_sku("EG030301HAEE99");');
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await page.evaluate((id) => { document.querySelector('input[name="ym_offer"]').value = id; }, heater);
  await page.locator('input[name="ym_offer"]').first().check({ force: true });
  await page.locator('button[value="buy"]').click();
  await page.waitForLoadState('networkidle');
  assert.doesNotMatch(page.url(), /checkout/);
  assert.match(await page.locator(ERR).innerText(), /مش متاح/);
  await ctx.close();
});

test('Tampering: injected price fields and edited totals do not change the order', async () => {
  const { ctx, page } = await fresh();
  const id = await h.buy(page, 'eg', {
    slug: 'absorbent-mats',
    customer: { phone: egPhone() },
    beforeSubmit: (p) => p.evaluate(() => {
      const f = document.querySelector('form.checkout');
      for (const [k, v] of [['price', '1'], ['line_total', '1'], ['ym_price', '1'], ['shipping_cost', '0']]) {
        const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; f.appendChild(i);
      }
      document.querySelectorAll('.order-total .amount').forEach((el) => { el.textContent = '1'; });
    }),
  });
  const o = h.order('eg', id);
  assert.equal(o.items[0].total, 399);
  assert.equal(o.total, 399 + 95);
  await ctx.close();
});

test('Tampering: a governorate outside the country is refused', async () => {
  const { ctx, page } = await fresh();
  const before = h.orderCount('eg');
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, h.CUSTOMER.eg);
  await page.evaluate(() => { const s = document.querySelector('#billing_state'); s.add(new Option('x', 'BG')); s.value = 'BG'; });
  await page.locator('#place_order').click();
  await page.waitForSelector(ERR);
  assert.equal(h.orderCount('eg'), before);
  await ctx.close();
});

test('Double submission: the same checkout sent twice at once creates one order; the late copy lands on that order', async () => {
  const { ctx, page } = await fresh();
  const before = h.orderCount('eg');
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone: egPhone() });
  // Hold the browser's own submission and send the same request twice at the same time instead.
  let body;
  await page.route('**/?wc-ajax=checkout', (route) => { body = route.request().postData(); route.abort(); });
  await page.locator('#place_order').click();
  for (let i = 0; i < 50 && !body; i++) await page.waitForTimeout(100);
  assert.ok(body, 'checkout request captured');
  const send = () => page.request.post(`${h.BASE.eg}/?wc-ajax=checkout`, { data: body, headers: { 'content-type': 'application/x-www-form-urlencoded; charset=UTF-8' } }).then((r) => r.json());
  const results = await Promise.all([send(), send()]);
  assert.equal(h.orderCount('eg'), before + 1, JSON.stringify(results));
  const ok = results.filter((r) => r.result === 'success');
  assert.ok(ok.length >= 1, JSON.stringify(results));
  // Every success points at the same order; anything else is the "still sending" message, never a second order.
  assert.equal(new Set(ok.map((r) => r.redirect.match(/order-received\/(\d+)/)[1])).size, 1);
  results.filter((r) => r.result !== 'success').forEach((r) => assert.match(r.messages, /بيتسجل|اتسجل/));
  await ctx.close();
});

test('Double click: two clicks on the order button create one order', async () => {
  const { ctx, page } = await fresh();
  const before = h.orderCount('eg');
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone: egPhone() });
  await page.evaluate(() => { const b = document.querySelector('#place_order'); b.click(); b.click(); });
  await page.waitForURL(/order-received/, { timeout: 30000 });
  assert.equal(h.orderCount('eg'), before + 1);
  await ctx.close();
});

test('Duplicate review: same phone + same product within 24 h is held, not blocked', async () => {
  const phone = egPhone();
  const a = await fresh();
  const first = await h.buy(a.page, 'eg', { slug: 'absorbent-mats', customer: { phone } });
  await a.ctx.close();
  const b = await fresh();
  const second = await h.buy(b.page, 'eg', { slug: 'absorbent-mats', customer: { phone } });
  await b.ctx.close();
  assert.equal(h.order('eg', first).status, 'processing');
  const o = h.order('eg', second);
  assert.equal(o.status, 'on-hold');
  assert.equal(o.review, 'possible_duplicate');
});

test('Supplier hand-off: a missing reference is "unconfirmed", never "failed"; a plugin reference marks it sent', async () => {
  const a = await fresh();
  const id = await h.buy(a.page, 'eg', { slug: 'absorbent-mats', customer: { phone: egPhone() } });
  await a.ctx.close();
  assert.equal(h.wp('eg', 'ym', 'supplier-check', String(id)), 'unconfirmed');
  h.wpEval('eg', `$o = wc_get_order(${id}); $o->update_meta_data("_taager_order_id", "777"); $o->save();`);
  assert.equal(h.wp('eg', 'ym', 'supplier-check', String(id)), 'sent');
  assert.equal(h.order('eg', id).supplier_ref, '777');
  assert.throws(() => h.wp('eg', 'ym', 'supplier-manual', String(id), 'TG-2000'));
});

test('Iraq: an Egyptian phone format is refused', async () => {
  const { ctx, page } = await fresh();
  const before = h.orderCount('iq');
  await page.goto(`${h.BASE.iq}/product/sink-rack/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.iq, phone: '01012345678' });
  await page.locator('#place_order').click();
  await page.waitForSelector(ERR);
  assert.match(await page.locator(ERR).innerText(), /رقم موبايل عراقي/);
  assert.equal(h.orderCount('iq'), before);
  await ctx.close();
});

test('Checkout form: our fields in order, no coupon or notes, fee appears once a governorate is picked', async () => {
  const { ctx, page } = await fresh();
  await page.goto(`${h.BASE.iq}/product/sink-rack/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await page.waitForLoadState('networkidle');
  const ids = await page.$$eval('.woocommerce-billing-fields .form-row:not(.ym-hidden):not(.ym-phone2-add)', (els) => els.map((e) => e.id));
  assert.deepEqual(ids, ['billing_first_name_field', 'billing_phone_field', 'ym_phone2_field', 'billing_state_field', 'billing_address_1_field', 'billing_address_2_field', 'ym_landmark_field']);
  // The second phone opens on request, right where it belongs, and says "optional" once.
  assert.equal(await page.locator('#ym_phone2').isVisible(), false);
  await page.locator('[data-ym-phone2-add]').click();
  assert.equal(await page.locator('#ym_phone2').isVisible(), true);
  assert.equal(await page.locator('[data-ym-phone2-add]').isVisible(), false);
  assert.equal(((await page.locator('label[for="ym_phone2"]').innerText()).match(/اختياري/g) || []).length, 1);
  assert.match(await page.locator('[data-ym-call-note]').innerText(), /اتصال/, 'the phone confirmation is said next to the button');
  assert.equal(await page.locator('.woocommerce-form-coupon-toggle, #order_comments').count(), 0);
  assert.match(await page.locator('.woocommerce-shipping-totals td').innerText(), /اختاري المحافظة/);
  await page.evaluate(() => jQuery('#billing_state').val('BA').trigger('change'));
  await page.waitForFunction(() => /7,000/.test(document.querySelector('.woocommerce-shipping-totals td')?.innerText || ''), null, { timeout: 15000 });
  assert.match(await page.locator('.order-total').innerText(), /50,000/);
  await ctx.close();
});
