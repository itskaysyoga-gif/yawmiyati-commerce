// Publisher against a fake Graph API (no Meta account involved): one post per approved item with its platform ID
// recorded, no duplicate when a timeout hides a successful publish, stale offers paused, emergency pause,
// dead letter after three failures, expired token stops everything, re-rendered files refused.
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { run } from '../lib/publish.mjs';
import { metaAdapter } from '../lib/meta.mjs';

const HERE = path.dirname(new URL(import.meta.url).pathname);
const facts = JSON.parse(fs.readFileSync(path.join(HERE, '..', 'fixtures', 'facts-iq-IQ030403SENK99.json'), 'utf8'));

// ---- fake Graph API ----
let server; let port; const state = { media: [], containers: {}, publishCalls: 0, hangPublish: 0, failAll: false, tokenBad: false };
before(async () => {
  server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      const u = new URL(req.url, 'http://x');
      const q = req.method === 'GET' ? u.searchParams : new URLSearchParams(body);
      const send = (code, j) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(j)); };
      if (state.tokenBad) return send(400, { error: { message: 'Error validating access token', code: 190 } });
      const p = u.pathname.replace(/^\/v\d+\.\d+/, '');
      if (p === '/me') return send(200, { id: 'me' });
      if (state.failAll && req.method === 'POST') return send(500, { error: { message: 'server error', code: 1 } });
      if (p === '/IG1/media' && req.method === 'POST') { const id = `C${Object.keys(state.containers).length + 1}`; state.containers[id] = { caption: q.get('caption'), video_url: q.get('video_url') }; return send(200, { id }); }
      if (/^\/C\d+$/.test(p)) return send(200, { status_code: 'FINISHED' });
      if (p === '/IG1/media_publish') {
        state.publishCalls++;
        const c = state.containers[q.get('creation_id')];
        const id = `M${state.media.length + 1}`;
        state.media.push({ id, caption: c.caption });
        if (state.hangPublish > 0) { state.hangPublish--; return; } // published, but the answer never arrives
        return send(200, { id });
      }
      if (p === '/IG1/media' && req.method === 'GET') return send(200, { data: state.media.map((m) => ({ id: m.id, caption: m.caption })) });
      send(404, { error: { message: 'unknown path ' + p } });
    });
  });
  await new Promise((r) => server.listen(0, '127.0.0.1', r));
  port = server.address().port;
});
after(() => { server.closeAllConnections(); server.close(); });

// ---- a scratch studio with one rendered, QA-passed record and an approved batch ----
function studio(id = 'iq-test-problem-h1') {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'studio-'));
  fs.mkdirSync(path.join(root, 'records', 'iq'), { recursive: true });
  fs.mkdirSync(path.join(root, 'renders', 'iq'), { recursive: true });
  fs.mkdirSync(path.join(root, 'batches'), { recursive: true });
  const file = path.join(root, 'renders', 'iq', `${id}.mp4`);
  fs.writeFileSync(file, 'not really a video');
  const sha = (f) => require_sha(f);
  const rec = { id, kind: 'reel', country: 'IQ', sku: facts.sku, status: 'qa_passed', offer_version: facts.offer_version, blocked: [], caption: `test\n${facts.landing_url}?utm_content=${id}`, tagged_url: `${facts.landing_url}?utm_content=${id}`, files: [{ role: 'master_9x16', path: `renders/iq/${id}.mp4`, sha256: sha(file) }], platform_ids: {}, attempts: [] };
  fs.writeFileSync(path.join(root, 'records', 'iq', `${id}.json`), JSON.stringify(rec));
  fs.writeFileSync(path.join(root, 'batches', 'b1.json'), JSON.stringify({ id: 'b1', platform: 'instagram', destination: 'test', format: 'master_9x16', approved_by: 'Rashid', approved_at: '2026-10-29T10:00:00Z', items: [{ record: id, at: '2026-10-29T12:00:00Z', sha256: { master_9x16: sha(file) } }] }));
  return { root, id, rec: () => JSON.parse(fs.readFileSync(path.join(root, 'records', 'iq', `${id}.json`), 'utf8')) };
}
import crypto from 'node:crypto';
function require_sha(f) { return crypto.createHash('sha256').update(fs.readFileSync(f)).digest('hex'); }

const adapter = () => metaAdapter({ YM_GRAPH_BASE: `http://127.0.0.1:${port}`, YM_GRAPH_VERSION: 'v24.0', YM_META_TOKEN: 'test', YM_IG_USER_ID: 'IG1', YM_MEDIA_BASE_URL: 'https://media.example.test/renders' }, { pollMs: 1, pollMax: 3 });
const deps = (over = {}) => ({ now: new Date('2026-10-29T12:30:00Z'), facts: async () => facts, landingOk: async () => true, adapter: adapter(), ...over });
const reset = () => { state.media = []; state.containers = {}; state.publishCalls = 0; state.hangPublish = 0; state.failAll = false; state.tokenBad = false; };

