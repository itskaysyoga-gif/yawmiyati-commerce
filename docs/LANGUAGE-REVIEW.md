# Language review

_29 Oct 2026 (language round). Replaces the hand-kept tables of 28 Oct: the strings themselves live in the copy
ledger, generated from the files, so this document cannot drift from the store._

## Status words (never merged)
| Status | Means | Set by |
| --- | --- | --- |
| AI-drafted / AI-revised | written by Claude (28 Oct draft; 29 Oct revision with a reason in the ledger) | Claude |
| AI-critiqued | an independent model pass (Claude Opus 5.5 through the API, separate context, local evidence and voice guide) judged naturalness, clarity, tone, factual risk and functional meaning; Claude resolved every "revise" and "question" in the critique file | tools/language/critique.mjs |
| **native-reviewed** | a native speaker of that country read it on the phone screens and approved it | a person only; never a tool |

Key completeness and AI critique are **not** native review. `wp ym launch-check` stays red until
`ym_language_reviewed_<CC>` is set after that country's native review (checklist P0-15).

## Where everything is
| What | File |
| --- | --- |
| Every customer string per country (copy, policy pages, categories, product texts): key, layer, surface, text at e10abdf, new text, reason, facts it depends on, AI critique, status | docs/language/ledger-eg.csv … ledger-om.csv; summary docs/language/LEDGER.md |
| Strings WooCommerce or WordPress print on our pages (rendered-page inventory, owner per string) | docs/language/rendered-<cc>.csv |
| Local references (3-5 retailers per open market, fewer per closed one; dates, URLs, short examples, what could not be inspected) | docs/language/evidence/EG.md, IQ.md, SA.md, AE.md, OM.md |
| House rules per country (three layers, spelling, gender, glossary) | docs/voice/EG.md, IQ.md, GULF.md, SA.md, AE.md, OM.md |
| AI critique results with resolutions | tools/language/critique/<cc>-<batch>.json |
| **Native review packages** (compact, prioritised) | docs/language/REVIEW-PACKAGE-EG.md, REVIEW-PACKAGE-IQ.md |

Regenerate after any copy change: `node tools/language/ledger.mjs && node tools/language/crawl.mjs && node tools/language/package.mjs`
(crawl needs the local stores running).

## How the native review works
1. One reviewer per country, ideally a woman in the 25-44 audience (owner action O8). Each Gulf country needs its own
   reviewer before it opens (docs/voice/SA.md, AE.md, OM.md have the checklists).
2. They read the package in order (checkout and money first) **on the phone screens** of the review PDF, not in JSON,
   and write OK or their wording.
3. Claude applies the changes, reruns the tests, regenerates the PDF from a clean commit; the reviewer checks the
   changed lines once more.
4. Facts are approved separately by Rashid (prices, sizes, policy terms): a reviewer approves wording only.

## Process for new or changed strings
Research first (evidence files), write in the right layer, run a **small** critique batch (priority order:
checkout/errors/policies → navigation/home → products → emails → closed markets), resolve, then apply the pattern.
Do not repeatedly paraphrase text that is fine. API calls go through the shared budget (docs/API-BUDGET.md).
