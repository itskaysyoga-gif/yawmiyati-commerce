// Customer permissions and email (29 Oct brief §7). Local only: messages and Brevo calls are captured, never sent.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const h = require('./helpers');

let browser;
const FLOWS = JSON.stringify(['transactional', 'newsletter', 'post_delivery', 'cross_sell']);
const outbox = (cc) => JSON.parse(h.wpEval(cc, 'echo wp_json_encode( get_option( "ym_test_mail_outbox", array() ) );'));
const brevo = (cc) => JSON.parse(h.wpEval(cc, 'echo wp_json_encode( get_option( "ym_test_brevo_calls", array() ) );'));
const contact = (cc, email) => JSON.parse(h.wpEval(cc, `echo wp_json_encode( YM_Mail::contact( "${email}" ) );`));
function flows(on) {
  for (const cc of ['eg', 'iq']) {
    if (on) {
      h.wp(cc, 'option', 'update', 'ym_email_flows', FLOWS, '--format=json');
      h.wp(cc, 'option', 'update', 'ym_test_mail_capture', '1');
      h.wp(cc, 'option', 'update', 'ym_test_webhook_key', 'hook-key-test');
    } else {
      for (const k of ['ym_email_flows', 'ym_test_mail_capture', 'ym_test_webhook_key']) h.wp(cc, 'option', 'delete', k);
    }
    h.wp(cc, 'option', 'delete', 'ym_test_mail_outbox');
    h.wp(cc, 'option', 'delete', 'ym_test_brevo_calls');
  }
}
before(async () => { browser = await chromium.launch(); });
after(async () => { flows(false); await browser.close(); });

test('Email off (default): checkout has no email field, a phone-only order gets no made-up address, nothing is sent', async () => {
  flows(false);
  h.wp('eg', 'option', 'update', 'ym_test_mail_capture', '1');
  const { ctx, page } = await h.context(browser);
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  assert.equal(await page.locator('#billing_email').count(), 0, 'no receipt promise while receipts are not sent');
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone: h.phone.eg() });
  await Promise.all([page.waitForURL(/order-received\/(\d+)/, { timeout: 30000 }), page.locator('#place_order').click()]);
  const id = Number(page.url().match(/order-received\/(\d+)/)[1]);
  assert.equal(h.wpEval('eg', `echo wc_get_order(${id})->get_billing_email();`), '');
  assert.equal(await page.locator('[data-ym-news]').count(), 0, 'no signup while the newsletter is off');
  assert.deepEqual(outbox('eg'), []);
  h.wp('eg', 'option', 'delete', 'ym_test_mail_capture');
  await ctx.close();
});

test('Receipts on: email is optional; a phone-only order still works with no address; a given address gets the COD wording (a request, not a confirmation or payment)', async () => {
  flows(true);
  const { ctx, page } = await h.context(browser);
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  assert.equal(await page.locator('#billing_email').count(), 1);
  assert.equal(await page.locator('#billing_email').getAttribute('aria-required'), null);
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone: h.phone.eg() });
  await Promise.all([page.waitForURL(/order-received\/(\d+)/, { timeout: 30000 }), page.locator('#place_order').click()]);
  const phoneOnly = Number(page.url().match(/order-received\/(\d+)/)[1]);
  assert.equal(h.wpEval('eg', `echo wc_get_order(${phoneOnly})->get_billing_email();`), '', 'no fabricated email');
  assert.deepEqual(outbox('eg'), [], 'nothing to send to');
  // Signup after ordering: separate, unticked.
  assert.equal(await page.locator('[data-ym-news]').count(), 1, 'one signup on the page');
  assert.equal(await page.locator('[data-ym-news][data-src=after_order] input[name=consent]').isChecked(), false);
  // With an address: one receipt with the right state.
  const email = `buyer${Date.now()}@example.test`;
  await page.goto(`${h.BASE.eg}/product/absorbent-mats/`);
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await h.fillCheckout(page, { ...h.CUSTOMER.eg, phone: h.phone.eg() });
  await page.fill('#billing_email', email);
  await Promise.all([page.waitForURL(/order-received\/(\d+)/, { timeout: 30000 }), page.locator('#place_order').click()]);
  const id = Number(page.url().match(/order-received\/(\d+)/)[1]);
  const mails = outbox('eg').filter((m) => m.to === email);
  assert.equal(mails.length, 1);
  assert.equal(mails[0].kind, 'order_received');
  assert.equal(mails[0].marketing, false);
  assert.match(mails[0].subject, new RegExp(`EG-${id}`), 'country-prefixed order reference');
  assert.match(mails[0].text, /ما اتأكدش/);
  assert.match(mails[0].html, /dir="rtl"/);
  assert.ok(!/List-Unsubscribe/.test(JSON.stringify(mails[0].headers)), 'transactional mail is not marketing');
  assert.equal(contact('eg', email), null, 'ordering is not a newsletter signup');
  // A verified confirmation sends the "confirmed" mail once, however often the event is replayed.
  h.wp('eg', 'ym', 'outcome', String(id), 'confirmed', '--evidence=test: call');
  h.wpEval('eg', `YM_Mail::transactional( wc_get_order(${id}), "confirmed" );`);
  assert.equal(outbox('eg').filter((m) => m.to === email && m.kind === 'order_confirmed').length, 1);
  await ctx.close();
});

