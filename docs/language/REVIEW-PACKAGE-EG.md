# Egypt: native review package

For one Egyptian reviewer, ideally a woman in the 25-44 audience. Every line was written or revised by AI (Claude) and
checked by a second AI pass; **none has been read by a native speaker yet**. Please read each line **on the phone screens**
(review PDF, same commit) and write "OK" or your wording. You review wording only: prices, sizes and policy facts are
checked separately. House rules: docs/voice/EG.md. Generated from docs/language/ledger-eg.csv.

## Priority 1: checkout, errors, money and policy lines
| # | Key | Where | Text | AI critique | OK? / your wording |
| --- | --- | --- | --- | --- | --- |
| 1 | `confirm_call` | policy line | هيوصلك اتصال لتأكيد الطلب والعنوان. ممكن المكالمة تيجي من رقم مش متسجل عندك، فياريت تردّي علشان الطلب يتشحن. | ok | |
| 2 | `payment` | policy line | الدفع كاش للمندوب وقت الاستلام. بعد الدفع تقدري تفتحي الشحنة، والفاتورة هتلاقيها جوّاها. | question | |
| 3 | `returns` | policy line | لو المنتج وصلك فيه عيب، تقدري تطلبي استبدال أو استرجاع خلال 14 يوم من الاستلام. | question | |
| 4 | `one_fee` | policy line | رسوم التوصيل بتتحسب مرة واحدة على الطلب كله، مهما كان عدد المنتجات. | ok | |
| 5 | `thanks_line` | policy line | رقم طلبك %2$s. | question | |
| 6 | `pick_gov` | prices and totals | اختاري المحافظة وهتظهر رسوم التوصيل. | revise | |
| 7 | `fee_range` | prices and totals | التوصيل من %1$s لـ %2$s حسب المحافظة | ok | |
| 8 | `fee_flat` | prices and totals | التوصيل %s | ok | |
| 9 | `unverified` | errors and states | غير مؤكد بعد | ok | |
| 10 | `expired` | errors and states | الصفحة فضلت مفتوحة وقت طويل. حدّثيها وجرّبي تاني من فضلك. | ok | |
| 11 | `offer_na` | errors and states | العرض ده مش متاح حاليًا. اختاري عرض تاني من فضلك. | ok | |
| 12 | `added` | errors and states | تمت الإضافة إلى السلة. | revise | |
| 13 | `max_qty` | errors and states | أقصى كمية من المنتج ده في الطلب الواحد: %d. | ok | |
| 14 | `sold_out_tag` | errors and states | مش متاح حاليًا | ok | |
| 15 | `sold_out` | errors and states | المنتج ده مش متاح حاليًا. | ok | |
| 16 | `name_label` | checkout form | الاسم بالكامل | ok | |
| 17 | `name_ph` | checkout form | الاسم الأول واسم العيلة | ok | |
| 18 | `name_err` | checkout form | من فضلك اكتبي الاسم الأول واسم العيلة. | ok | |
| 19 | `phone_label` | checkout form | رقم الموبايل | ok | |
| 20 | `phone_err` | checkout form | من فضلك اكتبي رقم موبايل مصري من 11 رقم، بيبدأ بـ 010 أو 011 أو 012 أو 015. | revise | |
| 21 | `phone2` | checkout form | رقم موبايل تاني | question | |
| 22 | `phone2_help` | checkout form | لو رقمك الأول مقفول، ممكن المكالمة تيجي على الرقم ده. | revise | |
| 23 | `phone2_err` | checkout form | الرقم التاني:  | ok | |
| 24 | `gov_label` | checkout form | المحافظة | ok | |
| 25 | `gov_ph` | checkout form | اختاري المحافظة | ok | |
| 26 | `gov_err` | checkout form | من فضلك اختاري المحافظة. | ok | |
| 27 | `area_label` | checkout form | المنطقة والشارع | ok | |
| 28 | `area_err` | checkout form | من فضلك اكتبي المنطقة واسم الشارع. | ok | |
| 29 | `building_label` | checkout form | رقم العمارة والدور والشقة | ok | |
| 30 | `landmark_label` | checkout form | أقرب علامة مميزة | ok | |
| 31 | `landmark_help` | checkout form | زي مدرسة أو صيدلية أو جامع قريب. | ok | |
| 32 | `wrong_country` | policy line | بنوصّل جوّه مصر بس. | ok | |
| 33 | `refresh` | errors and states | حدّثي الصفحة وجرّبي تاني من فضلك. | ok | |
| 34 | `dup` | errors and states | الطلب ده اتسجل قبل كده. لو حابة تطلبي تاني، حدّثي الصفحة الأول. | ok | |
| 35 | `in_flight` | errors and states | طلبك بيتسجل دلوقتي، استني ثواني من فضلك. | ok | |
| 36 | `cart_saved` | errors and states | باقي حاجات سلتك محفوظة، وهترجع للسلة بعد الطلب. | ok | |
| 37 | `home_trust` | policy line | الدفع عند الاستلام¦التوصيل لكل المحافظات¦تأكيد بالتليفون قبل الشحن | question | |
| 38 | `privacy_note` | policy line | بنستخدم بياناتك علشان نأكد طلبك ونوصّله ونتابع معاكي لو حصلت مشكلة، وعلشان نحمي المتجر من الطلبات الوهمية. الرسايل التسويقية بس لو وافقتي عليها. التفاصيل في [privacy_policy]. | ok | |
| 39 | `market_closed` | policy line | الطلب من يومياتي في مصر لسه مش متاح. | ok | |
| 40 | `try_again` | errors and states | حصلت مشكلة في إرسال الطلب. حدّثي الصفحة وجرّبي تاني من فضلك. | revise | |
| 41 | `rate_limited` | errors and states | اتبعتت محاولات كتير في وقت قصير. استني دقايق وجرّبي تاني من فضلك. | revise | |
| 42 | `bot_check` | errors and states | محتاجين نتأكد إنك مش برنامج آلي. كمّلي خطوة التأكيد اللي فوق زرار الطلب وجرّبي تاني. | ok | |
| 43 | `plus_delivery` | prices and totals | + توصيل | ok | |
| 44 | `cart_stays` | policy line | سلّتك هنا هتفضل في متجر مصر. لو غيّرتي البلد هتبدئي سلة جديدة بأسعار وتوصيل البلد التانية. | ok | |
| 45 | `total_line` | prices and totals | الإجمالي مع التوصيل: %s | ok | |
| 46 | `total_range` | prices and totals | الإجمالي مع التوصيل من %1$s لـ %2$s حسب المحافظة | ok | |
| 47 | `pay_total` | prices and totals | المطلوب وقت الاستلام | ok | |
| 48 | `thanks_steps` | policy line | هيوصلك اتصال لتأكيد الطلب والعنوان، ومفيش حاجة بتتشحن قبل التأكيد¦بعد التأكيد بنجهّز الشحنة ونبعتها¦الدفع كاش للمندوب وقت الاستلام، مش قبل كده | ok | |
| 49 | `email_label` | checkout form | الإيميل، لو عايزة إيصال الطلب | ok | |
| 50 | `email_err` | checkout form | من فضلك اكتبي إيميل صحيح أو سيبي الخانة فاضية. | revise | |
| 51 | `subtotal_label` | prices and totals | مجموع المنتجات | ok | |
| 52 | `delivery_after_gov` | prices and totals | التوصيل بيتحسب لما تختاري المحافظة | ok | |

