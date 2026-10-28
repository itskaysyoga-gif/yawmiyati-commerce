// Check drafts against the facts and the voice rules. Exits non-zero when anything fails.
// Usage: node tools/copy/validate.mjs <eg|iq>  → prints a report and writes tools/copy/drafts/<cc>/REPORT.md
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const cc = process.argv[2];
const facts = JSON.parse(fs.readFileSync(path.join(root, `tools/copy/facts/${cc}.json`), 'utf8'));
const dir = path.join(root, `tools/copy/drafts/${cc}`);
const banned = ['مش مسموح', 'ما مسموح', 'ممنوع', 'تاجر', 'Taager', 'ضمان', 'مضمون', 'أصلي 100', 'علاج', 'يعالج', 'الأكثر مبيع', 'آخر قطعة', 'لفترة محدودة', 'الكمية محدودة', 'خصم'];
const otherDialect = cc === 'eg' ? ['هسّه', 'هسه', 'تكدرين', 'عدج', 'مالتج', 'شلون', 'ندز'] : ['دلوقتي', 'عايزة', 'هتيجي', 'بتاع', 'علشان', 'إزاي'];
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
  for (const field of ['name', 'benefit', 'diary', 'fit']) {
    const text = d[field] || '';
    if (text.length > limits[field]) problems.push(`${field} longer than ${limits[field]}`);
    for (const b of banned) if (text.includes(b)) problems.push(`${field}: banned "${b}"`);
    for (const w of otherDialect) if (text.includes(w)) problems.push(`${field}: other dialect "${w}"`);
    for (const n of digits(text)) if (!allowed.has(n)) problems.push(`${field}: number ${n} not in facts`);
  }
  for (const u of product.unknown_specs || []) {
    for (const field of ['benefit', 'diary', 'fit']) if ((d[field] || '').includes(u)) problems.push(`${field}: mentions unknown spec "${u}"`);
  }
  if (problems.length) failed++;
  rows.push(`| ${sku} | ${d.name || ''} | ${problems.length ? problems.join('; ') : 'ok'} |`);
}
const report = `# Copy draft check: ${cc.toUpperCase()}\n\nAutomated checks only (facts, banned words, dialect, length). Wording still needs native review (docs/LANGUAGE-REVIEW.md).\n\n| SKU | Name | Result |\n| --- | --- | --- |\n${rows.join('\n')}\n`;
fs.writeFileSync(path.join(dir, 'REPORT.md'), report);
console.log(report);
process.exit(failed ? 1 : 0);
