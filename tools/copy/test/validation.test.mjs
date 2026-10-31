import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

function validate(drafts, country = 'om') {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ym-copy-'));
  const tool = path.join(root, 'tools/copy');
  fs.mkdirSync(path.join(tool, `drafts/${country}`), {recursive:true});
  fs.mkdirSync(path.join(tool, 'facts'), {recursive:true});
  for (const f of ['validate.mjs','markets.mjs']) fs.copyFileSync(new URL(`../${f}`, import.meta.url), path.join(tool,f));
  fs.writeFileSync(path.join(tool, `facts/${country}.json`), JSON.stringify({products:[{sku:'TESTONLY',offers:[{sku:'TESTONLY'}]}]}));
  for (const [sku, data] of Object.entries(drafts)) fs.writeFileSync(path.join(tool,`drafts/${country}/${sku}.json`),JSON.stringify(data));
  try { return spawnSync(process.execPath,[path.join(tool,'validate.mjs'),country],{encoding:'utf8'}); }
  finally { fs.rmSync(root,{recursive:true,force:true}); }
}
const valid = {name:'رف للمطبخ', benefit:'لترتيب أغراض المطبخ.', diary:'اختاري المكان المناسب في مطبخك.',fit:null,offer_labels:{TESTONLY:'قطعة واحدة'},used_facts:['رف للمطبخ']};

test('A complete neutral Arabic draft passes structural checks only',()=>assert.equal(validate({TESTONLY:valid}).status,0));
test('Empty batch, unknown SKU and missing content fail rather than report success',()=>{
  for(const drafts of [{},{UNKNOWN:valid},{TESTONLY:{}}]) assert.equal(validate(drafts).status,1);
});
test('Country dialect leakage and unsupported offer claims fail',()=>{
  assert.equal(validate({TESTONLY:{...valid,diary:'عايزة رف؟'}}).status,1);
  assert.equal(validate({TESTONLY:{...valid,offer_labels:{TESTONLY:'ضمان'}}}).status,1);
  assert.equal(validate({TESTONLY:{...valid,offer_labels:{OTHER:'قطعة'}}}).status,1);
});
