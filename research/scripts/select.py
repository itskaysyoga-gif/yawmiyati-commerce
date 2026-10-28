"""Build the product-selection record from the Taager catalog export.

Usage:  python3 research/scripts/select.py [repo_root] [benchmarks_csv] [workbook_xlsx]
Writes: research/selection/offers.csv     shortlist + existing launch-set rows (one row per country offer)
        research/selection/pool.csv       every in-scope, scored candidate after dedupe (audit)
        research/selection/excluded.csv   every catalog row that was filtered out, with the rule that removed it

Every filter and score is a named rule below (docs/PRODUCT-SELECTION.md explains them). No score uses demand,
delivery rates, stock counts or competitor prices that are not in the input files.

Catalog columns (verified on 5,249/5,249 rows and on all 27 SKUs in launch_assortment_v2.csv):
  original_price = Taager's price to the merchant (cost)      -> launch_v2.taager_cost
  selling_price  = Taager's minimum selling price              -> launch_v2.taager_min
  profit         = selling_price - original_price (minimum profit per delivered unit)
"""
from __future__ import annotations

import sys
import os

# This file is named select.py (as requested), which shadows the stdlib module `select` when run from its own
# directory. Drop the script directory from sys.path before importing anything that may need stdlib `select`.
_HERE = os.path.dirname(os.path.abspath(__file__))
sys.path[:] = [p for p in sys.path if os.path.abspath(p or os.getcwd()) != _HERE]

import csv  # noqa: E402
import importlib.util  # noqa: E402
import math  # noqa: E402
import re  # noqa: E402
from collections import defaultdict  # noqa: E402
from pathlib import Path  # noqa: E402

import pandas as pd  # noqa: E402

_spec = importlib.util.spec_from_file_location("economics", os.path.join(_HERE, "economics.py"))
ec = importlib.util.module_from_spec(_spec)
sys.modules["economics"] = ec
_spec.loader.exec_module(ec)

ROOT = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[2]
ATT = Path("/mnt/user-data/attachments/fb96a5a5-22d3-5ca1-bc55-75df3cefb507")
BENCH = Path(sys.argv[2]) if len(sys.argv) > 2 else ATT / "aaadcc68-research_price_benchmarks.csv"
WORKBOOK = Path(sys.argv[3]) if len(sys.argv) > 3 else ATT / "905beed8-yawmiyati_pricing_v3_taager_mechanics.xlsx"
DATA = ROOT / "research" / "data"
OUT = ROOT / "research" / "selection"

CATALOG_SRC = "taager_catalog_export.csv (capture date unknown, before 2026-10-28; latest created_at 2026-09-15)"
STOCK_CHECKED = "unknown (export captured before 2026-10-28; exact date unknown)"
BENCH_SRC = "research_price_benchmarks.csv, archetype ranges (BATCH-{b}); SIMILAR items, not identical"
WB_SRC = "pricing workbook v3 archetype mapping (workbook said)"
COMP_SRC = "research/data/benchmarks_{cc}_v2.csv from competitors_{cc}.csv, captured 2026-10-28"
COUNTRY_NAME = {"EGY": "Egypt", "IRQ": "Iraq", "SAU": "Saudi Arabia", "ARE": "UAE", "OMN": "Oman"}
SHORTLIST_MAX = 40
FIRST_WAVE_N = 5
GENERIC_HEADS = {"طقم", "عرض", "جهاز", "ماكينه", "مجموعه", "حامل", "منظم"}
SHORTLIST_MIN_SCORE = {"EGY": 60, "IRQ": 60,      # below this an offer is not shortlisted, whatever the count.
                       "SAU": 65, "ARE": 65, "OMN": 65}  # Gulf bar is higher: refusal charge unknown, small audience
FX_NOTE = "not used: scores are ranked within a country only"


