// Supplier hand-off states, re-checks, reconciliation and the manual-entry guard.
// These run against our own tracking only. Taager's real behaviour (which meta key its plugin writes, how it
// treats on-hold orders, whether it rejects duplicates) is NOT tested here and stays a verification task.
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { chromium } = require('playwright');
const h = require('./helpers');

let browser;
before(async () => { browser = await chromium.launch(); });
after(async () => { await browser.close(); });

async function newOrder() {
  const { ctx, page } = await h.context(browser);
  const id = await h.buy(page, 'eg', { slug: 'absorbent-mats', customer: { phone: h.phone.eg() } });
  await ctx.close();
  return id;
}
const status = (id) => h.order('eg', id).supplier;
const fails = (fn, code) => assert.throws(fn, (e) => String(e.stderr || '').includes(code));

test('Timeout: no reference after the wait is "unconfirmed" and another check is scheduled', async () => {
  const id = await newOrder();
  assert.equal(status(id), 'pending');
  assert.equal(h.wp('eg', 'ym', 'supplier-check', String(id)), 'unconfirmed');
  const next = h.wpEval('eg', `echo count(as_get_scheduled_actions(array("hook" => "ym_supplier_check", "args" => array(${id}, 1), "status" => "pending")));`);
  assert.equal(next, '1', 'a re-check at attempt 1 is queued');
});

test('Reconciliation: manual entry is refused until someone records "not found in Taager"', async () => {
  const id = await newOrder();
  h.wp('eg', 'ym', 'supplier-check', String(id));
  fails(() => h.wp('eg', 'ym', 'supplier-manual', String(id), 'TG-1'), 'ym_reconcile_first');
  h.wp('eg', 'ym', 'supplier-reconcile', String(id), '--not-found', '--by=test');
  assert.equal(status(id), 'not_found');
  h.wp('eg', 'ym', 'supplier-manual', String(id), 'TG-1');
  assert.equal(status(id), 'manual');
  fails(() => h.wp('eg', 'ym', 'supplier-manual', String(id), 'TG-2'), 'ym_already_sent');
});

test('Reconciliation: a "not found" older than two hours no longer allows manual entry', async () => {
  const id = await newOrder();
  h.wp('eg', 'ym', 'supplier-check', String(id));
  h.wp('eg', 'ym', 'supplier-reconcile', String(id), '--not-found');
  h.wpEval('eg', `$o = wc_get_order(${id}); $r = json_decode($o->get_meta("_ym_reconciled"), true); $r["at"] -= 3 * HOUR_IN_SECONDS; $o->update_meta_data("_ym_reconciled", wp_json_encode($r)); $o->save();`);
  fails(() => h.wp('eg', 'ym', 'supplier-manual', String(id), 'TG-3'), 'ym_reconcile_first');
});

test('Reconciliation: found in Taager records the reference; no manual entry possible afterwards', async () => {
  const id = await newOrder();
  h.wp('eg', 'ym', 'supplier-check', String(id));
  h.wp('eg', 'ym', 'supplier-reconcile', String(id), '--found=TG-77');
  assert.equal(status(id), 'sent');
  assert.equal(h.order('eg', id).supplier_ref, 'TG-77');
  fails(() => h.wp('eg', 'ym', 'supplier-manual', String(id), 'TG-4'), 'ym_already_sent');
});

test('Late reference: the plugin reports after an unconfirmed check, the order becomes sent', async () => {
  const id = await newOrder();
  h.wp('eg', 'ym', 'supplier-check', String(id));
  h.wpEval('eg', `$o = wc_get_order(${id}); $o->update_meta_data("_taager_order_id", "T-900"); $o->save();`);
  h.wpEval('eg', `YM_Supplier::check(${id}, 1);`);
  assert.equal(status(id), 'sent');
  assert.equal(h.order('eg', id).supplier_ref, 'T-900');
});

test('Conflict: a manual entry followed by a different plugin reference is flagged as a possible double order', async () => {
  const id = await newOrder();
  h.wp('eg', 'ym', 'supplier-check', String(id));
  h.wp('eg', 'ym', 'supplier-reconcile', String(id), '--not-found');
  h.wp('eg', 'ym', 'supplier-manual', String(id), 'TG-5');
  h.wpEval('eg', `$o = wc_get_order(${id}); $o->update_meta_data("_taager_order_id", "T-901"); $o->save();`);
  h.wpEval('eg', `YM_Supplier::check(${id}, 2);`);
  assert.equal(status(id), 'conflict');
});

test('Sweep job: runs, records itself and appears in the health report', async () => {
  h.wp('eg', 'ym', 'ops-run', 'supplier_sweep');
  const health = JSON.parse(h.wpEval('eg', 'echo wp_json_encode(YM_Ops::health()->get_data());'));
  assert.equal(health.jobs.supplier_sweep.stale, false);
});
