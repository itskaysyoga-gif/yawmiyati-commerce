const { chromium, devices } = require('playwright');
const pages = [['EG home', 'http://localhost:8081/'], ['EG catalog', 'http://localhost:8081/shop/'], ['EG product', 'http://localhost:8081/product/absorbent-mats/'], ['IQ product', 'http://localhost:8082/product/sink-rack/'], ['OM product', 'http://localhost:8085/product/dish-rack/'], ['IQ checkout', 'http://localhost:8082/checkout/']];
(async () => {
  const b = await chromium.launch();
  const rows = [];
  for (const consent of ['denied', 'granted']) {
    for (const [name, url] of pages) {
      const runs = [];
      for (let i = 0; i < 3; i++) {
        const ctx = await b.newContext({ ...devices['Pixel 7'], locale: 'ar' });
        await ctx.addCookies([{ name: 'ym_consent', value: consent, url }]);
        await ctx.route('https://www.googletagmanager.com/**', (r) => r.fulfill({ status: 200, body: '' }));
        const page = await ctx.newPage();
        const cdp = await ctx.newCDPSession(page);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: 1.6 * 1024 * 1024 / 8, uploadThroughput: 750 * 1024 / 8 });
        await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
        await page.addInitScript(() => {
          window.__lcp = 0; window.__cls = 0; window.__inp = 0;
          new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__lcp = e.startTime; }).observe({ type: 'largest-contentful-paint', buffered: true });
          new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
          new PerformanceObserver((l) => { for (const e of l.getEntries()) if (e.interactionId) window.__inp = Math.max(window.__inp, e.duration); }).observe({ type: 'event', buffered: true, durationThreshold: 16 });
        });
        const t0 = Date.now();
        await page.goto(url, { waitUntil: 'load' });
        await page.waitForTimeout(800);
        if (await page.locator('[data-ym-qty="1"]').count()) { await page.locator('[data-ym-qty="1"]').tap(); }
        else if (await page.locator('.ym-chips a').count()) { await page.locator('.ym-country summary').tap(); }
        await page.waitForTimeout(400);
        const m = await page.evaluate(() => ({ lcp: window.__lcp, cls: window.__cls, inp: window.__inp, bytes: performance.getEntriesByType('resource').reduce((s, r) => s + (r.transferSize || 0), 0) + (performance.getEntriesByType('navigation')[0].transferSize || 0), reqs: performance.getEntriesByType('resource').length + 1, ttfb: performance.getEntriesByType('navigation')[0].responseStart }));
        runs.push(m);
        await ctx.close();
      }
      runs.sort((a, b) => a.lcp - b.lcp);
      const med = runs[1];
      rows.push({ consent, name, lcp_ms: Math.round(med.lcp), cls: med.cls.toFixed(3), inp_ms: Math.round(Math.max(...runs.map((r) => r.inp))), ttfb_ms: Math.round(med.ttfb), kb: Math.round(med.bytes / 1024), requests: med.reqs });
    }
  }
  console.table(rows);
  require('fs').writeFileSync(process.argv[2] || 'perf-lab.json', JSON.stringify(rows, null, 1));
  await b.close();
})();
