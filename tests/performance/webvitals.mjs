// Lab measurement of Core Web Vitals and transfer sizes in Chromium
// (Playwright from tests/browser) against the simulation stack. Lab data
// only: it diagnoses, it is not field (real-user) data.
//
//   node tests/performance/webvitals.mjs OUT.json [runs]
//
// Profile: mobile viewport 412 x 915, CPU slowed 4x, network about "4G"
// (9 Mbit/s down, 1.5 Mbit/s up, 60 ms latency), cold cache per run.
// LCP, CLS, FCP and total blocking time (TBT, long tasks over 50 ms) come from
// the page's own PerformanceObserver; INP is approximated by the longest
// event duration of real clicks and key presses on the page (Event Timing API).
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const require = createRequire(path.join(here, '..', 'browser', 'package.json'));
const { chromium } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'https://localhost:8443';
const out = process.argv[2] || 'webvitals.json';
const runs = Number(process.argv[3] || 5);

const pages = [
    { name: 'home', path: '/', interact: async (p) => { await p.locator('input[type=search], input[name=q]').first().click(); await p.keyboard.type('font'); } },
    { name: 'catalog', path: '/products', interact: async (p) => { await p.locator('select[name=sort]').selectOption('price_asc'); } },
    { name: 'search', path: '/products?q=font', interact: async (p) => { await p.locator('input[name=q]').first().click(); await p.keyboard.type(' pack'); } },
    { name: 'product', path: null, interact: async (p) => { await p.locator('button, a').first().focus(); await p.keyboard.press('Tab'); } },
];

const observer = () => {
    window.__vitals = { lcp: 0, cls: 0, tbt: 0, fcp: 0, inp: 0, lcpElement: '' };
    new PerformanceObserver((l) => { for (const e of l.getEntries()) { window.__vitals.lcp = e.startTime; window.__vitals.lcpElement = e.element ? e.element.tagName + (e.element.className ? '.' + e.element.className : '') : (e.url || ''); } })
        .observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) { if (!e.hadRecentInput) window.__vitals.cls += e.value; } })
        .observe({ type: 'layout-shift', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) { window.__vitals.tbt += Math.max(0, e.duration - 50); } })
        .observe({ type: 'longtask', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) { if (e.name === 'first-contentful-paint') window.__vitals.fcp = e.startTime; } })
        .observe({ type: 'paint', buffered: true });
    new PerformanceObserver((l) => { for (const e of l.getEntries()) { if (e.interactionId) window.__vitals.inp = Math.max(window.__vitals.inp, e.duration); } })
        .observe({ type: 'event', buffered: true, durationThreshold: 16 });
};

const median = (a) => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };

const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });
const results = [];
let productPath = null;
for (const page of pages) {
    const samples = [];
    for (let i = 0; i < runs; i++) {
        const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 412, height: 915 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
        await context.addInitScript(observer);
        const p = await context.newPage();
        const cdp = await context.newCDPSession(p);
        await cdp.send('Network.enable');
        await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 60, downloadThroughput: 9 * 1024 * 1024 / 8, uploadThroughput: 1.5 * 1024 * 1024 / 8 });
        await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
        let bytes = 0;
        let requests = 0;
        cdp.on('Network.loadingFinished', (e) => { bytes += e.encodedDataLength; requests++; });
        let target = page.path;
        if (target === null) {
            if (productPath === null) {
                await p.goto(BASE + '/products', { waitUntil: 'load' });
                productPath = new URL(await p.locator('.grid a').first().getAttribute('href'), BASE).pathname;
                bytes = 0; requests = 0;
            }
            target = productPath;
        }
        await p.goto(BASE + target, { waitUntil: 'load' });
        await p.waitForTimeout(1500);
        await page.interact(p).catch(() => {});
        await p.waitForTimeout(800);
        // LCP is final once the user interacts; read the values now.
        const v = await p.evaluate(() => window.__vitals);
        const nav = await p.evaluate(() => { const n = performance.getEntriesByType('navigation')[0]; return { ttfb: n.responseStart, html: n.encodedBodySize, domNodes: document.getElementsByTagName('*').length }; });
        const images = await p.evaluate(() => [...document.images].map((im) => ({ src: im.currentSrc, loading: im.loading, w: im.width, h: im.height, natural: im.naturalWidth, inViewport: im.getBoundingClientRect().top < innerHeight })));
        samples.push({ ...v, ...nav, bytes, requests, images });
        await context.close();
    }
    const pick = (k) => Math.round(median(samples.map((s) => s[k])) * 1000) / 1000;
    const r = { name: page.name, path: page.path ?? productPath, runs, lcp_ms: pick('lcp'), cls: pick('cls'), tbt_ms: pick('tbt'), fcp_ms: pick('fcp'), inp_ms_lab: pick('inp'),
        ttfb_ms: pick('ttfb'), html_bytes: pick('html'), transfer_bytes: pick('bytes'), requests: pick('requests'), dom_nodes: pick('domNodes'),
        lcp_element: samples[samples.length - 1].lcpElement, images_above_fold_lazy: samples[samples.length - 1].images.filter((im) => im.inViewport && im.loading === 'lazy').length,
        images: samples[samples.length - 1].images.length };
    results.push(r);
    console.log(JSON.stringify(r));
}
await browser.close();
fs.writeFileSync(out, JSON.stringify({ generated: new Date().toISOString(), base: BASE, profile: 'mobile 412x915 DPR2, CPU 4x slower, 9 Mbit/s / 60 ms, cold cache', results }, null, 1));