test('An approved item is published once and its platform ID recorded; the next pass does nothing', async () => {
  reset();
  const s = studio();
  const out = await run(s.root, deps());
  assert.deepEqual(out.published, [s.id]);
  assert.equal(s.rec().platform_ids.instagram, 'M1');
  assert.equal(s.rec().status, 'published');
  await run(s.root, deps());
  assert.equal(state.media.length, 1);
});

test('Not before its time, and nothing at all while paused', async () => {
  reset();
  const s = studio();
  assert.deepEqual((await run(s.root, deps({ now: new Date('2026-10-29T11:00:00Z') }))).skipped, [s.id]);
  fs.mkdirSync(path.join(s.root, 'state'), { recursive: true });
  fs.writeFileSync(path.join(s.root, 'state', 'PAUSE'), 'x');
  assert.equal((await run(s.root, deps())).paused, true);
  assert.equal(state.media.length, 0);
});

test('A timeout after the platform published: the retry finds the post instead of posting again', async () => {
  reset();
  state.hangPublish = 1;
  const s = studio();
  const slowAdapter = metaAdapter({ YM_GRAPH_BASE: `http://127.0.0.1:${port}`, YM_META_TOKEN: 'test', YM_IG_USER_ID: 'IG1', YM_MEDIA_BASE_URL: 'https://m.test' }, { pollMs: 1, pollMax: 3 });
  // shorten the publish timeout for the test
  const orig = slowAdapter.publish;
  slowAdapter.publish = (pf, rec, url) => Promise.race([orig(pf, rec, url), new Promise((_, rej) => setTimeout(() => { const e = new Error('timed out'); e.unknown = true; rej(e); }, 300))]);
  const first = await run(s.root, deps({ adapter: slowAdapter }));
  assert.deepEqual(first.failed, [s.id]);
  assert.equal(s.rec().attempts.at(-1).outcome, 'unknown');
  const second = await run(s.root, deps());
  assert.deepEqual(second.reconciled, [s.id]);
  assert.equal(s.rec().platform_ids.instagram, 'M1');
  assert.equal(state.media.length, 1, 'no duplicate post');
  assert.equal(state.publishCalls, 1);
});

test('A changed offer or stock, or a re-rendered file, pauses the item instead of publishing it', async () => {
  reset();
  const s = studio();
  const out = await run(s.root, deps({ facts: async () => ({ ...facts, offer_version: 'changed00000' }) }));
  assert.deepEqual(out.invalidated, [s.id]);
  assert.match(s.rec().invalidated.reasons.join(), /offer changed/);
  const s2 = studio('iq-test-stock-h1');
  await run(s2.root, deps({ facts: async () => ({ ...facts, in_stock: false }) }));
  assert.match(s2.rec().invalidated.reasons.join(), /out of stock/);
  const s3 = studio('iq-test-file-h1');
  fs.writeFileSync(path.join(s3.root, 'renders', 'iq', 'iq-test-file-h1.mp4'), 'edited later');
  await run(s3.root, deps());
  assert.match(s3.rec().invalidated.reasons.join(), /file changed since approval/);
  const s4 = studio('iq-test-land-h1');
  await run(s4.root, deps({ landingOk: async () => false }));
  assert.match(s4.rec().invalidated.reasons.join(), /landing page/);
  assert.equal(state.media.length, 0);
});

test('Production destination refuses blocked records and closed markets', async () => {
  reset();
  const s = studio();
  const b = JSON.parse(fs.readFileSync(path.join(s.root, 'batches', 'b1.json'), 'utf8'));
  b.destination = 'production';
  fs.writeFileSync(path.join(s.root, 'batches', 'b1.json'), JSON.stringify(b));
  await run(s.root, deps());
  const why = s.rec().invalidated.reasons.join(' | ');
  assert.match(why, /market not open/);
  assert.match(why, /media rights/);
  assert.equal(state.media.length, 0);
});

test('Three failures move the item to the dead letter with an alert; an expired token stops everything', async () => {
  reset();
  state.failAll = true;
  const s = studio();
  for (let i = 0; i < 3; i++) await run(s.root, deps());
  assert.equal(s.rec().status, 'dead_letter');
  assert.match(fs.readFileSync(path.join(s.root, 'state', 'alerts.log'), 'utf8'), /dead letter/);
  reset();
  state.tokenBad = true;
  const s2 = studio('iq-test-token-h1');
  const out = await run(s2.root, deps());
  assert.match(out.alert, /token expired/);
  assert.equal(s2.rec().attempts.length, 0);
});
