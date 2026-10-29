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
    'Answer by calling the write_brief tool once.',
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
    body: JSON.stringify({ model: MODEL, max_tokens: 3000, system: system(f), tools: [TOOL], tool_choice: { type: 'tool', name: 'write_brief' }, messages: [{ role: 'user', content: `Facts (JSON):\n${JSON.stringify(slim(f), null, 1)}${extra ? `\n\nYour previous answer broke these rules; fix every one:\n- ${extra.join('\n- ')}` : ''}` }] }),
  });
  const body = await res.json();
  if (!res.ok) throw new Error(`API ${res.status}: ${JSON.stringify(body).slice(0, 300)}`);
  const usd = (body.usage.input_tokens * RATE.in + body.usage.output_tokens * RATE.out) / 1e6;
  runSpent += usd;
  ledger.calls.push({ at: new Date().toISOString(), model: MODEL, purpose: `brief ${f.country} ${f.sku}`, input_tokens: body.usage.input_tokens, output_tokens: body.usage.output_tokens, usd: Number(usd.toFixed(4)) });
  fs.writeFileSync(ledgerFile, JSON.stringify(ledger, null, 1));
  if (process.env.GITHUB_ACTIONS) console.log(`::notice title=studio spend::${f.country} ${f.sku}: ${body.usage.input_tokens} in / ${body.usage.output_tokens} out tokens, counted as ${usd.toFixed(4)} USD; ledger total ${spent().toFixed(4)} USD`);
  const use = (body.content || []).find((c) => c.type === 'tool_use');
  if (!use) throw new Error('no tool call in the answer');
  return use.input;
}

const line = { type: 'string' };
const TOOL = {
  name: 'write_brief',
  description: 'Return the creative brief: three angles (problem, fit, offer), each with two hooks, 2-3 beats with the fact key they restate, a call to action, a caption and a thumbnail line; and a 3-4 slide carousel.',
  input_schema: {
    type: 'object', required: ['angles', 'carousel'],
    properties: {
      angles: { type: 'array', minItems: 3, maxItems: 3, items: { type: 'object', required: ['id', 'title', 'hooks', 'beats', 'cta', 'caption', 'thumbnail'], properties: {
        id: { type: 'string', enum: ANGLES }, title: line, hooks: { type: 'array', minItems: 2, maxItems: 2, items: line },
        beats: { type: 'array', minItems: 2, maxItems: 3, items: { type: 'object', required: ['line', 'source'], properties: { line, source: line } } },
        cta: line, caption: line, thumbnail: line } } },
      carousel: { type: 'array', minItems: 3, maxItems: 4, items: line },
    },
  },
};

const note = (m) => console.log(process.env.GITHUB_ACTIONS ? `::error title=studio brief::${String(m).replace(/\n/g, ' ').slice(0, 900)}` : m);
if (!process.env.ANTHROPIC_API_KEY) { note('ANTHROPIC_API_KEY is empty in this run'); process.exit(1); }
let failed = 0;
for (const file of process.argv.slice(2)) {
  const f = JSON.parse(fs.readFileSync(file, 'utf8'));
  let brief, errors;
  try {
    brief = await call(f);
    errors = check(brief, f);
    if (errors.length) { brief = await call(f, errors); errors = check(brief, f); }
  } catch (e) { note(`${f.country} ${f.sku}: ${e.message}`); failed++; continue; }
  if (errors.length) note(`${f.country} ${f.sku}: ${errors.length} rule errors: ${errors.slice(0, 5).join(' | ')}`);
  const out = path.join(ROOT, 'briefs', f.country.toLowerCase(), `${f.sku}.json`);
  fs.mkdirSync(path.dirname(out), { recursive: true });
  fs.writeFileSync(out, JSON.stringify({ status: errors.length ? 'rejected_by_rules' : 'draft: rules passed, dialect review pending', errors, model: MODEL, facts_offer_version: f.offer_version, generated_at: new Date().toISOString(), angles_required: ANGLES, ...brief }, null, 1));
  console.log(`${f.country} ${f.sku}: ${errors.length ? errors.length + ' rule errors' : 'ok'}; spent so far ${spent().toFixed(3)} USD`);
}
if (failed) process.exit(1);
