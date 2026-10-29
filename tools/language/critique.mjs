// Independent AI critique of a small batch of customer strings for one country (review of 29 Oct, §3).
// Runs in CI only (the API key is a repository secret): push a branch language-run/<name> carrying
// tools/language/requests/current.json:
//   { "country": "eg", "batch": "01-checkout", "keys": ["copy.place", "copy.phone_err", ...] }   (at most 80 keys)
// Output: tools/language/critique/<cc>-<batch>.json — an AI critique, NOT native-speaker review. Claude resolves each
// "revise" or "question" item and records the resolution in the same file; tools/language/ledger.mjs shows both.
// Spend goes through the shared ledger (tools/spend, docs/API-BUDGET.md). Exits 1 on any failure.
import fs from 'node:fs';
import path from 'node:path';
import { paidCall } from '../spend/anthropic.mjs';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const MODEL = 'claude-opus-5-5';
const SCOPE = { eg: 'eg', iq: 'iq', sa: 'gulf', ae: 'gulf', om: 'gulf' };
const DIALECT = { eg: 'Egyptian Arabic', iq: 'Iraqi (Baghdadi) Arabic', sa: 'neutral Arabic for Saudi customers', ae: 'neutral Arabic for Emirati customers', om: 'neutral Arabic for Omani customers' };

export function readRequest(rel = 'tools/language/requests/current.json') {
  if (!/^tools\/language\/requests\/[A-Za-z0-9._-]+\.json$/.test(rel)) throw new Error('request must be tools/language/requests/<name>.json');
  const r = JSON.parse(fs.readFileSync(path.join(ROOT, rel), 'utf8'));
  if (!SCOPE[r.country]) throw new Error('country must be eg, iq, sa, ae or om');
  if (!/^[a-z0-9-]{1,40}$/.test(r.batch || '')) throw new Error('batch: lowercase letters, digits and dashes');
  if (!Array.isArray(r.keys) || !r.keys.length || r.keys.length > 80 || r.keys.some((k) => !/^[A-Za-z0-9_.-]{1,80}$/.test(k))) throw new Error('keys: 1-80 ledger keys');
  return r;
}

function ledgerRows(cc) {
  const text = fs.readFileSync(path.join(ROOT, `docs/language/ledger-${cc}.csv`), 'utf8').replace(/^﻿/, '');
  const [head, ...rows] = text.trim().split('\n');
  const cols = head.split(',');
  return Object.fromEntries(rows.map((r) => {
    const cells = r.slice(1, -1).split('","').map((c) => c.replace(/""/g, '"'));
    const o = Object.fromEntries(cols.map((c, i) => [c, cells[i]]));
    return [o.key, o];
  }));
}

const TOOL = {
  name: 'write_critique',
  description: 'Return one item per string reviewed.',
  input_schema: {
    type: 'object', required: ['items'],
    properties: { items: { type: 'array', items: { type: 'object', required: ['key', 'verdict', 'note'], properties: {
      key: { type: 'string' },
      verdict: { type: 'string', enum: ['ok', 'revise', 'question'] },
      note: { type: 'string', description: 'the problem in one English sentence (naturalness, clarity, tone, factual risk or functional meaning); for ok, at most a few words' },
      suggestion: { type: 'string', description: 'for revise only: the replacement text in Arabic, keeping every placeholder (%s, %d, %1$s, [shortcode]) and every | separator' },
    } } } },
  },
};

