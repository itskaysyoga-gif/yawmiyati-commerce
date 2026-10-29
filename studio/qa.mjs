// Automated QA of rendered records: files present and unchanged, formats and lengths right, on-screen words within
// the script rules, offer still current. Visual and Arabic reading QA stays with a person (docs/CONTENT-STUDIO.md):
// this marks what a machine can check and lists what it cannot.
// Usage: node studio/qa.mjs [record-id ...]   (default: every record)   Exit 1 when any record fails.
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { check, BANNED } from './lib/rules.mjs';
import { sha256 } from './lib/publish.mjs';

const ROOT = path.dirname(new URL(import.meta.url).pathname);
const probe = (f) => { try { return JSON.parse(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'stream=width,height,codec_name:format=duration', '-of', 'json', f]).toString()); } catch { return null; } };
const ids = process.argv.slice(2);
const files = fs.readdirSync(path.join(ROOT, 'records')).flatMap((cc) => fs.readdirSync(path.join(ROOT, 'records', cc)).map((f) => path.join(ROOT, 'records', cc, f)));
let bad = 0;
for (const file of files) {
  const rec = JSON.parse(fs.readFileSync(file, 'utf8'));
  if (ids.length && !ids.includes(rec.id)) continue;
  const fails = [];
  for (const fl of rec.files) {
    const p = path.join(ROOT, fl.path);
    if (!fs.existsSync(p)) { fails.push(`${fl.role}: file missing`); continue; }
    if (sha256(p) !== fl.sha256) fails.push(`${fl.role}: file changed after rendering`);
    if (p.endsWith('.mp4')) {
      const pr = probe(p);
      const v = pr && pr.streams.find((s) => s.codec_name === 'h264');
      const secs = pr ? Number(pr.format.duration) : 0;
      if (!v) fails.push(`${fl.role}: not H.264 video`);
      else if (v.width !== fl.width || v.height !== fl.height) fails.push(`${fl.role}: ${v.width}x${v.height}, expected ${fl.width}x${fl.height}`);
      if (secs < 10 || secs > 35) fails.push(`${fl.role}: ${secs.toFixed(1)} s, outside 10-35 s`);
    }
  }
  const digits = /[0-9٠-٩]/;
  for (const c of rec.claims || []) {
    if (digits.test(c.line)) fails.push(`on-screen words carry a number: ${c.line}`);
    for (const w of BANNED) if (c.line.includes(w)) fails.push(`banned wording "${w}": ${c.line}`);
  }
  if (!rec.caption.includes(`utm_content=${rec.id}`)) fails.push('caption lacks the tagged link');
  rec.qa = { at: new Date().toISOString(), automated: fails.length ? 'failed' : 'passed', failures: fails, person_checks: ['Arabic reads naturally in the country voice (native reviewer)', 'photos show the exact item; nothing implies a demonstration', 'subtitles readable on a phone with sound off', 'offer card matches the store page today'] };
  if (rec.status === 'rendered' || rec.status === 'qa_failed' || rec.status === 'qa_passed') rec.status = fails.length ? 'qa_failed' : 'qa_passed';
  fs.writeFileSync(file, JSON.stringify(rec, null, 1));
  console.log(`${rec.id}: ${rec.qa.automated}${fails.length ? ' — ' + fails.join('; ') : ''}${rec.blocked && rec.blocked.length ? ` (publication blocked: ${rec.blocked.join('; ')})` : ''}`);
  if (fails.length) bad++;
}
process.exit(bad ? 1 : 0);
