# Iraq (IQ): Arabic interface wording evidence

Date inspected: 2026-10-29. Method: public pages read with WebFetch. WebFetch returns text pulled out of the page by a model, not the raw HTML. Phrases below are copied exactly as that tool returned them. Spelling such as "ويانة" or "أتصل" is kept as returned. Before shipping copy, check the exact characters on the live page.
Scope: short interface labels only. Competitor fees, delivery times and return windows were left out on purpose. They are not evidence for our policies.

## 1. Miswag (مسواگ): local Iraqi marketplace. **Local dialect source**
- URLs: https://miswag.com/more/faq (Arabic FAQ), https://miswag.com/more/delivery, https://miswag.com/more/contact, https://miswag.com/en/more/services (English only)
- Contexts: FAQ / help centre, delivery page, contact page
- **FAQ question headings are written in Iraqi dialect.** Navigation and policy labels stay in MSA.
- Examples:
  - "شلون اطلب؟" (how do I order)
  - "وين طلبي؟" (where is my order)
  - "شنو طرق الدفع الموجودة؟"
  - "شلون اضيف او الغي منتجات من الطلب؟"
  - "عندي مشكلة بالطلب!"
  - "شگد مبلغ التوصيل؟" (field exists; we did not record their numbers)
  - "المنتجات راح تجي من مسواگ لو من البائع؟"
  - "تدفع عند الاستلام" (cash-on-delivery phrasing)
  - "تگدر تتواصل ويانة" (you can reach us)
  - "حتى نساعدك"
  - MSA labels: "مركز المساعدة", "تواصل معنا", "خدمة التوصيل", "سياسة الارجاع و التبديل لكل منتج", "معلومات الضمان للمنتج"
  - Contact page, also MSA: "الرقم الموحد", "راسلنا على العنوان", "تصفح الأسئلة الشائعة", "الشكاوي"
- Delivery page: MSA and neutral. It says "يتم الاتصال بالزبون" (the customer is contacted).
- Address: second-person singular, written with masculine or unmarked forms (تگدر، نساعدك). The site never addresses the customer in the feminine. Iraqi dialect markers seen: شلون، شنو، وين، شگد، تگدر، ويانة، راح.

## 2. Elryan (الريان): Iraqi electronics and general retailer. **Formal MSA throughout**
- URLs: https://www.elryan.com/ar/payment, https://www.elryan.com/ar/returns, https://www.elryan.com/ar/c/beauty-fragrances-personal-care-oral-hygiene (category page)
- Contexts: payment page, returns and exchange policy, category / product listing
- Examples:
  - "أضف إلى السلّة" (add-to-cart button)
  - "سلّة التّسوّق"
  - "متوفر في المخزون" / "المخزون محدود"
  - "الاستلام أو التوصيل؟"
  - "الدفع نقداً عند الاستلام"
  - "يتم دفع قيمة الطلب عند استلامه"
  - "خدمة الزبائن" / "مركز خدمة الزبائن" / "محادثة مباشرة"
  - "سياسة الاسترجاع والاستبدال"
  - "أن يكون المنتج بحالته الأصلية"
  - "طلب استبدال المنتج" / "الإبلاغ عن الحالة"
  - "اتصلوا بنا"
- Product naming: type, then function, then brand, then model. Example: "جهاز خيط الأسنان المائي شاومي 2" (truncated).
- Address: in the UI, masculine singular imperatives (أضف، أدخل). In the policy pages, formal plural (يمكنكم، اتصلوا، يزوّدكم). The customer is called "الزبون" and "الزبائن", not "العميل".

## 3. Nespresso Iraq (local franchise of an international brand). **International brand, MSA**
- URL: https://www.nespresso.com/iq/ar/shipping-returns
- Context: shipping and returns policy
- Examples:
  - "الشحن والإرجاع"
  - "خيارات الشحن"
  - "الاستبدال"
  - "الدفع عند الاستلام"
  - "كاش عند الاستلام" (the loanword كاش, common in Iraqi speech)
  - "تواصل معنا"
  - "تسوق بأمان"
- Address: masculine singular imperatives (اتصل), with "يرجى" on polite instructions.
- Useful for interface conventions only.

## 4. Talabcom / Delivery IQ: Iraqi delivery apps (App Store listings). **MSA**
- URLs: https://apps.apple.com/lb/app/talabcom/id6463662412, https://apps.apple.com/us/app/id1568878714
- Context: app-store marketing text (not in-app UI)
- Examples: "تتبع الطلب عبر الاشعارات النصية", "استخدام نظام تاكيد الطلب عبر رسالة SMS", "التوصيل لجميع انحاء العراق", "استلام الطلب", "لكل زبون"
- Notes: hamzas are often dropped (انحاء، تاكيد), which is informal spelling. The customer is again "زبون". This is weak evidence: marketing copy, small publishers.

## Could not inspect
- https://miswag.com/more/faq on the first attempt: the permission request timed out. A later attempt succeeded (entry 1).
- https://www.elryan.com/ar/return-policy: the permission request timed out. /ar/returns was used instead.
- Toters (https://apps.apple.com/us/app/toters-%D8%AA%D9%88%D8%AA%D8%B1%D8%B2/id1015006220): the listing description is English only. The in-app Arabic UI is not public.
- Kamel: no credible public Iraqi store page found.
- ar-pay.com list of Iraqi stores: the fetch failed (client_error).

## What this suggests for us (Iraq)
1. **Write FAQ and help questions in Iraqi dialect.** Iraq's largest marketplace (Miswag) does it: "شلون اطلب؟", "وين طلبي؟", "شنو…". Customers phrase questions this way. *(local dialect evidence)*
2. **Keep navigation, buttons and policy headings in plain MSA.** Both local stores do: "مركز المساعدة", "تواصل معنا", "سياسة الارجاع و التبديل", "أضف إلى السلّة". *(local + formal)*
3. **Call the customer "الزبون" (Iraq), not "العميل".** Every Iraqi source used it: Miswag, Elryan and the delivery apps. Use "خدمة الزبائن" for support.
4. COD wording: use "الدفع عند الاستلام" as the label. "تدفع عند الاستلام" fits dialect help text, and "كاش عند الاستلام" is an acceptable casual variant. Elryan's "يتم دفع قيمة الطلب عند استلامه" is a clean MSA explanation line.
5. Natural dialect words for help copy: شلون، شنو، وين، شگد، تگدر، ويانا، راح، حتى نساعدك. Use گ where the dialect sound needs it, as Miswag does.
6. **No Iraqi source used feminine address.** All used masculine or unmarked forms. A women-targeted store could address women in the feminine (تگدرين، اطلبي, used sparingly). That would be our own choice, not a local convention. Gender-neutral phrasing is safest for buttons: verbal nouns and first-person questions such as "شلون اطلب؟".
7. Use "سلّة التسوق" for the cart and "أضف إلى السلّة" for the button. Stock labels: "متوفر" / "الكمية محدودة"-style.
8. Avoid fully formal MSA policy constructions in customer-facing explanations (يُقدَّم طلب الإرجاع…). Keep policies in MSA but simple. Put the conversational dialect tone into the FAQ answers.
9. Contrast only: Miswag and Elryan also mention card and wallet payment. We are COD-only, so none of that wording applies.
