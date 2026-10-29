// Recommendations v1 (29 Oct brief §6): curated, deterministic, never unavailable, duplicate, bundled or preselected;
// adding one goes through the normal cart (delivery charged once); the order line remembers where it came from.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const h = require('./helpers');

let browser;
before(async () => { browser = await chromium.launch(); });
after(async () => { await browser.close(); });

const recoNames = (page) => page.locator('[data-ym-reco] .ym-reco__name').allInnerTexts();

test('Recommendations: a clothes organizer page suggests the clothes rack as an alternative, labelled as such; nothing preselected', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(`${h.BASE.iq}/product/corner-clothes-organizer/`);
  const sec = page.locator('[data-ym-reco="reco_pdp"]');
  assert.equal(await sec.count(), 1);
  const items = sec.locator('.ym-reco__item');
  assert.ok(await items.count() >= 1 && await items.count() <= 3);
  assert.equal(await items.first().getAttribute('data-ym-reco-type'), 'substitute');
  assert.match(await sec.innerText(), /بديل/);
  assert.equal(await sec.locator('input[type=checkbox]').count(), 0, 'nothing preselected');
  // Below the decision content: after the buy form and the delivery terms.
  const order = await page.evaluate(() => { const s = document.querySelector('[data-ym-reco]'); const f = document.querySelector('[data-ym-offers]'); const d = document.querySelector('.ym-delivery'); return [f.compareDocumentPosition(s) & 4, d.compareDocumentPosition(s) & 4]; });
  assert.deepEqual(order, [4, 4]);
  await ctx.close();
});

test('Recommendations: never sells separately what the page itself sells cheaper as a bundle (Iraq rack page: rack + mats offer)', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(`${h.BASE.iq}/product/sink-rack/`);
  assert.equal(await page.locator('[data-ym-reco]').count(), 0);
  await ctx.close();
});

test('Recommendations: nothing already in the cart (a bundle counts as its parts), nothing out of stock, nothing when none qualify', async () => {
  const { ctx, page } = await h.context(browser);
  // The rack + mats bundle in the cart: neither the rack nor the mats may be suggested anywhere.
  await page.goto(`${h.BASE.iq}/product/sink-rack/`);
  await page.locator('input[name="ym_offer"][type="radio"]').nth(1).check({ force: true });
  await page.locator('button[value="cart"]').click();
  await page.waitForLoadState('networkidle');
  await page.goto(`${h.BASE.iq}/product/absorbent-mats/`);
  assert.equal(await page.locator('[data-ym-reco]').count(), 0, 'the mats page suggests the rack, already in the bundle');
  await page.goto(`${h.BASE.iq}/cart/`);
  assert.equal(await page.locator('[data-ym-reco]').count(), 0, 'cart: nothing that duplicates the bundle');
  await ctx.close();
  // Out of stock candidate: the organizer page shows no suggestion at all.
  const id = h.wpEval('iq', 'echo wc_get_product_id_by_sku("IQ030104STAN99");');
  h.wpEval('iq', `$p = wc_get_product(${id}); $p->set_stock_status("outofstock"); $p->save();`);
  try {
    const c2 = await h.context(browser);
    await c2.page.goto(`${h.BASE.iq}/product/corner-clothes-organizer/`);
    assert.equal(await c2.page.locator('[data-ym-reco]').count(), 0);
    await c2.ctx.close();
  } finally {
    h.wpEval('iq', `$p = wc_get_product(${id}); $p->set_stock_status("instock"); $p->save();`);
  }
  // A product without curated rules shows nothing (no filler).
  const c3 = await h.context(browser);
  await c3.page.goto(`${h.BASE.iq}/product/magnetic-window-cleaner/`);
  assert.equal(await c3.page.locator('[data-ym-reco]').count(), 0);
  await c3.ctx.close();
});

test('Recommendations: adding one goes to the normal cart, delivery is charged once, and the order line remembers the source', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(`${h.BASE.eg}/product/heater-2in1-remote/`);
  await page.locator('button[value="cart"]').click(); // the heater, as she would
  await page.waitForLoadState('networkidle');
  await page.goto(`${h.BASE.eg}/cart/`);
  const sec = page.locator('[data-ym-reco="reco_cart"]');
  assert.equal(await sec.count(), 1, 'the cart suggests the heater\'s complement');
  assert.match(await sec.innerText(), /بطانية/);
  await Promise.all([page.waitForURL(/\/cart\//), sec.locator('[data-ym-reco-add]').first().click()]);
  await page.waitForLoadState('networkidle');
  assert.equal(await page.locator('[data-ym-reco]').count(), 0, 'once added it is no longer suggested');
  await page.goto(`${h.BASE.eg}/checkout/`);
  assert.equal(await page.locator('[data-ym-reco]').count(), 0, 'never inside the checkout form');
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone: h.phone.eg() });
  await Promise.all([page.waitForURL(/order-received\/(\d+)/, { timeout: 30000 }), page.locator('#place_order').click()]);
  const id = Number(page.url().match(/order-received\/(\d+)/)[1]);
  const o = h.order('eg', id);
  assert.deepEqual(o.items.map((i) => i.sku).sort(), ['EG030301HAEE99', 'EG030401GB99']);
  assert.equal(o.shipping, 95, 'one delivery fee for the order (Cairo)');
  assert.equal(o.total, 799 + 629 + 95);
  const from = JSON.parse(h.wpEval('eg', `$o = wc_get_order(${id}); $out = array(); foreach ($o->get_items() as $it) { $out[$it->get_product()->get_sku()] = $it->get_meta("_ym_reco_from"); } echo wp_json_encode($out);`));
  assert.equal(from.EG030401GB99, 'EG030301HAEE99');
  assert.equal(from.EG030301HAEE99, '');
  const rep = JSON.parse(h.wp('eg', 'ym', 'reco-report', '--days=1'));
  assert.ok(rep['EG030301HAEE99 -> EG030401GB99'].lines >= 1);
  await ctx.close();
});
