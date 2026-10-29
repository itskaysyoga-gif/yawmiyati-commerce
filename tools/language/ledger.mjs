// Copy ledger: every customer-facing string we own, per country, with the text at the base commit, the text now,
// why it changed, the facts it depends on, and its review status. Spec: docs/LANGUAGE-REVIEW.md.
//
//   node tools/language/ledger.mjs [--base <commit>]   → docs/language/ledger-<cc>.csv and docs/language/LEDGER.md
//
// Sources (the strings Yawmiyati owns): copy/<cc>.json (SA, AE, OM = copy/gulf.json + their overrides), policy pages
// content/<dir>/*.html, category names, and the seed product texts. Strings WooCommerce or WordPress print on our
// pages are listed separately by tools/language/crawl.mjs (rendered pages), so nothing customer-facing is missed.
// Review status comes from tools/language/notes.json (why a string changed, facts it relies on) and the critique
// files in tools/language/critique/ (API critique results). "Native-reviewed" is never set by a tool.
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const P = 'plugin/yawmiyati-commerce';
const argBase = process.argv.indexOf('--base');
const BASE = argBase > 0 ? process.argv[argBase + 1] : 'e10abdf';
const COUNTRIES = { eg: { voice: 'eg', content: 'eg', cats: 'eg' }, iq: { voice: 'iq', content: 'iq', cats: 'iq' }, sa: { voice: 'gulf', content: 'gulf', cats: 'gulf' }, ae: { voice: 'gulf', content: 'gulf', cats: 'gulf' }, om: { voice: 'gulf', content: 'gulf', cats: 'gulf' } };

const readNow = (rel) => (fs.existsSync(path.join(ROOT, rel)) ? fs.readFileSync(path.join(ROOT, rel), 'utf8') : null);
const readBase = (rel) => { try { return execFileSync('git', ['show', `${BASE}:${rel}`], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }); } catch { return null; } };
const json = (s) => (s ? JSON.parse(s) : {});

function copyDict(cc, read) {
  const c = COUNTRIES[cc];
  const base = c.voice !== cc ? json(read(`${P}/copy/${c.voice}.json`)) : {};
  const own = json(read(`${P}/copy/${cc}.json`));
  const out = {};
  for (const [k, v] of Object.entries({ ...base, ...own })) if (k !== '_status') out[`copy.${k}`] = { text: v, source: k in own ? `copy/${cc}.json` : `copy/${c.voice}.json` };
  return out;
}

function pages(cc, read) {
  const dir = COUNTRIES[cc].content;
  const out = {};
  for (const page of ['delivery', 'returns', 'privacy', 'contact', 'faq']) {
    const html = read(`${P}/content/${dir}/${page}.html`) || '';
    const blocks = [...html.matchAll(/<(h2|p|li|summary)[^>]*>([\s\S]*?)<\/\1>/g)].map((m) => m[2].replace(/<[^>]+>/g, '').trim()).filter(Boolean);
    blocks.forEach((t, i) => { out[`page.${page}.${String(i + 1).padStart(2, '0')}`] = { text: t, source: `content/${dir}/${page}.html` }; });
  }
  return out;
}

function cats(cc, read) {
  const all = json(read(`${P}/config/categories.json`));
  return Object.fromEntries(Object.entries(all[COUNTRIES[cc].cats] || {}).map(([k, v]) => [`category.${k}`, { text: v, source: 'config/categories.json' }]));
}

function products(cc, read) {
  const seed = json(read(`${P}/seed/${cc}.json`));
  const out = {};
  for (const p of seed.products || []) {
    const put = (field, text) => { if (text) out[`product.${p.sku}.${field}`] = { text, source: `seed/${cc}.json` }; };
    for (const f of ['name', 'label', 'note', 'benefit', 'diary', 'fit']) put(f, p[f]);
    (p.benefits || []).forEach((b, i) => { put(`benefit${i + 1}.title`, b[0]); put(`benefit${i + 1}.text`, b[1]); });
    (p.specs || []).forEach((s, i) => put(`spec${i + 1}.label`, s[0]));
    (p.images || []).forEach((im, i) => put(`image${i + 1}.alt`, im.alt));
  }
  return out;
}

const collect = (cc, read) => ({ ...copyDict(cc, read), ...pages(cc, read), ...cats(cc, read), ...products(cc, read) });

