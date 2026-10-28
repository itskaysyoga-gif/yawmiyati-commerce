// Saudi Arabia, UAE and Oman local stores: same plugin and theme, each country's currency, phones, regions and fees.
// Local stores take orders whatever the market gates say; the gates are tested as staging/production in submission.test.js.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const h = require('./helpers');

let browser;
before(async () => { browser = await chromium.launch(); });
after(async () => { await browser.close(); });

const cases = [
  { cc: 'sa', slug: 'corner-shelf', sku: 'SA030104APZ499', price: 99, fee: 28, currency: 'SAR', shown: /127\s*ر\.س/ },
  { cc: 'ae', slug: 'storage-bag', sku: 'UA030101HBCL99', price: 59, fee: 19, currency: 'AED', shown: /78\s*د\.إ/ },
  { cc: 'om', slug: 'dish-rack', sku: 'OM030403BCM399', price: 19.5, fee: 3, currency: 'OMR', shown: /22\.500\s*ر\.ع/ },
];

for (const c of cases) {
  test(`${c.cc.toUpperCase()}: order with the country's currency, phone format and delivery fee; total shown before submitting`, async () => {
    const { ctx, page } = await h.context(browser);
    const phone = h.phone[c.cc]();
    await page.goto(`${h.BASE[c.cc]}/product/${c.slug}/`);
    await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
    await h.fillCheckout(page, { ...h.CUSTOMER[c.cc], phone });
    assert.match(await page.locator('.order-total').innerText(), c.shown, 'final payable total visible before submission');
    await Promise.all([page.waitForURL(/order-received\/(\d+)/, { timeout: 30000 }), page.locator('#place_order').click()]);
    const id = Number(page.url().match(/order-received\/(\d+)/)[1]);
    const o = h.order(c.cc, id);
    assert.equal(o.currency, c.currency);
    assert.equal(o.country, c.cc.toUpperCase());
    assert.deepEqual(o.items, [{ sku: c.sku, qty: 1, total: c.price }]);
    assert.equal(o.shipping, c.fee);
    assert.equal(o.total, c.price + c.fee);
    assert.equal(o.phone, phone);
    await ctx.close();
  });
}

test('OM: an international or Arabic-digit phone is accepted and stored in national form', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(`${h.BASE.om}/product/dish-rack/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.om, phone: '+968 ٧١٢٣ ' + String(Date.now()).slice(-4) });
  await Promise.all([page.waitForURL(/order-received\/(\d+)/, { timeout: 30000 }), page.locator('#place_order').click()]);
  const o = h.order('om', Number(page.url().match(/order-received\/(\d+)/)[1]));
  assert.match(o.phone, /^7123\d{4}$/);
  await ctx.close();
});

test('SA: an Egyptian number is refused with the Saudi rule', async () => {
  const { ctx, page } = await h.context(browser);
  const before = h.orderCount('sa');
  await page.goto(`${h.BASE.sa}/product/corner-shelf/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.sa, phone: '01012345678' });
  await page.locator('#place_order').click();
  await page.waitForSelector('.woocommerce-error, .wc-block-components-notice-banner.is-error');
  assert.match(await page.locator('.woocommerce-error, .wc-block-components-notice-banner.is-error').innerText(), /سعودي/);
  assert.equal(h.orderCount('sa'), before);
  await ctx.close();
});
