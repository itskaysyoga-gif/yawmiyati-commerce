// Regressions for the 29 Oct review (docs/reports/2026-10-29-language-storefront.md): checkout totals before a
// governorate exists, email send outcomes, the shared weekly cap under concurrency, confirmation-link lifecycle,
// webhook half-failures, explicit consent stores, server events off by default, and the stated consent window.
// Local only: email is captured, never sent; no provider is involved.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const path = require('node:path');
const { spawn } = require('node:child_process');
const { chromium } = require('playwright');
const h = require('./helpers');

let browser;
const FLOWS = JSON.stringify(['transactional', 'newsletter', 'post_delivery', 'cross_sell']);
const sha = (s) => crypto.createHash('sha256').update(s).digest('hex');
const outbox = (cc) => JSON.parse(h.wpEval(cc, 'echo wp_json_encode( get_option( "ym_test_mail_outbox", array() ) );'));
const contact = (email) => JSON.parse(h.wpEval('eg', `echo wp_json_encode( YM_Mail::contact( "${email}" ) );`));
const logRows = (email) => JSON.parse(h.wpEval('eg', `global $wpdb; echo wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT kind, ref, state, marketing FROM \`ym_shared\`.\`ym_mail_log\` WHERE email_hash = %s ORDER BY id", "${sha(email)}" ), ARRAY_A ) );`));
const sql = (q) => h.wpEval('eg', `global $wpdb; $wpdb->query( ${JSON.stringify(q)} );`);
// One PHP call per store (each WP-CLI start costs about a second; in CI more).
function mailOn(on) {
  const php = on
    ? `update_option("ym_email_flows", json_decode('${FLOWS}', true)); update_option("ym_test_mail_capture", "1"); update_option("ym_test_webhook_key", "hook-key-test"); delete_option("ym_test_mail_outbox"); delete_option("ym_test_mail_outcome");`
    : 'foreach (array("ym_email_flows","ym_test_mail_capture","ym_test_webhook_key","ym_test_mail_outcome","ym_test_mail_outbox","ym_test_cap_pause_ms") as $k) delete_option($k);';
  for (const cc of ['eg', 'iq', 'sa']) h.wpEval(cc, php);
}
const subscribe = (email) => fetch(`${h.BASE.eg}/wp-json/ym/v1/subscribe`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-YM-Test-Ip': `10.9.${Math.floor(Math.random() * 250)}.${1 + Math.floor(Math.random() * 250)}` }, body: JSON.stringify({ email, consent: '1', source: 'footer' }) });
const confirmLinks = (email) => outbox('eg').filter((m) => m.to === email && m.kind === 'confirm').map((m) => m.html.match(/href="([^"]*ym_confirm=[^"]+)"/)[1].replace(/&#038;|&amp;/g, '&'));
const press = (link) => fetch(link, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'ym_page=1' });
/** A subscribed contact straight in the shared table (the opt-in itself is tested separately). */
function subscribed(email) {
  const id = crypto.randomBytes(16).toString('hex');
  sql(`INSERT INTO \`ym_shared\`.\`ym_contacts\` (id, email, status, consent_at, consent_wording, source, country, confirmed_at, updated_at) VALUES ('${id}', '${email}', 'subscribed', UNIX_TIMESTAMP(), '2026-10-29', 'test', 'EG', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())`);
  return id;
}
const sendMkt = (cc, email, kind, ref) => h.wpEval(cc, `echo YM_Mail::send_marketing( YM_Mail::contact("${email}"), "${kind}", "${ref}", "s", array("b") ) ? "sent" : "held";`);

before(async () => { browser = await chromium.launch(); mailOn(true); });
after(async () => { mailOn(false); await browser.close(); });

test('Checkout before a governorate: products subtotal with "delivery after choosing", never a payable total; after choosing, one total with the fee; no order without a governorate', async () => {
  const { ctx, page } = await h.context(browser);
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.waitIdle(page);
  const copy = JSON.parse(h.wpEval('eg', 'echo wp_json_encode( array( YM_Country::copy("pay_total"), YM_Country::copy("subtotal_label"), YM_Country::copy("delivery_after_gov") ) );'));
  const row = page.locator('.woocommerce-checkout-review-order-table tr.order-total');
  assert.equal((await row.locator('th').innerText()).trim(), copy[1], 'labelled as the products subtotal');
  assert.equal(await row.locator('[data-ym-total-pending]').count(), 1);
  assert.match(await row.locator('td').innerText(), new RegExp(copy[2]));
  assert.ok(!(await page.locator('.woocommerce-checkout-review-order-table').innerText()).includes(copy[0]), 'no "due on delivery" amount before the fee is known');
  // Submitting without a governorate: refused, no order.
  const before = h.orderCount('eg');
  await page.fill('#billing_first_name', h.CUSTOMER.eg.name);
  await page.fill('#billing_phone', h.phone.eg());
  await page.fill('#billing_address_1', h.CUSTOMER.eg.area);
  await page.locator('#place_order').click();
  await page.waitForSelector('.woocommerce-error, .wc-block-components-notice-banner.is-error', { timeout: 20000 });
  assert.equal(h.orderCount('eg'), before, 'no order without a governorate');
  // After choosing: one final total with the fee, labelled as what she pays on delivery.
  await page.evaluate(() => { jQuery('#billing_state').val('EGC').trigger('change'); });
  await h.waitIdle(page);
  assert.equal((await row.locator('th').innerText()).trim(), copy[0]);
  assert.equal(await row.locator('[data-ym-total-pending]').count(), 0);
  const fee = Number(h.wpEval('eg', 'echo YM_Country::fee("EGC");'));
  const price = Number(h.wpEval('eg', 'echo wc_get_product( wc_get_product_id_by_sku("EG030106MKKT99") )->get_price();'));
  assert.match((await row.locator('td').innerText()).replace(/[٬,]/g, ''), new RegExp(String(price + fee)));
  await ctx.close();
});

test('Confirmation links: opening changes nothing; only the button confirms, once; an old link never confirms a newer signup; complaints are not lifted by a form; per-address cooldown', async () => {
  const email = `link${Date.now()}@example.test`;
  assert.equal((await subscribe(email)).status, 200);
  const [link1] = confirmLinks(email);
  const get = await fetch(link1);
  assert.equal(get.status, 200);
  assert.match(await get.text(), /data-ym-mail-action="confirm"/, 'a page with one button');
  assert.equal(contact(email).status, 'pending', 'opening the link (a mail scanner) confirmed nothing');
  // A second signup at once: cooldown, no second email.
  await subscribe(email);
  assert.equal(confirmLinks(email).length, 1, 'no flood: one confirmation per 10 minutes per address');
  // She unsubscribes, later signs up again: the first link must not confirm the new signup.
  h.wpEval('eg', `YM_Mail::suppress("${email}");`);
  sql(`UPDATE \`ym_shared\`.\`ym_mail_log\` SET at = at - 700 WHERE email_hash = '${sha(email)}'`);
  await subscribe(email);
  const links = confirmLinks(email);
  assert.equal(links.length, 2);
  assert.equal((await press(links[0])).status, 400, 'old link refused');
  assert.equal(contact(email).status, 'pending');
  assert.equal((await press(links[1])).status, 200);
  assert.equal(contact(email).status, 'subscribed');
  assert.equal((await press(links[1])).status, 400, 'a link works once');
  assert.equal(outbox('eg').filter((m) => m.to === email && m.kind === 'welcome').length, 1);
  // A complaint is never undone by a new signup; a person can lift it, and she must opt in again.
  h.wpEval('eg', `YM_Mail::suppress("${email}", "complained");`);
  sql(`UPDATE \`ym_shared\`.\`ym_mail_log\` SET at = at - 700 WHERE email_hash = '${sha(email)}'`);
  const r = await subscribe(email);
  assert.equal(r.status, 200, 'same answer as anyone (no list disclosure)');
  assert.equal(contact(email).status, 'complained');
  assert.equal(confirmLinks(email).length, 2, 'no confirmation mail to a complainant');
  assert.equal(h.wpEval('eg', `echo YM_Mail::unblock("${email}") ? "ok" : "no";`), 'ok');
  assert.equal(contact(email).status, 'unsubscribed');
});

test('Send outcomes: a timeout is "unknown": kept, never resent automatically, counted against the cap; a refusal is "failed" and may be retried; a person resolves unknowns', async () => {
  const email = `outcome${Date.now()}@example.test`;
  subscribed(email);
  h.wp('eg', 'option', 'update', 'ym_test_mail_outcome', 'timeout');
  assert.equal(sendMkt('eg', email, 'newsletter', 'n1'), 'held');
  assert.deepEqual(logRows(email).map((r) => r.state), ['unknown']);
  h.wp('eg', 'option', 'delete', 'ym_test_mail_outcome');
  assert.equal(sendMkt('iq', email, 'newsletter', 'n1'), 'held', 'never resent while unknown (it may have gone out)');
  assert.equal(sendMkt('iq', email, 'newsletter', 'n2'), 'held', 'an unknown send counts against the weekly cap');
  const unknown = JSON.parse(h.wpEval('eg', 'echo wp_json_encode( YM_Mail::unknown_sends() );')).find((u) => u.ref === 'n1');
  assert.ok(unknown);
  assert.equal(h.wpEval('eg', `echo YM_Mail::resolve(${unknown.id}, "failed", "test: not in the provider log") ? "ok" : "no";`), 'ok');
  assert.equal(sendMkt('eg', email, 'newsletter', 'n1'), 'sent', 'after a person marked it failed, the same issue can go out');
  // Transactional: a provider refusal (400) frees it for a later attempt.
  const tx = `tx${Date.now()}@example.test`;
  h.wp('eg', 'option', 'update', 'ym_test_mail_outcome', '400');
  assert.equal(h.wpEval('eg', `echo YM_Mail::send("${tx}", "s", array("b"), array("kind" => "order_received", "ref" => "EG-1")) ? "sent" : "no";`), 'no');
  h.wp('eg', 'option', 'delete', 'ym_test_mail_outcome');
  assert.equal(h.wpEval('eg', `echo YM_Mail::send("${tx}", "s", array("b"), array("kind" => "order_received", "ref" => "EG-1")) ? "sent" : "no";`), 'sent');
  assert.equal(h.wpEval('eg', `echo YM_Mail::send("${tx}", "s", array("b"), array("kind" => "order_received", "ref" => "EG-1")) ? "sent" : "no";`), 'no', 'once accepted, never again');
});

test('Weekly cap across stores under concurrency: six sends at the same moment from three stores, exactly one goes out', async () => {
  const email = `cap${Date.now()}@example.test`;
  subscribed(email);
  // Each sender pauses 1.5 s between reading the count and reserving, so without the row lock all six would pass.
  for (const cc of ['eg', 'iq', 'sa']) h.wpEval(cc, 'update_option("ym_test_cap_pause_ms", "1500");');
  const runs = ['eg', 'iq', 'sa', 'eg', 'iq', 'sa'].map((cc, i) => new Promise((resolve) => {
    const p = spawn(path.join(h.ROOT, 'dev/wp.sh'), [cc, 'eval', `echo YM_Mail::send_marketing( YM_Mail::contact("${email}"), "campaign", "c${i}", "s", array("b") ) ? "sent" : "held";`]);
    let out = '';
    p.stdout.on('data', (d) => { out += d; });
    p.on('close', () => resolve(out.trim()));
  }));
  const res = await Promise.all(runs);
  for (const cc of ['eg', 'iq', 'sa']) h.wpEval(cc, 'delete_option("ym_test_cap_pause_ms");');
  assert.equal(res.filter((r) => r === 'sent').length, 1, `results: ${res.join(',')}`);
  assert.equal(logRows(email).filter((r) => r.marketing === '1' && r.state !== 'failed').length, 1);
  // Suppression is read inside the same lock: once she unsubscribes, a stale "subscribed" record passed in sends nothing.
  const email2 = `cap2${Date.now()}@example.test`;
  subscribed(email2);
  const stale = h.wpEval('eg', `echo wp_json_encode( YM_Mail::contact("${email2}") );`);
  h.wpEval('iq', `YM_Mail::suppress("${email2}");`);
  assert.equal(h.wpEval('eg', `echo YM_Mail::send_marketing( json_decode( '${stale}', true ), "campaign", "s1", "s", array("b") ) ? "sent" : "held";`), 'held');
});

test('Webhook half-failures: a suppression that cannot be saved answers 500 and is finished by the retry; a recorded-but-unapplied event is applied', async () => {
  const email = `hook${Date.now()}@example.test`;
  const post = (body) => fetch(`${h.BASE.iq}/wp-json/ym/v1/mail-events`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-YM-Key': 'hook-key-test' }, body: JSON.stringify(body) });
  const ev = { event: 'hard_bounce', email, 'message-id': '<h1@test>', ts_event: 1700000000 };
  h.wp('iq', 'option', 'update', 'ym_test_suppress_fail', '1');
  assert.equal((await post(ev)).status, 500, 'the provider is told to retry');
  assert.notEqual(contact(email)?.status, 'bounced');
  assert.equal((await (await post(ev)).json()).applied, 1, 'the retry finishes it');
  assert.equal(contact(email).status, 'bounced');
  // An event row left unapplied by an older version (recorded, then the process died) is applied on retry.
  const ev2 = { event: 'spam', email, 'message-id': '<h2@test>', ts_event: 1700000100 };
  const id = crypto.createHash('md5').update(`spam|${email}|<h2@test>|1700000100`).digest('hex');
  sql(`INSERT INTO \`ym_shared\`.\`ym_mail_events\` (id, at, applied) VALUES ('${id}', UNIX_TIMESTAMP(), 0)`);
  assert.equal((await (await post(ev2)).json()).applied, 1);
  assert.equal(contact(email).status, 'complained');
  assert.equal((await (await post(ev2)).json()).applied, 0, 'then a replay is ignored');
});

test('Consent stores are explicit: none configured, or this store not listed, means wait; an uninstalled market listed nowhere holds nothing', async () => {
  const subject = crypto.randomBytes(16).toString('hex');
  const clearance = () => h.wpEval('eg', `echo YM_Consent::clearance("${subject}", time() - 60, time() - 30);`);
  try {
    h.wp('eg', 'config', 'delete', 'YM_CONSENT_STORES');
    assert.equal(clearance(), 'wait:consent_stores_not_configured');
    h.wp('eg', 'config', 'set', 'YM_CONSENT_STORES', 'iq');
    assert.equal(clearance(), 'wait:this_store_not_in_consent_stores');
    h.wp('eg', 'config', 'set', 'YM_CONSENT_STORES', 'eg,iq');
    h.wp('iq', 'config', 'set', 'YM_CONSENT_STORES', 'eg,iq');
    h.sleep(2100);
    for (const cc of ['eg', 'iq']) h.wpEval(cc, 'YM_Consent::certify();');
    assert.equal(clearance(), 'clear', 'Saudi Arabia, UAE and Oman are not installed in this setup and hold nothing');
  } finally {
    for (const cc of ['eg', 'iq']) h.wp(cc, 'config', 'set', 'YM_CONSENT_STORES', 'eg,iq,sa,ae,om');
  }
});

test('Server events are off unless YM_SERVER_EVENTS is true; the order itself never depends on them', async () => {
  const { ctx, page } = await h.context(browser, { consent: 'granted' });
  try {
    h.wp('eg', 'config', 'set', 'YM_SERVER_EVENTS', 'false', '--raw');
    const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
    assert.equal(h.order('eg', id).status, 'processing');
    h.wpEval('eg', `YM_Tracking::send(${id}, "meta", "purchase");`);
    assert.equal(h.order('eg', id).capi_meta.reason, 'server_events_off');
  } finally {
    h.wp('eg', 'config', 'set', 'YM_SERVER_EVENTS', 'true', '--raw');
    await ctx.close();
  }
});

test('Stated consent window (docs/MEASUREMENT.md §4), reproduced: a withdrawal whose ledger write fails AFTER every store certified past the decision is not seen by the sender until that store flushes. This is why server events are off by default', async () => {
  const subject = crypto.randomBytes(16).toString('hex');
  const placed = Math.floor(Date.now() / 1000) - 120;
  const decided = Number(h.wpEval('iq', 'echo time();')) - 1;
  h.sleep(2100);
  h.certifyAll();
  try {
    h.wp('eg', 'option', 'update', 'ym_test_ledger_readonly', '1');
    assert.equal(h.wpEval('eg', `echo YM_Consent::record("${subject}") ? "in ledger" : "queued";`), 'queued');
    assert.equal(h.wpEval('iq', `echo YM_Consent::clearance("${subject}", ${placed}, ${decided});`), 'clear', 'the window: Iraq cannot know yet');
    assert.match(h.wpEval('iq', `echo YM_Consent::clearance("${subject}", ${placed}, ${Number(h.wpEval('iq', 'echo time();')) + 1});`), /^wait:certificate_pending/, 'a decision made after the failed write waits instead');
  } finally {
    h.wp('eg', 'option', 'delete', 'ym_test_ledger_readonly');
  }
  h.wpEval('eg', 'YM_Consent::certify();');
  assert.match(h.wpEval('iq', `echo YM_Consent::clearance("${subject}", ${placed}, ${decided});`), /^withdrawn:/, 'closed once Egypt flushes');
});

test('Home hero is curated, never picked automatically: Iraq shows the rack; a conditional or unchosen product leaves the hero without a product picture', async () => {
  const { ctx, page } = await h.context(browser);
  const hero = async () => { await page.goto(`${h.BASE.iq}/`); return page.locator('.ym-hero__caption').count().then(async (n) => (n ? page.locator('.ym-hero__caption').innerText() : '')); };
  assert.equal(h.wp('iq', 'option', 'get', 'ym_hero_sku'), 'IQ030403SENK99');
  assert.match(await hero(), /رف فوق السنك/);
  try {
    h.wp('iq', 'ym', 'hero', 'IQ030301SOBA99');
    assert.match(h.wp('iq', 'ym', 'hero'), /"shown":false.*conditional/, 'the heater has open electrical questions');
    assert.equal(await hero(), '', 'no automatic substitute');
    assert.equal(await page.locator('.ym-hero--text [data-ym-hero-cta]').count(), 1, 'message and one action remain');
    h.wp('iq', 'ym', 'hero', '--clear');
    assert.equal(await hero(), '');
  } finally {
    h.wp('iq', 'ym', 'hero', 'IQ030403SENK99');
  }
  await ctx.close();
});
