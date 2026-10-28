# Copy pipeline

Drafts product copy with the Anthropic API, then checks it. Nothing here publishes anything.

1. **Facts** `node tools/copy/facts.mjs eg` builds `facts/eg.json` from the product data. Only known facts go in;
   unknown specs are listed as unknown so the draft cannot use them.
2. **Draft** runs in GitHub Actions (`copy-drafts` workflow, manual) where `ANTHROPIC_API_KEY` is a secret.
   It writes `drafts/<cc>/<sku>.json` and pushes them to a branch `copy-drafts/<run>`. Model: `claude-sonnet-5-5`
   (override with `YM_COPY_MODEL`). Cost per product is a few thousand tokens.
3. **Automatic check** `node tools/copy/validate.mjs eg`: every number must exist in the facts; no banned words
   (supplier name, guarantees, medical claims, urgency); no words from the other country's dialect; length limits.
4. **Native review**: wording is approved by a native reviewer per country (docs/LANGUAGE-REVIEW.md).
5. **Fact approval**: Rashid confirms each fact the draft used (`used_facts`) against the Taager product page.
6. Approved copy is copied into the product data. A draft never reaches a live page directly.
