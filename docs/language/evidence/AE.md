# UAE (AE): Arabic interface wording evidence (market prepared, closed)

Date inspected: 2026-10-29. Method: public pages read with WebFetch, which returns model-extracted text. Phrases below are copied exactly as returned. Check the live page before reuse.
Scope: short labels only. Competitor fees, times and return windows were left out on purpose.

## 1. Nahdi Online UAE (النهدي أونلاين): pharmacy and health. **Formal MSA**
- URL: https://www.nahdionline.com/ar-ae/static/shipping-delivery
- Context: delivery policy, with returns and support links
- Examples:
  - "معلومات التوصيل" / "تكلفة التوصيل" (field exists; values not recorded)
  - "خيار الدفع عند الاستلام غير متاح" (availability-notice pattern)
  - "الاستبدال والاسترجاع"
  - "طلب الاسترداد"
  - "الإبلاغ عن مشكلة"
  - "خدمات ضيوف النهدي أونلاين"
  - "اتصل بنا" / "الأسئلة الشائعة"
- Address: gender-neutral ("الضيف"; "يُرجى من الضيف").

## 2. Jarir Bookstore UAE (مكتبة جرير): Saudi chain, UAE storefront. **Formal MSA**
- URL: https://www.jarir.com/ae-ar/login
- Context: login and registration, form labels, validation, navigation
- Examples:
  - "تسجيل الدخول" / "التسجيل"
  - "البريد الإلكتروني أو رقم الجوال" (field label)
  - "الرقم السري"
  - "الدخول برمز التحقق"
  - "نسيت؟"
  - "الرجاء كتابة 2 حروف على الأقل" (validation message pattern)
  - "طلباتي" / "المفضلة"
  - "خدمة العملاء"
  - "مرحباً، زائرنا"
- Address: masculine default (مرحباً بك، لديك). "الرجاء" and "يمكنك" are neutral. Gulf usage: "الجوال" for mobile phone.

## 3. noon (نون): UAE-based marketplace (App Store listing covering AE, SA, EG). **International, MSA, with one Gulf dialect line**
- URL: https://apps.apple.com/ma/app/%D9%86%D9%88%D9%86-%D9%84%D9%84%D8%AA%D8%B3%D9%88%D9%82-%D9%88%D8%A7%D9%84%D8%A3%D8%B7%D8%B9%D9%85%D8%A9-%D9%88%D8%A7%D9%84%D8%A8%D9%82%D8%A7%D9%84%D8%A9/id1269038866?l=ar
- Context: app marketing description
- Examples: "ادفع عند الاستلام", "خدمة التوصيل السريع", "سياسة استرجاع", "الإرجاع السهل", "مرحبا بك في نون!", "حمّل التطبيق وتسوق أونلاين"
- Gulf dialect, contrast only: "اشتري الحين وادفع بعدين" is BNPL wording and does not apply to our COD journey. It only shows that Gulf dialect turns up in short promotional lines.

## Could not inspect
- Home Centre UAE click-and-collect (https://www.homecentre.com/ae/ar/click-and-collect): the WebFetch permission request timed out, so the page was not retrieved. The English help article on click & collect was found in search but not used.
- Sharaf DG COD page (https://uae.sharafdg.com/ar/cod/): failed (client_error).
- Lulu UAE FAQ (https://gcc.luluhypermarket.com/ar-ae/faq): failed (client_error).
- Namshi UAE returns (https://www.namshi.com/uae-ar/return-policy/): server error.
- Samsung UAE COD FAQ (https://ushl.samsung.com/ae_ar/…): blocked by robots.txt.
- Bin Sina (https://www.binsina.ae/ar/delivery-return): failed (client_error).

## What this suggests for us (UAE)
1. UAE Arabic retail UI is **formal MSA**. All evidence comes from international or pan-GCC retailers, so it is useful for interface conventions only. No local dialect store was inspected.
2. Standard labels to mirror: "تسجيل الدخول", "طلباتي", "خدمة العملاء", "الاستبدال والاسترجاع", "طلب الاسترداد", "الإبلاغ عن مشكلة".
3. COD labels: "الدفع عند الاستلام" as the noun label and "ادفع عند الاستلام" as the imperative (noon). We should prefer the noun form, because it avoids marking gender.
4. Field labels: "رقم الجوال" (Gulf) rather than "رقم الموبايل" (Egypt). Mark this as a per-country string.
5. Validation pattern from Jarir: start with "الرجاء…" and state the rule. This is neutral and polite, and a good template for our form errors.
6. Nahdi's "الضيف" convention is again a neutral way to refer to the customer in policies.
7. Masculine is the default for imperatives. No feminine address was observed.
8. Contrast only: BNPL wording is prominent (noon). Do not adopt it.