## Priority 2: home and product wording
| # | Key | Where | Text | AI critique | OK? / your wording |
| --- | --- | --- | --- | --- | --- |
| 1 | `buy` | home, product and order page | اطلبي دلوقتي والدفع عند الاستلام | ok | |
| 2 | `thanks` | home, product and order page | استلمنا طلبك، شكرًا ليكي | ok | |
| 3 | `fit_q` | home, product and order page | هل المقاس مناسب للمكان؟ | ok | |
| 4 | `why` | home, product and order page | ليه هيعجبك | ok | |
| 5 | `diary_title` | home, product and order page | ملاحظة من يومياتي | ok | |
| 6 | `ask` | home, product and order page | عندك سؤال؟ ابعتيلنا رسالة على إنستجرام وهنرد عليكي. | ok | |
| 7 | `home_title` | home, product and order page | حاجات صغيرة بتفرق في البيت | ok | |
| 8 | `home_lede` | home, product and order page | اختيارات يومياتي للبيت، والدفع عند الاستلام. | ok | |
| 9 | `home_pick` | home, product and order page | اخترنالك | ok | |
| 10 | `reviews_title` | other | آراء بعد الاستلام | ok | |
| 11 | `review_verified` | reviews | تم الشراء والاستلام | revise | |
| 12 | `review_form_title` | reviews | قوليلنا رأيك في المنتج | ok | |
| 13 | `review_thanks` | reviews | شكرًا! هنراجع رأيك قبل ما ينزل. | ok | |
| 14 | `review_invalid` | reviews | الرابط ده مش شغال. لو محتاجة مساعدة ابعتيلنا على إنستجرام. | revise | |
| 15 | `review_done` | reviews | بعتتي رأيك في الطلب ده قبل كده. شكرًا! | ok | |
| 16 | `home_cta` | home, product and order page | تسوّقي اختياراتنا | ok | |
| 17 | `home_cats` | home, product and order page | تسوّقي حسب القسم | ok | |
| 18 | `home_steps_title` | home, product and order page | الطلب سهل | ok | |
| 19 | `home_steps` | home, product and order page | اختاري المنتج وابعتي الطلب من الموقع¦هيوصلك اتصال لتأكيد الطلب والعنوان¦الدفع كاش للمندوب وقت الاستلام | revise | |
| 20 | `home_tip_title` | home, product and order page | فكرة للبيت | ok | |
| 21 | `home_tip_body` | home, product and order page | خلّي الحاجات اللي بتستخدميها كل يوم في مكان واحد قريب من إيدك، والباقي في صندوق بعيد. ترتيب بسيط بيوفّر وقت كل صباح. | ok | |
| 22 | `home_featured` | home, product and order page | من اختياراتنا | ok | |
| 23 | `thanks_next` | home, product and order page | إيه اللي هيحصل بعد كده؟ | ok | |
| 24 | `reco_title` | home, product and order page | ممكن تحتاجي كمان | ok | |
| 25 | `reco_complement` | home, product and order page | حاجة تكمّل المنتج ده | revise | |
| 26 | `reco_substitute` | home, product and order page | بديل ممكن يناسبك | ok | |
| 27 | `product.EG030106MKKT99.name` | product | طقم دواسات قطعتين | question | |
| 28 | `product.EG030106MKKT99.label` | product | طقم واحد | ok | |
| 29 | `product.EG030106MKKT99.note` | product | قطعتين: طويلة + صغيرة | ok | |
| 30 | `product.EG030106MKKT99.benefit` | product | قطعتين في الطقم: واحدة طويلة وواحدة صغيرة، للأماكن اللي بتتبلّ بالمية قدّام الحوض أو الغسالة أو باب الحمام. | revise | |
| 31 | `product.EG030106MKKT99.diary` | product | الطويلة قدّام الحوض والصغيرة على باب الحمام، فأول خطوة بعد الشاور تبقى على دواسة مش على البلاط. | ok | |
| 32 | `product.EG030106MKKT99.fit` | product | قيسي المساحة قدّام الحوض أو باب الحمام وقارنيها بمقاس القطعتين. لو المكان أضيق، الصغيرة تنفع لوحدها قدّام الغسالة. | question | |
| 33 | `product.EG030106MKKT99.spec1.label` | product | في الطقم | ok | |
| 34 | `product.EG030106MKKT99.spec2.label` | product | المقاسات | ok | |
| 35 | `product.EG030106MKKT99.spec3.label` | product | الخامة | ok | |
| 36 | `product.EG030106MKKT99.spec4.label` | product | الغسيل | ok | |
| 37 | `product.EG030108BUND99.name` | product | طقمين دواسات (4 قطع) | ok | |
| 38 | `product.EG030108BUND99.label` | product | طقمين | ok | |
| 39 | `product.EG030108BUND99.note` | product | 4 قطع، للمطبخ والحمام | ok | |
| 40 | `product.EG030108BUND99.saving` | product | أوفر 49 ج.م عن طقمين منفصلين | question | |
| 41 | `product.EG030106STPG99.name` | product | ستاند ملابس بماسورتين وعجل | revise | |
| 42 | `product.EG030106STPG99.benefit` | product | ماسورتين للتعليق ورف تحت للجزم أو الشنط، وبيتحرك على عجل. | ok | |
| 43 | `product.EG030106STPG99.spec1.label` | product | الارتفاع والعرض | ok | |
| 44 | `product.EG030106STPG99.spec2.label` | product | الخامة | ok | |
| 45 | `product.EG030106STPG99.spec3.label` | product | أقصى حمل | revise | |
| 46 | `product.EG030301HAEE99.name` | product | دفاية كهربائية 2 في 1 بريموت | question | |
| 47 | `product.EG030301HAEE99.benefit` | product | دفاية بشكل مدفأة، بشاشة وريموت. | question | |
| 48 | `product.EG030301HAEE99.spec1.label` | product | القدرة (وات) | ok | |
| 49 | `product.EG030301HAEE99.spec2.label` | product | الضمان | question | |
| 50 | `product.EG030301HAEE99.spec3.label` | product | الفولت | ok | |
| 51 | `product.EG030401GB99.name` | product | بطانية تتلبس | ok | |
| 52 | `product.EG030401GB99.benefit` | product | بطانية بكابيشون تتلبس وانتي قاعدة، لون رمادي. | ok | |
| 53 | `product.EG030401GB99.spec1.label` | product | المقاس | ok | |
| 54 | `product.EG030401GB99.spec2.label` | product | الخامة | ok | |
| 55 | `product.EG030401GB99.spec3.label` | product | الغسيل | ok | |
| 56 | `phone2_add` | other | + إضافة رقم موبايل تاني | - | |
| 57 | `cart_totals` | other | ملخص السلة | ok | |
| 58 | `to_checkout` | other | إكمال الطلب | ok | |
| 59 | `fix_these` | other | راجعي النقاط دي قبل ما تبعتي الطلب: | ok | |
| 60 | `skip_link` | other | الانتقال إلى المحتوى | ok | |
| 61 | `cart_empty` | other | سلتك فاضية دلوقتي. | - | |
| 62 | `notfound_title` | other | الصفحة دي مش موجودة | - | |
| 63 | `notfound_help` | other | يمكن الرابط اتغيّر. جرّبي البحث أو شوفي كل المنتجات. | - | |
| 64 | `gallery_n` | other | صورة %1$d من %2$d | - | |

