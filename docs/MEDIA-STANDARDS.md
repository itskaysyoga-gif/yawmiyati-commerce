# Media standards

Connecting the Taager plugin does not guarantee clean, high-resolution images. Every SKU is checked asset by asset in
`research/data/media_checklist.csv` before it can be published. The catalog gate in the plugin enforces the same
rules (`wp ym gate <sku>` lists what is missing).

## What each SKU needs before publication (the gate checks coverage, not a count)
Reconciled 28 Oct 2026: the code required 3 images and the checklist said 5 assets. Neither number was the point.
The gate now requires **at least 3 accepted images that together cover three views**; more images are welcome only
when they add information.

| View (`role`) | Requirement | Blocks publication |
| --- | --- | --- |
| clean | Complete product, plain light background, no text or badges. Must be the main image | yes |
| detail | Material, texture, controls or print, close up | yes |
| scale | Real size is clear: in use, next to a known object, or with dimensions marked | yes |
| demo | Genuine demonstration of what it does (photo or real, unedited video of this exact item) | no, advised (`wp ym gate` shows it) |
| contents | Everything in the box laid out; bundles: every item together | no, advised for bundles |
| lifestyle | In a real room, accurate | no |

Every image: at least 1000 px on the short side (1500 preferred), sharp at 100 %, colours true to the product.
Video: poster image required; the file loads only when she presses play; a generated video is never a
demonstration and never shows performance that was not verified.

## Record for every file
Recorded on the attachment with `wp ym media <id> --role=… --sku=… --variant=… --source=… --rights=… --original=…
[--exact] [--complete] [--final]`: source (where it came from, with date), rights (who said we may use it, and whether
in writing), where the untouched original is kept, the exact supplier SKU and variant, and an identity check: does
this file show exactly this SKU (same print, colour, parts, count)? A picture recorded against another SKU is refused. Egypt and Iraq items that look
alike are checked separately; no launch product is verified identical across the two countries.

## Not allowed as final product images
- Enlarged or cropped advertising graphics, including the current interim crops in `media/interim/`.
- Images with price stickers, "order now" badges, other stores' watermarks or burned-in text.
- AI-generated or edited images that change the product's appearance, parts or size (`enhanced`).
- Screenshots (`screenshot`) and destructive crops.
- Pictures of a similar-looking product found on the web: never substituted for the item sold.
- Photos of real third-party brands not yet verified as authentic.

Lifestyle photos with people are fine as secondary images when they show the product accurately. They are not used
as the main image. Higgsfield or other generators are optional and not a launch requirement: good source footage comes first, and
editing, captions and localised narration make strong ads from it. Generators are only for clearly separate creative,
never as product images or as proof of performance.

## Status today (28 Oct 2026)
All 12 launch offers fail the media gate: the only images are 168-600 px crops of Taager listing thumbnails, and most
show part of the product. Originals have been requested (docs/requests/taager-round-3.md).
