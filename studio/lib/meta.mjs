// Meta Graph API adapter: Instagram Reels (container -> status -> media_publish) and Facebook Page videos.
// Version and IDs come from the environment; nothing here is verified against a real account yet
// (docs/CONTENT-STUDIO.md capability matrix). Every call has a timeout; a timeout on the step that makes the
// post public is reported as "unknown" so the publisher reconciles before retrying.
import fs from 'node:fs';
import path from 'node:path';

export function metaAdapter(env = process.env, { pollMs = 5000, pollMax = 60 } = {}) {
  const base = (env.YM_GRAPH_BASE || 'https://graph.facebook.com').replace(/\/$/, '') + '/' + (env.YM_GRAPH_VERSION || 'v24.0');
  const token = env.YM_META_TOKEN || '';
  const ig = env.YM_IG_USER_ID || '';
  const page = env.YM_FB_PAGE_ID || '';
  async function call(method, p, params = {}, timeoutMs = 20000) {
    const ctl = new AbortController();
    const t = setTimeout(() => ctl.abort(), timeoutMs);
    try {
      const url = new URL(base + p);
      const body = new URLSearchParams({ ...params, access_token: token });
      if (method === 'GET') { for (const [k, v] of body) url.searchParams.set(k, v); }
      const res = await fetch(url, { method, signal: ctl.signal, ...(method === 'GET' ? {} : { body }) });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || json.error) { const e = new Error(`${p}: ${json.error ? json.error.message : res.status}`); e.code = json.error && json.error.code; throw e; }
      return json;
    } catch (e) {
      if (e.name === 'AbortError') { const u = new Error(`${p}: timed out`); u.timeout = true; throw u; }
      throw e;
    } finally { clearTimeout(t); }
  }
  return {
    async checkToken() {
      if (!token) return { ok: false, reason: 'no token configured' };
      try { await call('GET', '/me', { fields: 'id' }); return { ok: true }; } catch (e) { return { ok: false, reason: e.code === 190 ? 'token expired or revoked (code 190)' : e.message }; }
    },
    // Public URL for the file: uploaded to the store's own media library with an application password that can only
    // upload (YM_WP_USER / YM_WP_APP_PASSWORD), unless YM_MEDIA_BASE_URL already serves the renders folder.
    async host(file, rec) {
      if (env.YM_MEDIA_BASE_URL) return env.YM_MEDIA_BASE_URL.replace(/\/$/, '') + '/' + path.basename(file);
      const store = env[`YM_STORE_${rec.country}`];
      const res = await fetch(`${store.replace(/\/$/, '')}/wp-json/wp/v2/media`, { method: 'POST', headers: { Authorization: 'Basic ' + Buffer.from(`${env.YM_WP_USER}:${env.YM_WP_APP_PASSWORD}`).toString('base64'), 'Content-Disposition': `attachment; filename="${path.basename(file)}"`, 'Content-Type': file.endsWith('.mp4') ? 'video/mp4' : 'image/png' }, body: fs.readFileSync(file) });
      const j = await res.json();
      if (!res.ok) throw new Error(`media upload: ${res.status}`);
      return j.source_url;
    },
    async publish(platform, rec, url) {
      if (platform === 'instagram') {
        const c = await call('POST', `/${ig}/media`, { media_type: 'REELS', video_url: url, caption: rec.caption, share_to_feed: 'true' });
        for (let i = 0; i < pollMax; i++) {
          const s = await call('GET', `/${c.id}`, { fields: 'status_code' });
          if (s.status_code === 'FINISHED') break;
          if (s.status_code === 'ERROR' || s.status_code === 'EXPIRED') throw new Error(`container ${s.status_code}`);
          if (i === pollMax - 1) throw new Error('container not ready in time');
          await new Promise((r) => setTimeout(r, pollMs));
        }
        try { return { id: (await call('POST', `/${ig}/media_publish`, { creation_id: c.id }, 30000)).id }; } catch (e) { if (e.timeout) e.unknown = true; throw e; }
      }
      if (platform === 'facebook') {
        try { return { id: (await call('POST', `/${page}/videos`, { file_url: url, description: rec.caption }, 60000)).id }; } catch (e) { if (e.timeout) e.unknown = true; throw e; }
      }
      throw new Error(`platform ${platform} not supported by this adapter`);
    },
    async find(platform, rec) {
      const marker = `utm_content=${rec.id}`;
      if (platform === 'instagram') {
        const r = await call('GET', `/${ig}/media`, { fields: 'id,caption,timestamp', limit: '50' });
        return ((r.data || []).find((m) => (m.caption || '').includes(marker)) || {}).id || null;
      }
      const r = await call('GET', `/${page}/videos`, { fields: 'id,description', limit: '50' });
      return ((r.data || []).find((m) => (m.description || '').includes(marker)) || {}).id || null;
    },
  };
}
