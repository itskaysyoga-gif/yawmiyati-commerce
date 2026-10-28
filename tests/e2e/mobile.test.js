// Phone browsers: the full product-to-order flow on an emulated Android phone (Chromium) and an iPhone (WebKit,
// Safari's engine). WebKit runs in CI; it is skipped locally when the WebKit browser is not installed.
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium, webkit, devices } = require('playwright');
const h = require('./helpers');

const hasWebKit = (() => { try { return fs.existsSync(webkit.executablePath()); } catch { return false; } })();

async function flow(engine, device, cc, slug, offer, total) {
  const browser = await engine.launch();
  try {
    const { ctx, page } = await h.context(browser, { device: devices[device], consent: null });
    await page.goto(`${h.BASE[cc]}/product/${slug}/`);
    // First visit: the consent banner is shown and can be answered with a tap.
    await page.locator('[data-ym-consent-no]').tap();
    assert.equal(await page.locator('[data-ym-consent]').isVisible(), false);
    // The sticky order bar appears on phones once the main order button is scrolled off screen, and follows the
    // chosen offer.
    if (offer) await page.locator('.ym-offer').nth(offer).tap();
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.locator('[data-ym-sticky]').waitFor({ state: 'visible' });
    const sticky = await page.locator('.ym-sticky__price').innerText();
    assert.equal(sticky, await page.locator('.ym-offer input:checked ~ .ym-offer__price').innerText());
    await Promise.all([page.waitForURL(/\/checkout\//), page.locator('[data-ym-sticky-buy]').tap()]);
    // No horizontal scrolling on the checkout.
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await h.fillCheckout(page, { ...h.CUSTOMER[cc], phone: h.phone[cc]() });
    await Promise.all([page.waitForURL(/order-received\/(\d+)/, { timeout: 30000 }), page.locator('#place_order').tap()]);
    const id = Number(page.url().match(/order-received\/(\d+)/)[1]);
    assert.equal(h.order(cc, id).total, total);
    await ctx.close();
  } finally {
    await browser.close();
  }
}

test('Android phone (Chromium, Pixel 7): Egypt 2-set offer ordered from the sticky bar', () => flow(chromium, 'Pixel 7', 'eg', 'absorbent-mats', 1, 749 + 95));

test('iPhone (WebKit, iPhone 13): Iraq rack + mats bundle ordered from the sticky bar', { skip: hasWebKit ? false : 'WebKit not installed here; runs in CI' }, () => flow(webkit, 'iPhone 13', 'iq', 'sink-rack', 1, 65000));
