// Check drafts against the facts and the voice rules. Exits non-zero when anything fails.
// Usage: node tools/copy/validate.mjs <eg|iq>  → prints a report and writes tools/copy/drafts/<cc>/REPORT.md
import fs from 'node:fs';
import path from 'node:path';
import { market, otherDialect as forbiddenDialect } from './markets.mjs';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const cc = process.argv[2];
market(cc);
const facts = JSON.parse(fs.readFileSync(path.join(root, `tools/copy/facts/${cc}.json`), 'utf8'));
const dir = path.join(root, `tools/copy/drafts/${cc}`);
const banned = ['مش مسموح', 'ما مسموح', 'ممنوع', 'تاجر', 'Taager', 'ضمان', 'مضمون', 'أصلي 100', 'علاج', 'يعالج', 'الأكثر مبيع', 'آخر قطعة', 'لفترة محدودة', 'الكمية محدودة', 'خصم'];
const otherDialect = forbiddenDialect(cc);
const limits = { name: 40, benefit: 120, diary: 160, fit: 160 };
const digits = (s) => (String(s).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d)).match(/\d[\d,.]*/g) || []).map((n) => n.replace(/,/g, ''));

const rows = [];
let failed = 0;
for (const f of fs.readdirSync(dir).filter((f) => f.endsWith('.json'))) {
  const sku = f.replace('.json', '');
  const d = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8'));
  const product = facts.products.find((p) => p.sku === sku) || {};
  const allowed = new Set(digits(JSON.stringify(product)));
  const problems = [];
  if (!product.sku) problems.push('SKU missing from country fact sheet');
  for (const field of ['name', 'benefit', 'diary']) {
    if (typeof d[field] !== 'string' || !d[field].trim()) problems.push(`${field}: required nonempty text`);
  }
  if (d.fit !== null && typeof d.fit !== 'string') problems.push('fit: text or null required');
  if (!Array.isArray(d.used_facts) || !d.used_facts.length) problems.push('used_facts: evidence list required');
  if (!d.offer_labels || typeof d.offer_labels !== 'object' || Array.isArray(d.offer_labels)) problems.push('offer_labels: object required');
  for (const [offerSku, label] of Object.entries(d.offer_labels || {})) {
    if (!(product.offers || []).some((o) => o.sku === offerSku)) problems.push('unknown offer SKU');
    if (typeof label !== 'string' || !label.trim() || label.length > 24) problems.push('invalid offer label');
    if (banned.some((word) => String(label).includes(word))) problems.push('banned offer label');
  }
  for (const field of ['name', 'benefit', 'diary', 'fit']) {
    const text = typeof d[field] === 'string' ? d[field] : '';
    if (text.length > limits[field]) problems.push(`${field} longer than ${limits[field]}`);
    for (const b of banned) if (text.includes(b)) problems.push(`${field}: banned "${b}"`);
    for (const w of otherDialect) if (text.includes(w)) problems.push(`${field}: other dialect "${w}"`);
    for (const n of digits(text)) if (!allowed.has(n)) problems.push(`${field}: number ${n} not in facts`);
  }
  for (const u of product.unknown_specs || []) {
    for (const field of ['benefit', 'diary', 'fit']) if (String(d[field] || '').includes(u)) problems.push(`${field}: mentions unknown spec "${u}"`);
  }
  if (problems.length) failed++;
  rows.push(`| ${sku} | ${d.name || ''} | ${problems.length ? problems.join('; ') : 'ok'} |`);
}
if (!rows.length) failed++;
const report = `# Copy draft check: ${cc.toUpperCase()}\n\nAutomated checks only (facts, banned words, dialect, length). Wording still needs native review (docs/LANGUAGE-REVIEW.md).\n\n| SKU | Name | Result |\n| --- | --- | --- |\n${rows.join('\n')}\n`;
fs.writeFileSync(path.join(dir, 'REPORT.md'), report);
console.log(report);
process.exit(failed ? 1 : 0);
