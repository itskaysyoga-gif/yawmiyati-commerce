/* Yawmiyati product page: gallery thumbnails, offer price, sticky order button. Works without JS too. */
(function () {
  var pdp = document.querySelector('[data-ym-pdp]');
  if (!pdp) return;

  var main = pdp.querySelector('[data-ym-main]');
  pdp.querySelectorAll('[data-ym-thumb]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (main) main.src = btn.getAttribute('data-ym-thumb');
      pdp.querySelectorAll('[data-ym-thumb]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
    });
  });

  var form = pdp.querySelector('[data-ym-offers]');
  function syncPrice() {
    var checked = form && form.querySelector('input[name="ym_offer"]:checked');
    if (!checked || !checked.dataset.ymPriceText) return;
    pdp.querySelectorAll('[data-ym-price]').forEach(function (el) { el.textContent = checked.dataset.ymPriceText; });
  }
  if (form) {
    form.addEventListener('change', syncPrice);
    form.addEventListener('submit', function (e) {
      if (form.dataset.ymSending) { e.preventDefault(); return; }
      form.dataset.ymSending = '1';
    });
  }
  syncPrice();

  var stickyBuy = pdp.querySelector('[data-ym-sticky-buy]');
  if (stickyBuy && form) {
    stickyBuy.addEventListener('click', function () {
      var b = form.querySelector('button[value="buy"]');
      if (b) b.click();
    });
  }
})();
