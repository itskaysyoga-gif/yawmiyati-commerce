# Taager: the one request before staging (29 Oct 2026)

Replaces the drafts for rounds 3, 4 and 5 (none was sent; their questions are all here, duplicates merged). Send as
**one message** to the account manager; ask for the answers in writing. Arabic text to send; English notes are for us.

---

السلام عليكم،
أنا صاحب متجر "يومياتي" على WooCommerce، وهربط إضافة تاجر بمتجر في مصر ومتجر في العراق. قبل ما أبدأ محتاج إجابات مكتوبة على الأسئلة دي، كلها في رسالة واحدة:

**أولًا: الإضافة والطلبات**
1. الطلب بيتبعت لتاجر إمتى بالظبط؟ لو حالة الطلب "قيد الانتظار" (on-hold)، هل بيتبعت ولا بيستنى لما أغيّرها لـ"قيد التنفيذ"؟
2. هل الإضافة بتبعت رقم موبايل تاني للعميلة لو موجود؟ وهل بتبعت "أقرب علامة مميزة" ورقم العمارة والدور؟ ولو لأ، أكتبهم فين علشان فريق التأكيد والمندوب يشوفوهم (ملاحظات الطلب مثلًا)؟
3. إيه أسماء حالات الطلب اللي بترجع لمتجري بالظبط (تم التأكيد، تم الشحن، تم التوصيل، مرفوض، مرتجع، ملغي)؟

**ثانيًا: الطلبات التجريبية**
4. هعمل كام طلب تجريبي وألغيهم قبل الشحن: هل في أي رسوم أو خصم؟ هل بيأثروا على تقييم حسابي أو نسبة التأكيد والتسليم؟ وإيه أحسن طريقة أعلّم بيها الطلب إنه تجريبي؟
5. هعمل كمان طلب واحد حقيقي في مصر وواحد في العراق يتسلّم لحد أعرفه ويدفع عند الاستلام، علشان أشوف الشحنة والفاتورة ومدة التوصيل وسحب الأرباح. في مانع أو حاجة لازم أعملها؟

**ثالثًا: الاسم اللي بيظهر للعميلة**
6. فريق التأكيد لما بيكلم العميلة بيقول إنه من مين؟ ينفع يقول "يومياتي"؟
7. الشحنة والفاتورة عليها اسم أو لوجو مين؟ ينفع يكون "يومياتي" أو من غير اسم؟
8. لو العميلة عايزة ترجّع منتج: مين بيكلمها، والفلوس بترجعلها إزاي؟

**رابعًا: الرسوم والمدد**
9. العراق: ممكن تأكدولي كتابةً إن مفيش خصم عليّ لو العميلة رفضت الطلب عند الاستلام؟ وهل في رسوم على المرتجع بعد الاستلام؟ ومدة الإرجاع 3 أيام ولا 14 يوم؟
10. مدة التوصيل في العراق لكل محافظة كام يوم تقريبًا؟
11. المخزون في العراق: ملف المنتجات مفيهوش بيانات مخزون للعراق. أعرف المخزون المتاح لكل منتج إزاي؟
12. سحب الأرباح: ينفع على حساب بنكي باسم أخويا في الإمارات؟ وفي رسوم على السحب لحساب بنكي أو Payoneer؟ كام؟
13. السعودية والإمارات وعُمان (بعدين، مش دلوقتي): في رسوم بتتخصم لو العميلة رفضت الطلب أو رجّعته؟ كام؟ وضريبة القيمة المضافة في السعودية (15% على الربح الزيادة) بتنطبق عليّ وأنا مش مقيم هناك؟

**خامسًا: بيانات وصور المنتجات**
14. للمنتجات دي محتاج المقاسات والخامة ومحتويات العلبة، والصور الأصلية بجودة عالية (من غير كتابة أو لوجوهات) مع إذن مكتوب إني أستخدمها على موقعي وصفحاتي. أهم حاجتين: مقاسات الدواسات وعرض رف الحوض.
EG030106MKKT99، EG030108BUND99، EG030106STPG99، EG030301HAEE99، EG030401GB99،
IQ030403SENK99، IQ030501SDMN99، IQ030106MATT99، IQ030301SOBA99، IQ030106MONZ99، IQ030107MASA99، IQ030104STAN99

شكرًا.

---

## Notes for us (where each answer goes)
| Q | Decides | Recorded in |
| --- | --- | --- |
| 1 | Whether our review holds stop an order before Taager (docs/RISK.md §5); still tested on staging (E4) whatever the answer | COUNTRY-RULES "Taager said"; then E4 |
| 2 | Whether the second phone, landmark and building reach the confirmation team; the fix (e.g. order note) is chosen on staging (E6), not before | STAGING.md §4.2 |
| 3 | `ym_stage_map`; `ym_status_sync_verified` only after E7 and E9 show the statuses arriving | wp option |
| 4-5 | How class C orders are placed and cancelled, and the class D order per country (docs/STAGING.md §4) | STAGING.md |
| 6-8 | Customer text about the call, the parcel, the invoice and returns (today it names no one); confirmed on E8 | copy/<cc>.json after E8 |
| 9-10 | Iraq refusal and return charges, return window, delivery days (Iraq ads stay closed until answered in writing) | COUNTRY-RULES, PRICING |
| 11 | Iraq stock per offer (every Iraq offer needs an answer before ads) | stock checks (`wp ym check --stock`) |
| 12 | Payout route and fees (O9) | docs/COSTS.md |
| 13 | Gulf economics only; Saudi Arabia, UAE and Oman stay closed whatever the answer | PRICING |
| 14 | Catalog gate (identity, specs, media rights): O6; the mats sizes and rack width drive the fit text | `wp ym check`, `wp ym media` |
