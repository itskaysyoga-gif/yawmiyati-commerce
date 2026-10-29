# Questions for Taager, round 5 (draft, 29 Oct 2026): staging test orders

Send with rounds 3 and 4, or before the first test order. Arabic text to send; English notes are for us.

---

السلام عليكم،
قبل ما أعمل طلبات تجريبية من متجري على WooCommerce:

1. لو عملت طلب تجريبي وألغيته قبل الشحن، هل في أي رسوم أو خصم؟ وإيه أحسن طريقة أعلّم بيها الطلب إنه تجريبي؟
2. إضافة WooCommerce: هل بتبعت لتاجر رقم موبايل تاني للعميلة لو موجود في الطلب؟ وهل بتبعت "أقرب علامة مميزة" ورقم العمارة والدور؟ ولو لأ، فين نكتبهم علشان فريق التأكيد والمندوب يشوفوهم (ملاحظات الطلب مثلًا)؟
3. الاسم اللي بيظهر للعميلة في مكالمة التأكيد وعلى الشحنة والفاتورة: هل ممكن يكون "يومياتي"؟

شكرًا.

---

Notes for us
- Q1 decides how the E1-E9 / I1-I9 test orders in docs/STAGING.md are placed and cancelled.
- Q2: the checkout collects a second phone and a landmark because Taager's own advice asks for them; if the plugin
  does not carry them, the fix (for example copying them into the order note) is decided on staging, not before.
- Q3 decides the customer text about the confirmation call, the parcel and the invoice (currently names no one).
