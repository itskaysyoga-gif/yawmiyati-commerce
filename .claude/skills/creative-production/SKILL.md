---
name: creative-production
description: CUSTOM (Yawmiyati). Use to turn an approved product into rendered videos, a carousel and a story with the Content Studio (Remotion), in the cloud.
---
# Creative production (custom skill, Yawmiyati)

Pipeline: facts -> brief -> rules check -> render -> QA -> owner batch approval -> publish (docs/CONTENT-STUDIO.md).
1. `dev/wp.sh <cc> ym studio-facts <sku> > studio/fixtures/facts-<cc>-<sku>.json` (production: the REST endpoint).
2. Brief: push a branch `studio-run/<name>` with `studio/requests/current.txt` listing the facts files; the
   studio-scripts workflow drafts with the Anthropic API (spend capped in studio/spend.json) and commits the draft.
3. `node studio/render.mjs <facts> <brief>`: 3 angles x 2 hooks as 9:16 masters (1080x1920), one re-composed 4:5
   derivative per angle, a 4-slide 4:5 carousel and a 9:16 story. Template photo-motion@1 moves real photos only and
   is never a demonstration. Prices, offers, delivery and country come from the facts, never from the script.
4. Load the pinned Remotion skills first if editing templates: `studio/skills-sync.sh`.
Renders run here or in CI, never on the store host or a laptop. One product per country first; never mass-render an
unapproved catalog.
