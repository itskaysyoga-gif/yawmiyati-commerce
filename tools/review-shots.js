// Review screenshots straight from the local stores (dev/serve.sh), phone (390 px) and desktop (1280 px), for review
// outside Claude. Every image carries a PREVIEW ONLY stamp with commit, data revision and time; a local store is not the
// production site. Covers home, catalog, category, product (tall and wide photos), a long Arabic title, an unavailable
// item, an empty search, the empty cart, a cart with items, checkout and the confirmation page, in Egypt and Iraq.
// Usage: node tools/review-shots.js <outDir> [--allow-dirty]      Then: the PDF is <outDir>/review.pdf
// The PDF has a short main part (the screens a customer sees on the way to an order, phone first) and an appendix of
// edge cases. Long pages are cut into screen-height parts so no picture is split across PDF pages and no heading sits
// alone on a page. It refuses to run on uncommitted plugin/theme changes (the stamp must name a real commit) and on a
// production store (the two temporary review products must never exist there).
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
  if (COMMIT.endsWith('-dirty') && !process.argv.includes('--allow-dirty')) { console.error(`uncommitted plugin/theme changes (${COMMIT}): commit first, or pass --allow-dirty for a draft`); process.exit(2); }
  for (const cc of ['eg', 'iq']) if (wp(cc, 'eval', 'echo YM_Env::is_production() ? "prod" : "ok";') !== 'ok') { console.error(`${cc} is a production store: refusing`); process.exit(2); }
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
        const snap = async (name, title, part = 'main', full = true) => {
          await page.evaluate((st) => { const d = document.createElement('div'); d.textContent = st; d.setAttribute('style', 'position:fixed;top:0;left:0;right:0;z-index:99999;background:rgba(31,29,30,.88);color:#fff;font:11px/1.6 ui-monospace,monospace;padding:3px 8px;direction:ltr;text-align:left'); document.body.appendChild(d); }, STAMP);
          await page.evaluate(() => document.fonts && document.fonts.ready);
          const vp = page.viewportSize();
          const height = full ? await page.evaluate(() => document.documentElement.scrollHeight) : vp.height;
          const step = Math.round(vp.height * 1.5);
          const max = vp.width < 600 ? 4 : 2; // phone parts sit four abreast, desktop two, so a figure always fits one page
          const parts = Math.min(max, Math.ceil(height / step));
          const files = [];
          for (let i = 0; i < parts; i++) {
            const file = `${String(shots.length + 1).padStart(2, '0')}-${cc}-${label}-${name}${parts > 1 ? '-' + (i + 1) : ''}.png`;
            const y = i * step;
            await page.screenshot({ path: path.join(OUT, file), fullPage: true, clip: { x: 0, y, width: vp.width, height: Math.min(step, height - y) } });
            files.push(file);
          }
          shots.push({ files, part, device: label, title: `${cc === 'eg' ? 'Egypt' : 'Iraq'} · ${label} · ${title}${height > step * max ? ' (first part of a long page)' : ''}` });
        };
        const go = async (u) => { await page.goto(BASE[cc] + u, { waitUntil: 'networkidle' }); await page.evaluate(() => document.querySelectorAll('img[loading=lazy]').forEach((i) => i.removeAttribute('loading'))); await page.waitForLoadState('networkidle'); };
        await go('/'); await snap('home', 'home');
        await go('/shop/'); await snap('catalog', 'catalog (cards: same 4:5 white card; wide photos shown whole)');
        await go(cc === 'eg' ? '/product-category/comfort/' : '/product-category/kitchen/'); await snap('category', 'category', 'appendix');
        await go(`/product/${S[cc].buy}/`); await snap('product', 'product page (gallery and buy panel first)');
        await go(cc === 'eg' ? '/product/double-clothes-rack/' : '/product/corner-clothes-organizer/'); await snap('product-2', cc === 'eg' ? 'product: tall item' : 'product with a recommendation', 'appendix');
        await go(cc === 'eg' ? '/product/wearable-blanket/' : '/product/magnetic-window-cleaner/'); await snap('product-wide', 'product: wide photo (weak card, flagged)', 'appendix');
        await go('/product/long-title-review/'); await snap('long-title', 'long Arabic title (temporary review product, deleted after)', 'appendix');
        await go('/product/unavailable-review/'); await snap('unavailable', 'unavailable item (temporary review product, deleted after)', 'appendix');
        await go('/?s=%D8%BA%D8%B3%D8%A7%D9%84%D8%A9+%D9%81%D8%B6%D8%A7%D8%A6%D9%8A%D8%A9&post_type=product'); await snap('search-empty', 'search with no result', 'appendix');
        await go('/cart/'); await snap('cart-empty', 'empty cart', 'appendix');
        await go(`/product/${S[cc].buy}/`);
        await Promise.all([page.waitForLoadState('networkidle'), page.locator('button[value="cart"]').click()]);
        await go(cc === 'eg' ? '/product/heater-2in1-remote/' : '/product/absorbent-mats/');
        await Promise.all([page.waitForLoadState('networkidle'), page.locator('button[value="cart"]').click()]);
        await go('/cart/'); await snap('cart', 'cart with two items (+ suggestion where a genuine pair exists)');
        await go('/checkout/'); await snap('checkout', 'checkout before a governorate: products subtotal, delivery after choosing');
        await page.fill('#billing_first_name', S[cc].name);
        await page.fill('#billing_phone', S[cc].phone + String(Date.now()).slice(-(cc === 'eg' ? 8 : 7)));
        await page.evaluate((st) => jQuery('#billing_state').val(st).trigger('change'), S[cc].state);
        await page.fill('#billing_address_1', S[cc].area);
        await page.waitForTimeout(1500);
        await snap('checkout-filled', 'checkout after choosing: one total with delivery', 'main', false);
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
        shots.push({ files: [file], part: 'main', device: label, title: `${cc === 'eg' ? 'Egypt' : 'Iraq'} · ${label} · consent card (first visit)` });
        await c2.close();
        await ctx.close();
      }
    }
    // One PDF. Every figure (caption + its parts) is one block that never breaks across pages; phone parts sit side by
    // side, each scaled to fit the page height. Main part first, appendix after.
    const fig = (s) => `<figure class="${s.device}"><figcaption>${s.title}</figcaption><div class="parts">${s.files.map((f) => `<img src="file://${path.join(OUT, f)}">`).join('')}</div></figure>`;
    const main = shots.filter((s) => s.part === 'main');
    const appx = shots.filter((s) => s.part === 'appendix');
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>
      @page { size: A4 landscape; margin: 10mm; }
      body { margin: 0; font: 12px system-ui; color: #222; }
      h1 { font-size: 18px; margin: 0 0 6px; } h2 { font-size: 15px; margin: 0 0 8px; break-after: avoid; }
      .cover { break-after: page; }
      figure { margin: 0 0 6mm; break-inside: avoid; page-break-inside: avoid; break-after: page; }
      figcaption { font-weight: 600; margin-bottom: 4px; }
      .parts { display: flex; gap: 4mm; align-items: flex-start; }
      .phone .parts img { height: 168mm; width: auto; border: 1px solid #ddd; }
      .desktop .parts img { width: calc((100% - 4mm) / 2); height: auto; max-height: 168mm; object-fit: contain; object-position: top; border: 1px solid #ddd; }
    </style></head><body>
      <section class="cover"><h1>Yawmiyati review screenshots</h1><p>${STAMP}</p>
      <p>Local stores with interim supplier photos and AI-reviewed draft copy (not native-reviewed). Prices are proposals (docs/ASSORTMENT-v1.md). Layout approval and photo approval are separate: the photos are interim supplier images and are not offered for approval.</p>
      <p>Main: ${main.length} screens on the way to an order. Appendix: ${appx.length} edge cases.</p></section>
      <h2>Main</h2>${main.map(fig).join('')}
      <h2>Appendix: edge cases</h2>${appx.map(fig).join('')}
    </body></html>`;
    fs.writeFileSync(path.join(OUT, 'index.html'), html);
    const pg = await browser.newPage();
    await pg.goto('file://' + path.join(OUT, 'index.html'), { waitUntil: 'load' });
    await pg.pdf({ path: path.join(OUT, 'review.pdf'), preferCSSPageSize: true, printBackground: true });
    fs.writeFileSync(path.join(OUT, 'index.json'), JSON.stringify({ stamp: STAMP, shots }, null, 1));
  } finally {
    await browser.close();
    for (const cc of ['eg', 'iq']) wp(cc, 'eval', `foreach (array(${tmp[cc]}) as $id) { wp_delete_post($id, true); }`);
  }
  console.log(`${shots.length} screenshots and review.pdf in ${OUT}`);
})();
