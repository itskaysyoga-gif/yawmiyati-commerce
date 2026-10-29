// Render one product's localized batch from its facts and its reviewed brief, and write a content record per asset.
// Usage: node studio/render.mjs studio/fixtures/facts-iq-IQ030403SENK99.json studio/briefs/iq/IQ030403SENK99.json
// Runs in the cloud (this build container or CI), never on the WooCommerce host and never on a laptop.
// Output: studio/renders/<cc>/... (git-ignored; uploaded to media storage on publish) and studio/records/<cc>/*.json.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { bundle } from '@remotion/bundler';
import { renderMedia, renderStill, selectComposition } from '@remotion/renderer';
import { check } from './lib/rules.mjs';

const ROOT = path.dirname(new URL(import.meta.url).pathname);
const REPO = path.resolve(ROOT, '..');
const TEMPLATE_VERSION = 'photo-motion@1';
const [factsFile, briefFile] = process.argv.slice(2);
const facts = JSON.parse(fs.readFileSync(factsFile, 'utf8'));
const brief = JSON.parse(fs.readFileSync(briefFile, 'utf8'));
const cc = facts.country.toLowerCase();

const errors = check(brief, facts);
if (errors.length) { console.error('Brief breaks the script rules:\n- ' + errors.join('\n- ')); process.exit(1); }

// Product photos: the store's own files (local stores) or downloaded from the store (CI). Copied into the bundle.
const pub = path.join(ROOT, 'video', 'public', 'media', cc);
fs.mkdirSync(pub, { recursive: true });
const images = [];
for (const m of facts.media) {
  const u = new URL(m.url);
  const local = path.join(REPO, 'dev', 'sites', cc, decodeURIComponent(u.pathname));
  const dst = path.join(pub, m.file);
  if (fs.existsSync(local)) fs.copyFileSync(local, dst);
  else fs.writeFileSync(dst, Buffer.from(await (await fetch(m.url)).arrayBuffer()));
  images.push(`media/${cc}/${m.file}`);
}
const draft = !facts.media.length || !facts.media.every((m) => m.organic);
const chrome = process.env.YM_CHROME || (() => { const d = '/opt/pw-browsers'; const s = fs.existsSync(d) && fs.readdirSync(d).find((x) => x.startsWith('chromium_headless_shell')); return s ? path.join(d, s, 'chrome-linux', 'headless_shell') : undefined; })();
const browserExecutable = chrome && fs.existsSync(chrome) ? chrome : (fs.existsSync('/opt/pw-browsers/chromium_headless_shell-1243/chrome-headless-shell') ? '/opt/pw-browsers/chromium_headless_shell-1243/chrome-headless-shell/chrome-headless-shell' : undefined);

const serveUrl = await bundle({ entryPoint: path.join(ROOT, 'video', 'index.jsx'), publicDir: path.join(ROOT, 'video', 'public') });
const outDir = path.join(ROOT, 'renders', cc);
const recDir = path.join(ROOT, 'records', cc);
fs.mkdirSync(outDir, { recursive: true });
fs.mkdirSync(recDir, { recursive: true });
const sha = (f) => crypto.createHash('sha256').update(fs.readFileSync(f)).digest('hex');
const rel = (f) => path.relative(ROOT, f);
const only = process.env.YM_ONLY ? new RegExp(process.env.YM_ONLY) : null;

function record(id, kind, extra) {
  const tagged = `${facts.landing_url}${facts.landing_url.includes('?') ? '&' : '?'}utm_source=instagram&utm_medium=organic&utm_campaign=${cc}-${facts.sku.toLowerCase()}&utm_content=${id}`;
  return {
    id, kind, country: facts.country, sku: facts.sku, product: facts.name,
    status: 'rendered',
    template_version: TEMPLATE_VERSION,
    narration: 'none (sound-off captions; narration only after dialect auditions)',
    music: 'none (licensed music for paid use not yet chosen)',
    photo_based: true, demonstration: false,
    source_evidence: { facts_offer_version: facts.offer_version, facts_exported_at: facts.exported_at, environment: facts.environment, brief: rel(briefFile), brief_status: brief.status, brief_model: brief.model },
    offer_version: facts.offer_version,
    media: facts.media.map((m) => ({ id: m.id, file: m.file, final: m.final, organic: m.organic, paid: m.paid, rights: m.rights })),
    claims: [],
    caption: '',
    landing_url: facts.landing_url,
    tagged_url: tagged,
    campaign: { utm_source: 'instagram', utm_medium: 'organic', utm_campaign: `${cc}-${facts.sku.toLowerCase()}`, utm_content: id },
    blocked: [...(draft ? ['media rights not approved for organic use (interim supplier photos)'] : []), ...(brief.status && !/review(ed)? done|approved/.test(brief.status) ? ['dialect review pending'] : []), ...(facts.gate_blockers.length ? [`catalog gate: ${facts.gate_blockers.join(', ')}`] : [])],
    approval: { status: 'pending', batch: null, by: null, at: null },
    schedule: null, platform_ids: {}, attempts: [], results: null, qa: null,
    files: [],
    created_at: new Date().toISOString(),
    ...extra,
  };
}

