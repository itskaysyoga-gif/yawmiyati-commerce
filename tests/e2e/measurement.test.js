// Measurement: consent gating, dataLayer events, no personal data, attribution capture, server-event payloads and
// deduplication IDs. Local only: GTM is intercepted, and Meta/Snap calls are stored as dry runs (no tokens, outbound
// HTTP blocked). The live checks (GTM preview, GA4 DebugView, Meta Test Events, Snap Events Manager) are in
// docs/MEASUREMENT.md and must pass before any ad spend.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const h = require('./helpers');

let browser;
before(async () => {
  browser = await chromium.launch();
  for (const cc of ['eg', 'iq']) {
    h.wp(cc, 'option', 'update', 'ym_gtm_id', 'GTM-TEST123');
    h.wp(cc, 'option', 'update', 'ym_meta_pixel_id', '1234567890');
    h.wp(cc, 'option', 'update', 'ym_snap_pixel_id', 'snap-test-pixel');
  }
});
after(async () => {
  for (const cc of ['eg', 'iq']) for (const k of ['ym_gtm_id', 'ym_meta_pixel_id', 'ym_snap_pixel_id']) h.wp(cc, 'option', 'delete', k);
  await browser.close();
});

// Record requests to Google Tag Manager without letting them leave the machine.
async function watchGTM(ctx) {
  const hits = [];
  await ctx.route('https://www.googletagmanager.com/**', (r) => { hits.push(r.request().url()); r.fulfill({ status: 200, body: '' }); });
  return hits;
}
const dataLayer = (page) => page.evaluate(() => JSON.parse(JSON.stringify(window.dataLayer)));
const events = (dl) => dl.filter((e) => e && e.event && !String(e.event).startsWith('gtm')).map((e) => e.event);
const runQueue = (cc) => h.wp(cc, 'action-scheduler', 'run', '--group=yawmiyati', '--batch-size=50');

test('Consent: banner shows on first visit and nothing measuring loads until she accepts', async () => {
  const { ctx, page } = await h.context(browser, { consent: null });
  const gtm = await watchGTM(ctx);
  await page.goto(`${h.BASE.eg}/`, { waitUntil: 'networkidle' });
  assert.equal(await page.locator('[data-ym-consent]').isVisible(), true);
  assert.equal(gtm.length, 0, 'GTM not requested before consent');
  await page.locator('[data-ym-consent-no]').click();
  await page.reload({ waitUntil: 'networkidle' });
  assert.equal(await page.locator('[data-ym-consent]').isVisible(), false);
  assert.equal(gtm.length, 0, 'GTM not requested after "essentials only"');
  await page.locator('[data-ym-consent-open]').click();
  await page.locator('[data-ym-consent-yes]').click();
  await page.waitForTimeout(300);
  assert.equal(gtm.length, 1, 'GTM requested right after accepting');
  assert.match(gtm[0], /id=GTM-TEST123/);
  const dl = await dataLayer(page);
  assert.ok(dl.some((e) => e && e[0] === 'consent' && e[1] === 'update' && e[2].ad_storage === 'granted'));
  await ctx.close();
});

