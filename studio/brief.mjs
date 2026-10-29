// Creative briefs from approved facts, drafted by the Anthropic API (CI only: the key is a repository secret).
// Usage: node studio/brief.mjs studio/fixtures/facts-iq-IQ030403SENK99.json [...]
// Output: studio/briefs/<cc>/<sku>.json — a DRAFT: checked by lib/rules.mjs, then dialect-reviewed by a person.
// Spend: every call's tokens are priced with deliberately high rates and added to studio/spend.json; the run stops
// before a call that could take the total past YM_STUDIO_BUDGET_USD (default 40) or the run past 3 USD.
import fs from 'node:fs';
import path from 'node:path';
import { check, ANGLES } from './lib/rules.mjs';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname));
const MODEL = process.env.YM_STUDIO_MODEL || 'claude-sonnet-5-5';
const RATE = { in: Number(process.env.YM_RATE_IN || 5), out: Number(process.env.YM_RATE_OUT || 25) }; // USD per 1M tokens, set above list price on purpose
const BUDGET = Number(process.env.YM_STUDIO_BUDGET_USD || 40);
const RUN_CAP = 3;
const ledgerFile = path.join(ROOT, 'spend.json');
const ledger = fs.existsSync(ledgerFile) ? JSON.parse(fs.readFileSync(ledgerFile, 'utf8')) : { cap_usd: BUDGET, rates_usd_per_mtok: RATE, calls: [] };
const spent = () => ledger.calls.reduce((s, c) => s + c.usd, 0);
let runSpent = 0;

const voice = (cc) => fs.readFileSync(path.join(ROOT, '..', 'docs/voice', `${cc.toUpperCase()}.md`), 'utf8');

function system(f) {
  return [
    `You write short vertical-video scripts and captions in Arabic for Yawmiyati, a home-goods shop selling cash on delivery in ${f.country_name}.`,
    `Write in the ${f.voice === 'eg' ? 'Egyptian' : f.voice === 'iq' ? 'Iraqi' : 'Gulf'} voice described below, addressing a woman (feminine forms). Conversational benefits, precise policies, no exaggerated dialect spelling, no slang about gender.`,
    'HARD RULES:',
    '- Use ONLY the facts given. Every beat must restate one fact and name its key in "source" (benefit, fit, diary, name, or benefits.N).',
    '- NEVER write a digit or a number word for prices, fees, sizes or quantities: the video template adds price and delivery from the facts.',
    '- Never mention a spec whose value is null (unknown). Never invent performance, materials, sizes, durability, warranty, origin, brands, reviews, customers, discounts, free delivery, scarcity or deadlines.',
    '- No superlatives ("best", "number one"), no medical or cleaning-germ claims, no "trend".',
    `- The call to action names the country (${f.country_name}) and points to the website order; the website is the only way to order.`,
    'Return ONLY JSON: {"angles":[{"id":"problem"|"fit"|"offer","title":"...","hooks":["...","..."],"beats":[{"line":"...","source":"..."}],"cta":"...","caption":"...","thumbnail":"..."}],"carousel":["slide 1","slide 2","slide 3","slide 4"]}',
    `Angles: "problem" = the everyday problem, the product in use, the result; "fit" = how to check it fits her space before ordering (from the fit fact; tell her to compare with the measurements on the product page, never state a measurement); "offer" = which offer to choose (labels only, no prices).`,
    'Lengths: hook <= 46 characters, beat <= 52, cta <= 34, thumbnail <= 30, carousel slide <= 60, caption <= 600 (caption may say the price and delivery are on the page; no numbers).',
    '',
    'VOICE GUIDE:',
    voice(f.voice || f.country.toLowerCase()),
  ].join('\n');
}

function slim(f) {
  return { country: f.country_name, name: f.name, benefit: f.benefit, benefits: f.benefits, fit: f.fit, diary: f.diary, unknown_specs: f.specs.filter((s) => s.value === null).map((s) => s.label), known_specs: f.specs.filter((s) => s.value !== null), offers: f.offers.map((o) => ({ label: o.label, note_without_numbers: true })), payment: 'cash on delivery; delivery fee shown by the template' };
}

async function call(f, extra) {
  const est = (8000 * RATE.in + 3000 * RATE.out) / 1e6; // worst case for one call
  if (spent() + est > BUDGET || runSpent + est > RUN_CAP) throw new Error(`budget: spent ${spent().toFixed(2)} of ${BUDGET} USD (run ${runSpent.toFixed(2)} of ${RUN_CAP})`);
  const res = await fetch('https://api.anthropic.com/v1/messages', {
    method: 'POST',
    headers: { 'x-api-key': process.env.ANTHROPIC_API_KEY, 'anthropic-version': '2023-06-01', 'content-type': 'application/json' },
    body: JSON.stringify({ model: MODEL, max_tokens: 3000, system: system(f), messages: [{ role: 'user', content: `Facts (JSON):\n${JSON.stringify(slim(f), null, 1)}${extra ? `\n\nYour previous answer broke these rules; fix every one:\n- ${extra.join('\n- ')}` : ''}` }] }),
  });
  const body = await res.json();
  if (!res.ok) throw new Error(`API ${res.status}: ${JSON.stringify(body).slice(0, 300)}`);
  const usd = (body.usage.input_tokens * RATE.in + body.usage.output_tokens * RATE.out) / 1e6;
  runSpent += usd;
  ledger.calls.push({ at: new Date().toISOString(), model: MODEL, purpose: `brief ${f.country} ${f.sku}`, input_tokens: body.usage.input_tokens, output_tokens: body.usage.output_tokens, usd: Number(usd.toFixed(4)) });
  fs.writeFileSync(ledgerFile, JSON.stringify(ledger, null, 1));
  const text = body.content.map((c) => c.text || '').join('');
  return JSON.parse(text.slice(text.indexOf('{'), text.lastIndexOf('}') + 1));
}

for (const file of process.argv.slice(2)) {
  const f = JSON.parse(fs.readFileSync(file, 'utf8'));
  let brief = await call(f);
  let errors = check(brief, f);
  if (errors.length) { brief = await call(f, errors); errors = check(brief, f); }
  const out = path.join(ROOT, 'briefs', f.country.toLowerCase(), `${f.sku}.json`);
  fs.mkdirSync(path.dirname(out), { recursive: true });
  fs.writeFileSync(out, JSON.stringify({ status: errors.length ? 'rejected_by_rules' : 'draft: rules passed, dialect review pending', errors, model: MODEL, facts_offer_version: f.offer_version, generated_at: new Date().toISOString(), angles_required: ANGLES, ...brief }, null, 1));
  console.log(`${f.country} ${f.sku}: ${errors.length ? errors.length + ' rule errors' : 'ok'}; spent so far ${spent().toFixed(3)} USD`);
}
