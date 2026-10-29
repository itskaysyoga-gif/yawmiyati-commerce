// Creative briefs from approved facts, drafted by the Anthropic API (CI only: the key is a repository secret).
// Usage: node studio/brief.mjs --request studio/requests/current.txt   (one facts file per line)
//    or: node studio/brief.mjs studio/fixtures/facts-iq-IQ030403SENK99.json [...]
// Output: studio/briefs/<cc>/<sku>.json — a DRAFT: checked by lib/rules.mjs, then dialect-reviewed by a person.
// Spend: every call goes through the shared API ledger (tools/spend, docs/API-BUDGET.md): it reserves the most the
// call could cost before sending, settles with the usage the API returns, and keeps the full reservation when the
// outcome is unknown. Video work is scheduled last (review of 29 Oct): this script is kept working and tested, and is
// not run until the storefront and language work are finished (docs/CONTENT-STUDIO.md).
import fs from 'node:fs';
import path from 'node:path';
import { check, ANGLES } from './lib/rules.mjs';
import { paidCall } from '../tools/spend/anthropic.mjs';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname));
const REPO = path.resolve(ROOT, '..');
const MODEL = 'claude-sonnet-5-5'; // priced in tools/spend/rates.json; never taken from the environment
const MAX_TOKENS = 4500;
const SCOPE = { EG: 'eg', IQ: 'iq', SA: 'gulf', AE: 'gulf', OM: 'gulf' };

/** Facts files allowed as input: inside studio/fixtures or studio/facts, plain names only. */
export function factsPaths(argv) {
  let list = argv;
  if (argv[0] === '--request') {
    const req = argv[1] || '';
    if (!/^studio\/requests\/[A-Za-z0-9._-]+\.txt$/.test(req)) throw new Error(`request file must be studio/requests/<name>.txt, got "${req}"`);
    list = fs.readFileSync(path.join(REPO, req), 'utf8').split('\n').map((l) => l.trim()).filter((l) => l && !l.startsWith('#'));
  }
  if (!list.length || list.length > 10) throw new Error('give 1-10 facts files');
  return list.map((f) => {
    if (!/^studio\/(fixtures|facts)\/[A-Za-z0-9._-]+\.json$/.test(f)) throw new Error(`facts file must be studio/fixtures/<name>.json or studio/facts/<name>.json, got "${f.slice(0, 80)}"`);
    return path.join(REPO, f);
  });
}

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
    `Angles: "problem" = the everyday problem, the product in use, the result; "fit" = how to check it suits her space before ordering: when the size specs are known, tell her to compare with the measurements on the product page (never state one); when they are unknown (listed in unknown_specs), do NOT mention measurements or sizes at all and explain only where each piece goes, from the diary and fit facts; "offer" = which offer to choose (labels only, no prices).`,
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
  const scope = SCOPE[f.country];
  if (!scope) throw new Error(`unknown country ${f.country}`);
  const body = { model: MODEL, max_tokens: MAX_TOKENS, system: system(f), tools: [TOOL], tool_choice: { type: 'auto' }, messages: [{ role: 'user', content: `Facts (JSON):\n${JSON.stringify(slim(f), null, 1)}${extra ? `\n\nYour previous answer broke these rules; fix every one:\n- ${extra.join('\n- ')}` : ''}` }] };
  const { json, entry } = await paidCall({ scope, purpose: `brief ${f.country} ${f.sku}`, body });
  if (process.env.GITHUB_ACTIONS) console.log(`::notice title=API spend::${f.country} ${f.sku}: ${entry.input_tokens} in / ${entry.output_tokens} out tokens, ${entry.usd} USD counted (${entry.usd_list} at list price)`);
  const use = (json.content || []).find((c) => c.type === 'tool_use');
  if (use) return use.input;
  const text = (json.content || []).map((c) => c.text || '').join('');
  try { return JSON.parse(text.slice(text.indexOf('{'), text.lastIndexOf('}') + 1)); } catch { throw new Error('the answer had no write_brief call and no readable JSON'); }
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

async function main() {
  const files = factsPaths(process.argv.slice(2));
  let failed = 0;
  for (const file of files) {
    const f = JSON.parse(fs.readFileSync(file, 'utf8'));
    let brief;
    let errors;
    try {
      brief = await call(f);
      errors = check(brief, f);
      if (errors.length) { brief = await call(f, errors); errors = check(brief, f); }
    } catch (e) { note(`${f.country} ${f.sku}: ${e.message}`); failed++; continue; }
    if (errors.length) { note(`${f.country} ${f.sku}: ${errors.length} rule errors: ${errors.slice(0, 5).join(' | ')}`); failed++; }
    const out = path.join(ROOT, 'briefs', f.country.toLowerCase(), `${f.sku}.json`);
    fs.mkdirSync(path.dirname(out), { recursive: true });
    // A rejected draft is kept for diagnosis, marked rejected; the run still fails.
    fs.writeFileSync(out, JSON.stringify({ status: errors.length ? 'rejected_by_rules' : 'draft: rules passed, dialect review pending', errors, model: MODEL, facts_offer_version: f.offer_version, generated_at: new Date().toISOString(), angles_required: ANGLES, ...brief }, null, 1));
    console.log(`${f.country} ${f.sku}: ${errors.length ? errors.length + ' rule errors' : 'ok'}`);
  }
  return failed;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main().then((failed) => process.exit(failed ? 1 : 0), (e) => { note(e.message); process.exit(1); });
}
