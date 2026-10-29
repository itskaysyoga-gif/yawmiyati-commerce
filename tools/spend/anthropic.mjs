// The only way code in this repo calls the Anthropic Messages API: reserve → call → settle (tools/spend/ledger.mjs).
// No retries in here: a caller that retries makes a new call, which makes a new reservation.
import { Ledger, openStore, BudgetError, rate } from './ledger.mjs';

const ENDPOINT = 'https://api.anthropic.com/v1/messages';
const OVERHEAD_TOKENS = 2000; // message framing and tool definitions beyond the JSON we send

/** Upper bound for input tokens: a token always covers at least one byte of the UTF-8 prompt. */
export function inputCeiling(body) {
  return Buffer.byteLength(JSON.stringify({ system: body.system ?? '', messages: body.messages ?? [], tools: body.tools ?? [], tool_choice: body.tool_choice ?? null }), 'utf8') + OVERHEAD_TOKENS;
}

export class ApiError extends Error {
  constructor(message, status) { super(message); this.status = status; }
}

const clean = (s) => String(s).replace(/sk-ant-[A-Za-z0-9_-]+/g, '[key]');

/**
 * @param {object} o
 * @param {string} o.scope     eg | iq | gulf | reserve
 * @param {string} o.purpose   short description for the ledger
 * @param {object} o.body      Messages API body; model and max_tokens required
 * @param {Ledger} [o.ledger]
 * @param {Function} [o.fetchImpl] tests only
 */
export async function paidCall({ scope, purpose, body, ledger, fetchImpl, timeoutMs = 180000, env = process.env }) {
  const key = env.ANTHROPIC_API_KEY;
  if (!key) throw new BudgetError('ANTHROPIC_API_KEY is empty');
  rate(body.model); // unknown model: refuse before reserving
  const lg = ledger || new Ledger(openStore(env));
  const r = await lg.reserve({ scope, purpose, model: body.model, maxInputTokens: inputCeiling(body), maxOutputTokens: body.max_tokens, run: env.GITHUB_RUN_ID || 'local' });
  let res;
  try {
    res = await (fetchImpl || fetch)(ENDPOINT, { method: 'POST', signal: AbortSignal.timeout(timeoutMs), headers: { 'x-api-key': key, 'anthropic-version': '2023-06-01', 'content-type': 'application/json' }, body: JSON.stringify(body) });
  } catch (e) {
    await lg.mark(r.id, 'unknown', `no answer: ${e.name}`); // it may have run; the reservation stays
    throw new ApiError(`no answer from the API (${e.name}); reservation kept`, 0);
  }
  let json;
  try { json = await res.json(); } catch (e) {
    await lg.mark(r.id, 'unknown', `unreadable answer, HTTP ${res.status}`);
    throw new ApiError(`unreadable answer (HTTP ${res.status}); reservation kept`, res.status);
  }
  if (!res.ok) {
    await lg.mark(r.id, 'failed', `HTTP ${res.status} ${json?.error?.type || ''}`);
    throw new ApiError(clean(`API ${res.status}: ${JSON.stringify(json).slice(0, 300)}`), res.status);
  }
  const entry = await lg.settle(r.id, json.usage);
  return { json, entry };
}