const written = [];
for (const angle of brief.angles) {
  for (const [hi, hook] of angle.hooks.entries()) {
    const id = `${cc}-${facts.sku.toLowerCase()}-${angle.id}-h${hi + 1}`;
    if (only && !only.test(id)) continue;
    const props = { facts, angle, hook, images, copy: { cod: facts.cod }, draft };
    const rec = record(id, 'reel', { angle: angle.id, hook_index: hi + 1, claims: [{ line: hook, source: 'hook (no factual claim)' }, ...angle.beats.map((b) => ({ line: b.line, source: b.source })), { line: angle.cta, source: 'cta' }] });
    rec.caption = `${angle.caption}\n\n${rec.tagged_url}`;
    const master = path.join(outDir, `${id}-9x16.mp4`);
    const comp = await selectComposition({ serveUrl, id: 'Reel', inputProps: props, browserExecutable });
    await renderMedia({ composition: comp, serveUrl, codec: 'h264', crf: 20, x264Preset: 'veryfast', outputLocation: master, inputProps: props, browserExecutable, concurrency: 4, muted: true });
    rec.files.push({ role: 'master_9x16', path: rel(master), width: 1080, height: 1920, seconds: comp.durationInFrames / comp.fps, sha256: sha(master) });
    const thumb = path.join(outDir, `${id}-thumb.jpg`);
    await renderStill({ composition: comp, serveUrl, output: thumb, frame: 45, inputProps: props, imageFormat: 'jpeg', jpegQuality: 88, browserExecutable });
    rec.files.push({ role: 'thumbnail', path: rel(thumb), sha256: sha(thumb) });
    if (hi === 0) { // one deliberate feed derivative per angle (re-composed, not cropped)
      const d = path.join(outDir, `${id}-4x5.mp4`);
      const c45 = await selectComposition({ serveUrl, id: 'Reel45', inputProps: props, browserExecutable });
      await renderMedia({ composition: c45, serveUrl, codec: 'h264', crf: 20, x264Preset: 'veryfast', outputLocation: d, inputProps: props, browserExecutable, concurrency: 4, muted: true });
      rec.files.push({ role: 'feed_4x5', path: rel(d), width: 1080, height: 1350, seconds: c45.durationInFrames / c45.fps, sha256: sha(d) });
    }
    fs.writeFileSync(path.join(recDir, `${id}.json`), JSON.stringify(rec, null, 1));
    written.push(id);
    console.log('rendered', id);
  }
}

// Carousel (4:5 stills, last slide = the offer) and a story frame (9:16 offer).
if (!only || only.test('carousel')) {
  const id = `${cc}-${facts.sku.toLowerCase()}-carousel`;
  const slides = brief.carousel;
  const rec = record(id, 'carousel', { claims: slides.map((s) => ({ line: s, source: 'carousel' })), });
  rec.caption = `${brief.angles.find((a) => a.id === 'problem').caption}\n\n${rec.tagged_url}`;
  for (let i = 0; i < slides.length; i++) {
    const props = { facts, angle: brief.angles.find((a) => a.id === 'offer'), images, copy: { cod: facts.cod }, slides, index: i, draft };
    const comp = await selectComposition({ serveUrl, id: 'Slide', inputProps: props, browserExecutable });
    const out = path.join(outDir, `${id}-${i + 1}.png`);
    await renderStill({ composition: comp, serveUrl, output: out, inputProps: props, browserExecutable });
    rec.files.push({ role: `slide_${i + 1}`, path: rel(out), width: 1080, height: 1350, sha256: sha(out) });
  }
  const sprops = { facts, angle: brief.angles.find((a) => a.id === 'offer'), images, copy: { cod: facts.cod }, draft };
  const scomp = await selectComposition({ serveUrl, id: 'Story', inputProps: sprops, browserExecutable });
  const story = path.join(outDir, `${cc}-${facts.sku.toLowerCase()}-story.png`);
  await renderStill({ composition: scomp, serveUrl, output: story, inputProps: sprops, browserExecutable });
  rec.files.push({ role: 'story_9x16', path: rel(story), width: 1080, height: 1920, sha256: sha(story) });
  fs.writeFileSync(path.join(recDir, `${id}.json`), JSON.stringify(rec, null, 1));
  written.push(id);
  console.log('rendered', id);
}
console.log(`${written.length} records in ${rel(recDir)}`);
