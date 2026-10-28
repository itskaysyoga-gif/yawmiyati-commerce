# Language review

Translation-key completeness (the integration check that all five countries define the same keys) proves nothing about
quality. Saudi Arabia, UAE and Oman share `copy/gulf.json` plus small country files (docs/voice/GULF.md); each needs its
own native reviewer before that market opens. New keys added 28 Oct (late): search, categories, catalog, quantity,
totals, reviews, abuse-control messages, closed-market message; they are drafts like the rest. This file is the language sign-off. Every line below was drafted by Claude on 28 Oct 2026 and has **not** been
reviewed by a native speaker. `wp ym launch-check` stays red until the option `ym_language_reviewed_<CC>` is set after
the review for that country.

## How the review works

1. One native reviewer per country (Egyptian for EG, Iraqi for IQ), ideally a woman in the 25-44 audience. Give them
   docs/voice/EG.md or IQ.md and this file.
2. They read each line in context on the preview (product page, checkout with errors, order received, policy pages)
   and mark it OK, or write the replacement in the Reviewer column.
3. Claude applies the changes to plugin/yawmiyati-commerce/copy/<cc>.json and content/<cc>/, reruns the tests and
   republishes the preview. The reviewer checks the changed lines once more.
4. Facts are checked separately (step 5 of the copy pipeline, tools/copy/README.md): a reviewer approves wording,
   not prices, sizes or policy terms.

Status values: draft · reviewed · changed · approved.

## Buying and product page

| Key | Egypt (draft) | Iraq (draft) | Status | Reviewer notes |
| --- | --- | --- | --- | --- |
| `buy` | اطلبي دلوقتي والدفع عند الاستلام | اطلبي هسّه والدفع عند الاستلام | draft | |
| `buy_short` | اطلبي دلوقتي | اطلبي هسّه | draft | |
| `add` | ضيفيه للسلة | ضيفيه للسلة | draft | |
| `choose` | اختاري | اختاري | draft | |
| `fit_q` | هتيجي مظبوطة عندك؟ | راح تجي مضبوطة عدج؟ | draft | |
| `why` | ليه هتحبيها | ليش راح تحبينها | draft | |
| `specs_title` | المواصفات | المواصفات | draft | |
| `diary_title` | ملاحظة من يومياتي | ملاحظة من يومياتي | draft | |
| `unverified` | بنأكدها | دا نتأكد منها | draft | |
| `sold_out_tag` | مش متاح حاليًا | مو متوفر حاليًا | draft | |
| `sold_out` | المنتج ده مش متاح حاليًا. | هذا المنتج مو متوفر حاليًا. | draft | |
| `offer_na` | العرض ده مش متاح حاليًا. اختاري عرض تاني من فضلك. | هذا العرض مو متوفر حاليًا. اختاري عرض ثاني رجاءً. | draft | |
| `added` | تمام، ضفناه للسلة. | تمام، انضاف للسلة. | draft | |
| `max_qty` | تقدري تطلبي لحد %d قطع من المنتج ده في الطلب الواحد. | تكدرين تطلبين لحد %d قطع من هذا المنتج بالطلب الواحد. | draft | |
| `cart_saved` | باقي حاجات سلتك محفوظة، وهترجع للسلة بعد الطلب. | باقي أغراض سلتج محفوظة، وترجع للسلة بعد الطلب. | draft | |
| `fee_range` | التوصيل من %1$s لـ %2$s حسب المحافظة | التوصيل من %1$s إلى %2$s حسب المحافظة | draft | |
| `fee_flat` | التوصيل %s | التوصيل %s لكل المحافظات | draft | |
| `zones_head` | المحافظات¦التوصيل¦المدة | المحافظات¦التوصيل¦المدة | draft | |

## Checkout form and errors

