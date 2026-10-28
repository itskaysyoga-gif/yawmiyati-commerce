// Static snapshot of the local stores (dev/serve.sh must be running).
// Usage: node tools/export-preview.js [outDir]   (default: preview/)
// Pages are saved as rendered by WordPress with their CSS, fonts, images and scripts copied next to them,
// so the folder opens offline. Ordering is disabled in the snapshot: the buy button jumps to the saved checkout.
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const OUT = path.resolve(process.argv[2] || 'preview');
const STORES = {
  eg: { base: 'http://localhost:8081', name: 'مصر', products: ['absorbent-mats', 'double-clothes-rack', 'heater-2in1-remote', 'wearable-blanket'], buy: 'absorbent-mats', state: 'EGC', phone: '01000000000' },
  iq: { base: 'http://localhost:8082', name: 'العراق', products: ['sink-rack', 'absorbent-mats', 'heater-180', 'corner-clothes-organizer', 'magnetic-window-cleaner', 'double-clothes-rack'], buy: 'sink-rack', state: 'BG', phone: '07700000000' },
};

const saved = new Set();
function assetPath(cc, url) {
  const u = new URL(url);
  return path.join(OUT, cc, decodeURIComponent(u.pathname));
}

async function capture(page, cc, base) {
  page.on('response', async (r) => {
    const url = r.url();
    if (!url.startsWith(base) || r.status() !== 200) return;
    const type = r.request().resourceType();
    if (!['stylesheet', 'script', 'font', 'image'].includes(type)) return;
    const file = assetPath(cc, url);
    if (saved.has(file)) return;
    saved.add(file);
    try {
      const body = await r.body();
      fs.mkdirSync(path.dirname(file), { recursive: true });
      fs.writeFileSync(file, body);
    } catch { /* response gone before it was read */ }
  });
}

