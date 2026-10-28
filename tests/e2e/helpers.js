// Shared helpers for the end-to-end tests. Stores must be running: dev/serve.sh
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '../..');
const BASE = { eg: 'http://localhost:8081', iq: 'http://localhost:8082', sa: 'http://localhost:8083', ae: 'http://localhost:8084', om: 'http://localhost:8085' };

function wp(cc, ...args) {
  return execFileSync(path.join(ROOT, 'dev/wp.sh'), [cc, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

function wpEval(cc, php) {
  return wp(cc, 'eval', php);
}

function orderCount(cc) {
  return Number(wpEval(cc, 'echo count( wc_get_orders( array( "limit" => -1, "return" => "ids", "status" => array_keys( wc_get_order_statuses() ) ) ) );'));
}

function order(cc, id) {
  return JSON.parse(wpEval(cc, `$o = wc_get_order(${Number(id)}); echo wp_json_encode(array(
    "status" => $o->get_status(), "total" => (float) $o->get_total(), "shipping" => (float) $o->get_shipping_total(),
    "currency" => $o->get_currency(), "payment" => $o->get_payment_method(), "state" => $o->get_billing_state(),
    "country" => $o->get_billing_country(), "phone" => $o->get_billing_phone(),
    "items" => array_values(array_map(function($i){ return array("sku" => $i->get_product()->get_sku(), "qty" => $i->get_quantity(), "total" => (float) $i->get_total()); }, $o->get_items())),
    "snapshot" => json_decode($o->get_meta("_ym_snapshot"), true), "review" => $o->get_meta("_ym_review"),
    "supplier" => $o->get_meta("_ym_supplier_status"), "supplier_ref" => $o->get_meta("_ym_supplier_ref"),
    "phone2" => $o->get_meta("_ym_phone2"), "landmark" => $o->get_meta("_ym_landmark"),
    "consent" => $o->get_meta("_ym_consent"), "attr" => json_decode($o->get_meta("_ym_attr"), true),
    "capi_meta" => json_decode($o->get_meta("_ym_capi_meta_purchase"), true), "capi_snap" => json_decode($o->get_meta("_ym_capi_snap_purchase"), true),
    "key" => $o->get_order_key(), "token" => $o->get_meta("_ym_token"),
    "capi_meta_delivered" => json_decode($o->get_meta("_ym_capi_meta_delivered"), true), "dl_purchase" => $o->get_meta("_ym_dl_purchase"),
    "consent_subject" => $o->get_meta("_ym_consent_subject"), "events" => json_decode($o->get_meta("_ym_events"), true)
  ));`));
}

const CUSTOMER = {
  eg: { name: 'منى أحمد', phone: '01012345678', state: 'EGC', area: 'مدينة نصر، شارع عباس العقاد', building: 'عمارة 5، الدور 3' },
  iq: { name: 'زينب علي', phone: '07701234567', state: 'BG', area: 'الكرادة، قرب ساحة الحرية', building: '' },
  sa: { name: 'نورة العتيبي', phone: '0512345678', state: 'SA01', area: 'الرياض، حي النرجس، شارع الأمير', building: 'فيلا 12' },
  ae: { name: 'مريم الكعبي', phone: '0501234567', state: 'DU', area: 'دبي، القصيص، شارع 12', building: 'شقة 304' },
  om: { name: 'شيخة البلوشي', phone: '91234567', state: 'MA', area: 'بوشر، الخوير، سكة 33', building: 'منزل 5' },
};

// Product page -> choose offer -> buy now -> checkout -> place order. Returns the order ID.
async function buy(page, cc, { slug, offer = 0, customer = {}, beforeSubmit } = {}) {
  const c = { ...CUSTOMER[cc], ...customer };
  await page.goto(`${BASE[cc]}/product/${slug}/`);
  const radios = page.locator('input[name="ym_offer"][type="radio"]');
  if (await radios.count()) await radios.nth(offer).check({ force: true });
  await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
  await fillCheckout(page, c);
  if (beforeSubmit) await beforeSubmit(page);
  await Promise.all([page.waitForURL(/order-received\/(\d+)/, { timeout: 30000 }), page.locator('#place_order').click()]);
  return Number(page.url().match(/order-received\/(\d+)/)[1]);
}

async function fillCheckout(page, c) {
  await page.waitForSelector('#billing_first_name');
  await page.fill('#billing_first_name', c.name);
  await page.fill('#billing_phone', c.phone);
  if (c.phone2) await page.fill('#ym_phone2', c.phone2);
  await page.evaluate((s) => { jQuery('#billing_state').val(s).trigger('change'); }, c.state);
  await page.fill('#billing_address_1', c.area);
  if (c.building) await page.fill('#billing_address_2', c.building);
  if (c.landmark) await page.fill('#ym_landmark', c.landmark);
  await waitIdle(page);
}

async function waitIdle(page) {
  await page.waitForTimeout(300);
  await page.waitForFunction(() => !document.querySelector('.blockUI') && !(window.jQuery && jQuery.active), null, { timeout: 20000 });
}

// A fresh browser context. Consent defaults to "denied" so the banner does not cover buttons; pass null to see it.
async function context(browser, { width = 390, consent = 'denied', device } = {}) {
  const opts = device ? { ...device, locale: 'ar' } : { viewport: { width, height: 844 }, locale: 'ar' };
  const ctx = await browser.newContext(opts);
  // Test browsers fill forms in about a second; shift the form clock so they are not held as "sent too fast".
  // The fast-submission check itself is tested in risk.test.js with an explicit header.
  // Each test browser is also its own customer connection; otherwise the whole suite looks like one busy IP.
  const ip = `10.${Math.floor(Math.random() * 250)}.${Math.floor(Math.random() * 250)}.${1 + Math.floor(Math.random() * 250)}`;
  await ctx.setExtraHTTPHeaders({ 'X-YM-Test-Clock': '30', 'X-YM-Test-Ip': ip });
  if (consent) {
    await ctx.addCookies(Object.values(BASE).map((u) => ({ name: 'ym_consent', value: consent, url: u })));
  }
  return { ctx, page: await ctx.newPage() };
}

// Unique phone numbers so the duplicate review only fires where a test wants it.
let seq = (Date.now() % 1e6) * 10;
const phone = {
  eg: () => '010' + String(++seq).padStart(8, '0').slice(-8),
  iq: () => '0770' + String(++seq).padStart(7, '0').slice(-7),
  sa: () => '055' + String(++seq).padStart(7, '0').slice(-7),
  ae: () => '050' + String(++seq).padStart(7, '0').slice(-7),
  om: () => '9' + String(++seq).padStart(7, '0').slice(-7),
};

module.exports = { BASE, ROOT, wp, wpEval, order, orderCount, buy, fillCheckout, waitIdle, CUSTOMER, context, phone };
