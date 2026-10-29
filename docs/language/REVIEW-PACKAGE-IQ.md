# Iraq: native review package

For one Iraqi reviewer, ideally a woman in the 25-44 audience. Every line was written or revised by AI (Claude) and
checked by a second AI pass; **none has been read by a native speaker yet**. Please read each line **on the phone screens**
(review PDF, same commit) and write "OK" or your wording. You review wording only: prices, sizes and policy facts are
checked separately. House rules: docs/voice/IQ.md. Generated from docs/language/ledger-iq.csv.

## Priority 1: checkout, errors, money and policy lines
| # | Key | Where | Text | AI critique | OK? / your wording |
| --- | --- | --- | --- | --- | --- |
| 1 | `confirm_call` | policy line | راح يوصلج اتصال حتى نتأكد من الطلب والعنوان. يمكن الرقم ما يكون محفوظ عندج، فرجاءً جاوبي حتى نكدر ندز الطلب. | ok | |
| 2 | `payment` | policy line | الدفع كاش للمندوب وقت الاستلام. بعد الدفع تكدرين تفتحين الكرتونة، والفاتورة داخلها. | question | |
| 3 | `one_fee` | policy line | أجور التوصيل تنحسب مرة وحدة على الطلب كله، مهما كان عدد المنتجات. | ok | |
| 4 | `thanks_line` | policy line | رقم طلبج %2$s. | question | |
| 5 | `pick_gov` | prices and totals | اختاري المحافظة حتى تطلع أجور التوصيل. | ok | |
| 6 | `fee_range` | prices and totals | التوصيل من %1$s إلى %2$s حسب المحافظة | ok | |
| 7 | `fee_flat` | prices and totals | التوصيل %s لكل المحافظات | question | |
| 8 | `unverified` | errors and states | غير مؤكد بعد | ok | |
| 9 | `expired` | errors and states | الصفحة بقت مفتوحة فترة طويلة. حدّثيها وجرّبي مرة ثانية رجاءً. | ok | |
| 10 | `offer_na` | errors and states | هذا العرض مو متوفر حاليًا. اختاري عرض ثاني رجاءً. | ok | |
| 11 | `added` | errors and states | تمت الإضافة إلى السلة. | revise | |
| 12 | `max_qty` | errors and states | أكثر كمية من هذا المنتج بالطلب الواحد: %d. | ok | |
| 13 | `sold_out_tag` | errors and states | مو متوفر حاليًا | ok | |
| 14 | `sold_out` | errors and states | هذا المنتج مو متوفر حاليًا. | ok | |
| 15 | `name_label` | checkout form | الاسم الكامل | ok | |
| 16 | `name_ph` | checkout form | الاسم الأول واسم العائلة | ok | |
| 17 | `name_err` | checkout form | رجاءً اكتبي الاسم الأول واسم العائلة. | ok | |
| 18 | `phone_label` | checkout form | رقم الموبايل | ok | |
| 19 | `phone_err` | checkout form | رجاءً اكتبي رقم موبايل عراقي من 11 رقم يبدأ بـ 07. | revise | |
| 20 | `phone2` | checkout form | رقم موبايل ثاني (اختياري) | ok | |
| 21 | `phone2_help` | checkout form | إذا رقمج الأول مسدود، ممكن الاتصال يجي على هذا الرقم. | revise | |
| 22 | `phone2_err` | checkout form | الرقم الثاني:  | ok | |
| 23 | `gov_label` | checkout form | المحافظة | ok | |
| 24 | `gov_ph` | checkout form | اختاري المحافظة | ok | |
| 25 | `gov_err` | checkout form | رجاءً اختاري المحافظة. | ok | |
| 26 | `area_label` | checkout form | المنطقة والمحلة والشارع | ok | |
| 27 | `area_err` | checkout form | رجاءً اكتبي المنطقة والمحلة واسم الشارع إذا تعرفيه. | question | |
| 28 | `building_label` | checkout form | رقم الدار أو العمارة والطابق | ok | |
| 29 | `landmark_label` | checkout form | أقرب نقطة دالة | ok | |
| 30 | `landmark_help` | checkout form | مثل جامع أو مدرسة أو صيدلية قريبة. | ok | |
| 31 | `wrong_country` | policy line | نوصّل داخل العراق بس. | ok | |
| 32 | `refresh` | errors and states | حدّثي الصفحة وجرّبي مرة ثانية رجاءً. | ok | |
| 33 | `dup` | errors and states | هذا الطلب تسجّل قبل. إذا تحبين تطلبين مرة ثانية، حدّثي الصفحة أولًا. | ok | |
| 34 | `in_flight` | errors and states | طلبج دا يتسجل هسّه، انتظري ثواني رجاءً. | ok | |
| 35 | `cart_saved` | errors and states | باقي أغراض سلتج محفوظة، وترجع للسلة بعد الطلب. | ok | |
| 36 | `home_trust` | policy line | الدفع عند الاستلام¦التوصيل لكل المحافظات¦تأكيد بالتلفون قبل الشحن | question | |
| 37 | `privacy_note` | policy line | نستخدم معلوماتج حتى نتأكد من طلبج ونوصّله ونتابع وياج إذا صارت مشكلة، وحتى نحمي المتجر من الطلبات الوهمية. الرسائل التسويقية بس إذا وافقتي عليها. التفاصيل بـ [privacy_policy]. | ok | |
| 38 | `market_closed` | policy line | الطلب من يومياتي بالعراق بعده مو متاح. | question | |
| 39 | `try_again` | errors and states | صارت مشكلة بإرسال الطلب. حدّثي الصفحة وجرّبي مرة ثانية. | ok | |
| 40 | `rate_limited` | errors and states | صارت محاولات هواية بوقت قصير. رجاءً انتظري كم دقيقة وجرّبي مرة ثانية. | revise | |
| 41 | `bot_check` | errors and states | نحتاج نتأكد إنج مو برنامج آلي. كمّلي خطوة التأكيد اللي فوق زر الطلب وجرّبي مرة ثانية. | revise | |
| 42 | `plus_delivery` | prices and totals | + توصيل | ok | |
| 43 | `cart_stays` | policy line | سلّتج هنا تبقى بمتجر العراق. إذا غيرتي البلد تبدين سلة جديدة بأسعار وتوصيل البلد الثاني. | ok | |
| 44 | `total_line` | prices and totals | المجموع ويا التوصيل: %s | ok | |
| 45 | `total_range` | prices and totals | المجموع ويا التوصيل من %1$s إلى %2$s حسب المحافظة | ok | |
| 46 | `pay_total` | prices and totals | المطلوب وقت الاستلام | ok | |
| 47 | `thanks_steps` | policy line | راح يوصلج اتصال حتى نتأكد من الطلب والعنوان، وما ينشحن شي قبل التأكيد¦بعد التأكيد نجهّز الشحنة وندزّها¦الدفع كاش للمندوب وقت الاستلام، مو قبل | ok | |
| 48 | `email_label` | checkout form | الإيميل (اختياري، إذا تريدين وصل الطلب) | ok | |
| 49 | `email_err` | checkout form | رجاءً اكتبي إيميل صحيح أو خلّي الخانة فارغة. | revise | |
| 50 | `subtotal_label` | prices and totals | مجموع المنتجات | ok | |
| 51 | `delivery_after_gov` | prices and totals | أجور التوصيل تنحسب من تختارين المحافظة | ok | |

