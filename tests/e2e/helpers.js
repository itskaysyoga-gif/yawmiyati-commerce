// Shared helpers for the end-to-end tests. Stores must be running: dev/serve.sh
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '../..');
const BASE = { eg: 'http://localhost:8081', iq: 'http://localhost:8082' };

function wp(cc, ...args) {
  return execFileSync(path.join(ROOT, 'dev/wp.sh'), [cc, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
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
    "phone2" => $o->get_meta("_ym_phone2"), "landmark" => $o->get_meta("_ym_landmark")
  ));`));
}

const CUSTOMER = {
  eg: { name: 'منى أحمد', phone: '01012345678', state: 'EGC', area: 'مدينة نصر، شارع عباس العقاد', building: 'عمارة 5، الدور 3' },
  iq: { name: 'زينب علي', phone: '07701234567', state: 'BG', area: 'الكرادة، قرب ساحة الحرية', building: '' },
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

module.exports = { BASE, ROOT, wp, wpEval, order, orderCount, buy, fillCheckout, waitIdle, CUSTOMER };
