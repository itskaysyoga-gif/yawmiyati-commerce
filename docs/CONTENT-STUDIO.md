# Content Studio

_29 Oct 2026. Code: `studio/` (separate Node project) and `YM_Studio` in the plugin. Status words as in PROJECT-STATE.md._

The studio turns one approved product into localized videos, a carousel, a story, captions and publishing records.
It never runs on the WooCommerce host and never on a laptop: renders run in the build container or in CI.

## Flow
1. **Facts.** `wp ym studio-facts <sku>` (CLI) or `GET /wp-json/ym/v1/studio-facts?sku=` with `X-YM-Studio-Key`
   returns what the store itself shows: name, every offer with its price, delivery range and the one-fee rule, the
   benefit / fit / diary lines, specs (unknown ones listed as unknown), media with rights (channels, approval,
   expiry), stock state and age, catalog-gate blockers, market gates, landing URL, and an **offer_version** hash.
2. **Brief.** `studio/brief.mjs` asks the Anthropic API for 3 angles (problem, fit, offer) x 2 hooks, 2-3 beats each
   with the fact key they restate, a CTA, caption and thumbnail line, and a 3-4 slide carousel. Runs in CI on a pushed
   `studio-run/<name>` branch (the key is a repository secret). Spend is capped: studio/spend.json, priced at rates
   set above list price, total cap 40 USD, 3 USD per run.
3. **Rules.** `studio/lib/rules.mjs`: no digits at all in the words layer (prices, fees and quantities come from the
   facts inside the template), no banned claims (superlatives, guarantees, medical/germ, scarcity, deadlines,
   discounts, free delivery, testimonials, "imported", "trend"), no unknown spec, every beat sourced, length limits.
   A failing brief is retried once with the errors, then marked `rejected_by_rules`.
4. **Render.** `node studio/render.mjs <facts> <brief>`: template `photo-motion@1` (Remotion 4.0.539, React 19):
   - 9:16 masters 1080x1920, 30 fps, H.264, muted, 12.5-15 s: hook (2.5 s), beats (2.5 s each), offer card (5 s).
   - one 4:5 derivative (1080x1350) per angle, **re-composed** for the feed, not cropped from the master;
   - a 4-slide 4:5 carousel (last slide = offer card) and a 9:16 story (offer card);
   - text kept out of the top 220 px and bottom 380 px; Arabic set right-to-left with `lang="ar"`, line-level motion
     only (Remotion's arabic-text guidance); brand font Readex Pro (OFL), brand logo, ivory/berry/blush/sage.
   - A visible tag "مسودة داخلية · صور مؤقتة غير معتمدة" while any photo lacks organic rights.
   Photo-based pieces are labelled `photo_based: true, demonstration: false` and are never presented as a demonstration.
5. **QA.** `node studio/qa.mjs`: files present and unchanged (sha256), H.264 at the declared size, 10-35 s, words layer
   clean, tagged link in the caption. A person still checks Arabic reading, exact-item photos and the offer card.
6. **Approval.** `node studio/approve.mjs --batch <id> --by Rashid --platform instagram --destination test|production
   --at <time> --every 24h <records>`: one decision for a batch and its schedule. Production refuses records with open
   blocks. File hashes are frozen at approval; re-rendering invalidates.
7. **Publish.** `node studio/publish.mjs` (cron in the cloud): pause file, token check, preflight right before posting
   (fresh facts: same offer_version, in stock, stock fresh on production, market open, media organic rights, catalog
   gate, landing page answering, files unchanged), then the platform adapter. A timeout after the platform may have
   published is reconciled first (the record ID travels in the caption's tagged link, `utm_content=<record id>`), so a
   retry never duplicates. Three failures: dead letter + alert (studio/state/alerts.log). Emergency stop:
   `node studio/publish.mjs --pause`.

## Content record (studio/records/<cc>/<id>.json)
id, kind (reel | carousel), country, sku, product, status (rendered, qa_passed, qa_failed, published, invalidated,
dead_letter), template_version, narration, music, photo_based, demonstration, source_evidence (facts offer version and
time, brief file, status and model), offer_version, media (ids, rights, organic/paid), claims (every on-screen line
with its fact key), caption, landing_url, tagged_url, campaign (utm), blocked (why it cannot go to production),
approval (batch, by, at), schedule, platform_ids, attempts, results, qa, files (role, path, size, seconds, sha256).
Renders themselves are git-ignored; records are committed (no customer data).

## Capability matrix (what is built vs proven)
| Platform | Account type | Format | API / permission | Automation here | Fallback | Tested |
| --- | --- | --- | --- | --- | --- | --- |
| Instagram | Professional (Business) linked to a Facebook Page | Reels (video_url container, status poll, media_publish) | Graph API, instagram_content_publish | adapter `studio/lib/meta.mjs`, reconciliation by tagged link | Meta Business Suite manual upload | **fake Graph server only** (studio/test). No real account connected |
| Instagram | same | Carousel, Story | Graph API supports carousels; stories via API only for Business accounts | not wired yet (renders exist) | Meta Business Suite | not tested |
| Facebook Page | Page | Video post (file_url) | Graph API, pages_manage_posts | adapter, reconciliation by description | Meta Business Suite | fake server only |
| Snapchat | Public Profile + Ads Manager | Story, Spotlight, ads | Marketing API for ads; organic publishing API access not assumed | none (assets prepared: 9:16 masters) | Snapchat app / Ads Manager by hand | not tested |
| TikTok | not in scope for launch | — | — | none | — | — |
| Comments / DMs | Instagram + Page | keyword replies | Meta messaging windows apply | **deferred** (see corrections) | Meta Business Suite inbox, a person | — |
| Email | Brevo | transactional + marketing | Brevo API | `YM_Mail` (docs/EMAIL.md) | wp_mail | local only |
The Graph API version is configuration (`YM_GRAPH_VERSION`, default v24.0) and must be checked against Meta's current
version on the day the account is connected.

## Corrections to the 29 Oct brief (and why)
- **Postiz: not used.** It needs its own server (Docker, Postgres, Redis) or a paid cloud plan, for two destinations
  (Instagram, Facebook) we reach directly through Meta's Graph API with less to run. Its account support would still
  have to be proven on a real account.
- **Manychat: deferred, not at launch.** Its paid tiers bill by contacts; comment-to-DM automation on 2.7M + 720K
  follows can create billable contacts quickly, and the website checkout is the only ordering path anyway. Launch with
  Meta Business Suite automated replies (free) plus a person; revisit with real comment volumes and a priced cap.
- **ElevenLabs narration: not for launch.** Sound-off captions carry the message; narration only after Egyptian and
  Iraqi auditions are approved by a native listener (owner action).
- **MoneyPrinterTurbo: not installed** (agreed with the brief): topic-to-video does not do exact-SKU facts, approval,
  reconciliation or delivered-profit reporting.
- **Higgsfield: not used for any product image or demonstration** (agreed; the repo rule is stricter).
- **Music:** none in the renders until a track licensed for paid use is chosen (owner action); IG accepts silent Reels.

## Licences
Remotion 4.0.539: free licence for individuals and companies with up to 3 employees (node_modules/remotion/LICENSE.md,
read 29 Oct 2026). Rashid as a sole operator qualifies; a company licence is needed if the business grows past 3.
Readex Pro: SIL OFL (theme/yawmiyati/assets/fonts/LICENSE-readex-pro.txt). Remotion's agent skills are fetched at a
pinned commit by `studio/skills-sync.sh` and not redistributed (their repository has no licence file).
