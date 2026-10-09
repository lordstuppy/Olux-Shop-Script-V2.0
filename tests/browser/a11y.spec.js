// Accessibility checks with axe-core (needs JavaScript for the checker itself),
// plus explicit heading, label and focus-order assertions.
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const pages = require('./pages');
const { login, firstProductPath } = require('./helpers');

test.use({ javaScriptEnabled: true });

async function audit(page, path) {
    await page.goto(path);
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
    const summary = results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`);
    expect(summary, `axe violations on ${path}`).toEqual([]);

    // Headings: exactly one h1 and no skipped levels.
    const levels = await page.$$eval('h1, h2, h3, h4, h5, h6', (hs) => hs.map((h) => Number(h.tagName[1])));
    expect(levels.filter((l) => l === 1).length, `${path} h1 count`).toBe(1);
    for (let i = 1; i < levels.length; i++) {
        expect(levels[i] - levels[i - 1], `${path} heading jump`).toBeLessThanOrEqual(1);
    }

    // Every visible form control has an accessible label.
    const unlabeled = await page.$$eval('input:not([type=hidden]), select, textarea', (els) =>
        els.filter((el) => !(el.labels && el.labels.length) && !el.getAttribute('aria-label')).map((el) => el.name));
    expect(unlabeled, `${path} unlabeled controls`).toEqual([]);

    // Focus order follows the document: no positive tabindex, skip link first.
    const positive = await page.$$eval('[tabindex]', (els) => els.filter((e) => Number(e.getAttribute('tabindex')) > 0).length);
    expect(positive, `${path} positive tabindex`).toBe(0);
    await page.keyboard.press('Tab');
    expect(await page.evaluate(() => document.activeElement.className), `${path} first focus`).toContain('skip-link');
}

test('guest pages are accessible', async ({ page }) => {
    const product = await firstProductPath(page);
    for (const path of pages.guest) {
        await audit(page, path === ':product' ? product : path);
    }
});

for (const role of ['buyer', 'seller', 'admin']) {
    test(`${role} pages are accessible`, async ({ page }) => {
        await login(page, role);
        for (const path of pages[role]) {
            await audit(page, path);
        }
    });
}
