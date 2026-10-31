// Build the fact sheet the copy drafts may use: only facts we have, never placeholders.
// Usage: node tools/copy/facts.mjs <eg|iq|sa|ae|om>  → tools/copy/facts/<cc>.json
import fs from 'node:fs';
import path from 'node:path';
import { market } from './markets.mjs';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const cc = (process.argv[2] || '').toLowerCase();
const config = market(cc);
const seed = JSON.parse(fs.readFileSync(path.join(root, `plugin/yawmiyati-commerce/seed/${cc}.json`), 'utf8'));
const readCopy = (name) => JSON.parse(fs.readFileSync(path.join(root, `plugin/yawmiyati-commerce/copy/${name}.json`), 'utf8'));
const copy = { ...(config.scope === 'gulf' ? readCopy('gulf') : {}), ...readCopy(cc) };
const currency = config.currency;

const groups = {};
for (const p of seed.products) {
  const key = p.group || p.slug;
  groups[key] ??= { offers: [] };
  const g = groups[key];
  if (p.primary || !p.group) Object.assign(g, { sku: p.sku, name: p.name, slug: p.slug, known_specs: Object.fromEntries((p.specs || []).filter((s) => s[1] !== null).map((s) => [s[0], s[1]])), unknown_specs: (p.specs || []).filter((s) => s[1] === null).map((s) => s[0]) });
  g.offers.push({ sku: p.sku, price: p.price, label: p.label || null, note: p.note || null });
}
const facts = {
  country: cc.toUpperCase(),
  currency,
  status: 'Built from seed data: catalog export, NOT supplier-verified. Drafts from these facts are drafts only.',
  policy_lines: { payment: copy.payment, confirm_call: copy.confirm_call, one_fee: copy.one_fee, returns: copy.returns || null },
  products: Object.values(groups),
};
fs.mkdirSync(path.join(root, 'tools/copy/facts'), { recursive: true });
fs.writeFileSync(path.join(root, `tools/copy/facts/${cc}.json`), JSON.stringify(facts, null, 2) + '\n');
console.log(`facts/${cc}.json: ${facts.products.length} products`);
