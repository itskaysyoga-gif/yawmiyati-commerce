// Anthropic API ledger commands (docs/API-BUDGET.md).
//   node tools/spend/cli.mjs status            totals per envelope, open reservations
//   node tools/spend/cli.mjs init              start the ledger branch with the calls made before it existed
//   node tools/spend/cli.mjs reconcile <id> <input_tokens> <output_tokens> "<note>"   after checking the console
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { Ledger, openStore, totals, usd } from './ledger.mjs';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const lg = new Ledger(openStore());
const [cmd, ...args] = process.argv.slice(2);

function priorEntries() {
  const p = JSON.parse(fs.readFileSync(path.join(HERE, 'prior.json'), 'utf8'));
  const known = p.known.map((c) => ({ id: crypto.createHash('sha256').update(c.at + c.purpose).digest('hex').slice(0, 16), at: c.at, scope: c.scope, purpose: c.purpose, model: c.model, run: 'before-ledger', state: 'settled', input_tokens: c.input_tokens, output_tokens: c.output_tokens, reserved_usd: 0, usd: Number(usd(c.model, c.input_tokens, c.output_tokens).toFixed(6)), usd_list: Number(usd(c.model, c.input_tokens, c.output_tokens, 'list').toFixed(6)), note: c.source }));
  const u = p.unknown;
  return [...known, { id: 'prior-unrecorded', at: '2026-10-29T08:00:00.000Z', scope: u.scope, purpose: 'calls in failed runs before the ledger', model: u.model, run: 'before-ledger', state: 'unknown', reserved_usd: u.reserved_usd, usd: null, usd_list: null, note: u.note }];
}

if (cmd === 'init') {
  console.log((await lg.init(priorEntries())) ? 'ledger started' : 'ledger already exists (unchanged)');
} else if (cmd === 'reconcile') {
  const [id, i, o, ...note] = args;
  const e = await lg.reconcile(id, { input_tokens: Number(i), output_tokens: Number(o), note: note.join(' ') });
  console.log(`${e.id}: settled at ${e.usd} USD counted`);
} else {
  const d = await lg.state();
  if (!d) { console.log('no ledger yet'); process.exit(0); }
  const t = totals(d);
  const lines = [`# Anthropic API budget`, '', `Cap ${d.cap_usd} USD. Held ${t.total.toFixed(4)} USD (settled calls at ${d.count_multiplier}x list price, plus full reservations for unknown or failed calls). Remaining ${t.remaining.toFixed(4)} USD.`, `Settled calls at list price: ${t.list_known.toFixed(4)} USD. The Anthropic console is the source of truth for what was charged.`, '', '| Envelope | Held | Limit |', '| --- | --- | --- |'];
  for (const [s, lim] of Object.entries(d.envelopes)) lines.push(`| ${s} | ${t.byScope[s].toFixed(4)} | ${lim} |`);
  const open = d.entries.filter((e) => e.state !== 'settled');
  lines.push('', `Calls: ${d.entries.length} (${d.entries.filter((e) => e.state === 'settled').length} settled, ${open.length} held at their reservation)`);
  for (const e of open) lines.push(`- ${e.id} ${e.state} ${e.reserved_usd} USD: ${e.purpose} — ${e.note || ''}`);
  console.log(lines.join('\n'));
  if (process.env.GITHUB_STEP_SUMMARY) fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, lines.join('\n') + '\n');
}
