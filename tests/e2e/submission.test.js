// Order submission under concurrency, slow requests, lost responses and crashes.
// These are the cases behind the CI failure of 28 Oct (run 40748106500): two requests carrying the same checkout
// token claimed it at the same moment and both created an order.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const h = require('./helpers');

let browser;
before(async () => { browser = await chromium.launch(); });
after(async () => { await browser.close(); });

// Fill a checkout and capture the request the browser would send, without sending it.
async function captured(cc = 'eg', phone) {
  const { ctx, page } = await h.context(browser);
  const slug = cc === 'eg' ? 'absorbent-mats' : 'sink-rack';
  await page.goto(`${h.BASE[cc]}/product/${slug}/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER[cc], phone: phone || h.phone[cc]() });
  let body;
  await page.route('**/?wc-ajax=checkout', (route) => { body = route.request().postData(); route.abort(); });
  await page.locator('#place_order').click();
  for (let i = 0; i < 50 && !body; i++) await page.waitForTimeout(100);
  assert.ok(body, 'checkout request captured');
  await page.unroute('**/?wc-ajax=checkout');
  const send = (headers = {}) => page.request.post(`${h.BASE[cc]}/?wc-ajax=checkout`, {
    data: body, timeout: 90000,
    headers: { 'content-type': 'application/x-www-form-urlencoded; charset=UTF-8', ...headers },
  }).then((r) => r.json());
  const token = new URLSearchParams(body).get('ym_token');
  return { ctx, page, send, token, body };
}

const orderIds = (results) => [...new Set(results.filter((r) => r.result === 'success').map((r) => r.redirect.match(/order-received\/(\d+)/)[1]))];

test('Submission: six copies released at the same millisecond create exactly one order', async () => {
  const { ctx, send } = await captured('eg');
  const before = h.orderCount('eg');
  const at = String(Date.now() + 1500);
  const results = await Promise.all(Array.from({ length: 6 }, () => send({ 'X-YM-Test-Claim-At': at })));
  assert.equal(h.orderCount('eg'), before + 1, JSON.stringify(results));
  assert.equal(orderIds(results).length, 1, JSON.stringify(results));
  results.filter((r) => r.result !== 'success').forEach((r) => assert.match(r.messages, /بيتسجل|اتسجل/));
  await ctx.close();
});

test('Submission: Iraq, same race, one order', async () => {
  const { ctx, send } = await captured('iq');
  const before = h.orderCount('iq');
  const at = String(Date.now() + 1500);
  const results = await Promise.all(Array.from({ length: 6 }, () => send({ 'X-YM-Test-Claim-At': at })));
  assert.equal(h.orderCount('iq'), before + 1, JSON.stringify(results));
  assert.equal(orderIds(results).length, 1);
  await ctx.close();
});

test('Submission: a first request still running after 35 s blocks every retry; no second order', async () => {
  const { ctx, send } = await captured('eg');
  const before = h.orderCount('eg');
  const t0 = Date.now();
  const first = send({ 'X-YM-Test-Hold': '35' });            // claims the token, then stalls inside order creation
  await new Promise((r) => setTimeout(r, 2000));
  const early = await send();                                // 2 s in
  await new Promise((r) => setTimeout(r, Math.max(0, 31000 - (Date.now() - t0))));
  const late = await send();                                 // past the old 30 s expiry, first request still alive
  assert.ok(Date.now() - t0 > 30000, 'late retry ran after 30 s');
  for (const r of [early, late]) {
    assert.notEqual(r.result, 'success', JSON.stringify(r));
    assert.match(r.messages, /بيتسجل/);                     // "still sending, wait"
  }
  const done = await first;
  assert.equal(done.result, 'success', JSON.stringify(done));
  const after = await send();                                // retry once the first finished
  assert.equal(after.result, 'success');
  assert.deepEqual(orderIds([done, after]).length, 1);
  assert.equal(h.orderCount('eg'), before + 1);
  await ctx.close();
});

test('Submission: the process dies after saving the order and before answering; the retry lands on that order', async () => {
  const { ctx, send, token } = await captured('eg');
  const before = h.orderCount('eg');
  const lost = await send({ 'X-YM-Test-Fail': 'after-create' }).catch(() => null);
  assert.ok(!lost || lost.result !== 'success', 'no success response reached the browser');
  assert.equal(h.orderCount('eg'), before + 1);
  const retry = await send();
  assert.equal(retry.result, 'success', JSON.stringify(retry));
  assert.equal(h.orderCount('eg'), before + 1);
  const id = Number(retry.redirect.match(/order-received\/(\d+)/)[1]);
  assert.ok(h.order('eg', id).token, 'order carries its token');
  assert.ok(token);
  await ctx.close();
});

test('Submission: order creation fails; the claim is released and the retry creates the one order', async () => {
  const { ctx, send } = await captured('iq');
  const before = h.orderCount('iq');
  const failed = await send({ 'X-YM-Test-Fail': 'create' });
  assert.equal(failed.result, 'failure');
  assert.equal(h.orderCount('iq'), before);
  const ok = await send();
  assert.equal(ok.result, 'success', JSON.stringify(ok));
  const again = await send();                                // lost response, browser retries again
  assert.equal(orderIds([ok, again]).length, 1);
  assert.equal(h.orderCount('iq'), before + 1);
  await ctx.close();
});

test('Submission: a copied token from another browser session cannot replay or create an order', async () => {
  const { ctx, send, body } = await captured('eg');
  const before = h.orderCount('eg');
  const mine = await send();
  assert.equal(mine.result, 'success');
  // A second browser replays the exact same request (same token, nonce and fields) after the order exists.
  const other = await h.context(browser);
  await other.page.goto(`${h.BASE.eg}/`);
  const res = await other.page.request.post(`${h.BASE.eg}/?wc-ajax=checkout`, { data: body, headers: { 'content-type': 'application/x-www-form-urlencoded; charset=UTF-8' } }).then((r) => r.json());
  assert.notEqual(res.result, 'success', JSON.stringify(res));
  assert.doesNotMatch(JSON.stringify(res), /order-received/);   // never sent to her order page
  assert.equal(h.orderCount('eg'), before + 1);
  await other.ctx.close();
  await ctx.close();
});

test('Markets: a live store whose checkout gate is off refuses the order on the server, whatever the page showed', async () => {
  const { ctx, send } = await captured('eg');
  const before = h.orderCount('eg');
  const closed = await send({ 'X-YM-Test-Env': 'production', 'X-YM-Test-Markets': 'prepared,catalog' });
  assert.notEqual(closed.result, 'success', JSON.stringify(closed));
  assert.match(closed.messages, /مش متاح/);
  assert.equal(h.orderCount('eg'), before);
  await ctx.close();
});

test('Markets: a closed market shows no buy buttons and refuses add-to-cart', async () => {
  const { ctx, page } = await h.context(browser);
  await ctx.setExtraHTTPHeaders({ 'X-YM-Test-Env': 'staging', 'X-YM-Test-Markets': 'prepared' });
  await page.goto(`${h.BASE.iq}/product/sink-rack/`);
  assert.equal(await page.locator('button[value="buy"]').count(), 0);
  assert.equal(await page.locator('[data-ym-sticky]').count(), 0);
  assert.match(await page.locator('[data-ym-closed]').innerText(), /مو متاح/);
  await ctx.close();
});
