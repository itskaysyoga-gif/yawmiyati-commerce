// Publishing approved content (docs/CONTENT-STUDIO.md). Platform calls go through an adapter (lib/meta.mjs for
// Instagram and Facebook through Meta's Graph API); this module holds the rules:
//   - only items of an approved batch, at or after their scheduled time, on the batch's platform and destination
//   - the emergency pause (studio/state/PAUSE) stops everything
//   - the token is checked first; an expired token raises an alert and nothing is attempted
//   - preflight right before posting: fresh facts from the store (same offer version: price, offers, delivery,
//     stock state, landing address, media rights), stock fresh, market open, landing page answering, files unchanged
//     since approval. Anything changed invalidates the item: it is paused, never published stale.
//   - an attempt whose outcome is unknown (timeout after the platform may have published) is reconciled with the
//     platform before any retry, so a retry never duplicates a post
//   - three failed attempts move the item to the dead-letter state with an alert
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

export const MAX_ATTEMPTS = 3;

export function sha256(file) { return crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex'); }

export function loadRecord(root, id) {
  const cc = id.split('-')[0];
  const f = path.join(root, 'records', cc, `${id}.json`);
  return { file: f, rec: JSON.parse(fs.readFileSync(f, 'utf8')) };
}
const save = (r) => fs.writeFileSync(r.file, JSON.stringify(r.rec, null, 1));

export function alert(root, text) {
  const dir = path.join(root, 'state');
  fs.mkdirSync(dir, { recursive: true });
  fs.appendFileSync(path.join(dir, 'alerts.log'), `${new Date().toISOString()} ${text}\n`);
  return text;
}

/** Preflight problems for one record (empty = safe to post now). */
export async function preflight(root, rec, item, batch, { facts, landingOk }) {
  const p = [];
  const f = await facts(rec.country, rec.sku);
  if (!f) return ['facts unavailable: the store did not answer'];
  if (f.offer_version !== rec.offer_version) p.push(`offer changed since rendering (${rec.offer_version} -> ${f.offer_version})`);
  if (!f.in_stock) p.push('out of stock');
  if (f.environment === 'production' && !f.stock_fresh) p.push('stock not confirmed within 48 h');
  if (batch.destination === 'production') {
    if (!f.market.catalog || !f.market.checkout) p.push('market not open for orders');
    if (f.media.some((m) => !m.organic)) p.push('media rights do not cover organic posting');
    if ((rec.blocked || []).length) p.push(`record blocked: ${rec.blocked.join('; ')}`);
    if (f.gate_blockers.length) p.push(`catalog gate: ${f.gate_blockers.join(', ')}`);
  }
  for (const fl of rec.files) {
    const want = (item.sha256 || {})[fl.role];
    if (want && (!fs.existsSync(path.join(root, fl.path)) || sha256(path.join(root, fl.path)) !== want)) p.push(`file changed since approval: ${fl.role}`);
  }
  if (!(await landingOk(rec.tagged_url))) p.push('landing page does not answer');
  return p;
}

/**
 * Run one publishing pass. deps: { now, facts(cc, sku), landingOk(url), adapter } where adapter implements
 * checkToken(), host(file) -> public URL, publish(platform, rec, url) -> {id} (throws {unknown:true} when the
 * outcome is unknown), find(platform, rec) -> id|null (reconciliation by the record ID in the tagged link).
 */
export async function run(root, deps) {
  const out = { published: [], invalidated: [], reconciled: [], failed: [], dead: [], skipped: [] };
  if (fs.existsSync(path.join(root, 'state', 'PAUSE'))) { out.paused = true; return out; }
  const tok = await deps.adapter.checkToken();
  if (!tok.ok) { out.alert = alert(root, `token problem: ${tok.reason}; nothing published`); return out; }
  const bdir = path.join(root, 'batches');
  const batches = fs.existsSync(bdir) ? fs.readdirSync(bdir).filter((f) => f.endsWith('.json')).map((f) => JSON.parse(fs.readFileSync(path.join(bdir, f), 'utf8'))) : [];
  for (const batch of batches) {
    if (!batch.approved_by || !batch.approved_at) continue;
    for (const item of batch.items) {
      if (new Date(item.at) > deps.now) { out.skipped.push(item.record); continue; }
      const r = loadRecord(root, item.record);
      const rec = r.rec;
      const platform = batch.platform;
      if ((rec.platform_ids || {})[platform] || ['invalidated', 'dead_letter'].includes(rec.status)) continue;
      // Unknown outcome from an earlier attempt: ask the platform before doing anything else.
      const last = (rec.attempts || []).at(-1);
      if (last && last.outcome === 'unknown') {
        const found = await deps.adapter.find(platform, rec);
        if (found) {
          rec.platform_ids = { ...(rec.platform_ids || {}), [platform]: found };
          rec.status = 'published';
          rec.attempts.push({ at: deps.now.toISOString(), outcome: 'reconciled', id: found });
          save(r); out.reconciled.push(rec.id);
          continue;
        }
      }
      const problems = await preflight(root, rec, item, batch, deps);
      if (problems.length) {
        rec.status = 'invalidated';
        rec.invalidated = { at: deps.now.toISOString(), reasons: problems };
        save(r); out.invalidated.push(rec.id);
        alert(root, `${rec.id} not published: ${problems.join('; ')}`);
        continue;
      }
      const media = rec.files.find((fl) => fl.role === (batch.format || 'master_9x16'));
      try {
        const url = await deps.adapter.host(path.join(root, media.path), rec);
        const res = await deps.adapter.publish(platform, rec, url);
        rec.platform_ids = { ...(rec.platform_ids || {}), [platform]: res.id };
        rec.status = 'published';
        rec.attempts = [...(rec.attempts || []), { at: deps.now.toISOString(), outcome: 'published', id: res.id }];
        rec.approval = { status: 'approved', batch: batch.id, by: batch.approved_by, at: batch.approved_at };
        out.published.push(rec.id);
      } catch (e) {
        rec.attempts = [...(rec.attempts || []), { at: deps.now.toISOString(), outcome: e.unknown ? 'unknown' : 'failed', error: String(e.message).slice(0, 300) }];
        const fails = rec.attempts.filter((a) => a.outcome === 'failed' || a.outcome === 'unknown').length;
        if (fails >= MAX_ATTEMPTS) {
          rec.status = 'dead_letter';
          out.dead.push(rec.id);
          alert(root, `${rec.id} moved to dead letter after ${fails} attempts: ${e.message}`);
        } else out.failed.push(rec.id);
      }
      save(r);
    }
  }
  return out;
}