## Priority 2: home and product wording
| # | Key | Where | Text | AI critique | OK? / your wording |
| --- | --- | --- | --- | --- | --- |
| 1 | `buy` | home, product and order page | اطلبي هسّه والدفع عند الاستلام | ok | |
| 2 | `thanks` | home, product and order page | استلمنا طلبج، شكرًا إلج | ok | |
| 3 | `fit_q` | home, product and order page | هل المقاس مناسب للمكان؟ | ok | |
| 4 | `why` | home, product and order page | ليش راح يعجبج | ok | |
| 5 | `diary_title` | home, product and order page | ملاحظة من يومياتي | ok | |
| 6 | `ask` | home, product and order page | عدج سؤال؟ دزيلنا رسالة على إنستغرام ونرد عليج. | ok | |
| 7 | `home_title` | home, product and order page | أغراض صغيرة تفرق بالبيت | ok | |
| 8 | `home_lede` | home, product and order page | اختيارات يومياتي للبيت، والدفع عند الاستلام. | ok | |
| 9 | `home_pick` | home, product and order page | اخترنالج | ok | |
| 10 | `reviews_title` | other | آراء بعد الاستلام | ok | |
| 11 | `review_verified` | reviews | تم الشراء والاستلام | question | |
| 12 | `review_form_title` | reviews | كوليلنا رأيج بالمنتج | ok | |
| 13 | `review_thanks` | reviews | شكرًا! رأيج يطلع بعد ما نراجعه. | ok | |
| 14 | `review_invalid` | reviews | هالرابط مو شغال. إذا تحتاجين مساعدة دزيلنا على إنستغرام. | ok | |
| 15 | `review_done` | reviews | دزيتي رأيج بهالطلب قبل. شكرًا! | ok | |
| 16 | `home_cta` | home, product and order page | تسوّقي اختياراتنا | ok | |
| 17 | `home_cats` | home, product and order page | تسوّقي حسب القسم | ok | |
| 18 | `home_steps_title` | home, product and order page | الطلب سهل | ok | |
| 19 | `home_steps` | home, product and order page | اختاري الغرض ودزّي الطلب من الموقع¦يوصلج اتصال حتى نتأكد من الطلب والعنوان¦الدفع كاش للمندوب وقت الاستلام | ok | |
| 20 | `home_tip_title` | home, product and order page | فكرة للبيت | ok | |
| 21 | `home_tip_body` | home, product and order page | خلّي الأغراض اللي تستخدميها كل يوم بمكان واحد قريب، والباقي بصندوق على جنب. ترتيب بسيط يوفّرلج وقت كل صبح. | ok | |
| 22 | `home_featured` | home, product and order page | من اختياراتنا | ok | |
| 23 | `thanks_next` | home, product and order page | شنو اللي يصير بعدين؟ | ok | |
| 24 | `reco_title` | home, product and order page | يمكن تحتاجين هم | ok | |
| 25 | `reco_complement` | home, product and order page | إضافة تكمّل اختيارج | revise | |
| 26 | `reco_substitute` | home, product and order page | بديل يمكن يناسبج | ok | |
| 27 | `product.IQ030403SENK99.name` | product | رف فوق السنك | ok | |
| 28 | `product.IQ030403SENK99.label` | product | الرف لوحده | ok | |
| 29 | `product.IQ030403SENK99.note` | product | المجموع مع التوصيل 50,000 د.ع | revise | |
| 30 | `product.IQ030403SENK99.benefit` | product | الصحون تنشف فوق السنك بدل ما تاخذ مكان على الكاونتر. | revise | |
| 31 | `product.IQ030403SENK99.diary` | product | خلّي الصحون اللي تستخدميها كل يوم على الرف، والكاونتر يبقى فاضي للشغل. | ok | |
| 32 | `product.IQ030403SENK99.fit` | product | قيسي عرض السنك من الحافة للحافة، وتأكدي إنه أقل من الفتحة بين رجول الرف. | ok | |
| 33 | `product.IQ030403SENK99.spec1.label` | product | العرض المناسب للسنك | ok | |
| 34 | `product.IQ030403SENK99.spec2.label` | product | الخامة | ok | |
| 35 | `product.IQ030403SENK99.spec3.label` | product | محتويات الكرتونة | ok | |
| 36 | `product.IQ030403SENK99.spec4.label` | product | التركيب | ok | |
| 37 | `product.IQ030501SDMN99.name` | product | رف فوق السنك + طقم دواسات قطعتين | ok | |
| 38 | `product.IQ030501SDMN99.label` | product | الرف + طقم دواسات قطعتين | ok | |
| 39 | `product.IQ030501SDMN99.note` | product | الرف والدواسات بطلب واحد وأجور توصيل وحدة | ok | |
| 40 | `product.IQ030501SDMN99.saving` | product | توفرين 3,000 د.ع عن الرف والدواسات منفصلين | ok | |
| 41 | `product.IQ030106MATT99.name` | product | طقم دواسات قطعتين | ok | |
| 42 | `product.IQ030106MATT99.benefit` | product | قطعتين، وحدة للمطبخ ووحدة للحمام. | ok | |
| 43 | `product.IQ030106MATT99.spec1.label` | product | محتويات الطقم | revise | |
| 44 | `product.IQ030106MATT99.spec2.label` | product | المقاسات | ok | |
| 45 | `product.IQ030106MATT99.spec3.label` | product | الخامة | ok | |
| 46 | `product.IQ030301SOBA99.name` | product | صوبة كهربائية دوّارة 180 درجة | revise | |
| 47 | `product.IQ030301SOBA99.benefit` | product | صوبة دائرية تدور 180 درجة حتى توزع الدفو بالغرفة. | ok | |
| 48 | `product.IQ030301SOBA99.spec1.label` | product | القدرة (واط) | ok | |
| 49 | `product.IQ030301SOBA99.spec2.label` | product | الضمان | question | |
| 50 | `product.IQ030106MONZ99.name` | product | منظم ملابس زاوية | ok | |
| 51 | `product.IQ030106MONZ99.benefit` | product | يستغل زاوية الغرفة للتعليق والرفوف. | ok | |
| 52 | `product.IQ030106MONZ99.spec1.label` | product | الارتفاع | ok | |
| 53 | `product.IQ030106MONZ99.spec2.label` | product | عدد الرفوف | ok | |
| 54 | `product.IQ030106MONZ99.spec3.label` | product | الخامة | ok | |
| 55 | `product.IQ030107MASA99.name` | product | مساحة زجاج مغناطيسية مزدوجة | ok | |
| 56 | `product.IQ030107MASA99.benefit` | product | تنظف الزجاج من الجهتين مرة وحدة. | ok | |
| 57 | `product.IQ030107MASA99.spec1.label` | product | سمك الزجاج المناسب | ok | |
| 58 | `product.IQ030104STAN99.name` | product | ستاند ملابس زوجي | ok | |
| 59 | `product.IQ030104STAN99.benefit` | product | ستاند بماسورتين ورف تحت. | ok | |
| 60 | `product.IQ030104STAN99.spec1.label` | product | الارتفاع والعرض | ok | |
| 61 | `product.IQ030104STAN99.spec2.label` | product | الخامة | ok | |
| 62 | `cart_totals` | other | ملخص السلة | ok | |
| 63 | `to_checkout` | other | متابعة الطلب | ok | |
| 64 | `fix_these` | other | راجعي هالنقاط قبل لا تدزين الطلب: | ok | |
| 65 | `skip_link` | other | الانتقال إلى المحتوى | ok | |