// Rewrite absolute local URLs to paths relative to the saved page, and page links to their index.html.
function relocate(html, base, depth, { stripScripts }) {
  const up = depth ? '../'.repeat(depth) : './';
  // Links to the other country's store point at its folder in the snapshot.
  for (const [other, st] of Object.entries(STORES)) {
    if (st.base !== base) html = html.split(st.base + '/').join(up + '../' + other + '/');
  }
  html = html.split(base + '/').join(up);
  html = html.split(base.replace(/\//g, '\\/') + '\\/').join(up.replace(/\//g, '\\/'));
  html = html.replace(/(href|src)="([^"]*?)\?ver=[^"]*"/g, '$1="$2"');
  html = html.replace(/href="((?:\.\.\/|\.\/)[^"#?]*\/)"/g, 'href="$1index.html"');
  html = html.replace(/href="(\.\.\/|\.\/)"/g, 'href="$1index.html"');
  html = html.replace(/\s(srcset|sizes)="[^"]*"/g, '');
  html = html.replace(/href="((?:\.\.\/)*|\.\/)cart\/index\.html"/g, 'href="$1checkout/index.html"');
  html = html.replace(/<link rel="(?:dns-prefetch|alternate|EditURI|https:\/\/api\.w\.org\/|pingback)"[^>]*>/g, '');
  if (stripScripts) html = html.replace(/<script\b[\s\S]*?<\/script>/g, '');
  return html;
}

const BANNER = (up) => `<div style="padding:8px 16px;background:#1F1D1E;color:#fff;font:14px/1.6 'Readex Pro',sans-serif;text-align:center">نسخة معاينة ثابتة من المتجر المحلي. الطلب معطّل هنا. <a href="${up}../index.html" style="color:#fff">كل الصفحات</a></div>`;
const PREVIEW_JS = `<script>document.addEventListener('submit',function(e){var f=e.target;if(f.matches('form.ym-buy, form.cart, form[data-ym]')||f.querySelector('[name=ym_offer]')){e.preventDefault();location.href=document.querySelector('meta[name=ym-preview-checkout]').content;}},true);</script>`;

async function save(page, cc, base, rel, opts = {}) {
  const depth = rel.split('/').filter(Boolean).length;
  const up = depth ? '../'.repeat(depth) : './';
  let html = relocate(await page.content(), base, depth, opts);
  html = html.replace('<head>', `<head><meta name="ym-preview-checkout" content="${up}checkout/index.html">`);
  html = html.replace(/<body([^>]*)>/, `<body$1>${BANNER(up)}`);
  if (!opts.stripScripts) html = html.replace('</body>', PREVIEW_JS + '</body>');
  const file = path.join(OUT, cc, rel, 'index.html');
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, html);
}

(async () => {
  fs.rmSync(OUT, { recursive: true, force: true });
  const browser = await chromium.launch();
  const index = [];
  for (const [cc, s] of Object.entries(STORES)) {
    for (const [w, label] of [[390, 'mobile']]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, locale: 'ar' });
      await ctx.addCookies([{ name: 'ym_consent', value: 'denied', url: s.base }]);
      const page = await ctx.newPage();
      await capture(page, cc, s.base);
      await page.goto(s.base + '/', { waitUntil: 'networkidle' });
      await save(page, cc, s.base, '');
      const links = [['الرئيسية', `${cc}/index.html`]];
      for (const slug of s.products) {
        await page.goto(`${s.base}/product/${slug}/`, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.querySelectorAll('img[loading=lazy]').forEach((i) => i.removeAttribute('loading')));
        await page.waitForLoadState('networkidle');
        await save(page, cc, s.base, `product/${slug}`);
        links.push([await page.locator('h1').first().innerText(), `${cc}/product/${slug}/index.html`]);
      }
      for (const [slug, label] of [['delivery', 'التوصيل والدفع'], ['returns', 'الاستبدال والاسترجاع'], ['faq', 'أسئلة'], ['contact', 'تواصل'], ['privacy', 'الخصوصية']]) {
        await page.goto(`${s.base}/${slug}/`, { waitUntil: 'networkidle' });
        await save(page, cc, s.base, slug);
        links.push([label, `${cc}/${slug}/index.html`]);
      }
      await page.goto(`${s.base}/product/${s.buy}/`, { waitUntil: 'networkidle' });
      await Promise.all([page.waitForURL(/\/checkout\//), page.locator('button[value="buy"]').click()]);
      await page.waitForLoadState('networkidle');
      await save(page, cc, s.base, 'checkout', { stripScripts: true });
      links.push(['صفحة الطلب', `${cc}/checkout/index.html`]);
      await page.fill('#billing_first_name', 'معاينة متجر');
      await page.fill('#billing_phone', s.phone);
      await page.evaluate((st) => jQuery('#billing_state').val(st).trigger('change'), s.state);
      await page.fill('#billing_address_1', 'عنوان تجريبي');
      await page.waitForTimeout(2000);
      await Promise.all([page.waitForURL(/order-received/, { timeout: 30000 }), page.locator('#place_order').click()]);
      await page.waitForLoadState('networkidle');
      await save(page, cc, s.base, 'thanks', { stripScripts: true });
      links.push(['تم الطلب', `${cc}/thanks/index.html`]);
      index.push([s.name, links]);
      await ctx.close();
    }
  }
  await browser.close();
  // Fetch anything a saved page or stylesheet points to that the browser did not load (other image sizes, fonts).
  for (const [cc, s] of Object.entries(STORES)) {
    const root = path.join(OUT, cc);
    const files = [];
    (function walk(d) { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); fs.statSync(p).isDirectory() ? walk(p) : files.push(p); } })(root);
    for (const f of files.filter((x) => /\.(html|css)$/.test(x))) {
      const text = fs.readFileSync(f, 'utf8');
      const refs = [...text.matchAll(/(?:src|href|data-ym-thumb)="([^"#:]+)"|url\(["']?([^)"':]+?)["']?\)/g)].map((m) => (m[1] || m[2]).split('?')[0]);
      for (const ref of refs) {
        const target = path.resolve(path.dirname(f), decodeURIComponent(ref));
        if (!target.startsWith(root) || fs.existsSync(target) || /\/$/.test(ref)) continue;
        const r = await fetch(s.base + '/' + path.relative(root, target).split(path.sep).join('/'));
        if (!r.ok) continue;
        fs.mkdirSync(path.dirname(target), { recursive: true });
        fs.writeFileSync(target, Buffer.from(await r.arrayBuffer()));
        saved.add(target);
      }
    }
  }
  const when = new Date().toISOString().slice(0, 10);
  const list = index.map(([n, ls]) => `<section><h2>${n}</h2><ul>${ls.map(([t, h]) => `<li><a href="${h}">${t}</a></li>`).join('')}</ul></section>`).join('');
  fs.writeFileSync(path.join(OUT, 'index.html'), `<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Yawmiyati preview</title>
<style>:root{--ink:#1F1D1E;--nila:#2B3192;--rule:#E7E5E1;--muted:#5E5A57;--paper:#fff}@media (prefers-color-scheme:dark){:root:not([data-theme=light]){--ink:#F2F1EF;--nila:#AEB4F5;--rule:#3A3836;--muted:#B8B3AE;--paper:#161515}}
body{margin:0;padding:24px 16px;background:var(--paper);color:var(--ink);font:16px/1.7 system-ui,sans-serif}main{max-width:720px;margin:0 auto}h1{font-size:24px;margin:0 0 4px}p{color:var(--muted);margin:0 0 20px}
h2{font-size:18px;margin:20px 0 8px}ul{list-style:none;margin:0;padding:0;border-top:1px solid var(--rule)}li{border-bottom:1px solid var(--rule)}a{display:block;padding:10px 0;color:var(--nila);text-decoration:none}</style></head>
<body><main><h1>معاينة يومياتي</h1><p>صفحات حقيقية من متجر WooCommerce المحلي، محفوظة يوم ${when}. الأسعار من ملف التشكيلة v2. الطلب معطّل في المعاينة.</p>${list}</main></body></html>`);
  console.log('saved', saved.size, 'assets to', OUT);
})();
