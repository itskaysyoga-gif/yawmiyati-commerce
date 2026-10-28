# Questions for Taager, round 4 (draft, 28 Oct 2026, late)

Send after round 3, or merge the two. Arabic text to send; English notes are for us.

---

السلام عليكم،
عندي أسئلة إضافية قبل ما أفتح متجري:

1. إضافة WooCommerce: الطلب بيتبعت لتاجر إمتى بالظبط؟ لو الطلب حالته "قيد الانتظار" (on-hold) هل بيتبعت ولا بيستنى لما أغيّره لـ "قيد التنفيذ"؟
2. حالات الطلب اللي بترجع لمتجري: إيه أسماء الحالات بالظبط (تم التأكيد، تم الشحن، تم التوصيل، مرفوض، مرتجع)؟
3. السعودية والإمارات وعمان: هل في رسوم بتتخصم مني لو العميلة رفضت الطلب عند الاستلام؟ وكام؟ وهل في رسوم لو رجّعت المنتج بعد الاستلام؟
4. العراق: ممكن تبعتولي كتابةً إن مفيش خصم عند الرفض؟ وهل في رسوم على المرتجع بعد الاستلام؟
5. ضريبة القيمة المضافة في السعودية (15% على الربح الزيادة): هل بتنطبق عليّ وأنا مش مقيم في السعودية؟
6. المخزون في العراق: ملف المنتجات مفيهوش أي بيانات مخزون للعراق. إزاي أعرف المخزون المتاح لكل منتج؟
7. رسوم سحب الأرباح: في رسوم على السحب لحساب بنكي أو Payoneer؟ كام؟

شكرًا.

---

Notes for us
- Q1 decides whether our review holds really stop an order before it reaches Taager (docs/RISK.md §5). Confirm on
  staging with a deliberately held test order whatever the answer.
- Q2 fills `ym_stage_map`; `ym_status_sync_verified` is set only after a real order shows each status arriving.
- Q3-Q5, Q7 fill the unknowns in docs/PRICING.md; until then Saudi, UAE and Oman offers stay "undecided".
- Q6: without stock data, every Iraq offer needs an account-manager answer before ads.
