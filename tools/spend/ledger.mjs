// One Anthropic API budget for every paid generator in this repo (studio briefs, copy drafts, language critique).
// Spec: docs/API-BUDGET.md.
//
// The ledger is a single JSON file on its own git branch (ledger/anthropic) in the GitHub repository. Every change is
// a new commit pushed with a compare-and-swap (--force-with-lease on the commit we read), so two runs that read the
// same balance cannot both write: the second push is rejected, re-reads and re-checks. A checkout, a branch switch, a
// restart or a retry never sees an older balance, because nothing is kept in the checkout.
//
// Each call: reserve the most it could cost (UTF-8 bytes of the prompt as an input-token ceiling + max_tokens of
// output, at the counted rate) → call → settle with the usage the API returned. A call whose outcome is unknown
// (timeout, broken connection, unreadable answer) or that failed keeps its full reservation: it is never treated as
// free. Only a person reconciling against the Anthropic console may lower an entry (cli.mjs reconcile).
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import crypto from 'node:crypto';

const HERE = path.dirname(new URL(import.meta.url).pathname);
export const RATES = JSON.parse(fs.readFileSync(path.join(HERE, 'rates.json'), 'utf8'));
export const FORMAT = 'ym-api-ledger-1';
export const SCOPES = ['eg', 'iq', 'gulf', 'reserve'];
// Envelopes inside the one 40 USD ceiling (ChatGPT review, 29 Oct): planning limits, not targets.
export const DEFAULTS = { cap_usd: 40, envelopes: { eg: 18, iq: 12, gulf: 5, reserve: 5 } };
const HOLDING = new Set(['reserved', 'unknown', 'failed']); // count at the reserved amount

export class BudgetError extends Error {}
export class LedgerBusy extends Error {}

export function emptyLedger() {
  return { format: FORMAT, ...structuredClone(DEFAULTS), rates_source: RATES.source, count_multiplier: RATES.count_multiplier, entries: [] };
}

export function rate(model) {
  const r = RATES.models[model];
  if (!r || !(r.in > 0) || !(r.out > 0)) throw new BudgetError(`model "${model}" has no list price in tools/spend/rates.json; refusing to call it`);
  const m = Number(RATES.count_multiplier);
  if (!(m >= 1)) throw new BudgetError('count_multiplier must be at least 1');
  return { in: r.in * m, out: r.out * m, list_in: r.in, list_out: r.out };
}

export const usd = (model, inTok, outTok, which = 'counted') => {
  const r = rate(model);
  return which === 'list' ? (inTok * r.list_in + outTok * r.list_out) / 1e6 : (inTok * r.in + outTok * r.out) / 1e6;
};

/** What an entry holds against the budget. */
export function held(e) {
  if (HOLDING.has(e.state)) return e.reserved_usd;
  return e.usd;
}

export function validate(data) {
  if (!data || data.format !== FORMAT) throw new BudgetError('ledger: unknown format');
  if (!(data.cap_usd > 0) || data.cap_usd > 40) throw new BudgetError('ledger: cap must be above 0 and at most 40 USD (Rashid, 29 Oct)');
  const env = data.envelopes || {};
  for (const s of SCOPES) if (!(env[s] >= 0)) throw new BudgetError(`ledger: envelope ${s} missing`);
  if (Object.values(env).reduce((a, b) => a + b, 0) > data.cap_usd + 1e-9) throw new BudgetError('ledger: envelopes add up to more than the cap');
  if (!Array.isArray(data.entries)) throw new BudgetError('ledger: entries missing');
  for (const e of data.entries) if (!(held(e) >= 0)) throw new BudgetError(`ledger: entry ${e.id} has no amount`);
  return data;
}

export function totals(data) {
  const byScope = Object.fromEntries(SCOPES.map((s) => [s, 0]));
  let total = 0;
  let list = 0;
  for (const e of data.entries) {
    const h = held(e);
    total += h;
    byScope[e.scope] = (byScope[e.scope] || 0) + h;
    list += e.usd_list ?? 0;
  }
  return { total, byScope, remaining: data.cap_usd - total, list_known: list };
}

