// One definition for generation, validation and budget routing. No API calls here.
export const markets = Object.freeze({
  eg: { currency: 'ج.م', dialect: 'Egyptian Arabic', scope: 'eg' },
  iq: { currency: 'د.ع', dialect: 'Iraqi (Baghdadi) Arabic', scope: 'iq' },
  sa: { currency: 'ر.س', dialect: 'warm, clear standard Arabic for Saudi Arabia', scope: 'gulf' },
  ae: { currency: 'د.إ', dialect: 'warm, clear standard Arabic for the UAE', scope: 'gulf' },
  om: { currency: 'ر.ع', dialect: 'warm, clear standard Arabic for Oman', scope: 'gulf' },
});
export function market(cc) {
  if (!Object.hasOwn(markets, cc)) throw new Error('Country must be eg, iq, sa, ae or om');
  return markets[cc];
}
export function otherDialect(cc) {
  market(cc);
  const egyptian = ['دلوقتي', 'عايزة', 'هتيجي', 'بتاع', 'علشان', 'إزاي'];
  const iraqi = ['هسّه', 'هسه', 'تكدرين', 'عدج', 'مالتج', 'شلون', 'ندز'];
  return cc === 'eg' ? iraqi : cc === 'iq' ? egyptian : [...egyptian, ...iraqi];
}
