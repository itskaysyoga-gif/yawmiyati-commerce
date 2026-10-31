// Staging release and order-test window against a fake host (deploy/test/fake: wp, curl, ssh, gh).
// Proves the refusals happen before any store file changes, failed releases roll back, interrupted runs recover, open
// launch items are not failures, and the development window only opens behind the password with the cron running.
// Run: node --test deploy/test/*.test.mjs
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync, execFileSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const REPO = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../..');
const BIN = path.join(REPO, 'deploy/test/fake');
const SHA = 'a'.repeat(40);
const AUTH = 'ym:secret';
for (const f of fs.readdirSync(BIN)) fs.chmodSync(path.join(BIN, f), 0o755);

function host({ stores = ['eg', 'iq'], active = true } = {}) {
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'ym-host-'));
  const root = path.join(home, 'www/staging.example.shop/public_html');
  for (const cc of stores) {
    const s = path.join(root, cc);
    fs.mkdirSync(path.join(s, '.fake'), { recursive: true });
    fs.writeFileSync(path.join(s, 'wp-config.php'), '<?php');
    for (const d of ['wp-content/plugins/yawmiyati-commerce', 'wp-content/themes/yawmiyati']) {
      fs.mkdirSync(path.join(s, d), { recursive: true });
      fs.writeFileSync(path.join(s, d, '.ym-release'), 'old\n');
    }
    fs.mkdirSync(path.join(s, 'wp-content/uploads'), { recursive: true });
    const f = (n, v) => fs.writeFileSync(path.join(s, '.fake', n), v);
    f('home', `https://staging.example.shop/${cc}/`);
    f('env', 'staging');
    if (active) f('active', '');
    f('health.json', JSON.stringify({ ok: false, country: cc.toUpperCase(), version: '1', jobs: { weekly_report: { stale: true } } }));
    f('health_code', '503');
    f('launch.json', JSON.stringify({ country: cc.toUpperCase(), environment: 'staging', open: 2, rows: [{ area: 'status sync', ok: 'NO', detail: 'confirm' }, { area: 'environment', ok: 'NO', detail: 'WP_ENVIRONMENT_TYPE=staging' }] }));
    f('cron_age', '120');
    f('gate_staging', JSON.stringify({ env: 'staging', enforced: true, accepts_orders: false, leaks: [], open_test_orders: [] }));
  }
  return { home, root, store: (cc) => path.join(root, cc), fake: (cc, n, v) => fs.writeFileSync(path.join(root, cc, '.fake', n), v) };
}

function run(h, args, extra = {}) {
  const summary = path.join(h.home, 'summary.md');
  const r = spawnSync('bash', [path.join(REPO, 'deploy/ci-staging.sh'), ...args], {
    encoding: 'utf8',
    env: {
      ...process.env, PATH: `${BIN}:${process.env.PATH}`, YM_SSH: path.join(BIN, 'ssh'), FAKE_HOME: h.home, FAKE_BIN: BIN,
      FAKE_ROOT: h.root, FAKE_AUTH: AUTH, HOST: 'host', USER_: 'u', ROOT: h.root, HTTP_AUTH: AUTH,
      BASE_URL: 'https://staging.example.shop', STORES: 'eg iq', SHA, SUMMARY: summary, GITHUB_STEP_SUMMARY: '', ...extra,
    },
  });
  return { code: r.status, out: r.stdout + r.stderr, summary: fs.existsSync(summary) ? fs.readFileSync(summary, 'utf8') : '' };
}
const live = (h, cc, comp = 'plugins/yawmiyati-commerce') => fs.readFileSync(path.join(h.store(cc), 'wp-content', comp, '.ym-release'), 'utf8').trim();
const state = (h, cc) => fs.readFileSync(path.join(h.home, 'ym-deploy/state', cc), 'utf8');
const backups = (h, cc) => { const d = path.join(h.home, 'ym-deploy/backups', cc); return fs.existsSync(d) ? fs.readdirSync(d) : []; };

