// Review screenshots straight from the local stores (dev/serve.sh), phone (390 px) and desktop (1280 px), for review
// outside Claude. Every image carries a PREVIEW ONLY stamp with commit, data revision and time; a local store is not the
// production site. Covers home, catalog, category, product (tall and wide photos), a long Arabic title, an unavailable
// item, an empty search, the empty cart, a cart with items, checkout and the confirmation page, in Egypt and Iraq.
// Usage: node tools/review-shots.js <outDir>      Then: the PDF is <outDir>/review.pdf
const { chromium } = require('playwright');
const { execFileSync, execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..');
const OUT = path.resolve(process.argv[2] || 'review-shots');
const wp = (cc, ...a) => execFileSync(path.join(ROOT, 'dev/wp.sh'), [cc, ...a], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
const COMMIT = (() => { try { const c = execSync('git rev-parse --short HEAD', { cwd: ROOT }).toString().trim(); const d = execSync('git status --porcelain -- plugin theme', { cwd: ROOT }).toString().trim(); return c + (d ? '-dirty' : ''); } catch { return 'unknown'; } })();
const DATA = crypto.createHash('sha256').update(['plugin/yawmiyati-commerce/seed/eg.json', 'plugin/yawmiyati-commerce/seed/iq.json', 'research/selection/first_wave.csv'].map((f) => fs.readFileSync(path.join(ROOT, f))).join('\n')).digest('hex').slice(0, 10);
const WHEN = new Date().toISOString().replace(/\.\d+Z$/, 'Z');
const STAMP = `PREVIEW ONLY · local store, not production · commit ${COMMIT} · data ${DATA} · ${WHEN}`;
const BASE = { eg: 'http://localhost:8081', iq: 'http://localhost:8082' };
const S = { eg: { buy: 'absorbent-mats', state: 'EGC', phone: '010', name: 'منى أحمد', area: 'مدينة نصر، شارع عباس العقاد' }, iq: { buy: 'sink-rack', state: 'BG', phone: '0770', name: 'زينب علي', area: 'الكرادة، قرب ساحة الحرية' } };

(async () => {
  fs.rmSync(OUT, { recursive: true, force: true });
  fs.mkdirSync(OUT, { recursive: true });
  // Temporary edge-case products (removed at the end): a long Arabic title (in stock) and an unavailable item.
  const tmp = {};
  for (const cc of ['eg', 'iq']) {
    tmp[cc] = wp(cc, 'eval', `$ids=array(); foreach (array(array("long-title-review","instock"),array("unavailable-review","outofstock")) as $x) { $p=new WC_Product_Simple(); $p->set_name($x[0]==="long-title-review" ? "منظّم أدراج المطبخ متعدد الاستخدامات بأقسام قابلة للتعديل ومقاسات مختلفة يناسب الأدراج العميقة والضيقة" : "منتج غير متاح حاليًا للمعاينة"); $p->set_slug($x[0]); $p->set_regular_price("${cc === 'eg' ? 299 : 25000}"); $p->set_status("publish"); $p->set_stock_status($x[1]); $p->update_meta_data("_ym_taager_cost", 1); $p->update_meta_data("_ym_taager_product_id", 1); $p->set_sku("REVIEW-" . $x[0]); $p->save(); $ids[]=$p->get_id(); } echo implode(",", $ids);`);
  }
  const browser = await chromium.launch();
  const shots = [];
  try {
    for (const [w, label] of [[390, 'phone'], [1280, 'desktop']]) {
      for (const cc of ['eg', 'iq']) {
        const ctx = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: w < 600 ? 2 : 1, locale: 'ar' });
        await ctx.addCookies(Object.values(BASE).map((u) => ({ name: 'ym_consent', value: 'denied', url: u })));
        await ctx.setExtraHTTPHeaders({ 'X-YM-Test-Clock': '30', 'X-YM-Test-Ip': `10.9.${w % 250}.${cc === 'eg' ? 1 : 2}` });
        const page = await ctx.newPage();
        const snap = async (name, title, full = true) => {
          await page.evaluate((st) => { const d = document.createElement('div'); d.textContent = st; d.setAttribute('style', 'position:fixed;top:0;left:0;right:0;z-index:99999;background:rgba(31,29,30,.88);color:#fff;font:11px/1.6 ui-monospace,monospace;padding:3px 8px;direction:ltr;text-align:left'); document.body.appendChild(d); }, STAMP);
          await page.evaluate(() => document.fonts && document.fonts.ready);
          const file = `${String(shots.length + 1).padStart(2, '0')}-${cc}-${label}-${name}.png`;
          await page.screenshot({ path: path.join(OUT, file), fullPage: full });
          shots.push({ file, title: `${cc === 'eg' ? 'Egypt' : 'Iraq'} · ${label} · ${title}` });
        };
        const go = async (u) => { await page.goto(BASE[cc] + u, { waitUntil: 'networkidle' }); await page.evaluate(() => document.querySelectorAll('img[loading=lazy]').forEach((i) => i.removeAttribute('loading'))); await page.waitForLoadState('networkidle'); };
        await go('/'); await snap('home', 'home');
        await go('/shop/'); await snap('catalog', 'catalog (cards: same 4:5 white card; wide photos shown whole)');
        await go(cc === 'eg' ? '/product-category/comfort/' : '/product-category/kitchen/'); await snap('category', 'category');
        await go(`/product/${S[cc].buy}/`); await snap('product', 'product page (gallery and buy panel first)');
        await go(cc === 'eg' ? '/product/double-clothes-rack/' : '/product/corner-clothes-organizer/'); await snap('product-2', cc === 'eg' ? 'product: tall item' : 'product with a recommendation');
        await go(cc === 'eg' ? '/product/wearable-blanket/' : '/product/magnetic-window-cleaner/'); await snap('product-wide', 'product: wide photo (weak card, flagged)');
        await go('/product/long-title-review/'); await snap('long-title', 'long Arabic title');
        await go('/product/unavailable-review/'); await snap('unavailable', 'unavailable item');
        await go('/?s=%D8%BA%D8%B3%D8%A7%D9%84%D8%A9+%D9%81%D8%B6%D8%A7%D8%A6%D9%8A%D8%A9&post_type=product'); await snap('search-empty', 'search with no result');
        await go('/cart/'); await snap('cart-empty', 'empty cart');
        await go(`/product/${S[cc].buy}/`);
        await Promise.all([page.waitForLoadState('networkidle'), page.locator('button[value="cart"]').click()]);
        await go(cc === 'eg' ? '/product/heater-2in1-remote/' : '/product/absorbent-mats/');
        await Promise.all([page.waitForLoadState('networkidle'), page.locator('button[value="cart"]').click()]);
        await go('/cart/'); await snap('cart', 'cart with two items (+ suggestion where a genuine pair exists)');
        await go('/checkout/'); await snap('checkout', 'checkout');
        await page.fill('#billing_first_name', S[cc].name);
        await page.fill('#billing_phone', S[cc].phone + String(Date.now()).slice(-(cc === 'eg' ? 8 : 7)));
        await page.evaluate((st) => jQuery('#billing_state').val(st).trigger('change'), S[cc].state);
        await page.fill('#billing_address_1', S[cc].area);
        await page.waitForTimeout(1500);
        await Promise.all([page.waitForURL(/order-received/, { timeout: 30000 }), page.locator('#place_order').click()]);
        await page.waitForLoadState('networkidle');
        await snap('thanks', 'order confirmation (a request: not confirmed, not paid)');
        // The consent card as a first-time visitor sees it, over a product page (fold only).
        const c2 = await browser.newContext({ viewport: { width: w, height: w < 600 ? 844 : 900 }, deviceScaleFactor: w < 600 ? 2 : 1, locale: 'ar' });
        const p2 = await c2.newPage();
        await p2.goto(`${BASE[cc]}/product/${S[cc].buy}/`, { waitUntil: 'networkidle' });
        const file = `${String(shots.length + 1).padStart(2, '0')}-${cc}-${label}-consent.png`;
        await p2.evaluate((st) => { const d = document.createElement('div'); d.textContent = st; d.setAttribute('style', 'position:fixed;top:0;left:0;right:0;z-index:99999;background:rgba(31,29,30,.88);color:#fff;font:11px/1.6 ui-monospace,monospace;padding:3px 8px;direction:ltr;text-align:left'); document.body.appendChild(d); }, STAMP);
        await p2.screenshot({ path: path.join(OUT, file) });
        shots.push({ file, title: `${cc === 'eg' ? 'Egypt' : 'Iraq'} · ${label} · consent card (first visit)` });
        await c2.close();
        await ctx.close();
      }
    }
    // One PDF: a page per screenshot with its caption.
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>body{margin:0;font:13px system-ui}section{page-break-after:always;padding:12px}h2{font-size:14px;margin:0 0 8px}img{max-width:100%;max-height:1500px;border:1px solid #ddd}</style></head><body><section><h1>Yawmiyati review screenshots</h1><p>${STAMP}</p><p>Local stores with interim supplier photos and draft copy. Prices are proposals (docs/ASSORTMENT-v1.md).</p></section>${shots.map((s) => `<section><h2>${s.title}</h2><img src="file://${path.join(OUT, s.file)}"></section>`).join('')}</body></html>`;
    fs.writeFileSync(path.join(OUT, 'index.html'), html);
    const pg = await browser.newPage();
    await pg.goto('file://' + path.join(OUT, 'index.html'), { waitUntil: 'load' });
    await pg.pdf({ path: path.join(OUT, 'review.pdf'), format: 'A4', printBackground: true });
    fs.writeFileSync(path.join(OUT, 'index.json'), JSON.stringify({ stamp: STAMP, shots }, null, 1));
  } finally {
    await browser.close();
    for (const cc of ['eg', 'iq']) wp(cc, 'eval', `foreach (array(${tmp[cc]}) as $id) { wp_delete_post($id, true); }`);
  }
  console.log(`${shots.length} screenshots and review.pdf in ${OUT}`);
})();
