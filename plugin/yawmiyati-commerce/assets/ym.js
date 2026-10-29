/* Yawmiyati product page: gallery, offer price, quantity, total with delivery, video on demand, sticky order
   button. Everything works without JS too (the form posts the offer and quantity; the server prices it). */
(function () {
  var pdp = document.querySelector('[data-ym-pdp]');
  if (!pdp) return;
  var money = {};
  try { money = JSON.parse(pdp.getAttribute('data-ym-money') || '{}'); } catch (e) { money = {}; }

  // Same format as the server: thousands with ",", the currency's decimals, trailing zeros trimmed (3.000 -> 3).
  function fmt(n) {
    var d = money.d || 0, s = Number(n).toFixed(d);
    if (d && /\.0+$/.test(s)) s = s.replace(/\.0+$/, '');
    var parts = s.split('.'); parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return parts.join('.');
  }

  var main = pdp.querySelector('[data-ym-main]');
  pdp.querySelectorAll('[data-ym-thumb]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (main) {
        main.removeAttribute('srcset');
        if (btn.dataset.ymSrcset) main.setAttribute('srcset', btn.dataset.ymSrcset);
        main.src = btn.getAttribute('data-ym-thumb');
      }
      pdp.querySelectorAll('[data-ym-thumb]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
    });
  });

  // Video: only the poster is loaded with the page; the file is requested when she presses play.
  pdp.querySelectorAll('[data-ym-video]').forEach(function (box) {
    var btn = box.querySelector('button');
    btn.addEventListener('click', function () {
      var v = document.createElement('video');
      v.src = box.getAttribute('data-src'); v.controls = true; v.autoplay = true; v.playsInline = true; v.preload = 'auto';
      box.replaceChildren(v);
      v.focus();
    });
  });

  var form = pdp.querySelector('[data-ym-offers]');
  var qty = pdp.querySelector('[data-ym-qty-input]');
  var total = pdp.querySelector('[data-ym-total]');
  function current() {
    var c = form && (form.querySelector('input[name="ym_offer"]:checked') || form.querySelector('input[name="ym_offer"][type="hidden"]'));
    return c;
  }
  function qtyValue() {
    if (!qty) return 1;
    var max = parseInt(qty.max, 10) || 1, n = parseInt(qty.value, 10);
    n = isNaN(n) ? 1 : Math.min(max, Math.max(1, n));
    qty.value = n;
    return n;
  }
  function sync() {
    var c = current();
    if (c && c.dataset.ymPriceText) pdp.querySelectorAll('[data-ym-price]').forEach(function (el) { el.textContent = c.dataset.ymPriceText; });
    if (total && c && c.dataset.ymPriceNum) {
      var sub = parseFloat(c.dataset.ymPriceNum) * qtyValue();
      total.textContent = money.min === money.max
        ? money.one.replace('%s', fmt(sub + money.min) + ' ' + money.sym)
        : money.range.replace('%1$s', fmt(sub + money.min)).replace('%2$s', fmt(sub + money.max) + ' ' + money.sym);
    }
  }
  pdp.querySelectorAll('[data-ym-qty]').forEach(function (b) {
    b.addEventListener('click', function () { if (qty) { qty.value = qtyValue() + parseInt(b.getAttribute('data-ym-qty'), 10); sync(); } });
  });
  if (qty) qty.addEventListener('input', sync);
  if (form) {
    form.addEventListener('change', sync);
    form.addEventListener('submit', function (e) {
      if (form.dataset.ymSending) { e.preventDefault(); return; }
      form.dataset.ymSending = '1';
    });
  }
  sync();

  // Sticky order bar: shown only while no part of the order form (offers, quantity, buttons) is on screen, so it never
  // covers the form itself.
  var sticky = pdp.querySelector('[data-ym-sticky]');
  var mainBuy = form && form.querySelector('button[value="buy"]');
  if (sticky && mainBuy) {
    sticky.querySelector('[data-ym-sticky-buy]').addEventListener('click', function () { mainBuy.click(); });
    if ('IntersectionObserver' in window) {
      sticky.hidden = true;
      var seen = new Map();
      new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { seen.set(e.target, e.isIntersecting); });
        var any = false;
        seen.forEach(function (v) { any = any || v; });
        sticky.hidden = any;
      }).observe(form);
    }
  }
})();