test('Release: both stores get the new plugin and theme, the old copies leave the web root, open launch items are reported, not failures', () => {
  const h = host();
  const r = run(h, ['release']);
  assert.equal(r.code, 0, r.out);
  for (const cc of ['eg', 'iq']) {
    assert.equal(live(h, cc), SHA);
    assert.equal(live(h, cc, 'themes/yawmiyati'), SHA);
    assert.match(state(h, cc), /phase=done/);
    assert.ok(fs.existsSync(path.join(h.home, 'ym-deploy/previous', cc, 'yawmiyati-commerce/.ym-release')), 'previous copy kept outside the web root');
    assert.ok(!fs.existsSync(path.join(h.store(cc), 'wp-content/plugins/.ym-previous-yawmiyati-commerce')));
    assert.ok(!fs.existsSync(path.join(h.store(cc), '.maintenance')), 'maintenance ended');
    assert.ok(backups(h, cc).some((f) => f.endsWith('.sql.gz')) && backups(h, cc).some((f) => f.endsWith('.consent.json')), 'backup with the consent ledger before the swap');
    assert.ok(fs.existsSync(path.join(h.store(cc), 'wp-content/plugins/yawmiyati-commerce/yawmiyati-commerce.php')), 'real plugin files deployed');
  }
  assert.match(r.summary, /eg launch-check: 2 open item\(s\)/);
  assert.match(r.summary, /health 503 \(expected/);
});

for (const [name, setup, why] of [
  ['production environment', (h) => h.fake('iq', 'env', 'production'), /production: refusing/],
  ['a local environment', (h) => h.fake('iq', 'env', 'local'), /expected one of/],
  ['a WordPress URL that is not the expected staging store', (h) => h.fake('iq', 'home', 'https://yawmiyati.shop/iq/'), /wrong destination/],
  ['a store that answers without the password', (h) => h.fake('iq', 'unprotected', ''), /not protected/],
]) {
  test(`Refused before any store changes: ${name} (the other store is not touched either)`, () => {
    const h = host();
    setup(h);
    const r = run(h, ['release']);
    assert.equal(r.code, 1, r.out);
    assert.match(r.out, why);
    for (const cc of ['eg', 'iq']) {
      assert.equal(live(h, cc), 'old');
      assert.deepEqual(backups(h, cc), []);
    }
  });
}

test('Refused: a base URL or document root that is not staging', () => {
  const h = host();
  assert.match(run(h, ['release'], { BASE_URL: 'https://yawmiyati.shop' }).out, /refusing anything that could be production/);
  assert.match(run(h, ['release'], { ROOT: path.join(h.home, 'www/yawmiyati.shop/public_html') }).out, /STAGING_ROOT/);
  assert.equal(live(h, 'eg'), 'old');
});

test('WordPress fails to load with the new release: rolled back on the host, the run fails, the next store is not touched', () => {
  const h = host();
  h.fake('eg', 'break', SHA);
  const r = run(h, ['release']);
  assert.equal(r.code, 1, r.out);
  assert.match(r.out, /failed to load/);
  assert.equal(live(h, 'eg'), 'old');
  assert.equal(live(h, 'eg', 'themes/yawmiyati'), 'old');
  assert.match(state(h, 'eg'), /phase=rolled_back/);
  assert.equal(live(h, 'iq'), 'old');
});

test('Health errors are deployment failures (rolled back), not hidden', () => {
  for (const [file, value, msg] of [['health_code', '500', /health endpoint answered 500/], ['health.json', 'Fatal error', /health endpoint answered/], ['no_noindex', '', /no noindex/], ['launch_broken', '', /launch-check gave no result|could not run/]]) {
    const h = host();
    h.fake('eg', file, value);
    const r = run(h, ['release']);
    assert.equal(r.code, 1, `${file}: ${r.out}`);
    assert.match(r.out, msg);
    assert.equal(live(h, 'eg'), 'old', file);
    assert.match(state(h, 'eg'), /phase=rolled_back/);
  }
});

test('First release on a store without the plugin active: deployed, activation reported as the next step', () => {
  const h = host({ active: false });
  fs.rmSync(path.join(h.store('eg'), 'wp-content/plugins/yawmiyati-commerce'), { recursive: true });
  const r = run(h, ['release']);
  assert.equal(r.code, 0, r.out);
  assert.equal(live(h, 'eg'), SHA);
  assert.match(r.summary, /plugin not active yet/);
  assert.ok(!backups(h, 'eg').some((f) => f.endsWith('.consent.json')), 'no ledger to export before activation');
});

test('Interrupted deployment: a swap stopped half-way or an unverified release is rolled back by the next run', () => {
  const h = host();
  const first = run(h, ['release']);
  assert.equal(first.code, 0, first.out);
  const s = h.store('eg');
  const plug = path.join(s, 'wp-content/plugins');
  // Simulate a runner that died mid-swap: live plugin renamed to .ym-previous, new copy not yet moved in.
  fs.renameSync(path.join(plug, 'yawmiyati-commerce'), path.join(plug, '.ym-previous-yawmiyati-commerce'));
  fs.mkdirSync(path.join(plug, '.ym-incoming-yawmiyati-commerce'));
  fs.writeFileSync(path.join(s, '.maintenance'), '<?php $upgrading = 1; // ym-deploy ?>');
  fs.writeFileSync(path.join(h.home, 'ym-deploy/state/eg'), `phase=swapping\nnew=${'b'.repeat(40)}\nprev=${SHA}\n`);
  const host_ = (...a) => execFileSync('bash', [path.join(h.home, `ym-deploy/releases/${SHA}/deploy/staging-host.sh`), ...a], { encoding: 'utf8', env: { ...process.env, HOME: h.home, PATH: `${BIN}:${process.env.PATH}` }, cwd: h.home });
  host_('recover', 'eg', s);
  assert.equal(live(h, 'eg'), SHA, 'the last good release is back');
  assert.ok(!fs.existsSync(path.join(s, '.maintenance')));
  assert.ok(!fs.existsSync(path.join(plug, '.ym-incoming-yawmiyati-commerce')));
  assert.match(state(h, 'eg'), /phase=rolled_back/);
  // A verified-but-not-committed release (runner died during the HTTP checks) is also rolled back.
  fs.writeFileSync(path.join(h.home, 'ym-deploy/state/eg'), `phase=verifying\nnew=${SHA}\nprev=old\n`);
  fs.renameSync(path.join(plug, 'yawmiyati-commerce'), path.join(plug, '.ym-previous-yawmiyati-commerce'));
  fs.cpSync(path.join(plug, '.ym-previous-yawmiyati-commerce'), path.join(plug, 'yawmiyati-commerce'), { recursive: true });
  fs.writeFileSync(path.join(plug, '.ym-previous-yawmiyati-commerce/.ym-release'), 'old\n');
  host_('recover', 'eg', s);
  assert.equal(live(h, 'eg'), 'old');
  // Explicit rollback is refused once nothing is unverified.
  assert.throws(() => host_('rollback', 'eg', s), /nothing unverified/);
});

test('Order-test window: development only with the cron running and the password holding; back to staging with the gate enforced', () => {
  const h = host();
  assert.equal(run(h, ['release']).code, 0);
  h.fake('eg', 'cron_age', '-1');
  let r = run(h, ['mode', 'eg', 'development']);
  assert.equal(r.code, 1);
  assert.match(r.out, /install the server cron/);
  assert.equal(fs.readFileSync(path.join(h.store('eg'), '.fake/env'), 'utf8').trim(), 'staging');

  h.fake('eg', 'cron_age', '200');
  r = run(h, ['mode', 'eg', 'development']);
  assert.equal(r.code, 0, r.out);
  assert.match(r.summary, /eg: development \(order tests\)/);

  h.fake('eg', 'gate_staging', JSON.stringify({ env: 'staging', enforced: true, accepts_orders: false, leaks: [], open_test_orders: [41, 42] }));
  r = run(h, ['mode', 'eg', 'staging']);
  assert.equal(r.code, 0, r.out);
  assert.match(r.summary, /staging restored: catalog gate enforced/);
  assert.match(r.summary, /without an outcome: 41, 42/);

  h.fake('eg', 'gate_staging', JSON.stringify({ env: 'staging', enforced: true, accepts_orders: false, leaks: ['EG1'], open_test_orders: [] }));
  assert.equal(run(h, ['mode', 'eg', 'staging']).code, 1, 'an unverified product still purchasable fails the switch');
});

test('Order-test window: protection lost after switching to development switches the store straight back to staging', () => {
  const h = host();
  assert.equal(run(h, ['release']).code, 0);
  h.fake('eg', 'unprotected_in_dev', '');
  const r = run(h, ['mode', 'eg', 'development']);
  assert.equal(r.code, 1);
  assert.match(r.out, /switched back to staging/);
  assert.equal(fs.readFileSync(path.join(h.store('eg'), '.fake/env'), 'utf8').trim(), 'staging');
});

test('Order-test window: an environment that cannot be changed (overridden elsewhere) is reported and reverted', () => {
  const h = host();
  assert.equal(run(h, ['release']).code, 0);
  h.fake('eg', 'env_locked', '');
  const r = run(h, ['mode', 'eg', 'development']);
  assert.equal(r.code, 1);
  assert.match(r.out, /overrides it/);
});

test('Release commit: only a main commit with green tests on main', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ym-repo-'));
  const g = (...a) => execFileSync('git', ['-C', dir, ...a], { encoding: 'utf8', env: { ...process.env, GIT_AUTHOR_NAME: 't', GIT_AUTHOR_EMAIL: 't@t', GIT_COMMITTER_NAME: 't', GIT_COMMITTER_EMAIL: 't@t' } }).trim();
  g('init', '-q', '-b', 'main');
  fs.mkdirSync(path.join(dir, 'deploy'));
  fs.copyFileSync(path.join(REPO, 'deploy/ci-staging.sh'), path.join(dir, 'deploy/ci-staging.sh'));
  g('add', '.'); g('commit', '-qm', 'one');
  const onMain = g('rev-parse', 'HEAD');
  g('checkout', '-qb', 'side'); fs.writeFileSync(path.join(dir, 'x'), 'x'); g('add', '.'); g('commit', '-qm', 'side');
  const offMain = g('rev-parse', 'HEAD');
  g('checkout', '-q', 'main'); g('remote', 'add', 'origin', dir);
  const check = (sha, green) => spawnSync('bash', [path.join(dir, 'deploy/ci-staging.sh'), 'check-commit'], { encoding: 'utf8', env: { ...process.env, PATH: `${BIN}:${process.env.PATH}`, SHA: sha, GITHUB_REPOSITORY: 'o/r', FAKE_GREEN_RUNS: String(green), SUMMARY: '/dev/null' } });
  assert.equal(check(onMain, 1).status, 0);
  assert.match(check(onMain, 0).stderr, /no successful 'tests' run on main/);
  assert.match(check(offMain, 1).stderr, /not on main/);
});