export function prompt(r, rows, evidence, voice) {
  const items = r.keys.map((k) => {
    const row = rows[k];
    if (!row) throw new Error(`no ledger row ${k}`);
    return { key: k, layer: row.layer, surface: row.surface, text: row.proposed || row.existing, previous_text: row.proposed ? row.existing : undefined, why_changed: row.reason || undefined, facts_it_relies_on: row.factual_dependencies || undefined };
  });
  const system = [
    `You are an independent reviewer of customer-facing Arabic for Yawmiyati, a small home-goods web shop that sells cash on delivery (no online payment) to women in ${r.country.toUpperCase()}. Target language: ${DIALECT[r.country]}.`,
    'Judge each string as a careful native editor would, on: naturalness for this country, clarity on a phone screen, tone (warm, respectful, addressed to a woman, no forced slang, no repeated affectionate words), factual risk (claims the facts do not support, promises about timing, payment, refunds or delivery that the facts do not state), and functional meaning (does a button or error say exactly what happens).',
    'Layers: "functional" = buttons, labels, errors: clarity over dialect, short, familiar wording; "brand" = light, natural local phrasing; "policy" = precise, unambiguous about when payment, confirmation, shipping and refunds happen.',
    'Rules: say "ok" when a string is fine — do not paraphrase good text. Use "revise" only for a real problem, with a concrete suggestion. Use "question" when the problem depends on a fact you cannot know. Keep every placeholder and | separator exactly. Never introduce online payment, discounts, urgency, scarcity, guarantees, speed claims or facts not given. Products differ in grammatical gender and number: wording should not force one feminine or masculine agreement onto every product.',
    'Answer by calling write_critique once.',
    '', 'VOICE GUIDE (house rules):', voice,
    '', 'LOCAL REFERENCE NOTES (how real retailers in this country word their interfaces; conventions, not facts about us):', evidence,
  ].join('\n');
  return { system, user: `Strings (JSON):\n${JSON.stringify(items, null, 1)}` };
}

async function main() {
  const r = readRequest(process.argv[2]);
  const cc = r.country;
  const guide = ['sa', 'ae', 'om'].includes(cc) ? `${fs.readFileSync(path.join(ROOT, 'docs/voice/GULF.md'), 'utf8')}\n${fs.readFileSync(path.join(ROOT, `docs/voice/${cc.toUpperCase()}.md`), 'utf8')}` : fs.readFileSync(path.join(ROOT, `docs/voice/${cc.toUpperCase()}.md`), 'utf8');
  const evidence = fs.readFileSync(path.join(ROOT, `docs/language/evidence/${cc.toUpperCase()}.md`), 'utf8');
  const { system, user } = prompt(r, ledgerRows(cc), evidence, guide);
  const { json, entry } = await paidCall({ scope: SCOPE[cc], purpose: `language critique ${cc} ${r.batch}`, body: { model: MODEL, max_tokens: 8000, system, tools: [TOOL], tool_choice: { type: 'auto' }, messages: [{ role: 'user', content: user }] } });
  const use = (json.content || []).find((c) => c.type === 'tool_use');
  if (!use) throw new Error('no write_critique call in the answer');
  const items = use.input.items || [];
  const missing = r.keys.filter((k) => !items.some((i) => i.key === k));
  const out = { country: cc, batch: r.batch, model: MODEL, at: new Date().toISOString(), status: 'AI critique, not native-speaker review', ledger_entry: entry.id, usage: { input_tokens: entry.input_tokens, output_tokens: entry.output_tokens, usd_counted: entry.usd }, missing, items };
  fs.mkdirSync(path.join(ROOT, 'tools/language/critique'), { recursive: true });
  fs.writeFileSync(path.join(ROOT, `tools/language/critique/${cc}-${r.batch}.json`), JSON.stringify(out, null, 1) + '\n');
  const counts = items.reduce((a, i) => ({ ...a, [i.verdict]: (a[i.verdict] || 0) + 1 }), {});
  console.log(`${cc} ${r.batch}: ${JSON.stringify(counts)}; ${entry.input_tokens} in / ${entry.output_tokens} out tokens, ${entry.usd} USD counted`);
  if (missing.length) throw new Error(`critique skipped ${missing.length} keys: ${missing.slice(0, 5).join(', ')}`);
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((e) => { console.log(process.env.GITHUB_ACTIONS ? `::error title=language critique::${String(e.message).slice(0, 900)}` : e.message); process.exit(1); });
}