// Layer (docs/LANGUAGE-REVIEW.md): functional UI (clarity first, neutral), brand (light dialect), policy (precise).
const RULES = [
  [/^page\./, 'policy', 'policy page'],
  [/^category\./, 'functional', 'navigation'],
  [/^product\..*\.(name|label|note)$/, 'brand', 'product'],
  [/^product\..*spec\d+\.label$/, 'functional', 'product specs'],
  [/^product\..*image\d+\.alt$/, 'functional', 'accessibility'],
  [/^product\./, 'brand', 'product'],
  [/^copy\.mail_(unsub|confirm_button|review_button|order_total)$/, 'functional', 'email'],
  [/^copy\.mail_.*_body$/, 'policy', 'email'],
  [/^copy\.mail_/, 'brand', 'email'],
  [/^copy\.news_(body|agree)$/, 'policy', 'newsletter consent'],
  [/^copy\.news_/, 'functional', 'newsletter consent'],
  [/^copy\.consent_(text|short)$/, 'policy', 'cookie consent'],
  [/^copy\.consent_/, 'functional', 'cookie consent'],
  [/^copy\.(confirm_call|payment|returns|one_fee|privacy_note|cart_stays|market_closed|home_trust|thanks_steps|thanks_line|wrong_country)$/, 'policy', 'policy line'],
  [/^copy\.(pick_gov|fee_range|fee_flat|total_line|total_range|pay_total|plus_delivery|subtotal_label|delivery_after_gov)$/, 'policy', 'prices and totals'],
  [/^copy\.(name|phone|phone2|gov|area|building|landmark|email)_?(label|ph|err|help)?$|^copy\.(name|phone|gov|area|email)_(label|ph|err|help)$|^copy\.phone2(_help|_err)?$|^copy\.(building|landmark)_(label|help)$/, 'functional', 'checkout form'],
  [/^copy\.(expired|offer_na|max_qty|sold_out|sold_out_tag|refresh|dup|in_flight|try_again|rate_limited|bot_check|cart_saved|added|unverified)$/, 'functional', 'errors and states'],
  [/^copy\.(search_|no_results|no_products|results|prev|next|pages)/, 'functional', 'search and lists'],
  [/^copy\.(nav_|categories|all_products|see_all|home_word|breadcrumb|switch_country|cart_label|search_label)/, 'functional', 'navigation'],
  [/^copy\.(buy_short|add|place|choose|qty_|reco_add|review_submit|video_play|specs_title|delivery_title|zones_head|delivery_word|delivery_address|order_details|country_name|review_rating|review_text|review_name|review_stars)/, 'functional', 'buttons and labels'],
  [/^copy\.review_/, 'brand', 'reviews'],
  [/^copy\.(home_|thanks|why|fit_q|diary_title|reco_|ask|buy)/, 'brand', 'home, product and order page'],
];
const classify = (key) => { for (const [re, layer, surface] of RULES) if (re.test(key)) return { layer, surface }; return { layer: 'brand', surface: 'other' }; };

const notes = json(readNow('tools/language/notes.json'));
const critiques = fs.existsSync(path.join(ROOT, 'tools/language/critique')) ? fs.readdirSync(path.join(ROOT, 'tools/language/critique')).filter((f) => f.endsWith('.json')).sort().map((f) => json(readNow(`tools/language/critique/${f}`))) : [];
const csv = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`;
const summary = ['# Copy ledger summary', '', `Base commit ${BASE}. Generated by tools/language/ledger.mjs. Rows: one per owned string per country.`, '', '| Country | Strings | Changed this round | AI-critiqued | Critique open | Native-reviewed |', '| --- | --- | --- | --- | --- | --- |'];

fs.mkdirSync(path.join(ROOT, 'docs/language'), { recursive: true });
for (const cc of Object.keys(COUNTRIES)) {
  const before = collect(cc, readBase);
  const now = collect(cc, readNow);
  const keys = [...new Set([...Object.keys(before), ...Object.keys(now)])];
  const rows = [['key', 'country', 'layer', 'surface', 'source', 'existing', 'proposed', 'reason', 'factual_dependencies', 'ai_critique', 'status'].join(',')];
  let changed = 0;
  let critiqued = 0;
  let open = 0;
  for (const key of keys) {
    const b = before[key]?.text ?? '';
    const n = now[key]?.text ?? '';
    const k = key.replace(/^copy\./, '');
    const note = { ...(notes['*']?.[k] || {}), ...(notes[cc]?.[k] || {}), ...(notes['*']?.[key] || {}), ...(notes[cc]?.[key] || {}) };
    const cr = critiques.flatMap((c) => (c.country === cc || (c.countries || []).includes(cc) ? (c.items || []).filter((i) => i.key === key || i.key === k).map((i) => ({ ...i, model: c.model, at: c.at })) : [])).pop();
    const isChanged = b !== n;
    if (isChanged) changed++;
    if (cr) { critiqued++; if (cr.resolution === undefined && cr.verdict !== 'ok') open++; }
    const { layer, surface } = classify(key);
    const status = [isChanged ? (n ? 'AI-revised 29 Oct' : 'removed 29 Oct') : 'AI-drafted 28 Oct', cr ? `AI-critiqued (${cr.model}): ${cr.verdict}${cr.resolution ? `, resolved: ${cr.resolution}` : ''}` : 'not critiqued', 'native-reviewed: no'].join('; ');
    rows.push([key, cc.toUpperCase(), layer, surface, (now[key] || before[key]).source, b, isChanged ? n : '', note.reason || '', note.facts || '', cr ? `${cr.note || ''}${cr.suggestion ? ` → ${cr.suggestion}` : ''}` : '', status].map(csv).join(','));
  }
  fs.writeFileSync(path.join(ROOT, `docs/language/ledger-${cc}.csv`), '﻿' + rows.join('\n') + '\n');
  summary.push(`| ${cc.toUpperCase()} | ${keys.length} | ${changed} | ${critiqued} | ${open} | 0 |`);
}
summary.push('', 'Strings printed by WooCommerce or WordPress on our pages (not in our files) are inventoried from the rendered pages in docs/language/rendered-<cc>.csv (tools/language/crawl.mjs).');
fs.writeFileSync(path.join(ROOT, 'docs/language/LEDGER.md'), summary.join('\n') + '\n');
console.log(summary.join('\n'));
