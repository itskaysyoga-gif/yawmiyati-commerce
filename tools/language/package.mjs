// Native-review package per country: a compact, prioritised list for one Egyptian and one Iraqi reviewer, built from
// the copy ledger (docs/language/ledger-<cc>.csv) and the AI critique files. Writes docs/language/REVIEW-PACKAGE-<CC>.md.
//   node tools/language/package.mjs
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
function rows(cc) {
  const text = fs.readFileSync(path.join(ROOT, `docs/language/ledger-${cc}.csv`), 'utf8').replace(/^﻿/, '');
  const [head, ...rs] = text.trim().split('\n');
  const cols = head.split(',');
  return rs.map((r) => Object.fromEntries(cols.map((c, i) => [c, r.slice(1, -1).split('","').map((x) => x.replace(/""/g, '"'))[i]]))).map((r) => ({ ...r, text: r.proposed || r.existing })).filter((r) => r.text);
}
const cell = (s) => String(s || '').replace(/\|/g, '¦').replace(/\n/g, ' ');
const NAME = { eg: ['Egypt', 'Egyptian'], iq: ['Iraq', 'Iraqi'] };

for (const cc of ['eg', 'iq']) {
  const all = rows(cc);
  const table = (list) => ['| # | Key | Where | Text | AI critique | OK? / your wording |', '| --- | --- | --- | --- | --- | --- |', ...list.map((r, i) => `| ${i + 1} | \`${r.key.replace(/^copy\./, '')}\` | ${r.surface} | ${cell(r.text)} | ${cell((r.status.match(/AI-critiqued \([^)]*\): (\w+)/) || [])[1] || '-')} | |`)].join('\n');
  const p1 = all.filter((r) => r.key.startsWith('copy.') && (r.layer === 'policy' && !/email|newsletter|cookie/.test(r.surface) || ['checkout form', 'errors and states'].includes(r.surface)));
  const p2 = all.filter((r) => (r.key.startsWith('copy.') && r.layer === 'brand' && !/email/.test(r.surface)) || r.key.startsWith('product.') && /\.(name|benefit|diary|fit|label|note|saving)$/.test(r.key));
  const p2f = all.filter((r) => r.key.startsWith('copy.') && r.layer === 'functional' && ['navigation', 'buttons and labels', 'search and lists'].includes(r.surface) || r.key.startsWith('category.'));
  const p3 = all.filter((r) => r.key.startsWith('copy.') && /email|newsletter|cookie/.test(r.surface));
  const out = [
    `# ${NAME[cc][0]}: native review package`,
    '',
    `For one ${NAME[cc][1]} reviewer, ideally a woman in the 25-44 audience. Every line was written or revised by AI (Claude) and`,
    'checked by a second AI pass; **none has been read by a native speaker yet**. Please read each line **on the phone screens**',
    '(review PDF, same commit) and write "OK" or your wording. You review wording only: prices, sizes and policy facts are',
    `checked separately. House rules: docs/voice/${cc.toUpperCase()}.md. Generated from docs/language/ledger-${cc}.csv.`,
    '',
    '## Priority 1: checkout, errors, money and policy lines',
    table(p1),
    '',
    '## Priority 2: home and product wording',
    table(p2),
    '',
    '## Priority 2b: buttons, navigation, search (one glance)',
    p2f.map((r) => `\`${r.key.replace(/^copy\./, '')}\`: ${cell(r.text)}`).join(' · '),
    '',
    '## Priority 3: emails and consent',
    table(p3),
    '',
    '## Priority 4: policy pages',
    'Read the five pages on the phone: delivery, returns, privacy, contact, FAQ (the review PDF and, after staging, the',
    'staging site). Mark anything unclear, too formal or not how you would say it.',
    '',
    '## After the review',
    `Claude applies the changes, reruns the tests, regenerates the PDF, and the reviewer checks the changed lines once.`,
    `Then Rashid sets \`ym_language_reviewed_${cc.toUpperCase()}\` (launch checklist P0-15).`,
  ];
  fs.writeFileSync(path.join(ROOT, `docs/language/REVIEW-PACKAGE-${cc.toUpperCase()}.md`), out.join('\n') + '\n');
  console.log(cc, p1.length, p2.length, p2f.length, p3.length);
}
