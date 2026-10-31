import { test } from 'node:test';
import assert from 'node:assert/strict';
import { market, otherDialect } from '../markets.mjs';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

test('Each market has a currency and the Gulf shares its existing budget envelope', () => {
  assert.equal(market('eg').scope, 'eg');
  assert.equal(market('iq').scope, 'iq');
  for (const cc of ['sa','ae','om']) {
    assert.equal(market(cc).scope, 'gulf');
    assert.ok(otherDialect(cc).includes('عايزة'));
    assert.ok(otherDialect(cc).includes('تكدرين'));
  }
  assert.throws(() => market('../secrets'));
});

test('All five fact sheets build without an API call; Gulf shared policies are included', () => {
  for (const cc of ['eg','iq','sa','ae','om']) {
    const file = new URL(`../facts/${cc}.json`, import.meta.url);
    const before = fs.existsSync(file) ? fs.readFileSync(file) : null;
    try {
      execFileSync(process.execPath, ['tools/copy/facts.mjs', cc]);
      const data = JSON.parse(fs.readFileSync(file));
      assert.equal(data.country, cc.toUpperCase());
      assert.equal(data.currency, market(cc).currency);
      assert.ok(data.policy_lines.payment);
      assert.ok(data.products.length);
      assert.match(data.status, /NOT supplier-verified/);
    } finally {
      if (before) fs.writeFileSync(file, before); else fs.rmSync(file, { force: true });
    }
  }
});
