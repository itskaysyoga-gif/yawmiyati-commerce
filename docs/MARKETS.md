# Markets: five countries, four gates

_28 Oct 2026. Config: `plugin/yawmiyati-commerce/config/countries.php` (rules) and `config/markets.json` (gates).
Code: `class-ym-markets.php`. Every store runs the same plugin and theme from this repo and the same release._

## Per-country configuration (countries.php)
Currency and minor units (EGP 0, IQD 0, SAR 2, AED 2, OMR 3), price step (1, 250, 1, 1, 0.100), phone rules
(national format, trunk prefix, dialling code; Arabic-Indic and Persian digits, +CC/00CC/CC and a missing trunk 0
are normalised before validation and duplicate checks), regions and delivery fees, delivery windows where
published, refusal and return fees (null = not published), return window, Saudi VAT on uplift, voice family (eg,
iq, gulf) and hreflang. Display trims only all-zero decimals: 3.000 OMR shows as 3, 2.500 stays.

## The four gates (markets.json)
| Gate | Meaning | Effect when off (staging and production) |
| --- | --- | --- |
| prepared | configuration, copy, policy pages, tracking config ready | — (a store must not be built without it) |
| catalog | assortment approved, at least one offer passes the catalog gate | noindex everywhere, robots.txt disallows, no sitemap |
| checkout | supplier link verified and a test order placed **in this country** | no buy buttons; checkout refuses on the server with our message; hidden from the switcher, hreflang and the root chooser |
| ads | live measurement checks passed in this country | ad spend reported as 0; recorded spend is flagged |

Later gates never stand without the earlier ones. Gates change only by a commit to markets.json (author, date and
reason in git). A store can switch a gate **off** for itself at once: `wp ym market off checkout`; `wp ym market
clear` returns to the file. `wp ym market` prints the matrix.

## Readiness, 28 Oct 2026
| | Egypt | Iraq | Saudi Arabia | UAE | Oman |
| --- | --- | --- | --- | --- | --- |
| prepared | on | on | on | on | on |
| catalog | off | off | off | off | off |
| checkout | off | off | off | off | off |
| ads | off | off | off | off | off |
| Local store, tested | :8081 | :8082 | :8083 | :8084 | :8085 |
| Copy | Egyptian draft | Iraqi draft | Gulf draft | Gulf draft | Gulf draft |
| Native review | no | no | no | no | no |
| Delivery fee | 95/100/105 | 7,000 | 28 | 19 | 3.000 |
| Refusal fee | 5 (policy) vs 0 (support said) | 0 (support said) | unknown | unknown | unknown |
| Delivery times | published | not published | by city only | 24-48 h | not published |
| Catalog depth (export) | 164 in scope | 124 in scope, no stock data | 424 in scope | 34 in scope, thin | 58 in scope, <50 units |
| Recommendation | launch first | launch with Egypt | after refusal fee known | single test product at most | keep prepared, do not open |

A homepage existing is not readiness. A country opens only when its own test order, governorate mapping, policy
facts and stock routine are verified (docs/LAUNCH-CHECKLIST.md).
