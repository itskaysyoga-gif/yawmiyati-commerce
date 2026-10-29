# Anthropic API budget

Rashid authorised **up to 40 USD of Anthropic API use in total** for improving the project (29 Oct 2026). One
aggregate ceiling, prior calls included; a ceiling, not a target. No new paid subscriptions come with it.

## How it is enforced
- **One ledger for every paid generator**: `tools/spend/` (studio briefs, copy drafts, language critique). The only
  code path to the Messages API is `tools/spend/anthropic.mjs` → `paidCall()`.
- **Where it lives**: one JSON file on its own branch, `ledger/anthropic`, in the GitHub repository. Every change is a
  commit pushed with a compare-and-swap (`--force-with-lease` on the commit that was read). Two runs that read the
  same balance cannot both write; the loser re-reads and re-checks. Nothing is kept in a checkout, so a restart, a
  branch switch or a retry never sees an older balance. Paid workflows also queue one at a time (concurrency group
  `anthropic-api`).
- **Reserve before sending**: the most a call could cost = UTF-8 bytes of the whole prompt (a token always covers at
  least one byte) + 2,000 tokens framing as the input ceiling, plus `max_tokens` as the output ceiling, at the counted
  rate. Refused when it would take the total past 40 USD or its envelope past its limit.
- **Settle after**: with the usage the API returned. A call whose outcome is unknown (timeout, broken connection,
  unreadable answer) or failed keeps its full reservation: never treated as free. Retries are new calls with new
  reservations. Only a person who checked the Anthropic console may lower an entry:
  `node tools/spend/cli.mjs reconcile <id> <input_tokens> <output_tokens> "<what was checked>"`.
- **Rates**: `tools/spend/rates.json`, Anthropic list prices (source and date in the file) counted at 1.5×. Models
  not listed are refused. Rates and models are never read from the environment.
- **Keys** never appear in ledger notes or logs (`sk-ant-…` is masked).

## Envelopes (inside the 40 USD)
| Envelope | Limit (USD) | For |
| --- | --- | --- |
| eg | 18 | Egypt copy, critique, studio |
| iq | 12 | Iraq |
| gulf | 5 | Saudi Arabia, UAE, Oman foundations |
| reserve | 5 | usage uncertainty, unrecorded earlier calls |

## Commands
- `node tools/spend/cli.mjs status`: held, remaining, open reservations (also printed in every paid workflow's summary).
- `node tools/spend/cli.mjs init`: start the ledger (done 29 Oct with the earlier calls from `tools/spend/prior.json`).

## Earlier calls (before 29 Oct)
14 calls with recorded usage (4 studio briefs, 10 copy drafts) are in the ledger at the counted rate. Failed runs
whose calls may have been billed without recorded usage are held as one 1 USD "unknown" entry in the reserve until
Rashid compares with the Anthropic console (studio-scripts 40832602690, 40832871836, 40833309790, 40833646057;
copy-drafts 40742859157, 40743408209). The console is the source of truth for what was charged; the ledger is a
deliberately high upper estimate.

## Where calls may run
GitHub Actions runs paid generation only as a development step (drafting copy, critiquing strings, drafting video
briefs), on demand and queued. Rendering, scheduling, publishing and customer email never run in Actions
(`studio/render.mjs` and `studio/publish.mjs` refuse to); they belong to a separate cloud worker when that phase
begins.
