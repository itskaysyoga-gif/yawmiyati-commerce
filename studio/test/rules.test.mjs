// Script rules: the model writes words only; numbers, banned claims, unknown specs and unsourced lines are refused.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { check } from '../lib/rules.mjs';

const facts = JSON.parse(fs.readFileSync(new URL('../fixtures/facts-iq-IQ030403SENK99.json', import.meta.url)));
const good = JSON.parse(fs.readFileSync(new URL('./fixtures/brief-iq.json', import.meta.url)));
const clone = () => JSON.parse(JSON.stringify(good));

test('The hand-written test brief passes', () => assert.deepEqual(check(good, facts), []));
test('Numbers in Latin or Arabic-Indic digits are refused', () => {
  const b = clone(); b.angles[0].hooks[0] = 'بس ٤٣ ألف'; b.angles[1].beats[0].line = 'عرض 60 سم';
  assert.equal(check(b, facts).filter((e) => /number/.test(e)).length, 2);
});
test('Banned claims, an unknown spec and an unsourced line are refused', () => {
  const b = clone(); b.angles[0].caption = 'أفضل رف ومضمون'; b.angles[2].beats[0].line = 'الخامة ممتازة'; b.angles[2].beats[1].source = 'reviews';
  const e = check(b, facts).join('\n');
  assert.match(e, /أفضل/); assert.match(e, /مضمون/); assert.match(e, /unknown/); assert.match(e, /not a fact key/);
});
test('All three angles with two hooks each are required', () => {
  const b = clone(); b.angles.pop(); b.angles[0].hooks.pop();
  const e = check(b, facts).join('\n');
  assert.match(e, /missing angle offer/); assert.match(e, /two hooks/);
});
