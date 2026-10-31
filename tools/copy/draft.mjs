// Draft product copy with the Anthropic API from the fact sheet and the voice guide.
// Usage: node tools/copy/draft.mjs <eg|iq|sa|ae|om> [sku ...]  → tools/copy/drafts/<cc>/<sku>.json
// Runs in GitHub Actions (.github/workflows/copy-drafts.yml) where the key is a secret. Never paste the key anywhere.
// Every call goes through the shared API ledger (tools/spend, docs/API-BUDGET.md). Exits 1 when any product failed.
import fs from 'node:fs';
import path from 'node:path';
import { market } from './markets.mjs';
import { paidCall } from '../spend/anthropic.mjs';
import { BudgetError } from '../spend/ledger.mjs';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const [cc, ...only] = process.argv.slice(2).map((s) => s.trim());
const model = 'claude-sonnet-5-5'; // priced in tools/spend/rates.json; never taken from the environment
const config = market(cc);
if (only.length > 20 || only.some((s) => !/^[A-Z0-9]{6,24}$/.test(s))) { console.error('SKUs: up to 20, capital letters and digits only'); process.exit(2); }
const facts = JSON.parse(fs.readFileSync(path.join(root, `tools/copy/facts/${cc}.json`), 'utf8'));
const countryVoice = fs.readFileSync(path.join(root, `docs/voice/${cc.toUpperCase()}.md`), 'utf8');
const voice = (config.scope === 'gulf' ? fs.readFileSync(path.join(root, 'docs/voice/GULF.md'), 'utf8') + '\n' : '') + countryVoice;
const dialect = config.dialect;

const system = `You write product page copy for Yawmiyati (يومياتي), a cash-on-delivery home goods store, in ${dialect}.
Follow this voice guide exactly:
${voice}

Hard rules:
- Use ONLY the facts given. Do not add sizes, materials, capacities, wattage, warranty, origin, certifications,
  quantities or performance claims that are not in the facts. If a fact is unknown, do not mention it.
- No medical or guaranteed-result claims, no urgency, no scarcity, no reviews, no comparisons with other stores.
- Never mention the supplier. The store is يومياتي.
- Prices with Western digits and the currency ${facts.currency}, only if you mention a price at all.
Return ONLY a JSON object with these keys:
  "name": product name, max 40 characters
  "benefit": one sentence, max 120 characters, what it does for her at home
  "diary": the diary note, 1-2 practical sentences in her voice, max 160 characters
  "fit": how to check it suits her space or use, max 160 characters, or null if the facts give nothing to check
  "offer_labels": object mapping each offer SKU to a short label (max 24 characters)
  "used_facts": list of the exact facts you relied on`;

// At most two attempts per product; each attempt is its own reserved call. A budget refusal stops at once.
async function draft(product, attempt = 1) {
  try {
    return await draftOnce(product);
  } catch (e) {
    console.error(`${product.sku}: attempt ${attempt} failed: ${String(e.message).slice(0, 300)}`);
    if (e instanceof BudgetError || attempt >= 2) throw e;
    await new Promise((r) => setTimeout(r, 5000));
    return draft(product, attempt + 1);
  }
}

async function draftOnce(product) {
  const { json: body, entry } = await paidCall({ scope: config.scope, purpose: `copy draft ${cc.toUpperCase()} ${product.sku}`, body: { model, max_tokens: 1200, system, messages: [{ role: 'user', content: `Facts:\n${JSON.stringify(product, null, 2)}` }] } });
  const text = body.content.map((c) => c.text || '').join('');
  const json = JSON.parse(text.slice(text.indexOf('{'), text.lastIndexOf('}') + 1));
  return { ...json, _model: model, _usage: body.usage, _ledger: entry.id, _at: new Date().toISOString(), _status: 'draft: not native-reviewed, not fact-approved' };
}

if (only.some((sku) => !facts.products.some((p) => p.sku === sku))) throw new Error('Requested SKU missing from country fact sheet');
if (!facts.products.length) throw new Error('No product facts to draft');

const outDir = path.join(root, `tools/copy/drafts/${cc}`);
fs.mkdirSync(outDir, { recursive: true });
const errors = [];
for (const p of facts.products.filter((p) => !only.length || only.includes(p.sku))) {
  try {
    const d = await draft(p);
    fs.writeFileSync(path.join(outDir, `${p.sku}.json`), JSON.stringify(d, null, 2) + '\n');
    console.log(`${p.sku}: ${d.name} (${d._usage.input_tokens}+${d._usage.output_tokens} tokens)`);
  } catch (e) {
    errors.push(`${p.sku}: ${String(e.message).replace(/sk-ant-[A-Za-z0-9_-]+/g, '[key]').slice(0, 500)}`);
    if (e instanceof BudgetError) break;
  }
}
// Errors go to a file on the review branch (workflow logs are not always readable from here).
if (errors.length) fs.writeFileSync(path.join(outDir, 'ERRORS.txt'), errors.join('\n') + '\n');
console.log(`${errors.length} error(s)`);
process.exit(errors.length ? 1 : 0);
