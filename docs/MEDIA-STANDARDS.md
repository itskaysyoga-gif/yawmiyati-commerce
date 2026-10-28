# Media standards

Connecting the Taager plugin does not guarantee clean, high-resolution images. Every SKU is checked asset by asset in
`research/data/media_checklist.csv` before it can be published. The catalog gate in the plugin enforces the same
rules (`wp ym gate <sku>` lists what is missing).

## What each SKU needs before publication
| Asset | Requirement | Blocks publication |
| --- | --- | --- |
| Main | Complete product, front view, plain light background, no text or badges | yes |
| Second angle | Complete product from another side | yes |
| Detail | Material, texture, controls or print, close up | yes |
| Scale | In use, or next to a known object, so the real size is clear | yes |
| Contents | Everything in the box laid out. Bundles: every item together | yes |
| Video | Supplier video, 720p or better, no burned-in ad text | no (requested) |

Every image: at least 1000 px on the short side (1500 px preferred), sharp at 100 %, colours true to the product.

## Record for every file
Source (where it came from, with date), rights (who said we may use it, and whether in writing), resolution, and an
identity check: does this file show exactly this SKU (same print, colour, parts, count)? Egypt and Iraq items that look
alike are checked separately; no launch product is verified identical across the two countries.

## Not allowed as final product images
- Enlarged or cropped advertising graphics, including the current interim crops in `media/interim/`.
- Images with price stickers, "order now" badges, other stores' watermarks or burned-in text.
- AI-generated or edited images that change the product's appearance, parts or size.
- Photos of real third-party brands not yet verified as authentic.

Lifestyle photos with people are fine as secondary images when they show the product accurately. They are not used
as the main image. Higgsfield or other generators are optional and only for clearly separate creative (ads, reels),
never as product images.

## Status today (28 Oct 2026)
All 12 launch offers fail the media gate: the only images are 168-600 px crops of Taager listing thumbnails, and most
show part of the product. Originals have been requested (docs/requests/taager-round-3.md).