/** Pure: the reservation entry for a call, or a BudgetError. */
export function planReservation(data, { scope, purpose, model, maxInputTokens, maxOutputTokens, run = '' }) {
  validate(data);
  if (!SCOPES.includes(scope)) throw new BudgetError(`scope must be one of ${SCOPES.join(', ')}`);
  if (!Number.isInteger(maxOutputTokens) || maxOutputTokens < 1 || maxOutputTokens > RATES.max_output_tokens) throw new BudgetError(`max_tokens must be 1-${RATES.max_output_tokens}`);
  if (!Number.isInteger(maxInputTokens) || maxInputTokens < 1) throw new BudgetError('input ceiling missing');
  if (model === 'claude-haiku-5-5' && maxInputTokens > 100000) throw new BudgetError('prompt too large for the listed Haiku price');
  const reserved = Number(usd(model, maxInputTokens, maxOutputTokens).toFixed(6));
  const t = totals(data);
  if (t.total + reserved > data.cap_usd + 1e-9) throw new BudgetError(`budget: ${t.total.toFixed(4)} of ${data.cap_usd} USD held; this call could cost ${reserved.toFixed(4)}`);
  if (t.byScope[scope] + reserved > data.envelopes[scope] + 1e-9) throw new BudgetError(`budget: envelope ${scope} holds ${t.byScope[scope].toFixed(4)} of ${data.envelopes[scope]} USD; this call could cost ${reserved.toFixed(4)}`);
  return { id: crypto.randomBytes(8).toString('hex'), at: new Date().toISOString(), scope, purpose: String(purpose).slice(0, 120), model, run: String(run).slice(0, 40), state: 'reserved', max_input_tokens: maxInputTokens, max_output_tokens: maxOutputTokens, reserved_usd: reserved, usd: null, usd_list: null };
}

/* ---------- stores ---------- */

/** Local file store with an exclusive lock file. Tests only (YM_LEDGER_FILE): a checkout-local ledger is exactly what the review found unsafe for real calls. */
export class FileStore {
  constructor(file) { this.file = file; this.lock = `${file}.lock`; }
  async update(fn, message = 'ledger') {
    const deadline = Date.now() + 30000;
    for (;;) {
      try { fs.writeFileSync(this.lock, String(process.pid), { flag: 'wx' }); break; } catch (e) {
        if (e.code !== 'EEXIST') throw e;
        if (Date.now() > deadline) throw new LedgerBusy('ledger lock held for 30 s');
        await new Promise((r) => setTimeout(r, 5 + Math.random() * 20));
      }
    }
    try {
      const data = fs.existsSync(this.file) ? JSON.parse(fs.readFileSync(this.file, 'utf8')) : null;
      const { next, result } = await fn(data);
      if (next) {
        fs.writeFileSync(`${this.file}.tmp${process.pid}`, JSON.stringify(validate(next), null, 1) + '\n');
        fs.renameSync(`${this.file}.tmp${process.pid}`, this.file);
      }
      return result;
    } finally { fs.rmSync(this.lock, { force: true }); }
  }
  async read() { return fs.existsSync(this.file) ? JSON.parse(fs.readFileSync(this.file, 'utf8')) : null; }
}

