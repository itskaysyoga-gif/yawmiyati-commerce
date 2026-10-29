---
name: publish-approved
description: CUSTOM (Yawmiyati). Use to schedule or publish studio content - approval scope, preflight, retry reconciliation, pause and dead letter.
---
# Publish approved (custom skill, Yawmiyati)

- Only Rashid approves, in batches: `node studio/approve.mjs --batch <id> --by Rashid --platform instagram
  --destination test|production --at <time> --every 24h <record-ids>`. Production refuses blocked records.
- `node studio/publish.mjs` posts what is due: pause file, token check, fresh facts (offer version, stock, market,
  media rights, landing page, file hashes) right before posting; a changed fact invalidates the item.
- A timeout after the platform may have published is reconciled (the record ID is in the caption's tagged link)
  before any retry. Three failures: dead letter plus an alert in studio/state/alerts.log.
- Emergency stop: `node studio/publish.mjs --pause` (resume with --resume).
- Outside any batch: paid promotion, new claims, changed offers, refunds and disputes need their own decision.
