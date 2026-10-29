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
// Run queued server events through both phases (decide, every store certifies, send).
const runQueue = (cc) => h.serverEvents(cc);

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

test('Consent card: compact on a phone; "choose myself" allows analytics without advertising: GTM loads with ad storage denied, no click IDs, no server ad events', async () => {
  const { ctx, page } = await h.context(browser, { consent: null });
  const gtm = await watchGTM(ctx);
  await page.goto(`${h.BASE.eg}/?utm_source=facebook&fbclid=ANALYTICSONLY1`, { waitUntil: 'networkidle' });
  const card = page.locator('[data-ym-consent]');
  const box = await card.boundingBox();
  assert.ok(box.height <= 170, `card height ${box.height}`);
  assert.equal(await page.locator('[data-ym-consent-prefs]').isVisible(), false, 'details only on request');
  await page.locator('[data-ym-consent-more]').click();
  assert.equal(await page.locator('[data-ym-consent-analytics]').isChecked(), false, 'nothing pre-ticked');
  assert.equal(await page.locator('[data-ym-consent-ads]').isChecked(), false);
  await page.locator('[data-ym-consent-analytics]').check();
  await page.locator('[data-ym-consent-save]').click();
  assert.equal(await card.isVisible(), false);
  assert.ok((await ctx.cookies(h.BASE.eg)).some((c) => c.name === 'ym_consent' && c.value === 'analytics'));
  await page.waitForTimeout(300);
  assert.ok(gtm.length >= 1, 'analytics tags may load');
  const upd = (await dataLayer(page)).filter((e) => e && e[0] === 'consent' && e[1] === 'update');
  assert.equal(upd.at(-1)[2].analytics_storage, 'granted');
  assert.equal(upd.at(-1)[2].ad_storage, 'denied');
  assert.equal(upd.at(-1)[2].ad_user_data, 'denied');
  // A later visit does not ask again.
  await page.goto(`${h.BASE.eg}/shop/`, { waitUntil: 'networkidle' });
  assert.equal(await card.isVisible(), false);
  const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
  const o = h.order('eg', id);
  assert.equal(o.consent, 'analytics');
  assert.equal(o.attr.first.fbclid, undefined, 'no ad click ID without advertising consent');
  assert.equal(o.attr.first.utm_source, 'facebook');
  runQueue('eg');
  assert.equal(h.order('eg', id).capi_meta, null, 'no server ad event');
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
  // The order key never stays in the address bar where tags could read it.
  assert.doesNotMatch(page.url(), /key=/);
  assert.ok(dl.every((e) => !e || !e.ym_page_location || !/[?#]/.test(e.ym_page_location)));
  assert.equal(await page.locator('meta[name="referrer"]').getAttribute('content'), 'origin');
  // The purchase is marked as handed to GTM only by the browser's acknowledgement, after GTM loaded.
  for (let i = 0; i < 30 && !h.order('eg', id).dl_purchase; i++) await page.waitForTimeout(100);
  assert.ok(h.order('eg', id).dl_purchase, 'acknowledged after GTM loaded');
  // Reloading the order page does not count the purchase twice, and still shows her order (key kept in session).
  await page.reload({ waitUntil: 'networkidle' });
  assert.ok(!events(await dataLayer(page)).includes('purchase'));
  assert.match(await page.locator('body').innerText(), new RegExp(String(id)));
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

test('Purchase marker: rendering the confirmation page is not evidence; the marker waits for GTM to load', async () => {
  // She has not chosen yet: GTM does not load, so the purchase stays unacknowledged and is pushed again later.
  const { ctx, page } = await h.context(browser, { consent: null });
  const gtm = await watchGTM(ctx);
  const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
  await page.waitForLoadState('networkidle');
  assert.equal(gtm.length, 0);
  assert.ok(events(await dataLayer(page)).includes('purchase'));
  await page.waitForTimeout(400);
  assert.equal(h.order('eg', id).dl_purchase, '', 'not marked on render');
  // She accepts on the confirmation page: GTM loads, processes the earlier push, and only then the marker is set.
  await page.locator('[data-ym-consent-yes]').click();
  for (let i = 0; i < 30 && !h.order('eg', id).dl_purchase; i++) await page.waitForTimeout(100);
  assert.equal(gtm.length, 1);
  assert.ok(h.order('eg', id).dl_purchase, 'marked after GTM loaded');
  // A forged acknowledgement is refused.
  const res = await page.request.post(`${h.BASE.eg}/wp-json/ym/v1/dl-ack`, { data: { tx: `EG-${id}`, ack: 'x'.repeat(32) } });
  assert.equal(res.status(), 403);
  await ctx.close();
});

test('Consent withdrawal: loaded tags stop (reload, cookies deleted), and queued server events for her orders are cancelled', async () => {
  const { ctx, page } = await h.context(browser, { consent: null });
  const gtm = await watchGTM(ctx);
  await page.goto(`${h.BASE.iq}/`, { waitUntil: 'networkidle' });
  await page.locator('[data-ym-consent-yes]').click();
  await page.waitForTimeout(300);
  assert.equal(gtm.length, 1);
  // Measurement cookies a loaded tag would have set.
  await ctx.addCookies([{ name: '_ga', value: 'GA1.1.1.1', url: h.BASE.iq }, { name: '_fbp', value: 'fb.1.1.1', url: h.BASE.iq }]);
  const id = await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  const o = h.order('iq', id);
  assert.equal(o.consent, 'granted');
  assert.match(o.consent_subject, /^[0-9a-f]{32}$/);
  // Another customer orders with consent before she withdraws; her withdrawal must not touch that order.
  const other = await h.context(browser, { consent: 'granted' });
  await watchGTM(other.ctx);
  const otherId = await h.buy(other.page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  await other.ctx.close();
  // Server events are queued but not sent yet. She now withdraws.
  await page.goto(`${h.BASE.iq}/`, { waitUntil: 'networkidle' });
  const before = gtm.length;
  await page.locator('[data-ym-consent-open]').first().click();
  await Promise.all([page.waitForEvent('load'), page.locator('[data-ym-consent-no]').click()]);
  await page.waitForLoadState('networkidle');
  assert.equal(gtm.length, before, 'GTM not loaded again after withdrawal');
  const names = (await ctx.cookies(h.BASE.iq)).map((c) => c.name);
  assert.ok(!names.includes('_ga') && !names.includes('_fbp'), `measurement cookies deleted: ${names}`);
  const dl = await dataLayer(page);
  assert.ok(!dl.some((e) => e && e[0] === 'consent' && e[1] === 'update' && e[2].ad_storage === 'granted'), 'fresh page: no granted consent');
  assert.equal(h.order('iq', id).consent, 'withdrawn');
  assert.equal(h.order('iq', otherId).consent, 'granted', 'another customer\'s order untouched');
  runQueue('iq');
  const after = h.order('iq', id);
  assert.ok(!after.capi_meta || after.capi_meta.status === 'skipped', JSON.stringify(after.capi_meta));
  assert.ok(!after.capi_snap || after.capi_snap.status === 'skipped');
  // Even if a send had already been queued elsewhere, sending re-checks consent.
  h.wpEval('iq', `YM_Tracking::send(${id}, "meta", "purchase");`);
  assert.equal(h.order('iq', id).capi_meta.status, 'skipped');
  assert.equal(h.order('iq', id).capi_meta.reason, 'no_consent');
  await ctx.close();
});

test('Late consent: click IDs from the landing visit are kept if she accepts later in that visit, dropped if she declines', async () => {
  const run = async (choice) => {
    const { ctx, page } = await h.context(browser, { consent: null });
    await watchGTM(ctx);
    await page.goto(`${h.BASE.eg}/?utm_source=facebook&fbclid=LATE123`, { waitUntil: 'networkidle' });
    const cookieBefore = (await ctx.cookies(h.BASE.eg)).find((c) => c.name === 'ym_attr');
    assert.ok(!cookieBefore || !decodeURIComponent(cookieBefore.value).includes('LATE123'), 'click ID not stored in a cookie before consent');
    await page.goto(`${h.BASE.eg}/product/absorbent-mats/`, { waitUntil: 'networkidle' });
    await page.locator(choice === 'yes' ? '[data-ym-consent-yes]' : '[data-ym-consent-no]').click();
    const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
    await ctx.close();
    return h.order('eg', id).attr;
  };
  const yes = await run('yes');
  assert.equal(yes.first.fbclid, 'LATE123');
  assert.equal(yes.first.utm_source, 'facebook');
  const no = await run('no');
  assert.equal(no.first.fbclid, undefined);
  assert.equal(no.first.utm_source, 'facebook');
});

test('Delivered: a "completed" status alone sends nothing; a verified delivery sends OrderDelivered with its own time, stable on retry', async () => {
  const { ctx, page } = await h.context(browser, { consent: 'granted' });
  await watchGTM(ctx);
  const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
  await ctx.close();
  h.wp('eg', 'wc', 'shop_order', 'update', String(id), '--status=completed', '--user=1');
  runQueue('eg');
  assert.equal(h.order('eg', id).capi_meta_delivered, null, 'no OrderDelivered from an unmapped status');
  const at = '2026-10-27 15:30';
  h.wp('eg', 'ym', 'outcome', String(id), 'delivered', `--at=${at}`, '--evidence=test: courier delivered');
  runQueue('eg');
  const first = h.order('eg', id).capi_meta_delivered;
  assert.ok(first, 'OrderDelivered recorded');
  const ev = first.payload.data[0];
  assert.equal(ev.event_name, 'OrderDelivered');
  assert.equal(ev.event_id, `EG-${id}-delivered`);
  const recorded = h.order('eg', id).events.find((e) => e.e === 'delivered');
  assert.equal(ev.event_time, recorded.at, 'event time = when it was delivered, not when it was sent');
  // Retry later: same ID and same time.
  h.wpEval('eg', `YM_Tracking::send(${id}, "meta", "delivered");`);
  const again = h.order('eg', id).capi_meta_delivered.payload.data[0];
  assert.equal(again.event_id, ev.event_id);
  assert.equal(again.event_time, ev.event_time);
});

test('Order key: a returning visitor with consent already granted never exposes the key to a tag that runs at once', async () => {
  const { ctx, page } = await h.context(browser, { consent: 'granted' });
  // The "GTM container" here is a tag that executes the moment it loads and records what it can see.
  await ctx.route('https://www.googletagmanager.com/**', (r) => r.fulfill({
    status: 200, contentType: 'application/javascript',
    body: 'window.__tags = (window.__tags || []); window.__tags.push({href: location.href, ref: document.referrer, dl: JSON.stringify(window.dataLayer)});',
  }));
  // Every document this origin ever creates records its address before any page script runs.
  await ctx.addInitScript(() => { try { const a = JSON.parse(localStorage.getItem('__docs') || '[]'); a.push({ href: location.href, ref: document.referrer }); localStorage.setItem('__docs', JSON.stringify(a)); } catch (e) {} });
  const rendered = [];
  page.on('response', (res) => { if (res.request().resourceType() === 'document') rendered.push({ url: res.url(), status: res.status() }); });
  const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
  await page.waitForLoadState('networkidle');
  const key = h.order('eg', id).key;
  // The emailed/bookmarked link with the key, opened again in the same browser.
  await page.goto(`${h.BASE.eg}/checkout/order-received/${id}/?key=${key}`, { waitUntil: 'networkidle' });
  assert.doesNotMatch(page.url(), /key=/);
  assert.match(await page.locator('body').innerText(), new RegExp(String(id)), 'her order still shows (key kept in her session)');
  const tags = await page.evaluate(() => window.__tags || []);
  assert.ok(tags.length >= 1, 'the tag ran on the confirmation page');
  const docs = await page.evaluate(() => JSON.parse(localStorage.getItem('__docs') || '[]'));
  for (const seen of [...tags, ...docs]) {
    assert.doesNotMatch(seen.href, /wc_order_|key=/, `key visible at ${seen.href}`);
    assert.doesNotMatch(seen.ref || '', /wc_order_|key=/, `key in referrer ${seen.ref}`);
    if (seen.dl) assert.doesNotMatch(seen.dl, /wc_order_/);
  }
  // A page carrying the key in its address was never rendered: the server answered with a redirect.
  for (const r of rendered.filter((x) => /key=wc_order_/.test(x.url))) assert.equal(r.status, 302, `rendered ${r.url}`);
  assert.ok(rendered.some((x) => /key=wc_order_/.test(x.url)), 'the key URL was requested (and redirected)');
  assert.ok(!(await page.evaluate(() => performance.getEntriesByType('navigation').map((n) => n.name).join(' '))).includes('key='));
  // Our own logs never record the key (the host's access log sees the first request line: docs/MEASUREMENT.md).
  const fs = require('node:fs'); const path = require('node:path');
  const logDir = path.join(h.ROOT, 'dev/sites/eg/wp-content/uploads/wc-logs');
  const logs = fs.existsSync(logDir) ? fs.readdirSync(logDir).map((f) => fs.readFileSync(path.join(logDir, f), 'utf8')).join('\n') : '';
  assert.ok(!logs.includes(key), 'order key in the store logs');
  const redirect = await fetch(`${h.BASE.eg}/checkout/order-received/${id}/?key=${key}`, { redirect: 'manual' });
  assert.equal(redirect.status, 302);
  assert.match(redirect.headers.get('cache-control') || '', /no-store/, 'the key answer is never cached');
  // The review page (its link is a credential) loads no tag at all and sends no referrer.
  await page.goto(`${h.BASE.eg}/review/?o=1&t=${'0'.repeat(32)}`, { waitUntil: 'networkidle' });
  assert.equal((await page.evaluate(() => window.__tags || [])).length, 0, 'no tag on the review page');
  assert.equal(await page.locator('meta[name="referrer"]').getAttribute('content'), 'no-referrer');
  await ctx.close();
});

test('Consent withdrawal across stores: withdrawing in Egypt stops queued server events for her Iraq order; a later order after accepting again is sent', async () => {
  const { ctx, page } = await h.context(browser, { consent: null });
  await watchGTM(ctx);
  await page.goto(`${h.BASE.iq}/`, { waitUntil: 'networkidle' });
  await page.locator('[data-ym-consent-yes]').click();
  const id = await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  assert.equal(h.order('iq', id).consent, 'granted');
  // Another customer who also accepted, in Iraq: must not be affected.
  const other = await h.context(browser, { consent: null });
  await watchGTM(other.ctx);
  await other.page.goto(`${h.BASE.iq}/`, { waitUntil: 'networkidle' });
  // (separate browser profile: give it its own subject by accepting there)
  await other.page.locator('[data-ym-consent-yes]').click();
  const otherId = await h.buy(other.page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  await other.ctx.close();
  assert.notEqual(h.order('iq', otherId).consent_subject, h.order('iq', id).consent_subject);
  // Her Iraq events are queued, not sent. She now withdraws in the Egypt store (same domain, same cookies).
  await page.goto(`${h.BASE.eg}/`, { waitUntil: 'networkidle' });
  await page.locator('[data-ym-consent-open]').first().click();
  await Promise.all([page.waitForEvent('load'), page.locator('[data-ym-consent-no]').click()]);
  await page.waitForLoadState('networkidle');
  assert.equal(h.order('iq', id).consent, 'granted', 'Iraq was not told directly');
  runQueue('iq');
  const after = h.order('iq', id);
  assert.equal(after.capi_meta.status, 'skipped', JSON.stringify(after.capi_meta));
  assert.equal(after.capi_meta.reason, 'consent_withdrawn');
  assert.equal(after.capi_snap.status, 'skipped');
  assert.equal(after.consent, 'withdrawn');
  assert.equal(h.order('iq', otherId).capi_meta.status, 'dry_run', 'the other customer\'s event still goes out');
  // She accepts again later and orders in Iraq: that new order is sent; the old one stays withdrawn.
  await page.locator('[data-ym-consent-open]').first().click();
  await page.locator('[data-ym-consent-yes]').click();
  await page.waitForTimeout(1100); // the new order must be placed after the withdrawal second
  const later = await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  runQueue('iq');
  assert.equal(h.order('iq', later).capi_meta.status, 'dry_run');
  assert.equal(h.order('iq', id).capi_meta.status, 'skipped');
  await ctx.close();
});

test('Consent ledger unreachable: server events wait instead of sending, then go out once it answers', async () => {
  const { ctx, page } = await h.context(browser, { consent: null });
  await watchGTM(ctx);
  await page.goto(`${h.BASE.iq}/`, { waitUntil: 'networkidle' });
  await page.locator('[data-ym-consent-yes]').click();
  const id = await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  await ctx.close();
  h.wp('iq', 'option', 'update', 'ym_test_ledger_down', '1');
  try {
    h.sendNow('iq', id, 'meta', 'purchase');
    assert.equal(h.order('iq', id).capi_meta, null, 'nothing sent or recorded while consent cannot be checked');
    assert.equal(h.wpEval('iq', `echo as_has_scheduled_action("ym_capi_send", array(${id}, "meta", "purchase"), "yawmiyati") ? "yes" : "no";`), 'yes', 'retry scheduled');
  } finally {
    h.wp('iq', 'option', 'delete', 'ym_test_ledger_down');
  }
  h.sendNow('iq', id, 'meta', 'purchase');
  assert.equal(h.order('iq', id).capi_meta.status, 'dry_run');
});

// 29 Oct brief §3: the shared ledger can still be READ by every store while Egypt's WRITE fails (read-only
// transaction, failed INSERT). Egypt keeps the withdrawal queued locally and cannot certify; Iraq must not send.
test('Consent partial failure: Egypt cannot write the ledger, Iraq can read it; her queued Iraq event is never sent, then skipped once Egypt flushes', async () => {
  const { ctx, page } = await h.context(browser, { consent: null });
  await watchGTM(ctx);
  await page.goto(`${h.BASE.iq}/`, { waitUntil: 'networkidle' });
  await page.locator('[data-ym-consent-yes]').click();
  const id = await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  const subject = h.order('iq', id).consent_subject;
  // Another Iraq customer who accepted: held too while any store cannot certify (fail closed), sent afterwards.
  const other = await h.context(browser, { consent: null });
  await watchGTM(other.ctx);
  await other.page.goto(`${h.BASE.iq}/`, { waitUntil: 'networkidle' });
  await other.page.locator('[data-ym-consent-yes]').click();
  const otherId = await h.buy(other.page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  await other.ctx.close();
  h.wp('eg', 'option', 'update', 'ym_test_ledger_readonly', '1');
  try {
    await page.goto(`${h.BASE.eg}/`, { waitUntil: 'networkidle' });
    await page.locator('[data-ym-consent-open]').first().click();
    await Promise.all([page.waitForEvent('load'), page.locator('[data-ym-consent-no]').click()]);
    await page.waitForLoadState('networkidle');
    assert.equal(h.wpEval('iq', `echo YM_Consent::withdrawn_at("${subject}");`), '0', 'Iraq reads the ledger fine and sees no withdrawal');
    assert.equal(h.wpEval('eg', `echo (int) ( YM_Consent::pending()["${subject}"] ?? 0 ) > 0 ? "queued" : "lost";`), 'queued');
    h.serverEvents('iq'); // decide, every store certifies (Egypt cannot), try to send
    h.serverEvents('iq'); // and once more
    const held = h.order('iq', id);
    assert.equal(held.capi_meta, null, `her event must not be dispatched: ${JSON.stringify(held.capi_meta)}`);
    assert.equal(held.capi_snap, null);
    assert.equal(h.order('iq', otherId).capi_meta, null, 'every store holds while Egypt cannot certify');
    assert.equal(h.wpEval('iq', `echo as_get_scheduled_actions(array("hook"=>"ym_capi_send","args"=>array(${id},"meta","purchase"),"status"=>ActionScheduler_Store::STATUS_PENDING),"ids") ? "waiting" : "dropped";`), 'waiting', 'the event waits, it is not dropped');
  } finally {
    h.wp('eg', 'option', 'delete', 'ym_test_ledger_readonly');
  }
  // Egypt's write works again: its next certification flushes the withdrawal; Iraq skips her event, sends the other.
  h.serverEvents('iq');
  assert.equal(h.wpEval('eg', `echo isset( YM_Consent::pending()["${subject}"] ) ? "queued" : "flushed";`), 'flushed');
  const after = h.order('iq', id);
  assert.equal(after.capi_meta.status, 'skipped', JSON.stringify(after.capi_meta));
  assert.equal(after.capi_meta.reason, 'consent_withdrawn');
  assert.equal(after.consent, 'withdrawn');
  assert.equal(h.order('iq', otherId).capi_meta.status, 'dry_run');
  await ctx.close();
});

test('Consent partial failure: when the sending store itself cannot write the ledger, it sends nothing', async () => {
  const { ctx, page } = await h.context(browser, { consent: null });
  await watchGTM(ctx);
  await page.goto(`${h.BASE.iq}/`, { waitUntil: 'networkidle' });
  await page.locator('[data-ym-consent-yes]').click(); // a real choice: her consent subject is set
  const id = await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  await ctx.close();
  h.wp('iq', 'option', 'update', 'ym_test_ledger_readonly', '1');
  try {
    assert.ok(h.order('iq', id).consent_subject, 'the order carries her consent subject');
    h.sendNow('iq', id, 'meta', 'purchase');
    assert.equal(h.order('iq', id).capi_meta, null);
  } finally {
    h.wp('iq', 'option', 'delete', 'ym_test_ledger_readonly');
  }
  h.sendNow('iq', id, 'meta', 'purchase');
  assert.equal(h.order('iq', id).capi_meta.status, 'dry_run');
});

test('Consent queue under concurrency: simultaneous withdrawals and retries in one store never lose a withdrawal', async () => {
  const subjects = Array.from({ length: 12 }, () => require('node:crypto').randomBytes(16).toString('hex'));
  const withdraw = (s) => fetch(`${h.BASE.eg}/wp-json/ym/v1/consent`, { method: 'POST', headers: { 'Content-Type': 'application/json', Cookie: `ym_cs=${s}` }, body: JSON.stringify({ consent: 'denied' }) }).then((r) => r.status);
  const certify = () => new Promise((res) => require('node:child_process').execFile(require('node:path').join(h.ROOT, 'dev/wp.sh'), ['eg', 'eval', 'YM_Consent::certify();'], () => res()));
  h.wp('eg', 'option', 'update', 'ym_test_ledger_readonly', '1');
  try {
    // Half while the ledger cannot be written, together with retries running at the same moment.
    const codes = await Promise.all([...subjects.slice(0, 6).map(withdraw), certify(), certify()]);
    assert.deepEqual(codes.slice(0, 6), [200, 200, 200, 200, 200, 200]);
    const queued = JSON.parse(h.wpEval('eg', 'echo wp_json_encode( array_keys( YM_Consent::pending() ) );'));
    for (const s of subjects.slice(0, 6)) assert.ok(queued.includes(s), `queued withdrawal lost: ${s}`);
  } finally {
    h.wp('eg', 'option', 'delete', 'ym_test_ledger_readonly');
  }
  // The other half while retries flush the queue at the same moment.
  const codes = await Promise.all([...subjects.slice(6).map(withdraw), certify(), certify(), certify()]);
  assert.deepEqual(codes.slice(0, 6), [200, 200, 200, 200, 200, 200]);
  h.wpEval('eg', 'YM_Consent::certify();');
  const missing = JSON.parse(h.wpEval('iq', `echo wp_json_encode( array_values( array_filter( json_decode('${JSON.stringify(subjects)}', true), fn( $s ) => ! YM_Consent::withdrawn_at( $s ) ) ) );`));
  assert.deepEqual(missing, [], 'every withdrawal reached the shared ledger (read from Iraq)');
  assert.equal(h.wpEval('eg', `echo count( array_intersect( array_keys( YM_Consent::pending() ), json_decode('${JSON.stringify(subjects)}', true) ) );`), '0', 'nothing left queued');
});
