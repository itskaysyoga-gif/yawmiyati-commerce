// COD abuse controls: refusals at submission (honeypot, rate limits, bot check), holds with reason codes before the
// supplier hand-off, reversible risk records from verified refusals, and the staff release/cancel trail.
// The supplier probe (dev/mu-plugins) records the order status at the hooks a supplier plugin typically uses.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const h = require('./helpers');

let browser;
before(async () => { browser = await chromium.launch(); });
after(async () => { await browser.close(); });

const probe = (cc, id) => JSON.parse(h.wpEval(cc, `echo wp_json_encode(get_option("ym_probe_${id}", array()));`));
const review = (cc, id) => JSON.parse(h.wpEval(cc, `echo wp_json_encode(YM_Risk::reasons(wc_get_order(${id})));`));
const fakeIp = () => `10.${Math.floor(Math.random() * 250)}.${Math.floor(Math.random() * 250)}.${Math.floor(Math.random() * 250)}`;

async function captured(cc, customer = {}) {
  const { ctx, page } = await h.context(browser);
  await ctx.route('https://challenges.cloudflare.com/**', (r) => r.fulfill({ status: 200, body: '' }));
  const slug = { eg: 'absorbent-mats', iq: 'sink-rack', om: 'dish-rack' }[cc];
  await page.goto(`${h.BASE[cc]}/product/${slug}/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER[cc], phone: h.phone[cc](), ...customer });
  let body;
  await page.route('**/?wc-ajax=checkout', (route) => { body = route.request().postData(); route.abort(); });
  await page.locator('#place_order').click();
  for (let i = 0; i < 50 && !body; i++) await page.waitForTimeout(100);
  await page.unroute('**/?wc-ajax=checkout');
  // The Turnstile widget cannot load locally; a stub token stands in for the one it would add.
  const send = (headers = {}, edit = (b) => b) => page.request.post(`${h.BASE[cc]}/?wc-ajax=checkout`, {
    data: edit(body.includes('cf-turnstile-response') ? body : body + '&cf-turnstile-response=stub-token'), headers: { 'content-type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-YM-Test-Ip': fakeIp(), ...headers },
  }).then((r) => r.json());
  return { ctx, page, send, body };
}
const idOf = (r) => Number(r.redirect.match(/order-received\/(\d+)/)[1]);

test('Honeypot: a filled hidden field is refused with a neutral message and creates nothing', async () => {
  const { ctx, send } = await captured('eg');
  const before = h.orderCount('eg');
  const r = await send({}, (b) => b.replace('ym_company_site=', 'ym_company_site=http%3A%2F%2Fspam.example'));
  assert.equal(r.result, 'failure');
  assert.match(r.messages, /حدّثي الصفحة/);
  assert.equal(h.orderCount('eg'), before);
  await ctx.close();
});

test('Rate limit: typing mistakes never count; a burst of complete submissions is refused politely after the limit', async () => {
  const LIMITED = /انرسلت طلبات هواية/;
  // Twelve submissions with a wrong phone from one browser: ordinary mistakes, never rate-limited.
  const typo = await captured('iq', { phone: '0123' });
  for (let i = 0; i < 12; i++) assert.doesNotMatch((await typo.send()).messages, LIMITED);
  await typo.ctx.close();
  // Complete submissions whose order creation fails (so nothing is created): the 7th from one session is refused.
  const ip = fakeIp();
  const a = await captured('iq');
  const before = h.orderCount('iq');
  const fromA = [];
  for (let i = 0; i < 7; i++) fromA.push(await a.send({ 'X-YM-Test-Ip': fakeIp(), 'X-YM-Test-Fail': 'create' }));
  assert.ok(!fromA.slice(0, 6).some((r) => LIMITED.test(r.messages)), 'six allowed per session');
  assert.match(fromA[6].messages, LIMITED);
  await a.ctx.close();
  assert.equal(h.orderCount('iq'), before);
  // One busy connection shared by many browsers (carrier NAT): orders are held for review, then refused only at an
  // extreme rate. Thresholds lowered for the test: hold after 3, refuse after 5.
  h.wpEval('iq', 'update_option("ym_risk_settings", array("ip_hold" => 3, "ip_refuse" => 5));');
  try {
    const results = [];
    for (let i = 0; i < 6; i++) {
      const b = await captured('iq');
      results.push(await b.send({ 'X-YM-Test-Ip': ip }));
      await b.ctx.close();
    }
    assert.ok(results.slice(0, 5).every((r) => r.result === 'success'), JSON.stringify(results.map((r) => r.result)));
    assert.deepEqual(review('iq', idOf(results[2])), []);
    assert.ok(review('iq', idOf(results[3])).includes('ip_burst'), 'held for review, not refused');
    assert.match(results[5].messages, LIMITED);
  } finally {
    h.wpEval('iq', 'delete_option("ym_risk_settings");');
  }
});

test('Hold before hand-off: a held order never passes through "processing" at any supplier hook until a person releases it', async () => {
  const { ctx, send } = await captured('eg');
  const r = await send({ 'X-YM-Test-Clock': '-100' }); // looks like the form was sent instantly: fast_submit
  assert.equal(r.result, 'success', JSON.stringify(r));
  const id = idOf(r);
  assert.deepEqual(review('eg', id), ['fast_submit']);
  const seen = probe('eg', id);
  assert.ok(seen.length >= 4, JSON.stringify(seen));
  // pending while WooCommerce creates it, then on-hold; never processing or completed.
  for (const s of seen) assert.ok(['pending', 'on-hold'].includes(s.status), `${s.hook} saw ${s.status}`);
  assert.ok(!seen.some((s) => s.hook === 'woocommerce_order_status_processing' || s.hook === 'woocommerce_payment_complete'));
  assert.equal(h.order('eg', id).status, 'on-hold');
  assert.ok(JSON.parse(h.wpEval('eg', `echo wp_json_encode(wc_get_order(${id})->get_meta("_ym_review_reasons"));`)), 'reason recorded from the first save');
  assert.equal(h.order('eg', id).supplier, 'pending');
  // The customer sees the normal confirmation (no blame, no internal reason).
  assert.throws(() => h.wp('eg', 'ym', 'review', 'release', String(id)), /--note|Say what you checked/);
  h.wp('eg', 'ym', 'review', 'release', String(id), '--note=called her, real order');
  const after = probe('eg', id);
  assert.ok(after.some((s) => s.hook === 'woocommerce_order_status_processing' && s.status === 'processing'));
  assert.equal(h.order('eg', id).status, 'processing');
  const audit = JSON.parse(h.wpEval('eg', `echo wc_get_order(${id})->get_meta("_ym_review_audit");`));
  assert.equal(audit[0].action, 'released');
  await ctx.close();
});

test('Normal order: processing at the hand-off hooks, no review', async () => {
  const { ctx, page } = await h.context(browser);
  const id = await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone: h.phone.iq() } });
  assert.deepEqual(review('iq', id), []);
  assert.ok(probe('iq', id).some((s) => s.hook === 'woocommerce_order_status_processing'));
  await ctx.close();
});

test('Duplicates: the same phone typed with Arabic digits and +20, or given as the second number, is recognised', async () => {
  const phone = h.phone.eg();
  const a = await h.context(browser);
  const first = await h.buy(a.page, 'eg', { slug: 'absorbent-mats', customer: { phone } });
  await a.ctx.close();
  const arabic = '+20 ' + phone.slice(1).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[d]);
  const b = await h.context(browser);
  const second = await h.buy(b.page, 'eg', { slug: 'absorbent-mats', customer: { phone: arabic } });
  await b.ctx.close();
  assert.equal(h.order('eg', second).phone, phone, 'stored normalised');
  assert.ok(review('eg', second).includes('possible_duplicate'));
  const c = await h.context(browser);
  const third = await h.buy(c.page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg(), phone2: phone } });
  await c.ctx.close();
  assert.ok(review('eg', third).includes('possible_duplicate'), 'matched on the second number');
  assert.equal(h.order('eg', first).status, 'processing');
});

test('Risk records: verified customer-caused refusals lead to "confirm first"; operational causes never count; staff can clear', async () => {
  const phone = h.phone.iq();
  const ids = [];
  for (let i = 0; i < 2; i++) {
    const { ctx, page } = await h.context(browser);
    ids.push(await h.buy(page, i ? 'iq' : 'iq', { slug: 'sink-rack', offer: i, customer: { phone } }));
    await ctx.close();
  }
  // One refusal caused by the operation (wrong item): nothing against her.
  h.wp('iq', 'ym', 'outcome', String(ids[0]), 'refused', '--reason=wrong_item', '--evidence=test');
  assert.match(h.wp('iq', 'ym', 'risk', 'show', phone), /No record/);
  // Two refusals she caused: first one is only "watch" (no effect), second makes it "confirm".
  h.wp('iq', 'ym', 'outcome', String(ids[1]), 'refused', '--reason=changed_mind', '--evidence=test');
  assert.equal(JSON.parse(h.wp('iq', 'ym', 'risk', 'show', phone)).level, 'watch');
  const { ctx, page } = await h.context(browser);
  const next = await h.buy(page, 'iq', { slug: 'sink-rack', customer: { phone } });
  await ctx.close();
  assert.ok(!review('iq', next).includes('confirm_first'), 'one refusal does not hold her next order');
  h.wp('iq', 'ym', 'outcome', String(next), 'refused', '--reason=not_reachable', '--evidence=test');
  const rec = JSON.parse(h.wp('iq', 'ym', 'risk', 'show', phone));
  assert.equal(rec.level, 'confirm');
  assert.ok(rec.expires > Date.now() / 1000 + 80 * 86400, 'expires (90 days)');
  const c2 = await h.context(browser);
  const held = await h.buy(c2.page, 'iq', { slug: 'sink-rack', customer: { phone } });
  await c2.ctx.close();
  assert.ok(review('iq', held).includes('confirm_first'));
  assert.equal(h.order('iq', held).status, 'on-hold');
  // Staff clear it with a reason; history kept; next order is normal.
  h.wp('iq', 'ym', 'risk', 'clear', phone, '--reason=shared family phone, confirmed by call');
  const cleared = JSON.parse(h.wp('iq', 'ym', 'risk', 'show', phone));
  assert.ok(cleared.cleared);
  assert.equal(cleared.history.at(-1).to, 'cleared');
  const c3 = await h.context(browser);
  const ok = await h.buy(c3.page, 'iq', { slug: 'sink-rack', customer: { phone: '+964' + phone.slice(1) } });
  await c3.ctx.close();
  assert.ok(!review('iq', ok).includes('confirm_first'));
});

test('Turnstile: checked once per checkout on the server; a spent token on a retry of the same checkout does not block it', async () => {
  h.wp('om', 'option', 'update', 'ym_turnstile_sitekey', '1x00000000000000000000AA');
  h.wp('om', 'option', 'update', 'ym_turnstile_test', 'stub');
  try {
    const { ctx, page, send } = await captured('om');
    assert.equal(await page.locator('.cf-turnstile').count(), 1, 'widget rendered');
    const before = h.orderCount('om');
    const failed = await send({ 'X-YM-Test-Turnstile': 'fail' });
    assert.equal(failed.result, 'failure');
    assert.match(failed.messages, /برنامجًا آليًا/);
    assert.equal(h.orderCount('om'), before);
    const ok = await send({ 'X-YM-Test-Turnstile': 'pass' });
    assert.equal(ok.result, 'success', JSON.stringify(ok));
    // Lost response: the browser retries the same checkout; Cloudflare would now say the token is spent.
    const retry = await send({ 'X-YM-Test-Turnstile': 'duplicate' });
    assert.equal(retry.result, 'success');
    assert.equal(idOf(retry), idOf(ok));
    assert.equal(h.orderCount('om'), before + 1);
    await ctx.close();
    // A "success" for another site or another action is refused: hostname and action are checked explicitly.
    for (const variant of ['wronghost', 'wrongaction', 'noaction']) {
      const v = await captured('om');
      const r = await v.send({ 'X-YM-Test-Turnstile': variant });
      assert.equal(r.result, 'failure', variant);
      assert.match(r.messages, /برنامجًا آليًا/, variant);
      await v.ctx.close();
    }
    assert.equal(h.orderCount('om'), before + 1, 'nothing created by mismatched tokens');
    // Cloudflare unreachable: the order is kept but held for a person, not lost.
    const c = await captured('om');
    const down = await c.send({ 'X-YM-Test-Turnstile': 'unreachable' });
    assert.equal(down.result, 'success');
    assert.ok(review('om', idOf(down)).includes('bot_check_unavailable'));
    await c.ctx.close();
  } finally {
    h.wp('om', 'option', 'delete', 'ym_turnstile_sitekey');
    h.wp('om', 'option', 'delete', 'ym_turnstile_test');
  }
});

test('Concurrent submissions: simultaneous requests are counted exactly (no lost counts), per session and per connection', async () => {
  const LIMITED = /انرسلت طلبات هواية/;
  // Ten simultaneous complete submissions from one browser session (creation made to fail, so nothing is created):
  // with a limit of 6 per session exactly 4 must be refused, whatever order they arrive in.
  const a = await captured('iq');
  const results = await Promise.all(Array.from({ length: 10 }, () => a.send({ 'X-YM-Test-Ip': fakeIp(), 'X-YM-Test-Fail': 'create' })));
  await a.ctx.close();
  assert.equal(results.filter((r) => LIMITED.test(r.messages || '')).length, 4, JSON.stringify(results.map((r) => r.result)));
  // Eight simultaneous submissions from different browsers on one connection, refuse after 5: exactly 3 refused.
  h.wpEval('iq', 'update_option("ym_risk_settings", array("ip_hold" => 100, "ip_refuse" => 5));');
  try {
    const ip = fakeIp();
    const browsers = [];
    for (let i = 0; i < 8; i++) browsers.push(await captured('iq'));
    const out = await Promise.all(browsers.map((b) => b.send({ 'X-YM-Test-Ip': ip, 'X-YM-Test-Fail': 'create' })));
    for (const b of browsers) await b.ctx.close();
    assert.equal(out.filter((r) => LIMITED.test(r.messages || '')).length, 3, JSON.stringify(out.map((r) => r.result)));
  } finally {
    h.wpEval('iq', 'delete_option("ym_risk_settings");');
  }
});

test('Client IP: a forged CF-Connecting-IP or X-Forwarded-For from a direct connection does not change the address the limits use', async () => {
  // No trusted proxy is configured locally, so the connection address (127.0.0.1) is the client. Rotating forged
  // forwarding headers must not reset the per-connection count.
  h.wpEval('iq', 'update_option("ym_risk_settings", array("ip_hold" => 100, "ip_refuse" => 3));');
  try {
    const out = [];
    for (let i = 0; i < 5; i++) {
      const b = await captured('iq');
      // No X-YM-Test-Ip here: the real connection address is what counts.
      out.push(await b.send({ 'X-YM-Test-Ip': '', 'CF-Connecting-IP': fakeIp(), 'X-Forwarded-For': fakeIp(), 'X-YM-Test-Fail': 'create' }));
      await b.ctx.close();
    }
    assert.ok(out.slice(3).every((r) => /انرسلت طلبات هواية/.test(r.messages || '')), JSON.stringify(out.map((r) => r.result)));
  } finally {
    h.wpEval('iq', 'delete_option("ym_risk_settings");');
  }
});
