// Draft product copy with the Anthropic API from the fact sheet and the voice guide.
// Usage: ANTHROPIC_API_KEY=... node tools/copy/draft.mjs <eg|iq> [sku ...]  → tools/copy/drafts/<cc>/<sku>.json
// Runs in GitHub Actions (.github/workflows/copy-drafts.yml) where the key is a secret. Never paste the key anywhere.
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const [cc, ...only] = process.argv.slice(2).map((s) => s.trim());
const key = process.env.ANTHROPIC_API_KEY;
const model = process.env.YM_COPY_MODEL || 'claude-sonnet-5-5';
if (!key) { console.error('ANTHROPIC_API_KEY missing'); process.exit(2); }
const facts = JSON.parse(fs.readFileSync(path.join(root, `tools/copy/facts/${cc}.json`), 'utf8'));
const voice = fs.readFileSync(path.join(root, `docs/voice/${cc.toUpperCase()}.md`), 'utf8');
const dialect = cc === 'eg' ? 'Egyptian Arabic' : 'Iraqi (Baghdadi) Arabic';

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

async function draft(product) {
  const res = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: { 'x-api-key': key, 'anthropic-version': '2023-06-01', 'content-type': 'application/json' },
    body: JSON.stringify({ model, max_tokens: 1200, system, messages: [{ role: 'user', content: `Facts:\n${JSON.stringify(product, null, 2)}` }] }),
  });
  if (!res.ok) throw new Error(`${res.status} ${await res.text()}`);
  const body = await res.json();
  const text = body.content.map((c) => c.text || '').join('');
  const json = JSON.parse(text.slice(text.indexOf('{'), text.lastIndexOf('}') + 1));
  return { ...json, _model: model, _usage: body.usage, _at: new Date().toISOString(), _status: 'draft: not native-reviewed, not fact-approved' };
}

const outDir = path.join(root, `tools/copy/drafts/${cc}`);
fs.mkdirSync(outDir, { recursive: true });
for (const p of facts.products.filter((p) => !only.length || only.includes(p.sku))) {
  const d = await draft(p);
  fs.writeFileSync(path.join(outDir, `${p.sku}.json`), JSON.stringify(d, null, 2) + '\n');
  console.log(`${p.sku}: ${d.name} (${d._usage.input_tokens}+${d._usage.output_tokens} tokens)`);
}
