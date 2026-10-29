// Test helper: reserve one call in a separate process (concurrency tests). Prints "ok <id>" or "refused".
import { Ledger, FileStore, GitStore, BudgetError } from '../ledger.mjs';
const [kind, where, scope, inTok, outTok] = process.argv.slice(2);
const store = kind === 'file' ? new FileStore(where) : new GitStore({ cwd: where });
try {
  const e = await new Ledger(store).reserve({ scope, purpose: `worker ${process.pid}`, model: 'claude-sonnet-5-5', maxInputTokens: Number(inTok), maxOutputTokens: Number(outTok) });
  console.log(`ok ${e.id}`);
} catch (e) {
  if (e instanceof BudgetError) console.log('refused'); else { console.error(e.stack); process.exit(2); }
}