test('Funnel events: view_item, add_to_cart, begin_checkout and purchase with a country-prefixed transaction ID, no personal data', async () => {
  const { ctx, page } = await h.context(browser, { consent: 'granted' });
  await watchGTM(ctx);
  const phone = h.phone.eg();
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`, { waitUntil: 'networkidle' });
  assert.ok(events(await dataLayer(page)).includes('view_item'));
  await page.locator('input[name="ym_offer"]').nth(1).check({ force: true });
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  const atCheckout = await dataLayer(page);
  assert.deepEqual(events(atCheckout).filter((e) => e !== 'ym_consent'), ['add_to_cart', 'begin_checkout']);
  const add = atCheckout.find((e) => e.event === 'add_to_cart');
  assert.equal(add.ecommerce.items[0].item_id, 'EG030108BUND99');
  assert.equal(add.ecommerce.value, 749);
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone });
  await Promise.all([page.waitForURL(/order-received\/(\d+)/), page.locator('#place_order').click()]);
  await page.waitForLoadState('networkidle');
  const id = Number(page.url().match(/order-received\/(\d+)/)[1]);
  const dl = await dataLayer(page);
  const purchase = dl.find((e) => e.event === 'purchase');
  assert.equal(purchase.ecommerce.transaction_id, `EG-${id}`);
  assert.equal(purchase.ym_event_id, `EG-${id}`);
  assert.equal(purchase.ecommerce.value, 749, 'value = products, without delivery');
  assert.equal(purchase.ecommerce.shipping, 95);
  // No personal data anywhere in the dataLayer.
  const text = JSON.stringify(dl);
  for (const pii of [phone, h.CUSTOMER.eg.name, h.CUSTOMER.eg.area, h.CUSTOMER.eg.building]) assert.ok(!text.includes(pii), `dataLayer leaks ${pii}`);
  // Reloading the order page does not count the purchase twice.
  await page.reload({ waitUntil: 'networkidle' });
  assert.ok(!events(await dataLayer(page)).includes('purchase'));
  await ctx.close();
});

test('Server events: with consent, Meta and Snap purchase payloads use the same ID as the browser and only a hashed phone', async () => {
  const { ctx, page } = await h.context(browser, { consent: 'granted' });
  await watchGTM(ctx);
  await ctx.addCookies([{ name: '_fbp', value: 'fb.1.1700000000.123456', url: h.BASE.iq }]);
  const phone = h.phone.iq();
  const id = await h.buy(page, 'iq', { slug: 'sink-rack', offer: 1, customer: { phone } });
  runQueue('iq');
  const o = h.order('iq', id);
  assert.equal(o.consent, 'granted');
  for (const [name, rec] of [['meta', o.capi_meta], ['snap', o.capi_snap]]) {
    assert.ok(rec, `${name} event recorded`);
    assert.equal(rec.status, 'dry_run', `${name} not sent from a local store`);
    assert.equal(rec.event_id, `IQ-${id}`);
    const ev = rec.payload.data[0];
    assert.equal(ev.event_id, `IQ-${id}`);
    assert.match(ev.user_data.ph[0], /^[0-9a-f]{64}$/);
    assert.equal(ev.custom_data.value, 58000);
    assert.equal(ev.custom_data.currency, 'IQD');
    const raw = JSON.stringify(rec.payload);
    for (const pii of [phone, phone.slice(1), h.CUSTOMER.iq.name, h.CUSTOMER.iq.area]) assert.ok(!raw.includes(pii), `${name} payload leaks ${pii}`);
  }
  assert.equal(o.capi_meta.payload.data[0].event_name, 'Purchase');
  assert.equal(o.capi_meta.payload.data[0].user_data.fbp, 'fb.1.1700000000.123456');
  assert.equal(o.capi_snap.payload.data[0].event_name, 'PURCHASE');
  // Hash of the phone in international form: 07xx… → 9647xx…
  const crypto = require('node:crypto');
  assert.equal(o.capi_meta.payload.data[0].user_data.ph[0], crypto.createHash('sha256').update('964' + phone.slice(1)).digest('hex'));
  await ctx.close();
});

test('Server events: "essentials only" sends nothing for that order', async () => {
  const { ctx, page } = await h.context(browser, { consent: 'denied' });
  const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
  runQueue('eg');
  const o = h.order('eg', id);
  assert.equal(o.consent, 'denied');
  assert.equal(o.capi_meta, null);
  assert.equal(o.capi_snap, null);
  await ctx.close();
});

test('Attribution: campaign tags are kept on the order; ad click IDs only with consent', async () => {
  const run = async (consent) => {
    const { ctx, page } = await h.context(browser, { consent });
    await watchGTM(ctx);
    await page.goto(`${h.BASE.eg}/?utm_source=instagram&utm_medium=social&utm_campaign=mats-oct&fbclid=FBCLID123`, { waitUntil: 'networkidle' });
    const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
    await ctx.close();
    return h.order('eg', id).attr;
  };
  const denied = await run('denied');
  assert.equal(denied.first.utm_source, 'instagram');
  assert.equal(denied.first.utm_campaign, 'mats-oct');
  assert.equal(denied.first.fbclid, undefined);
  const granted = await run('granted');
  assert.equal(granted.last.fbclid, 'FBCLID123');
});