| Key | Egypt (draft) | Iraq (draft) | Status | Reviewer notes |
| --- | --- | --- | --- | --- |
| `place` | تأكيد الطلب | تثبيت الطلب | draft | |
| `name_label` | الاسم بالكامل | الاسم الكامل | draft | |
| `name_ph` | الاسم الأول واسم العيلة | الاسم الأول واسم العائلة | draft | |
| `name_err` | من فضلك اكتبي الاسم الأول واسم العيلة. | رجاءً اكتبي الاسم الأول واسم العائلة. | draft | |
| `phone_label` | رقم الموبايل | رقم الموبايل | draft | |
| `phone_err` | اكتبي رقم موبايل مصري من 11 رقم، بيبدأ بـ 010 أو 011 أو 012 أو 015. | اكتبي رقم موبايل عراقي من 11 رقم يبدأ بـ 07. | draft | |
| `phone2` | رقم موبايل تاني | رقم موبايل ثاني | draft | |
| `phone2_help` | لو رقمك الأول مقفول، نقدر نوصلك عليه. | إذا رقمج الأول مسدود، نكدر نوصلج عليه. | draft | |
| `phone2_err` | الرقم التاني:  | الرقم الثاني:  | draft | |
| `gov_label` | المحافظة | المحافظة | draft | |
| `gov_ph` | اختاري المحافظة | اختاري المحافظة | draft | |
| `gov_err` | من فضلك اختاري المحافظة. | رجاءً اختاري المحافظة. | draft | |
| `area_label` | المنطقة والشارع | المنطقة والمحلة والشارع | draft | |
| `area_err` | من فضلك اكتبي المنطقة واسم الشارع. | رجاءً اكتبي المنطقة والمحلة أو اسم الشارع. | draft | |
| `building_label` | رقم العمارة والدور والشقة | رقم الدار أو العمارة والطابق | draft | |
| `landmark_label` | أقرب علامة مميزة | أقرب نقطة دالة | draft | |
| `landmark_help` | زي مدرسة أو صيدلية أو جامع قريب. | مثل جامع أو مدرسة أو صيدلية قريبة. | draft | |
| `pick_gov` | اختاري المحافظة وهيظهر سعر التوصيل. | اختاري المحافظة حتى تطلع أجور التوصيل. | draft | |
| `wrong_country` | بنوصّل جوّه مصر بس. | نوصّل داخل العراق بس. | draft | |
| `refresh` | حدّثي الصفحة وجرّبي تاني من فضلك. | حدّثي الصفحة وجرّبي مرة ثانية رجاءً. | draft | |
| `dup` | الطلب ده اتسجل قبل كده. لو حابة تطلبي تاني، حدّثي الصفحة الأول. | هذا الطلب تسجّل قبل. إذا تحبين تطلبين مرة ثانية، حدّثي الصفحة أولًا. | draft | |
| `in_flight` | طلبك بيتسجل دلوقتي، استني ثواني من فضلك. | طلبج دا يتسجل هسّه، انتظري ثواني رجاءً. | draft | |
| `expired` | الصفحة فضلت مفتوحة وقت طويل. حدّثيها وجرّبي تاني من فضلك. | الصفحة بقت مفتوحة فترة طويلة. حدّثيها وجرّبي مرة ثانية رجاءً. | draft | |

## Policy lines (also used on the policy pages)

| Key | Egypt (draft) | Iraq (draft) | Status | Reviewer notes |
| --- | --- | --- | --- | --- |
| `confirm_call` | هيوصلك اتصال خلال 24 ساعة لتأكيد الطلب والعنوان. ممكن المكالمة تيجي من رقم مش متسجل عندك، فياريت تردّي علشان نقدر نبعت الطلب. لو طلبتي قبل 7 مساءً، بيتأكد في نفس اليوم. | راح يوصلج اتصال خلال 24 ساعة حتى نأكد الطلب والعنوان، بين 9 الصبح و5 العصر. يمكن الرقم ما يكون محفوظ عندج، فإذا تكدرين جاوبي حتى نكدر ندز الطلب. إذا طلبتي قبل 3 العصر، يتأكد طلبج بنفس اليوم. | draft | |
| `payment` | الدفع كاش للمندوب وقت الاستلام. بعد الدفع تقدري تفتحي الشحنة، والفاتورة هتلاقيها جوّاها. | الدفع كاش للمندوب وقت الاستلام. بعد الدفع تكدرين تفتحين الكرتونة، والفاتورة داخلها. | draft | |
| `returns` | لو المنتج وصلك فيه عيب، تقدري تطلبي استبدال أو استرجاع خلال 14 يوم من الاستلام. | — (pending fact) | draft | |
| `one_fee` | رسوم التوصيل بتتحسب مرة واحدة على الطلب كله، مهما كان عدد المنتجات. | أجور التوصيل تنحسب مرة وحدة على الطلب كله، مهما كان عدد المنتجات. | draft | |

