// Owner batch approval: one decision covers a set of records and their schedule; the publisher then posts them
// automatically within that scope. Re-rendering a file after approval invalidates it (hashes are recorded here).
// Usage: node studio/approve.mjs --batch <id> --by <name> --platform instagram --destination test|production \
//          --at 2026-11-03T19:00:00+02:00 --every 24h <record-id> [record-id ...]
// Production refuses records with open blocks (media rights, dialect review, catalog gate); a test destination
// (a private test account) accepts them so the pipeline can be proven before the facts are final.
// Paid promotion, new claims or a changed offer are outside any batch: they need a new decision.
import fs from 'node:fs';
import path from 'node:path';
import { loadRecord } from './lib/publish.mjs';

const ROOT = path.dirname(new URL(import.meta.url).pathname);
const a = process.argv.slice(2);
const opt = (k, d) => { const i = a.indexOf(`--${k}`); return i >= 0 ? a.splice(i, 2)[1] : d; };
const id = opt('batch'); const by = opt('by'); const platform = opt('platform', 'instagram'); const destination = opt('destination', 'test');
const start = new Date(opt('at', new Date().toISOString())); const every = opt('every', '24h'); const format = opt('format', 'master_9x16');
const step = Number(every.replace(/h$/, '')) * 3600e3;
if (!id || !by || !a.length) { console.error('usage: --batch <id> --by <name> [--platform] [--destination test|production] [--at] [--every 24h] <record-id>...'); process.exit(2); }
const items = [];
for (const [i, rid] of a.entries()) {
  const { rec } = loadRecord(ROOT, rid);
  if (rec.status !== 'qa_passed') { console.error(`${rid}: status ${rec.status}, needs qa_passed`); process.exit(1); }
  if (destination === 'production' && (rec.blocked || []).length) { console.error(`${rid}: blocked for production: ${rec.blocked.join('; ')}`); process.exit(1); }
  items.push({ record: rid, at: new Date(start.getTime() + i * step).toISOString(), sha256: Object.fromEntries(rec.files.map((f) => [f.role, f.sha256])) });
}
fs.mkdirSync(path.join(ROOT, 'batches'), { recursive: true });
const batch = { id, platform, destination, format, approved_by: by, approved_at: new Date().toISOString(), scope: 'organic posts only; no paid spend, no new claims, no offer changes', items };
fs.writeFileSync(path.join(ROOT, 'batches', `${id}.json`), JSON.stringify(batch, null, 1));
console.log(`batch ${id}: ${items.length} item(s) for ${platform} (${destination}), first at ${items[0].at}`);
