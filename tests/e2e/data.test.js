// Data consistency (29 Oct brief §2): one source for prices, the stores show that source, copy files stay in step,
// and nothing that belongs to the static preview can reach a production build. No browser needed.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const h = require('./helpers');

function csv(file) {
  const text = fs.readFileSync(file, 'utf8').trim().split('\n');
  const split = (line) => { const out = []; let cur = ''; let q = false; for (const c of line) { if (c === '"') q = !q; else if (c === ',' && !q) { out.push(cur); cur = ''; } else cur += c; } out.push(cur); return out; };
  const head = split(text[0]);
  return text.slice(1).map((l) => Object.fromEntries(split(l).map((v, i) => [head[i], v])));
}

test('Prices: every seeded offer listed in the assortment uses its proposed price, and the store sells at that price', () => {
  const wave = csv(path.join(h.ROOT, 'research/selection/first_wave.csv'));
  const proposed = Object.fromEntries(wave.map((r) => [r.taager_sku, Number(r.proposed_price)]));
  for (const cc of ['eg', 'iq']) {
    const seed = JSON.parse(fs.readFileSync(path.join(h.ROOT, `plugin/yawmiyati-commerce/seed/${cc}.json`), 'utf8'));
    assert.match(seed.source, /first_wave\.csv/, 'seed names its price source');
    const live = JSON.parse(h.wpEval(cc, 'echo wp_json_encode( array_column( array_map( fn( $p ) => array( $p->get_sku(), (float) $p->get_regular_price() ), wc_get_products( array( "limit" => -1, "status" => "any" ) ) ), 1, 0 ) );'));
    for (const p of seed.products) {
      if (p.sku in proposed) assert.equal(p.price, proposed[p.sku], `${cc} ${p.sku}: seed ${p.price} vs assortment ${proposed[p.sku]}`);
      if (p.sku in live) assert.equal(live[p.sku], p.price, `${cc} ${p.sku}: store ${live[p.sku]} vs seed ${p.price}`);
    }
  }
});

test('Copy: Egyptian, Iraqi and shared Gulf files carry the same keys', () => {
  const keys = (f) => Object.keys(JSON.parse(fs.readFileSync(path.join(h.ROOT, `plugin/yawmiyati-commerce/copy/${f}.json`), 'utf8'))).filter((k) => !k.startsWith('_')).sort();
  const eg = keys('eg');
  assert.deepEqual(keys('iq'), eg);
  assert.deepEqual(keys('gulf'), eg);
});

test('Production build: no preview banner, preview script, local address or forced noindex in the theme or plugin', () => {
  const files = [];
  (function walk(d) { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); if (fs.statSync(p).isDirectory()) walk(p); else if (/\.(php|html|js|css|json)$/.test(f)) files.push(p); } })(path.join(h.ROOT, 'plugin'));
  (function walk(d) { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); if (fs.statSync(p).isDirectory()) walk(p); else if (/\.(php|html|js|css|json)$/.test(f)) files.push(p); } })(path.join(h.ROOT, 'theme'));
  for (const f of files) {
    const t = fs.readFileSync(f, 'utf8');
    assert.ok(!/localhost:808\d|127\.0\.0\.1:808\d/.test(t), `local store address in ${f}`);
    assert.ok(!/ym-preview|نسخة معاينة/.test(t), `preview-only markup in ${f}`);
  }
  // Indexing on production is decided by the market gates and is tested in seo.test.js; the preview's notice and
  // disabled ordering live only in tools/export-preview.js.
  const tool = fs.readFileSync(path.join(h.ROOT, 'tools/export-preview.js'), 'utf8');
  assert.match(tool, /PREVIEW ONLY/, 'the preview carries its notice');
});