test('Newsletter: explicit consent, confirmed address, welcome with one-click unsubscribe; unsubscribing in Egypt suppresses Iraq; a sync never re-subscribes', async () => {
  flows(true);
  const email = `reader${Date.now()}@example.test`;
  const { ctx, page } = await h.context(browser);
  await page.goto(`${h.BASE.eg}/delivery/`);
  const form = page.locator('footer [data-ym-news]');
  await form.locator('input[name=email]').fill(email);
  await form.locator('button').click();
  await page.waitForFunction(() => document.querySelector('footer [data-ym-news-msg]').textContent.length > 0);
  assert.equal(contact('eg', email), null, 'no consent ticked: nothing stored');
  await form.locator('input[name=consent]').check();
  await form.locator('button').click();
  await page.waitForFunction(() => /رسالة تأكيد/.test(document.querySelector('footer [data-ym-news-msg]').textContent));
  let c = contact('eg', email);
  assert.equal(c.status, 'pending');
  assert.equal(c.consent_wording, '2026-10-29');
  assert.equal(c.source, 'footer');
  assert.equal(c.country, 'EG');
  const confirm = outbox('eg').find((m) => m.to === email && m.kind === 'confirm');
  const link = confirm.html.match(/href="([^"]*ym_confirm=[^"]+)"/)[1].replace(/&#038;|&amp;/g, '&');
  assert.ok(!outbox('eg').some((m) => m.to === email && m.marketing), 'no marketing before confirmation');
  await page.goto(link);
  assert.equal(contact('eg', email).status, 'pending', 'opening the link changes nothing (mail scanners)');
  await Promise.all([page.waitForLoadState('load'), page.locator('[data-ym-mail-action=confirm]').click()]);
  await page.waitForSelector('[data-ym-mail-result]');
  assert.equal(await page.locator('[data-ym-mail-result]').getAttribute('data-ym-mail-result'), 'news_confirmed');
  c = contact('eg', email);
  assert.equal(c.status, 'subscribed');
  const welcome = outbox('eg').find((m) => m.to === email && m.kind === 'welcome');
  assert.ok(welcome && welcome.marketing);
  assert.match(welcome.headers['List-Unsubscribe'], /ym_unsub=/);
  assert.equal(welcome.headers['List-Unsubscribe-Post'], 'List-Unsubscribe=One-Click');
  assert.ok(brevo('eg').some((b) => b.email === email && b.fields.emailBlacklisted === false), 'Brevo contact created on confirmation only');
  // Cross-store cap: the same person, one marketing mail a week across all stores and flows.
  assert.equal(h.wpEval('iq', `echo YM_Mail::send_marketing( YM_Mail::contact("${email}"), "test", "x1", "s", array("b") ) ? "sent" : "held";`), 'held');
  // One-click unsubscribe (as a mail client does it), then nothing anywhere.
  const unsub = welcome.headers['List-Unsubscribe'].slice(1, -1);
  const r = await fetch(unsub, { method: 'POST', body: 'List-Unsubscribe=One-Click', headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
  assert.equal(r.status, 200);
  assert.equal(contact('iq', email).status, 'unsubscribed', 'the shared record: Iraq sees it');
  assert.ok(brevo('eg').some((b) => b.email === email && b.fields.emailBlacklisted === true));
  h.wpEval('iq', `global $wpdb; $wpdb->query( "DELETE FROM \`ym_shared\`.\`ym_mail_log\` WHERE kind = 'welcome'" );`);
  assert.equal(h.wpEval('iq', `echo YM_Mail::send_marketing( YM_Mail::contact("${email}"), "test", "x2", "s", array("b") ) ? "sent" : "held";`), 'held');
  h.wpEval('iq', `YM_Mail::import( array( array( "email" => "${email}", "status" => "subscribed" ) ) );`);
  assert.equal(contact('iq', email).status, 'unsubscribed', 'an import never re-subscribes');
  await ctx.close();
});

test('Provider webhook: authenticated, bounces and complaints suppress, each event applies once', async () => {
  flows(true);
  const email = `bounce${Date.now()}@example.test`;
  const post = (body, key) => fetch(`${h.BASE.iq}/wp-json/ym/v1/mail-events`, { method: 'POST', headers: { 'Content-Type': 'application/json', ...(key ? { 'X-YM-Key': key } : {}) }, body: JSON.stringify(body) });
  const ev = { event: 'hard_bounce', email, 'message-id': '<m1@test>', ts_event: 1700000000 };
  assert.equal((await post(ev)).status, 403);
  assert.equal((await post(ev, 'wrong')).status, 403);
  assert.equal((await (await post(ev, 'hook-key-test')).json()).applied, 1);
  assert.equal((await (await post(ev, 'hook-key-test')).json()).applied, 0, 'replayed event ignored');
  assert.equal(contact('eg', email).status, 'bounced', 'every store sees it');
  await post({ event: 'spam', email, 'message-id': '<m2@test>', ts_event: 1700000100 }, 'hook-key-test');
  assert.equal(contact('eg', email).status, 'complained');
  await post({ event: 'unsubscribed', email, 'message-id': '<m3@test>', ts_event: 1700000200 }, 'hook-key-test');
  assert.equal(contact('eg', email).status, 'complained', 'a weaker event never lowers a complaint');
});