## Order received

| Key | Egypt (draft) | Iraq (draft) | Status | Reviewer notes |
| --- | --- | --- | --- | --- |
| `thanks` | وصلنا طلبك، شكرًا ليكي | وصلنا طلبج، شكرًا إلج | draft | |
| `thanks_line` | رقم طلبك %2$s. | رقم طلبج %2$s. | draft | |
| `delivery_address` | عنوان التوصيل | عنوان التوصيل | draft | |
| `order_details` | تفاصيل الطلب | تفاصيل الطلب | draft | |

## Site chrome, home and consent

| Key | Egypt (draft) | Iraq (draft) | Status | Reviewer notes |
| --- | --- | --- | --- | --- |
| `home_title` | حاجات صغيرة بتفرق في البيت | أغراض صغيرة تفرق بالبيت | draft | |
| `home_lede` | اختيارات يومياتي للبيت، والدفع عند الاستلام. | اختيارات يومياتي للبيت، والدفع عند الاستلام. | draft | |
| `home_trust` | الدفع عند الاستلام¦التوصيل لكل المحافظات¦تأكيد بالتليفون قبل الشحن | الدفع عند الاستلام¦التوصيل لكل المحافظات¦تأكيد بالتلفون قبل الشحن | draft | |
| `country_name` | مصر | العراق | draft | |
| `switch_country` | غيّري الدولة | غيّري الدولة | draft | |
| `ask` | عندك سؤال؟ ابعتيلنا رسالة على إنستجرام وهنرد عليكي. | عدج سؤال؟ دزيلنا رسالة على إنستغرام ونرد عليج. | draft | |
| `nav_delivery` | التوصيل والدفع | التوصيل والدفع | draft | |
| `nav_returns` | الاستبدال والاسترجاع | الاستبدال والإرجاع | draft | |
| `nav_faq` | أسئلة بتتكرر | أسئلة متكررة | draft | |
| `nav_contact` | تواصلي معانا | تواصلي ويانا | draft | |
| `nav_privacy` | الخصوصية | الخصوصية | draft | |
| `consent_text` | بنستخدم ملفات تعريف (كوكيز) علشان نفهم الموقع بيتستخدم إزاي ونقيس نتايج الإعلانات. تقدري توافقي أو تكتفي بالضروري. | نستخدم ملفات تعريف (كوكيز) حتى نفهم شلون ينستخدم الموقع ونقيس نتائج الإعلانات. تكدرين توافقين أو تكتفين بالضروري. | draft | |
| `consent_yes` | موافقة | موافقة | draft | |
| `consent_no` | الضروري بس | الضروري بس | draft | |
| `consent_change` | إعدادات الكوكيز | إعدادات الكوكيز | draft | |
| `delivery_title` | التوصيل والدفع | التوصيل والدفع | draft | |

## Added later

| Key | Egypt (draft) | Iraq (draft) | Status | Reviewer notes |
| --- | --- | --- | --- | --- |
| `delivery_word` | التوصيل | التوصيل | draft | |
| `privacy_note` | بنستخدم بياناتك علشان نوصّلك الطلب بس. التفاصيل في [privacy_policy]. | نستخدم معلوماتج حتى نوصّلج الطلب بس. التفاصيل بـ [privacy_policy]. | draft | |

## Pages

| Page | Egypt file | Iraq file | Status |
| --- | --- | --- | --- |
| delivery | content/eg/delivery.html | content/iq/delivery.html | draft |
| returns | content/eg/returns.html | content/iq/returns.html | draft |
| faq | content/eg/faq.html | content/iq/faq.html | draft |
| contact | content/eg/contact.html | content/iq/contact.html | draft |
| privacy | content/eg/privacy.html | content/iq/privacy.html | draft |

## Product copy

Product names, one-line benefits, offer labels, diary notes and fit guidance live in
plugin/yawmiyati-commerce/seed/<cc>.json for now and will come from the copy pipeline once product facts are verified.
All are draft. Review them on each product page of the preview.

Anthropic drafts of the same fields (claude-sonnet-5-5, 28 Oct 2026) are on the branches `copy-drafts/eg-40743731736`
and `copy-drafts/iq-40742863270`, each with an automatic check report (all ok). They are conservative: they use only
the product name and offer labels, because no other facts are verified yet. Re-run after specs are confirmed.