test('Five-market release validates every destination and deploys each independent store', () => {
  const stores = ['eg', 'iq', 'sa', 'ae', 'om'];
  const h = host({ stores });
  const r = run(h, ['release'], { STORES: stores.join(' ') });
  assert.equal(r.code, 0, r.out);
  for (const cc of stores) {
    assert.equal(live(h, cc), SHA);
    assert.equal(live(h, cc, 'themes/yawmiyati'), SHA);
    assert.match(state(h, cc), /phase=done/);
  }
});

test('A wrong fifth destination blocks changes to all five stores', () => {
  const stores = ['eg', 'iq', 'sa', 'ae', 'om'];
  const h = host({ stores });
  h.fake('om', 'home', 'https://staging.example.shop/ae/');
  const r = run(h, ['release'], { STORES: stores.join(' ') });
  assert.equal(r.code, 1, r.out);
  for (const cc of stores) {
    assert.equal(live(h, cc), 'old');
    assert.deepEqual(backups(h, cc), []);
  }
});

test('Duplicate and whitespace-only store lists are refused before deployment', () => {
  for (const stores of ['eg eg', '   ']) {
    const h = host();
    const r = run(h, ['release'], { STORES: stores });
    assert.equal(r.code, 1, r.out);
    assert.equal(live(h, 'eg'), 'old');
  }
});
