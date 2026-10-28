// Phone screenshots of the static preview, for review outside Claude (a PDF and PNGs anyone can open).
// Usage: node tools/export-preview.js preview && node tools/screenshots.js preview shots
// Serves the preview folder on a local port and captures each page at 390 px wide (iPhone-class), full height.
const { chromium } = require('playwright');
const http = require('http');
const fs = require('fs');
const path = require('path');

const SRC = path.resolve(process.argv[2] || 'preview');
const OUT = path.resolve(process.argv[3] || 'shots');
const PAGES = [
  ['eg', 'index.html', 'Egypt — home'],
  ['eg', 'shop/index.html', 'Egypt — all products'],
  ['eg', 'product/absorbent-mats/index.html', 'Egypt — product: absorbent mats (1 or 2 sets)'],
  ['eg', 'product/double-clothes-rack/index.html', 'Egypt — product: double clothes rack'],
  ['eg', 'checkout/index.html', 'Egypt — checkout (total before ordering)'],
  ['eg', 'thanks/index.html', 'Egypt — order confirmation'],
  ['eg', 'delivery/index.html', 'Egypt — delivery and payment'],
  ['iq', 'index.html', 'Iraq — home'],
  ['iq', 'product/sink-rack/index.html', 'Iraq — product: over-sink rack'],
  ['iq', 'category/index.html', 'Iraq — category: kitchen'],
  ['iq', 'checkout/index.html', 'Iraq — checkout'],
  ['iq', 'thanks/index.html', 'Iraq — order confirmation'],
  ['om', 'product/dish-rack/index.html', 'Oman (prepared, closed) — product'],
];

const TYPES = { '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.woff2': 'font/woff2', '.woff': 'font/woff', '.ttf': 'font/ttf' };
const server = http.createServer((req, res) => {
  const file = path.join(SRC, decodeURIComponent(new URL(req.url, 'http://x').pathname));
  if (!file.startsWith(SRC) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) { res.writeHead(404); res.end(); return; }
  res.writeHead(200, { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});

(async () => {
  await new Promise((r) => server.listen(8099, '127.0.0.1', r));
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, locale: 'ar' });
  const page = await ctx.newPage();
  const index = [];
  for (const [i, [cc, p, title]] of PAGES.entries()) {
    await page.goto(`http://127.0.0.1:8099/${cc}/${p}`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts && document.fonts.ready);
    // The static banner and the consent box are not part of the store's design.
    await page.addStyleTag({ content: '.ym-consent{display:none!important}' });
    const name = `${String(i + 1).padStart(2, '0')}-${cc}-${p.replace(/\/index\.html$|\.html$/g, '').replace(/\//g, '-') || 'home'}.png`;
    await page.screenshot({ path: path.join(OUT, name), fullPage: true });
    index.push({ file: name, title });
  }
  fs.writeFileSync(path.join(OUT, 'index.json'), JSON.stringify(index, null, 1));
  await browser.close();
  server.close();
  console.log(`${index.length} screenshots in ${OUT}`);
})();