## Priority 2b: buttons, navigation, search (one glance)
`buy_short`: اطلبي هسّه · `add`: إضافة إلى السلة · `place`: إرسال الطلب · `choose`: اختاري اللي يناسبج · `specs_title`: المواصفات · `delivery_title`: التوصيل والدفع · `zones_head`: المحافظات¦أجور التوصيل¦المدة · `delivery_address`: عنوان التوصيل · `order_details`: تفاصيل الطلب · `country_name`: العراق · `switch_country`: تغيير الدولة · `nav_delivery`: التوصيل والدفع · `nav_returns`: الاستبدال والإرجاع · `nav_privacy`: الخصوصية · `nav_contact`: تواصلي معنا · `nav_faq`: الأسئلة الشائعة · `delivery_word`: التوصيل · `categories`: الأقسام · `all_products`: كل المنتجات · `search_label`: بحث · `search_ph`: دوّري على منتج… · `cart_label`: السلة · `see_all`: شوفي كل المنتجات · `search_title`: نتائج البحث عن "%s" · `results`: عدد المنتجات: %d · `prev`: السابق · `next`: التالي · `pages`: صفحات المنتجات · `no_results`: ما لكينا شي باسم "%s". · `no_products`: ماكو منتجات هنا هسّه. · `no_results_help`: جرّبي كلمة أقصر أو اختاري قسم من فوق. · `qty_label`: العدد · `qty_less`: تقليل العدد · `qty_more`: زيادة العدد · `video_play`: تشغيل الفيديو · `review_rating`: تقييمج · `review_text`: رأيج · `review_name`: اسمج الأول (يطلع ويا رأيج) · `review_submit`: إرسال رأيج · `review_stars`: %d من 5 · `home_word`: الرئيسية · `breadcrumb`: مسار الصفحة · `reco_add`: إضافة إلى السلة · `category.kitchen`: المطبخ · `category.bathroom`: الحمّام · `category.laundry`: الغسيل والملابس · `category.storage`: الترتيب والتخزين · `category.comfort`: الدفء والراحة · `category.cleaning`: التنظيف

