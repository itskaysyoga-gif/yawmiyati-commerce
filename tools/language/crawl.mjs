// Rendered-page string inventory: every piece of customer-facing text the local stores actually print (visible text,
// placeholders, accessible names, titles, meta descriptions, structured data, validation messages), each marked as
// ours (found in copy, policy pages, categories or product data) or not ours (WooCommerce, WordPress or theme text).
//   dev/serve.sh && node tools/language/crawl.mjs [eg iq ...]   → docs/language/rendered-<cc>.csv
// Read-only except one add-to-cart per store to reach the cart and checkout states.
import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const BASE = { eg: 8081, iq: 8082, sa: 8083, ae: 8084, om: 8085 };
const want = process.argv.slice(2).filter((a) => BASE[a]);
const list = want.length ? want : Object.keys(BASE);

function owned(cc) {
  const file = path.join(ROOT, `docs/language/ledger-${cc}.csv`);
  const rows = fs.readFileSync(file, 'utf8').replace(/^﻿/, '').split('\n').slice(1).filter(Boolean);
  const out = [];
  for (const r of rows) {
    const cells = r.slice(1, -1).split('","').map((c) => c.replace(/""/g, '"'));
    const text = cells[6] || cells[5];
    if (text) out.push([cells[0], text]);
  }
  return out;
}

const norm = (s) => s.replace(/\s+/g, ' ').replace(/[‎‏]/g, '').trim();
function match(str, own) {
  const s = norm(str);
  for (const [k, t] of own) {
    for (const part of String(t).split('|')) {
      const p = norm(part.replace(/\[[^\]]+\]/g, ''));
      if (!p) continue;
      if (p === s) return k;
      if (/%(\d\$)?[sd]/.test(p)) {
        const re = new RegExp('^' + p.split(/%(?:\d\$)?[sd]/).map((x) => x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('.+') + '$');
        if (re.test(s)) return k;
      }
      if (p.length > 12 && (s.includes(p) || p.includes(s) && s.length > 12)) return k;
    }
  }
  return '';
}

async function grab(page) {
  return page.evaluate(() => {
    const out = [];
    const push = (kind, v) => { v = (v || '').replace(/\s+/g, ' ').trim(); if (v && /[؀-ۿ]/.test(v)) out.push([kind, v]); };
    push('title', document.title);
    push('meta', document.querySelector('meta[name="description"]')?.content);
    document.querySelectorAll('script[type="application/ld+json"]').forEach((s) => { try { JSON.stringify(JSON.parse(s.textContent), (k, v) => { if (typeof v === 'string') push('structured data', v); return v; }); } catch { /* ignore */ } });
    document.querySelectorAll('[placeholder]').forEach((e) => push('placeholder', e.getAttribute('placeholder')));
    document.querySelectorAll('[aria-label]').forEach((e) => push('aria-label', e.getAttribute('aria-label')));
    document.querySelectorAll('img[alt]').forEach((e) => push('alt', e.getAttribute('alt')));
    document.querySelectorAll('[title]').forEach((e) => { if (e.tagName !== 'TITLE') push('title attr', e.getAttribute('title')); });
    document.querySelectorAll('option').forEach((e) => push('option', e.textContent));
    for (const line of (document.body?.innerText || '').split('\n')) push('text', line);
    return out;
  });
}

for (const cc of list) {
  const base = `http://localhost:${BASE[cc]}`;
  const own = owned(cc);
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ locale: 'ar', viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();
  const seen = new Map();
  const visit = async (label, url, act) => {
    try {
      if (url) await page.goto(base + url, { waitUntil: 'load' });
      if (act) await act();
      for (const [kind, v] of await grab(page)) {
        const key = `${kind}|${v}`;
        if (!seen.has(key)) seen.set(key, { pages: new Set(), kind, v });
        seen.get(key).pages.add(label);
      }
    } catch (e) { console.error(`${cc} ${label}: ${e.message.split('\n')[0]}`); }
  };
  await visit('home', '/');
  await visit('catalog', '/shop/');
  const cat = await page.locator('a[href*="/product-category/"]').first().getAttribute('href').catch(() => null);
  if (cat) await visit('category', new URL(cat).pathname);
  await visit('search with results', `/?s=${encodeURIComponent('طقم')}&post_type=product`);
  await visit('search without results', `/?s=${encodeURIComponent('zzqx')}&post_type=product`);
  await visit('not found', '/no-such-page-ym/');
  for (const p of ['delivery', 'returns', 'privacy', 'contact', 'faq']) await visit(`page ${p}`, `/${p}/`);
  await visit('empty cart', '/cart/');
  const prod = await page.goto(base + '/shop/').then(() => page.locator('a[href*="/product/"]').first().getAttribute('href')).catch(() => null);
  if (prod) {
    await visit('product', new URL(prod).pathname);
    await visit('cart with an item', null, async () => {
      const add = page.locator('button[value="add"], button[name="add-to-cart"], .single_add_to_cart_button').first();
      if (await add.count()) { await add.click(); await page.waitForLoadState('load'); }
      await page.goto(base + '/cart/');
    });
    await visit('checkout', '/checkout/');
    await visit('checkout errors', null, async () => {
      await page.locator('#place_order').click({ timeout: 5000 }).catch(() => {});
      await page.waitForTimeout(2500);
    });
  }
  await browser.close();
  const rows = ['"string","kind","pages","owner"'];
  let mine = 0;
  let theirs = 0;
  for (const { v, kind, pages } of [...seen.values()].sort((a, b) => a.v.localeCompare(b.v))) {
    const k = match(v, own);
    k ? mine++ : theirs++;
    rows.push([v, kind, [...pages].join(' / '), k || 'not ours: WooCommerce, WordPress or theme'].map((c) => `"${String(c).replace(/"/g, '""')}"`).join(','));
  }
  fs.writeFileSync(path.join(ROOT, `docs/language/rendered-${cc}.csv`), '﻿' + rows.join('\n') + '\n');
  console.log(`${cc}: ${seen.size} rendered strings, ${mine} traced to our files, ${theirs} not ours`);
}