## Priority 2b: buttons, navigation, search (one glance)
`buy_short`: اطلبي دلوقتي · `add`: إضافة إلى السلة · `place`: إرسال الطلب · `choose`: اختاري اللي يناسبك · `specs_title`: المواصفات · `delivery_title`: التوصيل والدفع · `zones_head`: المحافظات¦رسوم التوصيل¦المدة · `delivery_address`: عنوان التوصيل · `order_details`: تفاصيل الطلب · `country_name`: مصر · `switch_country`: تغيير الدولة · `nav_delivery`: التوصيل والدفع · `nav_returns`: الاستبدال والاسترجاع · `nav_privacy`: الخصوصية · `nav_contact`: تواصلي معنا · `nav_faq`: الأسئلة الشائعة · `delivery_word`: التوصيل · `categories`: الأقسام · `all_products`: كل المنتجات · `search_label`: بحث · `search_ph`: دوّري على منتج… · `cart_label`: السلة · `see_all`: شوفي كل المنتجات · `search_title`: نتائج البحث عن "%s" · `results`: عدد المنتجات: %d · `prev`: السابق · `next`: التالي · `pages`: صفحات المنتجات · `no_results`: مفيش نتايج لـ "%s". · `no_products`: مفيش منتجات هنا دلوقتي. · `no_results_help`: جرّبي كلمة أقصر أو اختاري قسم من فوق. · `qty_label`: الكمية · `qty_less`: تقليل الكمية · `qty_more`: زيادة الكمية · `video_play`: تشغيل الفيديو · `review_rating`: تقييمك · `review_text`: رأيك · `review_name`: اسمك الأول (هيظهر مع رأيك) · `review_submit`: إرسال رأيك · `review_stars`: %d من 5 · `home_word`: الرئيسية · `breadcrumb`: مسار الصفحة · `reco_add`: إضافة إلى السلة · `category.kitchen`: المطبخ · `category.bathroom`: الحمّام · `category.laundry`: الغسيل والملابس · `category.storage`: الترتيب والتخزين · `category.comfort`: الدفء والراحة · `category.cleaning`: التنظيف

