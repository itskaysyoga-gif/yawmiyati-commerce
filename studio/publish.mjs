// One publishing pass over approved batches (cron in the cloud, e.g. every 15 minutes in CI).
// Env: YM_META_TOKEN, YM_IG_USER_ID, YM_FB_PAGE_ID, YM_GRAPH_VERSION, YM_STORE_EG / YM_STORE_IQ (store base URLs),
// YM_STUDIO_KEY (facts endpoint), YM_WP_USER + YM_WP_APP_PASSWORD (upload-only) or YM_MEDIA_BASE_URL.
// Emergency stop: create studio/state/PAUSE (or run `node studio/publish.mjs --pause`).
import fs from 'node:fs';
import path from 'node:path';
import { run } from './lib/publish.mjs';
import { metaAdapter } from './lib/meta.mjs';

const ROOT = path.dirname(new URL(import.meta.url).pathname);
if (process.argv.includes('--pause')) { fs.mkdirSync(path.join(ROOT, 'state'), { recursive: true }); fs.writeFileSync(path.join(ROOT, 'state', 'PAUSE'), new Date().toISOString()); console.log('paused'); process.exit(0); }
if (process.argv.includes('--resume')) { fs.rmSync(path.join(ROOT, 'state', 'PAUSE'), { force: true }); console.log('resumed'); process.exit(0); }
const facts = async (cc, sku) => {
  const base = process.env[`YM_STORE_${cc}`];
  if (!base) return null;
  const r = await fetch(`${base.replace(/\/$/, '')}/wp-json/ym/v1/studio-facts?sku=${encodeURIComponent(sku)}`, { headers: { 'X-YM-Studio-Key': process.env.YM_STUDIO_KEY || '' } });
  return r.ok ? r.json() : null;
};
const landingOk = async (url) => { try { const r = await fetch(url, { redirect: 'follow' }); return r.status === 200; } catch { return false; } };
const out = await run(ROOT, { now: new Date(), facts, landingOk, adapter: metaAdapter() });
console.log(JSON.stringify(out, null, 1));
