// Script rules shared by the generator, the validator, QA and the publisher (docs/CONTENT-STUDIO.md).
// Everything a viewer reads must come from the store's approved facts. The model writes words, never numbers:
// prices, fees and quantities are drawn by the templates from the facts export itself.

export const ANGLES = ['problem', 'fit', 'offer'];

// Claims the facts never support, fake urgency, testimonials and superlatives (Egyptian, Iraqi and standard forms).
export const BANNED = [
  'أفضل', 'الأفضل', 'رقم واحد', 'مضمون', 'ضمان', 'بضمان', 'طبي', 'علاج', 'يعالج', 'بكتيريا', 'جراثيم', 'يقتل',
  'لفترة محدودة', 'عرض محدود', 'الكمية محدودة', 'آخر قطع', 'آخر حبات', 'قبل ما يخلص', 'قبل لا يخلص', 'خصم', 'تخفيض',
  'مجاني', 'مجانا', 'مجانًا', 'ببلاش', 'أصلي', 'ماركة', 'مستورد', 'مستوردة', 'تقييم', 'تقييمات', 'آراء العملاء',
  'زبائن', 'زبوناتنا', 'عميلاتنا', 'الكل بيستخدم', 'الكل يستخدم', 'الأكثر مبيع', 'الأكثر مبيعًا', 'ترند', 'التريند',
  'مقاوم للماء', 'ضد الماء', 'يدوم للأبد', 'عمر', 'سنين', 'سنة ضمان',
];

const DIGITS = /[0-9٠-٩۰-۹]/;

export function check(brief, facts) {
  const errors = [];
  const keys = new Set(['benefit', 'fit', 'diary', 'name']);
  (facts.benefits || []).forEach((_, i) => keys.add(`benefits.${i}`));
  const text = (where, s, max) => {
    if (typeof s !== 'string' || !s.trim()) return errors.push(`${where}: empty`);
    if (DIGITS.test(s)) errors.push(`${where}: contains a number (numbers come from the facts, never from the script): ${s}`);
    for (const w of BANNED) if (s.includes(w)) errors.push(`${where}: banned wording "${w}": ${s}`);
    if (max && [...s].length > max) errors.push(`${where}: longer than ${max} characters (${[...s].length})`);
    for (const sp of facts.specs || []) if (sp.value === null && sp.label && s.includes(sp.label)) errors.push(`${where}: talks about "${sp.label}", which is unknown`);
  };
  if (!brief || !Array.isArray(brief.angles)) return ['no angles'];
  const ids = brief.angles.map((a) => a.id);
  for (const id of ANGLES) if (!ids.includes(id)) errors.push(`missing angle ${id}`);
  for (const a of brief.angles) {
    if (!Array.isArray(a.hooks) || a.hooks.length !== 2) errors.push(`${a.id}: needs exactly two hooks`);
    (a.hooks || []).forEach((h, i) => text(`${a.id}.hook${i + 1}`, h, 46));
    if (!Array.isArray(a.beats) || a.beats.length < 2 || a.beats.length > 3) errors.push(`${a.id}: needs 2-3 beats`);
    (a.beats || []).forEach((b, i) => {
      text(`${a.id}.beat${i + 1}`, b.line, 52);
      if (!keys.has(b.source)) errors.push(`${a.id}.beat${i + 1}: source "${b.source}" is not a fact key`);
    });
    text(`${a.id}.cta`, a.cta, 34);
    text(`${a.id}.caption`, a.caption, 600);
    text(`${a.id}.thumbnail`, a.thumbnail, 30);
  }
  if (!Array.isArray(brief.carousel) || brief.carousel.length < 3 || brief.carousel.length > 4) errors.push('carousel: needs 3-4 slides');
  (brief.carousel || []).forEach((s, i) => text(`carousel.${i + 1}`, s, 60));
  return errors;
}
