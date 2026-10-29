# Photography still needed (29 Oct 2026)

What the finished design is waiting for. **Staging does not wait for any of it**: the stores run with the interim
photos, and the catalog gate keeps every product unpublishable on staging and production until its own photos are
recorded (`wp ym gate <sku>`). Rules for every file: docs/MEDIA-STANDARDS.md. Nothing generated is ever presented as
the product, its size, its result or its use.

## 1. Home page photograph, one per country (owner action O19)
The home page shows one focal image beside the headline (desktop) or under it (phone). Today it shows the lead
product's interim photo (Egypt: a close-up of the mats; Iraq: the sink rack), named, as a temporary stand-in.

| | Egypt | Iraq |
| --- | --- | --- |
| Subject | The **actual SKU being sold** (2-piece grey mat set, EG030106MKKT99) in place in front of a kitchen sink or at a bathroom doorway, in a lived-in Egyptian apartment (tiled floor, ordinary fittings) | The **actual over-sink rack** (IQ030403SENK99) installed over a kitchen sink with everyday dishes on it, in a lived-in Iraqi kitchen |
| If the exact SKU cannot be photographed | A licensed photo of a tidy, believable Egyptian home corner **with no product in it**; the caption then names nothing and nothing in the picture may suggest it shows our product | A licensed photo of a tidy, believable Iraqi kitchen **with no product in it**, same rule |
| Must not | Show a different mat, a staged result we have not verified (e.g. "dry floor" effects), people without a model release, brand logos, text | Show a different rack, claims about weight or fit, people without a model release, brand logos, text |
| Format | Landscape 4:3 (shown 4:3 on desktop; phones show it whole, height-capped). At least 2000 × 1500 px, sharp, true colour | Same |
| Licence | Commercial use on the website and in organic social posts, no expiry (or an expiry recorded), editing right "crop" at least; licence text kept | Same |
| How it goes live | `wp ym media <id> --channels=site --approved=<date> --rights=…` then option `ym_hero_image`; the focal product image steps aside automatically | Same |

## 2. Product photographs to replace (owner action O6, from Taager with written permission)
Every interim photo below is a 270-600 px listing thumbnail: all fail the 1000 px minimum and none has written rights,
so **all need replacing**. The cards never stretch or crop them to look better; the "fill" column says how much of
the 4:5 card frame the whole photo covers, and "weak" cards (under 70 %) look small next to others.

| Country | Product (SKU) | Interim file | Size | Card fill | What to ask Taager for |
| --- | --- | --- | --- | --- | --- |
| EG | Mat set (EG030106MKKT99), also the 2-set offer | eg-mats-1, eg-mats-2 | 300×286, 310×168 | 76 % | Clean whole set on white (both pieces, one frame), texture close-up, a scale view with dimensions; sizes in writing |
| EG | Clothes stand (EG030106STPG99) | eg-rack-1 | 430×430 | 80 % | Clean whole stand on white, wheel and rail detail, dimensions |
| EG | Wearable blanket (EG030401GB99) | eg-blanket-1 | 600×382 | **51 % weak** | Clean whole blanket laid flat or on a stand, fabric close-up, size |
| EG | Heater (EG030301HAEE99) | eg-heater-1 | 490×485 | 79 % | Held (conditional): electrical spec and warranty first, then clean, controls, scale |
| IQ | Sink rack (IQ030403SENK99), also the rack + mats offer | iq-sink-rack-1, -2 | 440×418, 300×270 | 76 % | Clean whole rack on white, joint/tier detail, installed over a sink with the width marked; **width range in writing** |
| IQ | Mat set (IQ030106MATT99) | iq-mats-1 | 470×405 | **69 % weak** | Clean whole set on white, texture, dimensions |
| IQ | Clothes stand (IQ030104STAN99) | iq-rack-1 | 600×455 | **61 % weak** | Clean whole stand on white, detail, dimensions |
| IQ | Corner organizer (IQ030106MONZ99) | iq-organizer-1 | 520×268 | **41 % weak** | Clean whole organizer on white (a square or portrait frame), shelf detail, height |
| IQ | Window cleaner (IQ030107MASA99) | iq-window-cleaner-1 | 450×320 | **57 % weak** | Clean both halves on white, handle detail, the glass thickness it fits |
| IQ | Heater (IQ030301SOBA99) | iq-heater-1 | 520×440 | **68 % weak** | Held (conditional): electrical spec and warranty first |

Best format for cards: a cut-out on a plain light background (the card trims it to the same optical scale as every
other product automatically) or a 4:5 / square photo of at least 1000 px on the short side.

## 3. Not needed for launch
Lifestyle and demonstration video (video phase is scheduled last), model photography, packaging shots.
