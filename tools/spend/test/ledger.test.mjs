// The shared Anthropic API ledger: limits, concurrency (file and git stores), unknown outcomes, retries.
// node --test tools/spend/test/*.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync, spawn } from 'node:child_process';
import { Ledger, FileStore, GitStore, emptyLedger, planReservation, totals, validate, BudgetError, openStore } from '../ledger.mjs';
import { paidCall, inputCeiling, ApiError } from '../anthropic.mjs';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const tmp = () => fs.mkdtempSync(path.join(os.tmpdir(), 'ym-ledger-'));
const run = (args) => new Promise((resolve) => {
  const p = spawn(process.execPath, [path.join(HERE, 'worker.mjs'), ...args]);
  let out = '';
  let err = '';
  p.stdout.on('data', (d) => { out += d; });
  p.stderr.on('data', (d) => { err += d; });
  p.on('close', (code) => resolve({ code, out: out.trim(), err }));
});
const git = (cwd, ...a) => execFileSync('git', a, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();

test('limits: cap, envelopes, unknown model, output ceiling, cap above 40 refused', () => {
  const d = emptyLedger();
  assert.throws(() => planReservation(d, { scope: 'eg', purpose: 'x', model: 'claude-made-up', maxInputTokens: 10, maxOutputTokens: 10 }), BudgetError);
  assert.throws(() => planReservation(d, { scope: 'eg', purpose: 'x', model: 'claude-sonnet-5-5', maxInputTokens: 10, maxOutputTokens: 9000 }), BudgetError);
  assert.throws(() => planReservation(d, { scope: 'nowhere', purpose: 'x', model: 'claude-sonnet-5-5', maxInputTokens: 10, maxOutputTokens: 10 }), BudgetError);
  // 6,000,000 input tokens at 1.5 x 2 USD = 18 USD: exactly the Egypt envelope; one more token is refused.
  const ok = planReservation(d, { scope: 'eg', purpose: 'x', model: 'claude-sonnet-5-5', maxInputTokens: 5_990_000, maxOutputTokens: 1 });
  d.entries.push(ok);
  assert.throws(() => planReservation(d, { scope: 'eg', purpose: 'x', model: 'claude-sonnet-5-5', maxInputTokens: 10_000, maxOutputTokens: 100 }), /envelope eg/);
  assert.ok(planReservation(d, { scope: 'iq', purpose: 'x', model: 'claude-sonnet-5-5', maxInputTokens: 10_000, maxOutputTokens: 100 }));
  assert.throws(() => validate({ ...emptyLedger(), cap_usd: 80 }), /at most 40/);
  assert.throws(() => validate({ ...emptyLedger(), envelopes: { eg: 30, iq: 12, gulf: 5, reserve: 5 } }), /more than the cap/);
});

test('unknown and failed calls keep their whole reservation; settled calls count their usage', () => {
  const d = emptyLedger();
  const a = planReservation(d, { scope: 'iq', purpose: 'a', model: 'claude-sonnet-5-5', maxInputTokens: 100_000, maxOutputTokens: 4000 });
  d.entries.push({ ...a, state: 'unknown' }, { ...a, id: 'b', state: 'failed' }, { ...a, id: 'c', state: 'settled', usd: 0.01 });
  assert.equal(Number(totals(d).byScope.iq.toFixed(6)), Number((a.reserved_usd * 2 + 0.01).toFixed(6)));
});

test('real calls refuse a checkout-local ledger file', () => {
  assert.throws(() => openStore({ YM_LEDGER_FILE: '/tmp/x.json' }), /tests only/);
});

test('file store: 12 processes at once cannot overspend an envelope', async () => {
  const dir = tmp();
  const file = path.join(dir, 'ledger.json');
  await new Ledger(new FileStore(file)).init([]);
  // Each reservation: 1,000,000 in x 3 USD + 8,000 out x 15 USD = 3.12 USD; Egypt envelope 18 USD holds 5.
  const res = await Promise.all(Array.from({ length: 12 }, () => run(['file', file, 'eg', '1000000', '8000'])));
  assert.deepEqual(res.filter((r) => r.code !== 0), []);
  assert.equal(res.filter((r) => r.out.startsWith('ok')).length, 5);
  const d = JSON.parse(fs.readFileSync(file, 'utf8'));
  assert.equal(d.entries.length, 5);
  assert.ok(totals(d).byScope.eg <= 18);
});

test('git store: two checkouts racing reserve against one branch; nothing lost, nothing overspent; a restart sees the balance', async () => {
  const dir = tmp();
  const bare = path.join(dir, 'remote.git');
  git(dir, 'init', '--quiet', '--bare', bare);
  const a = path.join(dir, 'a');
  const b = path.join(dir, 'b');
  for (const c of [a, b]) {
    git(dir, 'init', '--quiet', c);
    git(c, 'remote', 'add', 'origin', bare);
  }
  assert.equal(await new Ledger(new GitStore({ cwd: a })).init([]), true);
  assert.equal(await new Ledger(new GitStore({ cwd: b })).init([]), false, 'second init must not reset');
  // Iraq envelope 12 USD holds 3 reservations of 3.12 USD.
  const res = await Promise.all(Array.from({ length: 8 }, (_, i) => run(['git', i % 2 ? a : b, 'iq', '1000000', '8000'])));
  assert.deepEqual(res.filter((r) => r.code !== 0).map((r) => r.err), []);
  const ok = res.filter((r) => r.out.startsWith('ok')).map((r) => r.out.split(' ')[1]);
  assert.equal(ok.length, 3);
  const fresh = await new Ledger(new GitStore({ cwd: b })).state(); // a new process, another checkout
  assert.deepEqual(fresh.entries.map((e) => e.id).sort(), ok.sort());
  assert.ok(totals(fresh).byScope.iq <= 12);
  assert.equal(git(a, 'for-each-ref', 'refs/ym-ledger/'), '', 'temporary refs cleaned up');
});

const fakeKey = { ANTHROPIC_API_KEY: 'sk-ant-test-0123456789', GITHUB_RUN_ID: '7' };
const body = { model: 'claude-sonnet-5-5', max_tokens: 500, system: 'اكتبي', messages: [{ role: 'user', content: 'مرحبا' }] };

test('paidCall: reserve, settle with returned usage; ceiling covers the prompt bytes', async () => {
  const dir = tmp();
  const lg = new Ledger(new FileStore(path.join(dir, 'l.json')));
  await lg.init([]);
  const fetchImpl = async () => new Response(JSON.stringify({ content: [{ type: 'text', text: 'ok' }], usage: { input_tokens: 40, output_tokens: 20 } }), { status: 200 });
  const { entry } = await paidCall({ scope: 'eg', purpose: 't', body, ledger: lg, fetchImpl, env: fakeKey });
  assert.equal(entry.state, 'settled');
  assert.equal(entry.usd, Number(((40 * 3 + 20 * 15) / 1e6).toFixed(6)));
  assert.ok(inputCeiling(body) > Buffer.byteLength(JSON.stringify(body)));
});

test('paidCall: a timeout keeps the reservation as unknown; HTTP errors are failed and kept; a retry reserves again; the key never reaches the ledger', async () => {
  const dir = tmp();
  const file = path.join(dir, 'l.json');
  const lg = new Ledger(new FileStore(file));
  await lg.init([]);
  const timeout = async () => { const e = new Error('aborted'); e.name = 'TimeoutError'; throw e; };
  await assert.rejects(paidCall({ scope: 'iq', purpose: 't', body, ledger: lg, fetchImpl: timeout, env: fakeKey }), ApiError);
  const err500 = async () => new Response(JSON.stringify({ error: { type: 'api_error', message: 'boom sk-ant-test-0123456789' } }), { status: 500 });
  await assert.rejects(paidCall({ scope: 'iq', purpose: 't', body, ledger: lg, fetchImpl: err500, env: fakeKey }), (e) => !String(e.message).includes('sk-ant-test'));
  const d = JSON.parse(fs.readFileSync(file, 'utf8'));
  assert.deepEqual(d.entries.map((e) => e.state), ['unknown', 'failed']);
  assert.ok(d.entries.every((e) => e.reserved_usd > 0));
  assert.equal(totals(d).byScope.iq, d.entries[0].reserved_usd + d.entries[1].reserved_usd);
  assert.ok(!fs.readFileSync(file, 'utf8').includes('sk-ant'));
  await assert.rejects(paidCall({ scope: 'iq', purpose: 't', body: { ...body, model: 'claude-made-up' }, ledger: lg, fetchImpl: err500, env: fakeKey }), BudgetError);
  assert.equal(JSON.parse(fs.readFileSync(file, 'utf8')).entries.length, 2, 'an unknown model reserves nothing');
});