/** Git branch store: compare-and-swap pushes to refs/heads/<branch> of <remote>. */
export class GitStore {
  constructor({ cwd, remote = 'origin', branch = 'ledger/anthropic', file = 'ledger.json' } = {}) {
    Object.assign(this, { cwd: cwd || process.cwd(), remote, branch, file });
    if (!/^[A-Za-z0-9._/-]+$/.test(branch) || branch.includes('..')) throw new Error('bad ledger branch name');
  }
  git(args, input) {
    // Another process in the same checkout may hold a git lock (shallow.lock, index.lock): wait and retry.
    for (let i = 0; ; i++) {
      try { return this.gitOnce(args, input); } catch (e) {
        if (i < 40 && /\.lock': File exists|Unable to create .*\.lock|shallow file has changed/.test(String(e.stderr || ''))) { const until = Date.now() + 25 + Math.random() * 75; while (Date.now() < until) { /* short spin */ } continue; }
        throw e;
      }
    }
  }
  gitOnce(args, input) {
    return execFileSync('git', args, { cwd: this.cwd, input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], env: { ...process.env, GIT_TERMINAL_PROMPT: '0', GIT_AUTHOR_NAME: 'api-ledger', GIT_AUTHOR_EMAIL: 'actions@users.noreply.github.com', GIT_COMMITTER_NAME: 'api-ledger', GIT_COMMITTER_EMAIL: 'actions@users.noreply.github.com' } }).trim();
  }
  fetchBase() {
    // A ref of our own per read: several processes may share one checkout.
    const local = `refs/ym-ledger/${process.pid}-${crypto.randomBytes(4).toString('hex')}`;
    try { this.git(['fetch', '--quiet', '--no-tags', '--depth=1', this.remote, `+refs/heads/${this.branch}:${local}`]); } catch (e) {
      if (/couldn't find remote ref|not our ref|no such ref/i.test(String(e.stderr || e.message))) return { sha: '', data: null };
      throw e;
    }
    try {
      const sha = this.git(['rev-parse', local]);
      return { sha, data: JSON.parse(this.git(['show', `${sha}:${this.file}`])) };
    } finally {
      try { this.git(['update-ref', '-d', local]); } catch { /* best effort */ }
    }
  }
  async read() { return this.fetchBase().data; }
  async update(fn, message = 'ledger') {
    for (let attempt = 1; attempt <= 15; attempt++) {
      const { sha, data } = this.fetchBase();
      const { next, result } = await fn(data ? structuredClone(data) : null);
      if (!next) return result;
      const blob = this.git(['hash-object', '-w', '--stdin'], JSON.stringify(validate(next), null, 1) + '\n');
      const tree = this.git(['mktree'], `100644 blob ${blob}\t${this.file}\n`);
      const commit = this.git(['commit-tree', tree, ...(sha ? ['-p', sha] : []), '-m', String(message).slice(0, 200)]);
      try {
        this.git(['push', '--quiet', '--porcelain', `--force-with-lease=refs/heads/${this.branch}:${sha}`, this.remote, `${commit}:refs/heads/${this.branch}`]);
        return result;
      } catch (e) {
        const msg = String(e.stdout || '') + String(e.stderr || '');
        if (!/stale info|rejected|fetch first|non-fast-forward|failed to push|cannot lock ref/i.test(msg)) throw e;
        await new Promise((r) => setTimeout(r, Math.min(4000, 100 * attempt * attempt) * (0.5 + Math.random())));
      }
    }
    throw new LedgerBusy('ledger: 15 push attempts rejected; another run keeps writing');
  }
}

export function openStore(env = process.env) {
  if (env.YM_LEDGER_FILE) {
    if (env.YM_LEDGER_TEST !== '1') throw new BudgetError('YM_LEDGER_FILE is for tests only (set YM_LEDGER_TEST=1); real calls use the git ledger');
    return new FileStore(env.YM_LEDGER_FILE);
  }
  return new GitStore({ cwd: env.YM_LEDGER_REPO || process.cwd(), remote: env.YM_LEDGER_REMOTE || 'origin' });
}

/* ---------- operations ---------- */

export class Ledger {
  constructor(store) { this.store = store; }
  async state() { const d = await this.store.read(); return d ? validate(d) : null; }
  async init(prior = []) {
    return this.store.update((d) => (d ? { result: false } : { next: { ...emptyLedger(), entries: prior }, result: true }), 'ledger: start');
  }
  async reserve(req) {
    return this.store.update((d) => {
      if (!d) throw new BudgetError('ledger not started (node tools/spend/cli.mjs init)');
      const e = planReservation(d, req);
      d.entries.push(e);
      return { next: d, result: e };
    }, `reserve ${req.scope} ${req.purpose}`.slice(0, 120));
  }
  async settle(id, usage) {
    return this.store.update((d) => {
      const e = d.entries.find((x) => x.id === id);
      if (!e) throw new Error(`no entry ${id}`);
      const inTok = Number(usage?.input_tokens) + Number(usage?.cache_creation_input_tokens || 0) + Number(usage?.cache_read_input_tokens || 0);
      const outTok = Number(usage?.output_tokens);
      if (!Number.isFinite(inTok) || !Number.isFinite(outTok)) { Object.assign(e, { state: 'unknown', note: 'usage missing in the answer' }); return { next: d, result: e }; }
      Object.assign(e, { state: 'settled', input_tokens: inTok, output_tokens: outTok, usd: Number(usd(e.model, inTok, outTok).toFixed(6)), usd_list: Number(usd(e.model, inTok, outTok, 'list').toFixed(6)) });
      if (e.usd > e.reserved_usd) e.note = 'usage exceeded the reservation';
      return { next: d, result: e };
    }, `settle ${id}`);
  }
  async mark(id, state, note) {
    if (!['unknown', 'failed'].includes(state)) throw new Error('mark: unknown or failed only');
    return this.store.update((d) => {
      const e = d.entries.find((x) => x.id === id);
      if (!e) throw new Error(`no entry ${id}`);
      Object.assign(e, { state, note: String(note || '').replace(/sk-ant-[A-Za-z0-9_-]+/g, '[key]').slice(0, 200) });
      return { next: d, result: e };
    }, `${state} ${id}`);
  }
  /** A person compares with the Anthropic console and records what was actually used. */
  async reconcile(id, { input_tokens, output_tokens, note }) {
    return this.store.update((d) => {
      const e = d.entries.find((x) => x.id === id);
      if (!e) throw new Error(`no entry ${id}`);
      Object.assign(e, { state: 'settled', input_tokens, output_tokens, usd: Number(usd(e.model, input_tokens, output_tokens).toFixed(6)), usd_list: Number(usd(e.model, input_tokens, output_tokens, 'list').toFixed(6)), note: `reconciled: ${String(note || '').slice(0, 160)}` });
      return { next: d, result: e };
    }, `reconcile ${id}`);
  }
}
