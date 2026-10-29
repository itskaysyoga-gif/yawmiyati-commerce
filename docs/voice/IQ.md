# Yawmiyati voice: Iraq (Iraqi Arabic)

Status: draft by Claude, 28 Oct 2026; revised 29 Oct after the language review. AI-reviewed only. Needs sign-off by a
native Iraqi reviewer (docs/LANGUAGE-REVIEW.md).

## Who we talk to
Women 25-44 across Iraq, Baghdad and Basra first, mostly on a phone, coming from Instagram or Facebook. Cash on
delivery is the norm; trust comes from clear terms and a polite confirmation call.

## Tone
- Warm and respectful, close to how a trusted shop owner in the neighbourhood talks.
- Light Iraqi (Baghdadi) dialect that reads easily across governorates. Not Gulf, not Egyptian.
- Address her in the feminine: اطلبي، اختاري، تكدرين، عدج، طلبج.
- Short sentences. Western digits for prices (43,000 د.ع).
- Say what happens and what she can do, not what is forbidden.

## Three layers (29 Oct 2026, review of the language round; evidence in docs/language/evidence/)
| Layer | What | How it is written |
| --- | --- | --- |
| 1. Functional | buttons, labels, navigation, form errors, accessibility labels, category names | Short, familiar, close to standard Arabic, the same words local stores use ("الدفع عند الاستلام", "الأسئلة الشائعة", "إضافة إلى السلة"). Clarity first; dialect only where it is the plain word. Verbal nouns on buttons ("إضافة إلى السلة", "إرسال الطلب") so no gender is forced |
| 2. Brand and explanation | home lines, product benefits, diary note, tips, FAQ questions | Warm, light Iraqi (Baghdadi) Arabic; FAQ questions in her own voice. No forced slang, no repeated affectionate words |
| 3. Policies and money | payment, confirmation, shipping, returns, refunds, privacy, totals | Precise and unambiguous about WHEN things happen: nothing is paid before delivery; an order is a request until the call confirms it; a total is shown only when the delivery fee is known |

Button wording: the order button says "إرسال الطلب" (send the order), not "تأكيد الطلب": the order is confirmed
later by a call, and a button that says "confirm" next to a page that says "not confirmed yet" contradicts itself.

## Gender and agreement
- The brand speaks to a woman (اطلبي، تقدري، عندك/عندج) in explanatory text. Local retailers address customers in
  masculine or unmarked forms (evidence files); our feminine address is a deliberate brand choice, kept to prose.
- Products differ in gender and number (بطانية، رف، طقم دواسات). Shared strings never assume one: they speak of the
  product generically ("ليه هيعجبك"، "هل المقاس مناسب للمكان؟") instead of "ليه هتحبيها".

## Spelling policy (one rule, applied everywhere)
- Feminine suffix: **ج** (طلبج، عندج، يوصلج). Never چ.
- Dialect verbs whose q is pronounced g are written with **ك**: تكدرين، نكدر، كوليلنا. Words that are the same in
  standard Arabic keep **ق**: وقت، تسوّقي، المقاس، القياس، الطلب (never وكت، تسوكي).
- No گ or چ: Iraqi stores do use گ (Miswag: تگدر), but the site's body font (Readex Pro subset) has no glyph for it,
  so it would render in a fallback font mid-word. Revisit if the font changes.
- The customer in the third person: الزبون / خدمة الزبائن (every Iraqi source checked), never العميل.

## Policy wording: soft but exact
| Instead of | Write |
| --- | --- |
| ما مسموح تفتحين الكرتونة قبل الدفع | الدفع كاش للمندوب وقت الاستلام. بعد الدفع تكدرين تفتحين الكرتونة |
| جاوبي حتى يطلع الطلب | إذا تكدرين جاوبي حتى نكدر ندز الطلب |
| خلص من المخزن | مو متوفر حاليًا |
| أقرب علامة مميزة (Egyptian) | أقرب نقطة دالة |
| الدور (floor, Egyptian) | الطابق |

Errors: رجاءً + what to fix. No blame.

## Words we use
هسّه · رجاءً · تكدرين · راح · حتى · ندز · أجور التوصيل · المحافظة · المحلة · أقرب نقطة دالة · أغراض البيت

## Words we avoid
- Egyptian forms: دلوقتي، عايزة، هتيجي، بتاع، علشان (use حتى / لأن).
- Hype and pressure, unprovable claims, and the supplier's name (same rules as Egypt).
- Heavy slang or words that read as Gulf (وايد، زين as "good" in copy).
- Promises about timing ("خلال 24 ساعة", "بدقيقة", call hours) until verified on a real Iraqi order.

## Facts
Same rule as Egypt. Iraq-specific facts still open: return window (3 or 14 days), delivery time per governorate.
These lines stay out of customer copy until confirmed.

## Diary note (ملاحظة من يومياتي)
Practical and short. Example: «خلّي الصحون اللي تستخدميها كل يوم على الرف، والكاونتر يبقى فاضي للشغل.»