## Priority 3: emails and consent
| # | Key | Where | Text | AI critique | OK? / your wording |
| --- | --- | --- | --- | --- | --- |
| 1 | `consent_text` | cookie consent | بنستخدم ملفات تعريف الارتباط (الكوكيز) الضرورية علشان الموقع والطلب يشتغلوا. ولو وافقتي، بنستخدم كمان كوكيز علشان نفهم الموقع بيتستخدم إزاي ونقيس نتايج الإعلانات. تقدري توافقي أو تكتفي بالضروري. | revise | |
| 2 | `consent_yes` | cookie consent | موافقة | ok | |
| 3 | `consent_no` | cookie consent | الضروري بس | ok | |
| 4 | `consent_change` | cookie consent | إعدادات الكوكيز | ok | |
| 5 | `consent_short` | cookie consent | كوكيز القياس والإعلانات مش بنستخدمها غير لو وافقتي. | revise | |
| 6 | `consent_customize` | cookie consent | تخصيص الاختيارات | revise | |
| 7 | `consent_essential` | cookie consent | الضروري للطلب (شغّال دايمًا) | ok | |
| 8 | `consent_analytics` | cookie consent | قياس استخدام الموقع (إحصاءات) | ok | |
| 9 | `consent_ads` | cookie consent | قياس الإعلانات وتحسينها (فيسبوك، إنستجرام، سناب شات) | question | |
| 10 | `consent_save` | cookie consent | حفظ | ok | |
| 11 | `news_title` | newsletter consent | أفكار للبيت على إيميلك | ok | |
| 12 | `news_body` | newsletter consent | رسالة واحدة في الأسبوع بالكتير، فيها فكرة مفيدة للبيت ومنتج من اختياراتنا. تقدري تلغي الاشتراك في أي وقت. | revise | |
| 13 | `news_email` | newsletter consent | إيميلك | ok | |
| 14 | `news_agree` | newsletter consent | أوافق إن يومياتي تبعتلي رسايل تسويقية على الإيميل ده | ok | |
| 15 | `news_button` | newsletter consent | اشتراك | revise | |
| 16 | `news_pending` | newsletter consent | بعتنالك رسالة تأكيد. افتحيها واضغطي على الرابط علشان الاشتراك يبدأ. | ok | |
| 17 | `news_need_agree` | newsletter consent | من فضلك علّمي على خانة الموافقة علشان نقدر نسجّل اشتراكك. | revise | |
| 18 | `news_confirmed` | newsletter consent | تمام، اتأكد اشتراكك. | ok | |
| 19 | `news_unsubscribed` | newsletter consent | اتلغى اشتراكك ومش هتوصلك رسايل تسويقية تاني من يومياتي. | ok | |
| 20 | `news_invalid` | newsletter consent | الرابط ده مش شغال أو انتهت صلاحيته. من فضلك افتحي آخر رسالة وصلتك من يومياتي وجرّبي الرابط اللي فيها. | revise | |
| 21 | `mail_footer` | email | الرسالة دي من يومياتي. لو حابة تكلمينا، ردّي على الرسالة دي. | ok | |
| 22 | `mail_unsub` | email | إلغاء الاشتراك | ok | |
| 23 | `mail_confirm_subject` | email | أكّدي اشتراكك في رسايل يومياتي | ok | |
| 24 | `mail_confirm_body` | email | اضغطي على الزرار علشان تأكدي إن الإيميل ده بتاعك وإنك عايزة رسايل يومياتي. لو مش إنتي اللي طلبتي، تجاهلي الرسالة. | ok | |
| 25 | `mail_confirm_button` | email | تأكيد الاشتراك | ok | |
| 26 | `mail_welcome_subject` | email | أهلًا بيكي في يومياتي | ok | |
| 27 | `mail_welcome_body` | email | يومياتي بتختار حاجات بسيطة ومفيدة للبيت، والطلب بالدفع عند الاستلام. هنبعتلك رسالة تسويقية واحدة في الأسبوع بالكتير. | revise | |
| 28 | `mail_received_subject` | email | استلمنا طلبك %s | revise | |
| 29 | `mail_received_body` | email | استلمنا طلبك، ولسه ما اتأكدش. هيوصلك اتصال لتأكيده وتأكيد العنوان. الدفع كاش للمندوب وقت الاستلام، مش قبل كده. | ok | |
| 30 | `mail_confirmed_subject` | email | اتأكد طلبك %s | ok | |
| 31 | `mail_confirmed_body` | email | طلبك اتأكد وبنجهّزه للشحن. الدفع كاش للمندوب وقت الاستلام. | ok | |
| 32 | `mail_shipped_subject` | email | طلبك %s اتشحن | ok | |
| 33 | `mail_shipped_body` | email | طلبك خرج مع شركة الشحن. المبلغ المطلوب وقت الاستلام: %s. | question | |
| 34 | `mail_cancelled_subject` | email | اتلغى طلبك %s | ok | |
| 35 | `mail_cancelled_body` | email | الطلب ده اتلغى ومش هيتشحن. لو ده مش مقصود، ردّي على الرسالة دي. | ok | |
| 36 | `mail_refund_subject` | email | بخصوص استرداد مبلغ طلبك %s | ok | |
| 37 | `mail_refund_body` | email | سجّلنا استرداد %s لطلبك. | question | |
| 38 | `mail_delivered_subject` | email | طلبك %s وصلك: قوليلنا رأيك | ok | |
| 39 | `mail_delivered_body` | email | نتمنى طلبك يعجبك. لو حابة تكتبي رأيك، الرابط تحت. رأيك بيظهر زي ما هو بعد المراجعة، سواء كان كويس أو لأ. | ok | |
| 40 | `mail_review_button` | email | كتابة رأيك | ok | |
| 41 | `mail_order_total` | email | الإجمالي مع التوصيل | ok | |
| 42 | `news_confirm_q` | newsletter consent | اضغطي على الزرار علشان نأكد اشتراكك في رسايل يومياتي. | ok | |
| 43 | `news_unsub_q` | newsletter consent | عايزة توقفي رسايل يومياتي التسويقية على الإيميل ده؟ | ok | |

## Priority 4: policy pages
Read the five pages on the phone: delivery, returns, privacy, contact, FAQ (the review PDF and, after staging, the
staging site). Mark anything unclear, too formal or not how you would say it.

## After the review
Claude applies the changes, reruns the tests, regenerates the PDF, and the reviewer checks the changed lines once.
Then Rashid sets `ym_language_reviewed_EG` (launch checklist P0-15).