# ---------------------------------------------------------------- text normalisation
def norm(s: str) -> str:
    s = str(s).lower()
    s = re.sub(r"[ـً-ْ]", "", s)                    # tatweel, harakat
    s = re.sub("[أإآ]", "ا", s).replace("ة", "ه").replace("ى", "ي")
    s = re.sub(r"[•\"\t()\[\]|،,:\-–_/+*×]", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def fold(s: str) -> str:
    """Letter folding only (keeps regex syntax intact) - used for patterns."""
    s = str(s).lower()
    s = re.sub("[أإآ]", "ا", s).replace("ة", "ه").replace("ى", "ي")
    return s


def rx(*parts: str) -> re.Pattern:
    """Patterns are substrings, except those starting with '=' which must be a whole word
    (optionally prefixed by ال / و / ب / لل / ل). Whole-word matching avoids e.g. 'الام' (pain) matching
    'الامتصاص' (absorbent) or 'درج' (drawer) matching 'درجه' (degree)."""
    out = []
    for p in parts:
        if p.startswith("="):
            out.append(r"(?<!\S)(?:ال|و|ب|لل|ل)?(?:" + fold(p[1:]) + r")(?!\S)")
        else:
            out.append(fold(p))
    return re.compile("|".join(out))


# ---------------------------------------------------------------- exclusion rules (first match wins)
# Each rule: (code, reason, pattern). Applied to the normalised Arabic/Latin title.
EXCLUDE = [
    ("warranty", "warranty SKU, not a product", rx("ضمان \\d+ (شهر|شهور|اشهر)", "ضمان اضافي")),
    ("medical", "medical/health device or claim", rx(
        "مساج", "تدليك", "علاج", "=الام", "الآلام", "=الم", "مسكن", "مفاصل", "فقرات", "بواسير", "شرج", "قياس الضغط", "ضغط الدم",
        "السكر في الدم", "الاكسجين", "حجامه", "عكاز", "عصا مشي", "=طبي", "=طبيه", "تنفس", "استنشاق", "مصحح", "تصحيح",
        "دعامه", "مشد", "ركبه", "ساونا", "تخسيس", "تنحيف", "نحت", "العلاج الطبيعي", "الشخير", "الاسنان",
        "=اسنان", "=ميزان", "تعقيم", "معقم", "منظم دواء", "اعاده التاهيل", "=القدم", "=قدم", "=ساق")),
    ("cosmetic", "cosmetic, skin, hair or body-care product or claim", rx(
        "كريم", "سيروم", "سيرم", "serum", "cream", "شامبو", "بلسم", "صبغ", "صبغه", "بشره", "تجاعيد", "بوتوكس",
        "botox", "كولاجين", "ريتين", "ريتون", "تفتيح", "ميك اب", "مكياج", "رموش", "حواجب", "اظافر", "شفاه", "لحيه",
        "حلاقه", "ازاله الشعر", "شعر", "عطر", "صابون", "ماسك", "قناع", "واقي شمس", "sun", "مزيل الجلد", "الكلح",
        "الشامه", "زيت \\d+", "brush cleaner", "ميسيلار", "التمليس", "تمليس", "ايونات", "الايونات", "سشوار", "توبيك", "toppik", "شاور", "فرشاه الاستحمام", "فرشاه تنظيف الجسم", "الجسم",
        "الوجه", "مبرد العنايه", "فرش الميك")),
    ("supplement", "supplement or ingestible claim", rx("مكمل", "فيتامين", "كبسول", "شراب")),
    ("weapon", "weapon or weapon-like item", rx("سيف", "مسدس", "رشاش", "صاعق كهربائي للدفاع", "ليزر قابل للشحن",
                                               "laser pointer", "فاس")),
    ("adult", "adult item", rx("جنسي", "sex", "مثير", "لانجري")),
    ("baby_kids", "baby/kids item (child-safety verification; outside the home first wave)", rx(
        "اطفال", "للاطفال", "الاطفال", "طفل", "الطفل", "رضع", "الرضع", "رضيع", "رضاعه", "بيبي", "baby", "بورت",
        "دميه", "لعبه", "=العاب", "بابا نويل", "نطاطه", "ترامبولين", "لابوبو", "labubu", "=كور", "حصاله", "سكوتر",
        "اولادي", "بناتي", "=الوان", "مقلمه",
        "دراجه", "دباب", "بوتي", "ستيتش", "البلياتشو", "شيخ العرب", "العروسه", "روبوت متتبع", "الحليب")),
    ("electronics", "phone/computer/audio/camera electronics (outside home scope)", rx(
        "تابلت", "tablet", "ايباد", "هاتف", "موبايل", "موبيل", "جوال", "سماعه", "ايربودز", "airpods", "earbuds",
        "ساعه ذكيه", "smart watch", "سمارت واتش", "ساعه بديله", "كاميرا", "كاميره", "داش كام", "بروجكتور",
        "بروجيكتور", "باور بانك", "باوربانك", "power bank", "شاحن", "راوتر", "router", "هارد", "ssd", "سبيكر",
        "بلاستيشن", "ماوس", "كيبورد", "ووكي", "وكي توكي", "wifi", "box x8", "game tv", "طابعه", "printer",
        "جهاز تتبع", "gps", "اليكسا", "مقبس", "قلم قراءه", "قلم الترجمه", "خاتم", "اداه تصوير", "رينج لايت",
        "ترايبود", "projector", "جيمنج", "قارئ كروت", "لاب توب", "لابتوب", "راديو", "مكبر صوت")),
    ("car", "car/motorbike accessory", rx("سياره", "السياره", "للسيارات", "للسياره", "موتوسيكل", "موتسكل",
                                          "الموتوسيكل", "اطارات", "الاطارات", "كفرات", "دريكسيون", "عادم", "فتيس",
                                          "=car", "جامب ستارتر", "jump starter", "رافعه", "مسند يد", "مضخه فوم",
                                          "مسدس الغسيل", "مسدس غسيل")),
    ("tools_diy", "tools / DIY / repair (outside home scope)", rx(
        "شنيور", "دريل", "مفك", "لحام", "=لقم", "=بنط", "كماشه", "صاروخ", "منشار", "ايبوكسي", "اللاصق", "لاصق",
        "مسامير", "دوكو", "طلاء", "دهان", "=عده", "عازل المياه", "المياه الحديث", "اصلاح", "tile reform",
        "معالج البلاط", "معجون البلاط", "ملء فراغات", "بلاور", "طارد الهواء", "تشذيب صوف", "مقص تقليم",
        "كمبروسر", "كومبريسور", "منفاخ", "مضخه هواء", "ركبه الشغل", "السلك الحراري", "قلم كشف", "مولد",
        "محطه الطاقه", "عاكس", "خلايا شمسيه", "قلم المهام", "عصا قابله للطي")),
    ("outdoor_sport", "fitness / sport / outdoor / camping", rx(
        "تمارين", "تمرين", "=جيم", "ملاكمه", "رياضي", "رياضيه", "=صيد", "خيمه", "تخييم", "رحلات",
        "الرحلات", "مسبح", "سباحه", "عوامه", "شمسيه", "شاطئ", "شازلونج", "بيكل", "بينج بونج", "بنج بونج", "جولف",
        "يوغا", "اليوجا", "احزمه مقاومه", "احبال المقاومه", "سفاري", "الحديقه", "حدائق", "عقله", "الطلعات", "بدال")),
    ("fashion", "fashion / bags / watches", rx("حجاب", "فستان", "بنطلون", "تيشيرت", "جينز", "وشاح", "ساعه رجالي",
                                              "watch", "شنطه ظهر", "جنطه", "نظاره", "اسوره", "حامي الحذاء")),
    ("religious_gift", "religious / gift / seasonal-Ramadan item (owner decision, not in home first wave)", rx(
        "صلاه", "مصليه", "سبحه", "القران", "قران", "رمضان", "هلال", "فانوس", "فنانيس", "الكريسماس", "هدايا",
        "=بوكس", "تهادوا", "مبخره", "=جامع", "الذكر", "التسبيح", "التحصين", "نخله")),
    ("pets", "pet item", rx("الحيوانات", "للحيوانات", "الكلاب")),
    ("industrial", "large appliance / furniture / industrial (delivery and install risk)", rx(
        "غساله مكس توب", "مبرد صحراوي", "تكييف صحراوي", "مرتبه التطريه", "مرتبه bestway", "كرسي استراحه",
        "سرير هوائي", "air bed", "كرسي القمر", "كرسي هوائي", "قنفه", "بين باج", "ثلاجه", "مكيف مقاعد", "فرن",
        "طاوله الكمبيوتر", "computer desk", "ترابيزه لاب", "حامل لابتوب", "حامل التلفاز", "مغسله متحركه",
        "قفل باب ذكي", "smart lock", "جرس باب", "روبوت التحكم", "مرحاض")),
]

# ---------------------------------------------------------------- home sub-categories (first match wins)
CATEGORIES = [
    ("kitchen_appliance", rx("غساله الفواكه", "غساله الخضروات")),   # override: not laundry
    ("home_textiles", rx("موكيت", "دفايه فرو", "بطانيه", "بطانيه قابله", "لحاف", "ملايه", "مفرش مضلع", "مفرش تخم",
                         "مفرش انتريه", "مفرش كورنر", "طقم كفر", "كفر كنبه", "كفر انتريه", "كفر ركنه", "كفر ظهر السرير",
                         "كفر لحاف", "كفر مرتبه", "واقي مرتبه", "مخده", "وساده", "كيس مخده", "كوشن", "رانر",
                         "ستاره ديجراديه", "مشابك تثبيت الملايات", "لحاف الكنبه", "كفر للكنبه", "انتريه")),
    ("laundry", rx("منشر", "ستاند ملابس", "مجفف ملابس", "مجفف الملابس", "مكواه", "مكواة", "كوايه", "اوتي", "وبر",
                   "ازاله الصوف", "الصوف", "بهتان الملابس", "غساله", "سله الملابس", "كيس غسيل", "بديل الكوي", "حوامل غساله",
                   "طاوله كي", "طاوله الكي", "مجفف احذيه", "تنظيف الاحذيه", "منظف الاحذيه", "lint", "washer",
                   "حقيبه تخزين الملابس", "منظم ملابس", "رف تجفيف الملابس")),
    ("bathroom", rx("حمام", "=دش", "شطاف", "شطافه", "سخان الحنفيه", "وصله حنفيه", "راس صنبور", "صنبور", "حنفيه",
                    "تسليك الاحواض", "حامل و معقم فرش", "فلتر مياه", "فلتر مياة", "فلتر تنقيه")),
    ("cleaning", rx("مساحه", "ممسحه", "مكنسه", "مكنسة", "تنظيف", "منظف", "ملمع", "اسبراي", "سبراي", "بخاخه",
                    "لمامه", "منفضه", "فرشاه", "الفرشاه", "جارف", "pink stuff", "مزيل الصدا", "مزيل البقع", "بقع",
                    "فوطه", "شفاط هواء", "x clean", "vacuum", "foam", "=فوم", "معجون تلميع", "=stain", "cleaner",
                    "mop", "بخار")),
    ("storage_organisation", rx("منظم", "=رف", "=رفوف", "ستاند", "جزامه", "دولاب", "خزانه", "كنتور", "تخزين", "=سله", "باسكت",
                                "حامل فويل", "ادراج", "ادارج", "=درج", "زاويه", "اكياس وشنط", "شنط السفر",
                                "الدولاب البلاستيك", "حامل", "عربه نقل", "قاعده متحركه", "storage")),
    ("kitchen_appliance", rx("خلاط", "مطحنه", "طاحونه", "مفرمه", "محضره", "محضر", "عصاره", "معصاره", "قلايه",
                             "فشار", "قهوه", "غلايه", "كاتيل", "صانعه", "صانع", "طباخ", "موقد", "بوتجاز", "شوايه",
                             "جريل", "وافل", "كريب", "قطايف", "ماكينه الحشو", "زبادي", "=جدر", "=قدر", "طاسه كهربائيه",
                             "مج كهربائي", "ماكينه تقشير", "ماكينه التقشير", "قطاعه كهربائيه", "القطاعه الكهربائيه",
                             "قطاعه الخضروات الحديثه الكهربائيه", "قطاعه وايرليس", "ديسبنسر مياه", "ديسبنسر كهربائي",
                             "ماكينه صنع", "ماكينه قهوه", "ماكينه القهوه", "صينيه تسخين", "حصيره تدفئه", "blender",
                             "chopper", "juice", "breakfast", "ice maker", "تغليف المواد", "غلق الاكياس",
                             "غالق الاكياس", "غزل البنات", "كبه", "instapot", "وعاء التسخين", "food packaging",
                             "بديل البوتاجاز", "مكينه القهوه", "مقلاه كهربائيه", "الخياطه", "اير فراير")),
    ("kitchen_tools", rx("قطاعه", "مقشره", "مبشره", "سكين", "سكينه", "سكاكين", "بورد التقطيع", "لوح تقطيع",
                         "ديسبنسر", "توابل", "بهارات", "اطباق", "معالق", "=حله", "مقلاه", "غطاء حله", "مصفاه", "=مج",
                         "كوب", "اكواب", "ترمس", "زجاجه", "حافظه", "غطاء حفظ", "مجفف الاطباق", "مجفف ومصفي",
                         "مفرش تجفيف", "تجفيف الاطباق", "تجفيف صحون", "مطبقيه", "رول مناديل", "ice cube", "جردل",
                         "طبق دوار", "مندولين", "ماندولين", "mandoline", "slicer", "مقص خضراوات", "لف الدولمه",
                         "لف الورق", "موزع الحبوب", "مقشره البطاطس", "وعاء غسيل", "رول شفاف", "عزبه الشاي",
                         "طقم ادوات مائده", "قطاعه بطاطس", "غطاء طاوله", "الحبوب")),
    ("home_comfort", rx("دفايه", "دفايه كهربائيه", "صوبه", "مروحه", "مروحة", "بانكه", "مبرد", "تكييف", "مكيف",
                        "مكييف", "ناموس", "ناموسيه", "حشرات", "الحشرات", "بعوض", "صاعق", "مانع الاتربه",
                        "غالق الشباك", "غالق شباك", "مغناطيس باب", "غالق الابواب", "فواحه", "معطر", "وناسه", "مبخره", "air cooler",
                        "cooling", "mosquito", "رذاذ", "بخاخ رذاذ", "عاكس هواء", "مؤشر مغناطيسي", "قياس قنينه الغاز",
                        "طارد", "مصيده", "نافذه مغناطيسيه", "غالق باب", "مفصله الباب", "الحمايه من الحرائق")),
    ("decor_lighting", rx("=ليد", "led", "اباجوره", "كشاف", "شمعه", "شموع", "ديكور", "استيكر", "ساعه حائط", "اضاءه",
                          "انوار", "=نور", "مصباح", "لمبه", "كلوب", "تمريه", "جيلي فيش", "كره كريستال", "sun catcher")),
]

# Rule B1: a similar-archetype benchmark is attached only when the workbook's archetype family fits the category.
ARCH_FAMILY = {
    "kitchen_tools": re.compile(r"^(KITT|GEN-KITT|HOME-0[2-4])"),
    "kitchen_appliance": re.compile(r"^(KITA|GEN-KITA)"),
    "cleaning": re.compile(r"^(CLEA|GEN-CLEA)"),
    "laundry": re.compile(r"^(CLEA-0[78]|HOME-0[1-3]|GEN-CLEA|GEN-HOME)"),
    "storage_organisation": re.compile(r"^(HOME|GEN-HOME)"),
    "bathroom": re.compile(r"^(GEN-BATH|HOME|CLEA)"),
    "home_textiles": re.compile(r"^(TEXT|GEN-TEXT|GEN-CLEA)"),
    "home_comfort": re.compile(r"^(COMF|GEN-COMF)"),
    "decor_lighting": re.compile(r"^(DECO|GEN-DECO)"),
}

AUDIENCE = {"kitchen_tools": 5, "storage_organisation": 5, "cleaning": 5, "laundry": 5, "bathroom": 5,
            "home_textiles": 4, "kitchen_appliance": 4, "home_comfort": 3, "decor_lighting": 2}
DEMO_DEFAULT = {"cleaning": 4, "laundry": 4, "storage_organisation": 4, "kitchen_tools": 4, "bathroom": 3,
                "kitchen_appliance": 4, "home_textiles": 3, "home_comfort": 3, "decor_lighting": 2}
DEMO_5 = rx("امتصاص", "مغناطيس", "دوار", "دواره", "للطي", "منظم", "غالق", "مساحه", "ممسحه", "لمامه", "وبر", "الصوف",
            "تجفيف", "مجفف", "منشر", "ستاند", "=رف", "قطاعه", "مندولين", "ماندولين", "مقشره", "تقشير", "ذاتيه العصر",
            "سهله العصر", "بخار", "منظف", "ملمع", "مزيل", "تسليك", "pink stuff", "طبق دوار", "ديسبنسر", "زاويه",
            "جزامه", "كنتور", "تخزين", "قابله للارتداء", "غلق الاكياس", "فشار", "عصاره")
DEMO_2 = rx("فواحه", "معطر", "شمعه", "شموع", "استيكر", "طارد الحشرات بالموجات", "بالموجات")
COMMODITY = rx("ملايه", "لحاف", "مخده", "وساده", "كفر", "اطباق", "معالق", "=مج", "كوب", "شمعه", "شموع", "جردل",
               "ستاره", "مفرش", "سكين", "سكاكين", "حله")
MECHANISM = rx("2 في 1", "3 في 1", "4 في 1", "5 في 1", "6 في 1", "متعدد", "للطي", "مغناطيس", "دوار", "امتصاص",
               "ذاتيه العصر", "سهله العصر", "قابله للارتداء", "360")

# ---------------------------------------------------------------- caution / risk rules
HEATING = rx("دفايه(?! فرو)", "دفايه كهربائيه", "صوبه", "سخان", "مكواه", "مكواة", "كوايه", "اوتي", "غلايه", "كاتيل",
             "قلايه", "طاسه كهربائيه", "جريل", "شوايه", "طباخ", "موقد", "بوتجاز", "وافل", "كريب", "فان هواء وتدفئه",
             "تدفئه", "بخار", "صانع الافطار", "breakfast", "مقلاه كهربائيه", "حصيره تدفئه", "صينيه تسخين", "ice maker",
             "صانعه الثلج", "صانعه تلج", "مجفف ملابس محمول", "مجفف الملابس السحري", "مجفف احذيه", "مجفف ملابس كهربائي")
WET_ELECTRIC = rx("مجفف كهربائي", "منشر غسيل ومجفف", "غساله قابله للطي", "foldable washer", "غساله السفر",
                  "غساله ملابس", "غساله مكس")
GAS = rx("غاز ودفايه", "موقد الغاز", "بالغاز", "بوتجاز")
GLASS_MAGNET = rx("مساحه زجاج مغناطيسيه", "ممسحه الزجاج")
INSTALL = rx("=دش", "شطاف", "حنفيه", "صنبور", "شفاط", "تركيب", "سخان الحنفيه", "فلتر", "مفصله", "كفر وحده تكييف",
             "غالق الشباك", "انبوبه", "زاويه حمام")
FIT = rx("كفر كنبه", "كفر انتريه", "كفر ركنه", "مفرش انتريه", "كفر ظهر السرير", "ملايه", "واقي مرتبه", "كفر مرتبه",
         "كفر لحاف", "مفرش كورنر", "مانع ناموس للشباك", "ستاره مغناطيسيه", "كفر للكنبه", "غطاء حله")
CHEMICAL = rx("مبيد", "القضاء علي لحشرات", "القضاء علي الحشرات", "مسحوق تسليك", "مزيل الصدا", "منظف المكيف",
              "سبراي", "اسبراي", "معجون", "مسحوق", "foam", "=فوم", "\\d+ ?مل(?!\\S)", "منظف البقع")
EFFICACY = rx("طارد", "بالموجات", "الموجات فوق", "pest reject", "مانع الاتربه والغبار")
FRAGILE = rx("زجاج", "كريستال", "سيراميك", "اطباق", "مطبقيه", "بايركس")
BULKY = rx("دولاب", "جزامه", "كنتور", "خزانه", "ستاند ملابس", "منشر", "منظم 5 ادراج", "منظم تربو", "مجفف ملابس",
           "لحاف", "طاوله كي", "طاوله الكي", "عربه نقل")
SEASON_COLD = rx("دفايه", "صوبه", "بطانيه", "لحاف", "تدفئه", "دفايه فرو")
SEASON_HOT = rx("مروحه", "مروحة", "بانكه", "مبرد", "تكييف", "مكيف", "مكييف", "رذاذ", "cooling", "air cooler")
TREND = rx("الترند", "التريند", "العجيب", "السحري", "السحريه", "الجبار", "الرهيب", "trendy")
IMPORT_CLAIM = rx("مستورد", "اصلي", "الاصلي", "original", "semi original")
WATT = re.compile(r"(\d{3,5})\s*(?:واط|وات|watt|w\b)")

# Third-party brands seen in titles. Held until authenticity is verified (CLAUDE.md).
BRANDS = rx("تيفال", "tefal", "braun", "silver crest", "silvercrest", "سيلفر كريست", "سيلفر كرست", "=raf", "=راف", "=dsp", "denx", "دينكس", "dlc",
            "kumtel", "سونيفير", "=استانلي", "=stanley", "bestway", "total", "توتال", "ingco", "pink stuff", "olympia",
            "krawn", "krypton", "galaxy", "toppik", "shein", "porodo", "xiaomi", "شاومي", "ارشيا", "moog", "داش",
            "enzo", "bardefu", "gufy", "cyber", "بايركس", "snooze", "lazylap", "سوكاني", "sokany", "انزو", "kemei",
            "كيمي", "cosrx", "the ordinary", "joseon", "sadoer", "سادوير", "ماركه", "getsun", "honton", "=evia",
            "active max", "x clean", "x-clean", "wonder warm", "bright searchlight", "kuwajia",
            "mille", "fino", "eelhoe", "airtab", "ايرتاب", "dr. d", "swiss", "=city", "=داش")

NOISE = {"الحديث", "الحديثه", "الترند", "التريند", "الجديد", "cons", "new", "عام", "بضمان", "ضمان", "الشهير",
         "العجيب", "العجيبه", "السحري", "السحريه", "الجبار", "المطور", "المتطور", "العمليه", "العملي", "الفعال"}
COLORS = {"رمادي", "وردي", "اسود", "ابيض", "اوف", "وايت", "بيج", "بني", "كحل", "ازرق", "زيتي", "كشمير", "كافيه",
          "رصاصي", "اخضر", "احمر", "فاتح", "غامق", "رومادي", "بينك"}


def tokens(name: str) -> list[str]:
    out = []
    for t in norm(name).split():
        if t in NOISE or t in COLORS or re.fullmatch(r"c\d+|\d+c\d+", t):
            continue
        if t.startswith("ال") and len(t) > 4:
            t = t[2:]
        out.append(t)
    return out


def group_key(name: str) -> str:
    return " ".join(tokens(name))


def jaccard(a: list[str], b: list[str]) -> float:
    a, b = set(a), set(b)
    return len(a & b) / len(a | b) if a | b else 0.0


MULTI = re.compile(fold(r"^(?:عرض\s+)?(قطعتين|2\s*قطع[ةه]?|حبتين|3\s*قطع|ثلاث\s*قطع|ثلاثه\s*قطع|4\s*قطع|اربع\s*قطع"
                        r"|5\s*قطع|عشر\s*قطع)\s+(?:من\s+)?(.+)$"))
UNITS = {"قطعتين": 2, "حبتين": 2, "2": 2, "3": 3, "ثلاث": 3, "ثلاثه": 3, "4": 4, "اربع": 4, "5": 5, "عشر": 10}


def multi_unit(name: str):
    n = re.sub(r"^عرض\s+", "", norm(name))
    m = MULTI.match(n) or MULTI.match("عرض " + n)
    if not m:
        return None
    first = m.group(1).split()[0]
    first = re.sub(r"\D", "", first) or first
    return UNITS.get(first), m.group(2)


# ---------------------------------------------------------------- pricing rule
PRICE_STEP = "EGY: next price ending in 9; IRQ: next 1,000; SAU/ARE: next price ending in 9; OMN: next 0.500"


def charm_up(country: str, x: float) -> float:
    if country == "IRQ":
        return float(math.ceil(x / 1000.0) * 1000)
    if country == "OMN":
        return math.ceil(x * 2 - 1e-9) / 2.0
    n = math.ceil(x - 1e-9)
    while n % 10 != 9:
        n += 1
    return float(n)


def floor_price(country: str, cost: float, tmin: float) -> float:
    """Rule P1: lowest charm price >= max(Taager minimum, cost + one (highest) delivery fee)."""
    return charm_up(country, max(tmin, cost + ec.fee_range(country)[1]))


# ---------------------------------------------------------------- scoring rules (0-5 each)
STOCK_SCORE = {"more_than_1000": 5, "from_800_to_1000": 4, "from_600_to_800": 4, "from_400_to_600": 4,
               "from_200_to_400": 4, "from_100_to_200": 3, "from_50_to_100": 2, "less_than_50": 1}
WEIGHTS = {"expected_contribution": 30, "audience_fit": 20, "demonstration_quality": 15,
           "operational_risk_inverted": 15, "availability": 10, "differentiation": 10}
HIGH_SCRUTINY = {"EGY": 800, "IRQ": 60000, "SAU": 300, "ARE": 300, "OMN": 35}   # workbook said (Phase 0 research)


def availability(stock_range, detailed) -> int:
    if pd.isna(stock_range):
        return 1                              # not reported: kept, flagged, lowest positive score
    return STOCK_SCORE.get(str(detailed), 1)


def contribution_score(country: str, margin: float) -> int:
    """Rule C: margin per delivered order in units of the country's highest delivery fee.
    The refusal term is the same for every offer in a country, so this ranks identically to break-even CPA."""
    r = margin / ec.fee_range(country)[1]
    for t, s in ((3, 5), (2, 4), (1.5, 3), (1, 2), (0.5, 1)):
        if r >= t:
            return s
    return 0


# ---------------------------------------------------------------- loaders
def load_workbook_archetypes() -> dict:
    try:
        import openpyxl
    except ImportError:
        return {}
    if not WORKBOOK.exists():
        return {}
    wb = openpyxl.load_workbook(WORKBOOK, read_only=True, data_only=True)
    out = {}
    for sheet in ("Egypt", "Iraq", "Saudi Arabia", "UAE", "Oman", "REJECTED v3"):
        rows = list(wb[sheet].iter_rows(values_only=True))
        hdr = rows[2]
        i_sku, i_arch = hdr.index("SKU"), hdr.index("Archetype")
        for r in rows[3:]:
            if r[i_sku]:
                out[str(r[i_sku])] = str(r[i_arch])
    return out


def load_bench() -> dict:
    if not BENCH.exists():
        return {}
    b = pd.read_csv(BENCH)
    cmap = {v: k for k, v in COUNTRY_NAME.items()}
    return {(r.archetype_id, cmap[r.country]): r for r in b.itertuples() if r.country in cmap}


def load_launch() -> pd.DataFrame:
    return pd.read_csv(DATA / "launch_assortment_v2.csv")


def load_comp() -> dict:
    out = {}
    for cc, c in (("egy", "EGY"), ("irq", "IRQ")):
        p = DATA / f"benchmarks_{cc}_v2.csv"
        if p.exists():
            for r in pd.read_csv(p).itertuples():
                out[(c, r.slug)] = r
    return out


def load_media() -> dict:
    p = DATA / "media_checklist.csv"
    if not p.exists():
        return {}
    m = pd.read_csv(p)
    out = {}
    for sku, g in m.groupby("sku"):
        req = g[g.required == "yes"]
        missing = int((req.status == "missing").sum())
        interim = int(req.status.astype(str).str.contains("interim").sum())
        out[sku] = f"media_checklist.csv: {len(req)} required assets, {interim} interim crops, {missing} missing; fails gate"
    return out


# ---------------------------------------------------------------- main build
SEGMENT = re.compile(r"^(?:EG|IQ|SA|UA|OM)(0[12])")   # Taager SKU segment, inferred from the export:
SEGMENT_NAME = {"01": "electronics", "02": "fashion"}  # 01xx = phones/gadgets, 02xx = clothing (observed, not documented)


def classify(name: str, sku: str = ""):
    m = SEGMENT.match(str(sku))
    if m:
        return None, (f"sku_segment_{SEGMENT_NAME[m.group(1)]}",
                      f"Taager SKU segment {m.group(1)} ({SEGMENT_NAME[m.group(1)]}, inferred from export)"), None
    n = norm(name)
    cat = None
    for c, p in CATEGORIES:
        if p.search(n):
            cat = c
            break
    # home textiles/laundry/storage phrases that contain excluded words (e.g. "كيس مخدة ستان للشعر") are kept
    # in scope but flagged; everything else that hits an exclusion rule is excluded.
    for code, reason, p in EXCLUDE:
        if p.search(n):
            if code == "cosmetic" and cat in ("home_textiles",) and re.search(norm("مخده|وساده"), n):
                return cat, None, "title carries a hair/skin claim: do not repeat it"
            return cat, (code, reason), None
    if cat is None:
        return None, ("no_home_category", "no home/lifestyle sub-category matched the title"), None
    return cat, None, None


def flags_for(n: str, cat: str, price_total: float, country: str):
    caution, quality, refusal, risk = [], [], [], 0
    if HEATING.search(n):
        caution.append("electrical heating appliance: wattage, voltage, plug, thermal cut-off and warranty must be verified")
        risk += 2
    m = WATT.search(n)
    if m and int(m.group(1)) >= 1000:
        if "electrical" not in " ".join(caution):
            caution.append(f"high-wattage electrical ({m.group(1)} W in title): specs and safety must be verified")
            risk += 2
        if cat in ("kitchen_appliance",) and int(m.group(1)) >= 3000:
            quality.append(f"title claims {m.group(1)} W for a small appliance: implausible, treat as unverified")
    if WET_ELECTRIC.search(n) and not HEATING.search(n):
        caution.append("electrical appliance used with water/laundry: voltage, plug, motor rating and safety must be verified")
        risk += 2
    if GAS.search(n):
        caution.append("gas appliance: safety certification and fittings must be verified")
        risk += 2
    if GLASS_MAGNET.search(n):
        caution.append("magnetic window cleaner: supported glass thickness must be verified and stated")
        risk += 1
    if INSTALL.search(n):
        caution.append("needs installation/fit (threads, fittings or mounting): compatibility must be verified")
        risk += 1
    if FIT.search(n):
        caution.append("size/fit item: exact dimensions and what it fits must be verified")
        risk += 1
        refusal.append("size mismatch after delivery")
    if CHEMICAL.search(n):
        caution.append("chemical product: ingredients, safety label and claims must be verified")
        risk += 1
    if EFFICACY.search(n):
        quality.append("efficacy claim (repellent/barrier) cannot be substantiated from the listing")
        risk += 1
    if BRANDS.search(n):
        caution.append("third-party brand in title: held until authenticity is verified")
        risk += 2
    if FRAGILE.search(n):
        refusal.append("breakage in transit")
        risk += 1
    if BULKY.search(n):
        refusal.append("large parcel: transit damage / assembly expectations")
        risk += 1
    if TREND.search(n):
        refusal.append("viral-gadget title: expectation gap at the door")
    if IMPORT_CLAIM.search(n):
        quality.append("origin/authenticity word in title ('مستورد'/'اصلي'): unverified, not to be repeated")
    if cat in ("kitchen_appliance", "home_comfort", "decor_lighting") and not HEATING.search(n):
        refusal.append("electrical: dead-on-arrival / not as expected")
    if price_total > HIGH_SCRUTINY[country]:
        refusal.append(f"customer total above the high-scrutiny line ({HIGH_SCRUTINY[country]}, workbook said)")
        risk += 1
    if country == "EGY":
        refusal.append("Egypt: parcel may not be opened before payment (Taager policy)")
    return caution, quality, refusal, min(risk, 5)


def main():
    cat_df = pd.read_csv(DATA / "taager_catalog_export.csv")
    assert ((cat_df.selling_price - cat_df.original_price - cat_df.profit).abs() < 0.01).all(), "column identity broke"
    launch = load_launch()
    comp, bench, arche, media = load_comp(), load_bench(), load_workbook_archetypes(), load_media()
    launch_by_sku = {r.taager_sku: r for r in launch.itertuples()}

    excluded, pool = [], []
    for r in cat_df.itertuples():
        base = {"country": r.country_code, "taager_product_id": r.product_id, "variant_id": r.variant_id,
                "taager_sku": r.sku, "name_ar": r.name, "type": r.type}
        lr = launch_by_sku.get(r.sku)
        cat, exc, claim_note = classify(r.name, r.sku)
        if lr is not None and exc is not None:
            exc = None     # existing launch-set rows are always kept with their status
            cat = cat or "storage_organisation"
        if exc is None and r.stock_range == "not_available":
            exc = ("stock_not_available", "stock_range = not_available in the export")
        if exc is None and r.type == "bundle" and lr is None and multi_unit(r.name) is None:
            exc = ("mixed_bundle", "bundle of different products: merchandising decision after singles are validated")
        if exc is None and r.profit <= 0 and lr is None:
            exc = ("no_minimum_profit", "Taager minimum price equals cost (profit 0): odd listing, excluded")
        if exc is not None:
            excluded.append({**base, "category": cat or "", "rule": exc[0], "reason": exc[1]})
            continue
        pool.append({**base, "category": cat, "cost": float(r.original_price), "tmin": float(r.selling_price),
                     "stock_range": r.stock_range, "detailed_stock": r.detailed_stock, "thumbnail": r.thumbnail,
                     "created_at": r.created_at, "claim_note": claim_note, "launch": lr})

    pool_df = pd.DataFrame(pool)

    # ---- multi-unit Taager SKUs attach to their single-unit product (structure c)
    multi = [p for p in pool if p["type"] == "bundle" and multi_unit(p["name_ar"])]
    singles = [p for p in pool if not (p["type"] == "bundle" and multi_unit(p["name_ar"]))]
    attach = defaultdict(list)
    for b in multi:
        units, rest = multi_unit(b["name_ar"])
        best, best_j = None, 0.0
        for s in singles:
            if s["country"] != b["country"]:
                continue
            j = jaccard(tokens(rest), tokens(s["name_ar"]))
            if j > best_j:
                best, best_j = s, j
        if best is not None and best_j >= 0.6:
            attach[best["taager_sku"]].append((b, units))
        elif b["launch"] is None:
            excluded.append({k: b[k] for k in ("country", "taager_product_id", "variant_id", "taager_sku", "name_ar", "type")}
                            | {"category": b["category"], "rule": "multi_unit_no_single",
                               "reason": "multi-unit SKU whose single-unit product is not in scope"})
    launch_bundles = [b for b in multi if b["launch"] is not None]

    # ---- dedupe: group singles by (country, product_id) and by normalised title
    groups = defaultdict(list)
    keymap = {}
    for s in singles:
        k = (s["country"], group_key(s["name_ar"]))
        pk = (s["country"], s["taager_product_id"])
        if pk in keymap:
            k = keymap[pk]
        keymap[pk] = k
        groups[k].append(s)

    # second pass: merge near-identical titles (same country and category, digits ignored, Jaccard >= 0.7)
    def soft(name):
        return [t for t in tokens(name) if not re.search(r"\d", t) and len(t) > 1]

    reps = list(groups.keys())
    parent = {k: k for k in reps}

    def find(k):
        while parent[k] != k:
            parent[k] = parent[parent[k]]
            k = parent[k]
        return k

    by_cc = defaultdict(list)
    for k in reps:
        by_cc[(k[0], groups[k][0]["category"])].append(k)
    for _, ks in by_cc.items():
        st = {k: soft(groups[k][0]["name_ar"]) for k in ks}
        for i, a in enumerate(ks):
            for b in ks[i + 1:]:
                if any(x["launch"] is not None for x in groups[a] + groups[b]):
                    continue          # launch-set rows keep their own identity
                if len(st[a]) >= 2 and jaccard(st[a], st[b]) >= 0.7:
                    parent[find(b)] = find(a)
    merged = defaultdict(list)
    for k in reps:
        merged[find(k)].extend(groups[k])
    groups = merged

    def best_of(g):
        return sorted(g, key=lambda s: (s["launch"] is None, -availability(s["stock_range"], s["detailed_stock"]),
                                        s["type"] != "product", s["cost"], s["tmin"]))

    offers = []
    for k, g in groups.items():
        ordered = best_of(g)
        offers.append((ordered[0], ordered[1:]))
    for b in launch_bundles:
        offers.append((b, []))

    rows = []
    for rep, alts in offers:
        country, name = rep["country"], rep["name_ar"]
        n = norm(name)
        lr = rep["launch"]
        cat = rep["category"]
        cost, tmin = rep["cost"], rep["tmin"]
        if lr is not None and not pd.isna(lr.our_price):
            price, price_rule = float(lr.our_price), "launch_assortment_v2 price (researched, identical/similar evidence)"
        else:
            price, price_rule = (floor_price(country, cost, tmin),
                                 "P1 floor: max(Taager min, cost + highest delivery fee), charm-rounded; "
                                 "a floor, not a market price: set from item-level comparisons at 'researched'")
        a = ec.structure_a(country, cost, tmin, price)
        margin = a["margin"]
        if margin <= 0:
            continue
        total_max = a["customer_total_max"]

        caution, quality, refusal, risk = flags_for(n, cat, total_max, country)
        if rep["claim_note"]:
            caution.append(rep["claim_note"])
        if pd.isna(rep["stock_range"]):
            risk = min(5, risk + 1)
        season = ""
        if SEASON_COLD.search(n):
            season = "winter item (in season Nov-Feb in EG/IQ)"
        elif SEASON_HOT.search(n):
            season = "summer item (off season Oct-Mar in EG/IQ)"

        # audience
        aud = AUDIENCE[cat]
        if season.startswith("summer") and country in ("EGY", "IRQ"):
            aud = max(0, aud - 1)
        if total_max > HIGH_SCRUTINY[country]:      # rule A2: above the high-scrutiny line (workbook said)
            aud = max(0, aud - 1)
        # demonstration
        demo = DEMO_DEFAULT[cat]
        if DEMO_5.search(n):
            demo = 5
        if DEMO_2.search(n):
            demo = min(demo, 2)
        # differentiation
        diff = 3
        if COMMODITY.search(n + " "):
            diff = 2
        if MECHANISM.search(n):
            diff += 1
        comp_txt, research_evidence = [], False
        slug = lr.slug if lr is not None else None
        cr = comp.get((country, slug)) if slug else None
        if cr is not None:
            research_evidence = True
            src = COMP_SRC.format(cc=country.lower()[:3] if country != "IRQ" else "irq")
            src = COMP_SRC.format(cc="egy" if country == "EGY" else "irq")
            if cr.identical_n:
                comp_txt.append(f"identical: n={cr.identical_n} (likely-matches {int(cr.identical_likely_n or 0)}), "
                                f"median {cr.identical_price_median:g}, delivery known for {cr.identical_delivery_known_n}"
                                + (f", delivered median {cr.identical_delivered_median:g}" if not pd.isna(cr.identical_delivered_median) else ""))
                if not pd.isna(cr.identical_delivered_median):
                    diff += 1 if total_max < cr.identical_delivered_median else -1
            if cr.similar_n:
                comp_txt.append(f"similar: n={cr.similar_n}, median {cr.similar_price_median:g}")
            comp_txt.append(f"source: {src}")
        arch = arche.get(rep["taager_sku"])
        br = bench.get((arch, country)) if arch else None
        if br is not None and not ARCH_FAMILY[cat].match(arch):
            comp_txt.append(f"workbook archetype {arch} not used: inconsistent with category {cat} "
                            "(workbook mapping is title-based and unreliable)")
            br = None
        price_pos = ""
        if br is not None and not pd.isna(br.median):
            lo = "" if pd.isna(br.low) else f"{br.low:g}"
            hi = "" if pd.isna(br.high) else f"{br.high:g}"
            comp_txt.append(f"similar-archetype {arch}: low {lo or '?'} / median {br.median:g} / high {hi or '?'} "
                            f"({BENCH_SRC.format(b=br.batch)}; archetype per {WB_SRC})")
            if not pd.isna(br.high) and price > br.high:
                diff -= 1
                price_pos = "product price above the similar-archetype high"
            elif price <= br.median:
                price_pos = "product price at/below the similar-archetype median"
            else:
                price_pos = "product price between similar-archetype median and high"
        diff = max(0, min(5, diff))
        avail = availability(rep["stock_range"], rep["detailed_stock"])
        contrib = contribution_score(country, margin)
        scores = {"audience_fit": aud, "differentiation": diff, "demonstration_quality": demo,
                  "availability": avail, "expected_contribution": contrib, "operational_risk": risk}
        total = (WEIGHTS["expected_contribution"] * contrib + WEIGHTS["audience_fit"] * aud
                 + WEIGHTS["demonstration_quality"] * demo + WEIGHTS["operational_risk_inverted"] * (5 - risk)
                 + WEIGHTS["availability"] * avail + WEIGHTS["differentiation"] * diff) / 5.0

        # stage
        if lr is not None:
            existing = str(lr.decision)
            stage = "researched"          # every launch_assortment_v2 row has item-level competitor research
        else:
            stage = "researched" if research_evidence else "candidate"
            existing = ""

        variants = []
        same_pid = pool_df[(pool_df.country == country) & (pool_df.taager_product_id == rep["taager_product_id"])]
        if len(same_pid) > 1:
            variants.append(f"{len(same_pid)} Taager variants of product {rep['taager_product_id']}: "
                            + "; ".join(same_pid.taager_sku))
        alt_txt = "; ".join(f"{x['taager_sku']} ({x['stock_range'] if not pd.isna(x['stock_range']) else 'stock n/r'}, "
                            f"cost {x['cost']:g}, min {x['tmin']:g})" for x in alts)
        att = sorted(attach.get(rep["taager_sku"], []), key=lambda bu: bu[1])
        multi_txt = "; ".join(f"{b['taager_sku']} = {u} units, cost {b['cost']:g}, min {b['tmin']:g}"
                              for b, u in attach.get(rep["taager_sku"], []))
        bundle_contents = ""
        if rep["type"] == "bundle":
            bundle_contents = f"per Taager title only: {name.strip()}"
        uncertainties = ["dimensions, materials and box contents not in export",
                         "stock and price must be re-checked in Taager (export date unknown)",
                         "no order history: confirmation/delivery/return rates unknown"]
        if not research_evidence:
            uncertainties.append("no item-level competitor evidence (identical or similar with URL)")
        if pd.isna(rep["stock_range"]):
            uncertainties.append("stock not reported in export")
        seg = re.match(r"^(?:EG|IQ|SA|UA|OM)?(\d{4})", rep["taager_sku"])
        seg = seg.group(1) if seg else ""
        if seg[:2] == "05":
            uncertainties.append(f"Taager SKU segment {seg} is a lifestyle/kids/outdoor segment, not home (03xx): "
                                 "item identity must be checked (e.g. a baby or camping version)")
        if not re.match(r"^(EG|IQ|SA|UA|OM)", rep["taager_sku"]):
            uncertainties.append("legacy SKU format without country prefix: confirm it is live in Taager")
        if ec.refusal_fee(country).value is None:
            uncertainties.append(f"{country} refusal/return charge not published: break-even not computable")
        if season:
            uncertainties.append(season)
        if lr is not None and isinstance(lr.benchmark_note, str):
            why = f"existing decision '{existing}' (launch_assortment_v2): {lr.benchmark_note}"
        else:
            top = sorted([(v, k) for k, v in scores.items() if k != "operational_risk"], reverse=True)[:3]
            why = (f"{cat.replace('_', ' ')}; strongest rules: " + ", ".join(f"{k}={v}" for v, k in top)
                   + f"; margin/delivery {ec.rnd(country, margin)} {ec.CURRENCY[country]} at the P1 floor")
        rows.append({
            "country": country, "stage": stage, "existing_status": existing, "supplier": "Taager",
            "taager_product_id": int(rep["taager_product_id"]), "taager_sku": rep["taager_sku"],
            "variant": int(rep["variant_id"]), "type": rep["type"], "name_ar": name.strip().lstrip("•").strip(),
            "category": cat, "currency": ec.CURRENCY[country],
            "taager_cost": cost, "taager_min_price": tmin, "price_source": CATALOG_SRC,
            "proposed_price": price, "price_rule": price_rule,
            "customer_delivery_fee": "/".join(f"{x:g}" for x in ec.DELIVERY_FEE[country]),
            "customer_total": f"{a['customer_total_min']:g}" if a["customer_total_min"] == total_max
            else f"{a['customer_total_min']:g}-{total_max:g}",
            "margin_per_delivery": ec.rnd(country, margin),
            "other_fees": ("refusal " + (f"{ec.refusal_fee(country).value:g} ({ec.refusal_fee(country).status})"
                                         if ec.refusal_fee(country).known else "unknown")
                           + "; return charge unknown; payout/withdrawal fee unknown"
                           + ("; Saudi VAT 15% on uplift above Taager minimum" if country == "SAU" else "")),
            "stock_status": (f"{rep['stock_range']} ({rep['detailed_stock']})" if not pd.isna(rep["stock_range"])
                             else ""),
            "stock_note": "" if not pd.isna(rep["stock_range"]) else "not reported in export (all Iraq rows, bundles)",
            "stock_source": "taager_catalog_export.csv",
            "stock_checked_at": STOCK_CHECKED,
            "dimensions": "", "dimensions_note": "unknown: not in export; required for the catalog gate",
            "variants": " | ".join(filter(None, variants + ([f"alternates (deduped): {alt_txt}"] if alt_txt else []))),
            "multi_unit_skus": multi_txt,
            "multi_sku": att[0][0]["taager_sku"] if att else "",
            "multi_units": att[0][1] if att else "",
            "multi_cost": att[0][0]["cost"] if att else "",
            "multi_min": att[0][0]["tmin"] if att else "",
            "bundle_contents": bundle_contents,
            "media_quality": media.get(rep["taager_sku"], "not assessed: only a Taager thumbnail URL in the export"),
            "media_rights": "Taager said supplier images may be used (support chat 2026-10-28); not in writing",
            "thumbnail": rep["thumbnail"],
            "confirmation_rate_evidence": "", "delivery_rate_evidence": "", "return_rate_evidence": "",
            "rate_evidence_note": "none: no order history (0 orders, no date range)",
            "competitor_comparisons": " || ".join(comp_txt),
            "price_position_vs_similar": price_pos,
            "quality_concerns": "; ".join(quality),
            "refusal_return_risks": "; ".join(dict.fromkeys(refusal)),
            "why_recommended": why,
            "uncertainties": "; ".join(uncertainties),
            "fit_install_safety_flag": "caution" if caution else "",
            "caution_reasons": "; ".join(caution),
            **{f"score_{k}": v for k, v in scores.items()},
            "score_total": round(total, 1),
        })

    res = pd.DataFrame(rows).sort_values(["country", "score_total"], ascending=[True, False])
    res["pool_rank"] = res.groupby("country").cumcount() + 1

    # ---- shortlist: launch-set rows always; others ranked, score >= threshold, max 40 per country
    keep = []
    res["existing_status"] = res["existing_status"].fillna("")
    for c, g in res.groupby("country"):
        fixed = g[g.existing_status != ""]
        rest = g[(g.existing_status == "") & (g.score_total >= SHORTLIST_MIN_SCORE[c])]
        room = max(0, SHORTLIST_MAX - int((fixed.existing_status != "reject").sum()))
        keep.append(pd.concat([fixed, rest.head(room)]))
    short = pd.concat(keep).sort_values(["country", "score_total"], ascending=[True, False]).copy()
    short = short.reset_index(drop=True)
    ranks = [""] * len(short)
    counter = defaultdict(int)
    for i, r in short.iterrows():
        if r.existing_status != "reject":
            counter[r.country] += 1
            ranks[i] = str(counter[r.country])
    short.insert(0, "rank", ranks)

    # ---- first-wave picks (rule W): new rows only, no caution flag, customer total under the high-scrutiny line,
    #      >= 100 units in the export where stock is reported (Iraq reports none), max 2 per category, 5 per country.
    short["first_wave_pick"] = ""
    short["rule_r_family"] = ""
    # Rule R: a new row whose head noun (first title word, generic words excluded) matches an existing research
    # reject in the same country inherits that reject's evidence as an uncertainty and is not a first-wave pick.
    rejected = defaultdict(list)
    for _, r in short[short.existing_status == "reject"].iterrows():
        rejected[r.country].append((r.category, tokens(r.name_ar)[0], r.taager_sku, r.why_recommended))
    for i, r in short.iterrows():
        if r.existing_status != "":
            continue
        head = tokens(r.name_ar)[0] if tokens(r.name_ar) else ""
        for cat_, h, sku, why_ in rejected[r.country]:
            if h == head and head not in GENERIC_HEADS:
                short.at[i, "uncertainties"] += f"; same product family as research reject {sku} ({why_.split(': ', 1)[-1]})"
                short.at[i, "rule_r_family"] = sku
    for c, g in short.groupby("country"):
        taken, per_cat = 0, defaultdict(int)
        for i, r in g.iterrows():
            if taken >= FIRST_WAVE_N or r.existing_status != "":
                continue
            tot = float(str(r.customer_total).split("-")[-1])
            stock_ok = r.score_availability >= 3 or (c == "IRQ")   # Iraq: the export reports no stock at all
            home_seg = re.match(r"^(?:EG|IQ|SA|UA|OM)03", r.taager_sku) is not None
            if r.fit_install_safety_flag == "caution" or tot > HIGH_SCRUTINY[c] or not stock_ok or not home_seg:
                continue
            if per_cat[r.category] >= 2:
                continue
            if isinstance(r.get("rule_r_family"), str) and r.get("rule_r_family"):
                continue
            per_cat[r.category] += 1
            taken += 1
            short.at[i, "first_wave_pick"] = "yes"

    OUT.mkdir(parents=True, exist_ok=True)
    cols = [c for c in short.columns if c not in ("pool_rank",)]
    order = ["rank"] + [c for c in cols if c != "rank"]
    short[order].to_csv(OUT / "offers.csv", index=False, quoting=csv.QUOTE_MINIMAL)
    res.to_csv(OUT / "pool.csv", index=False)
    pd.DataFrame(excluded).to_csv(OUT / "excluded.csv", index=False)

    print("catalog rows", len(cat_df), "| excluded", len(excluded), "| in-scope rows", len(pool),
          "| deduped offers", len(res))
    print(pd.DataFrame(excluded).groupby(["country", "rule"]).size().unstack(fill_value=0).to_string())
    print("\nshortlist per country / stage:")
    print(short.groupby(["country", "stage"]).size().unstack(fill_value=0).to_string())
    print("\npool score >= threshold per country:",
          res[res.score_total >= res.country.map(SHORTLIST_MIN_SCORE)].groupby("country").size().to_dict())


if __name__ == "__main__":
    main()