## Priority 3: emails and consent
| # | Key | Where | Text | AI critique | OK? / your wording |
| --- | --- | --- | --- | --- | --- |
| 1 | `consent_text` | cookie consent | نستخدم ملفات تعريف (كوكيز) حتى نفهم شلون ينستخدم الموقع ونقيس نتائج الإعلانات. تكدرين توافقين أو تكتفين بالضروري. | ok | |
| 2 | `consent_yes` | cookie consent | موافقة | ok | |
| 3 | `consent_no` | cookie consent | الضروري بس | ok | |
| 4 | `consent_change` | cookie consent | إعدادات الكوكيز | ok | |
| 5 | `consent_short` | cookie consent | نستخدم كوكيز للقياس والإعلانات بس إذا وافقتي. | ok | |
| 6 | `consent_customize` | cookie consent | تخصيص الاختيارات | ok | |
| 7 | `consent_essential` | cookie consent | الضروري للطلب (شغّال دائمًا) | ok | |
| 8 | `consent_analytics` | cookie consent | قياس استخدام الموقع (إحصائيات) | ok | |
| 9 | `consent_ads` | cookie consent | قياس الإعلانات وتحسينها (فيسبوك، إنستغرام، سناب شات) | question | |
| 10 | `consent_save` | cookie consent | حفظ | ok | |
| 11 | `news_title` | newsletter consent | أفكار للبيت على إيميلج | ok | |
| 12 | `news_body` | newsletter consent | رسالة وحدة بالأسبوع بالأكثر، بيها فكرة مفيدة للبيت وغرض من اختياراتنا. تكدرين تلغين الاشتراك بأي وقت. | ok | |
| 13 | `news_email` | newsletter consent | إيميلج | ok | |
| 14 | `news_agree` | newsletter consent | أوافق إن يومياتي تدزلي رسائل تسويقية على هذا الإيميل | revise | |
| 15 | `news_button` | newsletter consent | اشتراك | ok | |
| 16 | `news_pending` | newsletter consent | دزّينالج رسالة تأكيد. افتحيها واضغطي الرابط حتى يبدي الاشتراك. | ok | |
| 17 | `news_need_agree` | newsletter consent | أشّري على الموافقة أول. | ok | |
| 18 | `news_confirmed` | newsletter consent | تمام، تأكد اشتراكج. | ok | |
| 19 | `news_unsubscribed` | newsletter consent | انلغى اشتراكج وما راح توصلج رسائل تسويقية بعد من يومياتي. | ok | |
| 20 | `news_invalid` | newsletter consent | هذا الرابط ما يشتغل أو انتهى. | ok | |
| 21 | `mail_footer` | email | هذي الرسالة من يومياتي. للرد اكتبي على نفس الإيميل. | ok | |
| 22 | `mail_unsub` | email | إلغاء الاشتراك | ok | |
| 23 | `mail_confirm_subject` | email | أكّدي اشتراكج برسائل يومياتي | ok | |
| 24 | `mail_confirm_body` | email | اضغطي الزر حتى تأكدين هذا الإيميل مالج وتريدين رسائل يومياتي. إذا مو إنتِ اللي طلبتي، تجاهلي الرسالة. | ok | |
| 25 | `mail_confirm_button` | email | تأكيد الاشتراك | ok | |
| 26 | `mail_welcome_subject` | email | هلا بيج بيومياتي | ok | |
| 27 | `mail_welcome_body` | email | يومياتي تختار أغراض بسيطة ومفيدة للبيت، والطلب بالدفع عند الاستلام. راح ندزلج رسالة تسويقية وحدة بالأسبوع بالأكثر. | ok | |
| 28 | `mail_received_subject` | email | استلمنا طلبج %s | ok | |
| 29 | `mail_received_body` | email | استلمنا طلبج، وبعده ما تأكد. راح يوصلج اتصال حتى نتأكد منه ومن العنوان. الدفع كاش للمندوب وقت الاستلام، مو قبل. | ok | |
| 30 | `mail_confirmed_subject` | email | تأكد طلبج %s | ok | |
| 31 | `mail_confirmed_body` | email | طلبج تأكد ودا نجهّزه للشحن. الدفع كاش للمندوب وقت الاستلام. | ok | |
| 32 | `mail_shipped_subject` | email | طلبج %s انشحن | ok | |
| 33 | `mail_shipped_body` | email | طلبج طلع ويا شركة الشحن. المبلغ المطلوب وقت الاستلام: %s. | ok | |
| 34 | `mail_cancelled_subject` | email | انلغى طلبج %s | ok | |
| 35 | `mail_cancelled_body` | email | هذا الطلب انلغى وما راح ينشحن. إذا مو مقصود، ردّي على هذي الرسالة. | ok | |
| 36 | `mail_refund_subject` | email | استرجاع مبلغ لطلبج %s | ok | |
| 37 | `mail_refund_body` | email | سجّلنا استرجاع %s لطلبج. | question | |
| 38 | `mail_delivered_subject` | email | طلبج %s وصلج: كوليلنا رأيج | ok | |
| 39 | `mail_delivered_body` | email | نتمنى طلبج يعجبج. إذا حابة تكتبين رأيج، الرابط جوّه. رأيج يطلع مثل ما هو بعد المراجعة، سواء عجبج لو لا. | ok | |
| 40 | `mail_review_button` | email | كتابة رأيج | revise | |
| 41 | `mail_order_total` | email | المجموع ويا التوصيل | ok | |
| 42 | `news_confirm_q` | newsletter consent | اضغطي الزر حتى نثبّت اشتراكج برسائل يومياتي. | ok | |
| 43 | `news_unsub_q` | newsletter consent | تريدين توقفين رسائل يومياتي التسويقية على هذا الإيميل؟ | ok | |

## Priority 4: policy pages
Read the five pages on the phone: delivery, returns, privacy, contact, FAQ (the review PDF and, after staging, the
staging site). Mark anything unclear, too formal or not how you would say it.

## After the review
Claude applies the changes, reruns the tests, regenerates the PDF, and the reviewer checks the changed lines once.
Then Rashid sets `ym_language_reviewed_IQ` (launch checklist P0-15).
